<?php

/**
 * The reporting inbox (progress-reporting.md §6).
 *
 * Two things have to be true and neither is provable from the Action tests:
 * the ROUTE reaches this screen at all (`/reports/inbox` shares a prefix with
 * the `/reports/{report}` wildcard, and a literal registered after its sibling
 * wildcard binds as a model key and 404s), and the QUEUE respects separation
 * of duties — a user must never be offered work the chain would refuse.
 *
 * Every route case is a real request over the tenant subdomain. Livewire::test
 * never crosses HTTP, so it cannot tell a working route from a missing one.
 */

use App\Enums\ProgressReportStatus;
use App\Enums\Role;
use App\Livewire\Tenant\Reporting\ReportInbox;
use App\Livewire\Tenant\Reporting\ReportReview;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->otherOfficer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->period = ReportingPeriod::factory()->monthly()->create();

    $this->project = Project::factory()->ongoing()->create([
        'title' => 'Township Road Rehabilitation',
        'reference' => 'WKS/2026/001',
    ]);

    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    $this->consultant = User::query()->whereKey($this->consultant->id)->firstOrFail();
});

/* -------------------------------------------------------------------------- */
/* The route reaches the screen */
/* -------------------------------------------------------------------------- */

it('dispatches /reports/inbox to the inbox, not to the report wildcard', function () {
    // Dispatch, not registration: a route test that only asserts the route
    // EXISTS passes happily while `inbox` is being bound as a report ULID and
    // the screen 404s. The only honest assertion is which component the real
    // request actually renders.
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/reports/inbox'))
        ->assertOk()
        ->assertSeeLivewire(ReportInbox::class)
        ->assertDontSeeLivewire(ReportReview::class);
});

it('leaves the /reports/{report} wildcard working beside it', function () {
    $report = ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->submitted($this->consultant)
        ->create();

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/reports/'.$report->ulid))
        ->assertOk()
        ->assertSeeLivewire(ReportReview::class);
});

/* -------------------------------------------------------------------------- */
/* What is waiting on whom */
/* -------------------------------------------------------------------------- */

it('shows an officer a return someone else filed, waiting for review', function () {
    ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->submitted($this->consultant)
        ->create();

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/reports/inbox'))
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Review');
});

it('never offers a user their own submission as theirs to clear', function () {
    // The on-behalf path: an officer typed a contractor's return. They filed
    // it, so someone else must review it.
    ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->officer)
        ->submitted($this->officer)
        ->create();

    $mine = Livewire::actingAs($this->officer)->test(ReportInbox::class);
    expect($mine->instance()->reports->total())->toBe(0);

    // …and it IS waiting for their colleague.
    $theirs = Livewire::actingAs($this->otherOfficer)->test(ReportInbox::class);
    expect($theirs->instance()->reports->total())->toBe(1);
});

it('offers a director a reviewed return, and withholds one they reviewed themselves', function () {
    ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->submitted($this->consultant)
        ->reviewed($this->admin)
        ->create();

    // require_separate_approver is on by default: the reviewer is not the
    // approver, so the director who reviewed it sees nothing.
    expect(Livewire::actingAs($this->admin)->test(ReportInbox::class)->instance()->reports->total())->toBe(0);

    $second = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    expect(Livewire::actingAs($second)->test(ReportInbox::class)->instance()->reports->total())->toBe(1);
});

it('hands the reviewed return back to its reviewer when the instance allows one signer', function () {
    // A single-officer MDA: nobody else can sign, so separation is switched
    // off at the instance and the same director approves what they reviewed.
    config()->set('platform.reporting.require_separate_approver', false);

    ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->submitted($this->consultant)
        ->reviewed($this->admin)
        ->create();

    expect(Livewire::actingAs($this->admin)->test(ReportInbox::class)->instance()->reports->total())->toBe(1);
});

it('puts an author’s returned work in their own inbox and nobody else’s', function () {
    ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->returned('Expenditure does not reconcile with the attached valuation.')
        ->create();

    $author = Livewire::actingAs($this->consultant)->test(ReportInbox::class);
    expect($author->instance()->reports->total())->toBe(1);
    $author->assertSee('Correct and refile');

    // The officer holds `reports.review`, but a returned report is not in any
    // reviewer's queue — it is back with its author.
    expect(Livewire::actingAs($this->officer)->test(ReportInbox::class)->instance()->reports->total())->toBe(0);
});

it('offers a consultant no review or approval queue at all', function () {
    $options = Livewire::actingAs($this->consultant)
        ->test(ReportInbox::class)
        ->instance()
        ->queueOptions;

    expect(array_keys($options))->toBe(['returned']);
});

