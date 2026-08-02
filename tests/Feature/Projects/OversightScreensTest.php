<?php

/**
 * Oversight project screens (projects-module.md §5).
 *
 * The Action tests already prove the cross-MDA reads aggregate correctly
 * (Feature/Oversight/PortfolioSummaryTest). What is unproven until here is the
 * *surface*: that the state screens render every entity, that the drill-down
 * narrows to one, that the only mutations they offer are the interventions the
 * state is actually empowered to make — and that a workspace role cannot reach
 * any of it, however they arrive.
 *
 * Every route case is a real request to the oversight host, because the role
 * gate lives in middleware and a Livewire-only test would skip it entirely.
 */

use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Livewire\Oversight\Projects\ContractorRegistry;
use App\Livewire\Oversight\Projects\ProjectView;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $current = app(CurrentTenant::class);

    $this->road = $current->runAs($this->works, fn (): Project => Project::factory()->ongoing()->create([
        'title' => 'Township Road Rehabilitation',
        'contract_value_total' => '300000000.00',
    ]));

    $this->clinic = $current->runAs($this->health, fn (): Project => Project::factory()->ongoing()->create([
        'title' => 'Model Primary Health Centre',
        'contract_value_total' => '200000000.00',
    ]));

    // The oversight surface binds no tenant — every assertion below runs in
    // the context the real requests run in.
    $current->forget();

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->execViewer = userWithRole(Role::ExecutiveViewer);

    // Both roles mandate 2FA enrolment. AssignRole stamps the grace anchor
    // already; restating it keeps these tests about authorization rather than
    // about how close the clock is to a deadline.
    foreach ([$this->stateAdmin, $this->execViewer] as $user) {
        $user->forceFill(['two_factor_required_at' => now()])->save(); // inside grace
    }

    // The strongest workspace role there is — the yardstick for "no authority
    // outside your own subdomain".
    $this->mdaAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    // …and back to the oversight context that memberOf() just switched away from.
    $current->forget();
});

/* -------------------------------------------------------------------------- */
/* Portfolio */
/* -------------------------------------------------------------------------- */

it('renders the state portfolio with every entity on it', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/portfolio'))
        ->assertOk()
        ->assertSee('Ministry of Works')
        ->assertSee('Ministry of Health')
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Model Primary Health Centre');
});

it('drills down to one entity by slug, and shows no one else’s projects', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/portfolio/works'))
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertDontSee('Model Primary Health Centre');
});

/* -------------------------------------------------------------------------- */
/* Project record */
/* -------------------------------------------------------------------------- */

it('renders a project from any entity on the state surface', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/projects/'.$this->clinic->ulid))
        ->assertOk()
        ->assertSee('Model Primary Health Centre')
        ->assertSee('Ministry of Health');
});

it('suspends a project state-side, through the same Action the workspace uses', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ProjectView::class, ['ulid' => $this->road->ulid])
        ->call('startTransition', ProjectStatus::Suspended->value)
        ->set('transitionReason', 'Works halted pending release of the second tranche.')
        ->call('confirmTransition')
        ->assertHasNoErrors();

    $fresh = app(CurrentTenant::class)->runAs($this->works, fn (): Project => $this->road->fresh());

    expect($fresh->status)->toBe(ProjectStatus::Suspended);
});

it('records the suspension against the right entity, never an orphaned ledger row', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ProjectView::class, ['ulid' => $this->road->ulid])
        ->call('startTransition', ProjectStatus::Suspended->value)
        ->set('transitionReason', 'Works halted pending release of the second tranche.')
        ->call('confirmTransition')
        ->assertHasNoErrors();

    // The ledger row is tenant-owned. Written under an oversight bypass it can
    // silently lose its tenant_id — which would strand the entry outside the
    // MDA's own timeline while still appearing to have "worked".
    $event = app(CurrentTenant::class)->runAs(
        $this->works,
        fn () => $this->road->statusEvents()->where('to_status', ProjectStatus::Suspended)->first(),
    );

    expect($event)->not->toBeNull()
        ->and($event->tenant_id)->toBe($this->works->id)
        ->and($event->reason)->toContain('second tranche');
});

