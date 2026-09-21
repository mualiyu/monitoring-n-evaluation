<?php

declare(strict_types=1);

namespace App\Actions\Lifecycle;

use App\Enums\CommencementNoticeStatus;
use App\Exceptions\Lifecycle\LifecycleRuleViolation;
use App\Models\CommencementNotice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Record the contractor's confirmation that the notice was received.
 *
 * Small, and load-bearing: the acknowledgement is what turns "we posted it"
 * into "they were served on this date", and the initial-inspection clock
 * (`monitoring.initial_inspection_days`) runs from a notice that landed, not
 * from one that was printed.
 *
 * Idempotency is a state check, not a null check on the timestamp: receipt is
 * confirmed exactly once, and a second confirmation is refused out loud rather
 * than quietly overwriting the first date.
 */
class AcknowledgeCommencementNotice
{
    public function __invoke(
        CommencementNotice $notice,
        User $actor,
        ?string $note = null,
    ): CommencementNotice {
        Gate::forUser($actor)->authorize('acknowledge', $notice);

        $from = $notice->status;

        if ($from === CommencementNoticeStatus::Acknowledged) {
            throw LifecycleRuleViolation::noticeAlreadyAcknowledged();
        }

        if (! $from->isServed()) {
            throw LifecycleRuleViolation::noticeNotIssued();
        }

        if (! $from->canTransitionTo(CommencementNoticeStatus::Acknowledged)) {
            throw LifecycleRuleViolation::invalidNoticeTransition($from, CommencementNoticeStatus::Acknowledged);
        }

        $note = $note === null ? null : trim($note);

        return DB::transaction(function () use ($notice, $actor, $note): CommencementNotice {
            $locked = CommencementNotice::query()->lockForUpdate()->findOrFail($notice->id);

            // Re-read under the lock: two tabs, or a retried request, must not
            // both write a receipt date.
            if ($locked->status === CommencementNoticeStatus::Acknowledged) {
                throw LifecycleRuleViolation::noticeAlreadyAcknowledged();
            }

            // forceFill: the acknowledgement chain is deliberately not
            // fillable — this Action is its only writer.
            $locked->forceFill([
                'status' => CommencementNoticeStatus::Acknowledged,
                'acknowledged_by_id' => $actor->id,
                'acknowledged_by_contractor_at' => now(),
                'acknowledgement_note' => $note === '' ? null : $note,
            ])->save();

            return $locked;
        });
    }
}
