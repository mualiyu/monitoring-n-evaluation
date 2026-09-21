<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Settings;

use App\Actions\Settings\SaveSetting;
use App\Actions\Settings\SettingDefinition;
use App\Actions\Settings\SettingDefinitions;
use App\Models\Setting;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Instance settings: the state's own policy floor.
 *
 * ONE EDITOR, DRIVEN BY THE MAP. Every field on this screen comes from
 * App\Actions\Settings\SettingDefinitions, so a new configurable number is one
 * entry there and it appears here, validated, with its hint and its bounds —
 * and appears on the workspace override screen at the same moment. Two
 * hand-built forms would have let the state screen accept 0 where the MDA
 * screen accepted 1.
 *
 * READS GO THROUGH SettingsRepository, the single reader, which is what
 * guarantees the number shown here is the number the deadline engine will
 * actually use. Writes go through SaveSetting, the single writer, which
 * re-validates server-side and records the before/after in the append-only
 * activity log.
 */
#[Layout('layouts::oversight')]
class InstanceSettings extends Component
{
    /** Which group is open. Persisted so a deep link lands on the right tab. */
    #[Url(except: 'instance')]
    public string $group = 'instance';

    /**
     * Form state, nested by group so wire:model="values.reporting.monthly_due_days"
     * addresses one field.
     *
     * @var array<string, array<string, string|bool>>
     */
    public array $values = [];

    public ?string $failure = null;

    public function mount(): void
    {
        $this->authorize('manage', Setting::class);

        if (! in_array($this->group, SettingDefinitions::groups(), true)) {
            $this->group = SettingDefinitions::groups()[0];
        }

        $this->loadValues();
    }

    private function loadValues(): void
    {
        $repository = new SettingsRepository;
        $values = [];

        foreach (SettingDefinitions::all() as $definition) {
            $values[$definition->group][$definition->key] = $definition->toForm(
                $repository->get($definition->group, $definition->key, $definition->default),
            );
        }

        $this->values = $values;
    }

    public function selectGroup(string $group): void
    {
        if (in_array($group, SettingDefinitions::groups(), true)) {
            $this->group = $group;
            $this->failure = null;
            $this->resetErrorBag();
        }
    }

    /**
     * Save one group. Per-group rather than per-screen because these are
     * unrelated policies — somebody retuning the reminder ladder should not be
     * made to re-submit the state's terminology, and a validation failure in
     * one group should not discard edits in another.
     */
    public function saveGroup(string $group): void
    {
        $this->authorize('manage', Setting::class);

        $this->failure = null;
        $this->resetErrorBag();

        $definitions = SettingDefinitions::inGroup($group);

        if ($definitions === []) {
            return;
        }

        $saved = 0;

        foreach ($definitions as $definition) {
            $input = $this->values[$definition->group][$definition->key] ?? null;

            try {
                (new SaveSetting)($this->actor(), $definition->group, $definition->key, $input);
                $saved++;
            } catch (ValidationException $e) {
                // SaveSetting validates under the key `value`; re-point the
                // message at the field the user is actually looking at.
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

        session()->flash('status', __(':group saved — :count setting(s) now in force across the instance.', [
            'group' => SettingDefinitions::groupLabel($group),
            'count' => $saved,
        ]));
    }

    /** @return list<string> */
    #[Computed]
    public function groups(): array
    {
        return SettingDefinitions::groups();
    }

    /** @return list<SettingDefinition> */
    #[Computed]
    public function definitions(): array
    {
        return SettingDefinitions::inGroup($this->group);
    }

    public function groupLabel(string $group): string
    {
        return SettingDefinitions::groupLabel($group);
    }

    public function groupDescription(string $group): string
    {
        return SettingDefinitions::groupDescription($group);
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.oversight.settings.instance-settings');
    }
}
