<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Projects;

use App\Actions\Projects\RegisterContractor;
use App\Enums\FirmType;
use App\Models\Contractor;
use App\Models\User;
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
 * Vendor registry, workspace view (design §1.3, §5).
 *
 * The registry is deliberately state-wide, not per-MDA: a firm blacklisted by
 * one ministry must not keep winning work in the next one. So this screen can
 * ADD a firm (deduped on RC number) and read the whole register — but editing
 * and blacklisting are oversight-only, and the UI says so rather than showing
 * buttons that 403.
 */
#[Layout('layouts::tenant')]
class ContractorIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: false)]
    public bool $blacklistedOnly = false;

    // Registration form
    public string $name = '';

    public string $rcNumber = '';

    public string $firmType = 'contractor';

    public string $category = '';

    public string $contactName = '';

    public string $contactEmail = '';

    public string $contactPhone = '';

    public string $address = '';

    public ?string $notice = null;

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

    /** @return LengthAwarePaginator<int, Contractor> */
    #[Computed]
    public function contractors(): LengthAwarePaginator
    {
        return Contractor::query()
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
            ->paginate(25);
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

    public function register(RegisterContractor $registerContractor): void
    {
        $this->authorize('create', Contractor::class);

        $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'rcNumber' => ['nullable', 'string', 'max:40'],
            'firmType' => ['required', Rule::enum(FirmType::class)],
            'category' => ['nullable', 'string', 'max:60'],
            'contactName' => ['nullable', 'string', 'max:255'],
            'contactEmail' => ['nullable', 'email:rfc', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'rcNumber' => __('RC number'),
            'firmType' => __('firm type'),
            'contactName' => __('contact name'),
            'contactEmail' => __('contact email'),
            'contactPhone' => __('contact phone'),
        ]);

        /** @var User $actor */
        $actor = auth()->user();

        $before = Contractor::query()->count();

        $contractor = $registerContractor($actor, [
            'name' => trim($this->name),
            'rc_number' => trim($this->rcNumber),
            'type' => $this->firmType,
            'category' => $this->nullIfBlank($this->category),
            'contact_name' => $this->nullIfBlank($this->contactName),
            'contact_email' => $this->nullIfBlank($this->contactEmail),
            'contact_phone' => $this->nullIfBlank($this->contactPhone),
            'address' => $this->nullIfBlank($this->address),
        ]);

        // The Action dedupes on RC number and hands back the existing firm.
        // Saying so is the difference between "done" and a user creating the
        // same vendor three times because nothing appeared to happen.
        $this->notice = Contractor::query()->count() === $before
            ? __('That RC number is already registered as “:name”, so we linked you to the existing record instead of creating a duplicate.', ['name' => $contractor->name])
            : __('“:name” added to the vendor registry.', ['name' => $contractor->name]);

        $this->reset(['name', 'rcNumber', 'category', 'contactName', 'contactEmail', 'contactPhone', 'address']);
        unset($this->contractors, $this->stats);

        $this->dispatch('close-modal', 'register-contractor');
    }

    private function nullIfBlank(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public function render(): View
    {
        return view('livewire.tenant.projects.contractor-index');
    }
}
