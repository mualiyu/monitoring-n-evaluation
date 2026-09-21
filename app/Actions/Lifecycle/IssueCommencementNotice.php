<?php

declare(strict_types=1);

namespace App\Actions\Lifecycle;

use App\Actions\Lifecycle\Concerns\RendersLifecyclePdf;
use App\Enums\CommencementNoticeStatus;
use App\Exceptions\Lifecycle\LifecycleRuleViolation;
use App\Jobs\Lifecycle\NotifyCommencementNoticeIssued;
use App\Models\CommencementNotice;
use App\Models\Contract;
use App\Models\User;
use App\Support\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Serve the notice to commence — step 1 of the monitoring lifecycle (digest
 * §8): within `monitoring.commencement_notice_days` of award, the supervising
 * agency tells the contractor to start, quoting the scope, the sum, the
 * duration and the completion date it will be held to.
 *
 * THE SINGLE WRITER of CommencementNotice::$status and of the whole service
 * chain, which is why those columns are not fillable.
 *
 * Order fails closed at the cheapest question first:
 *   1. authorization (`commencement.issue` in this workspace),
 *   2. domain preconditions (a real award, not already served, a sane date),
 *   3. the row + the rendered notice, in ONE transaction,
 *   4. the notification, after the transaction has closed.
 *
 * WHY THE PDF IS INSIDE THE TRANSACTION: the two failure modes are an orphan
 * file on a private disk, or a notice recorded as served with no document to
 * serve. The first is litter; the second is a government record that lies.
 */
class IssueCommencementNotice
{
    use RendersLifecyclePdf;

    public function __invoke(
        Contract $contract,
        User $actor,
        ?string $instructions = null,
        ?CarbonInterface $commencementDate = null,
    ): CommencementNotice {
        $contract->loadMissing(['project.tenant', 'contractor']);
        $project = $contract->project;

        Gate::forUser($actor)->authorize('issue', [CommencementNotice::class, $project]);

        // A notice instructs a contractor to begin work it has been awarded.
        // Before award there is no contractor to instruct, and after
        // cancellation there is nothing to begin.
        if (! $project->status->isAwardedOrBeyond()) {
            throw LifecycleRuleViolation::noticeOnUnawardedProject($project->status);
        }

        $existing = CommencementNotice::query()
            ->where('contract_id', $contract->id)
            ->first();

        if ($existing !== null && $existing->status->isServed()) {
            throw LifecycleRuleViolation::noticeAlreadyIssued();
        }

        $commencementDate ??= $contract->commencement_date;

        if ($commencementDate !== null && $commencementDate->isBefore($contract->award_date)) {
            throw LifecycleRuleViolation::commencementDateBeforeAward();
        }

        $noticeDays = app(SettingsRepository::class)->int('monitoring', 'commencement_notice_days', 3);
        $instructions = $instructions === null ? null : trim($instructions);

        $notice = DB::transaction(function () use (
            $contract, $project, $actor, $existing, $noticeDays, $instructions, $commencementDate
        ): CommencementNotice {
            $notice = $existing === null
                ? new CommencementNotice(CommencementNotice::snapshotOf($contract, $project, $noticeDays))
                : CommencementNotice::query()->lockForUpdate()->findOrFail($existing->id);

            // A row the overdue sweep materialised carries the snapshot
            // already; re-asserting it would silently re-date a notice that
            // has been sitting in the register since the award.
            if ($existing !== null && $existing->status->isServed()) {
                throw LifecycleRuleViolation::noticeAlreadyIssued();
            }

            $notice->fill([
                'created_by_id' => $notice->created_by_id ?? $actor->id,
                'instructions' => $instructions,
                'reference' => $notice->reference ?? $this->reference($contract->contract_number),
            ]);

            if ($commencementDate !== null) {
                $notice->commencement_date = $commencementDate;
            }

            // Explicit, like ProjectStatusEvent's: the project is always the
            // authority on whose record this is, and a console or oversight
            // caller has no bound tenant for the auto-fill to read.
            $notice->tenant_id = $project->tenant_id;

            // forceFill: the service chain is deliberately not fillable, so
            // the assignment is explicit and this Action stays greppable as
            // the only place a notice becomes served.
            $notice->forceFill([
                'status' => CommencementNoticeStatus::Issued,
                'issued_by_id' => $actor->id,
                'issued_at' => now(),
                // Snapshotted, not derived on read: whether the state met its
                // own statutory window is a fact about the day it was served.
                'issued_late' => $notice->due_at->endOfDay()->isBefore(now()),
            ])->save();

            $this->attachRenderedPdf(
                $notice,
                'commencement_notice',
                'pdf.commencement-notice',
                [
                    'notice' => $notice,
                    'project' => $project,
                    'contract' => $contract,
                    'contractor' => $contract->contractor,
                    'issuedBy' => $actor,
                ],
                __('Commencement notice :reference', ['reference' => $notice->reference]),
                $actor,
                $project->tenant,
            );

            return $notice;
        });

        NotifyCommencementNoticeIssued::dispatch($notice->id);

        return $notice;
    }

    /**
     * The notice number, derived from the contract it serves rather than
     * minted from a counter: one notice exists per contract, so the contract
     * number already identifies it uniquely and an officer reading
     * "CTR-04182/CN" knows instantly which award it belongs to.
     */
    private function reference(string $contractNumber): string
    {
        return Str::upper(Str::limit($contractNumber, 56, '')).'/CN';
    }
}
