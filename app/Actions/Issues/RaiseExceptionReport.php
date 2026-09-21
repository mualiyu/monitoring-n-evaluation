<?php

namespace App\Actions\Issues;

use App\Enums\ExceptionStatus;
use App\Enums\ExceptionTrigger;
use App\Enums\IssueSeverity;
use App\Exceptions\Issues\IssueRuleViolation;
use App\Jobs\Issues\NotifyExceptionReportRaised;
use App\Models\ExceptionReport;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Filing the manual's Exception Report (Table 5.2 — "on critical incidence /
 * high deviation"), from either of its two doors:
 *
 *   - App\Actions\Issues\EvaluateProjectThresholds, with no actor, when a
 *     project trips a configured tolerance; and
 *   - a person, for a critical incident on site or a considered manual
 *     deviation report.
 *
 * THE DUPLICATE GATE is the whole difficulty of the automated door. The sweep
 * runs nightly and a project 30 points behind schedule is still 30 points
 * behind tomorrow, so the naive version files the same deviation every night
 * until somebody mutes the entire feature. The gate is therefore structural
 * rather than a cleanup job: one live report per (project, trigger), checked
 * and written under the same row lock, exactly as FlagOverdueObligations
 * advances its counters.
 *
 * "Live" includes `acknowledged`, not just `open`: an officer who has seen the
 * deviation and accepted it does not need to be told again tonight.
 *
 * The gate deliberately does NOT apply to the human triggers. A second
 * critical incident on the same site is a second fact, and refusing to record
 * it because the first is still open would lose it.
 */
class RaiseExceptionReport
{
    /**
     * @param  array{
     *     narrative: string,
     *     severity?: IssueSeverity|null,
     *     measured_value?: float|string|null,
     *     threshold_value?: float|string|null,
     *     physical_progress?: float|string|null,
     *     schedule_elapsed?: float|string|null,
     *     financial_progress?: float|string|null,
     *     measured_at?: CarbonImmutable|null,
     * }  $attributes
     * @param  User|null  $actor  null = raised by the threshold engine
     * @return ExceptionReport|null null when the duplicate gate refused an
     *                              automated raise; a human raise throws
     *                              instead, because a silent no-op on a
     *                              screen reads as a broken button
     */
    public function __invoke(
        Project $project,
        ExceptionTrigger $trigger,
        array $attributes,
        ?User $actor = null,
    ): ?ExceptionReport {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('create', ExceptionReport::class);
            Gate::forUser($actor)->authorize('view', $project);
        }

        $narrative = trim($attributes['narrative']);

        if ($narrative === '') {
            throw IssueRuleViolation::narrativeRequired();
        }

        $report = DB::transaction(function () use ($project, $trigger, $attributes, $actor, $narrative): ?ExceptionReport {
            // The gate lives on a SET of rows, not on one, so the project is
            // the lock anchor: two sweeps (or a sweep and an officer) racing
            // on the same project serialise here, and the second one sees the
            // first one's report before deciding.
            $locked = Project::query()->lockForUpdate()->find($project->getKey());

            if ($locked === null) {
                return null;
            }

            if ($trigger->isAutomatic() && $this->alreadyLive($locked->id, $trigger)) {
                if ($actor !== null) {
                    throw IssueRuleViolation::duplicateCondition($trigger->value);
                }

                return null;
            }

            $new = new ExceptionReport([
                'project_id' => $locked->id,
                'trigger' => $trigger,
                'severity' => $attributes['severity'] ?? $trigger->defaultSeverity(),
                'narrative' => $narrative,
                'measured_value' => $attributes['measured_value'] ?? null,
                'threshold_value' => $attributes['threshold_value'] ?? null,
                'physical_progress' => $attributes['physical_progress'] ?? null,
                'schedule_elapsed' => $attributes['schedule_elapsed'] ?? null,
                'financial_progress' => $attributes['financial_progress'] ?? null,
                'measured_at' => $attributes['measured_at'] ?? now(),
                'raised_by_id' => $actor?->id,
            ]);

            // `status` is not fillable — every report starts open and only
            // TransitionExceptionStatus moves it.
            $new->status = ExceptionStatus::Open;
            $new->save();

            return $new;
        });

        if ($report !== null) {
            // After the transaction, never inside it. The job re-reads the row.
            NotifyExceptionReportRaised::dispatch($report->id);
        }

        return $report;
    }

    /** Is this condition already on the record and unresolved? */
    private function alreadyLive(int $projectId, ExceptionTrigger $trigger): bool
    {
        return ExceptionReport::query()
            ->where('project_id', $projectId)
            ->where('trigger', $trigger)
            ->live()
            ->exists();
    }
}
