<?php

/**
 * Platform administration (plan module map §1 and §11): the workspace
 * register, the onboarding wizard, one entity's record, instance settings, the
 * audit trail and the notification centre — plus the workspace-side settings
 * screens on the tenant surface.
 *
 * Every screen is asserted BOTH ways: an authorized request that really
 * renders the record over HTTP, and the denial. A suite of denials proves only
 * that nothing works, and that has bitten this project twice.
 *
 * The authorization matrix here is the one that matters most on the platform:
 * `tenants.view` opens the register, `tenants.manage` works the suspend
 * switch, and an MDA admin — who legitimately holds `settings.manage` and
 * `users.manage` inside their own workspace — holds NOTHING on this surface.
 */

use App\Enums\Role;
use App\Livewire\Oversight\Audit\AuditLog;
use App\Livewire\Oversight\Notifications\NotificationCentre as OversightNotificationCentre;
use App\Livewire\Oversight\Settings\InstanceSettings;
use App\Livewire\Oversight\Tenancy\TenantDirectory;
use App\Livewire\Oversight\Tenancy\TenantOnboarding;
use App\Livewire\Oversight\Tenancy\TenantSettings;
use App\Livewire\Tenant\Notifications\NotificationCentre as TenantNotificationCentre;
use App\Livewire\Tenant\Settings\NotificationPreferences;
use App\Livewire\Tenant\Settings\WorkspaceSettings;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    $this->current->runAs($this->works, fn () => Project::factory()->ongoing()->count(2)->create());

    actingWithoutTenant();

    $this->superAdmin = userWithRole(Role::SuperAdmin);
    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->execViewer = userWithRole(Role::ExecutiveViewer);
    $this->qualityReviewer = userWithRole(Role::DataQualityReviewer);

    // 2FA is mandatory for the state roles; stamping the anchor puts them
    // inside the enrolment grace window rather than at the setup redirect.
    foreach ([$this->superAdmin, $this->stateAdmin, $this->execViewer, $this->qualityReviewer] as $user) {
        $user->forceFill(['two_factor_required_at' => now()])->save();
    }

    $this->mdaAdmin = memberOf(User::factory()->create(['name' => 'Amina Bello']), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(['name' => 'Chidi Okafor']), $this->works, Role::MeOfficer);

    actingWithoutTenant();
});

/* -------------------------------------------------------------------------- */
/* Oversight — authorized renders over real HTTP */
/* -------------------------------------------------------------------------- */

it('renders the workspace register with every entity, its subdomain and its load', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/entities'))
        ->assertOk()
        ->assertSee('Ministry of Works')
        ->assertSee('Ministry of Health')
        // route(), not a hand-built string: a wrong binding key 404s silently
        // in production and would pass an assertSee on the slug alone.
        ->assertSee(route('oversight.entities.show', $this->works), escape: false)
        ->assertSee(route('oversight.entities.show', $this->health), escape: false);
});

it('renders the onboarding wizard on its first step', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/entities/create'))
        ->assertOk()
        ->assertSee(__('Entity type'))
        ->assertSee(__('Subdomain'));
});

it('renders one entity’s record, bound by slug', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/entities/works'))
        ->assertOk()
        ->assertSee('Ministry of Works')
        ->assertSee('works.'.config('platform.domain'));
});

it('404s an entity record asked for by numeric id rather than slug', function () {
    // The binding is {tenant:slug} — the slug IS the workspace's public
    // identity here, and passing an id to a slug binding is a 404 this
    // project has shipped once already.
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/entities/'.$this->works->id))
        ->assertNotFound();
});

it('renders instance settings with the state’s own policy floor', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/settings'))
        ->assertOk()
        ->assertSee(__('Instance identity'))
        ->assertSee(__('Instance name'));
});

it('renders the state audit log', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/audit'))
        ->assertOk();
});

it('renders the state notification centre', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/notifications'))
        ->assertOk()
        ->assertSee(__('Everything you have been told across every entity, newest first.'));
});

