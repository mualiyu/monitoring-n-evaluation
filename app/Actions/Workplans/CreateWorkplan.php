<?php

namespace App\Actions\Workplans;

use App\Enums\WorkplanStatus;
use App\Exceptions\Workplans\WorkplanRuleViolation;
use App\Models\User;
use App\Models\Workplan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Opens an MDA's Annual Work Plan & Budget for a year.
 *
 * ONE PLAN PER (MDA, YEAR, BASIS), enforced here under a lock rather than by a
 * unique index: soft deletes make such an index either block re-creation after
 * a discard or, with deleted_at in the key, enforce nothing (the same reasoning
 * as StartProgressReport). Two plans for one year is two answers to "what are
 * we delivering this year", and the second one is always the one somebody is
 * working from by mistake.
 *
 * The creation is written to the append-only ledger, so the plan's timeline
 * starts where the plan did rather than at its first status move.
 */
class CreateWorkplan
{
    public function __construct(private readonly TransitionWorkplanStatus $chain) {}

    /**
     * @param  array<string, mixed>  $attributes  validated plan fields
     */
    public function __invoke(User $actor, array $attributes): Workplan
    {
        Gate::forUser($actor)->authorize('create', Workplan::class);

        $attributes['created_by_id'] = $actor->id;
        $attributes['owner_id'] ??= $actor->id;

        $this->assertPeriodIsSane($attributes);

        return DB::transaction(function () use ($actor, $attributes): Workplan {
            // lockForUpdate on the existence check, so two officers opening
            // next year's plan in the same minute cannot both win. The
            // TenantScope keeps the check inside this MDA.
            $existing = Workplan::query()
                ->lockForUpdate()
                ->where('year', $attributes['year'])
                ->where('year_basis', $attributes['year_basis'] ?? 'calendar')
                ->first();

            if ($existing !== null) {
                throw WorkplanRuleViolation::duplicateYear(
                    (int) $attributes['year'],
                    (string) ($attributes['year_basis'] ?? 'calendar'),
                );
            }

            $workplan = new Workplan($attributes);

            // Not fillable, so assigned explicitly here: `status` is
            // TransitionWorkplanStatus's column, and stating the opening value
            // in code rather than leaning on the column default means the
            // in-memory model is never a `null` status waiting for a refresh
            // to become real — recordCreation() below reads it, and the
            // ledger's to_status is NOT NULL. Same shape as
            // App\Actions\Reporting\StartProgressReport.
            $workplan->forceFill(['status' => WorkplanStatus::Draft])->save();

            // The ledger row joins the same transaction as the insert: a plan
            // that exists with no record of who opened it is exactly the gap
            // an append-only ledger is for.
            $this->chain->recordCreation($workplan, $actor);

            return $workplan;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertPeriodIsSane(array $attributes): void
    {
        $start = CarbonImmutable::parse((string) $attributes['period_start']);
        $end = CarbonImmutable::parse((string) $attributes['period_end']);

        if (! $end->isAfter($start)) {
            throw WorkplanRuleViolation::periodReversed();
        }
    }
}
