<?php

namespace App\Actions\Workplans;

use App\Jobs\Workplans\NotifyActivityOverdue;
use App\Models\Tenant;
use App\Models\Workplan;
use App\Models\WorkplanActivity;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The overdue sweep for work-plan activities — the same shape as
 * App\Actions\Reporting\FlagOverdueObligations, for the same reason: an
 * activity that has quietly slipped past its planned date is the earliest
 * signal a plan is failing, and nobody should have to run a report to see it.
 *
 * IDEMPOTENT BY CONSTRUCTION, not by the scheduler's guard: the notice is
 * gated on `overdue_notified_at`, which is null-checked and stamped under the
 * same row lock as the dispatch. A second run the same day is silent, and a
 * sweep that crashes halfway re-announces nothing it already announced.
 *
 * Only activities of APPROVED or ACTIVE plans are considered. A draft plan's
 * dates are a proposal, and nagging an officer about a deadline in a plan
 * nobody has signed is how a notification channel gets muted.
 */
class FlagOverdueActivities
{
    /**
     * @return array{overdue: int}
     */
    public function __invoke(?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $current = app(CurrentTenant::class);
        $total = 0;

        foreach (Tenant::query()->where('is_active', true)->cursor() as $tenant) {
            // runAs binds tenancy AND the permission team, so everything below
            // — including the queued notification's recipient query — runs
            // inside the MDA it belongs to.
            $total += $current->runAs($tenant, fn (): int => $this->forTenant($asOf));
        }

        return ['overdue' => $total];
    }

    private function forTenant(CarbonImmutable $asOf): int
    {
        $flagged = 0;

        $activities = WorkplanActivity::query()
            ->overdue($asOf)
            ->whereNull('overdue_notified_at')
            ->whereIn('workplan_id', Workplan::query()->live()->select('id'))
            ->orderBy('planned_end')
            ->cursor();

        foreach ($activities as $activity) {
            $flagged += $this->notify($activity);
        }

        return $flagged;
    }

    /** The "this has slipped" notice — exactly once per activity, ever. */
    private function notify(WorkplanActivity $activity): int
    {
        return DB::transaction(function () use ($activity): int {
            $locked = WorkplanActivity::query()
                ->lockForUpdate()
                ->whereKey($activity->getKey())
                ->first();

            if ($locked === null || $locked->overdue_notified_at !== null) {
                return 0;
            }

            $locked->forceFill(['overdue_notified_at' => now()])->save();

            NotifyActivityOverdue::dispatch($locked->id);

            return 1;
        });
    }
}