/* -------------------------------------------------------------------------- */
/* Tenant surface — authorized renders over real HTTP */
/* -------------------------------------------------------------------------- */

it('renders workspace settings for the MDA admin who owns them', function () {
    actingOnTenant($this->works);

    $this->actingAs($this->mdaAdmin)
        ->get(tenantUrl($this->works, '/settings'))
        ->assertOk()
        ->assertSee(__('Reporting deadlines'))
        // Every row states what it would inherit, so "is this ours or the
        // state's?" is answerable on the screen.
        ->assertSee(__('Monthly return due (days after month end)'));
});

it('renders a person’s own notification preferences', function () {
    actingOnTenant($this->works);

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/settings/notifications'))
        ->assertOk()
        ->assertSee(__('Progress reporting'))
        // Non-mutable categories are SHOWN, not hidden: a person is entitled
        // to know what the platform will always send them.
        ->assertSee(__('Account & access'));
});

it('renders the workspace notification centre', function () {
    actingOnTenant($this->works);

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/notifications'))
        ->assertOk()
        ->assertSee('Ministry of Works');
});

/* -------------------------------------------------------------------------- */
/* Authorization matrix */
/* -------------------------------------------------------------------------- */

it('opens the register to an executive viewer, who holds tenants.view', function () {
    $this->actingAs($this->execViewer)
        ->get(oversightUrl('/entities'))
        ->assertOk()
        ->assertSee('Ministry of Works');
});

it('refuses the register to a data-quality reviewer, who holds no tenants.view', function () {
    // Both roles pass the surface's role middleware; only one of them holds
    // the permission, and that is the line that decides.
    $this->actingAs($this->qualityReviewer)
        ->get(oversightUrl('/entities'))
        ->assertForbidden();

    Livewire::actingAs($this->qualityReviewer)->test(TenantDirectory::class)->assertForbidden();
});

it('never lets a read-only oversight role provision or close a workspace', function (string $role) {
    $user = $role === 'executive' ? $this->execViewer : $this->qualityReviewer;

    // Neither role holds tenants.manage, so the wizard is refused outright —
    // on the route AND at the Livewire endpoint route middleware does not
    // protect.
    $this->actingAs($user)->get(oversightUrl('/entities/create'))->assertForbidden();
    Livewire::actingAs($user)->test(TenantOnboarding::class)->assertForbidden();

    expect(Tenant::query()->whereKey($this->works->id)->sole()->is_active)->toBeTrue();
})->with(['executive', 'quality reviewer']);

it('refuses the suspend switch to the executive viewer who can read the register', function () {
    // ExecutiveViewer DOES hold tenants.view, so both screens mount for them.
    // What must not work is the switch, on either of the two screens that
    // offer it — and it is checked again inside each mutating method.
    Livewire::actingAs($this->execViewer)
        ->test(TenantDirectory::class)
        ->assertOk()
        ->call('confirmSetActive', $this->works->ulid, false)
        ->assertForbidden();

    Livewire::actingAs($this->execViewer)
        ->test(TenantSettings::class, ['tenant' => $this->works])
        ->assertOk()
        ->call('confirmSetActive')
        ->assertForbidden();

    // Nor may they edit the record itself.
    Livewire::actingAs($this->execViewer)
        ->test(TenantSettings::class, ['tenant' => $this->works])
        ->set('name', 'Ministry of Something Else')
        ->call('save')
        ->assertForbidden();

    $unchanged = Tenant::query()->whereKey($this->works->id)->sole();

    expect($unchanged->is_active)->toBeTrue()
        ->and($unchanged->name)->toBe('Ministry of Works');
});

it('refuses even the entity record to a data-quality reviewer', function () {
    $this->actingAs($this->qualityReviewer)
        ->get(oversightUrl('/entities/works'))
        ->assertForbidden();

    Livewire::actingAs($this->qualityReviewer)
        ->test(TenantSettings::class, ['tenant' => $this->works])
        ->assertForbidden();
});

