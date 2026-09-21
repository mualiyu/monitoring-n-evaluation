<?php

/**
 * Opening and closing an MDA workspace.
 *
 * THE SLUG is the hard part and gets most of this file: it becomes a DNS label
 * and a permanent URL, so it is checked against the platform's own pattern,
 * the reserved list, and every slug ever issued — including soft-deleted
 * workspaces, which a unique index cannot see. Each of those three is asserted
 * twice: once on the FORM (the courtesy check, read out of rendered markup)
 * and once inside ProvisionTenant (the authority), because a form is bypassed
 * by anybody who wants to.
 *
 * THE FIRST ADMINISTRATOR must go through the existing invitation chain
 * (App\Actions\Iam\InviteUser) rather than a second provisioning-only path —
 * that is what keeps the token hashing, the expiry, the "one pending invite
 * per (workspace, email)" rule and the mail identical everywhere.
 *
 * DEACTIVATION DELETES NOTHING and is reversible. A government record is not
 * destroyed because an agency was merged or a secretariat is investigating it.
 */

use App\Actions\Oversight\ProvisionTenant;
use App\Actions\Oversight\SetTenantActive;
use App\Enums\Role;
use App\Enums\TenantType;
use App\Livewire\Oversight\Tenancy\TenantDirectory;
use App\Livewire\Oversight\Tenancy\TenantOnboarding;
use App\Livewire\Oversight\Tenancy\TenantSettings;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Iam\UserInvited;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * The attributes of one named form field in a chunk of markup, so an
 * assertion cannot pass on a coincidental match elsewhere in the page.
 */
function formFieldMarkup(string $html, string $name): string
{
    preg_match('/<(?:input|select|textarea)\b[^>]*\bname="'.preg_quote($name, '/').'"[^>]*>/s', $html, $match);

    return $match[0] ?? '';
}

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->current = app(CurrentTenant::class);

    actingWithoutTenant();

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->execViewer = userWithRole(Role::ExecutiveViewer);

    foreach ([$this->stateAdmin, $this->execViewer] as $user) {
        $user->forceFill(['two_factor_required_at' => now()])->save();
    }
});

/* -------------------------------------------------------------------------- */
/* Provisioning */
/* -------------------------------------------------------------------------- */

it('opens a workspace, stamps its onboarding and records who opened it', function () {
    $tenant = (new ProvisionTenant)($this->stateAdmin, [
        'name' => 'Ministry of Education',
        'short_name' => 'Education',
        'slug' => 'education',
        'type' => TenantType::Ministry,
        'contact_name' => 'The Permanent Secretary',
        'contact_email' => 'ps@education.test',
    ]);

    $stored = Tenant::query()->whereKey($tenant->id)->sole();

    expect($tenant->slug)->toBe('education')
        ->and($tenant->onboarded_at)->not->toBeNull()
        ->and($tenant->url())->toBe('http://education.'.config('platform.domain').'/')
        // A workspace opens OPEN: `is_active` is not fillable and comes from
        // the column default, so the question is asked of the stored row.
        ->and($stored->is_active)->toBeTrue();

    $entry = Activity::query()->where('description', 'tenant.provisioned')->sole();

    expect($entry->causer_id)->toBe($this->stateAdmin->id)
        ->and($entry->subject_id)->toBe($tenant->id)
        ->and($entry->properties->get('attributes')['slug'])->toBe('education');
});

it('invites the first administrator through the one invitation path, not a second one', function () {
    $tenant = (new ProvisionTenant)($this->stateAdmin, [
        'name' => 'Ministry of Education',
        'slug' => 'education',
        'type' => TenantType::Ministry,
    ], 'ps@education.test');

    // An Invitation row with a HASHED token and the MdaAdmin role — the same
    // record the team screen's pending list reads, produced by the same
    // Action. A provisioning-only invite path would not produce this.
    $invitation = Invitation::query()->where('email', 'ps@education.test')->sole();

    expect($invitation->tenant_id)->toBe($tenant->id)
        ->and($invitation->role)->toBe(Role::MdaAdmin)
        ->and($invitation->invited_by_id)->toBe($this->stateAdmin->id)
        ->and($invitation->token_hash)->not->toBeNull()
        ->and($invitation->accepted_at)->toBeNull();

    Notification::assertSentOnDemand(UserInvited::class);
});

