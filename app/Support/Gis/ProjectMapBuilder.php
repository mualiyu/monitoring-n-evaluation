<?php

namespace App\Support\Gis;

use App\Enums\ProjectMapCategory;
use App\Enums\ProjectStatus;
use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Sector;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The data behind both GIS dashboards: map pins, the summary tiles, the
 * category legend counts and the LGA breakdown — all derived from ONE filtered
 * project query, so a pin can never be on the map but missing from the LGA
 * table, or counted in a tile the filters excluded.
 *
 * It never decides WHO may see what. The caller hands in a query already
 * narrowed for the viewer — Project::visibleTo() on the tenant surface, the
 * authorized cross-MDA bypass on oversight — and this class only filters and
 * shapes it. That keeps the tenancy privilege in the one directory allowed to
 * hold it (app/Actions/Oversight/), not in a helper shared by both surfaces.
 * Every site query below reaches projects ONLY through that filtered query (as
 * a subquery), so the viewer's narrowing cannot be bypassed by a join.
 *
 * BOUNDED WORK, not just a bounded payload. The aggregates run in SQL, and at
 * most maxPins + 1 sites are ever loaded as models — overdue first, so the cap
 * can never hide the pins that need attention. (The first version loaded every
 * matching project and site to count them in PHP: 1.3 s and +108 MB for one
 * unfiltered oversight view at 10,000 projects.)
 */
class ProjectMapBuilder
{
    /** Maximum markers sent to one map view. */
    public const MAX_PINS = 1000;

    /** Statuses that mean the work is over — never overdue, whatever the date. */
    private const FINISHED = [
        ProjectStatus::Completed,
        ProjectStatus::Certified,
        ProjectStatus::Closed,
        ProjectStatus::Cancelled,
    ];

    public function __construct(private readonly int $maxPins = self::MAX_PINS) {}

    /**
     * @param  Builder<Project>  $projects  already narrowed to what the viewer may see
     * @param  array{tenant?: Tenant|null, status?: ProjectStatus|null, sector?: Sector|null, lga?: Lga|null, overdue?: bool}  $filters
     * @return array{
     *     pins: list<array<string, mixed>>,
     *     capped: bool,
     *     totals: array{projects: int, sites: int, contract_value: Money, overdue: int, unmapped: int},
     *     by_category: array<string, int>,
     *     by_lga: list<array{lga_id: int|null, name: string, projects: int, sites: int, overdue: int, average_progress: float}>
     * }
     */
    public function __invoke(Builder $projects, array $filters, bool $withEntity = false): array
    {
        $lga = ($filters['lga'] ?? null) instanceof Lga ? $filters['lga'] : null;
        $filtered = $this->filter($projects, $filters);

        // A site is mappable when it has BOTH coordinates; with an LGA filter
        // only that LGA's sites count — a multi-site road appears in every
        // LGA it crosses (projects-module.md §1.5), drawn in each one.
        $mappable = fn (Builder $location): Builder => $location
            ->whereNotNull('project_locations.latitude')
            ->whereNotNull('project_locations.longitude')
            ->when($lga instanceof Lga, fn (Builder $query) => $query->whereBelongsTo($lga));

        /** @var Builder<ProjectLocation> $sites */
        $sites = $mappable(ProjectLocation::query()
            ->whereIn('project_locations.project_id', (clone $filtered)->select('projects.id')));

        [$byCategory, $projectCount, $overdueCount, $contractValue] = $this->categories(
            (clone $filtered)->whereHas('locations', $mappable),
        );

        [$pins, $capped] = $this->pins(clone $sites, $withEntity);

        return [
            'pins' => $pins,
            'capped' => $capped,
            'totals' => [
                'projects' => $projectCount,
                'sites' => (clone $sites)->count(),
                'contract_value' => $contractValue,
                'overdue' => $overdueCount,
                // Matching projects that cannot be drawn: no site with a GPS
                // fix. A data-quality number the M&E unit should drive to zero.
                'unmapped' => (clone $filtered)->whereDoesntHave('locations', $mappable)->count(),
            ],
            'by_category' => $byCategory,
            'by_lga' => $this->lgaRows(clone $sites),
        ];
    }

