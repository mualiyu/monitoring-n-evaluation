<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Evaluation;

use App\Actions\Evaluation\TransitionRecommendationStatus;
use App\Enums\RecommendationPriority;
use App\Enums\RecommendationStatus;
use App\Exceptions\Evaluation\EvaluationRuleViolation;
use App\Exceptions\Evaluation\InvalidEvaluationTransition;
use App\Models\Recommendation;
use App\Models\User;
use App\Support\InstanceTime;
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
 * The follow-up register — what this entity was told to do, by whom, by when,
 * and whether it happened.
 *
 * THIS IS THE SCREEN THAT MAKES EVALUATIONS MATTER. Everything about it is
 * built around one question a commissioner will ask: "what did we promise to
 * fix and haven't?" Hence the default ordering (most pressing, longest
 * ignored), the dedicated overdue filter, the stat row that counts overdue
 * separately from outstanding, and an export that carries the evidence column.
 *
 * Transitions happen inline, because the alternative — a detail page per
 * recommendation — would put four clicks between an officer and recording
 * that something was done, and a register that is tedious to update is a
 * register that stops being true.
 */
#[Layout('layouts::tenant')]
class RecommendationIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $priority = '';

    #[Url(except: false)]
    public bool $overdue = false;

    /** The recommendation being moved, and the inputs its move needs. */
    public ?int $movingId = null;

    public string $moveTo = '';

    public string $moveReason = '';

    public string $moveEvidence = '';

    public string $supersededByUlid = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Recommendation::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPriority(): void
    {
        $this->resetPage();
    }

    public function updatedOverdue(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'priority', 'overdue']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->status !== '' || $this->priority !== '' || $this->overdue;
    }

    /**
     * @return LengthAwarePaginator<int, Recommendation>
     */
    #[Computed]
    public function recommendations(): LengthAwarePaginator
    {
        return $this->query()
            ->with(['addressee:id,name', 'project:id,ulid,title,reference'])
            ->orderByRaw($this->priorityOrdering(), $this->priorityBindings())
            // Nulls last, then soonest first: a register is read from the top
            // and the top should be what is most overdue.
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END, due_on')
            ->orderByDesc('id')
            ->paginate(25);
    }

    /** @return Builder<Recommendation> */
    private function query(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return Recommendation::query()
            ->visibleTo($user)
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->priority !== '', fn (Builder $q) => $q->where('priority', $this->priority))
            ->when($this->overdue, fn (Builder $q) => $q->overdue())
            ->when($this->search !== '', function (Builder $q): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $q->where(fn (Builder $match) => $match
                    ->where('title', 'like', $term)
                    ->orWhere('body', 'like', $term)
                    ->orWhere('addressee_body', 'like', $term));
            });
    }

    /**
     * The state of the register, deliberately NOT filtered: a stat row that
     * moves with the filter bar cannot answer "are we behind?".
     *
     * @return array{outstanding: int, overdue: int, implemented: int, closed: int}
     */
    #[Computed]
    public function stats(): array
    {
        /** @var User $user */
        $user = auth()->user();

        $outstanding = array_values(array_filter(array_map(
            fn (RecommendationStatus $status): ?string => $status->isOutstanding() ? $status->value : null,
            RecommendationStatus::cases(),
        )));

        $row = Recommendation::query()
            ->visibleTo($user)
            ->toBase()
            ->selectRaw('COUNT(CASE WHEN status IN (?, ?, ?) THEN 1 END) as outstanding', $outstanding)
            ->selectRaw(
                'COUNT(CASE WHEN status IN (?, ?, ?) AND due_on IS NOT NULL AND due_on < ? THEN 1 END) as overdue',
                [...$outstanding, Carbon::now()->toDateString()],
            )
            ->selectRaw(
                'COUNT(CASE WHEN status = ? THEN 1 END) as implemented',
                [RecommendationStatus::Implemented->value],
            )
            ->selectRaw(
                'COUNT(CASE WHEN status IN (?, ?) THEN 1 END) as closed',
                [RecommendationStatus::Rejected->value, RecommendationStatus::Superseded->value],
            )
            ->first();

        return [
            'outstanding' => (int) ($row->outstanding ?? 0),
            'overdue' => (int) ($row->overdue ?? 0),
            'implemented' => (int) ($row->implemented ?? 0),
            'closed' => (int) ($row->closed ?? 0),
        ];
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        return RecommendationStatus::options();
    }

    /** @return array<string, string> */
    #[Computed]
    public function priorityOptions(): array
    {
        return RecommendationPriority::options();
    }

    /* ---------------------------------------------------------------- */
    /* Moving one through the loop */
    /* ---------------------------------------------------------------- */

    /**
     * The recommendation a move names, resolved through `visibleTo()` — the
     * same narrowing the list runs. An id outside this workspace (or outside a
     * user's project visibility) is a 404, not a refusal that confirms the row
     * exists somewhere.
     */
    private function movable(int $id): Recommendation
    {
        /** @var User $user */
        $user = auth()->user();

        return Recommendation::query()->visibleTo($user)->findOrFail($id);
    }

    public function startMove(int $id, string $to): void
    {
        $recommendation = $this->movable($id);

        $this->authorize('transition', $recommendation);

        $this->resetErrorBag();
        $this->failure = null;
        $this->movingId = $id;
        $this->moveTo = $to;
        $this->moveReason = '';
        $this->moveEvidence = '';
        $this->supersededByUlid = '';

        $this->dispatch('open-modal', 'move-recommendation');
    }

    public function cancelMove(): void
    {
        $this->movingId = null;
        $this->moveTo = '';
        $this->moveReason = '';
        $this->moveEvidence = '';
        $this->supersededByUlid = '';
        $this->resetErrorBag();

        $this->dispatch('close-modal', 'move-recommendation');
    }

    public function targetStatus(): ?RecommendationStatus
    {
        return $this->moveTo === '' ? null : RecommendationStatus::tryFrom($this->moveTo);
    }

    public function confirmMove(TransitionRecommendationStatus $transition): void
    {
        $recommendation = $this->movable((int) $this->movingId);
        $to = $this->targetStatus();

        $this->authorize('transition', $recommendation);

        if ($to === null) {
            $this->cancelMove();

            return;
        }

        $rules = [];
        $messages = [];

        if ($to->requiresReason()) {
            $rules['moveReason'] = ['required', 'string', 'min:10', 'max:2000'];
            $messages['moveReason.required'] = __('Say why. A recommendation that was simply dropped, with nothing on the record explaining it, is how an evaluation stops mattering.');
        }

        if ($to === RecommendationStatus::Implemented) {
            $rules['moveEvidence'] = ['required', 'string', 'min:10', 'max:5000'];
            $messages['moveEvidence.required'] = __('Record what actually happened. “Done” with nothing behind it is what makes a follow-up register worthless.');
        }

        if ($to === RecommendationStatus::Superseded) {
            $rules['supersededByUlid'] = ['required', 'string', 'max:40'];
            $messages['supersededByUlid.required'] = __('Name the recommendation that replaces this one.');
        }

        if ($rules !== []) {
            $this->validate($rules, $messages, [
                'moveReason' => __('reason'),
                'moveEvidence' => __('evidence'),
                'supersededByUlid' => __('replacement'),
            ]);
        }

        $this->failure = null;

        $replacement = $this->supersededByUlid === ''
            ? null
            // Resolved under the TenantScope: a ULID from another workspace
            // matches nothing rather than resolving to a foreign row.
            : Recommendation::query()->where('ulid', $this->supersededByUlid)->first();

        try {
            /** @var User $actor */
            $actor = auth()->user();

            $transition(
                $recommendation,
                $to,
                $actor,
                $this->moveReason === '' ? null : $this->moveReason,
                $this->moveEvidence === '' ? null : $this->moveEvidence,
                $replacement,
            );
        } catch (EvaluationRuleViolation|InvalidEvaluationTransition $exception) {
            $this->failure = $exception->getMessage();
            $this->dispatch('close-modal', 'move-recommendation');

            return;
        }

        $this->cancelMove();

        unset($this->recommendations, $this->stats);

        session()->flash('status', __('Follow-up recorded. The register now reads :status.', [
            'status' => mb_strtolower($to->label()),
        ]));
    }

    /**
     * Open recommendations this one could be replaced by — the register's own
     * rows, so a supersession always names something real.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function replacementOptions(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return Recommendation::query()
            ->visibleTo($user)
            ->outstanding()
            ->when($this->movingId !== null, fn (Builder $q) => $q->whereKeyNot($this->movingId))
            ->orderByDesc('id')
            ->limit(100)
            ->pluck('title', 'ulid')
            ->all();
    }

    /* ---------------------------------------------------------------- */
    /* Export */
    /* ---------------------------------------------------------------- */

    /**
     * CSV of exactly the rows on screen, under exactly the filters in force —
     * the artifact a secretariat takes into a review meeting. The evidence
     * column is in it deliberately: a list of "implemented" with no evidence
     * beside it is the document this whole module exists to prevent.
     *
     * Authorization is repeated HERE and not merely inherited from mount():
     * this is a network-callable method, and its rows go straight past the
     * view layer into a file someone forwards.
     */
    public function export(): StreamedResponse
    {
        $this->authorize('viewAny', Recommendation::class);

        $query = $this->query()
            ->with(['addressee:id,name', 'project:id,title,reference'])
            ->orderByRaw($this->priorityOrdering(), $this->priorityBindings())
            ->orderByRaw('CASE WHEN due_on IS NULL THEN 1 ELSE 0 END, due_on')
            ->orderByDesc('id');

        $filename = 'recommendations-'.Carbon::now()->format('Y-m-d-Hi').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = fopen('php://output', 'wb');

            // BOM: Excel on Windows reads UTF-8 CSV as cp1252 without it,
            // which mangles the naira sign and every accented place name.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                __('Recommendation'), __('Raised by'), __('Project'), __('Addressee'),
                __('Priority'), __('Status'), __('Estimated cost'), __('Timeline'),
                __('Due'), __('Overdue'), __('Evidence of implementation'), __('Closure reason'),
            ]);

            $query->chunk(500, function (iterable $recommendations) use ($handle): void {
                /** @var Recommendation $recommendation */
                foreach ($recommendations as $recommendation) {
                    fputcsv($handle, [
                        $recommendation->title,
                        $recommendation->sourceLabel(),
                        $recommendation->project?->title,
                        $recommendation->addresseeLabel(),
                        $recommendation->priority->label(),
                        $recommendation->status->label(),
                        $recommendation->estimated_cost?->toDecimalString(),
                        $recommendation->timeline,
                        // The state's wall clock, not UTC — the date the
                        // addressee was actually given.
                        $recommendation->due_on === null ? null : InstanceTime::local($recommendation->due_on)->toDateString(),
                        $recommendation->isOverdue() ? __('Yes') : __('No'),
                        $recommendation->implementation_evidence,
                        $recommendation->closure_reason,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Priority is a string column, so "most pressing first" needs the enum's
     * own weighting expressed in SQL. Bound parameters, and the CASE form
     * rather than FIELD() so MySQL and SQLite agree.
     */
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

    public function render(): View
    {
        return view('livewire.tenant.evaluation.recommendation-index');
    }
}
