<?php

/**
 * The site-inspection screens (plan §4).
 *
 * Every screen this module registers is asserted the AUTHORIZED way first —
 * a real GET on the real subdomain that renders the real record — and only
 * then the denial. A suite of denials proves that nothing works, and
 * Livewire::test() sets properties directly without ever dispatching a route
 * or rendering a layout, so it cannot catch a route that does not bind or a
 * view that throws.
 *
 * Domain rules themselves are proved in InspectionLifecycleTest; repeating
 * them here would be theatre.
 */

use App\Enums\InspectionOutcome;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\Role;
use App\Livewire\Oversight\Inspections\InspectionBoard;
use App\Livewire\Tenant\Inspections\InspectionConduct;
use App\Livewire\Tenant\Inspections\InspectionDetail;
use App\Livewire\Tenant\Inspections\InspectionIndex;
use App\Livewire\Tenant\Inspections\InspectionSchedule;
use App\Models\InspectionChecklistTemplate;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\SiteInspection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('documents');
    Notification::fake();
    seedPermissions();

    // Every date in this module is deadline arithmetic. A fixed clock is the
    // only way the report-due assertions mean anything at 23:59.
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-21 09:00:00'));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    // What ResolveTenant does on every real request to a workspace. The shared
    // vault panel embedded in the detail and conduct screens signs its links
    // with route('tenant.documents.download') and relies on this default for
    // the subdomain parameter; Livewire::test never crosses HTTP.
    URL::defaults(['tenant' => $this->works->slug]);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->project = Project::factory()->ongoing()->create([
        'title' => 'Township Road Rehabilitation',
        'reference' => 'WKS/2026/001',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/* -------------------------------------------------------------------------- */
/* The routes reach the screens */
/* -------------------------------------------------------------------------- */

it('renders the field desk over real HTTP', function () {
    SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->scheduled()
        ->create();

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/inspections'))
        ->assertOk()
        ->assertSeeLivewire(InspectionIndex::class)
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Routine monitoring visit')
        ->assertSee('In the diary');
});

it('renders the schedule form over real HTTP', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/inspections/create'))
        ->assertOk()
        ->assertSeeLivewire(InspectionSchedule::class)
        ->assertSee('Schedule a site visit')
        ->assertSee('Township Road Rehabilitation');
});

it('renders a filed visit detail over real HTTP', function () {
    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->submitted($this->monitor)
        ->create();

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/inspections/'.$inspection->ulid))
        ->assertOk()
        ->assertSeeLivewire(InspectionDetail::class)
        ->assertSee('Field Trip Report')
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Sub-base laid across 1.2 km of the northern section.')
        ->assertSee('Report filed');
});

it('renders the conduct form over real HTTP and starts the visit by opening it', function () {
    $template = InspectionChecklistTemplate::factory()->withItems()->create();

    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->usingTemplate($template)
        ->scheduled()
        ->create();

    $this->actingAs($this->monitor)
        ->get(tenantUrl($this->works, '/inspections/'.$inspection->ulid.'/conduct'))
        ->assertOk()
        ->assertSeeLivewire(InspectionConduct::class)
        ->assertSee('Conduct: Routine monitoring visit')
        ->assertSee('Is the work on site consistent with the approved drawings and specification?');

    $opened = SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();

    // Opening the form IS the visit: conducted_at is stamped now, and the
    // report clock starts from the state's own window rather than a literal.
    $dueDays = app(SettingsRepository::class)->int('inspections', 'report_due_days', 3);

    expect($opened->status)->toBe(InspectionStatus::InProgress)
        ->and($opened->conducted_at?->toDateTimeString())->toBe(Carbon::now()->toDateTimeString())
        ->and($opened->report_due_at?->toDateTimeString())
        ->toBe(Carbon::now()->addDays($dueDays)->endOfDay()->toDateTimeString());
});

it('renders the state field-work board over real HTTP', function () {
    SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->escalating()
        ->create();

    $stateAdmin = userWithRole(Role::StateAdmin);
    $stateAdmin->forceFill(['two_factor_required_at' => now()])->save(); // inside the grace window

    app(CurrentTenant::class)->forget();

    $this->actingAs($stateAdmin)
        ->get(oversightUrl('/inspections'))
        ->assertOk()
        ->assertSeeLivewire(InspectionBoard::class)
        ->assertSee('Ministry of Works')
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Major issues');
});

it('shows an empty state rather than a blank screen on both surfaces', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/inspections'))
        ->assertOk()
        ->assertSee('No site visits yet');

    $stateAdmin = userWithRole(Role::StateAdmin);
    $stateAdmin->forceFill(['two_factor_required_at' => now()])->save();

    app(CurrentTenant::class)->forget();

    $this->actingAs($stateAdmin)
        ->get(oversightUrl('/inspections'))
        ->assertOk()
        ->assertSee('No field work recorded yet');
});