    /**
     * The shared filter set. Model-typed on purpose: tenant and sector filter
     * through whereBelongsTo(), so no hand-written tenant clause exists here.
     *
     * @param  Builder<Project>  $projects
     * @param  array{tenant?: Tenant|null, status?: ProjectStatus|null, sector?: Sector|null, lga?: Lga|null, overdue?: bool}  $filters
     * @return Builder<Project>
     */
    private function filter(Builder $projects, array $filters): Builder
    {
        [$overdue, $bindings] = $this->overdueExpression();

        return $projects
            ->when(($filters['tenant'] ?? null) instanceof Tenant, fn (Builder $query) => $query->whereBelongsTo($filters['tenant']))
            ->when(($filters['status'] ?? null) instanceof ProjectStatus, fn (Builder $query) => $query->where('projects.status', $filters['status']))
            ->when(($filters['sector'] ?? null) instanceof Sector, fn (Builder $query) => $query->whereBelongsTo($filters['sector']))
            ->when(($filters['lga'] ?? null) instanceof Lga, fn (Builder $query) => $query->whereHas(
                'locations',
                fn (Builder $location) => $location->whereBelongsTo($filters['lga']),
            ))
            // Raw because "overdue" is a derived condition over three columns;
            // the one definition lives in overdueExpression(), with bindings.
            ->when($filters['overdue'] ?? false, fn (Builder $query) => $query->whereRaw($overdue.' = 1', $bindings));
    }

    /**
     * Legend counts and project-level totals in one grouped query: one row per
     * (status, overdue) pair, folded into map categories here.
     *
     * @param  Builder<Project>  $mapped
     * @return array{0: array<string, int>, 1: int, 2: int, 3: Money}
     */
    private function categories(Builder $mapped): array
    {
        [$overdue, $bindings] = $this->overdueExpression();

        $rows = $mapped->toBase()
            ->selectRaw('projects.status as status')
            ->selectRaw($overdue.' as is_overdue', $bindings)
            ->selectRaw('COUNT(*) as aggregate')
            ->selectRaw('SUM(projects.contract_value_total) as contract_value')
            ->groupBy('projects.status')
            ->groupByRaw($overdue, $bindings)
            ->get();

        $byCategory = array_fill_keys(array_map(fn (ProjectMapCategory $c): string => $c->value, ProjectMapCategory::cases()), 0);
        $projects = 0;
        $overdueCount = 0;
        $value = Money::zero();

        foreach ($rows as $row) {
            $status = ProjectStatus::tryFrom((string) $row->status);

            if (! $status instanceof ProjectStatus) {
                continue;
            }

            $count = (int) $row->aggregate;
            $isOverdue = (int) $row->is_overdue === 1;

            $byCategory[ProjectMapCategory::for($status, $isOverdue)->value] += $count;
            $projects += $count;
            $overdueCount += $isOverdue ? $count : 0;
            $value = $value->plus($this->money($row->contract_value));
        }

        return [$byCategory, $projects, $overdueCount, $value];
    }

