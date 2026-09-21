<?php

/**
 * Every screen the evaluation module registers, rendered over REAL HTTP by
 * somebody who is allowed to see it (plan §4, manual digest §3–4).
 *
 * Authorized 200s first, denials second, and never only the second: a suite
 * of denials proves that nothing works, which is exactly what a broken screen
 * looks like from the outside. This project has shipped that twice.
 *
 * Each case goes through the real host — `works.mne.test/evaluations`,
 * `oversight.mne.test/recommendations` — because the surface gates live in
 * middleware (`tenant.member`, the oversight `role:` list, `2fa.require`) and
 * a Livewire-only test skips all of them.
 */

use App\Actions\Evaluation\CommissionEvaluation;
use App\Enums\EvaluationType;
use App\Enums\RecommendationPriority;
use App\Enums\Role;
use App\Models\Evaluation;
use App\Models\EvaluationTeamMember;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(['name' => 'Bolanle Adewale']), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(['name' => 'Ifeoma Nnadi']), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    actingOnTenant($this->works);

    $this->project = Project::factory()->ongoing()->create([
        'title' => 'Township Road Rehabilitation',
        'reference' => 'WKS/2026/001',
    ]);

    $this->evaluation = Evaluation::factory()->forProject($this->project)->midTerm()->create([
        'title' => 'Mid-term evaluation of the township road programme',
        'sponsor' => 'State M&E Secretariat',
    ]);

    EvaluationTeamMember::factory()->forEvaluation($this->evaluation)->lead($this->officer)->create();
});

/* -------------------------------------------------------------------------- */
/* Tenant surface — the register */
/* -------------------------------------------------------------------------- */

it('renders the evaluation register over HTTP with this entity’s commissions on it', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/evaluations'))
        ->assertOk()
        ->assertSee('Mid-term evaluation of the township road programme')
        ->assertSee('Township Road Rehabilitation');
});

it('renders the register for every role that holds evaluations.view', function (Role $role) {
    $user = memberOf(User::factory()->create(), $this->works, $role);

    $this->actingAs($user)
        ->get(tenantUrl($this->works, '/evaluations'))
        ->assertOk()
        ->assertSee('Mid-term evaluation of the township road programme');
})->with([
    'MDA admin' => [Role::MdaAdmin],
    'M&E officer' => [Role::MeOfficer],
]);

it('keeps a consultant off the evaluation register entirely', function () {
    // `evaluations.view` is never seeded to a project-level role: an
    // evaluation of a contractor's own delivery is not the contractor's to
    // read while it is being written.
    $this->actingAs($this->consultant)
        ->get(tenantUrl($this->works, '/evaluations'))
        ->assertForbidden();
});

it('renders the register with an empty state rather than a blank screen', function () {
    $education = Tenant::factory()->create(['name' => 'Ministry of Education', 'slug' => 'education']);
    $admin = memberOf(User::factory()->create(), $education, Role::MdaAdmin);

    $this->actingAs($admin)
        ->get(tenantUrl($education, '/evaluations'))
        ->assertOk()
        ->assertDontSee('Mid-term evaluation of the township road programme');
});

/* -------------------------------------------------------------------------- */
/* Tenant surface — commissioning */
/* -------------------------------------------------------------------------- */

it('renders the commissioning form over HTTP', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/evaluations/create'))
        ->assertOk()
        // The form has to offer the state's own projects and the four formats
        // the manual recognises.
        ->assertSee('Township Road Rehabilitation')
        ->assertSee(EvaluationType::MidTerm->label())
        ->assertSee(EvaluationType::Impact->label());
});

it('binds /evaluations/create as the form, not as an evaluation ULID', function () {
    // The literal-before-wildcard rule: registered the other way round,
    // "create" binds as a ULID and the form 404s.
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/evaluations/create'))
        ->assertOk()
        ->assertDontSee('Mid-term evaluation of the township road programme');
});