/* -------------------------------------------------------------------------- */
/* The desk shows the right rows */
/* -------------------------------------------------------------------------- */

it('narrows the desk to their own projects for a consultant', function () {
    $unassigned = Project::factory()->ongoing()->create(['title' => 'Unassigned Bridge Works']);

    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    SiteInspection::factory()->forProject($this->project)->ledBy($this->monitor)->scheduled()->create();
    SiteInspection::factory()->forProject($unassigned)->ledBy($this->monitor)->scheduled()->create();

    $this->actingAs(User::query()->whereKey($this->consultant->id)->firstOrFail())
        ->get(tenantUrl($this->works, '/inspections'))
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertDontSee('Unassigned Bridge Works');
});

it('shows a field monitor the visit they lead even on a project they are not assigned to', function () {
    // The union the scope exists for: an inspector sent to verify a project
    // they are not on the assignment list for still finds their own diary.
    $elsewhere = Project::factory()->ongoing()->create(['title' => 'Cottage Hospital Perimeter Wall']);

    SiteInspection::factory()->forProject($elsewhere)->ledBy($this->monitor)->scheduled()->create();
    SiteInspection::factory()->forProject($this->project)->ledBy($this->officer)->scheduled()->create();

    $this->actingAs(User::query()->whereKey($this->monitor->id)->firstOrFail())
        ->get(tenantUrl($this->works, '/inspections'))
        ->assertOk()
        ->assertSee('Cottage Hospital Perimeter Wall')
        ->assertDontSee('Township Road Rehabilitation');
});

it('tallies the desk from the whole workspace, not from the filter bar', function () {
    SiteInspection::factory()->forProject($this->project)->ledBy($this->monitor)->scheduled()->count(2)->create();
    SiteInspection::factory()->forProject($this->project)->ledBy($this->monitor)->inProgress()->create();
    SiteInspection::factory()->forProject($this->project)->ledBy($this->monitor)->submitted($this->monitor)->create();
    SiteInspection::factory()->forProject($this->project)->ledBy($this->monitor)->reportOverdue()->create();

    $component = Livewire::actingAs($this->officer)
        ->test(InspectionIndex::class)
        ->assertOk()
        ->set('status', InspectionStatus::Submitted->value);

    // One row on screen, but the stat row still describes the whole desk —
    // a summary that moves with the filters cannot answer "am I behind?".
    expect($component->instance()->inspections()->total())->toBe(1)
        ->and($component->instance()->stats())->toBe([
            'scheduled' => 2,
            'under_way' => 2,
            'awaiting_review' => 1,
            'reports_overdue' => 1,
        ]);
});

it('filters the desk by status, type, outcome and my own diary', function () {
    SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->ofType(InspectionType::Final)
        ->submitted($this->monitor, InspectionOutcome::MajorIssues)
        ->create();

    SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->officer)
        ->ofType(InspectionType::Routine)
        ->scheduled()
        ->create();

    $component = Livewire::actingAs($this->officer)->test(InspectionIndex::class)->assertOk();

    expect($component->instance()->inspections()->total())->toBe(2);

    $component->set('status', InspectionStatus::Submitted->value);
    expect($component->instance()->inspections()->total())->toBe(1);

    $component->set('status', '')->set('type', InspectionType::Final->value);
    expect($component->instance()->inspections()->total())->toBe(1);

    $component->set('type', '')->set('outcome', InspectionOutcome::MajorIssues->value);
    expect($component->instance()->inspections()->total())->toBe(1);

    $component->set('outcome', '')->set('mineOnly', true);
    expect($component->instance()->inspections()->total())->toBe(1)
        ->and($component->instance()->inspections()->getCollection()->first()->lead_inspector_id)
        ->toBe($this->officer->id);

    $component->call('clearFilters');
    expect($component->instance()->inspections()->total())->toBe(2)
        ->and($component->instance()->hasFilters())->toBeFalse();
});

it('carries the project filter in the URL as a ULID and matches nothing for a foreign one', function () {
    SiteInspection::factory()->forProject($this->project)->ledBy($this->monitor)->scheduled()->create();

    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $foreign = app(CurrentTenant::class)->runAs(
        $health,
        fn (): Project => Project::factory()->ongoing()->create(),
    );

    actingOnTenant($this->works);

    $component = Livewire::withQueryParams(['project' => $this->project->ulid])
        ->actingAs($this->officer)
        ->test(InspectionIndex::class)
        ->assertSet('projectUlid', $this->project->ulid);

    expect($component->instance()->inspections()->total())->toBe(1)
        // A ULID belonging to another ministry resolves inside the TenantScope,
        // so it matches no project and therefore no visit — it does not
        // quietly widen the list to everything.
        ->and($component->set('projectUlid', $foreign->ulid)->instance()->inspections()->total())->toBe(0);
});

