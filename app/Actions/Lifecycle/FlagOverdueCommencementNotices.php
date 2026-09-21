<?php

declare(strict_types=1);

namespace App\Actions\Lifecycle;

use App\Enums\CommencementNoticeStatus;
use App\Enums\ContractStatus;
use App\Enums\ProjectStatus;
use App\Jobs\Lifecycle\NotifyCommencementNoticeOverdue;
use App\Models\CommencementNotice;
use App\Models\Contract;
use App\Models\Tenant;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The compliance sweep behind step 1 of the lifecycle: a contract awarded more
 * than `monitoring.commencement_notice_days` ago whose contractor has still
 * not been told to start.
 *
 * TWO PASSES, and the first one is why this is not a report:
 *
 *  1. **Materialise.** An overdue contract with no notice row gets a `pending`
 *     notice carrying the award snapshot. Every idempotency gate in this
 *     platform is a column on the row being acted on (progress-reporting.md
 *     §3) — with no row there is nowhere to record "already chased", and the
 *     MDA admin would be notified again every single morning. The pending row
 *     is also what the screen needs: the officer opens a notice that is ready
 *     to serve rather than a blank form.
 *
 *  2. **Flag.** A pending notice past its deadline with `overdue_notified_at`
 *     still null is announced exactly once, ever — the stamp is written under
 *     a row lock in the same transaction as the dispatch, so a double run, an
 *     overlapping worker and a retry are all silent.
 *
 * VARIATIONS ARE SKIPPED: a variation order is raised against works already
 * commenced, so demanding a second notice to commence for it would invent an
 * obligation nobody owes. Terminated contracts and draft/cancelled projects
 * are skipped for the same reason — there is nothing to commence.
 */
class FlagOverdueCommencementNotices
{
    /**
     * @return array{materialised: int, flagged: int}
     */
    public function __invoke(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $current = app(CurrentTenant::class);
        $totals = ['materialised' => 0, 'flagged' => 0];

        foreach (Tenant::query()->where('is_active', true)->cursor() as $tenant) {
            $tenantTotals = $current->runAs($tenant, fn (): array => $this->forTenant($asOf));

            $totals['materialised'] += $tenantTotals['materialised'];
            $totals['flagged'] += $tenantTotals['flagged'];
        }

        return $totals;
    }

    /**
     * @return array{materialised: int, flagged: int}
     */
    private function forTenant(CarbonImmutable $asOf): array
    {
        $noticeDays = app(SettingsRepository::class)->int('monitoring', 'commencement_notice_days', 3);

        return [
            'materialised' => $this->materialise($asOf, $noticeDays),
            'flagged' => $this->flag($asOf),
        ];
    }

    /** Give every overdue award a notice row to be late on. */
    private function materialise(CarbonImmutable $asOf, int $noticeDays): int
    {
        $cutoff = $asOf->startOfDay()->subDays($noticeDays)->toDateString();
        $created = 0;

        $contracts = Contract::query()
            ->with(['project.tenant'])
            ->whereNull('varies_contract_id')
            ->where('status', '!=', ContractStatus::Terminated)
            ->whereDate('award_date', '<=', $cutoff)
            ->whereNotIn('id', CommencementNotice::query()->select('contract_id'))
            ->whereHas('project', fn (Builder $project) => $project
                ->whereNotIn('status', [ProjectStatus::Draft, ProjectStatus::Cancelled]))
            ->orderBy('award_date')
            ->cursor();

        foreach ($contracts as $contract) {
            $project = $contract->project;

            $created += DB::transaction(function () use ($contract, $project, $noticeDays): int {
                // Re-check inside the transaction: the officer may have served
                // the notice by hand while this sweep was walking the list.
                if (CommencementNotice::query()->where('contract_id', $contract->id)->exists()) {
                    return 0;
                }

                $notice = new CommencementNotice(
                    CommencementNotice::snapshotOf($contract, $project, $noticeDays)
                );

                // Explicit: the project is the authority on whose record this
                // is, exactly as in the issuing Action.
                $notice->tenant_id = $project->tenant_id;
                $notice->save();

                return 1;
            });
        }

        return $created;
    }

    /** Announce each unserved, overdue notice once. */
    private function flag(CarbonImmutable $asOf): int
    {
        $flagged = 0;

        $notices = CommencementNotice::query()
            ->where('status', CommencementNoticeStatus::Pending)
            ->whereNull('overdue_notified_at')
            ->whereDate('due_at', '<', $asOf->startOfDay()->toDateString())
            ->orderBy('due_at')
            ->cursor();

        foreach ($notices as $notice) {
            $flagged += DB::transaction(function () use ($notice): int {
                $locked = CommencementNotice::query()->lockForUpdate()->find($notice->id);

                if ($locked === null
                    || $locked->overdue_notified_at !== null
                    || $locked->status->isServed()) {
                    return 0;
                }

                // The stamp is written in the SAME transaction as the
                // dispatch: that pairing, not the scheduler's overlap guard,
                // is what makes a second run silent.
                $locked->forceFill(['overdue_notified_at' => now()])->save();

                NotifyCommencementNoticeOverdue::dispatch($locked->id);

                return 1;
            });
        }

        return $flagged;
    }
}
