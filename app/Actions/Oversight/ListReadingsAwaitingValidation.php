<?php

namespace App\Actions\Oversight;

use App\Enums\IndicatorReadingStatus;
use App\Models\IndicatorReading;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Data Quality Reviewer's cross-MDA queue: every figure submitted by any
 * ministry and not yet validated.
 *
 * This is the one place in the indicators module that crosses MDA boundaries,
 * and it is a deliberate, named privilege rather than a convenience
 * (rules/tenancy.md, enforced by the discipline test). The permission is
 * re-checked HERE, in the GLOBAL permission team, BEFORE any bypass — an
 * MDA-scoped role holding `indicators.readings.validate` inside its own
 * workspace must not be able to reach another ministry's figures by calling
 * this Action, and a check that ran after the bypass would already have opened
 * the door.
 *
 * Eager-loads what the row renders — tenant, indicator, project, and the two
 * people whose identities the separation guard weighs. A cross-MDA queue is
 * exactly the query where an N+1 becomes hundreds of round trips.
 */
class ListReadingsAwaitingValidation
{
    /**
     * @param  array{tenant?: Tenant|null, sector?: Sector|null, search?: string|null, status?: IndicatorReadingStatus|null}  $filters
     * @return LengthAwarePaginator<int, IndicatorReading>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        if (! $actor->holdsGlobalPermission('oversight.validation.review')) {
            throw new AuthorizationException('Reviewing submitted figures across MDAs requires oversight authority.');
        }

        // bypass(), not just withoutTenancy(): the scope removal applies to
        // the query it is called on, while the eager loads below are separate
        // queries against tenant-owned models (indicators, projects) that
        // would each hit the fail-closed scope with no tenant bound.
        return app(CurrentTenant::class)->bypass(fn (): LengthAwarePaginator => $this->query($filters, $perPage));
    }

    /**
     * How many figures are waiting, state-wide — the oversight dashboard's
     * count, answered without paginating the queue itself.
     */
    public function count(User $actor): int
    {
        if (! $actor->holdsGlobalPermission('oversight.validation.review')) {
            throw new AuthorizationException('Reviewing submitted figures across MDAs requires oversight authority.');
        }

        return app(CurrentTenant::class)->bypass(
            fn (): int => IndicatorReading::query()->awaitingValidation()->count(),
        );
    }

    /**
     * @param  array{tenant?: Tenant|null, sector?: Sector|null, search?: string|null, status?: IndicatorReadingStatus|null}  $filters
     * @return LengthAwarePaginator<int, IndicatorReading>
     */
    private function query(array $filters, int $perPage): LengthAwarePaginator
    {
        $status = $filters['status'] ?? null;

        return IndicatorReading::query()
            ->with([
                'tenant:id,name,slug',
                'indicator:id,ulid,name,unit,tier,measurement_frequency,target_type,baseline_value,project_id,result_framework_id',
                'indicator.project:id,ulid,title,reference,sector_id',
                'indicator.latestTarget',
                'recordedBy:id,name',
                'submittedBy:id,name',
            ])
            ->when(
                $status instanceof IndicatorReadingStatus,
                fn (Builder $query) => $query->where('status', $status),
                // The queue's reason to exist: what is waiting on a reviewer.
                fn (Builder $query) => $query->awaitingValidation(),
            )
            ->when(
                ($filters['tenant'] ?? null) instanceof Tenant,
                // whereBelongsTo(), never a hand-written tenant clause. Manual
                // tenant filters are banned platform-wide, and "except in
                // oversight code" is the exception that stops being read as one.
                fn (Builder $query) => $query->whereBelongsTo($filters['tenant']),
            )
            ->when(
                ($filters['sector'] ?? null) instanceof Sector,
                fn (Builder $query) => $query->whereHas(
                    'indicator.project',
                    fn (Builder $project) => $project->whereBelongsTo($filters['sector']),
                ),
            )
            ->when(
                ($filters['search'] ?? null) !== null && $filters['search'] !== '',
                fn (Builder $query) => $query->whereHas(
                    'indicator',
                    fn (Builder $indicator) => $indicator->where(
                        'name',
                        'like',
                        '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $filters['search']).'%',
                    ),
                ),
            )
            // Oldest submission first: a validation queue is a queue, and the
            // figure that has been waiting longest is the one holding up a
            // report.
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->paginate($perPage);
    }
}
