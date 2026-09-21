<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Lifecycle;

use App\Actions\Lifecycle\Concerns\FindsFinalInspection;
use App\Actions\Lifecycle\IssueCompletionCertificate;
use App\Actions\Lifecycle\RevokeCertificate;
use App\Enums\CertificateType;
use App\Enums\ProjectStatus;
use App\Exceptions\Lifecycle\LifecycleRuleViolation;
use App\Exceptions\Projects\InvalidStatusTransition;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Certificate;
use App\Models\Project;
use App\Models\ReportObligation;
use App\Models\User;
use App\Support\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The certification screen: the preconditions, then the signature.
 *
 * THE CHECKLIST IS NOT THE GUARD. Every item below is re-asserted inside
 * IssueCompletionCertificate (and, for the project status itself, inside
 * TransitionProjectStatus), which is what actually refuses. What the checklist
 * buys is that an MDA administrator sees WHY certification is not available
 * before clicking, instead of meeting a refusal message afterwards — a
 * distinction that matters when the missing item is a site visit somebody has
 * to go and make.
 *
 * Certification moves the project to `certified` through the status chokepoint,
 * never by writing the column — see IssueCompletionCertificate.
 */
#[Layout('layouts::tenant')]
class CertifyProject extends Component
{
    use FindsFinalInspection;

    public Project $project;

    public string $type = CertificateType::PracticalCompletion->value;

    public string $narrative = '';

    public string $defectsLiabilityEndsOn = '';

    /** The certificate a withdrawal is being written for, and its reason. */
    public ?string $revokingUlid = null;

