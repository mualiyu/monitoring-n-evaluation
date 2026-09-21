<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Reporting;

use App\Actions\Oversight\ListReportsAcrossTenants;
use App\Enums\ProgressReportStatus;
use App\Models\ProgressReport;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Support\InstanceTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The cross-MDA reports desk (progress-reporting.md §6): every return the
 * state has been sent, in one read-only list.
 *
 * READ-ONLY ON PURPOSE, and not merely by omission. The approval chain belongs
 * to the MDA that owns the record: a secretariat officer who could approve an
 * MDA's return from here would break the separation the whole chain exists to
 * create, and would do it without the workspace ever seeing the decision.
 * Oversight reads; the entity decides.
 *
 * Every read goes through ListReportsAcrossTenants, which re-checks
 * `oversight.reports.view` in the GLOBAL permission team before bypassing
 * tenancy. This component never bypasses tenancy itself — the bypass belongs
 * with the authorization check that justifies it, in one place.
 */
#[Layout('layouts::oversight')]
class ReportDesk extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'mda', except: '')]
    public string $tenantId = '';

    #[Url(as: 'period', except: '')]
    public string $periodId = '';

    #[Url(except: '')]
    public string $status = '';

    /** '' | late | on_time — timeliness is the state's first question. */
    #[Url(except: '')]
    public string $lateness = '';

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        // The Action checks this too. Checking here as well is what turns a
        // 403 into a 403 on the PAGE rather than an exception thrown out of a
        // computed property halfway down a render.
        abort_unless($user->holdsGlobalPermission('oversight.reports.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTenantId(): void
    {
        $this->resetPage();
    }

    public function updatedPeriodId(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedLateness(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'tenantId', 'periodId', 'status', 'lateness']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->tenantId !== '' || $this->periodId !== ''
            || $this->status !== '' || $this->lateness !== '';
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * The Action takes MODELS, not ids — it filters with whereBelongsTo() so
     * that no hand-written tenant clause exists anywhere, including here.
     *
     * @return array<string, mixed>
     */
    private function filters(): array
    {
        return [
            'tenant' => $this->tenantId !== '' ? $this->tenants()->firstWhere('id', (int) $this->tenantId) : null,
            'period' => $this->periodId !== '' ? $this->periods()->firstWhere('id', (int) $this->periodId) : null,
            'status' => $this->status !== '' ? ProgressReportStatus::tryFrom($this->status) : null,
            'search' => $this->search === '' ? null : $this->search,
            'lateness' => $this->lateness === '' ? null : $this->lateness,
        ];
    }

    /** @return LengthAwarePaginator<int, ProgressReport> */
    #[Computed]
    public function reports(): LengthAwarePaginator
    {
        return app(ListReportsAcrossTenants::class)($this->user(), $this->filters());
    }

    /**
     * The summary strip. It describes THIS list under THESE filters — an
     * oversight desk is used by narrowing, and a stat row that ignored the
     * narrowing would answer a question nobody asked.
     *
     * @return array{total: int, late: int, on_time: int, entities: int}
     */
    #[Computed]
    public function stats(): array
    {
        return app(ListReportsAcrossTenants::class)->summarise($this->user(), $this->filters());
    }

    /** @return Collection<int, Tenant> */
    #[Computed]
    public function tenants(): Collection
    {
        return Tenant::query()->orderBy('name')->get(['id', 'name', 'slug']);
    }

    /**
     * Windows the state has actually received returns against — the whole
     * calendar would offer options that match nothing.
     *
     * @return Collection<int, ReportingPeriod>
     */
    #[Computed]
    public function periods(): Collection
    {
        return ReportingPeriod::query()
            ->where('opens_at', '<=', now())
            ->orderByDesc('period_start')
            ->limit(24)
            ->get(['id', 'code', 'label', 'due_at', 'period_start']);
    }

    /**
     * Only the states this desk may show: a draft belongs to its author and a
     * returned one is back with them, so offering either as a filter would
     * offer a filter that can only ever produce nothing.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(ListReportsAcrossTenants::VISIBLE_STATUSES)
            ->mapWithKeys(fn (ProgressReportStatus $case) => [$case->value => $case->label()])
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function latenessOptions(): array
    {
        return [
            'late' => __('Filed late'),
            'on_time' => __('Filed on time'),
        ];
    }

    /**
     * CSV of exactly the list on screen, under exactly the filters in force.
     *
     * Authorization is asserted HERE as well as inside the Action: this is a
     * network-callable method, a client can invoke it long after the screen
     * was opened, and the rows it writes go straight past the view layer into
     * a file someone forwards. Streamed in chunks — a state-wide export of a
     * year's returns must not build an array in memory.
     */
    public function export(): StreamedResponse
    {
        abort_unless($this->user()->holdsGlobalPermission('oversight.reports.view'), 403);

        $reports = app(ListReportsAcrossTenants::class);
        $filters = $this->filters();
        $actor = $this->user();

        $filename = 'state-progress-returns-'.Carbon::now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($reports, $actor, $filters): void {
            $handle = fopen('php://output', 'wb');

            // BOM: Excel on Windows reads UTF-8 CSV as cp1252 without it, which
            // mangles the naira sign and every accented place name.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('Entity'), __('Project'), __('Reference'), __('Window'),
                __('Status'), __('Progress claimed %'), __('Period spend'),
                __('Deadline'), __('Filed on'), __('Filed by'), __('Filed late'),
            ]);

            $reports->chunk($actor, $filters, function (iterable $rows) use ($handle): void {
                /** @var ProgressReport $report */
                foreach ($rows as $report) {
                    fputcsv($handle, [
                        $report->tenant->name,
                        $report->project->title,
                        $report->project->reference,
                        $report->reportingPeriod->label,
                        $report->status->label(),
                        $report->physical_progress_claimed,
                        $report->period_expenditure->toDecimalString(),
                        // The state's wall clock, not UTC — the deadline the
                        // entity was actually given.
                        InstanceTime::local($report->due_at)->toDateString(),
                        $report->submitted_at === null ? null : InstanceTime::local($report->submitted_at)->toDateString(),
                        $report->submittedBy?->name,
                        $report->submitted_late ? __('Yes') : __('No'),
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function render(): View
    {
        return view('livewire.oversight.reporting.report-desk');
    }
}