it('demands a reason before the state may suspend a project', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ProjectView::class, ['ulid' => $this->road->ulid])
        ->call('startTransition', ProjectStatus::Suspended->value)
        ->set('transitionReason', 'stalled')
        ->call('confirmTransition')
        ->assertHasErrors(['transitionReason']);

    $fresh = app(CurrentTenant::class)->runAs($this->works, fn (): Project => $this->road->fresh());

    expect($fresh->status)->toBe(ProjectStatus::InProgress);
});

/* -------------------------------------------------------------------------- */
/* Closure — including the §2.1 early-closure override, which had no surface */
/* -------------------------------------------------------------------------- */

/**
 * A certified project's post-completion review window is still running (the
 * factory dates it five months out), so every close below is an EARLY close —
 * the case the override exists for.
 */
function certifiedProjectIn(Tenant $tenant, array $attributes = []): Project
{
    return app(CurrentTenant::class)->runAs(
        $tenant,
        fn (): Project => Project::factory()->certified()->create($attributes),
    );
}

it('lets a state administrator close a certified project before its review window ends', function () {
    $project = certifiedProjectIn($this->works, ['title' => 'Cold Chain Facility Upgrade']);

    Livewire::actingAs($this->stateAdmin)
        ->test(ProjectView::class, ['ulid' => $project->ulid])
        ->call('startTransition', ProjectStatus::Closed->value)
        ->set('transitionReason', 'Facility handed over to the federal agency; state monitoring ends here.')
        ->call('confirmTransition')
        ->assertHasNoErrors();

    $fresh = app(CurrentTenant::class)->runAs($this->works, fn (): Project => $project->fresh());

    expect($fresh->status)->toBe(ProjectStatus::Closed);
});

it('demands a reason for an early closure — the reason IS the override', function () {
    $project = certifiedProjectIn($this->works);

    $component = Livewire::actingAs($this->stateAdmin)
        ->test(ProjectView::class, ['ulid' => $project->ulid])
        ->call('startTransition', ProjectStatus::Closed->value);

    // The screen has to say so up front, not only reject afterwards.
    expect($component->instance()->availableTransitions())
        ->toBe([['status' => ProjectStatus::Closed, 'needsReason' => true]]);

    $component->set('transitionReason', '')
        ->call('confirmTransition')
        ->assertHasErrors(['transitionReason']);

    $fresh = app(CurrentTenant::class)->runAs($this->works, fn (): Project => $project->fresh());

    expect($fresh->status)->toBe(ProjectStatus::Certified);
});

it('puts the override reason on the entity’s own timeline, not just in a log', function () {
    $project = certifiedProjectIn($this->works);

    Livewire::actingAs($this->stateAdmin)
        ->test(ProjectView::class, ['ulid' => $project->ulid])
        ->call('startTransition', ProjectStatus::Closed->value)
        ->set('transitionReason', 'Successor phase cancelled; no further monitoring is planned.')
        ->call('confirmTransition')
        ->assertHasNoErrors();

    $event = app(CurrentTenant::class)->runAs(
        $this->works,
        fn () => $project->statusEvents()->where('to_status', ProjectStatus::Closed)->first(),
    );

    expect($event)->not->toBeNull()
        ->and($event->tenant_id)->toBe($this->works->id)
        ->and($event->actor_id)->toBe($this->stateAdmin->id)
        ->and($event->reason)->toContain('Successor phase cancelled');
});

