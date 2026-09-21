<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The only writer of the settings tables.
 *
 * Three things happen here that a settings screen must never be trusted to
 * repeat: the key is checked against the declarative map (an unknown key is a
 * bug or an attack, never a new feature), the value is re-validated
 * server-side against that entry's own rules, and the change is written to the
 * append-only activity log with its before and after. A settings row silently
 * changing a statutory deadline with nobody's name on it is exactly the audit
 * gap a government client asks about first.
 *
 * SCOPE. `$tenant === null` writes the instance value (the `settings` table,
 * global by definition); a tenant writes its own override row. The override
 * table is tenant-scoped by BelongsToTenant, so the write must happen with
 * that tenant bound — which is true on the workspace surface and enforced
 * below rather than assumed.
 *
 * Reads stay in App\Support\SettingsRepository. There is exactly one reader
 * and exactly one writer, so a screen can never show a value the engine does
 * not use.
 *
 * @throws ValidationException when the value fails the definition's rules
 */
class SaveSetting
{
    public function __invoke(
        User $actor,
        string $group,
        string $key,
        mixed $input,
        ?Tenant $tenant = null,
    ): void {
        $definition = SettingDefinitions::find($group, $key);

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown setting [{$group}.{$key}].");
        }

        $this->authorize($actor, $definition, $tenant);

        Validator::make(
            ['value' => $input],
            ['value' => $definition->validationRules()],
            [],
            ['value' => $definition->label],
        )->validate();

        $value = $definition->fromForm($input);

        $this->guardCoherence($definition, $value);

        $previous = (new SettingsRepository)->get($group, $key, $definition->default);

        $model = $tenant === null
            ? $this->writeInstance($group, $key, $value)
            : $this->writeOverride($group, $key, $value);

        activity('settings')
            ->causedBy($actor)
            ->performedOn($model)
            ->withProperties([
                'old' => ['value' => $previous],
                'attributes' => ['value' => $value],
                'group' => $group,
                'key' => $key,
                'scope' => $tenant === null ? 'instance' : $tenant->slug,
            ])
            ->log($tenant === null ? 'setting.instance_updated' : 'setting.override_updated');
    }

    /**
     * Instance settings are state-level authority read from the GLOBAL
     * permission team; an override is workspace authority read from the bound
     * workspace. An MDA admin holds `settings.manage` in their own team and
     * nothing at all in the state's, so the split needs no second permission.
     */
    private function authorize(User $actor, SettingDefinition $definition, ?Tenant $tenant): void
    {
        if ($tenant === null) {
            if (! $actor->holdsGlobalPermission('settings.manage')) {
                throw new AuthorizationException('Changing instance settings requires state-level settings.manage authority.');
            }

            return;
        }

        if (! $definition->tenantOverridable) {
            throw new InvalidArgumentException(
                "[{$definition->id()}] is a state-wide setting and cannot be overridden per workspace."
            );
        }

        $current = app(CurrentTenant::class);

        if (! $current->bound() || $current->idOrFail() !== $tenant->id) {
            throw new AuthorizationException('A workspace override may only be written from that workspace.');
        }

        if (! $actor->can('settings.manage')) {
            throw new AuthorizationException('Changing workspace settings requires settings.manage in this workspace.');
        }
    }

    /**
     * The one cross-field rule the map cannot express: amber must sit below
     * green. Bands the wrong way round would colour every indicator on every
     * dashboard incorrectly and look like a data problem, not a settings one.
     *
     * The comparison reads the OTHER band through SettingsRepository, so on a
     * workspace surface it compares against whatever that workspace actually
     * resolves — its own override if it has one, the state's floor if not.
     */
    private function guardCoherence(SettingDefinition $definition, mixed $value): void
    {
        if ($definition->group !== 'indicators') {
            return;
        }

        if (! in_array($definition->key, ['on_track_percent', 'at_risk_percent'], true)) {
            return;
        }

        $repository = new SettingsRepository;

        $onTrack = $definition->key === 'on_track_percent'
            ? (int) $value
            : $repository->int('indicators', 'on_track_percent', 90);

        $atRisk = $definition->key === 'at_risk_percent'
            ? (int) $value
            : $repository->int('indicators', 'at_risk_percent', 70);

        if ($atRisk >= $onTrack) {
            throw ValidationException::withMessages([
                'value' => __('The at-risk band (:at_risk%) must sit below the on-track band (:on_track%).', [
                    'at_risk' => $atRisk,
                    'on_track' => $onTrack,
                ]),
            ]);
        }
    }

    private function writeInstance(string $group, string $key, mixed $value): Model
    {
        $setting = Setting::query()->where('group', $group)->where('key', $key)->first();

        if ($setting === null) {
            return Setting::create(['group' => $group, 'key' => $key, 'value' => $value]);
        }

        $setting->value = $value;
        $setting->save();

        return $setting;
    }

    private function writeOverride(string $group, string $key, mixed $value): Model
    {
        // TenantSetting is tenant-scoped, so this lookup can only ever see the
        // bound workspace's own rows and the create auto-fills tenant_id.
        $override = TenantSetting::query()->where('group', $group)->where('key', $key)->first();

        if ($override === null) {
            return TenantSetting::create(['group' => $group, 'key' => $key, 'value' => $value]);
        }

        $override->value = $value;
        $override->save();

        return $override;
    }
}
