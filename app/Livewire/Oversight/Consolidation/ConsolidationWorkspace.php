<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Consolidation;

use App\Actions\Consolidation\OpenConsolidation;
use App\Enums\ConsolidatedReportType;
use App\Enums\ConsolidationStatus;
use App\Exceptions\Consolidation\ConsolidationRuleViolation;
use App\Models\ConsolidatedReport;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The secretariat's consolidation desk (plan §8): every roll-up the state has
 * opened, which window it covers, how much of the state answered, and where it
 * has got to in the chain.
 *
 * Nothing is computed here. Opening a consolidation goes through
 * App\Actions\Consolidation\OpenConsolidation, which owns the one-per-window
 * rule and the cadence guard; this component collects two form values and
 * renders the result.
 *
 * ConsolidatedReport is GLOBAL, so the list needs no tenancy bypass at all —
 * which is exactly why the model carries no tenant_id. The DENOMINATOR (how
 * many entities were expected to answer) does cross MDAs, and it is read
 * through the model's own stored counter rather than a live cross-tenant
 * count: the number that matters is the one the compile recorded.
 */
#[Layout('layouts::oversight')]
class ConsolidationWorkspace extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    /** The "open a consolidation" form — a period code and a type. */
    public string $newPeriod = '';

    public string $newType = '';

    public string $newTitle = '';

    public bool $opening = false;

    public function mount(): void
    {
        $this->authorize('viewAny', ConsolidatedReport::class);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['status', 'type']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->status !== '' || $this->type !== '';
    }

    public function startOpening(): void
    {
        // Authorize on the way IN to the form, not only on submit: a user who
        // cannot open one should not be shown the fields.
        $this->authorize('create', ConsolidatedReport::class);

        $this->opening = true;
        $this->newType = $this->newType !== '' ? $this->newType : ConsolidatedReportType::AnnualApr->value;
    }

    public function cancelOpening(): void
    {
        $this->reset(['opening', 'newPeriod', 'newType', 'newTitle']);
        $this->resetErrorBag();
    }

    /**
     * Open a roll-up for a window.
     *
     * Authorizes AGAIN here: route middleware does not gate a Livewire update
     * POST by itself, and this method is network-callable long after the
     * screen was opened.
     */
    public function open(): void
    {
        $this->authorize('create', ConsolidatedReport::class);

        $validated = $this->validate([
            'newPeriod' => ['required', 'string', 'exists:reporting_periods,code'],
            'newType' => ['required', 'string', 'in:'.implode(',', array_column(ConsolidatedReportType::cases(), 'value'))],
            'newTitle' => ['nullable', 'string', 'max:180'],
        ], attributes: [
            'newPeriod' => __('reporting window'),
            'newType' => __('report type'),
            'newTitle' => __('title'),
        ]);

        /** @var ReportingPeriod $period */
        $period = ReportingPeriod::query()->where('code', $validated['newPeriod'])->firstOrFail();

        /** @var User $user */
        $user = auth()->user();

        try {
            $report = app(OpenConsolidation::class)(
                $user,
                $period,
                ConsolidatedReportType::from($validated['newType']),
                $validated['newTitle'] ?? null,
            );
        } catch (ConsolidationRuleViolation $violation) {
            // A domain refusal is a field error, not a 500: the officer chose
            // a window that is already consolidated or a cadence that does not
            // match, and both are answers the form can state.
            $this->addError('newPeriod', $violation->getMessage());

            return;
        }

        $this->cancelOpening();
        unset($this->stats);

        $this->redirectRoute('oversight.consolidation.show', ['consolidatedReport' => $report], navigate: true);
    }

    /**
     * @return LengthAwarePaginator<int, ConsolidatedReport>
     */
    #[Computed]
    public function reports(): LengthAwarePaginator
    {
        return ConsolidatedReport::query()
            // Eager-loaded because every row prints the window label and the
            // compiler's name, and preventLazyLoading is on outside production.
            ->with(['reportingPeriod:id,code,label,cadence', 'compiledBy:id,name', 'approvedBy:id,name'])
            ->when(
                $this->status !== '' && ConsolidationStatus::tryFrom($this->status) !== null,
                fn (Builder $query) => $query->where('status', $this->status),
            )
            ->when(
                $this->type !== '' && ConsolidatedReportType::tryFrom($this->type) !== null,
                fn (Builder $query) => $query->where('type', $this->type),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /**
     * The desk's summary row. One grouped query for the counts, plus the
     * entity total that every coverage figure is measured against.
     *
     * @return array{open: int, in_review: int, approved: int, published: int, entities: int}
     */
    #[Computed]
    public function stats(): array
    {
        /** @var array<string, int> $byStatus */
        $byStatus = ConsolidatedReport::query()
            ->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return [
            'open' => ($byStatus[ConsolidationStatus::Draft->value] ?? 0)
                + ($byStatus[ConsolidationStatus::Compiling->value] ?? 0),
            'in_review' => $byStatus[ConsolidationStatus::InReview->value] ?? 0,
            'approved' => $byStatus[ConsolidationStatus::Approved->value] ?? 0,
            'published' => $byStatus[ConsolidationStatus::Published->value] ?? 0,
            'entities' => Tenant::query()->where('is_active', true)->count(),
        ];
    }

    /**
     * Windows that have opened, newest first — the ones a roll-up can be
     * compiled against. A window that has not opened has no returns in it.
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
            ->get();
    }

    /**
     * Window options keyed by CODE, not id: the code is what a stored filter
     * set and a register row carry, and it stays readable six months later
     * where an integer would not.
     *
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        return $this->periods()
            ->mapWithKeys(fn (ReportingPeriod $period): array => [
                $period->code => $period->label.' · '.$period->cadence->label(),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function typeOptions(): array
    {
        $options = [];

        foreach (ConsolidatedReportType::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public function statusOptions(): array
    {
        $options = [];

        foreach (ConsolidationStatus::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    public function render(): View
    {
        return view('livewire.oversight.consolidation.consolidation-workspace');
    }
}
