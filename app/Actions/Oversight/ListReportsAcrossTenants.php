<?php

namespace App\Actions\Oversight;

use App\Enums\ProgressReportStatus;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The cross-MDA reports desk (progress-reporting.md §6, "Oversight /reports"):
 * every return the state has been sent, read-only, in one list.
 *
 * Same shape as ListProjectsAcrossTenants, and for the same reasons: the
 * cross-tenant read is an explicit oversight privilege, so the bypass lives
 * next to the authority check that justifies it, and authority is read from
 * the GLOBAL permission team — an MDA admin holds `reports.view` in their own
 * workspace and nothing at all here, however the request arrived.
 *
 * WHICH RETURNS. The universe is `submitted | reviewed | approved` — a draft
 * is not a return, it is someone's unfinished typing, and a `returned` one is
 * back with its author for correction. Both re-enter this desk the moment they
 * are (re)filed, so nothing is hidden from the state permanently; what is
 * excluded is work the MDA has not yet handed over.
 *
 * TWO SHAPES, ONE BUILDER. `__invoke()` paginates for the screen and `chunk()`
 * streams for the CSV — both over `query()`, because a row that is on the
 * screen and missing from the export (or the reverse) is the defect this
 * arrangement exists to prevent. The bypass has to wrap the EXECUTION, not the
 * builder: eager loads run as separate queries and would each meet the
 * fail-closed scope with no tenant bound.
 *
 * NOTE on filtering by MDA: `whereBelongsTo()`, never a hand-written
 * `where('tenant_id', …)`. Manual tenant clauses are banned platform-wide, and
 * "except in oversight code" is exactly the exception that stops being read as
 * an exception.
 */
class ListReportsAcrossTenants
{
    /** The states the state surface may see — see the class docblock. */
    public const VISIBLE_STATUSES = [
        ProgressReportStatus::Submitted,
        ProgressReportStatus::Reviewed,
        ProgressReportStatus::Approved,
    ];

    /**
     * @param  array{tenant?: Tenant|null, period?: ReportingPeriod|null, status?: ProgressReportStatus|null, search?: string|null, lateness?: string|null}  $filters
     * @return LengthAwarePaginator<int, ProgressReport>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $this->authorize($actor);

        return app(CurrentTenant::class)->bypass(
            fn (): LengthAwarePaginator => $this->query($filters)
                ->with([
                    'tenant:id,name,slug',
                    'project:id,ulid,title,reference',
                    'reportingPeriod:id,code,label,due_at',
                    'submittedBy:id,name',
                ])
                ->paginate($perPage),
        );
    }

    /**
     * The same list, streamed in chunks for the export. Identical filters,
     * identical ordering; only the eager loads are trimmed to what a CSV row
     * prints.
     *
     * @param  array{tenant?: Tenant|null, period?: ReportingPeriod|null, status?: ProgressReportStatus|null, search?: string|null, lateness?: string|null}  $filters
     * @param  callable(Collection<int, ProgressReport>): void  $callback
     */
    public function chunk(User $actor, array $filters, callable $callback, int $size = 500): void
    {
        $this->authorize($actor);

        app(CurrentTenant::class)->bypass(function () use ($filters, $callback, $size): void {
            $this->query($filters)
                ->with([
                    'tenant:id,name',
                    'project:id,title,reference',
                    'reportingPeriod:id,label',
                    'submittedBy:id,name',
                ])
                ->chunk($size, $callback);
        });
    }

    /**
     * The summary strip above the list: the same filters, counted in ONE
     * grouped query rather than by paging through the result. It describes
     * exactly what is on screen — a summary that ignored the filter bar would
     * be a different question answered in the same place.
     *
     * @param  array{tenant?: Tenant|null, period?: ReportingPeriod|null, status?: ProgressReportStatus|null, search?: string|null, lateness?: string|null}  $filters
     * @return array{total: int, late: int, on_time: int, entities: int}
     */
    public function summarise(User $actor, array $filters = []): array
    {
        $this->authorize($actor);

        /** @var object|null $row */
        $row = app(CurrentTenant::class)->bypass(fn (): ?object => $this->query($filters)
            ->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(CASE WHEN submitted_late = 1 THEN 1 END) as late')
            ->selectRaw('COUNT(CASE WHEN submitted_late = 0 THEN 1 END) as on_time')
            ->selectRaw('COUNT(DISTINCT tenant_id) as entities')
            ->reorder()
            ->first());

        return [
            'total' => (int) ($row->total ?? 0),
            'late' => (int) ($row->late ?? 0),
            'on_time' => (int) ($row->on_time ?? 0),
            'entities' => (int) ($row->entities ?? 0),
        ];
    }

    private function authorize(User $actor): void
    {
        if (! $actor->holdsGlobalPermission('oversight.reports.view')) {
            throw new AuthorizationException('Viewing returns across MDAs requires oversight authority.');
        }
    }

    /**
     * @param  array{tenant?: Tenant|null, period?: ReportingPeriod|null, status?: ProgressReportStatus|null, search?: string|null, lateness?: string|null}  $filters
     * @return Builder<ProgressReport>
     */
    private function query(array $filters): Builder
    {
        $status = $filters['status'] ?? null;

        // A status filter NARROWS the visible universe, it never widens it: an
        // unexpected value (a draft, say) intersects to nothing and the desk
        // shows nothing, rather than reaching past what oversight may read.
        $statuses = array_values(array_filter(
            self::VISIBLE_STATUSES,
            fn (ProgressReportStatus $case): bool => ! $status instanceof ProgressReportStatus || $case === $status,
        ));

        return ProgressReport::query()
            ->whereIn('status', array_map(
                fn (ProgressReportStatus $case): string => $case->value,
                $status instanceof ProgressReportStatus ? $statuses : self::VISIBLE_STATUSES,
            ))
            ->when(
                ($filters['tenant'] ?? null) instanceof Tenant,
                fn (Builder $query) => $query->whereBelongsTo($filters['tenant']),
            )
            ->when(
                ($filters['period'] ?? null) instanceof ReportingPeriod,
                fn (Builder $query) => $query->whereBelongsTo($filters['period'], 'reportingPeriod'),
            )
            ->when(
                ($filters['search'] ?? null) !== null && $filters['search'] !== '',
                fn (Builder $query) => $query->whereIn('project_id', $this->projectMatches((string) $filters['search'])),
            )
            ->when(
                ($filters['lateness'] ?? null) === 'late',
                fn (Builder $query) => $query->where('submitted_late', true),
            )
            ->when(
                ($filters['lateness'] ?? null) === 'on_time',
                fn (Builder $query) => $query->where('submitted_late', false),
            )
            // Most recently filed first: a state desk is read from the top.
            ->orderByDesc('submitted_at')
            ->orderByDesc('id');
    }

    /**
     * Projects matching the search box, as a subquery. Inside the bypass this
     * crosses MDAs exactly as the outer query does — one bypass, one scope
     * decision, rather than a second read that quietly disagrees.
     *
     * @return Builder<Project>
     */
    private function projectMatches(string $search): Builder
    {
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($search)).'%';

        return Project::query()
            ->where(fn (Builder $match) => $match
                ->where('title', 'like', $term)
                ->orWhere('reference', 'like', $term))
            ->select('id');
    }
}
