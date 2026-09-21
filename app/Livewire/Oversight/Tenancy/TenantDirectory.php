<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Tenancy;

use App\Actions\Oversight\ListWorkspaceRegister;
use App\Actions\Oversight\SetTenantActive;
use App\Enums\TenantType;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The workspace register (plan §1/§11): every MDA on the platform, its
 * subdomain, the sector it reports into, how many people can enter it and how
 * many projects it carries.
 *
 * `tenants.view` opens the screen; `tenants.manage` works the suspend switch.
 * ExecutiveViewer holds the first and not the second — an executive reads the
 * register, the secretariat administers it.
 *
 * SUSPENDING IS NOT DELETING and the screen says so in as many words: the
 * confirmation spells out that the record, its projects and its audit trail
 * survive and that reactivation restores the workspace intact. Government
 * software that is vague about this is how somebody hesitates to merge two
 * agencies for a year.
 */
#[Layout('layouts::oversight')]
class TenantDirectory extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $sector = '';

    #[Url(except: '')]
    public string $status = '';

    /** The workspace whose activation flip is being confirmed. */
    public ?string $togglingUlid = null;

    public bool $togglingTo = false;

    public string $togglingReason = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Tenant::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedSector(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Tenant> */
    #[Computed]
    public function workspaces(): LengthAwarePaginator
    {
        return (new ListWorkspaceRegister)($this->actor(), [
            'search' => $this->search,
            'type' => TenantType::tryFrom($this->type),
            'sector' => $this->sector === '' ? null : (int) $this->sector,
            'status' => $this->status,
        ]);
    }

    /** @return array{total: int, active: int, suspended: int} */
    #[Computed]
    public function summary(): array
    {
        return (new ListWorkspaceRegister)->summary($this->actor());
    }

    /** @return array<string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        $options = [];

        foreach (TenantType::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** @return array<array-key, string> */
    #[Computed]
    public function sectorOptions(): array
    {
        return Sector::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])
            ->all();
    }

    /** @return array<string, string> */
    #[Computed]
    public function statusOptions(): array
    {
        return ['active' => __('Open'), 'suspended' => __('Suspended')];
    }

    /* ------------------------------------------------------------------ */
    /* Suspend / reopen — always confirmed, never destructive */
    /* ------------------------------------------------------------------ */

    public function confirmSetActive(string $ulid, bool $active): void
    {
        $this->authorize('setActive', $this->workspace($ulid));

        $this->failure = null;
        $this->togglingUlid = $ulid;
        $this->togglingTo = $active;
        $this->togglingReason = '';

        $this->dispatch('open-modal', 'set-tenant-active');
    }

    #[Computed]
    public function togglingWorkspace(): ?Tenant
    {
        return $this->togglingUlid === null ? null : Tenant::query()->where('ulid', $this->togglingUlid)->first();
    }

    public function applySetActive(): void
    {
        $tenant = $this->workspace((string) $this->togglingUlid);

        $this->authorize('setActive', $tenant);

        $this->validate([
            'togglingReason' => $this->togglingTo ? ['nullable', 'string', 'max:500'] : ['required', 'string', 'min:5', 'max:500'],
        ], [], ['togglingReason' => __('reason')]);

        (new SetTenantActive)($this->actor(), $tenant, $this->togglingTo, $this->togglingReason);

        $activated = $this->togglingTo;
        $this->togglingUlid = null;
        $this->togglingReason = '';

        unset($this->workspaces, $this->summary, $this->togglingWorkspace);

        $this->dispatch('close-modal', 'set-tenant-active');

        session()->flash('status', $activated
            ? __(':name is open again at :url — nothing was lost while it was suspended.', [
                'name' => $tenant->name,
                'url' => $tenant->url(),
            ])
            : __(':name is suspended. Its records, projects and audit trail are untouched and reopening restores it exactly.', [
                'name' => $tenant->name,
            ]));
    }

    /** Whether the viewer may work the suspend switch (view drives markup only). */
    public function actorCanManage(): bool
    {
        return $this->actor()->holdsGlobalPermission('tenants.manage');
    }

    /** Resolve a wire-supplied ulid; anything else is a 404. */
    private function workspace(string $ulid): Tenant
    {
        return Tenant::query()->where('ulid', $ulid)->firstOrFail();
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.oversight.tenancy.tenant-directory');
    }
}
