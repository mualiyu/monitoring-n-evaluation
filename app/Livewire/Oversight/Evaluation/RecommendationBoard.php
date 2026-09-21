<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Evaluation;

use App\Actions\Oversight\ListRecommendationsAcrossTenants;
use App\Enums\RecommendationPriority;
use App\Enums\RecommendationStatus;
use App\Models\Recommendation;
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
 * The state-wide follow-up register — the board the whole evaluation module
 * exists to make possible.
 *
 * An evaluation that produces recommendations nobody implements is a document,
 * not a control. This screen is where the secretariat sees, across every MDA,
 * what was recommended, who owns it, and what is overdue. It defaults to
 * OUTSTANDING items, because the implemented ones are not the ones that need
 * a secretariat.
 *
 * READ-ONLY, and the cross-MDA read goes through app/Actions/Oversight, which
 * re-checks `recommendations.view` in the GLOBAL permission team before
 * bypassing tenancy.
 */
#[Layout('layouts::oversight')]
class RecommendationBoard extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'mda', except: '')]
    public string $tenantId = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $priority = '';

    #[Url(except: true)]
    public bool $outstanding = true;

    #[Url(except: false)]
    public bool $overdue = false;

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        abort_unless($user->holdsGlobalPermission('recommendations.view'), 403);
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

    public function updatedPriority(): void
    {
        $this->resetPage();
    }

    public function updatedOutstanding(): void
    {
        $this->resetPage();
    }

    public function updatedOverdue(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'tenantId', 'status', 'priority', 'overdue']);
        $this->outstanding = true;
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== ''
            || $this->tenantId !== ''
            || $this->status !== ''
            || $this->priority !== ''
            || $this->overdue
            || ! $this->outstanding;
    }

    /** @return LengthAwarePaginator<int, Recommendation> */
    #[Computed]
    public function recommendations(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return (new ListRecommendationsAcrossTenants)($user, [
            'tenant' => $this->selectedTenant(),
            'status' => RecommendationStatus::tryFrom($this->status),
            'priority' => RecommendationPriority::tryFrom($this->priority),
            'search' => $this->search !== '' ? trim($this->search) : null,
            // An explicit status filter overrides the outstanding default —
            // asking for "implemented" and getting nothing would be a bug the
            // user cannot see the cause of.
            'outstanding' => $this->outstanding && $this->status === '',
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
        return collect(RecommendationStatus::cases())
            ->mapWithKeys(fn (RecommendationStatus $case) => [$case->value => $case->label()])
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function priorityOptions(): array
    {
        return collect(RecommendationPriority::cases())
            ->mapWithKeys(fn (RecommendationPriority $case) => [$case->value => $case->label()])
            ->all();
    }

    public function render(): View
    {
        return view('livewire.oversight.evaluation.recommendation-board');
    }
}
