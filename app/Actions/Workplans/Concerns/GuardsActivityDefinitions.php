<?php

namespace App\Actions\Workplans\Concerns;

use App\Exceptions\Workplans\WorkplanRuleViolation;
use App\Models\Workplan;
use App\Models\WorkplanActivity;
use Carbon\CarbonImmutable;

/**
 * The rules an activity's DEFINITION has to satisfy, shared by the Actions
 * that write one (Add / Update / Remove). Stated once so the add path and the
 * edit path can never disagree about what a valid plan line is — a class of
 * bug that ships quietly, because the add path is the one everybody tests.
 */
trait GuardsActivityDefinitions
{
    /**
     * The approval freeze (WorkplanStatus::allowsDefinitionEdits). It never
     * blocks recording progress: RecordActivityProgress does not call this.
     */
    protected function assertDefinitionsAreEditable(Workplan $workplan): void
    {
        if ($workplan->isFrozen()) {
            throw WorkplanRuleViolation::frozenPlan($workplan->status);
        }
    }

    /**
     * An activity lives inside its plan's year. A line scheduled outside it
     * makes the plan unmeasurable against its own period — the Gantt would
     * have to draw columns for months the plan does not cover, and the
     * schedule-health figure would compare work to a calendar it is not in.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function assertScheduleFitsPlan(Workplan $workplan, array $attributes, string $title): void
    {
        $start = CarbonImmutable::parse((string) $attributes['planned_start'])->startOfDay();
        $end = CarbonImmutable::parse((string) $attributes['planned_end'])->startOfDay();

        if ($end->isBefore($start)) {
            throw WorkplanRuleViolation::scheduleReversed($title);
        }

        if ($start->isBefore($workplan->period_start->startOfDay())
            || $end->isAfter($workplan->period_end->startOfDay())) {
            throw WorkplanRuleViolation::scheduleOutsidePeriod($title);
        }
    }

    /**
     * A dependency points at a sibling in the same plan, and never round in a
     * circle. The walk is bounded by the number of activities in the plan, so
     * a pre-existing cycle in the data cannot hang the request.
     */
    protected function assertDependencyIsSane(
        Workplan $workplan,
        ?int $dependsOnId,
        ?WorkplanActivity $activity = null,
    ): void {
        if ($dependsOnId === null) {
            return;
        }

        if ($activity !== null && $dependsOnId === $activity->id) {
            throw WorkplanRuleViolation::dependencyCycle();
        }

        // The TenantScope confines this read to the bound MDA; the
        // workplan_id clause confines it to this plan.
        $predecessor = WorkplanActivity::query()
            ->where('workplan_id', $workplan->id)
            ->whereKey($dependsOnId)
            ->first();

        if ($predecessor === null) {
            throw WorkplanRuleViolation::dependencyOutsidePlan();
        }

        if ($activity === null) {
            return; // a brand-new row cannot yet be depended upon
        }

        $seen = [];
        $cursor = $predecessor;
        $budget = WorkplanActivity::query()->where('workplan_id', $workplan->id)->count() + 1;

        while ($cursor !== null && $budget-- > 0) {
            if ($cursor->id === $activity->id) {
                throw WorkplanRuleViolation::dependencyCycle();
            }

            if (isset($seen[$cursor->id])) {
                break; // a cycle that predates this edit — not this edit's fault
            }

            $seen[$cursor->id] = true;
            $cursor = $cursor->depends_on_id === null
                ? null
                : WorkplanActivity::query()->whereKey($cursor->depends_on_id)->first();
        }
    }
}