    public string $revocationReason = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);

        $this->project = $project;
        $this->type = $this->defaultType()->value;
    }

    /**
     * Certificates already standing against this project, withdrawn ones
     * included — the register on this screen is the project's certification
     * history, not just its current state.
     *
     * @return Collection<int, Certificate>
     */
    #[Computed]
    public function certificates(): Collection
    {
        return Certificate::query()
            ->where('project_id', $this->project->id)
            ->with(['issuedBy:id,name', 'revokedBy:id,name'])
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The preconditions, each as {met, label, detail}. Ordered by what an
     * officer can actually do something about.
     *
     * @return list<array{key: string, met: bool, label: string, detail: string}>
     */
    #[Computed]
    public function preconditions(): array
    {
        $progress = (int) round(((float) $this->project->physical_progress) * 100);
        $outstanding = $this->outstandingObligations();
        $requiresInspection = app(SettingsRepository::class)
            ->bool('monitoring', 'require_final_inspection_for_certification', false);
        $inspection = $this->finalInspectionFor($this->project);

        $checks = [
            [
                'key' => 'status',
                'met' => in_array($this->project->status, [ProjectStatus::Completed, ProjectStatus::Certified], true),
                'label' => __('Works recorded as completed'),
                'detail' => __('The project is currently :status.', ['status' => $this->project->status->label()]),
            ],
            [
                'key' => 'progress',
                'met' => $progress === 10_000,
                'label' => __('Physical progress at 100%'),
                'detail' => __('Recorded progress is :progress%.', ['progress' => $this->project->physical_progress]),
            ],
            [
                'key' => 'obligations',
                'met' => $outstanding === 0,
                'label' => __('Reporting obligations met'),
                'detail' => $outstanding === 0
                    ? __('No return is outstanding against this project.')
                    : trans_choice(
                        '{1} :count return is still outstanding.|[2,*] :count returns are still outstanding.',
                        $outstanding,
                        ['count' => $outstanding],
                    ),
            ],
        ];

        // Shown only where it is a rule. An instance that does not require a
        // final inspection should not display a permanently unmet checkbox.
        if ($requiresInspection) {
            $checks[] = [
                'key' => 'inspection',
                'met' => $inspection !== null,
                'label' => __('Final inspection on record'),
                'detail' => $inspection === null
                    ? __('No completed final inspection is recorded against this project.')
                    : __('Final inspection on record.'),
            ];
        }

        return $checks;
    }

    /**
     * Whether the blocking preconditions are met. An outstanding RETURN is
     * deliberately advisory rather than blocking: the reporting chain has its
     * own waiver path, and a return nobody can now file must not be able to
     * strand a finished road in an uncertified state forever.
     */
    #[Computed]
    public function canCertify(): bool
    {
        foreach ($this->preconditions() as $check) {
            if ($check['key'] !== 'obligations' && ! $check['met']) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        return CertificateType::options();
    }

    public function updatedType(): void
    {
        $this->resetErrorBag();

        if (! $this->selectedType()->opensDefectsLiability()) {
            $this->defectsLiabilityEndsOn = '';
        }
    }

    /* ---------------------------------------------------------------- */
    /* Issuing */
    /* ---------------------------------------------------------------- */

    /**
     * Authorization is repeated here and not merely inherited from mount():
     * this is a network-callable method, and mount() only asked whether the
     * caller may VIEW the project.
     */
    public function certify(IssueCompletionCertificate $issue): void
    {
        $this->authorize('issue', [Certificate::class, $this->project]);

        $this->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(CertificateType::options()))],
            'narrative' => ['nullable', 'string', 'max:2000'],
            'defectsLiabilityEndsOn' => ['nullable', 'date', 'after_or_equal:today'],
        ], [
            'defectsLiabilityEndsOn.after_or_equal' => __('A defects-liability period cannot end before the certificate that opens it is issued.'),
        ], [
            'type' => __('certificate type'),
            'narrative' => __('remarks'),
            'defectsLiabilityEndsOn' => __('defects-liability end date'),
        ]);

        $this->failure = null;

        try {
            $issue(
                $this->project,
                $this->selectedType(),
                $this->user(),
                $this->narrative === '' ? null : $this->narrative,
                $this->defectsLiabilityEndsOn === '' ? null : CarbonImmutable::parse($this->defectsLiabilityEndsOn),
            );
        } catch (LifecycleRuleViolation|ProjectRuleViolation|InvalidStatusTransition $exception) {
            // Three exception families because certification crosses two
            // modules: this one's preconditions, and the project lifecycle's.
            // All three are domain refusals and all three are shown verbatim.
            $this->failure = $exception->getMessage();

            return;
        }

        $this->narrative = '';
        $this->defectsLiabilityEndsOn = '';

        // The project row in memory is stale after the status transition, and
        // re-querying (rather than refreshing in place) keeps the read inside
        // the TenantScope.
        $this->project = Project::query()->whereKey($this->project->getKey())->firstOrFail();
        $this->type = $this->defaultType()->value;

        unset($this->certificates, $this->preconditions, $this->canCertify);

        session()->flash('status', __('Certificate issued. The project is certified and the entity administrator and state oversight have been notified.'));
    }

    /* ---------------------------------------------------------------- */
    /* Withdrawal */
    /* ---------------------------------------------------------------- */

    public function startRevoke(string $ulid): void
    {
        $this->authorize('revoke', $this->certificateByUlid($ulid));

        $this->resetErrorBag();
        $this->failure = null;
        $this->revokingUlid = $ulid;
        $this->revocationReason = '';

        $this->dispatch('open-modal', 'revoke-certificate');
    }

    public function cancelRevoke(): void
    {
        $this->revokingUlid = null;
        $this->resetErrorBag();

        $this->dispatch('close-modal', 'revoke-certificate');
    }

    public function confirmRevoke(RevokeCertificate $revoke): void
    {
        $certificate = $this->certificateByUlid((string) $this->revokingUlid);

        $this->authorize('revoke', $certificate);

        $this->validate([
            'revocationReason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'revocationReason.required' => __('Say why the certificate is being withdrawn. It stays on the record and an auditor will read it.'),
            'revocationReason.min' => __('Give the auditor something to read — a few words at least.'),
        ], [
            'revocationReason' => __('reason'),
        ]);

        $this->failure = null;

        try {
            $revoke($certificate, $this->user(), $this->revocationReason);
        } catch (LifecycleRuleViolation $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'revoke-certificate');

            return;
        }

        $this->revokingUlid = null;
        $this->revocationReason = '';

        unset($this->certificates, $this->preconditions, $this->canCertify);
        $this->dispatch('close-modal', 'revoke-certificate');

        session()->flash('status', __('Certificate withdrawn. It stays in the register, marked as withdrawn, with your reason attached.'));
    }

    /* ---------------------------------------------------------------- */

    /**
     * Practical completion unless it is already in force — then the next thing
     * a state signs is final completion, so that is what the form offers.
     */
    private function defaultType(): CertificateType
    {
        $hasPractical = Certificate::query()
            ->active()
            ->where('project_id', $this->project->id)
            ->where('type', CertificateType::PracticalCompletion)
            ->exists();

        return $hasPractical ? CertificateType::FinalCompletion : CertificateType::PracticalCompletion;
    }

    private function selectedType(): CertificateType
    {
        return CertificateType::tryFrom($this->type) ?? CertificateType::PracticalCompletion;
    }

    private function outstandingObligations(): int
    {
        return ReportObligation::query()
            ->outstanding()
            ->where('project_id', $this->project->id)
            ->count();
    }

    private function certificateByUlid(string $ulid): Certificate
    {
        return Certificate::query()
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
        return view('livewire.tenant.lifecycle.certify-project');
    }
}