it('keeps an unassigned consultant out of another consultant’s queue', function () {
    $stranger = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($stranger)
        ->returned()
        ->create();

    // Authored by them, but on a project they are not assigned to —
    // visibleTo() is the single definition of project visibility and it wins.
    expect(Livewire::actingAs($stranger)->test(ReportInbox::class)->instance()->reports->total())->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* Counts and filters */
/* -------------------------------------------------------------------------- */

it('counts each queue separately, and the whole inbox once', function () {
    ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->submitted($this->consultant)
        ->create();

    $second = Project::factory()->ongoing()->create(['title' => 'Primary Health Centre Upgrade']);

    ProgressReport::factory()
        ->forProject($second)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->submitted($this->consultant)
        ->reviewed($this->officer)
        ->create();

    $stats = Livewire::actingAs($this->admin)->test(ReportInbox::class)->instance()->stats;

    expect($stats['review'])->toBe(1)
        ->and($stats['approve'])->toBe(1)
        ->and($stats['total'])->toBe(2)
        ->and($stats['returned'])->toBe(0);
});

it('narrows to one step of the chain when asked', function () {
    ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->submitted($this->consultant)
        ->create();

    $second = Project::factory()->ongoing()->create(['title' => 'Primary Health Centre Upgrade']);

    ProgressReport::factory()
        ->forProject($second)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->submitted($this->consultant)
        ->reviewed($this->officer)
        ->create();

    $component = Livewire::actingAs($this->admin)->test(ReportInbox::class);

    expect($component->instance()->reports->total())->toBe(2);

    $component->set('queue', 'approve');

    expect($component->instance()->reports->pluck('status')->all())
        ->toBe([ProgressReportStatus::Reviewed]);
});

it('persists the chosen queue in the query string', function () {
    Livewire::withQueryParams(['queue' => 'review'])
        ->actingAs($this->officer)
        ->test(ReportInbox::class)
        ->assertOk()
        ->assertSet('queue', 'review');
});

it('filters the inbox by project without changing the counts above it', function () {
    ProgressReport::factory()
        ->forProject($this->project)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->submitted($this->consultant)
        ->create();

    $second = Project::factory()->ongoing()->create(['title' => 'Primary Health Centre Upgrade']);

    ProgressReport::factory()
        ->forProject($second)
        ->forPeriod($this->period)
        ->by($this->consultant)
        ->submitted($this->consultant)
        ->create();

    $component = Livewire::actingAs($this->officer)
        ->test(ReportInbox::class)
        ->set('projectUlid', $second->ulid);

    expect($component->instance()->reports->total())->toBe(1)
        // The summary row answers "how much is waiting on me", not "how much
        // matches this search" — it must not move with the filter bar.
        ->and($component->instance()->stats['total'])->toBe(2);
});

it('offers an honest empty state rather than a blank screen', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/reports/inbox'))
        ->assertOk()
        ->assertSee('Your inbox is clear');
});

/* -------------------------------------------------------------------------- */
/* Tenancy isolation */
/* -------------------------------------------------------------------------- */

it('never shows one entity’s inbox to another, in the list or the counts', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $current = app(CurrentTenant::class);

    $current->runAs($health, function () use ($health) {
        $admin = memberOf(User::factory()->create(), $health, Role::MdaAdmin);
        $author = memberOf(User::factory()->create(), $health, Role::MeOfficer);

        $project = Project::factory()->ongoing()->create(['title' => 'Cottage Hospital Rewiring']);

        ProgressReport::factory()
            ->forProject($project)
            ->by($author)
            ->submitted($author)
            ->create();

        $this->healthAdmin = $admin;
    });

    $current->set($this->works);

    // Works has nothing of its own.
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/reports/inbox'))
        ->assertOk()
        ->assertDontSee('Cottage Hospital Rewiring')
        ->assertSee('Your inbox is clear');

    expect(Livewire::actingAs($this->officer)->test(ReportInbox::class)->instance()->stats['total'])->toBe(0);

    // Health sees exactly its own.
    $current->set($health);

    $this->actingAs($this->healthAdmin)
        ->get(tenantUrl($health, '/reports/inbox'))
        ->assertOk()
        ->assertSee('Cottage Hospital Rewiring');
});

it('refuses the inbox to a signed-in user with no membership of this workspace', function () {
    $stateAdmin = userWithRole(Role::StateAdmin);

    $this->actingAs($stateAdmin)
        ->get(tenantUrl($this->works, '/reports/inbox'))
        ->assertForbidden();
});
