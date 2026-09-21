<?php

declare(strict_types=1);

namespace App\Actions\Oversight;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * The state's audit log: every recorded act, across every MDA, in one list.
 *
 * APPEND-ONLY. This class reads. There is no update path and no delete path
 * here or on any screen that uses it, and there never will be — an audit trail
 * a privileged user can edit is not an audit trail. Retention is a
 * configuration concern (a scheduled purge by age), never a feature.
 *
 * THE AUTHORITY CHECK COMES FIRST, and it is read from the GLOBAL permission
 * team. An MDA admin holds plenty of permissions inside their own workspace
 * and holds `oversight.audit.view` nowhere, however the request arrived; the
 * tenancy bypass below happens only on the far side of that check.
 *
 * FILTERING BY MDA, without a tenant_id on activity_log. The table
 * deliberately carries none — it must record oversight acts, which belong to
 * no workspace — so "show me what happened in Works" is answered through the
 * SUBJECT: for every subject type present in the log that is a tenant-owned
 * model, the ids belonging to that workspace, plus the workspace record
 * itself. This is self-maintaining (a module shipped next month becomes
 * filterable the moment it writes its first entry) and it works retroactively,
 * which a column added today would not.
 *
 * TWO SHAPES, ONE BUILDER — `__invoke()` paginates the screen and `chunk()`
 * streams the CSV over the same query(), so the export can never disagree with
 * what is on screen. The bypass wraps the EXECUTION, not the builder: the
 * subject-id subqueries and the eager loads each run as their own query and
 * would otherwise meet the fail-closed scope with no tenant bound.
 */
class ListActivityAcrossTenants
{
    /**
     * @param  array{actor?: User|null, log?: string|null, subjectType?: string|null, tenant?: Tenant|null, from?: CarbonImmutable|null, to?: CarbonImmutable|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, Activity>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $this->authorize($actor);

        return app(CurrentTenant::class)->bypass(
            fn (): LengthAwarePaginator => $this->query($filters)->with(['causer'])->paginate($perPage),
        );
    }

    /**
     * The same list, streamed for the export.
     *
     * @param  array{actor?: User|null, log?: string|null, subjectType?: string|null, tenant?: Tenant|null, from?: CarbonImmutable|null, to?: CarbonImmutable|null, search?: string|null}  $filters
     * @param  callable(Collection<int, Activity>): void  $callback
     */
    public function chunk(User $actor, array $filters, callable $callback, int $size = 500): void
    {
        $this->authorize($actor);

        app(CurrentTenant::class)->bypass(function () use ($filters, $callback, $size): void {
            $this->query($filters)->with(['causer'])->chunkById($size, $callback);
        });
    }

    /**
     * The log names present in the table, for the filter bar. Cheap, indexed,
     * and honest: it offers what is actually there rather than a hard-coded
     * list that goes stale the moment a module is added.
     *
     * @return list<string>
     */
    public function logNames(User $actor): array
    {
        $this->authorize($actor);

        return app(CurrentTenant::class)->bypass(
            fn (): array => Activity::query()
                ->whereNotNull('log_name')
                ->distinct()
                ->orderBy('log_name')
                ->pluck('log_name')
                ->all(),
        );
    }

    /**
     * Subject types present in the log, as morph alias => readable label.
     *
     * @return array<string, string>
     */
    public function subjectTypes(User $actor): array
    {
        $this->authorize($actor);

        $types = app(CurrentTenant::class)->bypass(
            fn (): array => Activity::query()
                ->whereNotNull('subject_type')
                ->distinct()
                ->orderBy('subject_type')
                ->pluck('subject_type')
                ->all(),
        );

        $options = [];

        foreach ($types as $type) {
            $options[(string) $type] = class_basename((string) Relation::getMorphedModel((string) $type) ?: (string) $type);
        }

        return $options;
    }

