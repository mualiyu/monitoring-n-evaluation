<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Settings;

use App\Actions\Settings\ClearTenantSetting;
use App\Actions\Settings\SaveSetting;
use App\Actions\Settings\SettingDefinition;
use App\Actions\Settings\SettingDefinitions;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Workspace settings: where an MDA runs a rule tighter than the state's.
 *
 * EVERY FIELD SHOWS WHAT IT WOULD INHERIT. An override screen that only shows
 * the current number cannot answer the question people actually have — "is
 * this ours or the state's?" — and the usual result is a workspace freezing
 * today's state default under its own name and then never following the state
 * again. So each row prints the inherited value beside the control and offers
 * one button to go back to it.
 *
 * CLEARING DELETES THE ROW rather than writing the state's value into it, so
 * inheritance stays live: an MDA that clears its reminder ladder follows the
 * state the next time the state retunes it.
 *
 * Only groups the map marks `tenantOverridable` appear. Terminology and
 * instance identity are state-wide by definition — one ministry renaming "MDA"
 * for everybody else is not a workspace preference — and they are absent
 * rather than shown disabled, because a control that cannot be used is a
 * question nobody should have to ask.
 */
#[Layout('layouts::tenant')]
class WorkspaceSettings extends Component
{
    #[Url(except: '')]
    public string $group = '';

    /** @var array<string, array<string, string|bool>> */
    public array $values = [];

    /** @var array<string, array<string, bool>> */
    public array $overridden = [];

    public ?string $failure = null;

    public function mount(): void
    {
        $this->authorize('override', Setting::class);

        $groups = SettingDefinitions::tenantGroups();

        if (! in_array($this->group, $groups, true)) {
            $this->group = $groups[0];
        }

        $this->loadValues();
    }

    private function loadValues(): void
    {
        $repository = new SettingsRepository;

        $overrides = TenantSetting::query()->get()
            ->mapWithKeys(fn (TenantSetting $row): array => [$row->group.'.'.$row->key => $row->value]);

        $values = [];
        $overridden = [];

        foreach (SettingDefinitions::all() as $definition) {
            if (! $definition->tenantOverridable) {
                continue;
            }

            $has = $overrides->has($definition->id());

            $overridden[$definition->group][$definition->key] = $has;
            $values[$definition->group][$definition->key] = $definition->toForm(
                $has
                    ? $overrides->get($definition->id())
                    : $repository->get($definition->group, $definition->key, $definition->default),
            );
        }

        $this->values = $values;
        $this->overridden = $overridden;
    }

    /**
     * What each setting would resolve to with this workspace's overrides
     * removed — the state's floor.
     *
     * Read through the single reader with no tenant bound, which is exactly
     * how SettingsRepository behaves for an unbound context: tenant overrides
     * are skipped and the chain falls through to the instance value and then
     * the deployment default. One context switch for the whole screen, not one
     * per field.
     *
     * @return array<string, mixed> keyed by "group.key"
     */
    #[Computed]
    public function inherited(): array
    {
        return app(CurrentTenant::class)->runWithoutTenant(function (): array {
            $repository = new SettingsRepository;
            $inherited = [];

            foreach (SettingDefinitions::all() as $definition) {
                if (! $definition->tenantOverridable) {
                    continue;
                }

                $inherited[$definition->id()] = $repository->get(
                    $definition->group,
                    $definition->key,
                    $definition->default,
                );
            }

            return $inherited;
        });
    }

    public function selectGroup(string $group): void
    {
        if (in_array($group, SettingDefinitions::tenantGroups(), true)) {
            $this->group = $group;
            $this->failure = null;
            $this->resetErrorBag();
        }
    }

    public function saveGroup(string $group): void
    {
        $this->authorize('override', Setting::class);

        $this->failure = null;
        $this->resetErrorBag();

        $tenant = $this->workspace();
        $definitions = SettingDefinitions::inGroup($group);

        foreach ($definitions as $definition) {
            if (! $definition->tenantOverridable) {
                continue;
            }

            $input = $this->values[$definition->group][$definition->key] ?? null;

            try {
                (new SaveSetting)($this->actor(), $definition->group, $definition->key, $input, $tenant);
            } catch (ValidationException $e) {
                foreach ($e->validator->errors()->all() as $message) {
                    $this->addError("values.{$definition->group}.{$definition->key}", $message);
                }
            } catch (InvalidArgumentException $e) {
                $this->failure = $e->getMessage();
            }
        }

        if ($this->getErrorBag()->isNotEmpty() || $this->failure !== null) {
            return;
        }

        $this->loadValues();
        unset($this->inherited);

        session()->flash('status', __(':group saved for this workspace.', [
            'group' => SettingDefinitions::groupLabel($group),
        ]));
    }

    /** Drop one override so the setting follows the state again. */
    public function clearOverride(string $group, string $key): void
    {
        $this->authorize('override', Setting::class);

        $this->failure = null;

        (new ClearTenantSetting)($this->actor(), $group, $key, $this->workspace());

        $this->loadValues();
        unset($this->inherited);

        $definition = SettingDefinitions::find($group, $key);

        session()->flash('status', __(':setting now follows the state again.', [
            'setting' => $definition->label ?? $key,
        ]));
    }

    /** @return list<string> */
    #[Computed]
    public function groups(): array
    {
        return SettingDefinitions::tenantGroups();
    }

    /** @return list<SettingDefinition> */
    #[Computed]
    public function definitions(): array
    {
        return array_values(array_filter(
            SettingDefinitions::inGroup($this->group),
            fn (SettingDefinition $definition): bool => $definition->tenantOverridable,
        ));
    }

    public function groupLabel(string $group): string
    {
        return SettingDefinitions::groupLabel($group);
    }

    public function groupDescription(string $group): string
    {
        return SettingDefinitions::groupDescription($group);
    }

    public function isOverridden(SettingDefinition $definition): bool
    {
        return (bool) ($this->overridden[$definition->group][$definition->key] ?? false);
    }

    public function inheritedDisplay(SettingDefinition $definition): string
    {
        return $definition->display($this->inherited()[$definition->id()] ?? $definition->default);
    }

    private function workspace(): Tenant
    {
        return app(CurrentTenant::class)->getOrFail();
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.tenant.settings.workspace-settings');
    }
}