    /**
     * The markers: at most maxPins + 1 sites loaded (the extra one only tells
     * us the cap was hit), overdue first, then most recently moved.
     *
     * @param  Builder<ProjectLocation>  $sites
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function pins(Builder $sites, bool $withEntity): array
    {
        [$overdue, $bindings] = $this->overdueExpression();

        $locations = $sites
            ->join('projects', 'projects.id', '=', 'project_locations.project_id')
            ->select([
                'project_locations.id', 'project_locations.tenant_id', 'project_locations.project_id',
                'project_locations.site_name', 'project_locations.lga_id',
                'project_locations.latitude', 'project_locations.longitude',
            ])
            ->with([
                'lga:id,name',
                'project' => fn ($project) => $project
                    ->select([
                        'id', 'ulid', 'tenant_id', 'reference', 'title', 'sector_id', 'status',
                        'physical_progress', 'contract_value_total', 'expected_end_date',
                        'revised_end_date', 'actual_end_date',
                    ])
                    ->with(array_filter([
                        'sector:id,name',
                        $withEntity ? 'tenant:id,name' : null,
                    ])),
            ])
            ->orderByRaw($overdue.' DESC', $bindings)
            ->orderByDesc('projects.status_changed_at')
            ->orderByDesc('projects.id')
            ->orderBy('project_locations.id')
            ->limit($this->maxPins + 1)
            ->get();

        $today = Carbon::today();
        $pins = [];

        foreach ($locations->take($this->maxPins) as $location) {
            $project = $location->project;

            if (! $project instanceof Project) {
                continue;
            }

            $isOverdue = $project->isOverdue($today);
            $category = ProjectMapCategory::for($project->status, $isOverdue);

            $pins[] = [
                'ulid' => $project->ulid,
                'reference' => $project->reference,
                'title' => $project->title,
                'entity' => $withEntity ? $project->tenant?->name : null,
                'sector' => $project->sector?->name,
                'status' => $project->status->value,
                'status_label' => $project->status->label(),
                'category' => $category->value,
                'category_label' => $category->label(),
                'overdue' => $isOverdue,
                'progress' => round((float) $project->physical_progress, 1),
                'value' => $project->contract_value_total?->format(),
                'site' => $location->site_name,
                'lga' => $location->lga?->name,
                'lat' => (float) $location->latitude,
                'lng' => (float) $location->longitude,
            ];
        }

        return [$pins, $locations->count() > $this->maxPins];
    }

    /**
     * LGA rows, busiest first, the "not recorded" bucket last. Projects per
     * area are DISTINCT (see ProjectLocation): a 12-site project counts once
     * in each area it touches, not twelve times — hence the distinct
     * (area, project) pairs underneath the projects/overdue/progress figures.
     *
     * @param  Builder<ProjectLocation>  $sites
     * @return list<array{lga_id: int|null, name: string, projects: int, sites: int, overdue: int, average_progress: float}>
     */
    private function lgaRows(Builder $sites): array
    {
        [$overdue, $bindings] = $this->overdueExpression();

        $siteCounts = (clone $sites)->toBase()
            ->selectRaw('project_locations.lga_id as lga_id, COUNT(*) as sites')
            ->groupBy('project_locations.lga_id')
            ->pluck('sites', 'lga_id');

        $pairs = (clone $sites)->toBase()
            ->join('projects', 'projects.id', '=', 'project_locations.project_id')
            ->select(['project_locations.lga_id', 'project_locations.project_id', 'projects.physical_progress'])
            ->selectRaw($overdue.' as is_overdue', $bindings)
            ->distinct();

        $perLga = DB::query()->fromSub($pairs, 'pairs')
            ->selectRaw('lga_id, COUNT(*) as projects, SUM(is_overdue) as overdue, AVG(physical_progress) as average_progress')
            ->groupBy('lga_id')
            ->get();

        $names = Lga::query()
            ->whereIn('id', $perLga->pluck('lga_id')->filter()->all())
            ->pluck('name', 'id');

        $rows = $perLga->map(fn (object $row): array => [
            'lga_id' => $row->lga_id === null ? null : (int) $row->lga_id,
            'name' => $row->lga_id === null ? __('LGA not recorded') : (string) ($names[$row->lga_id] ?? __('LGA not recorded')),
            'projects' => (int) $row->projects,
            'sites' => (int) ($siteCounts[$row->lga_id ?? ''] ?? 0),
            'overdue' => (int) $row->overdue,
            'average_progress' => round((float) $row->average_progress, 1),
        ])->all();

        usort($rows, fn (array $a, array $b): int => [$a['lga_id'] === null, -$a['projects'], $a['name']]
            <=> [$b['lga_id'] === null, -$b['projects'], $b['name']]);

        return $rows;
    }

    /**
     * THE definition of overdue in SQL — the same rule as Project::isOverdue(),
     * the portfolio and the register: past the revised (else planned) delivery
     * date, no actual end date, not finished. Columns are qualified because it
     * runs against joined queries too.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function overdueExpression(): array
    {
        return [
            'CASE WHEN projects.actual_end_date IS NULL'
                .' AND projects.status NOT IN (?, ?, ?, ?)'
                .' AND COALESCE(projects.revised_end_date, projects.expected_end_date) < ?'
                .' THEN 1 ELSE 0 END',
            [...array_map(fn (ProjectStatus $status): string => $status->value, self::FINISHED), Carbon::today()->toDateString()],
        ];
    }

    /**
     * SUM() over a DECIMAL column comes back as a string on MySQL and a float
     * on SQLite; either way it becomes Money here, never a float in a total.
     */
    private function money(mixed $value): Money
    {
        return match (true) {
            $value === null => Money::zero(),
            is_float($value) => Money::fromDecimalString(sprintf('%.2F', $value)),
            default => Money::fromDecimalString((string) $value),
        };
    }
}
