<?php

/**
 * The authorization matrix for this module: one test per role for what it can
 * and cannot do.
 *
 * The line that matters most is between `commencement.issue` (SuperAdmin +
 * MDA staff — serving a notice is administrative) and `certificates.issue`
 * (SuperAdmin + MdaAdmin only — signing a completion certificate releases
 * public works and unlocks payment). An M&E officer who could certify would
 * make `projects.certify` decorative.
 */

use App\Actions\Oversight\ListCertificatesAcrossTenants;
use App\Actions\Oversight\SummariseCertificatesAcrossTenants;
use App\Enums\ProjectRole;
use App\Enums\Role;
use App\Livewire\Oversight\Lifecycle\CertificateRegister as OversightRegister;
use App\Livewire\Tenant\Lifecycle\CertificateRegister;
use App\Livewire\Tenant\Lifecycle\CertifyProject;
use App\Livewire\Tenant\Lifecycle\ProjectCommencement;
use App\Models\Certificate;
use App\Models\CommencementNotice;
use App\Models\Contract;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('documents');
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->project = Project::factory()->completed()->create(['title' => 'Township Road Rehabilitation']);
    $this->contract = Contract::factory()->forProject($this->project)->create(['commencement_date' => null]);
});

/* -------------------------------------------------------------------------- */
/* Tenant surface */
/* -------------------------------------------------------------------------- */

it('lets an entity administrator certify', function () {
    $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    Livewire::actingAs($admin)
        ->test(CertifyProject::class, ['project' => $this->project])
        ->call('certify')
        ->assertHasNoErrors();

    expect(Certificate::query()->count())->toBe(1);
});

it('refuses certification to an M&E officer at the screen as well as the Action', function () {
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    Livewire::actingAs($officer)
        ->test(CertifyProject::class, ['project' => $this->project])
        ->call('certify')
        ->assertForbidden();

    expect(Certificate::query()->count())->toBe(0);
});

it('lets an M&E officer serve a commencement notice', function () {
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    Livewire::actingAs($officer)
        ->test(ProjectCommencement::class, ['project' => $this->project])
        ->call('startIssue', $this->contract->ulid)
        ->call('issue')
        ->assertHasNoErrors();

    expect(CommencementNotice::query()->count())->toBe(1);
});

it('refuses a consultant the power to serve a notice', function () {
    $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    ProjectAssignment::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $consultant->id,
        'role' => ProjectRole::Consultant,
        'assigned_by_id' => $admin->id,
    ]);

    Livewire::actingAs($consultant->fresh())
        ->test(ProjectCommencement::class, ['project' => $this->project])
        ->call('startIssue', $this->contract->ulid)
        ->assertForbidden();

    expect(CommencementNotice::query()->count())->toBe(0);
});

it('shows a consultant only the certificates of projects they are assigned to', function () {
    $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $assigned = Project::factory()->certified()->create(['title' => 'Assigned Works']);
    $unassigned = Project::factory()->certified()->create(['title' => 'Someone Elses Works']);

    Certificate::factory()->forProject($assigned)->create();
    Certificate::factory()->forProject($unassigned)->create();

    ProjectAssignment::factory()->create([
        'project_id' => $assigned->id,
        'user_id' => $consultant->id,
        'role' => ProjectRole::Consultant,
        'assigned_by_id' => $admin->id,
    ]);

    Livewire::actingAs($consultant->fresh())
        ->test(CertificateRegister::class)
        ->assertOk()
        ->assertSee('Assigned Works')
        ->assertDontSee('Someone Elses Works');
});

it('lets a field monitor read the register and change nothing', function () {
    $monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    // The register is readable — an inspector needs to know what has been
    // signed off on the sites they visit.
    Livewire::actingAs($monitor)
        ->test(CertificateRegister::class)
        ->assertOk();

    // The certify screen is not even reachable: mount() authorises `view` on
    // the project, and a field monitor sees only the projects they are
    // assigned to. Asserting on ->call('certify') never got that far — a
    // component whose mount aborts has no snapshot to call into, so the old
    // form of this test failed on a Livewire internals error rather than on
    // the authorization it meant to prove.
    Livewire::actingAs($monitor)
        ->test(CertifyProject::class, ['project' => $this->project])
        ->assertForbidden();

    // And the authority itself is absent, independently of any screen: this
    // is the assertion that still holds if the component is ever rewritten.
    expect($monitor->can('issue', [Certificate::class, $this->project]))->toBeFalse();
});

it('refuses withdrawal to everyone who cannot sign', function () {
    $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    $certified = Project::factory()->certified()->create();
    $certificate = Certificate::factory()->forProject($certified)->create();

    expect($officer->can('revoke', $certificate))->toBeFalse()
        ->and($admin->can('revoke', $certificate))->toBeTrue();
});

/* -------------------------------------------------------------------------- */
/* Oversight surface */
/* -------------------------------------------------------------------------- */

it('keeps an entity administrator out of the state register', function () {
    $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    app(CurrentTenant::class)->forget();

    // The role middleware on the oversight surface, not a policy: an MDA role
    // is held in a tenant team and grants nothing globally.
    $this->actingAs($admin)
        ->get(oversightUrl('/certificates'))
        ->assertForbidden();
});

it('lets an executive viewer read the state register', function () {
    $viewer = userWithRole(Role::ExecutiveViewer);
    $viewer->forceFill(['two_factor_required_at' => now()])->save();

    app(CurrentTenant::class)->forget();

    $this->actingAs($viewer)
        ->get(oversightUrl('/certificates'))
        ->assertOk();
});

it('refuses the cross-tenant read to a user without the global permission', function () {
    $admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    app(CurrentTenant::class)->forget();

    // The Action re-checks `certificates.view` in the GLOBAL team before any
    // bypass — the route middleware is not what protects the cross-MDA read.
    expect(fn () => app(ListCertificatesAcrossTenants::class)($admin))
        ->toThrow(AuthorizationException::class);

    expect(fn () => app(SummariseCertificatesAcrossTenants::class)($admin))
        ->toThrow(AuthorizationException::class);
});

it('gives the oversight register no way to write', function () {
    $stateAdmin = userWithRole(Role::StateAdmin);
    $stateAdmin->forceFill(['two_factor_required_at' => now()])->save();

    app(CurrentTenant::class)->forget();

    $component = Livewire::actingAs($stateAdmin)->test(OversightRegister::class)->assertOk();

    // Read only by construction, asserted rather than assumed: the state's
    // interest is oversight of certification, not the power to certify on an
    // MDA's behalf.
    $methods = get_class_methods($component->instance());

    expect($methods)->not->toContain('certify')
        ->and($methods)->not->toContain('revoke')
        ->and($methods)->not->toContain('issue');
});