it('searches the desk by project title and reference', function () {
    $other = Project::factory()->ongoing()->create(['title' => 'Storm Drainage Upgrade', 'reference' => 'WKS/2026/002']);

    SiteInspection::factory()->forProject($this->project)->ledBy($this->monitor)->scheduled()->create();
    SiteInspection::factory()->forProject($other)->ledBy($this->monitor)->scheduled()->create();

    $component = Livewire::actingAs($this->officer)->test(InspectionIndex::class)->set('search', 'Drainage');

    expect($component->instance()->inspections()->total())->toBe(1);

    $component->set('search', 'WKS/2026/001');

    expect($component->instance()->inspections()->getCollection()->first()->project->reference)
        ->toBe('WKS/2026/001');
});

/* -------------------------------------------------------------------------- */
/* The board */
/* -------------------------------------------------------------------------- */

it('narrows the state board to one entity, to escalations and to silent field work', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    app(CurrentTenant::class)->runAs($health, function (): void {
        $project = Project::factory()->ongoing()->create(['title' => 'Cottage Hospital Upgrade']);
        $inspector = memberOf(User::factory()->create(), $project->tenant, Role::FieldMonitor);

        SiteInspection::factory()->forProject($project)->ledBy($inspector)->reportOverdue()->create();
    });

    actingOnTenant($this->works);
    SiteInspection::factory()->forProject($this->project)->ledBy($this->monitor)->escalating()->create();

    $stateAdmin = userWithRole(Role::StateAdmin);
    app(CurrentTenant::class)->forget();

    $component = Livewire::actingAs($stateAdmin)->test(InspectionBoard::class)->assertOk();

    expect($component->instance()->inspections()->total())->toBe(2)
        ->and($component->instance()->stats())
        ->toBe(['total' => 2, 'escalated' => 1, 'reports_overdue' => 1]);

    $component->set('tenantId', (string) $health->id);
    expect($component->instance()->inspections()->total())->toBe(1);

    $component->set('tenantId', '')->set('escalated', true);
    expect($component->instance()->inspections()->getCollection()->first()->outcome)
        ->toBe(InspectionOutcome::MajorIssues);

    $component->set('escalated', false)->set('overdue', true);
    expect($component->instance()->inspections()->getCollection()->first()->tenant_id)->toBe($health->id);
});

/* -------------------------------------------------------------------------- */
/* Denials */
/* -------------------------------------------------------------------------- */

it('refuses the schedule form to a field monitor, who conducts visits but does not plan them', function () {
    $this->actingAs(User::query()->whereKey($this->monitor->id)->firstOrFail())
        ->get(tenantUrl($this->works, '/inspections/create'))
        ->assertForbidden();
});

it('refuses the schedule form to a consultant', function () {
    $this->actingAs(User::query()->whereKey($this->consultant->id)->firstOrFail())
        ->get(tenantUrl($this->works, '/inspections/create'))
        ->assertForbidden();
});

it('refuses the conduct form to a consultant, who never files an inspection', function () {
    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->scheduled()
        ->create();

    $this->actingAs(User::query()->whereKey($this->consultant->id)->firstOrFail())
        ->get(tenantUrl($this->works, '/inspections/'.$inspection->ulid.'/conduct'))
        ->assertForbidden();

    // …and the visit is untouched: a refused page must not have started it.
    expect(SiteInspection::query()->whereKey($inspection->getKey())->value('status'))
        ->toBe(InspectionStatus::Scheduled);
});

it('refuses another monitor’s conduct form to a field monitor', function () {
    // `inspections.conduct` lets you conduct the visits assigned to you, not
    // walk into someone else's.
    $other = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    $inspection = SiteInspection::factory()
        ->forProject($this->project)
        ->ledBy($this->monitor)
        ->scheduled()
        ->create();

    $this->actingAs(User::query()->whereKey($other->id)->firstOrFail())
        ->get(tenantUrl($this->works, '/inspections/'.$inspection->ulid.'/conduct'))
        ->assertForbidden();
});

it('keeps a workspace administrator out of the state board, however senior in their own ministry', function () {
    // Through the route, because the role gate is middleware: an MDA admin
    // holds no role at all in the GLOBAL team the oversight surface checks.
    $this->actingAs($this->admin)
        ->get(oversightUrl('/inspections'))
        ->assertForbidden();
});

it('refuses the board component itself to a workspace administrator', function () {
    // Belt and braces: mount() asks the same question the Action asks, so a
    // direct Livewire update cannot reach it if the route group ever changes.
    Livewire::actingAs(User::query()->whereKey($this->admin->id)->firstOrFail())
        ->test(InspectionBoard::class)
        ->assertForbidden();
});

it('links the field desk from the workspace sidebar', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/'))
        ->assertOk()
        ->assertSee(tenantUrl($this->works, '/inspections'));
});