it('asks for no reason once the review window has actually run out', function () {
    // Nothing to override here: the window closed a month ago, so closure is
    // the ordinary end of the record and demanding a justification would be
    // ceremony.
    $project = certifiedProjectIn($this->works, ['post_completion_review_due_at' => now()->subMonth()->toDateString()]);

    $component = Livewire::actingAs($this->stateAdmin)
        ->test(ProjectView::class, ['ulid' => $project->ulid]);

    expect($component->instance()->availableTransitions())
        ->toBe([['status' => ProjectStatus::Closed, 'needsReason' => false]]);

    $component->call('startTransition', ProjectStatus::Closed->value)
        ->set('transitionReason', '')
        ->call('confirmTransition')
        ->assertHasNoErrors();

    $fresh = app(CurrentTenant::class)->runAs($this->works, fn (): Project => $project->fresh());

    expect($fresh->status)->toBe(ProjectStatus::Closed);
});

/* -------------------------------------------------------------------------- */
/* Cancellation */
/* -------------------------------------------------------------------------- */

it('cancels a project state-side, with the reason on the record', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ProjectView::class, ['ulid' => $this->road->ulid])
        ->call('startTransition', ProjectStatus::Cancelled->value)
        ->set('transitionReason', 'Procurement review found the award irregular; the works are abandoned.')
        ->call('confirmTransition')
        ->assertHasNoErrors();

    [$fresh, $event] = app(CurrentTenant::class)->runAs($this->works, fn (): array => [
        $this->road->fresh(),
        $this->road->statusEvents()->where('to_status', ProjectStatus::Cancelled)->first(),
    ]);

    expect($fresh->status)->toBe(ProjectStatus::Cancelled)
        ->and($event?->reason)->toContain('Procurement review')
        ->and($event?->tenant_id)->toBe($this->works->id);
});

it('refuses to cancel a project on a one-word reason', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(ProjectView::class, ['ulid' => $this->road->ulid])
        ->call('startTransition', ProjectStatus::Cancelled->value)
        ->set('transitionReason', 'stalled')
        ->call('confirmTransition')
        ->assertHasErrors(['transitionReason']);

    $fresh = app(CurrentTenant::class)->runAs($this->works, fn (): Project => $this->road->fresh());

    expect($fresh->status)->toBe(ProjectStatus::InProgress);
});

it('offers the state no intervention the machine forbids, on an already-closed project', function () {
    $project = app(CurrentTenant::class)->runAs(
        $this->works,
        fn (): Project => Project::factory()->closed()->create(),
    );

    $component = Livewire::actingAs($this->stateAdmin)
        ->test(ProjectView::class, ['ulid' => $project->ulid]);

    expect($component->instance()->availableTransitions())->toBe([]);

    $component->call('startTransition', ProjectStatus::Cancelled->value);

    $fresh = app(CurrentTenant::class)->runAs($this->works, fn (): Project => $project->fresh());

    expect($fresh->status)->toBe(ProjectStatus::Closed);
});

it('lets an executive viewer read a project but never intervene in one', function () {
    $component = Livewire::actingAs($this->execViewer)
        ->test(ProjectView::class, ['ulid' => $this->road->ulid])
        ->assertOk()
        ->assertSee('Township Road Rehabilitation');

    // Read-wide, write-nothing: the design gives ExecutiveViewer
    // `oversight.portfolio.view` but none of projects.suspend|close|cancel, so
    // the screen must offer no intervention at all.
    expect($component->instance()->availableTransitions())->toBe([]);

    $component->call('startTransition', ProjectStatus::Suspended->value)
        ->assertForbidden();

    $fresh = app(CurrentTenant::class)->runAs($this->works, fn (): Project => $this->road->fresh());

    expect($fresh->status)->toBe(ProjectStatus::InProgress);
});

/* -------------------------------------------------------------------------- */
/* Vendor registry */
/* -------------------------------------------------------------------------- */