    /**
     * The ids of users who have actually caused an entry, for the actor
     * filter. Distinct over an indexed column, so it stays cheap as the log
     * grows.
     *
     * @return list<int>
     */
    public function causerIds(User $actor): array
    {
        $this->authorize($actor);

        return app(CurrentTenant::class)->bypass(
            fn (): array => Activity::query()
                ->where('causer_type', (new User)->getMorphClass())
                ->whereNotNull('causer_id')
                ->distinct()
                ->pluck('causer_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
        );
    }

    private function authorize(User $actor): void
    {
        if (! $actor->holdsGlobalPermission('oversight.audit.view')) {
            throw new AuthorizationException('Reading the state audit log requires oversight.audit.view authority.');
        }
    }

    /**
     * @param  array{actor?: User|null, log?: string|null, subjectType?: string|null, tenant?: Tenant|null, from?: CarbonImmutable|null, to?: CarbonImmutable|null, search?: string|null}  $filters
     * @return Builder<Activity>
     */
    private function query(array $filters): Builder
    {
        $search = $filters['search'] ?? null;

        return Activity::query()
            ->when(
                ($filters['actor'] ?? null) instanceof User,
                fn (Builder $query) => $query
                    ->where('causer_type', (new User)->getMorphClass())
                    ->where('causer_id', $filters['actor']->getKey()),
            )
            ->when(
                ($filters['log'] ?? null) !== null && $filters['log'] !== '',
                fn (Builder $query) => $query->where('log_name', $filters['log']),
            )
            ->when(
                ($filters['subjectType'] ?? null) !== null && $filters['subjectType'] !== '',
                fn (Builder $query) => $query->where('subject_type', $filters['subjectType']),
            )
            ->when(
                ($filters['from'] ?? null) instanceof CarbonImmutable,
                fn (Builder $query) => $query->where('created_at', '>=', $filters['from']),
            )
            ->when(
                ($filters['to'] ?? null) instanceof CarbonImmutable,
                fn (Builder $query) => $query->where('created_at', '<=', $filters['to']),
            )
            ->when(
                is_string($search) && trim($search) !== '',
                fn (Builder $query) => $query->where('description', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $search)).'%'),
            )
            ->when(
                ($filters['tenant'] ?? null) instanceof Tenant,
                fn (Builder $query) => $this->scopeToTenant($query, $filters['tenant']),
            )
            // Newest first: an audit log is read from the top.
            ->orderByDesc('id');
    }

    /**
     * Narrow to the acts that happened inside one workspace.
     *
     * @param  Builder<Activity>  $query
     */
    private function scopeToTenant(Builder $query, Tenant $tenant): void
    {
        $query->where(function (Builder $scoped) use ($tenant): void {
            // The workspace record itself: provisioning, branding, suspension.
            $scoped->where(function (Builder $own) use ($tenant): void {
                $own->where('subject_type', $tenant->getMorphClass())
                    ->where('subject_id', $tenant->getKey());
            });

            foreach ($this->tenantOwnedSubjectTypes() as $morphAlias => $class) {
                $scoped->orWhere(function (Builder $byType) use ($morphAlias, $class, $tenant): void {
                    /** @var Model $instance */
                    $instance = new $class;

                    $byType->where('subject_type', $morphAlias)
                        // newQueryWithoutScopes, deliberately and only here:
                        // this runs inside the oversight bypass (so the tenant
                        // scope is already off) and the workspace is pinned
                        // explicitly on the next line. What it additionally
                        // drops is the soft-delete scope — which is the point.
                        // An audit trail outlives the record it describes, and
                        // a deleted project's history must not vanish from the
                        // log the moment somebody deletes the project.
                        ->whereIn('subject_id', $instance->newQueryWithoutScopes()
                            ->whereBelongsTo($tenant)
                            ->select($instance->getQualifiedKeyName()));
                });
            }
        });
    }

    /**
     * The subject types in the log that are tenant-owned models.
     *
     * Discovered from the log rather than declared: every module that writes
     * activity for a BelongsToTenant model becomes filterable by workspace
     * without touching this class, and a list maintained by hand here would be
     * exactly one module behind forever.
     *
     * @return array<string, class-string<Model>>
     */
    private function tenantOwnedSubjectTypes(): array
    {
        $types = Activity::query()
            ->whereNotNull('subject_type')
            ->distinct()
            ->pluck('subject_type')
            ->all();

        $tenantOwned = [];

        foreach ($types as $type) {
            $type = (string) $type;
            $class = Relation::getMorphedModel($type) ?: $type;

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            if (in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                $tenantOwned[$type] = $class;
            }
        }

        return $tenantOwned;
    }
}
