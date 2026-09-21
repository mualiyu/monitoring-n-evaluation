<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Lifecycle;

use App\Actions\Lifecycle\AcknowledgeCommencementNotice;
use App\Actions\Lifecycle\IssueCommencementNotice;
use App\Exceptions\Lifecycle\LifecycleRuleViolation;
use App\Models\CommencementNotice;
use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use App\Support\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step 1 of the monitoring lifecycle, per project: which of its awards have
 * been served on the contractor, which are late, and the one form that fixes
 * that (digest §8).
 *
 * ONE SCREEN PER PROJECT RATHER THAN A STATE-WIDE NOTICE QUEUE, because the
 * question an officer actually has is "has THIS contract been told to start" —
 * and the answer has to sit next to the award it belongs to. The state-wide
 * view of the same fact is the overdue sweep, which pushes rather than waits
 * to be visited.
 *
 * Variations are not listed: a variation order is raised against works already
 * commenced, so demanding a second notice to commence for it would invent an
 * obligation nobody owes. The sweep applies the same rule.
 *
 * Every mutation delegates to an Action, which re-authorizes and enforces the
 * domain rule independently — the checks here decide what to RENDER, never
 * what is allowed.
 */
#[Layout('layouts::tenant')]
class ProjectCommencement extends Component
{
    public Project $project;

    /** The contract whose notice form is open, by ULID. */
    public ?string $issuingContractUlid = null;

    public string $commencementDate = '';

    public string $instructions = '';

    /** The notice a receipt is being recorded against, by ULID. */
    public ?string $acknowledgingNoticeUlid = null;

    public string $acknowledgementNote = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
    }

    /**
     * The original awards on this project. Variations are excluded (see the
     * class docblock).
     *
     * @return Collection<int, Contract>
     */
    #[Computed]
    public function contracts(): Collection
    {
        return Contract::query()
            ->where('project_id', $this->project->id)
            ->whereNull('varies_contract_id')
            ->with('contractor:id,name,rc_number')
            ->orderByDesc('award_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Notices keyed by the contract they serve — one query for the whole
     * table rather than one per row.
     *
     * @return Collection<int, CommencementNotice>
     */
    #[Computed]
    public function notices(): Collection
    {
        /** @var Collection<int, CommencementNotice> $keyed */
        $keyed = CommencementNotice::query()
            ->where('project_id', $this->project->id)
            ->with(['issuedBy:id,name', 'acknowledgedBy:id,name', 'media'])
            ->get()
            ->keyBy('contract_id');

        return $keyed;
    }

    public function noticeFor(Contract $contract): ?CommencementNotice
    {
        return $this->notices()->get($contract->id);
    }

    /** The statutory window, shown on the screen that has to meet it. */
    #[Computed]
    public function noticeDays(): int
    {
        return app(SettingsRepository::class)->int('monitoring', 'commencement_notice_days', 3);
    }

    /* ---------------------------------------------------------------- */
    /* Serving a notice */
    /* ---------------------------------------------------------------- */

    public function startIssue(string $contractUlid): void
    {
        $this->authorize('issue', [CommencementNotice::class, $this->project]);

        $contract = $this->contractFor($contractUlid);

        $this->resetErrorBag();
        $this->failure = null;
        $this->issuingContractUlid = $contractUlid;
        $this->instructions = '';
        // Prefer the date the contract already names; otherwise today, which
        // is what "commence now" means on the day a notice is served.
        $this->commencementDate = ($contract->commencement_date ?? CarbonImmutable::now())->toDateString();

        $this->dispatch('open-modal', 'issue-notice');
    }

    public function cancelIssue(): void
    {
        $this->issuingContractUlid = null;
        $this->resetErrorBag();

        $this->dispatch('close-modal', 'issue-notice');
    }

    /**
     * Authorization is repeated here and not merely inherited from mount():
     * this is a network-callable method and a client can invoke it long after
     * the screen was opened. IssueCommencementNotice authorizes again on its
     * own account — two independent checks, both tested.
     */
    public function issue(IssueCommencementNotice $issue): void
    {
        $this->authorize('issue', [CommencementNotice::class, $this->project]);

        $contract = $this->contractFor((string) $this->issuingContractUlid);

        $this->validate([
            'commencementDate' => ['required', 'date', 'after_or_equal:'.$contract->award_date->toDateString()],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ], [
            'commencementDate.after_or_equal' => __('A contractor cannot be instructed to have commenced before the contract was awarded.'),
        ], [
            'commencementDate' => __('commencement date'),
            'instructions' => __('instructions'),
        ]);

        $this->failure = null;

        try {
            $issue(
                $contract,
                $this->user(),
                $this->instructions === '' ? null : $this->instructions,
                CarbonImmutable::parse($this->commencementDate),
            );
        } catch (LifecycleRuleViolation $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'issue-notice');

            return;
        }

        $this->issuingContractUlid = null;
        unset($this->notices);

        $this->dispatch('close-modal', 'issue-notice');

        session()->flash('status', __('Commencement notice issued. The contractor has been notified and the notice is filed against the contract.'));
    }

    /* ---------------------------------------------------------------- */
    /* Recording receipt */
    /* ---------------------------------------------------------------- */

    public function startAcknowledge(string $noticeUlid): void
    {
        $notice = $this->noticeByUlid($noticeUlid);

        $this->authorize('acknowledge', $notice);

        $this->resetErrorBag();
        $this->failure = null;
        $this->acknowledgingNoticeUlid = $noticeUlid;
        $this->acknowledgementNote = '';

        $this->dispatch('open-modal', 'acknowledge-notice');
    }

    public function cancelAcknowledge(): void
    {
        $this->acknowledgingNoticeUlid = null;
        $this->resetErrorBag();

        $this->dispatch('close-modal', 'acknowledge-notice');
    }

    public function confirmAcknowledge(AcknowledgeCommencementNotice $acknowledge): void
    {
        $notice = $this->noticeByUlid((string) $this->acknowledgingNoticeUlid);

        $this->authorize('acknowledge', $notice);

        $this->validate([
            'acknowledgementNote' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'acknowledgementNote' => __('note'),
        ]);

        $this->failure = null;

        try {
            $acknowledge(
                $notice,
                $this->user(),
                $this->acknowledgementNote === '' ? null : $this->acknowledgementNote,
            );
        } catch (LifecycleRuleViolation $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'acknowledge-notice');

            return;
        }

        $this->acknowledgingNoticeUlid = null;
        $this->acknowledgementNote = '';
        unset($this->notices);

        $this->dispatch('close-modal', 'acknowledge-notice');

        session()->flash('status', __('Receipt recorded. The monitoring clock runs from the date this notice was served.'));
    }

    /* ---------------------------------------------------------------- */

    /**
     * The contract behind a ULID, resolved inside this project and inside the
     * TenantScope: an id from another project (or another MDA) is a 404, not a
     * refusal that confirms the row exists somewhere.
     */
    private function contractFor(string $ulid): Contract
    {
        return Contract::query()
            ->where('project_id', $this->project->id)
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    private function noticeByUlid(string $ulid): CommencementNotice
    {
        return CommencementNotice::query()
            ->where('project_id', $this->project->id)
            ->where('ulid', $ulid)
            ->firstOrFail();
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.tenant.lifecycle.project-commencement');
    }
}
