<?php

/**
 * The configuration chain, end to end and through the screens that write it:
 *
 *     tenant override → instance setting → config/platform.php default
 *
 * tests/Feature/Tenancy/SettingsRepositoryTest proves the READ chain at the
 * repository. What is unproven until here is the WRITE round trip: that a
 * value saved on the state screen is the value the engine reads, that an MDA's
 * override shadows it only for that MDA, that CLEARING an override drops the
 * row (so inheritance stays live rather than freezing today's number under the
 * workspace's own name) — and that the one reader and the one writer never
 * disagree, which is the entire reason there is exactly one of each.
 *
 * The map (App\Actions\Settings\SettingDefinitions) drives both screens and
 * the validator, so the definitions are exercised as the contract they are.
 */

use App\Actions\Settings\ClearTenantSetting;
use App\Actions\Settings\SaveSetting;
use App\Actions\Settings\SettingDefinitions;
use App\Enums\Role;
use App\Livewire\Oversight\Settings\InstanceSettings;
use App\Livewire\Oversight\Tenancy\TenantSettings;
use App\Livewire\Tenant\Settings\WorkspaceSettings;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);
    $this->settings = new SettingsRepository;

    actingWithoutTenant();

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->stateAdmin->forceFill(['two_factor_required_at' => now()])->save();

    $this->worksAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->healthAdmin = memberOf(User::factory()->create(), $this->health, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    actingWithoutTenant();
});

/* -------------------------------------------------------------------------- */
/* The whole chain, one setting at a time */
/* -------------------------------------------------------------------------- */

it('resolves the deployment default, then the instance value, then this MDA’s override', function () {
    $default = (int) config('platform.reporting.monthly_due_days');

    // 1. Nothing written anywhere: the config default answers, in every
    //    context — inside a workspace and on the state surface.
    expect($this->settings->int('reporting', 'monthly_due_days', 7))->toBe($default);
    expect($this->current->runAs($this->works, fn (): int => $this->settings->int('reporting', 'monthly_due_days', 7)))
        ->toBe($default);

    // 2. The state sets its floor. Both surfaces now read it.
    (new SaveSetting)($this->stateAdmin, 'reporting', 'monthly_due_days', '14');

    expect($this->settings->int('reporting', 'monthly_due_days', 7))->toBe(14);
    expect($this->current->runAs($this->works, fn (): int => $this->settings->int('reporting', 'monthly_due_days', 7)))
        ->toBe(14);

    // 3. One MDA runs tighter than the floor. Only that MDA sees it.
    $this->current->runAs($this->works, function () {
        (new SaveSetting)($this->worksAdmin, 'reporting', 'monthly_due_days', '5', $this->works);
    });

    expect($this->current->runAs($this->works, fn (): int => $this->settings->int('reporting', 'monthly_due_days', 7)))
        ->toBe(5);
    expect($this->current->runAs($this->health, fn (): int => $this->settings->int('reporting', 'monthly_due_days', 7)))
        ->toBe(14);
    // The state's own reading is unchanged — an MDA cannot retune the floor.
    expect($this->settings->int('reporting', 'monthly_due_days', 7))->toBe(14);

    // 4. The MDA drops its override. Inheritance is LIVE: the row is gone, so
    //    the workspace follows the state the next time the state moves.
    $this->current->runAs($this->works, function () {
        (new ClearTenantSetting)($this->worksAdmin, 'reporting', 'monthly_due_days', $this->works);
    });

    expect($this->current->runAs($this->works, fn (): int => TenantSetting::query()->count()))->toBe(0);
    expect($this->current->runAs($this->works, fn (): int => $this->settings->int('reporting', 'monthly_due_days', 7)))
        ->toBe(14);

    (new SaveSetting)($this->stateAdmin, 'reporting', 'monthly_due_days', '10');

    expect($this->current->runAs($this->works, fn (): int => $this->settings->int('reporting', 'monthly_due_days', 7)))
        ->toBe(10);
});

it('stores a comma-separated ladder as the array the deadline engine reads back', function () {
    (new SaveSetting)($this->stateAdmin, 'reporting', 'reminder_days_before', '10, 5,  2 ');

    expect(Setting::query()->where('key', 'reminder_days_before')->sole()->value)->toBe([10, 5, 2])
        ->and($this->settings->ints('reporting', 'reminder_days_before', [7, 3, 1]))->toBe([10, 5, 2]);

    // …and back into the form control it came from, unchanged in meaning.
    $definition = SettingDefinitions::find('reporting', 'reminder_days_before');

    expect($definition->toForm([10, 5, 2]))->toBe('10, 5, 2');
});

