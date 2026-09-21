<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Evaluation;

use App\Enums\EvaluationStatus;
use App\Enums\EvaluationType;
use App\Models\Evaluation;
use App\Models\User;
use App\Support\InstanceTime;
use App\Support\SettingsRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The MDA's evaluation register: what has been commissioned, where each study
 * has got to, and what it concluded.
 *
 * The data-heavy pattern from the design system, in order: filter bar → stat
 * row → table → pagination, with a CSV export of exactly the rows on screen.
 *
 * One code path for every role: `visibleTo()` narrows on the PROJECT, so a
 * user who cannot see a road cannot see the evaluation of it, and isolation is
 * proven once rather than per screen. The TenantScope confines everything to
 * the bound MDA before any of that runs.
 *
 * Reads only — every mutation lives on the detail screen, next to the record
 * it changes.
 */
#[Layout('layouts::tenant')]
class EvaluationIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    /** Only commissions whose report deadline has passed without approval. */
    #[Url(except: false)]
    public bool $overdue = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Evaluation::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedOverdue(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'type', 'overdue']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->type !== '' || $this->overdue;
    }

    /**
     * @return LengthAwarePaginator<int, Evaluation>
     */
    #[Computed]
    public function evaluations(): LengthAwarePaginator
    {
        return $this->query()
            ->with([
                'project:id,ulid,title,reference',
                'lead.user:id,name',
                'criterionScores:id,evaluation_id,criterion,score,weight',
            ])
            ->withCount('recommendations')
            ->orderByDesc('status_changed_at')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /** @return Builder<Evaluation> */
    private function query(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return Evaluation::query()
            ->visibleTo($user)
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type))
            ->when($this->overdue, fn (Builder $q) => $q
                ->whereNotNull('report_due_on')
                ->whereDate('report_due_on', '<', Carbon::now()->toDateString())
                ->whereIn('status', [
                    EvaluationStatus::Planned,
                    EvaluationStatus::InProgress,
                    EvaluationStatus::DraftReport,
                    EvaluationStatus::UnderReview,
                ]))
            ->when($this->search !== '', function (Builder $q): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $q->where(fn (Builder $match) => $match
                    ->where('title', 'like', $term)
                    ->orWhere('subject_name', 'like', $term)
                    ->orWhere('sponsor', 'like', $term));
            });
    }

    /**
     * The summary row. The tallies deliberately IGNORE the filter bar: they
     * are the state of the register, not of the current search. A stat row
     * that moves with the filters cannot answer "are we behind?", which is the
     * only question it is there to answer.
     *
     * @return array{live: int, awaiting_approval: int, settled: int, overdue: int}
     */
    #[Computed]
    public function stats(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $now = Carbon::now();

        $live = [
            EvaluationStatus::Planned->value,
            EvaluationStatus::InProgress->value,
            EvaluationStatus::DraftReport->value,
            EvaluationStatus::UnderReview->value,
        ];

        // One round trip: this screen is a landing card and gets refreshed all
        // morning. CASE rather than SUM(bool) for SQLite/MySQL parity.
        $row = Evaluation::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw(
                'COUNT(CASE WHEN status IN (?, ?, ?, ?) THEN 1 END) as live',
                $live,
            )
            ->selectRaw(
                'COUNT(CASE WHEN status = ? THEN 1 END) as awaiting_approval',
                [EvaluationStatus::UnderReview->value],
            )
            ->selectRaw(
                'COUNT(CASE WHEN status IN (?, ?) THEN 1 END) as settled',
                [EvaluationStatus::Approved->value, EvaluationStatus::Published->value],
            )
            ->selectRaw(
                'COUNT(CASE WHEN status IN (?, ?, ?, ?) AND report_due_on IS NOT NULL AND report_due_on < ? THEN 1 END) as overdue',
                [...$live, $now->toDateString()],
            )
            ->first();

        return [
            'live' => (int) ($row->live ?? 0),
            'awaiting_approval' => (int) ($row->awaiting_approval ?? 0),
            'settled' => (int) ($row->settled ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
        ];
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        return EvaluationStatus::options();
    }

    /** @return array<string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        return EvaluationType::options();
    }

    #[Computed]
    public function scoreMax(): int
    {
        return app(SettingsRepository::class)->int('evaluation', 'score_max', 5);
    }

    /**
     * CSV of exactly the rows on screen, under exactly the filters in force.
     * Streamed in chunks so a ministry with years of evaluations does not
     * build an array in memory.
     *
     * The authorization is repeated HERE and not merely inherited from
     * mount(): this is a network-callable method, a client can invoke it long
     * after the screen was opened, and the rows it writes go straight past the
     * view layer into a file someone forwards.
     */
    public function export(): StreamedResponse
    {
        $this->authorize('viewAny', Evaluation::class);

        $query = $this->query()
            ->with(['project:id,title,reference', 'lead.user:id,name', 'criterionScores'])
            ->withCount('recommendations')
            ->orderByDesc('status_changed_at')
            ->orderByDesc('id');

        $scoreMax = $this->scoreMax();
        $filename = 'evaluations-'.Carbon::now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($query, $scoreMax): void {
            $handle = fopen('php://output', 'wb');

            // BOM: Excel on Windows reads UTF-8 CSV as cp1252 without it,
            // which mangles the naira sign and every accented place name.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('Title'), __('Subject'), __('Type'), __('Status'), __('Sponsor'),
                __('Lead'), __('Started'), __('Report due'), __('Overall score'),
                __('Recommendations'),
            ]);

            $query->chunk(200, function (iterable $evaluations) use ($handle, $scoreMax): void {
                /** @var Evaluation $evaluation */
                foreach ($evaluations as $evaluation) {
                    $score = $evaluation->overallScore();

                    fputcsv($handle, [
                        $evaluation->title,
                        $evaluation->subjectLabel(),
                        $evaluation->type->label(),
                        $evaluation->status->label(),
                        $evaluation->sponsor,
                        $evaluation->lead?->displayName(),
                        // The state's wall clock, not UTC — the dates the MDA
                        // was actually working to.
                        $evaluation->starts_on === null ? null : InstanceTime::local($evaluation->starts_on)->toDateString(),
                        $evaluation->report_due_on === null ? null : InstanceTime::local($evaluation->report_due_on)->toDateString(),
                        $score === null ? null : $score.' / '.$scoreMax,
                        $evaluation->recommendations_count,
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
        return view('livewire.tenant.evaluation.evaluation-index');
    }
}