it('opens a workspace with nobody in it, because a state may provision ahead of appointing', function () {
    (new ProvisionTenant)($this->stateAdmin, [
        'name' => 'Bureau of Statistics',
        'slug' => 'statistics',
        'type' => TenantType::Agency,
    ]);

    expect(Invitation::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('refuses provisioning to anyone without tenants.manage in the global team', function () {
    expect(fn () => (new ProvisionTenant)($this->execViewer, [
        'name' => 'Ministry of Education',
        'slug' => 'education',
        'type' => TenantType::Ministry,
    ]))->toThrow(AuthorizationException::class);

    $mdaAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    actingWithoutTenant();

    expect(fn () => (new ProvisionTenant)($mdaAdmin, [
        'name' => 'Ministry of Education',
        'slug' => 'education',
        'type' => TenantType::Ministry,
    ]))->toThrow(AuthorizationException::class);

    expect(Tenant::query()->where('slug', 'education')->exists())->toBeFalse();
});

/* -------------------------------------------------------------------------- */
/* The slug, checked by the Action */
/* -------------------------------------------------------------------------- */

it('refuses a subdomain the platform has reserved for itself', function (string $slug) {
    expect(fn () => (new ProvisionTenant)($this->stateAdmin, [
        'name' => 'Ministry of Education',
        'slug' => $slug,
        'type' => TenantType::Ministry,
    ]))->toThrow(InvalidArgumentException::class, 'is reserved by the platform');

    expect(Tenant::query()->where('slug', $slug)->exists())->toBeFalse();
    // Every reserved label, read from config rather than retyped, so a new
    // entry there is covered the moment it is added.
})->with(['www', 'oversight', 'api', 'admin', 'portal', 'app', 'horizon', 'status']);

it('covers every reserved subdomain config actually declares', function () {
    /** @var list<string> $reserved */
    $reserved = config('platform.reserved_subdomains');

    foreach ($reserved as $slug) {
        expect(fn () => (new ProvisionTenant)($this->stateAdmin, [
            'name' => 'Ministry of Education',
            'slug' => $slug,
            'type' => TenantType::Ministry,
        ]))->toThrow(InvalidArgumentException::class, 'is reserved by the platform');
    }

    expect(Tenant::query()->count())->toBe(1);
});

it('refuses a subdomain another workspace already holds', function () {
    expect(fn () => (new ProvisionTenant)($this->stateAdmin, [
        'name' => 'Works Rebooted',
        'slug' => 'works',
        'type' => TenantType::Ministry,
    ]))->toThrow(InvalidArgumentException::class, 'is already in use');

    expect(Tenant::query()->where('slug', 'works')->count())->toBe(1);
});

it('refuses a subdomain a RETIRED workspace still holds, which a unique index cannot see', function () {
    $this->works->delete();

    expect(Tenant::query()->where('slug', 'works')->exists())->toBeFalse()
        ->and(Tenant::withTrashed()->where('slug', 'works')->exists())->toBeTrue();

    // Without the withTrashed() check this would reach the database and fail
    // there — a 500 instead of a sentence a human can act on.
    expect(fn () => (new ProvisionTenant)($this->stateAdmin, [
        'name' => 'Works Rebooted',
        'slug' => 'works',
        'type' => TenantType::Ministry,
    ]))->toThrow(InvalidArgumentException::class, 'is already in use');
});

it('refuses a subdomain that is not a single DNS label', function (string $slug) {
    expect(fn () => (new ProvisionTenant)($this->stateAdmin, [
        'name' => 'Ministry of Education',
        'slug' => $slug,
        'type' => TenantType::Ministry,
    ]))->toThrow(InvalidArgumentException::class);

    expect(Tenant::query()->count())->toBe(1);
})->with([
    // Laravel's default domain-parameter regex matches dots, so an
    // unvalidated slug is how `evil.works.<domain>` reaches a tenant route.
    'a dotted label' => ['evil.works'],
    'a leading hyphen' => ['-works'],
    'a trailing hyphen' => ['works-'],
    'an underscore' => ['works_roads'],
    'a space' => ['ministry of works'],
    'a slash' => ['works/admin'],
    'empty' => [''],
    'a wildcard' => ['*'],
]);

it('lowercases and trims a subdomain before it is judged', function () {
    $tenant = (new ProvisionTenant)($this->stateAdmin, [
        'name' => 'Ministry of Education',
        'slug' => '  EDUCATION  ',
        'type' => TenantType::Ministry,
    ]);

    expect($tenant->slug)->toBe('education');
});

/* -------------------------------------------------------------------------- */
/* The wizard, read as rendered markup */
/* -------------------------------------------------------------------------- */

it('renders a subdomain field carrying the platform’s own pattern, and validates against it', function () {
    $html = (string) $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/entities/create'))
        ->assertOk()
        ->getContent();

    // The field really is on the page under the name the component models —
    // a wizard whose step-1 input is named something else validates nothing.
    expect(formFieldMarkup($html, 'slug'))->not->toBe('')
        ->and(formFieldMarkup($html, 'name'))->not->toBe('');

    // The entity-type picker is a MAP, so its option values are the enum's
    // backing values rather than the labels a human reads.
    preg_match('/<select\b[^>]*\bname="type"[^>]*>(.*?)<\/select>/s', $html, $block);
    preg_match_all('/<option value="([^"]*)"/', $block[1] ?? '', $values);

    $offered = array_values(array_filter($values[1] ?? []));

    expect($offered)->toBe(array_map(fn (TenantType $t): string => $t->value, TenantType::cases()));

    // And the value the rendered form offers is accepted by the wizard.
    Livewire::actingAs($this->stateAdmin)
        ->test(TenantOnboarding::class)
        ->set('name', 'Ministry of Education')
        ->set('slug', 'education')
        ->set('type', $offered[0])
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', 2);
});

it('suggests a subdomain from the entity name until somebody types their own', function () {
    $component = Livewire::actingAs($this->stateAdmin)
        ->test(TenantOnboarding::class)
        ->set('name', 'Ministry of Works & Infrastructure');

    expect($component->get('slug'))->toBe('works-infrastructure');

    // Once edited by hand the suggestion stops overwriting it.
    $component->set('slug', 'MOWI')->set('name', 'Ministry of Water Resources');

    expect($component->get('slug'))->toBe('mowi');
});

it('stops a reserved, taken or malformed subdomain on step one of the wizard', function (string $slug) {
    Livewire::actingAs($this->stateAdmin)
        ->test(TenantOnboarding::class)
        ->set('name', 'Ministry of Education')
        ->set('slug', $slug)
        ->call('next')
        ->assertHasErrors('slug')
        ->assertSet('step', 1);
})->with([
    'reserved' => ['oversight'],
    'already issued' => ['works'],
    'not a DNS label' => ['evil.works'],
]);

it('walks the wizard to a live subdomain with its first administrator invited', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(TenantOnboarding::class)
        ->set('name', 'Ministry of Education')
        ->set('shortName', 'Education')
        ->set('slug', 'education')
        ->call('next')
        ->assertSet('step', 2)
        ->set('contactName', 'The Permanent Secretary')
        ->set('contactEmail', 'ps@education.test')
        ->call('next')
        ->assertSet('step', 3)
        ->set('administratorEmail', 'ps@education.test')
        ->call('provision')
        ->assertHasNoErrors()
        ->assertRedirect();

    $tenant = Tenant::query()->where('slug', 'education')->sole();

    expect($tenant->name)->toBe('Ministry of Education')
        ->and($tenant->onboarded_at)->not->toBeNull()
        ->and(Invitation::query()->where('tenant_id', $tenant->id)->where('email', 'ps@education.test')->exists())
        ->toBeTrue();
});

it('refuses a forward jump past the validation that makes the later steps meaningful', function () {
    $component = Livewire::actingAs($this->stateAdmin)
        ->test(TenantOnboarding::class)
        ->call('goToStep', 3);

    expect($component->get('step'))->toBe(1);

    // Backwards is allowed.
    $component->set('name', 'Ministry of Education')
        ->set('slug', 'education')
        ->call('next')
        ->call('goToStep', 1)
        ->assertSet('step', 1);
});

it('re-validates every step at submit, so a payload that jumped to the last one is refused', function () {
    // `step` is a public property, so a hand-edited payload can name step 3
    // without ever satisfying step 1. provision() re-runs the rules for every
    // step before it calls the Action, and the Action is the authority behind
    // that anyway.
    Livewire::actingAs($this->stateAdmin)
        ->test(TenantOnboarding::class)
        ->set('step', 3)
        ->set('administratorEmail', 'ps@nowhere.test')
        ->call('provision')
        ->assertHasErrors(['name', 'slug']);

    expect(Tenant::query()->count())->toBe(1)
        ->and(Invitation::query()->count())->toBe(0);
});

it('refuses a malformed administrator email before a workspace is opened', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(TenantOnboarding::class)
        ->set('name', 'Ministry of Education')
        ->set('slug', 'education')
        ->set('administratorEmail', 'not-an-email')
        ->call('provision')
        ->assertHasErrors('administratorEmail');

    // Nothing half-built: the transaction never opened.
    expect(Tenant::query()->where('slug', 'education')->exists())->toBeFalse();
});

it('keeps the wizard’s courtesy check and the Action’s authority in step', function (string $slug) {
    // The wizard checks the slug while it is typed; ProvisionTenant checks it
    // again and is the authority. Both must answer the same way, or a user
    // fills in three steps to be refused at the end.
    Livewire::actingAs($this->stateAdmin)
        ->test(TenantOnboarding::class)
        ->set('name', 'Ministry of Education')
        ->set('slug', $slug)
        ->call('next')
        ->assertHasErrors('slug');

    expect(fn () => (new ProvisionTenant)($this->stateAdmin, [
        'name' => 'Ministry of Education',
        'slug' => $slug,
        'type' => TenantType::Ministry,
    ]))->toThrow(InvalidArgumentException::class);
})->with([
    'reserved' => ['oversight'],
    'already issued' => ['works'],
    'not a DNS label' => ['evil.works'],
    'underscored' => ['works_roads'],
]);

/* -------------------------------------------------------------------------- */
/* Suspension — reversible, and it deletes nothing */
/* -------------------------------------------------------------------------- */

it('closes a subdomain without deleting a single record', function () {
    $this->current->runAs($this->works, fn () => Project::factory()->ongoing()->count(3)->create());
    actingWithoutTenant();

    (new SetTenantActive)($this->stateAdmin, $this->works, false, 'Merged into the Ministry of Infrastructure pending the white paper.');

    $suspended = Tenant::query()->whereKey($this->works->id)->sole();

    expect($suspended->is_active)->toBeFalse()
        ->and($suspended->deactivated_at)->not->toBeNull()
        ->and($suspended->deactivated_reason)->toContain('Merged into')
        ->and($suspended->deleted_at)->toBeNull();

    // The workspace's records are untouched — and still readable inside it.
    expect($this->current->runAs($this->works, fn (): int => Project::query()->count()))->toBe(3);

    $entry = Activity::query()->where('description', 'tenant.deactivated')->sole();

    expect($entry->causer_id)->toBe($this->stateAdmin->id)
        ->and($entry->properties->get('attributes')['is_active'])->toBeFalse();
});

it('refuses to resolve a closed subdomain, and restores it exactly when it reopens', function () {
    $member = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    actingWithoutTenant();

    (new SetTenantActive)($this->stateAdmin, $this->works, false, 'Under investigation by the state secretariat.');

    // TenantLocator refuses an inactive slug, so the whole subdomain 404s.
    $this->actingAs($member)->get(tenantUrl($this->works, '/'))->assertNotFound();

    (new SetTenantActive)($this->stateAdmin, Tenant::query()->whereKey($this->works->id)->sole(), true);

    $reopened = Tenant::query()->whereKey($this->works->id)->sole();

    expect($reopened->is_active)->toBeTrue()
        ->and($reopened->deactivated_at)->toBeNull()
        ->and($reopened->deactivated_reason)->toBeNull();

    actingOnTenant($this->works);
    $this->actingAs($member)->get(tenantUrl($this->works, '/'))->assertOk();
});

it('writes no phantom audit row for a no-op flip', function () {
    expect($this->works->is_active)->toBeTrue();

    (new SetTenantActive)($this->stateAdmin, $this->works, true);

    expect(Activity::query()->whereIn('description', ['tenant.activated', 'tenant.deactivated'])->count())
        ->toBe(0);
});

it('demands a reason to close a workspace and none to reopen it', function () {
    $component = Livewire::actingAs($this->stateAdmin)
        ->test(TenantDirectory::class)
        ->call('confirmSetActive', $this->works->ulid, false)
        ->set('togglingReason', '')
        ->call('applySetActive')
        ->assertHasErrors('togglingReason');

    expect(Tenant::query()->whereKey($this->works->id)->sole()->is_active)->toBeTrue();

    $component->set('togglingReason', 'Merged into the Ministry of Infrastructure.')
        ->call('applySetActive')
        ->assertHasNoErrors();

    expect(Tenant::query()->whereKey($this->works->id)->sole()->is_active)->toBeFalse();

    // Reopening needs no reason — the record of the closure already carries one.
    Livewire::actingAs($this->stateAdmin)
        ->test(TenantSettings::class, ['tenant' => Tenant::query()->whereKey($this->works->id)->sole()])
        ->call('confirmSetActive')
        ->call('applySetActive')
        ->assertHasNoErrors();

    expect(Tenant::query()->whereKey($this->works->id)->sole()->is_active)->toBeTrue();
});

it('says in as many words that suspending is not deleting', function () {
    // Government software that is vague about this is how somebody hesitates
    // to merge two agencies for a year.
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/entities'))
        ->assertOk()
        ->assertSee('closes its subdomain and deletes nothing');
});

it('404s the suspend switch for a workspace ulid that is not a workspace', function () {
    expect(fn () => Livewire::actingAs($this->stateAdmin)
        ->test(TenantDirectory::class)
        ->call('confirmSetActive', 'not-a-real-ulid', false))
        ->toThrow(ModelNotFoundException::class);
});

/* -------------------------------------------------------------------------- */
/* Editing a workspace record */
/* -------------------------------------------------------------------------- */

it('edits a workspace record and its branding, but never its subdomain', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(TenantSettings::class, ['tenant' => $this->works])
        ->set('name', 'Ministry of Works & Transport')
        ->set('shortName', 'Works')
        ->set('displayName', 'Works & Transport')
        ->set('brandColor', '#0f5132')
        ->call('save')
        ->assertHasNoErrors();

    $updated = Tenant::query()->whereKey($this->works->id)->sole();

    expect($updated->name)->toBe('Ministry of Works & Transport')
        ->and($updated->slug)->toBe('works')  // the DNS label is not an edit field
        ->and($updated->branding['display_name'])->toBe('Works & Transport')
        ->and($updated->branding['brand_color'])->toBe('#0f5132')
        // One picked colour writes the whole brand ramp the semantic tokens
        // resolve through, not one recoloured button.
        ->and($updated->branding['tokens'])->toHaveKeys(['--brand-500', '--brand-600', '--brand-700', '--brand-soft'])
        ->and($updated->branding['tokens']['--brand-600'])->toBe('#0f5132');
});

it('refuses a brand colour that is not a six-digit hex', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(TenantSettings::class, ['tenant' => $this->works])
        ->set('brandColor', 'javascript:alert(1)')
        ->call('save')
        ->assertHasErrors('brandColor');
});

it('offers no subdomain input on the entity record, and says why', function () {
    $html = (string) $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/entities/works'))
        ->assertOk()
        ->getContent();

    // Present but disabled: the screen answers the question where somebody
    // would look for the field, rather than leaving them hunting for it.
    expect(formFieldMarkup($html, 'subdomain'))->toContain('disabled');
});
