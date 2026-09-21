<?php

/**
 * Every screen the results-framework module registers, rendered over REAL
 * HTTP by somebody who is allowed to see it (plan §4, manual digest §2, §4).
 *
 * Authorized 200s first, denials second, and never only the second: a suite
 * of denials proves that nothing works, which is exactly what a broken screen
 * looks like from the outside. This project has shipped that twice.
 *
 * Each case goes through the real host — `works.mne.test/indicators`,
 * `oversight.mne.test/validation` — because the surface gates live in
 * middleware (`tenant.member`, the oversight `role:` list, `2fa.require`) and
 * a Livewire-only test skips all of them.
 */

use App\Enums\IndicatorTier;
use App\Enums\Role;
use App\Models\Indicator;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorReading;
use App\Models\IndicatorTarget;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ResultFramework;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Str;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    actingOnTenant($this->works);

    $this->project = Project::factory()->ongoing()->create([
        'title' => 'Township Road Rehabilitation',
        'reference' => 'WKS/2026/001',
    ]);

    // The consultant is assigned to it, which is what makes them a consultant
    // ON it — Project::visibleTo narrows project-level roles to their active
    // assignments.
    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    $this->impact = ResultFramework::factory()->forProject($this->project)->create([
        'statement' => 'Living standards in the served communities improve measurably.',
    ]);

    $this->outcome = ResultFramework::factory()->outcomeOf($this->impact)->create([
        'statement' => 'Travel time to the nearest referral facility falls.',
    ]);

    $this->indicator = Indicator::factory()->active()->inFramework($this->outcome)->create([
        'name' => 'Average travel time to a referral facility',
        'tier' => IndicatorTier::Intermediate,
        'baseline_value' => '90.0000',
    ]);

    IndicatorTarget::factory()->forIndicator($this->indicator)->ofValue('45.0000')->create();
});

/* -------------------------------------------------------------------------- */
/* Tenant surface — the register */
/* -------------------------------------------------------------------------- */

it('renders the indicator register over HTTP with this entity’s measures on it', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/indicators'))
        ->assertOk()
        ->assertSee('Average travel time to a referral facility')
        ->assertSee('Township Road Rehabilitation');
});

it('renders the register for every role that holds indicators.view', function (Role $role) {
    $user = memberOf(User::factory()->create(), $this->works, $role);

    $this->actingAs($user)
        ->get(tenantUrl($this->works, '/indicators'))
        ->assertOk()
        ->assertSee('Average travel time to a referral facility');
})->with([
    'MDA admin' => [Role::MdaAdmin],
    'M&E officer' => [Role::MeOfficer],
    // A consultant reports figures against the framework, so they must be
    // able to read it. Assignment narrowing is what limits WHICH rows.
    'consultant' => [Role::Consultant],
]);

it('renders the register with its empty state rather than a blank screen', function () {
    $empty = Tenant::factory()->create(['name' => 'Ministry of Education', 'slug' => 'education']);
    $admin = memberOf(User::factory()->create(), $empty, Role::MdaAdmin);

    $this->actingAs($admin)
        ->get(tenantUrl($empty, '/indicators'))
        ->assertOk()
        ->assertDontSee('Average travel time to a referral facility');
});

it('refuses the register to a member holding no indicator permission at all', function () {
    $stranger = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    setPermissionsTeamId($this->works->id);
    $stranger->roles()->detach();
    $stranger->forgetCachedPermissions();

    $this->actingAs($stranger)
        ->get(tenantUrl($this->works, '/indicators'))
        ->assertForbidden();
});

