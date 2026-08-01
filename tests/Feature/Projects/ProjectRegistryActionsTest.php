<?php

/**
 * The registry write path: RegisterProject, UpdateProjectDetails (and the
 * certification freeze), ReviseProjectSchedule, funding splits, sites,
 * publishing and archiving — projects-module.md §1.5, §1.6, §2.3, §3.
 *
 * The freeze cases are the two halves of domain finding 3, which is the one
 * most likely to be re-broken by someone "simplifying" the check: certified
 * figures stop moving, but a certified project is NOT a locked record.
 */

use App\Actions\Projects\AddProjectLocation;
use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\PublishProject;
use App\Actions\Projects\RegisterProject;
use App\Actions\Projects\RemoveProjectLocation;
use App\Actions\Projects\ReviseProjectSchedule;
use App\Actions\Projects\SetPrimaryProjectLocation;
use App\Actions\Projects\SetProjectFundingSources;
use App\Actions\Projects\UpdateProjectDetails;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\Role;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\FundingSource;
use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectFundingSource;
use App\Models\ProjectLocation;
use App\Models\ProjectStatusEvent;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->sector = Sector::factory()->create();
    $this->lga = Lga::factory()->create();
});

function registrationAttributes(Sector $sector, array $overrides = []): array
{
    return [
        'reference' => 'PRJ-2026-0001',
        'title' => 'Rehabilitation of the township ring road',
        'description' => 'Reconstruction of 7km of failed carriageway with drainage.',
        'sector_id' => $sector->id,
        'type' => ProjectType::Capital,
        'budget_allocation' => '520000000.00',
        'start_date' => now()->addMonth()->toDateString(),
        'expected_end_date' => now()->addMonths(13)->toDateString(),
        ...$overrides,
    ];
}

it('registers a draft project in the current workspace with its site, funding and ledger row', function () {
    $donor = FundingSource::factory()->create();
    $counterpart = FundingSource::factory()->create();

    $project = (new RegisterProject)(
        $this->admin,
        registrationAttributes($this->sector),
        ['site_name' => 'Ring road — eastern section', 'lga_id' => $this->lga->id],
        [
            ['funding_source_id' => $donor->id, 'percentage' => '70.00', 'is_primary' => true],
            ['funding_source_id' => $counterpart->id, 'percentage' => '30.00'],
        ],
    );

    $location = ProjectLocation::query()->where('project_id', $project->id)->firstOrFail();

    expect($project->tenant_id)->toBe($this->works->id)
        ->and($project->status)->toBe(ProjectStatus::Draft)
        ->and($project->created_by_id)->toBe($this->admin->id)
        ->and($project->contract_value_total)->toBeNull()
        ->and($location->is_primary)->toBeTrue()
        ->and(ProjectFundingSource::query()->where('project_id', $project->id)->count())->toBe(2);

    // The ledger starts at creation, so the trail is complete from day one.
    $creation = ProjectStatusEvent::query()->where('project_id', $project->id)->firstOrFail();

    expect($creation->from_status)->toBeNull()
        ->and($creation->to_status)->toBe(ProjectStatus::Draft)
        ->and($creation->isCreation())->toBeTrue();
});

it('refuses to let a registration payload assign a status', function () {
    expect(fn () => (new RegisterProject)(
        $this->admin,
        registrationAttributes($this->sector, ['status' => ProjectStatus::Certified]),
    ))->toThrow(MassAssignmentException::class);
});

it('refuses registration to a consultant', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    expect(fn () => (new RegisterProject)($consultant->fresh(), registrationAttributes($this->sector)))
        ->toThrow(AuthorizationException::class);
});