it('refuses the commissioning form to a role that cannot commission', function () {
    $monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    $this->actingAs($monitor)
        ->get(tenantUrl($this->works, '/evaluations/create'))
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Tenant surface — one evaluation */
/* -------------------------------------------------------------------------- */

it('renders an evaluation whole: commission, scorecard, report skeleton and team', function () {
    $commissioned = app(CommissionEvaluation::class)($this->officer, [
        'project_id' => $this->project->id,
        'scope' => 'project',
        'type' => EvaluationType::Terminal,
        'title' => 'Terminal evaluation of the township road programme',
        'purpose' => 'Establish what the intervention delivered against the targets it set itself.',
        'sponsor' => 'State M&E Secretariat',
    ], [
        ['user_id' => $this->officer->id, 'role' => 'lead'],
        ['external_name' => 'Dr. A. Balogun', 'external_organisation' => 'Independent', 'role' => 'member'],
    ]);

    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/evaluations/'.$commissioned->ulid))
        ->assertOk()
        ->assertSee('Terminal evaluation of the township road programme')
        // The eleven-section skeleton and the DAC scorecard are built at
        // commissioning, so the screen shows what still has to be ANSWERED
        // rather than an empty panel that looks like an unused feature.
        ->assertSee('Executive summary')
        ->assertSee('Findings')
        ->assertSee('Lessons learned')
        ->assertSee('Sustainability')
        // …and the roster, including the external evaluator with no account.
        ->assertSee('Ifeoma Nnadi')
        ->assertSee('Dr. A. Balogun');
});

it('404s on a well-formed ULID that names no evaluation', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/evaluations/'.Str::ulid()))
        ->assertNotFound();
});

/* -------------------------------------------------------------------------- */
/* Tenant surface — the follow-up register */
/* -------------------------------------------------------------------------- */

it('renders the follow-up register over HTTP', function () {
    Recommendation::factory()
        ->from($this->evaluation)
        ->priority(RecommendationPriority::Critical)
        ->create([
            'title' => 'Re-sequence the drainage works ahead of the wet season',
            'addressee_body' => 'Directorate of Works',
        ]);

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/recommendations'))
        ->assertOk()
        ->assertSee('Re-sequence the drainage works ahead of the wet season')
        ->assertSee('Directorate of Works');
});

it('keeps a consultant off the follow-up register', function () {
    $this->actingAs($this->consultant)
        ->get(tenantUrl($this->works, '/recommendations'))
        ->assertForbidden();
});

it('refuses every evaluation screen to somebody who is not a member of this workspace', function (string $path) {
    $outsider = memberOf(User::factory()->create(), $this->health, Role::MdaAdmin);

    // `tenant.member` stops them at the door, whatever authority they hold in
    // their own ministry.
    $this->actingAs($outsider)
        ->get(tenantUrl($this->works, $path))
        ->assertForbidden();
})->with([
    'the register' => ['/evaluations'],
    'the commissioning form' => ['/evaluations/create'],
    'the follow-up register' => ['/recommendations'],
]);

/* -------------------------------------------------------------------------- */
/* Oversight surface */
/* -------------------------------------------------------------------------- */

describe('oversight surface', function () {
    beforeEach(function () {
        $this->current->runAs($this->works, fn () => Recommendation::factory()
            ->from($this->evaluation)
            ->create([
                'title' => 'Re-sequence the drainage works ahead of the wet season',
                'addressee_body' => 'Directorate of Works',
            ]));

        $this->current->runAs($this->health, function (): void {
            $clinic = Project::factory()->ongoing()->create(['title' => 'Model Primary Health Centre']);

            $evaluation = Evaluation::factory()->forProject($clinic)->terminal()->create([
                'title' => 'Terminal evaluation of the cottage hospital upgrade',
            ]);

            Recommendation::factory()->from($evaluation)->create([
                'title' => 'Commission the generator before handover',
                'addressee_body' => 'Directorate of Hospital Services',
            ]);
        });

        $this->current->forget();

        $this->stateAdmin = userWithRole(Role::StateAdmin);
        $this->executive = userWithRole(Role::ExecutiveViewer);

        foreach ([$this->stateAdmin, $this->executive] as $user) {
            $user->forceFill(['two_factor_required_at' => now()])->save(); // inside grace
        }

        $this->current->forget();
    });

    it('renders the state evaluation board with every entity on it', function () {
        $this->actingAs($this->stateAdmin)
            ->get(oversightUrl('/evaluations'))
            ->assertOk()
            ->assertSee('Mid-term evaluation of the township road programme')
            ->assertSee('Terminal evaluation of the cottage hospital upgrade')
            ->assertSee('Ministry of Works')
            ->assertSee('Ministry of Health');
    });

    it('renders the state follow-up board with every entity’s outstanding items', function () {
        $this->actingAs($this->stateAdmin)
            ->get(oversightUrl('/recommendations'))
            ->assertOk()
            ->assertSee('Re-sequence the drainage works ahead of the wet season')
            ->assertSee('Commission the generator before handover');
    });

    it('lets an executive viewer READ both boards, because reading is what the role is for', function (string $path) {
        $this->actingAs($this->executive)
            ->get(oversightUrl($path))
            ->assertOk();
    })->with([
        'the evaluation board' => ['/evaluations'],
        'the follow-up board' => ['/recommendations'],
    ]);

    it('keeps the strongest workspace role off both oversight boards', function (string $path) {
        $mdaAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
        $this->current->forget();

        // An MDA admin's authority stops at their own subdomain: the oversight
        // role middleware admits oversight roles only.
        $this->actingAs($mdaAdmin)
            ->get(oversightUrl($path))
            ->assertForbidden();
    })->with([
        'the evaluation board' => ['/evaluations'],
        'the follow-up board' => ['/recommendations'],
    ]);
});
