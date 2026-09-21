<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Inspections;

use App\Actions\Oversight\ListInspectionsAcrossTenants;
use App\Enums\InspectionOutcome;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Models\SiteInspection;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The state's field-work board — every MDA's site visits on one screen.
 *
 * READ-ONLY. There is no oversight Action in this module that writes an
 * inspection: the state observes MDA field work, it does not conduct it
 * through this screen. Scheduling and sign-off are the MDA's acts, on the
 * MDA's surface, by people who hold the permission in that workspace's team.
 *
 * The cross-MDA read goes through app/Actions/Oversight/ListInspectionsAcrossTenants,
 * which re-checks `inspections.view` in the GLOBAL permission team before
 * bypassing tenancy. This component never calls withoutTenancy() itself: the
 * bypass belongs with the authorization check that justifies it, in one place.
 *
 * The summary row is the exception, and it is deliberate — see stats().
 */
#[Layout('layouts::oversight')]
class InspectionBoard extends Component
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

    #[Url(except: '')]
    public string $outcome = '';

    /** Field work that has gone quiet: the visit happened, the report never came. */
    #[Url(except: false)]
    public bool $overdue = false;

    /** major_issues / work_stopped — what the state actually needs to see. */
    #[Url(except: false)]
    public bool $escalated = false;

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        // The same question the Action asks before it bypasses anything. Asked
        // here too so the screen 403s at the door rather than rendering a
        // header and then throwing.
        abort_unless($user->holdsGlobalPermission('inspections.view'), 403);
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

    public function updatedOutcome(): void
    {
        $this->resetPage();
    }

    public function updatedOverdue(): void
    {
        $this->resetPage();
    }

    public function updatedEscalated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'tenantId', 'status', 'type', 'outcome', 'overdue', 'escalated']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->tenantId !== '' || $this->status !== ''
            || $this->type !== '' || $this->outcome !== '' || $this->overdue || $this->escalated;
    }

    /**
     * @return LengthAwarePaginator<int, SiteInspection>
     */
    #[Computed]
    public function inspections(): LengthAwarePaginator
    {
        /** @var User $user */
        $user = auth()->user();

        return app(ListInspectionsAcrossTenants::class)($user, [
            'tenant' => $this->selectedTenant(),
            'status' => InspectionStatus::tryFrom($this->status),
            'type' => InspectionType::tryFrom($this->type),
            'outcome' => InspectionOutcome::tryFrom($this->outcome),
            'search' => $this->search === '' ? null : $this->search,
            'overdue' => $this->overdue,
            'escalated' => $this->escalated,
        ]);
    }

    private function selectedTenant(): ?Tenant
    {
        if ($this->tenantId === '') {
            return null;
        }

        return Tenant::query()->whereKey($this->tenantId)->first();
    }

    /**
     * The state-wide tallies. Three figures in ONE round trip.
     *
     * The bypass lives HERE rather than in an Action, which is the one place
     * this module departs from "the bypass belongs with its authorization
     * check" — and it is allowed: the discipline sweep permits `->bypass(` in
     * app/Livewire/Oversight precisely for aggregates like this one. mount()
     * has already refused anyone without `inspections.view` in the GLOBAL
     * team, so the same question has been asked; a whole Action wrapping one
     * aggregate query would be ceremony, not safety.
     *
     * The tallies deliberately ignore the filter bar: they are the state of
     * the state's field work, not of the current search.
     *
     * @return array{total: int, escalated: int, reports_overdue: int}
     */
    #[Computed]
    public function stats(): array
    {
        return app(CurrentTenant::class)->bypass(function (): array {
            $row = SiteInspection::query()
                ->withoutTenancy()
                ->toBase()
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('COUNT(CASE WHEN outcome IN (?, ?) THEN 1 END) as escalated', [
                    InspectionOutcome::MajorIssues->value,
                    InspectionOutcome::WorkStopped->value,
                ])
                ->selectRaw(
                    'COUNT(CASE WHEN status = ? AND report_due_at IS NOT NULL AND report_due_at < ? THEN 1 END) as reports_overdue',
                    [InspectionStatus::InProgress->value, now()],
                )
                ->first();

            return [
                'total' => (int) ($row->total ?? 0),
                'escalated' => (int) ($row->escalated ?? 0),
                'reports_overdue' => (int) ($row->reports_overdue ?? 0),
            ];
        });
    }

    /**
     * @return Collection<int, Tenant>
     */
    #[Computed]
    public function tenants(): Collection
    {
        return Tenant::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        return collect(InspectionStatus::cases())
            ->mapWithKeys(fn (InspectionStatus $case) => [$case->value => $case->label()])
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        return collect(InspectionType::cases())
            ->mapWithKeys(fn (InspectionType $case) => [$case->value => $case->label()])
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function outcomeOptions(): array
    {
        return collect(InspectionOutcome::cases())
            ->mapWithKeys(fn (InspectionOutcome $case) => [$case->value => $case->label()])
            ->all();
    }

    public function render(): View
    {
        return view('livewire.oversight.inspections.inspection-board');
    }
}