it('refuses a funding split that adds up to more than the whole project', function () {
    $project = Project::factory()->draft()->create();

    expect(fn () => (new SetProjectFundingSources)($project, $this->admin, [
        ['funding_source_id' => FundingSource::factory()->create()->id, 'percentage' => '70.00'],
        ['funding_source_id' => FundingSource::factory()->create()->id, 'percentage' => '45.00'],
    ]))->toThrow(ProjectRuleViolation::class, 'more than 100% funded');

    expect(ProjectFundingSource::query()->where('project_id', $project->id)->count())->toBe(0);
});

it('accepts a partially identified funding plan, because that is honest', function () {
    $project = Project::factory()->draft()->create();

    (new SetProjectFundingSources)($project, $this->admin, [
        ['funding_source_id' => FundingSource::factory()->create()->id, 'percentage' => '60.00'],
    ]);

    expect(ProjectFundingSource::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('replaces the funding split rather than accumulating rows', function () {
    $project = Project::factory()->draft()->create();
    $first = FundingSource::factory()->create();
    $second = FundingSource::factory()->create();

    (new SetProjectFundingSources)($project, $this->admin, [
        ['funding_source_id' => $first->id, 'percentage' => '100.00'],
    ]);
    (new SetProjectFundingSources)($project, $this->admin, [
        ['funding_source_id' => $second->id, 'percentage' => '100.00'],
    ]);

    $rows = ProjectFundingSource::query()->where('project_id', $project->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->funding_source_id)->toBe($second->id);
});

/*
|--------------------------------------------------------------------------
| Sites — the single-primary invariant (§1.5)
|--------------------------------------------------------------------------
*/

it('makes the first site primary and keeps exactly one primary thereafter', function () {
    $project = Project::factory()->draft()->create();

    $first = (new AddProjectLocation)($project, $this->admin, ['site_name' => 'Site A', 'lga_id' => $this->lga->id]);
    $second = (new AddProjectLocation)($project, $this->admin, ['site_name' => 'Site B', 'lga_id' => $this->lga->id]);
    $third = (new AddProjectLocation)($project, $this->admin, ['site_name' => 'Site C', 'lga_id' => $this->lga->id]);

    expect($first->fresh()->is_primary)->toBeTrue()
        ->and($second->fresh()->is_primary)->toBeFalse();

    (new SetPrimaryProjectLocation)($third, $this->admin);

    $primaries = ProjectLocation::query()->where('project_id', $project->id)->where('is_primary', true)->get();

    expect($primaries)->toHaveCount(1)
        ->and($primaries->first()->id)->toBe($third->id);
});

it('promotes another site when the primary one is removed, and refuses to leave a project with none', function () {
    $project = Project::factory()->draft()->create();

    $first = (new AddProjectLocation)($project, $this->admin, ['site_name' => 'Site A', 'lga_id' => $this->lga->id]);
    $second = (new AddProjectLocation)($project, $this->admin, ['site_name' => 'Site B', 'lga_id' => $this->lga->id]);

    (new RemoveProjectLocation)($first, $this->admin);

    expect($second->fresh()->is_primary)->toBeTrue();

    expect(fn () => (new RemoveProjectLocation)($second, $this->admin))
        ->toThrow(ProjectRuleViolation::class, 'at least one site');
});

/*
|--------------------------------------------------------------------------
| Editing and the certification freeze (§2.3)
|--------------------------------------------------------------------------
*/

it('updates an ongoing project', function () {
    $project = Project::factory()->ongoing()->create();

    (new UpdateProjectDetails)($project, $this->officer, ['title' => 'Corrected project title']);

    expect($project->fresh()->title)->toBe('Corrected project title');
});

it('freezes scope, money and dates from certification onward', function (string $field, mixed $value) {
    $project = Project::factory()->certified()->create();

    expect(fn () => (new UpdateProjectDetails)($project, $this->admin, [$field => $value]))
        ->toThrow(ProjectRuleViolation::class, 'frozen from certification');
})->with([
    'the title' => ['title', 'Quietly renamed'],
    'the budget' => ['budget_allocation', '1.00'],
    'the end date' => ['expected_end_date', '2030-01-01'],
    'the sector' => ['sector_id', 999],
]);

it('still lets a certified project change what the certificate never attested to', function () {
    $project = Project::factory()->certified()->create();
    $manager = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    (new UpdateProjectDetails)($project, $this->admin, [
        'manager_id' => $manager->id,
        'reporting_frequency' => 'monthly',
    ]);

    expect($project->fresh()->manager_id)->toBe($manager->id);
});

it('keeps a closed project open to monitoring artifacts — the other half of the freeze', function () {
    $project = Project::factory()->closed()->create();

    // Indicator readings, assignments and (Phase 2) inspections all attach to
    // a closed project: 6–12 month post-completion monitoring is the point.
    $reading = IndicatorReading::factory()->create([
        'indicator_id' => Indicator::factory()->active()->forProject($project),
    ]);

    expect($reading->exists)->toBeTrue()
        ->and($reading->tenant_id)->toBe($this->works->id);

    expect(fn () => (new UpdateProjectDetails)($project, $this->admin, ['title' => 'Rewritten after the fact']))
        ->toThrow(ProjectRuleViolation::class);
});

it('revises the end date with a reason and leaves the planned date alone', function () {
    $project = Project::factory()->ongoing()->create([
        'expected_end_date' => now()->addMonths(2)->toDateString(),
    ]);
    $planned = $project->expected_end_date->toDateString();

    (new ReviseProjectSchedule)($project, $this->admin, now()->addMonths(8), 'Right-of-way dispute at chainage 2+100.');

    $project->refresh();

    expect($project->revised_end_date->toDateString())->toBe(now()->addMonths(8)->toDateString())
        ->and($project->expected_end_date->toDateString())->toBe($planned);
});

it('refuses a schedule revision without a reason, and on a frozen project', function () {
    $project = Project::factory()->ongoing()->create();

    expect(fn () => (new ReviseProjectSchedule)($project, $this->admin, now()->addYear(), '  '))
        ->toThrow(ProjectRuleViolation::class);

    $certified = Project::factory()->certified()->create();

    expect(fn () => (new ReviseProjectSchedule)($certified, $this->admin, now()->addYear(), 'Because.'))
        ->toThrow(ProjectRuleViolation::class, 'frozen');
});

/*
|--------------------------------------------------------------------------
| Publishing and archiving
|--------------------------------------------------------------------------
*/

it('publishes a project and refuses to publish it twice', function () {
    $project = Project::factory()->completed()->create();

    (new PublishProject)($project, $this->admin);

    expect($project->fresh()->published_at)->not->toBeNull()
        ->and($project->fresh()->published_by_id)->toBe($this->admin->id);

    expect(fn () => (new PublishProject)($project->fresh(), $this->admin))
        ->toThrow(ProjectRuleViolation::class, 'already published');
});

it('refuses publishing to an M&E officer, who does not decide what becomes public', function () {
    $project = Project::factory()->completed()->create();

    expect(fn () => (new PublishProject)($project, $this->officer))
        ->toThrow(AuthorizationException::class);
});

it('archives a draft or cancelled project and nothing else', function () {
    $draft = Project::factory()->draft()->create();
    $cancelled = Project::factory()->cancelled()->create();
    $ongoing = Project::factory()->ongoing()->create();

    (new ArchiveProject)($draft, $this->admin);
    (new ArchiveProject)($cancelled, $this->admin);

    expect(Project::query()->count())->toBe(1);

    expect(fn () => (new ArchiveProject)($ongoing, $this->admin))
        ->toThrow(ProjectRuleViolation::class, 'draft or cancelled');
});

it('refuses archiving to an M&E officer, who cannot delete records', function () {
    $draft = Project::factory()->draft()->create();

    expect(fn () => (new ArchiveProject)($draft, $this->officer))
        ->toThrow(AuthorizationException::class);
});
