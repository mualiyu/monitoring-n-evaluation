<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Tenancy;

use App\Actions\Oversight\SetTenantActive;
use App\Actions\Oversight\UpdateTenantProfile;
use App\Actions\Settings\SettingDefinition;
use App\Actions\Settings\SettingDefinitions;
use App\Enums\TenantType;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * One entity's record: what it is called, who to call, how it is branded, and
 * whether its subdomain is open.
 *
 * THE SUBDOMAIN IS SHOWN, NOT EDITED. It is a live DNS label that every
 * bookmark, emailed link and signed download URL in that ministry depends on;
 * renaming it is a migration with a redirect plan, not a text input. The
 * screen says so where somebody would look for the field.
 *
 * THE OVERRIDES PANEL is read-only here on purpose. Oversight can see which
 * of the state's rules a workspace has retuned for itself — that is an
 * assurance question — but it changes them from inside the workspace, where
 * the MDA admin who owns the decision can see them too. A settings value with
 * two write paths is a settings value nobody trusts.
 */
#[Layout('layouts::oversight')]
class TenantSettings extends Component
{
    use WithFileUploads;

    public Tenant $tenant;

    /* Profile */
    public string $name = '';

    public string $shortName = '';

    public string $type = '';

    public string $sectorId = '';

    public string $contactName = '';

    public string $contactEmail = '';

    public string $contactPhone = '';

    /* Branding */
    public string $displayName = '';

    public string $brandColor = '';

    public ?TemporaryUploadedFile $logo = null;

    /* Activation */
    public string $deactivationReason = '';

    public ?string $failure = null;

    public function mount(Tenant $tenant): void
    {
        $this->authorize('view', $tenant);

        $this->tenant = $tenant;
        $this->fillFromRecord();
    }

    private function fillFromRecord(): void
    {
        $branding = is_array($this->tenant->branding) ? $this->tenant->branding : [];

        $this->name = $this->tenant->name;
        $this->shortName = (string) $this->tenant->short_name;
        $this->type = $this->tenant->type->value;
        $this->sectorId = $this->tenant->sector_id === null ? '' : (string) $this->tenant->sector_id;
        $this->contactName = (string) $this->tenant->contact_name;
        $this->contactEmail = (string) $this->tenant->contact_email;
        $this->contactPhone = (string) $this->tenant->contact_phone;
        $this->displayName = is_string($branding['display_name'] ?? null) ? $branding['display_name'] : '';
        $this->brandColor = is_string($branding['brand_color'] ?? null) ? $branding['brand_color'] : '';
    }

    /* ------------------------------------------------------------------ */
    /* Save */
    /* ------------------------------------------------------------------ */

    public function save(): void
    {
        $this->authorize('update', $this->tenant);

        $this->failure = null;

        $this->validate([
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'shortName' => ['nullable', 'string', 'max:60'],
            'type' => ['required', Rule::in(array_keys($this->typeOptions()))],
            'sectorId' => ['nullable', Rule::in(array_keys($this->sectorOptions()))],
            'contactName' => ['nullable', 'string', 'max:160'],
            'contactEmail' => ['nullable', 'email:rfc', 'max:160'],
            'contactPhone' => ['nullable', 'string', 'max:32'],
            'displayName' => ['nullable', 'string', 'max:120'],
            'brandColor' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // The browser accept attribute is a hint; the real mime and size
            // check runs server-side inside UpdateTenantProfile.
            'logo' => ['nullable', 'image', 'max:2048'],
        ], [], [
            'name' => __('entity name'),
            'shortName' => __('short name'),
            'type' => __('entity type'),
            'sectorId' => __('sector'),
            'contactEmail' => __('contact email'),
            'displayName' => __('display name'),
            'brandColor' => __('brand colour'),
            'logo' => __('logo'),
        ]);

        try {
            (new UpdateTenantProfile)($this->actor(), $this->tenant, [
                'name' => $this->name,
                'short_name' => $this->shortName,
                'type' => $this->type,
                'sector_id' => $this->sectorId === '' ? null : (int) $this->sectorId,
                'contact_name' => $this->contactName,
                'contact_email' => $this->contactEmail,
                'contact_phone' => $this->contactPhone,
                'display_name' => $this->displayName,
                'brand_color' => $this->brandColor,
            ], $this->logo);
        } catch (ValidationException $e) {
            // The Action re-validated the upload server-side and refused it.
            $this->failure = collect($e->errors())->flatten()->implode(' ');

            return;
        }

        $this->logo = null;
        $this->tenant = Tenant::query()->whereKey($this->tenant->getKey())->firstOrFail();
        $this->fillFromRecord();

        unset($this->overrides);

        session()->flash('status', __('Workspace record updated.'));
    }

    /* ------------------------------------------------------------------ */
    /* Suspend / reopen */
    /* ------------------------------------------------------------------ */

    public function confirmSetActive(): void
    {
        $this->authorize('setActive', $this->tenant);

        $this->deactivationReason = '';
        $this->dispatch('open-modal', 'set-tenant-active');
    }

    public function applySetActive(): void
    {
        $this->authorize('setActive', $this->tenant);

        $activating = ! $this->tenant->is_active;

        $this->validate([
            'deactivationReason' => $activating ? ['nullable', 'string', 'max:500'] : ['required', 'string', 'min:5', 'max:500'],
        ], [], ['deactivationReason' => __('reason')]);

        (new SetTenantActive)($this->actor(), $this->tenant, $activating, $this->deactivationReason);

        $this->tenant = Tenant::query()->whereKey($this->tenant->getKey())->firstOrFail();
        $this->deactivationReason = '';

        $this->dispatch('close-modal', 'set-tenant-active');

        session()->flash('status', $activating
            ? __('The workspace is open again at :url.', ['url' => $this->tenant->url()])
            : __('The workspace is suspended. Every record it holds is untouched.'));
    }

    /* ------------------------------------------------------------------ */

    /**
     * What this workspace has retuned for itself, read-only.
     *
     * TenantSetting is tenant-scoped and this surface binds no tenant, so the
     * read runs as that workspace — the sanctioned oversight context switch,
     * inside an oversight component, after an authorize() on the record.
     *
     * @return Collection<int, array{definition: SettingDefinition, value: mixed, inherited: mixed}>
     */
    #[Computed]
    public function overrides(): Collection
    {
        $rows = app(CurrentTenant::class)->runAs(
            $this->tenant,
            fn (): Collection => TenantSetting::query()->get(),
        );

        return $rows
            ->map(function (TenantSetting $row): ?array {
                $definition = SettingDefinitions::find($row->group, $row->key);

                if ($definition === null) {
                    return null;
                }

                return [
                    'definition' => $definition,
                    'value' => $row->value,
                    'inherited' => $definition->default,
                ];
            })
            ->filter()
            ->values();
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

    public function currentLogoUrl(): ?string
    {
        $branding = is_array($this->tenant->branding) ? $this->tenant->branding : [];
        $url = $branding['logo_url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function actorCanManage(): bool
    {
        return $this->actor()->holdsGlobalPermission('tenants.manage');
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.oversight.tenancy.tenant-settings');
    }
}
