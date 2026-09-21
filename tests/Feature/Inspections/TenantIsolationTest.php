<?php

/**
 * The mandatory isolation proof for every tenant-owned model this module adds
 * — SiteInspection, SiteInspectionResponse and SiteInspectionEvent: records in
 * two workspaces, and subdomain A never seeing B's, in the list, on the detail
 * screen and in the export.
 *
 * A tenancy leak in a government system is a security incident, so the
 * assertions are deliberately paranoid: the same ULID is tried from the wrong
 * subdomain, the primary key is tried directly against the scope, and the
 * export is read as bytes rather than trusted to match the screen.
 *
 * The checklist TEMPLATE is deliberately absent from all of this: it is global
 * reference data with no tenant_id at all, which is what makes the cross-MDA
 * question the checklist exists for answerable. What the two workspaces must
 * not share is their ANSWERS.
 */

use App\Enums\Role;
use App\Livewire\Oversight\Inspections\InspectionBoard;
use App\Livewire\Tenant\Inspections\InspectionIndex;
use App\Models\InspectionChecklistTemplate;
use App\Models\Project;
use App\Models\SiteInspection;
use App\Models\SiteInspectionEvent;
use App\Models\SiteInspectionResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    Storage::fake('documents');
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $this->current = app(CurrentTenant::class);

    // One state-wide instrument, answered by both ministries. Global reference
    // data by design — the answers below are what must stay apart.
    $this->template = InspectionChecklistTemplate::factory()->withItems(1)->create();

    $this->current->runAs($this->works, function (): void {
        $this->worksProject = Project::factory()->ongoing()->create([
            'title' => 'Township Road Rehabilitation',
            'reference' => 'WKS/2026/001',
        ]);

        $this->worksInspector = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

        $this->worksInspection = SiteInspection::factory()
            ->forProject($this->worksProject)
            ->ledBy($this->worksInspector)
            ->usingTemplate($this->template)
            ->submitted($this->worksInspector)
            ->create();

        SiteInspectionResponse::factory()
            ->forInspection($this->worksInspection)
            ->forItem($this->template->items->first())
            ->finding('Blinding poured onto uncompacted fill at the Works site.')
            ->create();

        SiteInspectionEvent::factory()
            ->forInspection($this->worksInspection)
            ->by($this->worksInspector)
            ->create();
    });

    $this->current->runAs($this->health, function (): void {
        $this->healthProject = Project::factory()->ongoing()->create([
            'title' => 'Cottage Hospital Upgrade',
            'reference' => 'HLT/2026/001',
        ]);

        $this->healthInspector = memberOf(User::factory()->create(), $this->health, Role::FieldMonitor);

        $this->healthInspection = SiteInspection::factory()
            ->forProject($this->healthProject)
            ->ledBy($this->healthInspector)
            ->usingTemplate($this->template)
            ->submitted($this->healthInspector)
            ->create();

        SiteInspectionResponse::factory()
            ->forInspection($this->healthInspection)
            ->forItem($this->template->items->first())
            ->finding('Ward ceiling panels water-stained at the Health site.')
            ->create();

        SiteInspectionEvent::factory()
            ->forInspection($this->healthInspection)
            ->by($this->healthInspector)
            ->create();
    });

    $this->current->forget();
});

