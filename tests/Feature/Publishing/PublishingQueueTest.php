<?php

/**
 * The two publishing gates: an MDA's own, and the state's.
 *
 * What makes this screen worth testing rather than eyeballing is that the
 * decision and the consequence live in different places. The queue is where a
 * civil servant decides; the portal is where a citizen reads. These tests
 * assert the two agree — the row shows the PAYLOAD (the same projection the
 * portal renders, never the Eloquent record), a publish really does put the
 * project on the public surface, and a withdrawal really does take it off.
 *
 * Every screen is rendered over real HTTP on the real subdomain first. The
 * oversight gate in particular is middleware plus a component check, and
 * Livewire::test() would skip the middleware entirely.
 */

use App\Enums\ContractStatus;
use App\Enums\Role;
use App\Livewire\Oversight\Publishing\PublishingQueue as StateQueue;
use App\Livewire\Tenant\Publishing\PublishingQueue as WorkspaceQueue;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();

    $this->current = app(CurrentTenant::class);
    $this->lga = Lga::factory()->create(['name' => 'Ilorin West']);

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $this->current->runAs($this->works, function (): void {
        $this->worksAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
        $this->worksOfficer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

        $this->roads = Project::factory()->ongoing()->create([
            'title' => 'Township Road Rehabilitation',
            'reference' => 'WKS-2026-001',
            'budget_code' => 'BC-WKS-INTERNAL',
            // `contract_value_total` is a CACHE whose source of truth is
            // `contracts`, and the factory's ongoing() state fabricates it
            // without writing a contract row. Pinning both keeps the figure
            // this test asserts the same one the payload reads.
            'contract_value_total' => '450000000.00',
            'expenditure_to_date' => '180000000.00',
        ]);

        ProjectLocation::factory()->primary()->at($this->lga)->create(['project_id' => $this->roads->id]);

        Contract::factory()->forProject($this->roads)->create([
            'contractor_id' => Contractor::factory()->create(['name' => 'Riverside Civil Works Ltd'])->id,
            'sum' => Money::fromDecimalString('450000000.00'),
            'created_by_id' => $this->worksAdmin->id,
            'status' => ContractStatus::Active,
        ]);

        // Neither of these may ever be offered: a plan reads as a commitment,
        // and an abandoned project reads as one being delivered.
        $this->draft = Project::factory()->draft()->create(['title' => 'Draft Perimeter Fencing']);
        $this->cancelled = Project::factory()->cancelled()->create(['title' => 'Cancelled Bridge Approach']);
    });

    $this->current->runAs($this->health, function (): void {
        $this->healthAdmin = memberOf(User::factory()->create(), $this->health, Role::MdaAdmin);

        $this->clinic = Project::factory()->completed()->create([
            'title' => 'Cottage Hospital Rewiring',
            'reference' => 'HLT-2026-007',
        ]);
    });

    $this->current->forget();

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->execViewer = userWithRole(Role::ExecutiveViewer);
});

/* -------------------------------------------------------------------------- */
/* The MDA queue */
/* -------------------------------------------------------------------------- */

it('renders the MDA publishing queue over real HTTP, showing what would become public', function () {
    $this->actingAs($this->worksAdmin)
        ->get(tenantUrl($this->works, '/publishing'))
        ->assertOk()
        ->assertSeeLivewire(WorkspaceQueue::class)
        ->assertSee('Publishing')
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('WKS-2026-001')
        // The payload's formatted money, not a raw decimal column.
        ->assertSee(Money::fromDecimalString('450000000.00')->format())
        ->assertSee('Not published')
        // …and not one field the payload withholds.
        ->assertDontSee('BC-WKS-INTERNAL');
});

it('never offers a draft or a cancelled project for publication', function () {
    $this->actingAs($this->worksAdmin)
        ->get(tenantUrl($this->works, '/publishing'))
        ->assertOk()
        ->assertDontSee('Draft Perimeter Fencing')
        ->assertDontSee('Cancelled Bridge Approach');
});

it('confines the MDA queue to the workspace that opened it', function () {
    $this->actingAs($this->worksAdmin)
        ->get(tenantUrl($this->works, '/publishing'))
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertDontSee('Cottage Hospital Rewiring');

    $this->actingAs($this->healthAdmin)
        ->get(tenantUrl($this->health, '/publishing'))
        ->assertOk()
        ->assertSee('Cottage Hospital Rewiring')
        ->assertDontSee('Township Road Rehabilitation');
});

it('cannot publish another MDA\'s project even with its identifier in hand', function () {
    actingOnTenant($this->works);

    Livewire::actingAs($this->worksAdmin)
        ->test(WorkspaceQueue::class)
        ->call('publish', $this->clinic->ulid);

    // TenantScope is what makes the other ministry's ULID resolve to nothing.
    expect($this->current->runAs(
        $this->health,
        fn () => Project::query()->whereKey($this->clinic->id)->firstOrFail()->published_at,
    ))->toBeNull();
});

