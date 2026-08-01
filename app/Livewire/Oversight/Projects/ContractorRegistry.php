<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Projects;

use App\Actions\Projects\BlacklistContractor;
use App\Actions\Projects\LiftContractorBlacklist;
use App\Actions\Projects\UpdateContractor;
use App\Enums\FirmType;
use App\Models\Contractor;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Vendor registry management — oversight only (design §1.3, §3).
 *
 * Editing and blacklisting live here rather than in a workspace because one MDA
 * must not rewrite a vendor record another MDA's contracts depend on, and a firm
 * blacklisted by Works must not keep winning work in Health.
 *
 * Blacklisting is never a field on the edit form: both Actions demand a stated
 * reason, so they get their own confirmation with a required reason box.
 */
#[Layout('layouts::oversight')]
class ContractorRegistry extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: false)]
    public bool $blacklistedOnly = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $rcNumber = '';

    public string $firmType = 'contractor';

    public string $category = '';

    public string $contactName = '';

    public string $contactEmail = '';

    public string $contactPhone = '';

    public string $address = '';

    public ?int $blacklistTargetId = null;

    public bool $lifting = false;

    public string $blacklistReason = '';

    public ?string $failure = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Contractor::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedBlacklistedOnly(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'type', 'blacklistedOnly']);
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->type !== '' || $this->blacklistedOnly;
    }

    /* ------------------------------------------------------------------ */
    /* Edit */
    /* ------------------------------------------------------------------ */

    public function edit(int $contractorId): void
    {
        $contractor = Contractor::query()->findOrFail($contractorId);

        $this->authorize('update', $contractor);

        $this->resetErrorBag();
        $this->failure = null;

        $this->editingId = $contractor->id;
        $this->name = $contractor->name;
        $this->rcNumber = (string) $contractor->rc_number;
        $this->firmType = $contractor->type->value;
        $this->category = (string) $contractor->category;
        $this->contactName = (string) $contractor->contact_name;
        $this->contactEmail = (string) $contractor->contact_email;
        $this->contactPhone = (string) $contractor->contact_phone;
        $this->address = (string) $contractor->address;

        $this->dispatch('open-modal', 'edit-contractor');
    }

    public function saveContractor(UpdateContractor $updateContractor): void
    {
        $contractor = Contractor::query()->findOrFail($this->editingId);

        $this->authorize('update', $contractor);

        $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'rcNumber' => ['nullable', 'string', 'max:40', Rule::unique('contractors', 'rc_number')->ignore($contractor->id)],
            'firmType' => ['required', Rule::enum(FirmType::class)],
            'category' => ['nullable', 'string', 'max:60'],
            'contactName' => ['nullable', 'string', 'max:255'],
            'contactEmail' => ['nullable', 'email:rfc', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'rcNumber' => __('RC number'),
            'firmType' => __('firm type'),
            'contactEmail' => __('contact email'),
        ]);

        $updateContractor($contractor, $this->actor(), [
            'name' => trim($this->name),
            'rc_number' => $this->nullIfBlank($this->rcNumber),
            'type' => $this->firmType,
            'category' => $this->nullIfBlank($this->category),
            'contact_name' => $this->nullIfBlank($this->contactName),
            'contact_email' => $this->nullIfBlank($this->contactEmail),
            'contact_phone' => $this->nullIfBlank($this->contactPhone),
            'address' => $this->nullIfBlank($this->address),
        ]);

        $this->editingId = null;
        unset($this->contractors);

        $this->dispatch('close-modal', 'edit-contractor');
        session()->flash('status', __('“:name” updated.', ['name' => trim($this->name)]));
    }

    /* ------------------------------------------------------------------ */
    /* Blacklist / lift — both require a stated reason */
    /* ------------------------------------------------------------------ */

    public function confirmBlacklist(int $contractorId, bool $lifting = false): void
    {
        $contractor = Contractor::query()->findOrFail($contractorId);

        $this->authorize('blacklist', $contractor);

        $this->resetErrorBag();
        $this->failure = null;
        $this->blacklistTargetId = $contractor->id;
        $this->lifting = $lifting;
        $this->blacklistReason = '';

        $this->dispatch('open-modal', 'blacklist-contractor');
    }

    public function applyBlacklist(BlacklistContractor $blacklist, LiftContractorBlacklist $lift): void
    {
        $contractor = Contractor::query()->findOrFail($this->blacklistTargetId);

        $this->authorize('blacklist', $contractor);

        $this->validate(
            ['blacklistReason' => ['required', 'string', 'min:10', 'max:1000']],
            [],
            ['blacklistReason' => __('reason')],
        );

        $this->lifting
            ? $lift($contractor, $this->actor(), $this->blacklistReason)
            : $blacklist($contractor, $this->actor(), $this->blacklistReason);

        $this->blacklistTargetId = null;
        $this->blacklistReason = '';
        unset($this->contractors, $this->stats);

        $this->dispatch('close-modal', 'blacklist-contractor');

        session()->flash('status', $this->lifting
            ? __('Blacklisting lifted for “:name”.', ['name' => $contractor->name])
            : __('“:name” is blacklisted and can no longer be awarded new contracts.', ['name' => $contractor->name]));
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function nullIfBlank(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * The register itself is global, but `withCount('contracts')` is not:
     * Contract is tenant-owned, so the count subquery meets the fail-closed
     * TenantScope — and this surface binds no tenant, so without a bypass the
     * whole screen 500s.
     *
     * The bypass is therefore load-bearing, not incidental, and it is
     * sanctioned here: app/Livewire/Oversight/ is on the discipline allowlist
     * in tests/Unit/TenancyDisciplineTest.php precisely for reads like this
     * one.
     *
     * ⚠ The number it produces is STATE-WIDE and is NOT the same figure as the
     * identically-named column on the tenant-side ContractorIndex, which
     * counts only the contracts of the workspace you are standing in. That is
     * the right figure for each surface — a per-MDA count is meaningless to
     * the state, and a state-wide count would leak volume between MDAs — but
     * two different numbers under one word is a trap, so the column header
     * here says "state-wide" out loud.
     *
     * @return LengthAwarePaginator<int, Contractor>
     */
    #[Computed]
    public function contractors(): LengthAwarePaginator
    {
        return app(CurrentTenant::class)->bypass(fn (): LengthAwarePaginator => Contractor::query()
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('rc_number', 'like', $term)
                    ->orWhere('category', 'like', $term));
            })
            ->when($this->type !== '', fn (Builder $q) => $q->where('type', $this->type))
            ->when($this->blacklistedOnly, fn (Builder $q) => $q->where('is_blacklisted', true))
            ->withCount('contracts')
            ->orderBy('name')
            ->paginate(25));
    }

    /** @return array{total: int, blacklisted: int} */
    #[Computed]
    public function stats(): array
    {
        $totals = Contractor::query()
            ->toBase()
            ->selectRaw('COUNT(*) as aggregate_count')
            ->selectRaw('SUM(CASE WHEN is_blacklisted = 1 THEN 1 ELSE 0 END) as aggregate_blacklisted')
            ->first();

        return [
            'total' => (int) ($totals->aggregate_count ?? 0),
            'blacklisted' => (int) ($totals->aggregate_blacklisted ?? 0),
        ];
    }

    /** @return array<string, string> */
    #[Computed]
    public function typeOptions(): array
    {
        return collect(FirmType::cases())
            ->mapWithKeys(fn (FirmType $case) => [$case->value => $case->label()])
            ->all();
    }

    public function render(): View
    {
        return view('livewire.oversight.projects.contractor-registry');
    }
}