/** Run the export and return the bytes it streams, not the Livewire payload. */
function exportedInspectionCsv(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

it('scopes the field desk to the workspace it is opened on', function () {
    actingOnTenant($this->works);
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    $this->actingAs($officer)
        ->get(tenantUrl($this->works, '/inspections'))
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertDontSee('Cottage Hospital Upgrade')
        ->assertDontSee('HLT/2026/001');
});

it('shows the other workspace only its own, from its own subdomain', function () {
    actingOnTenant($this->health);
    $officer = memberOf(User::factory()->create(), $this->health, Role::MeOfficer);

    $this->actingAs($officer)
        ->get(tenantUrl($this->health, '/inspections'))
        ->assertOk()
        ->assertSee('Cottage Hospital Upgrade')
        ->assertDontSee('Township Road Rehabilitation');
});

it('404s a foreign inspection ULID on the detail screen', function () {
    actingOnTenant($this->works);
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    // Through the route, because the route-model binder is what applies the
    // scope. A leak here would be a 200 rendering another ministry's findings.
    $this->actingAs($officer)
        ->get(tenantUrl($this->works, '/inspections/'.$this->healthInspection->ulid))
        ->assertNotFound();
});

it('404s a foreign inspection ULID on the conduct form', function () {
    actingOnTenant($this->works);
    $inspector = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    $this->actingAs($inspector)
        ->get(tenantUrl($this->works, '/inspections/'.$this->healthInspection->ulid.'/conduct'))
        ->assertNotFound();
});

it('404s an inspection that belongs to the subdomain it is asked for from the wrong one', function () {
    // The mirror case: the same ULID is a 200 on its own subdomain and a 404
    // on the neighbour's. Asserting only one direction would pass against a
    // binder that resolves nothing at all.
    $healthOfficer = memberOf(User::factory()->create(), $this->health, Role::MeOfficer);
    $worksOfficer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    actingOnTenant($this->health);

    $this->actingAs($healthOfficer)
        ->get(tenantUrl($this->health, '/inspections/'.$this->healthInspection->ulid))
        ->assertOk()
        ->assertSee('Cottage Hospital Upgrade');

    actingOnTenant($this->works);

    $this->actingAs($worksOfficer)
        ->get(tenantUrl($this->works, '/inspections/'.$this->healthInspection->ulid))
        ->assertNotFound();
});

it('keeps a foreign visit out of the export, not merely off the screen', function () {
    actingOnTenant($this->works);
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    $csv = exportedInspectionCsv(
        Livewire::actingAs($officer)->test(InspectionIndex::class)->instance()->export(),
    );

    expect($csv)->toContain('Township Road Rehabilitation')
        ->and($csv)->toContain('WKS/2026/001')
        // The row that must never be in this file, by title AND by reference —
        // a leak that only drops the title is still a leak.
        ->and($csv)->not->toContain('Cottage Hospital Upgrade')
        ->and($csv)->not->toContain('HLT/2026/001');
});

it('refuses to load a foreign inspection, response or ledger row even by primary key', function () {
    actingOnTenant($this->works);

    // The global scope, asserted directly: everything above depends on it.
    expect(SiteInspection::query()->find($this->healthInspection->id))->toBeNull()
        ->and(SiteInspection::query()->count())->toBe(1)
        ->and(SiteInspectionResponse::query()->count())->toBe(1)
        ->and(SiteInspectionEvent::query()->count())->toBe(1)
        ->and(SiteInspectionResponse::query()->where('site_inspection_id', $this->healthInspection->id)->exists())
        ->toBeFalse()
        ->and(SiteInspectionEvent::query()->where('site_inspection_id', $this->healthInspection->id)->exists())
        ->toBeFalse();
});

it('stamps each record with the workspace that created it', function () {
    expect($this->worksInspection->tenant_id)->toBe($this->works->id)
        ->and($this->healthInspection->tenant_id)->toBe($this->health->id);

    $this->current->runAs($this->works, function (): void {
        expect(SiteInspectionResponse::query()->sole()->tenant_id)->toBe($this->works->id)
            ->and(SiteInspectionEvent::query()->sole()->tenant_id)->toBe($this->works->id);
    });

    $this->current->runAs($this->health, function (): void {
        expect(SiteInspectionResponse::query()->sole()->tenant_id)->toBe($this->health->id)
            ->and(SiteInspectionEvent::query()->sole()->tenant_id)->toBe($this->health->id);
    });
});

it('keeps one workspace’s answers off the other’s detail screen, though the instrument is shared', function () {
    actingOnTenant($this->works);
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    $this->actingAs($officer)
        ->get(tenantUrl($this->works, '/inspections/'.$this->worksInspection->ulid))
        ->assertOk()
        ->assertSee('Blinding poured onto uncompacted fill at the Works site.')
        ->assertDontSee('Ward ceiling panels water-stained at the Health site.');
});

it('shows both workspaces to state oversight, which is what the surface is for', function () {
    $stateAdmin = userWithRole(Role::StateAdmin);
    $this->current->forget();

    Livewire::actingAs($stateAdmin)
        ->test(InspectionBoard::class)
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Cottage Hospital Upgrade')
        ->assertSee('Ministry of Works')
        ->assertSee('Ministry of Health');
});