it('stores a boolean as a boolean, whatever the checkbox posted', function () {
    (new SaveSetting)($this->stateAdmin, 'reporting', 'allow_late_submission', false);

    expect($this->settings->bool('reporting', 'allow_late_submission', true))->toBeFalse();

    (new SaveSetting)($this->stateAdmin, 'reporting', 'allow_late_submission', '1');

    expect($this->settings->bool('reporting', 'allow_late_submission', false))->toBeTrue();
});

/* -------------------------------------------------------------------------- */
/* Through the screens */
/* -------------------------------------------------------------------------- */

it('saves the state’s floor from the instance screen and reads it back on the next paint', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(InstanceSettings::class)
        ->call('selectGroup', 'reporting')
        ->set('values.reporting.monthly_due_days', '21')
        ->set('values.reporting.reminder_days_before', '14, 7, 1')
        ->call('saveGroup', 'reporting')
        ->assertHasNoErrors()
        ->assertSet('values.reporting.monthly_due_days', '21');

    expect($this->settings->int('reporting', 'monthly_due_days', 7))->toBe(21)
        ->and($this->settings->ints('reporting', 'reminder_days_before', [7, 3, 1]))->toBe([14, 7, 1]);

    // The value the screen shows on a fresh mount is the value the engine
    // uses — that is what one reader is for.
    $fresh = Livewire::actingAs($this->stateAdmin)->test(InstanceSettings::class);

    expect($fresh->get('values')['reporting']['monthly_due_days'])->toBe('21');
});

it('overrides one setting from the workspace screen and shows what it would inherit', function () {
    (new SaveSetting)($this->stateAdmin, 'reporting', 'monthly_due_days', '14');

    actingOnTenant($this->works);

    $component = Livewire::actingAs($this->worksAdmin)
        ->test(WorkspaceSettings::class)
        ->call('selectGroup', 'reporting');

    $definition = SettingDefinitions::find('reporting', 'monthly_due_days');

    // Before: inherited, and the screen says so.
    expect($component->instance()->isOverridden($definition))->toBeFalse()
        ->and($component->instance()->inheritedDisplay($definition))->toBe('14');

    $component->set('values.reporting.monthly_due_days', '5')
        ->call('saveGroup', 'reporting')
        ->assertHasNoErrors();

    // After: overridden — and the inherited value is STILL shown beside it,
    // so "is this ours or the state's?" stays answerable.
    expect($component->instance()->isOverridden($definition))->toBeTrue()
        ->and($component->instance()->inheritedDisplay($definition))->toBe('14')
        ->and($this->settings->int('reporting', 'monthly_due_days', 7))->toBe(5);

    // And back again: clearing DELETES the row rather than writing the
    // state's number into it, so this setting follows the state next time the
    // state moves.
    $component->call('clearOverride', 'reporting', 'monthly_due_days');

    expect($component->instance()->isOverridden($definition))->toBeFalse()
        ->and(TenantSetting::query()->where('key', 'monthly_due_days')->exists())->toBeFalse()
        ->and($this->settings->int('reporting', 'monthly_due_days', 7))->toBe(14);

    (new SaveSetting)($this->stateAdmin, 'reporting', 'monthly_due_days', '9');
    actingOnTenant($this->works);

    expect($this->settings->int('reporting', 'monthly_due_days', 7))->toBe(9);
});

it('writes an override row for every field of a group that is saved, not only the edited one', function () {
    // Documented here because it is the behaviour, not the intention: the
    // screen's own note warns against "a workspace freezing today's state
    // default under its own name", and a group save does exactly that for
    // every untouched field in the group. The per-row "follow the state
    // again" button is the mitigation, and it is what the test above proves.
    (new SaveSetting)($this->stateAdmin, 'reporting', 'monthly_due_days', '14');

    actingOnTenant($this->works);

    $overridable = collect(SettingDefinitions::inGroup('reporting'))
        ->filter(fn ($definition): bool => $definition->tenantOverridable)
        ->count();

    Livewire::actingAs($this->worksAdmin)
        ->test(WorkspaceSettings::class)
        ->call('selectGroup', 'reporting')
        ->set('values.reporting.monthly_due_days', '5')
        ->call('saveGroup', 'reporting')
        ->assertHasNoErrors();

    expect(TenantSetting::query()->where('group', 'reporting')->count())->toBe($overridable);

    // The untouched ones were frozen at the state's CURRENT value, so they
    // read the same until somebody clears them.
    (new SaveSetting)($this->stateAdmin, 'reporting', 'quarterly_due_days', '30');
    actingOnTenant($this->works);

    expect($this->settings->int('reporting', 'quarterly_due_days', 14))
        ->toBe((int) config('platform.reporting.quarterly_due_days'));

    $this->current->runAs($this->works, function () {
        (new ClearTenantSetting)($this->worksAdmin, 'reporting', 'quarterly_due_days', $this->works);
    });

    actingOnTenant($this->works);

    expect($this->settings->int('reporting', 'quarterly_due_days', 14))->toBe(30);
});

