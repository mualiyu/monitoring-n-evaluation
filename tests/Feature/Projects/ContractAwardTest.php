<?php

/**
 * Award, variation and the derived cache (projects-module.md §1.7, §3, §9.2 and
 * migration review §4/§6).
 *
 * The two things this file exists to prove:
 *  - an awarded sum cannot be rewritten by ANY path — model, Action or mass
 *    update — because the amendment register only works if superseded figures
 *    stay superseded;
 *  - `projects.contract_value_total` is a cache that always agrees with
 *    `contracts`, including after a soft delete or a termination. That column
 *    is on the executive dashboard, and drift there is the failure the design
 *    flags as most likely to bite.
 */

use App\Actions\Projects\AwardContract;
use App\Actions\Projects\RecalculateContractValueTotal;
use App\Actions\Projects\RecordContractVariation;
use App\Enums\ContractStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectStatusEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->contractor = Contractor::factory()->create(['name' => 'Riverside Civil Works Ltd']);
    $this->award = new AwardContract;
});

function contractAttributes(array $overrides = []): array
{
    return [
        'contract_number' => 'CTR-2026-001',
        'sum' => '450000000.00',
        'scope_of_works' => 'Construction of 7km township roads including drainage.',
        'award_date' => now()->subWeek()->toDateString(),
        ...$overrides,
    ];
}

it('awards a contract, refreshes the project total and moves the project out of draft', function () {
    $project = Project::factory()->draft()->create();

    $contract = ($this->award)($project, $this->contractor, $this->admin, contractAttributes());

    $project->refresh();

    expect($contract->project_id)->toBe($project->id)
        ->and($contract->tenant_id)->toBe($this->works->id)
        ->and($contract->created_by_id)->toBe($this->admin->id)
        ->and($project->status)->toBe(ProjectStatus::Awarded)
        ->and($project->contract_value_total->toDecimalString())->toBe('450000000.00')
        // the award transition is on the ledger like any other
        ->and(ProjectStatusEvent::query()->where('project_id', $project->id)->where('to_status', ProjectStatus::Awarded)->exists())
        ->toBeTrue();
});

it('adds a second contract to an already-awarded project without transitioning it again', function () {
    $project = Project::factory()->draft()->create();

    ($this->award)($project, $this->contractor, $this->admin, contractAttributes(['sum' => '100000000.00']));
    ($this->award)($project->fresh(), Contractor::factory()->create(), $this->admin, contractAttributes([
        'contract_number' => 'CTR-2026-002',
        'sum' => '50000000.00',
    ]));

    $project->refresh();

    expect($project->contract_value_total->toDecimalString())->toBe('150000000.00')
        ->and(ProjectStatusEvent::query()->where('project_id', $project->id)->count())->toBe(1);
});

it('refuses to award work to a blacklisted firm, in any workspace', function () {
    $barred = Contractor::factory()->blacklisted()->create();
    $project = Project::factory()->draft()->create();

    expect(fn () => ($this->award)($project, $barred, $this->admin, contractAttributes()))
        ->toThrow(ProjectRuleViolation::class, 'blacklisted');

    expect($project->fresh()->status)->toBe(ProjectStatus::Draft)
        ->and(Contract::query()->count())->toBe(0);
});

it('refuses to award against a certified, closed or cancelled project', function (string $state) {
    $project = Project::factory()->{$state}()->create();

    expect(fn () => ($this->award)($project, $this->contractor, $this->admin, contractAttributes()))
        ->toThrow(ProjectRuleViolation::class, 'cannot be awarded');
})->with(['certified', 'closed', 'cancelled']);

it('refuses the award to a consultant', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    $project = Project::factory()->draft()->create();

    expect(fn () => ($this->award)($project, $this->contractor, $consultant->fresh(), contractAttributes()))
        ->toThrow(AuthorizationException::class);
});

/*
|--------------------------------------------------------------------------
| Immutability of the award terms (migration review §4)
|--------------------------------------------------------------------------
*/

it('refuses to rewrite an awarded term on the model', function (string $field, mixed $value) {
    $project = Project::factory()->draft()->create();
    $contract = ($this->award)($project, $this->contractor, $this->admin, contractAttributes());

    expect(fn () => $contract->update([$field => $value]))
        ->toThrow(ProjectRuleViolation::class, 'immutable once a contract is awarded');
})->with([
    'the sum' => ['sum', '999000000.00'],
    'the award date' => ['award_date', '2020-01-01'],
    'the scope of works' => ['scope_of_works', 'Something else entirely.'],
]);

it('refuses to rewrite an awarded term through a mass update, where model events never fire', function () {
    $project = Project::factory()->draft()->create();
    ($this->award)($project, $this->contractor, $this->admin, contractAttributes());

    expect(fn () => Contract::query()->update(['sum' => '1.00']))
        ->toThrow(ProjectRuleViolation::class, 'immutable');

    expect(Contract::query()->firstOrFail()->sum->toDecimalString())->toBe('450000000.00');
});