it('renders the vendor registry for an oversight admin', function () {
    Contractor::factory()->create(['name' => 'Harmony Civil Works Ltd']);

    // The registry is global, but the contract COUNT beside each firm is not:
    // contracts are tenant-owned, so anything counting them has to say whose
    // side of the register it is reporting from.
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/contractors'))
        ->assertOk()
        ->assertSee('Harmony Civil Works Ltd');
});

it('bars a firm from new awards state-wide, with the reason on the record', function () {
    $firm = Contractor::factory()->create([
        'name' => 'Rayfield Energy Systems',
        'is_blacklisted' => false,
    ]);

    Livewire::actingAs($this->stateAdmin)
        ->test(ContractorRegistry::class)
        ->call('confirmBlacklist', $firm->id)
        ->set('blacklistReason', 'Abandoned two sites after collecting mobilisation.')
        ->call('applyBlacklist')
        ->assertHasNoErrors();

    expect($firm->fresh()->is_blacklisted)->toBeTrue()
        ->and($firm->fresh()->blacklist_reason)->toContain('Abandoned two sites');
});

it('refuses to bar a firm on a one-word reason', function () {
    $firm = Contractor::factory()->create(['is_blacklisted' => false]);

    Livewire::actingAs($this->stateAdmin)
        ->test(ContractorRegistry::class)
        ->call('confirmBlacklist', $firm->id)
        ->set('blacklistReason', 'bad')
        ->call('applyBlacklist')
        ->assertHasErrors(['blacklistReason']);

    expect($firm->fresh()->is_blacklisted)->toBeFalse();
});

it('lifts a blacklisting, clearing the bar but not the audit trail', function () {
    $firm = Contractor::factory()->create([
        'name' => 'Harmony Civil Works Ltd',
        'is_blacklisted' => true,
        'blacklist_reason' => 'Abandoned two sites after collecting mobilisation.',
    ]);

    Livewire::actingAs($this->stateAdmin)
        ->test(ContractorRegistry::class)
        ->call('confirmBlacklist', $firm->id, true)
        ->set('blacklistReason', 'Remediation completed and verified on site.')
        ->call('applyBlacklist')
        ->assertHasNoErrors();

    expect($firm->fresh()->is_blacklisted)->toBeFalse()
        ->and($firm->fresh()->blacklist_reason)->toBeNull();

    // blacklist_reason is cleared on lift, so the "why" survives only in the
    // activity log — losing it would leave a clean-looking firm and no history.
    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'contractors',
        'subject_id' => $firm->id,
        'description' => 'blacklist_lifted',
    ]);
});

/* -------------------------------------------------------------------------- */
/* The gate */
/* -------------------------------------------------------------------------- */

/**
 * An MDA administrator is the strongest workspace role there is, and none of
 * that authority exists outside their own subdomain. One case per route so a
 * regression names the screen that let them in.
 */
it('refuses the state portfolio to a workspace role', function () {
    $this->actingAs($this->mdaAdmin)
        ->get(oversightUrl('/portfolio'))
        ->assertForbidden();
});

it('refuses the entity drill-down to a workspace role', function () {
    $this->actingAs($this->mdaAdmin)
        ->get(oversightUrl('/portfolio/works'))
        ->assertForbidden();
});

it('refuses the state project record to a workspace role', function () {
    $this->actingAs($this->mdaAdmin)
        ->get(oversightUrl('/projects/'.$this->road->ulid))
        ->assertForbidden();
});

it('refuses the vendor registry to a workspace role', function () {
    $this->actingAs($this->mdaAdmin)
        ->get(oversightUrl('/contractors'))
        ->assertForbidden();
});

it('grants no state authority over the vendor registry to a workspace role', function () {
    $mdaAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    // Belt and braces behind the route gate: with no tenant bound — the only
    // context this component ever runs in — a workspace permission answers no.
    actingWithoutTenant();

    Livewire::actingAs($mdaAdmin->fresh())
        ->test(ContractorRegistry::class)
        ->assertForbidden();
});
