<?php

/**
 * THE WHITELIST.
 *
 * App\Support\Publishing\PublicProjectPayload is the only code path from an
 * Eloquent attribute to a citizen's browser, and this file is what keeps it
 * that way. The first test asserts the payload's key set EXACTLY: add a column
 * to `projects` next quarter — a contractor's bank details, an internal risk
 * note, the officer who flagged a firm — and nothing widens, because widening
 * requires a deliberate edit to that class and this test says so out loud.
 *
 * The rest proves the publishable-status guard, which is the other half of the
 * decision: what may be published at all.
 */

use App\Actions\Publishing\PublishProjectToPortal;
use App\Enums\ContractStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Exceptions\Publishing\PublishingRuleViolation;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use App\Support\Publishing\PublicProjectPayload;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
});

function payloadFor(Project $project): array
{
    $loaded = Project::query()->with(PublicProjectPayload::RELATIONS)->whereKey($project->id)->firstOrFail();

    return PublicProjectPayload::for($loaded)->toArray();
}

it('produces exactly the declared field set and nothing else', function () {
    $project = Project::factory()->ongoing()->create();

    $payload = payloadFor($project);

    expect(array_keys($payload))->toBe(PublicProjectPayload::FIELDS);
});

it('withholds every column the class says it withholds', function () {
    $project = Project::factory()->ongoing()->create([
        'budget_code' => 'BC-1234',
        'manager_id' => User::factory()->create()->id,
    ]);

    $withheld = [
        'id', 'tenant_id', 'budget_allocation', 'budget_code', 'created_by_id',
        'manager_id', 'published_by_id', 'reporting_frequency', 'status_changed_at',
        'mid_term_flagged_at', 'post_completion_review_due_at', 'deleted_at',
    ];

    foreach ($withheld as $column) {
        expect(payloadFor($project))->not->toHaveKey($column);
    }
});

it('publishes the contractor\'s name and nothing else from the vendor file', function () {
    $contractor = Contractor::factory()->create([
        'name' => 'Riverside Civil Works Ltd',
        'rc_number' => 'RC998877',
        'contact_email' => 'desk@riverside.test',
    ]);

    $project = Project::factory()->ongoing()->create();

    Contract::factory()->forProject($project)->create([
        'contractor_id' => $contractor->id,
        'sum' => Money::fromDecimalString('450000000.00'),
        'created_by_id' => $this->admin->id,
        'status' => ContractStatus::Active,
    ]);

    $payload = payloadFor($project);

    expect($payload['contractor'])->toBe('Riverside Civil Works Ltd')
        ->and(json_encode($payload))->not->toContain('RC998877')
        ->not->toContain('desk@riverside.test');
});

it('keeps coordinates as strings so they round-trip unchanged', function () {
    $project = Project::factory()->ongoing()->create();

    $location = ProjectLocation::factory()->primary()->at(Lga::factory()->create(['name' => 'Ilorin West']))->create([
        'project_id' => $project->id,
        'site_name' => 'Township Section',
        'latitude' => '8.496400',
        'longitude' => '4.542700',
    ]);

    $stored = ProjectLocation::query()->whereKey($location->id)->firstOrFail();
    $payload = payloadFor($project);

    // Strings, never floats: coordinates are printed on inspection reports and
    // must come out of the payload exactly as the column holds them.
    expect($payload['primary_location']['latitude'])->toBeString()->toBe($stored->latitude)
        ->and($payload['primary_location']['longitude'])->toBeString()->toBe($stored->longitude)
        ->and((float) $payload['primary_location']['latitude'])->toBe(8.4964)
        ->and($payload['primary_location']['lga'])->toBe('Ilorin West');
});

it('filters a query to published rows in one place', function () {
    $published = Project::factory()->ongoing()->create();
    $published->forceFill(['published_at' => now(), 'published_by_id' => $this->admin->id])->save();

    Project::factory()->ongoing()->create();

    $visible = PublicProjectPayload::publishedOnly(Project::query())->pluck('id')->all();

    expect($visible)->toBe([$published->id]);
});

/* -------------------------------------------------------------------------- */
/* What may be published at all */
/* -------------------------------------------------------------------------- */

it('publishes every status the gate declares publishable', function (ProjectStatus $status) {
    $project = Project::factory()->ongoing()->create();
    $project->forceFill(['status' => $status])->save();

    (new PublishProjectToPortal)($project, $this->admin);

    expect(Project::query()->whereKey($project->id)->firstOrFail()->published_at)->not->toBeNull();
})->with(fn () => array_map(
    fn (ProjectStatus $status): array => [$status],
    PublishProjectToPortal::PUBLISHABLE_STATUSES,
));

it('refuses to publish a draft project', function () {
    $project = Project::factory()->draft()->create();

    // A draft has no award, no contractor and no attested progress: its figures
    // are a plan, and a plan on a transparency portal reads as a commitment.
    expect(fn () => (new PublishProjectToPortal)($project, $this->admin))
        ->toThrow(PublishingRuleViolation::class);

    expect(Project::query()->whereKey($project->id)->firstOrFail()->published_at)->toBeNull();
});

it('refuses to publish a cancelled project', function () {
    $project = Project::factory()->cancelled()->create();

    expect(fn () => (new PublishProjectToPortal)($project, $this->admin))
        ->toThrow(PublishingRuleViolation::class);
});

it('refuses to publish the same project twice', function () {
    $project = Project::factory()->ongoing()->create();

    (new PublishProjectToPortal)($project, $this->admin);

    expect(fn () => (new PublishProjectToPortal)($project, $this->admin))
        ->toThrow(ProjectRuleViolation::class);
});

it('refuses publication to a workspace role without publishing authority', function () {
    $project = Project::factory()->ongoing()->create();

    // `projects.publish` is seeded to MdaAdmin and the state roles; an M&E
    // officer files returns, they do not decide what the public sees.
    expect(fn () => (new PublishProjectToPortal)($project, $this->officer))
        ->toThrow(AuthorizationException::class);

    expect(Project::query()->whereKey($project->id)->firstOrFail()->published_at)->toBeNull();
});
