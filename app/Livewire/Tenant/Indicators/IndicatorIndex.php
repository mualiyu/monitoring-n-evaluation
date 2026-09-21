<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Indicators;

use App\Enums\IndicatorTier;
use App\Models\Indicator;
use App\Models\Project;
use App\Support\IndicatorAchievement;
use App\Support\InstanceTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The MDA's indicator register — every measure this workspace reports against,
 * with where each one stands.
 *
 * The traffic light is computed in ONE place (App\Support\IndicatorAchievement)
 * and read here; nothing on this screen re-derives a percentage, and the Blade
 * file contains no thresholds at all. Two screens quoting different achievement
 * for the same figure is how an M&E system loses the only thing it sells.
 *
 * Reads only. Capture happens on the detail screen, structure in the logframe
 * builder — a register that could also edit would need every guard twice.
 */
#[Layout('layouts::tenant')]
class IndicatorIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * Keyed by ULID rather than by primary key: a bookmark carries this value,
     * and an auto-increment id in a URL both enumerates another MDA's volumes
     * and invites a guess at a row that is not yours (rules/tenancy.md).
     */
    #[Url(as: 'project', except: '')]
    public string $projectUlid = '';

    #[Url(except: '')]
    public string $tier = '';

    /** on_track | at_risk | off_track | no_data — see IndicatorAchievement. */
    #[Url(as: 'standing', except: '')]
    public string $band = '';

    #[Url(as: 'state', except: '')]
    public string $activation = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Indicator::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedProjectUlid(): void
    {
        $this->resetPage();
    }

    public function updatedTier(): void
    {
        $this->resetPage();
    }

    public function updatedBand(): void
    {
        $this->resetPage();
    }

    public function updatedActivation(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'projectUlid', 'tier', 'band', 'activation']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->projectUlid !== '' || $this->tier !== ''
            || $this->band !== '' || $this->activation !== '';
    }

    /**
     * @return LengthAwarePaginator<int, Indicator>
     */
    #[Computed]
    public function indicators(): LengthAwarePaginator
    {
        return $this->query()
            ->with([
                'project:id,ulid,title,reference',
                'resultFramework:id,ulid,level,statement',
                'latestTarget',
                'latestCountableReading',
            ])
            ->orderBy('tier')
            ->orderBy('name')
            ->paginate(25);
    }

    /** @return Builder<Indicator> */
    private function query(): Builder
    {
        return Indicator::query()
            ->when($this->search !== '', fn (Builder $q) => $q->where(
                fn (Builder $match) => $match
                    ->where('name', 'like', $this->term())
                    ->orWhere('definition', 'like', $this->term()),
            ))
            // A subquery, not whereHas(): the TenantScope on Project confines
            // it to the bound MDA exactly as the outer query is confined, so a
            // ULID from another workspace matches nothing rather than
            // resolving to a row that is then filtered out somewhere later.
            ->when($this->projectUlid !== '', fn (Builder $q) => $q->whereIn(
                'project_id',
                Project::query()->where('ulid', $this->projectUlid)->select('id'),
            ))
            ->when($this->tier !== '', fn (Builder $q) => $q->where('tier', $this->tier))
            ->when($this->activation === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($this->activation === 'draft', fn (Builder $q) => $q->where('is_active', false))
            // The standing filter cannot be a WHERE clause: achievement is a
            // computation over baseline, target, unit and target type, and
            // expressing it in SQL would be a SECOND definition of the number
            // — the one thing this module must not have. So the bands are
            // computed once (three queries, see standings()) and the result is
            // handed back to the database as a set of ids, which keeps
            // pagination honest.
            ->when($this->band !== '', fn (Builder $q) => $q->whereIn('id', $this->standings()[$this->band] ?? [0]));
    }

    private function term(): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';
    }

    /**
     * Indicator ids grouped by achievement band, for the summary row and the
     * standing filter.
     *
     * Deliberately NOT filtered by the filter bar: the stat row is the state
     * of the register, not of the current search. A summary that moves with
     * the filters cannot answer "how are we doing?", which is the only
     * question it is there to answer.
     *
     * Cost: one query for the indicators plus the two `ofMany` sub-selects,
     * bounded by this MDA's indicator count — hundreds, by construction (the
     * manual's whole point is a SHORT predetermined list).
     *
     * @return array<string, list<int>>
     */
    #[Computed]
    public function standings(): array
    {
        $grouped = array_fill_keys(array_keys(IndicatorAchievement::bands()), []);

        /** @var Collection<int, Indicator> $indicators */
        $indicators = Indicator::query()
            ->active()
            ->with(['latestTarget', 'latestCountableReading'])
            ->get();

        foreach ($indicators as $indicator) {
            $grouped[$indicator->achievement()->band][] = $indicator->id;
        }

        return $grouped;
    }

    /**
     * @return array{on_track: int, at_risk: int, off_track: int, no_data: int, total: int}
     */
    #[Computed]
    public function stats(): array
    {
        $standings = $this->standings();

        return [
            'on_track' => count($standings[IndicatorAchievement::ON_TRACK]),
            'at_risk' => count($standings[IndicatorAchievement::AT_RISK]),
            'off_track' => count($standings[IndicatorAchievement::OFF_TRACK]),
            'no_data' => count($standings[IndicatorAchievement::NO_DATA]),
            'total' => Indicator::query()->count(),
        ];
    }

    /**
     * Projects this workspace actually has indicators for — a filter option
     * that returns nothing is a dead end.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function projectOptions(): array
    {
        return Project::query()
            ->whereIn('id', Indicator::query()->whereNotNull('project_id')->select('project_id'))
            ->orderBy('title')
            ->pluck('title', 'ulid')
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function tierOptions(): array
    {
        return collect(IndicatorTier::cases())
            ->mapWithKeys(fn (IndicatorTier $case) => [$case->value => $case->label()])
            ->all();
    }

    /**
     * CSV of the register under exactly the filters in force.
     *
     * Authorization is repeated HERE and not merely inherited from mount():
     * this is a network-callable method, a client can invoke it long after the
     * screen was opened, and the rows it writes go straight past the view
     * layer into a file someone forwards. It runs the list's own builder, so a
     * row can never be missing from the screen and present in the export.
     */
    public function export(): StreamedResponse
    {
        $this->authorize('viewAny', Indicator::class);

        $query = $this->query()
            ->with(['project:id,title,reference', 'latestTarget', 'latestCountableReading'])
            ->orderBy('tier')
            ->orderBy('name');

        $filename = 'indicator-register-'.Carbon::now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            // BOM: Excel on Windows reads UTF-8 CSV as cp1252 without it,
            // which mangles the naira sign and every accented place name.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('Indicator'), __('Tier'), __('Project'), __('Reference'),
                __('Unit'), __('Frequency'), __('Baseline'), __('Baseline date'),
                __('Target'), __('Latest actual'), __('Period end'),
                __('Achievement %'), __('Standing'), __('State'),
            ]);

            $query->chunk(500, function (iterable $indicators) use ($handle): void {
                /** @var Indicator $indicator */
                foreach ($indicators as $indicator) {
                    $achievement = $indicator->achievement();
                    $reading = $indicator->latestCountableReading;

                    fputcsv($handle, [
                        $indicator->name,
                        $indicator->tier?->label(),
                        $indicator->project?->title,
                        $indicator->project?->reference,
                        $indicator->unit->label(),
                        $indicator->measurement_frequency->label(),
                        $indicator->baseline_value,
                        $indicator->baseline_date === null
                            ? null
                            : InstanceTime::local($indicator->baseline_date)->toDateString(),
                        $indicator->latestTarget?->target_value,
                        $reading?->actual_value,
                        $reading === null ? null : InstanceTime::local($reading->period_end)->toDateString(),
                        $achievement->percent,
                        $achievement->label(),
                        $indicator->is_active ? __('Active') : __('Draft'),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render(): View
    {
        return view('livewire.tenant.indicators.indicator-index');
    }
}