it('refuses the register to somebody who is not a member of this workspace', function () {
    $outsider = memberOf(User::factory()->create(), $this->health, Role::MdaAdmin);

    // `tenant.member` stops them at the door, whatever authority they hold in
    // their own ministry.
    $this->actingAs($outsider)
        ->get(tenantUrl($this->works, '/indicators'))
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Tenant surface — one indicator */
/* -------------------------------------------------------------------------- */

it('renders the indicator definition sheet over HTTP, whole', function () {
    $this->indicator->update([
        'definition' => 'Mean minutes from settlement centroid to the nearest general hospital.',
        'data_source' => 'Household travel survey',
        'means_of_verification' => 'Survey report signed by the enumerating officer.',
    ]);

    // The officer disputing a figure reads these fields before the number, so
    // the whole matrix row has to be on the page rather than behind an "edit".
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/indicators/'.$this->indicator->ulid))
        ->assertOk()
        ->assertSee('Average travel time to a referral facility')
        ->assertSee('Mean minutes from settlement centroid to the nearest general hospital.')
        ->assertSee('Household travel survey')
        ->assertSee('Survey report signed by the enumerating officer.');
});

it('shows the figures recorded against the indicator on its own screen', function () {
    IndicatorReading::factory()
        ->forIndicator($this->indicator)
        ->ofValue('62.0000')
        ->validated()
        ->create(['collection_method' => 'Enumerator timing run, dry season.']);

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/indicators/'.$this->indicator->ulid))
        ->assertOk()
        ->assertSee('62')
        ->assertSee('Validated');
});

it('404s on a well-formed ULID that names no indicator', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/indicators/'.Str::ulid()))
        ->assertNotFound();
});

/* -------------------------------------------------------------------------- */
/* Tenant surface — the logframe builder */
/* -------------------------------------------------------------------------- */

it('renders the logframe builder for a project, nested impact → outcome → output', function () {
    $output = ResultFramework::factory()->outputOf($this->outcome)->create([
        'statement' => 'Road sections rehabilitated and handed over.',
    ]);

    Indicator::factory()->inFramework($output)->create(['name' => 'Kilometres handed over']);

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/framework'))
        ->assertOk()
        ->assertSee('Living standards in the served communities improve measurably.')
        ->assertSee('Travel time to the nearest referral facility falls.')
        ->assertSee('Road sections rehabilitated and handed over.')
        ->assertSee('Kilometres handed over');
});

it('renders the logframe builder read-only for a consultant assigned to the project', function () {
    // Consultants hold `frameworks.view` and never `frameworks.manage`: they
    // report against the logframe and must see what their figures are for.
    $this->actingAs($this->consultant)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/framework'))
        ->assertOk()
        ->assertSee('Travel time to the nearest referral facility falls.');
});

it('404s on the framework of a project belonging to another entity', function () {
    $foreign = $this->current->runAs(
        $this->health,
        fn (): Project => Project::factory()->ongoing()->create(['title' => 'Model Primary Health Centre']),
    );

    actingOnTenant($this->works);

    // A leaked public id from another ministry resolves to nothing, so the
    // binding 404s rather than serving another entity's logframe.
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/projects/'.$foreign->ulid.'/framework'))
        ->assertNotFound();
});

/* -------------------------------------------------------------------------- */
/* Oversight surface — the state library */
/* -------------------------------------------------------------------------- */

describe('oversight surface', function () {
    beforeEach(function () {
        $this->current->forget();

        $this->stateAdmin = userWithRole(Role::StateAdmin);
        $this->reviewer = userWithRole(Role::DataQualityReviewer);
        $this->executive = userWithRole(Role::ExecutiveViewer);

        // These roles mandate 2FA enrolment; AssignRole already stamps the
        // grace anchor. Restating it keeps these tests about authorization
        // rather than about how close the clock is to a deadline.
        foreach ([$this->stateAdmin, $this->reviewer, $this->executive] as $user) {
            $user->forceFill(['two_factor_required_at' => now()])->save();
        }

        $this->libraryEntry = IndicatorDefinition::factory()->create([
            'code' => 'EDU-001',
            'name' => 'Classrooms completed and in use',
        ]);

        $this->waiting = $this->current->runAs($this->works, function (): IndicatorReading {
            $indicator = Indicator::factory()->active()->create(['name' => 'Boreholes commissioned']);

            return IndicatorReading::factory()
                ->forIndicator($indicator)
                ->ofValue('37.0000')
                ->submitted()
                ->create();
        });

        $this->current->forget();
    });

    it('renders the state indicator library over HTTP', function () {
        $this->actingAs($this->stateAdmin)
            ->get(oversightUrl('/indicator-library'))
            ->assertOk()
            ->assertSee('EDU-001')
            ->assertSee('Classrooms completed and in use');
    });

    it('lets every oversight role READ the library, because an MDA picks from it', function (string $actor) {
        $this->actingAs($this->{$actor})
            ->get(oversightUrl('/indicator-library'))
            ->assertOk()
            ->assertSee('EDU-001');
    })->with([
        'state admin' => ['stateAdmin'],
        'data quality reviewer' => ['reviewer'],
        'executive viewer' => ['executive'],
    ]);

    it('renders the validation queue with every entity’s submitted figures', function () {
        $this->actingAs($this->reviewer)
            ->get(oversightUrl('/validation'))
            ->assertOk()
            ->assertSee('Boreholes commissioned')
            ->assertSee('Ministry of Works');
    });

    it('renders the validation queue for the state admin too', function () {
        $this->actingAs($this->stateAdmin)
            ->get(oversightUrl('/validation'))
            ->assertOk()
            ->assertSee('Boreholes commissioned');
    });

    it('keeps an executive viewer out of the validation desk entirely', function () {
        // Read-only oversight: `oversight.validation.review` is seeded to the
        // secretariat and the Bureau of Statistics, not to the governor's
        // office. The screen is a working desk, not a report.
        $this->actingAs($this->executive)
            ->get(oversightUrl('/validation'))
            ->assertForbidden();
    });

    it('keeps the strongest workspace role off both oversight screens', function (string $path) {
        $mdaAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
        $this->current->forget();

        // The role middleware on the oversight host admits oversight roles
        // only. An MDA admin's authority stops at their own subdomain.
        $this->actingAs($mdaAdmin)
            ->get(oversightUrl($path))
            ->assertForbidden();
    })->with([
        'the library' => ['/indicator-library'],
        'the validation desk' => ['/validation'],
    ]);
});
