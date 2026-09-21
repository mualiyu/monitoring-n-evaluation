<?php

namespace App\Actions\Workplans;

use App\Enums\ActivityStatus;
use App\Exceptions\Workplans\WorkplanRuleViolation;
use App\Models\User;
use App\Models\WorkplanActivity;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The REPORTED side of an activity: how far along it is, what it has cost, and
 * when it actually started and finished.
 *
 * This Action deliberately does NOT consult the approval freeze. An approved
 * plan is precisely the plan you report against — freezing progress with the
 * definition would leave an MDA with an authorised programme it cannot
 * account for. The freeze is on WorkplanActivity::DEFINITION_FIELDS and lives
 * in UpdateWorkplanActivity.
 *
 * Status is DERIVED, never posted: ActivityStatus::derive() is the single
 * definition of started/late/done, shared with the overdue sweep and the Gantt
 * so no two screens can disagree about whether a line is slipping. Cancelled
 * is the one exception — a cancelled line is a decision, and this Action
 * refuses to report against it rather than quietly reviving it.
 *
 * Actual dates are inferred where the officer did not give them, because
 * "50% done with no start date" is a contradiction the data should not carry:
 * first progress stamps a start, 100% stamps a finish, and dropping back below
 * 100% clears the finish again.
 */
class RecordActivityProgress
{
    /**
     * @param  array{progress_percent?: int|string, expenditure_to_date?: Money|int|string|null, actual_start?: string|CarbonImmutable|null, actual_end?: string|CarbonImmutable|null}  $attributes
     */
    public function __invoke(WorkplanActivity $activity, User $actor, array $attributes): WorkplanActivity
    {
        Gate::forUser($actor)->authorize('recordProgress', $activity);

        $workplan = $activity->loadMissing('workplan')->workplan;

        if (! $workplan->status->acceptsProgress()) {
            throw WorkplanRuleViolation::progressNotAccepted($workplan->status);
        }

        if ($activity->status === ActivityStatus::Cancelled) {
            throw WorkplanRuleViolation::cancelledActivity();
        }

        $percent = (int) ($attributes['progress_percent'] ?? $activity->progress_percent);

        if ($percent < 0 || $percent > 100) {
            throw WorkplanRuleViolation::progressOutOfRange($percent);
        }

        $now = CarbonImmutable::now();

        $changes = [
            'progress_percent' => $percent,
            'status' => ActivityStatus::derive($percent, $activity->planned_end, $now),
            'actual_start' => $this->actualStart($activity, $attributes, $percent, $now),
            'actual_end' => $this->actualEnd($activity, $attributes, $percent, $now),
            'progress_recorded_at' => $now,
        ];

        if (array_key_exists('expenditure_to_date', $attributes)) {
            $changes['expenditure_to_date'] = $attributes['expenditure_to_date'] ?? Money::zero();
        }

        DB::transaction(function () use ($activity, $changes): void {
            // forceFill: every one of these columns is deliberately not
            // fillable, so this Action stays the only writer of them and the
            // assignment is explicit rather than a mass-assignment side effect.
            $activity->forceFill($changes)->save();
        });

        return $activity;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function actualStart(
        WorkplanActivity $activity,
        array $attributes,
        int $percent,
        CarbonImmutable $now,
    ): ?CarbonImmutable {
        if (array_key_exists('actual_start', $attributes) && $attributes['actual_start'] !== null) {
            return CarbonImmutable::parse((string) $attributes['actual_start']);
        }

        if ($activity->actual_start !== null) {
            return $activity->actual_start;
        }

        // Work reported is work started. Recorded as today rather than as the
        // planned date: the plan is what was intended, this column is what
        // happened, and conflating them is how slippage disappears.
        return $percent > 0 ? $now->startOfDay() : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function actualEnd(
        WorkplanActivity $activity,
        array $attributes,
        int $percent,
        CarbonImmutable $now,
    ): ?CarbonImmutable {
        if ($percent < 100) {
            // Reopened: an activity that is no longer complete has no
            // completion date, whatever it used to have.
            return null;
        }

        if (array_key_exists('actual_end', $attributes) && $attributes['actual_end'] !== null) {
            return CarbonImmutable::parse((string) $attributes['actual_end']);
        }

        return $activity->actual_end ?? $now->startOfDay();
    }
}
