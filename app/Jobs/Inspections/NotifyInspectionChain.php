<?php

namespace App\Jobs\Inspections;

use App\Enums\InspectionStatus;
use App\Jobs\Concerns\TenantAware;
use App\Jobs\Inspections\Concerns\ResolvesInspectionRecipients;
use App\Models\SiteInspection;
use App\Models\User;
use App\Notifications\Inspections\InspectionCancelled;
use App\Notifications\Inspections\InspectionOutcomeEscalated;
use App\Notifications\Inspections\InspectionReportSubmitted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * The lifecycle's one notifying job, dispatched by
 * TransitionInspectionStatus after every transition it makes.
 *
 * Only two steps have an audience, and they are different audiences:
 *
 *  - SUBMITTED → the M&E officers who must sign it off. Without this the
 *    separation guard turns into a silent dead end: the inspector cannot clear
 *    their own report, so somebody has to be told it is waiting. AND, when the
 *    verdict is `major_issues` or `work_stopped`, the MDA admins — the people
 *    who can halt a payment — in the same pass.
 *  - CANCELLED → the lead inspector, with the reason. Mundane, and the reason
 *    the reason is mandatory: an inspector who is not told drives to a site
 *    that has been cancelled.
 *  - everything else → nobody. A visit moving to `in_progress` is an inspector
 *    opening a form, and mailing a director about it is how a channel becomes
 *    noise. Sign-off is visible on the board.
 *
 * THE ACTOR IS NEVER NOTIFIED of their own act. They know what they just did,
 * and noise is how people learn to ignore a channel.
 *
 * IDS, NOT MODELS: see NotifyInspectionScheduled.
 *
 * IDEMPOTENT ENOUGH: a replayed job re-sends one notice. It holds no counter
 * of its own because there is nothing periodic here — each transition happens
 * once, and the chokepoint that dispatched this already refused a second one
 * under a row lock.
 */
class NotifyInspectionChain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, ResolvesInspectionRecipients, SerializesModels, TenantAware;

    public function __construct(
        private readonly int $inspectionId,
        private readonly string $toStatus,
        private readonly int $actorId,
        private readonly ?string $reason = null,
    ) {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $to = InspectionStatus::tryFrom($this->toStatus);

        if ($to !== InspectionStatus::Submitted && $to !== InspectionStatus::Cancelled) {
            return;
        }

        $inspection = SiteInspection::query()
            ->with(['project.tenant', 'tenant', 'leadInspector', 'submittedBy'])
            ->find($this->inspectionId);

        if ($inspection === null) {
            return;
        }

        if ($to === InspectionStatus::Cancelled) {
            $this->announceCancellation($inspection);

            return;
        }

        $reviewers = $this->withoutActor($this->reviewers($inspection));

        if ($reviewers->isNotEmpty()) {
            Notification::send($reviewers, new InspectionReportSubmitted($inspection));
        }

        if ($inspection->outcome === null || ! $inspection->outcome->requiresEscalation()) {
            return;
        }

        // The escalation goes to the MDA admins specifically. They overlap
        // with the reviewers above and will receive both — deliberately: "a
        // report needs your sign-off" and "this site has stopped work" are
        // different messages with different urgencies, and collapsing them
        // would bury the second inside the first.
        $admins = $this->withoutActor($this->admins());

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new InspectionOutcomeEscalated($inspection));
        }
    }

    /**
     * The lead inspector learns their visit is off, and why. Skipped when they
     * cancelled it themselves.
     */
    private function announceCancellation(SiteInspection $inspection): void
    {
        $recipients = $this->withoutActor($this->inspector($inspection));

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new InspectionCancelled($inspection, $this->reason));
    }

    /**
     * @param  Collection<int, User>  $recipients
     * @return Collection<int, User>
     */
    private function withoutActor(Collection $recipients): Collection
    {
        /** @var Collection<int, User> $filtered */
        $filtered = $recipients->reject(fn (User $user): bool => $user->id === $this->actorId);

        return $filtered;
    }
}
