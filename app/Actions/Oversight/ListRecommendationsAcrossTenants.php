<?php

namespace App\Actions\Oversight;

use App\Enums\RecommendationPriority;
use App\Enums\RecommendationStatus;
use App\Models\Recommendation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The state-wide follow-up board: every MDA's outstanding recommendations in
 * one list, which is the view the secretariat needs and the one no single
 * workspace can produce.
 *
 * Together with ListEvaluationsAcrossTenants these are the ONLY tenancy
 * bypasses in this module, and `recommendations.view` is re-checked in the
 * GLOBAL permission team before either of them bypasses anything.
 *
 * Default ordering is deliberately "most pressing, longest ignored": priority
 * first, then the oldest deadline. A board sorted by created-at tells a
 * commissioner what was written most recently, which is the one question they
 * are not asking.
 */
class ListRecommendationsAcrossTenants
{
    /**
     * @param  array{tenant?: Tenant|null, status?: RecommendationStatus|null, priority?: RecommendationPriority|null, search?: string|null, overdue?: bool, outstanding?: bool}  $filters
     * @return LengthAwarePaginator<int, Recommendation>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        if (! $actor->holdsGlobalPermission('recommendations.view')) {
            throw new AuthorizationException('Viewing the follow-up register across MDAs requires oversight authority.');
        }

        return app(CurrentTenant::class)->bypass(fn (): LengthAwarePaginator => $this->query($filters, $perPage));
    }

    /**
     * @param  array{tenant?: Tenant|null, status?: RecommendationStatus|null, priority?: RecommendationPriority|null, search?: string|null, overdue?: bool, outstanding?: bool}  $filters
     * @return LengthAwarePaginator<int, Recommendation>
     */
    private function query(array $filters, int $perPage): LengthAwarePaginator
    {
        return Recommendation::query()
            ->with([
                'tenant:id,name,slug',
                'project:id,ulid,title,reference',
                'addressee:id,name',
            ])
            ->when(
                ($filters['tenant'] ?? null) instanceof Tenant,
                fn (Builder $query) => $query->whereBelongsTo($filters['tenant']),
            )
            ->when(
                ($filters['status'] ?? null) instanceof RecommendationStatus,
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                ($filters['priority'] ?? null) instanceof RecommendationPriority,
                fn (Builder $query) => $query->where('priority', $filters['priority']),
            )
            ->when(
                $filters['outstanding'] ?? false,
                fn (Builder $query) => $query->outstanding(),
            )
            ->when(
                $filters['overdue'] ?? false,
                fn (Builder $query) => $query->overdue(),
            )
            ->when(
                ($filters['search'] ?? null) !== null && $filters['search'] !== '',
                fn (Builder $query) => $query->where(
                    fn (Builder $match) => $match
                        ->where('title', 'like', '%'.$filters['search'].'%')
                        ->orWhere('addressee_body', 'like', '%'.$filters['search'].'%'),
                ),
            )
            // Priority is a string column, so "most pressing first" needs the
            // enum's own weighting expressed in SQL. Bound parameters, and the
            // CASE form rather than FIELD() so MySQL and SQLite agree.
            ->orderByRaw($this->priorityOrdering(), $this->priorityBindings())
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END, due_on')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    private function priorityOrdering(): string
    {
        $whens = str_repeat('WHEN ? THEN ? ', count(RecommendationPriority::cases()));

        return 'CASE priority '.$whens.'ELSE 99 END';
    }

    /**
     * @return list<string|int>
     */
    private function priorityBindings(): array
    {
        $bindings = [];

        foreach (RecommendationPriority::cases() as $priority) {
            $bindings[] = $priority->value;
            $bindings[] = $priority->weight();
        }

        return $bindings;
    }
}