it('still allows the fields a contract is meant to move through', function () {
    $project = Project::factory()->draft()->create();
    $contract = ($this->award)($project, $this->contractor, $this->admin, contractAttributes());

    $contract->update([
        'status' => ContractStatus::Active,
        'commencement_date' => now()->toDateString(),
    ]);

    expect($contract->fresh()->status)->toBe(ContractStatus::Active);
});

/*
|--------------------------------------------------------------------------
| Variations
|--------------------------------------------------------------------------
*/

it('records a variation as a new row and leaves the original untouched', function () {
    $project = Project::factory()->draft()->create();
    $original = ($this->award)($project, $this->contractor, $this->admin, contractAttributes());

    $variation = (new RecordContractVariation)($original, $this->admin, [
        'contract_number' => 'CTR-2026-001-VO1',
        'sum' => '25000000.00',
        'scope_of_works' => 'Additional culverts at chainage 3+400.',
        'award_date' => now()->toDateString(),
    ], 'Revised ground conditions after the rains.');

    $project->refresh();
    $original->refresh();

    expect($variation->varies_contract_id)->toBe($original->id)
        ->and($variation->contractor_id)->toBe($original->contractor_id)
        ->and($variation->variation_reason)->toBe('Revised ground conditions after the rains.')
        ->and($original->sum->toDecimalString())->toBe('450000000.00')
        ->and($project->contract_value_total->toDecimalString())->toBe('475000000.00');
});

it('carries a downward variation through the total as a reduction', function () {
    $project = Project::factory()->draft()->create();
    $original = ($this->award)($project, $this->contractor, $this->admin, contractAttributes());

    (new RecordContractVariation)($original, $this->admin, [
        'contract_number' => 'CTR-2026-001-VO1',
        'sum' => '-50000000.00',
        'scope_of_works' => 'Descoped: the two lay-bys are deferred.',
        'award_date' => now()->toDateString(),
    ], 'Scope reduced by the ministry.');

    expect($project->fresh()->contract_value_total->toDecimalString())->toBe('400000000.00');
});

it('refuses a variation without a reason, and a variation of a variation', function () {
    $project = Project::factory()->draft()->create();
    $original = ($this->award)($project, $this->contractor, $this->admin, contractAttributes());

    expect(fn () => (new RecordContractVariation)($original, $this->admin, [
        'contract_number' => 'CTR-2026-001-VO1',
        'sum' => '1000000.00',
        'scope_of_works' => 'More works.',
        'award_date' => now()->toDateString(),
    ]))->toThrow(ProjectRuleViolation::class, 'requires a stated reason');

    $variation = (new RecordContractVariation)($original, $this->admin, [
        'contract_number' => 'CTR-2026-001-VO1',
        'sum' => '1000000.00',
        'scope_of_works' => 'More works.',
        'award_date' => now()->toDateString(),
    ], 'Agreed addendum.');

    expect(fn () => (new RecordContractVariation)($variation, $this->admin, [
        'contract_number' => 'CTR-2026-001-VO2',
        'sum' => '1000000.00',
        'scope_of_works' => 'Even more works.',
        'award_date' => now()->toDateString(),
    ], 'Second addendum.'))->toThrow(ProjectRuleViolation::class, 'against the original award');
});

/*
|--------------------------------------------------------------------------
| The derived cache (§9.2 — the drift risk)
|--------------------------------------------------------------------------
*/

it('drops a soft-deleted contract out of the project total', function () {
    $project = Project::factory()->draft()->create();
    $first = ($this->award)($project, $this->contractor, $this->admin, contractAttributes(['sum' => '100000000.00']));
    ($this->award)($project->fresh(), Contractor::factory()->create(), $this->admin, contractAttributes([
        'contract_number' => 'CTR-2026-002',
        'sum' => '40000000.00',
    ]));

    $first->delete();
    (new RecalculateContractValueTotal)($project);

    expect($project->fresh()->contract_value_total->toDecimalString())->toBe('40000000.00');
});

it('drops a terminated contract out of the project total, because the state is no longer committed to it', function () {
    $project = Project::factory()->draft()->create();
    $contract = ($this->award)($project, $this->contractor, $this->admin, contractAttributes(['sum' => '80000000.00']));

    $contract->update(['status' => ContractStatus::Terminated]);
    $total = (new RecalculateContractValueTotal)($project);

    expect($total->equals(Money::zero()))->toBeTrue()
        ->and($project->fresh()->contract_value_total->toDecimalString())->toBe('0.00');
});

it('keeps the cache equal to the sum of live contracts after every operation', function () {
    $project = Project::factory()->draft()->create();

    $original = ($this->award)($project, $this->contractor, $this->admin, contractAttributes(['sum' => '300000000.00']));

    (new RecordContractVariation)($original, $this->admin, [
        'contract_number' => 'CTR-2026-001-VO1',
        'sum' => '20000000.00',
        'scope_of_works' => 'Extra works.',
        'award_date' => now()->toDateString(),
    ], 'Approved addendum.');

    $live = Contract::query()->get()->reduce(
        fn (Money $carry, Contract $contract) => $carry->plus($contract->sum),
        Money::zero(),
    );

    expect($project->fresh()->contract_value_total->equals($live))->toBeTrue();
});