it('puts a published project on the public portal, and takes it off again', function () {
    actingOnTenant($this->works);

    Livewire::actingAs($this->worksAdmin)
        ->test(WorkspaceQueue::class)
        ->call('publish', $this->roads->ulid)
        ->assertHasNoErrors();

    actingWithoutTenant();

    $this->get(portalUrl('/projects/'.$this->roads->ulid))
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Riverside Civil Works Ltd');

    $this->get(portalUrl('/projects'))->assertOk()->assertSee('Township Road Rehabilitation');

    actingOnTenant($this->works);

    Livewire::actingAs($this->worksAdmin)
        ->test(WorkspaceQueue::class)
        ->call('confirmWithdraw', $this->roads->ulid)
        ->set('reason', 'The published contract value predates an approved variation.')
        ->call('withdraw')
        ->assertHasNoErrors();

    actingWithoutTenant();

    $this->get(portalUrl('/projects/'.$this->roads->ulid))->assertNotFound();
    $this->get(portalUrl('/projects'))->assertOk()->assertDontSee('Township Road Rehabilitation');
});

it('will not withdraw without a stated reason', function () {
    actingOnTenant($this->works);

    Livewire::actingAs($this->worksAdmin)
        ->test(WorkspaceQueue::class)
        ->call('publish', $this->roads->ulid)
        ->call('confirmWithdraw', $this->roads->ulid)
        ->set('reason', '')
        ->call('withdraw')
        ->assertHasErrors(['reason' => 'required']);

    // "Why did the state take this down" is the only question that follows.
    expect(Project::query()->whereKey($this->roads->id)->firstOrFail()->published_at)->not->toBeNull();
});

it('previews the exact payload publication would expose', function () {
    actingOnTenant($this->works);

    Livewire::actingAs($this->worksAdmin)
        ->test(WorkspaceQueue::class)
        ->call('showPreview', $this->roads->ulid)
        ->assertSee('Exactly what the public would see')
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Riverside Civil Works Ltd')
        ->assertSee('Ilorin West')
        // The preview is the whitelist itself, so what it withholds is what
        // publication withholds.
        ->assertDontSee('BC-WKS-INTERNAL');
});

it('refuses publishing authority to an M&E officer', function () {
    actingOnTenant($this->works);

    // The officer may READ the queue (they hold projects.view) but the publish
    // is refused inside the Action, which is where the authority lives.
    Livewire::actingAs($this->worksOfficer)
        ->test(WorkspaceQueue::class)
        ->call('publish', $this->roads->ulid)
        ->assertForbidden();

    expect(Project::query()->whereKey($this->roads->id)->firstOrFail()->published_at)->toBeNull();
});

/* -------------------------------------------------------------------------- */
/* The state queue */
/* -------------------------------------------------------------------------- */

it('renders the state publishing queue over real HTTP across every entity', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/publishing'))
        ->assertOk()
        ->assertSeeLivewire(StateQueue::class)
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Cottage Hospital Rewiring')
        ->assertSee('Ministry of Works')
        ->assertSee('Ministry of Health')
        ->assertDontSee('BC-WKS-INTERNAL');
});

it('publishes across entities from the state queue', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(StateQueue::class)
        ->set('selected', [$this->roads->ulid, $this->clinic->ulid])
        ->call('publish')
        ->assertHasNoErrors();

    $this->get(portalUrl('/projects'))
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Cottage Hospital Rewiring');
});

it('refuses the state queue to a read-only oversight role', function () {
    // The role middleware admits ExecutiveViewer to the surface; deciding what
    // the public sees is a different authority, and the component says so.
    $this->actingAs($this->execViewer)
        ->get(oversightUrl('/publishing'))
        ->assertForbidden();

    Livewire::actingAs($this->execViewer)
        ->test(StateQueue::class)
        ->assertForbidden();
});

it('refuses the state queue to a workspace admin, who publishes only their own work', function () {
    $this->actingAs($this->worksAdmin)
        ->get(oversightUrl('/publishing'))
        ->assertForbidden();

    $this->current->forget();

    Livewire::actingAs(User::query()->whereKey($this->worksAdmin->id)->firstOrFail())
        ->test(StateQueue::class)
        ->assertForbidden();
});

it('filters the state queue by entity and by publication state', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(StateQueue::class)
        ->set('tenantId', (string) $this->health->id)
        ->assertSee('Cottage Hospital Rewiring')
        ->assertDontSee('Township Road Rehabilitation')
        ->set('tenantId', '')
        ->set('state', 'published')
        ->assertDontSee('Cottage Hospital Rewiring')
        ->assertDontSee('Township Road Rehabilitation');
});
