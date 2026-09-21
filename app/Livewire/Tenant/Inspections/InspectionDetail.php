<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Inspections;

use App\Actions\Inspections\CancelInspection;
use App\Actions\Inspections\ReviewInspectionReport;
use App\Enums\InspectionStatus;
use App\Exceptions\Inspections\InspectionRuleViolation;
use App\Exceptions\Inspections\InvalidInspectionTransition;
use App\Models\SiteInspection;
use App\Models\SiteInspectionEvent;
use App\Models\SiteInspectionResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One site visit, whole: the Field Trip Report, the checklist as answered, the
 * evidence vault, the lifecycle timeline, and whatever this viewer may do
 * about it.
 *
 * The inspection arrives by route-model binding on its ULID, resolved through
 * the TenantScope — another MDA's public id is a 404 at the binder, before any
 * code here runs.
 *
 * Every mutating method authorizes AGAIN. mount()'s authorize() protects the
 * page load; it does not protect a Livewire update POST, which a client can
 * send long afterwards against a component it has the id for.
 */
#[Layout('layouts::tenant')]
class InspectionDetail extends Component
{
    public SiteInspection $inspection;

    public string $reviewNotes = '';

    public string $cancelReason = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(SiteInspection $inspection): void
    {
        $this->authorize('view', $inspection);

        $this->inspection = $inspection;
    }

    /**
     * The record, re-read rather than trusted from the property.
     *
     * NOT `->fresh()`: that is newQueryWithoutScopes(), i.e. an unscoped
     * cross-tenant read wearing innocuous clothing. Re-querying through the
     * model keeps the TenantScope on and fails closed.
     */
    #[Computed]
    public function record(): SiteInspection
    {
        return SiteInspection::query()
            ->with([
                'project:id,ulid,title,reference,physical_progress',
                'location:id,site_name,latitude,longitude',
                'template:id,name',
                'leadInspector:id,name',
                'scheduledBy:id,name',
                'submittedBy:id,name',
                'reviewedBy:id,name',
                'cancelledBy:id,name',
            ])
            ->whereKey($this->inspection->getKey())
            ->firstOrFail();
    }

    /**
     * The checklist as it was answered — reading each response's SNAPSHOTTED
     * prompt, never the live template. The instrument is global and curated by
     * the state; it will be reworded, and a report must show the question that
     * was actually put to the inspector.
     *
     * @return Collection<int, SiteInspectionResponse>
     */
    #[Computed]
    public function responses(): Collection
    {
        return SiteInspectionResponse::query()
            ->where('site_inspection_id', $this->inspection->id)
            ->with('item:id,position,unit,rating_scale')
            ->get()
            ->sortBy(fn (SiteInspectionResponse $response): int => $response->item->position ?? 0)
            ->values();
    }

    /**
     * The lifecycle ledger, oldest first. The *_by_id/*_at columns on the
     * inspection hold the current state; this is the history they cannot
     * express — a visit rescheduled twice has two events and one date.
     *
     * @return Collection<int, SiteInspectionEvent>
     */
    #[Computed]
    public function chain(): Collection
    {
        return SiteInspectionEvent::query()
            ->where('site_inspection_id', $this->inspection->id)
            ->with('actor:id,name')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /** The visit before this one on the same project — the manual's section 5. */
    #[Computed]
    public function previousVisit(): ?SiteInspection
    {
        return $this->record()->previousOnProject();
    }

    /**
     * Whether THIS viewer may sign this report off right now. Both halves
     * matter and they answer different questions: the policy says "may you
     * review inspections here", the identity check says "did you conduct this
     * one" — and the second is a domain rule enforced in the chokepoint, so
     * the screen mirrors it rather than owning it.
     */
    public function canReview(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        $record = $this->record();

        return $record->status === InspectionStatus::Submitted
            && $user->can('review', $record)
            && $record->lead_inspector_id !== $user->id
            && $record->submitted_by_id !== $user->id;
    }

    /**
     * Why the sign-off button is absent, in the viewer's own terms. A disabled
     * control with no explanation is how an officer concludes the platform is
     * broken.
     */
    public function reviewBlockedReason(): ?string
    {
        /** @var User $user */
        $user = auth()->user();

        $record = $this->record();

        if ($record->status !== InspectionStatus::Submitted) {
            return null;
        }

        if ($record->lead_inspector_id === $user->id || $record->submitted_by_id === $user->id) {
            return __('You conducted this visit, so you cannot sign off its report. An inspection is the state’s assurance over delivery — assurance a person performs on their own field work is not assurance.');
        }

        if (! $user->can('review', $record)) {
            return __('Signing off an inspection report requires M&E review authority in this workspace.');
        }

        return null;
    }

    public function review(ReviewInspectionReport $review): void
    {
        $record = $this->record();

        $this->authorize('review', $record);

        $this->validate([
            'reviewNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $review($record, $actor, $this->reviewNotes);
        } catch (InspectionRuleViolation|InvalidInspectionTransition $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'review-inspection');

            return;
        }

        $this->reviewNotes = '';
        unset($this->record, $this->chain);
        $this->dispatch('close-modal', 'review-inspection');

        session()->flash('status', __('Inspection signed off. It is now part of this project’s monitoring record.'));
    }

    public function cancel(CancelInspection $cancel): void
    {
        $record = $this->record();

        $this->authorize('cancel', $record);

        $this->validate([
            'cancelReason' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'cancelReason.required' => __('Say why the visit is not happening. A visit that silently disappears from the diary is indistinguishable from one nobody bothered to make.'),
            'cancelReason.min' => __('Give the auditor something to read — a few words at least.'),
        ], [
            'cancelReason' => __('reason'),
        ]);

        $this->failure = null;

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $cancel($record, $actor, $this->cancelReason);
        } catch (InspectionRuleViolation|InvalidInspectionTransition $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'cancel-inspection');

            return;
        }

        $this->cancelReason = '';
        unset($this->record, $this->chain);
        $this->dispatch('close-modal', 'cancel-inspection');

        session()->flash('status', __('Visit cancelled. The reason is on the record and visible to state oversight.'));
    }

    public function render(): View
    {
        return view('livewire.tenant.inspections.inspection-detail');
    }
}