it('shows oversight which rules a workspace has retuned, read-only', function () {
    $this->current->runAs($this->works, function () {
        (new SaveSetting)($this->worksAdmin, 'reporting', 'monthly_due_days', '5', $this->works);
    });

    actingWithoutTenant();

    $component = Livewire::actingAs($this->stateAdmin)
        ->test(TenantSettings::class, ['tenant' => $this->works])
        ->assertOk();

    $overrides = $component->instance()->overrides();

    expect($overrides)->toHaveCount(1)
        ->and($overrides->first()['definition']->id())->toBe('reporting.monthly_due_days')
        ->and($overrides->first()['value'])->toBe(5);

    // A settings value with two write paths is a settings value nobody
    // trusts: the panel is read-only, and the screen carries no writer.
    expect(method_exists($component->instance(), 'saveOverride'))->toBeFalse()
        ->and(method_exists($component->instance(), 'clearOverride'))->toBeFalse();
});

/* -------------------------------------------------------------------------- */
/* Authority */
/* -------------------------------------------------------------------------- */

it('refuses an instance write to an MDA admin, who holds settings.manage only in their own team', function () {
    actingOnTenant($this->works);

    expect(fn () => (new SaveSetting)($this->worksAdmin, 'reporting', 'monthly_due_days', '5'))
        ->toThrow(AuthorizationException::class, 'state-level settings.manage');

    expect(Setting::query()->count())->toBe(0);
});

it('refuses a workspace override written from anywhere but that workspace', function () {
    // No tenant bound — an oversight-surface request.
    actingWithoutTenant();

    expect(fn () => (new SaveSetting)($this->worksAdmin, 'reporting', 'monthly_due_days', '5', $this->works))
        ->toThrow(AuthorizationException::class, 'only be written from that workspace');

    // Bound to the WRONG workspace.
    actingOnTenant($this->health);

    expect(fn () => (new SaveSetting)($this->worksAdmin, 'reporting', 'monthly_due_days', '5', $this->works))
        ->toThrow(AuthorizationException::class, 'only be written from that workspace');

    expect(fn () => (new ClearTenantSetting)($this->worksAdmin, 'reporting', 'monthly_due_days', $this->works))
        ->toThrow(AuthorizationException::class, 'only be cleared from that workspace');
});

it('refuses an override to a workspace role without settings.manage', function () {
    actingOnTenant($this->works);

    expect(fn () => (new SaveSetting)($this->officer, 'reporting', 'monthly_due_days', '5', $this->works))
        ->toThrow(AuthorizationException::class, 'settings.manage in this workspace');
});

it('refuses to override a rule the state keeps for itself', function () {
    actingOnTenant($this->works);

    // Terminology is state-wide by definition — one ministry renaming "MDA"
    // for everybody else is not a workspace preference.
    expect(fn () => (new SaveSetting)($this->worksAdmin, 'terminology', 'tenant', 'Agency', $this->works))
        ->toThrow(InvalidArgumentException::class, 'state-wide setting');

    // …and it is absent from the workspace screen rather than shown disabled.
    $component = Livewire::actingAs($this->worksAdmin)->test(WorkspaceSettings::class);

    expect($component->instance()->groups())->not->toContain('terminology')
        ->and($component->instance()->groups())->toContain('reporting');
});

it('refuses a setting key that is not on the map', function () {
    expect(fn () => (new SaveSetting)($this->stateAdmin, 'reporting', 'invented_key', '5'))
        ->toThrow(InvalidArgumentException::class, 'Unknown setting');

    expect(fn () => (new ClearTenantSetting)($this->worksAdmin, 'reporting', 'invented_key', $this->works))
        ->toThrow(InvalidArgumentException::class, 'Unknown setting');
});

/* -------------------------------------------------------------------------- */
/* Validation and coherence */
/* -------------------------------------------------------------------------- */

it('re-validates a value server-side against the definition’s own bounds', function (
    string $group,
    string $key,
    mixed $input,
) {
    expect(fn () => (new SaveSetting)($this->stateAdmin, $group, $key, $input))
        ->toThrow(ValidationException::class);

    expect(Setting::query()->where('group', $group)->where('key', $key)->exists())->toBeFalse();
})->with([
    'a due day below the floor' => ['reporting', 'monthly_due_days', '0'],
    'a due day beyond the ceiling' => ['reporting', 'monthly_due_days', '999'],
    'a non-numeric due day' => ['reporting', 'monthly_due_days', 'soon'],
    'a currency nobody offered' => ['instance', 'currency', 'XYZ'],
    'a brand colour that is not a hex' => ['branding', 'primary_color', 'rebeccapurple'],
    'an instance name of nothing at all' => ['instance', 'name', ''],
]);

