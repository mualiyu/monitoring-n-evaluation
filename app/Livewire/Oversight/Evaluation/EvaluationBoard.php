<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Evaluation;

use App\Actions\Oversight\ListEvaluationsAcrossTenants;
use App\Enums\EvaluationStatus;
use App\Enums\EvaluationType;
use App\Models\Evaluation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * State-wide evaluation board: what is being evaluated across the MDAs, at
 * what stage, and which reports are past their deadline.
 *
 * READ-ONLY. The secretariat commissions and reads evaluations; it does not
 * edit an MDA's findings from here — that would make the evaluation the
 * secretariat's opinion rather than the evaluator's.
 *
 * The cross-MDA read goes through app/Actions/Oversight, which re-checks
 * `evaluations.view` in the GLOBAL permission team before bypassing tenancy.
 * This component never bypasses anything itself: the bypass belongs with the
 * authorization check that justifies it.
 */
#[Layout('layouts::oversight')]
class EvaluationBoard extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'mda', except: '')]
    public string $tenantId = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: false)]
    public bool $overdue = false;

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        // The Action re-checks this; asking here too turns what would be an
        // exception into a 403 for a role that cleared the surface's role
        // middleware but holds no evaluations.view.
        abort_unless($user->holdsGlobalPermission('evaluations.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTenantId(): void
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
        $this->reset(['search', 'tenantId', 'status', 'type', 'overdue']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== ''
            || $this->tenantId !== ''
            || $this->status !== ''
            || $this->type !== ''
            || $this->overdue;
    }

    /** @return LengthAwarePaginator<int, Evaluation> */
    #[Computed]
    public function evaluations(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return (new ListEvaluationsAcrossTenants)($user, [
            'tenant' => $this->selectedTenant(),
            'status' => EvaluationStatus::tryFrom($this->status),
            'type' => EvaluationType::tryFrom($this->type),
            'search' => $this->search !== '' ? trim($this->search) : null,
            'overdue' => $this->overdue,
        ]);
    }

    private function selectedTenant(): ?Tenant
    {
        return $this->tenantId === ''
            ? null
            : Tenant::query()->whereKey($this->tenantId)->first();
    }

    /** @return Collection<int, Tenant> */
    #[Computed]
    public function tenants(): Collection
    {
        return Tenant::query()->orderBy('name')->get(['id', 'name', 'slug']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(EvaluationStatus::cases())
            ->mapWithKeys(fn (EvaluationStatus $case) => [$case->value => $case->label()])
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        return collect(EvaluationType::cases())
            ->mapWithKeys(fn (EvaluationType $case) => [$case->value => $case->label()])
            ->all();
    }

    public function render(): View
    {
        return view('livewire.oversight.evaluation.evaluation-board');
    }
}