it('shows an executive viewer the register without the controls they cannot use', function () {
    $component = Livewire::actingAs($this->execViewer)->test(TenantDirectory::class)->assertOk();

    expect($component->instance()->actorCanManage())->toBeFalse();

    $component->assertDontSeeHtml('wire:click="confirmSetActive(');
});

it('refuses every oversight administration screen to an MDA admin', function (string $path) {
    // An MDA admin holds settings.manage and users.manage INSIDE their own
    // workspace and holds nothing at all in the global team, so the oversight
    // role gate stops them before any policy is consulted.
    $this->actingAs($this->mdaAdmin)
        ->get(oversightUrl($path))
        ->assertForbidden();
})->with([
    '/entities',
    '/entities/create',
    '/entities/works',
    '/settings',
    '/audit',
]);

it('refuses the oversight administration components to an MDA admin, endpoint by endpoint', function () {
    actingWithoutTenant();

    Livewire::actingAs($this->mdaAdmin)->test(TenantDirectory::class)->assertForbidden();
    Livewire::actingAs($this->mdaAdmin)->test(TenantOnboarding::class)->assertForbidden();
    Livewire::actingAs($this->mdaAdmin)->test(TenantSettings::class, ['tenant' => $this->works])->assertForbidden();
    Livewire::actingAs($this->mdaAdmin)->test(InstanceSettings::class)->assertForbidden();
    Livewire::actingAs($this->mdaAdmin)->test(AuditLog::class)->assertForbidden();
});

it('refuses instance settings to an MDA admin even from inside their own workspace', function () {
    // settings.manage in a tenant team must never reach the state's floor:
    // SettingPolicy::manage reads the GLOBAL team only.
    actingOnTenant($this->works);

    expect($this->mdaAdmin->can('settings.manage'))->toBeTrue()
        ->and($this->mdaAdmin->can('manage', Setting::class))->toBeFalse();

    Livewire::actingAs($this->mdaAdmin)->test(InstanceSettings::class)->assertForbidden();
});

it('refuses workspace settings to a role without settings.manage in the workspace', function () {
    actingOnTenant($this->works);

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/settings'))
        ->assertForbidden();

    Livewire::actingAs($this->officer)->test(WorkspaceSettings::class)->assertForbidden();
});

it('lets the state notification centre through to any oversight role — it is their own inbox', function () {
    foreach ([$this->stateAdmin, $this->execViewer, $this->qualityReviewer] as $user) {
        $this->actingAs($user)->get(oversightUrl('/notifications'))->assertOk();
    }
});

it('refuses every administration screen to a guest', function () {
    foreach (['/entities', '/entities/create', '/settings', '/audit', '/notifications'] as $path) {
        $this->get(oversightUrl($path))->assertRedirect();
    }

    foreach (['/settings', '/settings/notifications', '/notifications'] as $path) {
        $this->get(tenantUrl($this->works, $path))->assertRedirect();
    }
});

/* -------------------------------------------------------------------------- */
/* Tenancy of the workspace-side screens */
/* -------------------------------------------------------------------------- */

it('refuses a workspace’s settings to somebody who is not a member of it', function () {
    actingOnTenant($this->health);

    $this->actingAs($this->mdaAdmin)
        ->get(tenantUrl($this->health, '/settings'))
        ->assertForbidden();
});

it('keeps every tenant-surface administration component out of an unbound context', function () {
    // The notification screens are personal, so they render anywhere; the
    // settings screen is a WORKSPACE override screen and must not.
    actingWithoutTenant();
    URL::defaults(['tenant' => $this->works->slug]);

    Livewire::actingAs($this->mdaAdmin)->test(WorkspaceSettings::class)->assertForbidden();

    Livewire::actingAs($this->officer)->test(NotificationPreferences::class)->assertOk();
    Livewire::actingAs($this->officer)->test(TenantNotificationCentre::class)->assertOk();
    Livewire::actingAs($this->stateAdmin)->test(OversightNotificationCentre::class)->assertOk();
});
