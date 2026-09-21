<?php

/**
 * PUBLISHED-ONLY — the second of the three properties routes/portal.php states
 * and the one that would end a government contract if it broke.
 *
 * An unpublished project must be absent from EVERY portal surface, not hidden
 * on most of them: the detail page, the browser, the map pins, the landing-page
 * counters and the photo route are each asserted separately, because "hidden in
 * the list but reachable by URL" is exactly how this class of leak ships.
 *
 * And the whitelist: a column that is not on PublicProjectPayload::FIELDS must
 * not appear in portal output at all. The budget code is a key into the state's
 * finance system; the officers' names are a safety decision. Both are seeded
 * with distinctive values here and hunted for across every public page.
 */

use App\Actions\Portal\BuildPortalSummary;
use App\Actions\Portal\ListPublishedProjectPins;
use App\Actions\Projects\UnpublishProject;
use App\Enums\ContractStatus;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Str;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(['name' => 'Adaeze Okonkwo']), $this->works, Role::MdaAdmin);
    $this->lga = Lga::factory()->create(['name' => 'Ilorin West']);

    $this->published = project('Completed Primary Health Centre', 'PRJ-20001', $this->lga);
    $this->hidden = project('Secret Perimeter Fencing Works', 'PRJ-20002', $this->lga, [
        'latitude' => '9.111111',
        'longitude' => '5.222222',
    ]);

    $this->published->forceFill([
        'published_at' => now()->subDay(),
        'published_by_id' => $this->admin->id,
    ])->save();

    actingWithoutTenant();
});

function project(string $title, string $reference, Lga $lga, array $coordinates = []): Project
{
    $project = Project::factory()->ongoing()->create([
        'title' => $title,
        'reference' => $reference,
        // Deliberately distinctive and deliberately NOT on the whitelist.
        'budget_code' => 'BC-'.substr($reference, -4).'-INTERNAL',
        'manager_id' => User::factory()->create(['name' => 'Ibrahim Confidential-Manager'])->id,
    ]);

    ProjectLocation::factory()->primary()->at($lga)->create([
        'project_id' => $project->id,
        'site_name' => 'Main Site',
        'latitude' => $coordinates['latitude'] ?? '8.496400',
        'longitude' => $coordinates['longitude'] ?? '4.542700',
    ]);

    return $project;
}

it('answers 404 for an unpublished project, not 403', function () {
    // 404, not 403: a 403 would confirm the record exists.
    $this->get(portalUrl('/projects/'.$this->hidden->ulid))->assertNotFound();

    $this->get(portalUrl('/projects/'.$this->published->ulid))->assertOk();
});

it('keeps an unpublished project out of the browser, the map, the counters and the photo route', function () {
    $this->get(portalUrl('/projects'))
        ->assertOk()
        ->assertSee('Completed Primary Health Centre')
        ->assertDontSee('Secret Perimeter Fencing Works')
        ->assertDontSee('PRJ-20002');

    $this->get(portalUrl('/map'))
        ->assertOk()
        ->assertDontSee('Secret Perimeter Fencing Works')
        // Not merely hidden from the list: absent from the pin payload.
        ->assertDontSee('9.111111', escape: false);

    expect(collect((new ListPublishedProjectPins)())->pluck('ulid')->all())
        ->toBe([$this->published->ulid]);

    $summary = (new BuildPortalSummary)();
    expect($summary['projects'])->toBe(1);

    $this->get(portalUrl('/'))
        ->assertOk()
        ->assertDontSee('Secret Perimeter Fencing Works');

    // Media never even loads: the project fails to resolve first, so an
    // attacker guessing uuids learns nothing about an unpublished record.
    $this->get(portalUrl('/projects/'.$this->hidden->ulid.'/photos/'.Str::uuid()))
        ->assertNotFound();
});

it('removes a project from every portal surface the moment it is withdrawn', function () {
    $this->get(portalUrl('/projects/'.$this->published->ulid))->assertOk();
    expect((new BuildPortalSummary)()['projects'])->toBe(1);

    actingOnTenant($this->works);
    $project = Project::query()->whereKey($this->published->id)->firstOrFail();
    (new UnpublishProject)($project, $this->admin, 'The published contract value predates an approved variation.');
    actingWithoutTenant();

    // No cache to wait for and no second switch to remember.
    $this->get(portalUrl('/projects/'.$this->published->ulid))->assertNotFound();

    $this->get(portalUrl('/projects'))
        ->assertOk()
        ->assertDontSee('Completed Primary Health Centre');

    $this->get(portalUrl('/map'))
        ->assertOk()
        ->assertDontSee('Completed Primary Health Centre');

    expect((new ListPublishedProjectPins)())->toBe([])
        ->and((new BuildPortalSummary)()['projects'])->toBe(0);

    $this->get(portalUrl('/projects/'.$this->published->ulid.'/photos/'.Str::uuid()))
        ->assertNotFound();
});

it('never prints a field that is missing from the payload whitelist', function () {
    actingOnTenant($this->works);

    $contractor = Contractor::factory()->create([
        'name' => 'Riverside Civil Works Ltd',
        'rc_number' => 'RC998877',
        'contact_email' => 'private.desk@riverside.test',
    ]);

    Contract::factory()->forProject($this->published)->create([
        'contractor_id' => $contractor->id,
        'sum' => Money::fromDecimalString('450000000.00'),
        'created_by_id' => $this->admin->id,
        'status' => ContractStatus::Active,
    ]);

    actingWithoutTenant();

    $withheld = [
        'BC-0001-INTERNAL',            // budget_code — a key into the finance system
        'Ibrahim Confidential-Manager', // manager_id — naming the responsible officer
        'Adaeze Okonkwo',               // published_by_id — likewise
        'RC998877',                     // the vendor's registration
        'private.desk@riverside.test',  // the vendor's contact file
    ];

    foreach (['/', '/projects', '/projects/'.$this->published->ulid, '/map'] as $path) {
        $response = $this->get(portalUrl($path))->assertOk();

        foreach ($withheld as $secret) {
            $response->assertDontSee($secret);
        }
    }

    // What IS whitelisted still reaches the page — otherwise this test would
    // pass on a blank portal.
    $this->get(portalUrl('/projects/'.$this->published->ulid))
        ->assertOk()
        ->assertSee('Riverside Civil Works Ltd')
        ->assertSee('Completed Primary Health Centre');
});
