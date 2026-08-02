<?php

namespace App\Actions\Reporting;

use App\Enums\ReportObligationStatus;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Materializes "who owes a return for this window" (progress-reporting.md
 * §1.2, §3) — one row per (tenant, period, project).
 *
 * Why materialize rather than derive: the standing question is how a state-wide
 * board aggregates compliance across 40 MDAs without scanning raw report rows,
 * and a pre-built obligation table answers it with one indexed GROUP BY. It
 * also gives every reminder a durable per-row stage counter — the mechanism
 * that makes the deadline engine idempotent without a "have I sent this?" table.
 *
 * TENANCY: iterates tenants through CurrentTenant::runAs(), so BelongsToTenant
 * fills tenant_id on create and every read is scoped. There is no
 * withoutTenancy() here and no `where('tenant_id')` — a console command is not
 * an oversight surface, and the sweep visiting each workspace in turn is what
 * keeps the scope honest.
 *
 * IDEMPOTENT: the (period, project) pair is looked up under a row lock before
 * insert. No DB unique backs it, deliberately — `project_id` is nullable for
 * the Phase 2 MDA-level obligations and SQL treats NULLs as distinct, so the
 * constraint would enforce nothing on exactly the rows it is meant to protect.
 *
 * `due_at` is refreshed for UNFULFILLED rows only: moving a future deadline
 * must work, while a filed return keeps the deadline it was judged against.
 */
class GenerateReportObligations
{
    /**
     * @return int the number of obligations created or refreshed
     */
    public function __invoke(?CarbonImmutable $asOf = null): int
    {
        $asOf ??= CarbonImmutable::now();
        $current = app(CurrentTenant::class);

        // Open AND not yet past its deadline. The second half is load-bearing:
        // under the default configuration `closes_at` is null, so a window
        // never technically closes and `open()` alone would keep matching
        // every historical window forever — an installer running this on a
        // seeded two-year calendar would hand every MDA a year and a half of
        // instantly-overdue obligations for deadlines nobody ever asked them
        // about, and the escalation ladder would mail all of it to the
        // secretariat on day one.
        //
        // A project cannot be late for a deadline it was never subject to.
        // Obligations for the live window are created the day it opens (this
        // runs daily), and once its deadline passes the set is closed: the
        // rows already generated stay pending and keep being chased, but no
        // new ones appear behind the deadline.
        /** @var list<ReportingPeriod> $periods */
        $periods = ReportingPeriod::query()
            ->open($asOf)
            ->where('due_at', '>', $asOf)
            ->orderBy('due_at')
            ->get()
            ->all();

        if ($periods === []) {
            return 0;
        }

        $touched = 0;

        foreach (Tenant::query()->where('is_active', true)->cursor() as $tenant) {
            $touched += $current->runAs($tenant, fn (): int => $this->forTenant($periods));
        }

        return $touched;
    }

    /**
     * @param  list<ReportingPeriod>  $periods
     */
    private function forTenant(array $periods): int
    {
        $settings = app(SettingsRepository::class);

        // Read inside the tenant context, so an MDA may run a different
        // default cadence or report on a different set of statuses.
        $statuses = $settings->strings('reporting', 'obligation_statuses', ['mobilized', 'in_progress', 'completed']);
        $defaultFrequency = $settings->string('reporting', 'default_frequency', 'monthly');
        $touched = 0;

        foreach ($periods as $period) {
            $cadence = $period->cadence->value;

            $projects = Project::query()
                ->whereIn('status', $statuses)
                ->where(function ($query) use ($cadence, $defaultFrequency): void {
                    $query->where('reporting_frequency', $cadence);

                    // A project naming no cadence of its own inherits the
                    // instance default — that is what the nullable column
                    // means, and it must not silently owe nothing.
                    if ($cadence === $defaultFrequency) {
                        $query->orWhereNull('reporting_frequency');
                    }
                })
                ->select(['id'])
                ->cursor();

            foreach ($projects as $project) {
                $touched += $this->upsert($period, (int) $project->id);
            }
        }

        return $touched;
    }

    /**
     * The upsert, under a lock so two overlapping crons cannot both insert.
     */
    private function upsert(ReportingPeriod $period, int $projectId): int
    {
        return DB::transaction(function () use ($period, $projectId): int {
            $obligation = ReportObligation::query()
                ->where('reporting_period_id', $period->id)
                ->where('project_id', $projectId)
                ->lockForUpdate()
                ->first();

            if ($obligation === null) {
                ReportObligation::query()->create([
                    'reporting_period_id' => $period->id,
                    'project_id' => $projectId,
                    'due_at' => $period->due_at,
                ]);

                return 1;
            }

            // History stays immutable: a fulfilled, waived or missed row keeps
            // the deadline it was judged against.
            if ($obligation->status !== ReportObligationStatus::Pending) {
                return 0;
            }

            if ($obligation->due_at->equalTo($period->due_at)) {
                return 0;
            }

            $obligation->forceFill(['due_at' => $period->due_at])->save();

            return 1;
        });
    }
}
