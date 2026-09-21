<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Workplans;

use App\Enums\ActivityScheduleGranularity;
use App\Models\Workplan;
use App\Models\WorkplanActivity;
use App\Support\WorkplanProgress;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The implementation-plan timeline — the manual's Appendix A drawn as a grid
 * (`no | activity | owner | month × week`).
 *
 * DELIBERATELY NOT A JS GANTT LIBRARY. Every bar is a positioned <div> inside
 * a CSS grid, which means: it renders with JavaScript off, it prints, it works
 * on the Android WebViews field monitors actually carry, and every bar can
 * carry a text label and an aria-label. A canvas-drawn chart can do none of
 * those things, and on this platform status is never conveyed by colour or
 * position alone — the bar states its own dates, status and percentage in
 * text, and a table fallback below presents the identical data as rows.
 *
 * All the geometry is computed HERE, in integers, and handed to the view as
 * plain numbers. A Blade template doing date arithmetic inline is a template
 * nobody can test; this component's output is an array a test can assert on.
 */
#[Layout('layouts::tenant')]
class WorkplanGantt extends Component
{
    public Workplan $workplan;

    /** month | week — the column width of the grid. */
    #[Url(except: 'month')]
    public string $scale = 'month';

    /** Show the table presentation instead of the grid. */
    #[Url(except: false)]
    public bool $asTable = false;

    public function mount(Workplan $workplan): void
    {
        $this->authorize('view', $workplan);

        $this->workplan = $workplan;
    }

    public function setScale(string $scale): void
    {
        if (in_array($scale, ['month', 'week'], true)) {
            $this->scale = $scale;
        }
    }

    public function granularity(): ActivityScheduleGranularity
    {
        return ActivityScheduleGranularity::tryFrom($this->scale) ?? ActivityScheduleGranularity::Month;
    }

    /** @return Collection<int, WorkplanActivity> */
    #[Computed]
    public function activities(): Collection
    {
        return $this->workplan->activities()
            ->with(['owner:id,name', 'indicator:id,name', 'dependsOn:id,title'])
            ->get();
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function summary(): array
    {
        return WorkplanProgress::summarise($this->activities());
    }

    /**
     * The grid's column headings: one per month (or week) of the plan's
     * period, in order.
     *
     * @return list<array{key: string, label: string, sublabel: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    #[Computed]
    public function columns(): array
    {
        $isWeekly = $this->granularity() === ActivityScheduleGranularity::Week;
        $columns = [];

        $cursor = $isWeekly
            ? $this->workplan->period_start->startOfWeek()
            : $this->workplan->period_start->startOfMonth();
        $last = $this->workplan->period_end;

        // Bounded: 53 weeks or 12 months for a normal year, and the guard
        // stops a mis-entered decade-long period from rendering 500 columns.
        $limit = $isWeekly ? 70 : 24;

        while (! $cursor->isAfter($last) && count($columns) < $limit) {
            $end = $isWeekly ? $cursor->endOfWeek() : $cursor->endOfMonth();

            $columns[] = [
                'key' => $cursor->format($isWeekly ? 'o-\WW' : 'Y-m'),
                'label' => $isWeekly ? 'W'.$cursor->format('W') : $cursor->translatedFormat('M'),
                'sublabel' => $isWeekly ? $cursor->translatedFormat('j M') : $cursor->format('Y'),
                'start' => $cursor,
                'end' => $end,
            ];

            $cursor = $isWeekly ? $cursor->addWeek()->startOfWeek() : $cursor->addMonth()->startOfMonth();
        }

        return $columns;
    }

    /**
     * One row per activity, with its bar already positioned in GRID COLUMN
     * numbers (1-based, CSS-grid semantics) plus the text every bar must carry.
     *
     * A bar is clamped to the plan's own period: an activity cannot be
     * scheduled outside it (AddWorkplanActivity refuses), but legacy or
     * imported data might be, and a bar drawn off the grid is invisible rather
     * than obviously wrong.
     *
     * @return list<array{
     *     activity: WorkplanActivity, start: int, span: int, progress: int,
     *     label: string, aria: string, dependency: int|null, unlinked: bool
     * }>
     */
    #[Computed]
    public function rows(): array
    {
        $columns = $this->columns();

        if ($columns === []) {
            return [];
        }

        $rows = [];
        $byId = [];

        foreach ($this->activities() as $index => $activity) {
            $start = $this->columnIndexFor($activity->planned_start, $columns);
            $end = $this->columnIndexFor($activity->planned_end, $columns);

            $byId[$activity->id] = $index + 1;

            $rows[] = [
                'activity' => $activity,
                'start' => $start + 1,                    // CSS grid is 1-based
                'span' => max(1, $end - $start + 1),
                'progress' => max(0, min(100, $activity->progress_percent)),
                'label' => $activity->progress_percent.'%',
                'aria' => $this->describe($activity),
                'dependency' => null,
                'unlinked' => $activity->lacksOutputIndicator(),
            ];
        }

        // The dependency reference is resolved to the ROW NUMBER of the
        // predecessor, so the view can say "after row 3" in words rather than
        // drawing an arrow no screen reader can follow.
        foreach ($rows as $i => $row) {
            $dependsOn = $row['activity']->depends_on_id;
            $rows[$i]['dependency'] = $dependsOn !== null ? ($byId[$dependsOn] ?? null) : null;
        }

        return $rows;
    }

    /**
     * Today's position in the grid, 1-based, or null when the plan's period
     * does not contain today. Drives the "today" marker — which also carries a
     * visually hidden label, because a coloured line is not information.
     */
    #[Computed]
    public function todayColumn(): ?int
    {
        $today = CarbonImmutable::now()->startOfDay();

        foreach ($this->columns() as $index => $column) {
            if (! $today->isBefore($column['start']->startOfDay())
                && ! $today->isAfter($column['end']->startOfDay())) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * The zero-based index of the column a date falls in, clamped to the grid.
     *
     * @param  list<array{key: string, label: string, sublabel: string, start: CarbonImmutable, end: CarbonImmutable}>  $columns
     */
    private function columnIndexFor(CarbonImmutable $date, array $columns): int
    {
        $day = $date->startOfDay();
        $last = count($columns) - 1;

        foreach ($columns as $index => $column) {
            if ($day->isBefore($column['start']->startOfDay())) {
                return $index === 0 ? 0 : $index - 1;
            }

            if (! $day->isAfter($column['end']->startOfDay())) {
                return $index;
            }
        }

        return max(0, $last);
    }

    /**
     * The accessible description of one bar. Status, dates and percentage in
     * words — because a bar's colour and its position on a grid are exactly
     * the two things a screen reader, a monochrome printout and a
     * colour-blind director cannot read.
     */
    private function describe(WorkplanActivity $activity): string
    {
        return __(':title. :status, :percent% complete. Planned :start to :end.', [
            'title' => $activity->title,
            'status' => $activity->status->label(),
            'percent' => $activity->progress_percent,
            'start' => $activity->planned_start->translatedFormat('j M Y'),
            'end' => $activity->planned_end->translatedFormat('j M Y'),
        ]);
    }

    public function render(): View
    {
        return view('livewire.tenant.workplans.workplan-gantt');
    }
}