it('refuses indicator bands the wrong way round, in either order of editing', function () {
    (new SaveSetting)($this->stateAdmin, 'indicators', 'on_track_percent', '90');

    // Amber must sit below green; bands inverted would colour every traffic
    // light on every dashboard wrongly and look like a data problem.
    expect(fn () => (new SaveSetting)($this->stateAdmin, 'indicators', 'at_risk_percent', '95'))
        ->toThrow(ValidationException::class, 'must sit below the on-track band');

    expect(fn () => (new SaveSetting)($this->stateAdmin, 'indicators', 'on_track_percent', '50'))
        ->toThrow(ValidationException::class, 'must sit below the on-track band');

    (new SaveSetting)($this->stateAdmin, 'indicators', 'at_risk_percent', '70');

    expect($this->settings->int('indicators', 'at_risk_percent', 70))->toBe(70);
});

it('shows a refused value on the field it is about, not as a 500', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(InstanceSettings::class)
        ->call('selectGroup', 'reporting')
        ->set('values.reporting.monthly_due_days', '0')
        ->call('saveGroup', 'reporting')
        ->assertHasErrors('values.reporting.monthly_due_days');

    expect(Setting::query()->where('key', 'monthly_due_days')->exists())->toBeFalse();
});

it('ignores a group nobody offered on either settings screen', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(InstanceSettings::class)
        ->call('selectGroup', 'nonsense')
        ->assertSet('group', 'instance');

    actingOnTenant($this->works);

    $tenantGroups = SettingDefinitions::tenantGroups();

    Livewire::actingAs($this->worksAdmin)
        ->test(WorkspaceSettings::class)
        ->call('selectGroup', 'terminology')
        ->assertSet('group', $tenantGroups[0]);
});

/* -------------------------------------------------------------------------- */
/* Audit */
/* -------------------------------------------------------------------------- */

it('records every settings change with its before, its after and whose say-so', function () {
    (new SaveSetting)($this->stateAdmin, 'reporting', 'monthly_due_days', '14');
    (new SaveSetting)($this->stateAdmin, 'reporting', 'monthly_due_days', '21');

    $entries = Activity::query()->where('description', 'setting.instance_updated')->orderBy('id')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->causer_id)->toBe($this->stateAdmin->id)
        ->and($entries[0]->properties->get('attributes')['value'])->toBe(14)
        ->and($entries[1]->properties->get('old')['value'])->toBe(14)
        ->and($entries[1]->properties->get('attributes')['value'])->toBe(21)
        ->and($entries[1]->properties->get('scope'))->toBe('instance');

    $this->current->runAs($this->works, function () {
        (new SaveSetting)($this->worksAdmin, 'reporting', 'monthly_due_days', '5', $this->works);
        (new ClearTenantSetting)($this->worksAdmin, 'reporting', 'monthly_due_days', $this->works);
    });

    $override = Activity::query()->where('description', 'setting.override_updated')->sole();
    $cleared = Activity::query()->where('description', 'setting.override_cleared')->sole();

    expect($override->properties->get('scope'))->toBe('works')
        ->and($override->properties->get('attributes')['value'])->toBe(5)
        // The act survives the row: the override is deleted, the record of
        // deleting it is not.
        ->and($cleared->properties->get('old')['value'])->toBe(5)
        ->and($cleared->properties->get('attributes')['value'])->toBeNull();
});

it('writes no audit row for clearing an override that was never there', function () {
    $this->current->runAs($this->works, function () {
        (new ClearTenantSetting)($this->worksAdmin, 'reporting', 'monthly_due_days', $this->works);
    });

    expect(Activity::query()->where('description', 'setting.override_cleared')->count())->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* Tenancy of the override table */
/* -------------------------------------------------------------------------- */

it('keeps one workspace’s overrides invisible to the next', function () {
    $this->current->runAs($this->works, function () {
        (new SaveSetting)($this->worksAdmin, 'inspections', 'geofence_metres', '500', $this->works);
    });

    $this->current->runAs($this->health, function () {
        (new SaveSetting)($this->healthAdmin, 'inspections', 'geofence_metres', '5000', $this->health);
    });

    expect($this->current->runAs($this->works, fn (): int => TenantSetting::query()->count()))->toBe(1)
        ->and($this->current->runAs($this->works, fn (): int => $this->settings->int('inspections', 'geofence_metres', 2000)))->toBe(500)
        ->and($this->current->runAs($this->health, fn (): int => $this->settings->int('inspections', 'geofence_metres', 2000)))->toBe(5000);

    // The workspace screen shows only this workspace's own rows.
    actingOnTenant($this->works);

    $component = Livewire::actingAs($this->worksAdmin)
        ->test(WorkspaceSettings::class)
        ->call('selectGroup', 'inspections');

    expect($component->get('values')['inspections']['geofence_metres'])->toBe('500');
});
