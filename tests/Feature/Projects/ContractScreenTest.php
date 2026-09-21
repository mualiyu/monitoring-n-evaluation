<?php

/**
 * The contract screens (design §5): `/projects/{project}/contracts/create` and
 * `/projects/{project}/contracts/{contract}`.
 *
 * The domain rules themselves — immutable award terms, the amendment register,
 * the `contract_value_total` cache — are proven in ContractAwardTest against
 * the Actions. What is proven HERE is the part a green Action suite cannot see:
 * that the screens exist on the routes the rest of the UI links to, that the
 * markup a browser receives submits values the validation accepts, that the
 * role matrix holds on both paths, and that a ULID from another workspace (or
 * another project) is a 404 rather than a record.
 */

use App\Actions\Projects\AwardContract;
use App\Enums\ContractStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Livewire\Tenant\Projects\ContractCreate;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Money;
use App\Tenancy\CurrentTenant;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The [value => label] pairs of one named <select> in a page of markup, with
 * the placeholder dropped. Named for this file: VaultSecurityTest and
 * SelectOptionValueTest already own `fakePdf`/`selectOptions` at global scope,
 * and Pest loads every test file into one process.
 *
 * @return array<array-key, string>
 */
function contractFormOptions(string $html, string $name): array
{
    preg_match('/<select\b[^>]*\bname="'.preg_quote($name, '/').'"[^>]*>(.*?)<\/select>/s', $html, $block);

    preg_match_all('/<option value="([^"]*)"[^>]*>\s*(.*?)\s*<\/option>/s', $block[1] ?? '', $matches, PREG_SET_ORDER);

    return collect($matches)
        ->mapWithKeys(fn (array $m): array => [
            html_entity_decode($m[1], ENT_QUOTES) => html_entity_decode(trim($m[2]), ENT_QUOTES),
        ])
        ->reject(fn ($label, $value) => (string) $value === '')
        ->all();
}

/** A filled-in award form, ready to be posted through the component. */
function awardForm(Contractor $contractor, array $overrides = []): array
{
    return [
        'contractorId' => (string) $contractor->id,
        'contractNumber' => 'CTR-2026-0001',
        'contractType' => 'works',
        'contractSum' => '450000000.00',
        'scopeOfWorks' => 'Construction of 7km of township roads including drainage and walkways.',
        'awardDate' => now()->toDateString(),
        ...$overrides,
    ];
}

/** A filled-in variation form against $original. */
function variationForm(Contract $original, array $overrides = []): array
{
    return [
        'mode' => ContractCreate::MODE_VARIATION,
        'variesUlid' => $original->ulid,
        'contractNumber' => 'CTR-2026-0001-VO1',
        'variationDirection' => ContractCreate::DIRECTION_INCREASE,
        'variationAmount' => '12000000.00',
        'scopeOfWorks' => 'Additional reinforced drainage across the flood-prone section at Km 3.',
        'variationReason' => 'Ground conditions found on site differ materially from the design assumptions.',
        'awardDate' => now()->toDateString(),
        ...$overrides,
    ];
}

/** Drive the contract form through the component, in the order the form is filled. */
function fillContractForm(User $actor, Project $project, array $values): Testable
{
    $component = Livewire::actingAs($actor)->test(ContractCreate::class, ['project' => $project]);

    foreach ($values as $field => $value) {
        $component->set($field, $value);
    }

    return $component;
}

/** A head contract on the project, written the way the Action writes one. */
function seedAward(Project $project, Contractor $contractor, User $actor, array $overrides = []): Contract
{
    return (new AwardContract)($project, $contractor, $actor, [
        'contract_number' => 'CTR-2026-0001',
        'type' => 'works',
        'sum' => '450000000.00',
        'scope_of_works' => 'Construction of 7km of township roads including drainage and walkways.',
        'award_date' => now()->subMonth()->toDateString(),
        ...$overrides,
    ]);
}

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->sector = Sector::factory()->create(['name' => 'Transport', 'is_active' => true]);

    $this->project = Project::factory()->for($this->works)->draft()->create([
        'reference' => 'PRJ-CTR-01',
        'title' => 'Township Road Rehabilitation',
        'sector_id' => $this->sector->id,
    ]);

    $this->contractor = Contractor::factory()->create([
        'name' => 'Riverside Civil Works Ltd',
        'rc_number' => 'RC998877',
    ]);
});

/* -------------------------------------------------------------------------- */
/* The routes exist and render */
/* -------------------------------------------------------------------------- */

it('serves the contract form to an authorized member', function () {
    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/contracts/create'))
        ->assertOk()
        ->assertSee('Record a contract award')
        ->assertSee($this->project->title);
});

it('serves the contract record with its amendment register', function () {
    $award = seedAward($this->project, $this->contractor, $this->admin);

    Contract::factory()->variation($award)->create([
        'project_id' => $this->project->id,
        'contract_number' => 'CTR-2026-0001-VO1',
        'sum' => '12000000.00',
        'created_by_id' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/contracts/'.$award->ulid))
        ->assertOk()
        ->assertSee('CTR-2026-0001')
        ->assertSee('CTR-2026-0001-VO1')
        ->assertSee('Amendment register')
        // Award sum beside value today: both figures stay readable.
        ->assertSee(Money::fromDecimalString('450000000.00')->format(), escape: false)
        ->assertSee(Money::fromDecimalString('462000000.00')->format(), escape: false);
});

it('opens a variation on its own url, headed by the award it amends', function () {
    $award = seedAward($this->project, $this->contractor, $this->admin);

    $variation = Contract::factory()->variation($award)->create([
        'project_id' => $this->project->id,
        'contract_number' => 'CTR-2026-0001-VO1',
        'sum' => '12000000.00',
        'created_by_id' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/contracts/'.$variation->ulid))
        ->assertOk()
        ->assertSee('This is a variation, not an award')
        ->assertSee('CTR-2026-0001');
});

/* -------------------------------------------------------------------------- */
/* The markup the browser gets */
/* -------------------------------------------------------------------------- */

it('renders the contractor picker keyed by id, not by name', function () {
    // The regression this whole file inherits: an id-keyed map that renders
    // the NAME as the option value posts a label where an id is expected.
    Contractor::factory()->create(['name' => 'Azure Builders Ltd', 'rc_number' => 'RC111222']);

    $html = (string) $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/contracts/create'))
        ->assertOk()
        ->getContent();

    $expected = Contractor::query()->where('is_blacklisted', false)->orderBy('name')->get()
        ->mapWithKeys(fn (Contractor $c): array => [(string) $c->id => $c->name.' — '.$c->rc_number])
        ->all();

    expect(contractFormOptions($html, 'contractorId'))->toBe($expected);
});

it('accepts the contractor value exactly as the rendered form submits it', function () {
    $html = (string) $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/contracts/create'))
        ->getContent();

    // Pull the submitted value out of the real markup rather than assuming it.
    $submitted = (string) array_search(
        $this->contractor->name.' — '.$this->contractor->rc_number,
        contractFormOptions($html, 'contractorId'),
        true,
    );

    expect($submitted)->toBe((string) $this->contractor->id);

    fillContractForm($this->admin, $this->project, awardForm($this->contractor, ['contractorId' => $submitted]))
        ->call('save')
        ->assertHasNoErrors();

    expect(Contract::query()->where('contract_number', 'CTR-2026-0001')->first()?->contractor_id)
        ->toBe($this->contractor->id);
});

it('renders the varied-contract picker keyed by ulid so the deep link stays opaque', function () {
    $award = seedAward($this->project, $this->contractor, $this->admin);

    $html = (string) $this->actingAs($this->admin)
        ->get(tenantUrl(
            $this->works,
            '/projects/'.$this->project->ulid.'/contracts/create?mode=variation'
        ))
        ->assertOk()
        ->getContent();

    $options = contractFormOptions($html, 'variesUlid');

    expect(array_keys($options))->toBe([$award->ulid])
        ->and(reset($options))->toContain('CTR-2026-0001');

    // And the value the markup carries is one the form accepts.
    fillContractForm($this->admin, $this->project, variationForm($award, ['variesUlid' => array_key_first($options)]))
        ->call('save')
        ->assertHasNoErrors();
});

it('switches to the variation form when a link names the contract to amend', function () {
    $award = seedAward($this->project, $this->contractor, $this->admin);

    $this->actingAs($this->admin)
        ->get(tenantUrl(
            $this->works,
            '/projects/'.$this->project->ulid.'/contracts/create?mode=variation&varies='.$award->ulid
        ))
        ->assertOk()
        ->assertSee('Record a contract variation')
        ->assertSee('Reason for the variation');
});

it('offers no variation path on a project that has no contract yet', function () {
    // Nothing to amend: the form falls back to the award it actually needs
    // rather than presenting an empty picker.
    Livewire::actingAs($this->admin)
        ->test(ContractCreate::class, ['project' => $this->project])
        ->assertSet('mode', ContractCreate::MODE_AWARD);
});

/* -------------------------------------------------------------------------- */
/* Awarding */
/* -------------------------------------------------------------------------- */

it('awards a contract, moves the project out of draft and refreshes the portfolio total', function () {
    fillContractForm($this->admin, $this->project, awardForm($this->contractor, [
        'commencementDate' => now()->addWeek()->toDateString(),
        'durationDays' => '365',
        'retentionPercentage' => '5',
        'expectedCompletionDate' => now()->addYear()->toDateString(),
    ]))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirectContains('/contracts/');

    $contract = Contract::query()->where('contract_number', 'CTR-2026-0001')->firstOrFail();
    $project = Project::query()->whereKey($this->project->getKey())->firstOrFail();

    expect($contract->sum->toDecimalString())->toBe('450000000.00')
        ->and($contract->status)->toBe(ContractStatus::Awarded)
        ->and($contract->duration_days)->toBe(365)
        ->and($contract->varies_contract_id)->toBeNull()
        ->and($contract->created_by_id)->toBe($this->admin->id)
        ->and($project->status)->toBe(ProjectStatus::Awarded)
        ->and($project->contract_value_total?->toDecimalString())->toBe('450000000.00');
});

it('rejects a sum the money cast cannot parse', function (string $sum) {
    // `numeric` alone accepts '5.' and '1e5'; Money::FORM_RULE is what stops
    // them reaching the cast and 500ing there. A signed value is refused too:
    // an award is never negative, and a downward VARIATION expresses itself
    // through its direction field rather than a typed minus.
    fillContractForm($this->admin, $this->project, awardForm($this->contractor, ['contractSum' => $sum]))
        ->call('save')
        ->assertHasErrors('contractSum');

    expect(Contract::query()->count())->toBe(0);
})->with([['5.'], ['1e5'], ['450,000.00'], ['-1000']]);

it('refuses a contract number this workspace already uses', function () {
    seedAward($this->project, $this->contractor, $this->admin);

    fillContractForm($this->admin, $this->project, awardForm($this->contractor))
        ->call('save')
        ->assertHasErrors('contractNumber');
});

it('allows a contract number another workspace already uses', function () {
    // The unique index is (tenant_id, contract_number): the scope is the
    // workspace, and a clash in another ministry must be invisible here.
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    app(CurrentTenant::class)->runAs($health, function () use ($health) {
        $project = Project::factory()->for($health)->draft()->create();
        $actor = memberOf(User::factory()->create(), $health, Role::MdaAdmin);

        seedAward($project, Contractor::factory()->create(), $actor);
    });

    actingOnTenant($this->works);

    fillContractForm($this->admin, $this->project, awardForm($this->contractor))
        ->call('save')
        ->assertHasNoErrors();

    expect(Contract::query()->where('contract_number', 'CTR-2026-0001')->count())->toBe(1);
});

it('surfaces the Action’s refusal when the project cannot receive a contract', function () {
    $completed = Project::factory()->for($this->works)->completed()->create(['reference' => 'PRJ-CTR-02']);

    $component = fillContractForm($this->admin, $completed, awardForm($this->contractor))
        ->call('save')
        ->assertHasNoErrors();

    expect($component->get('failure'))->not->toBeNull()
        ->and(Contract::query()->count())->toBe(0);
});

it('refuses a blacklisted firm even if its id is posted directly', function () {
    $barred = Contractor::factory()->blacklisted()->create(['name' => 'Stalled Works Ltd']);

    $component = fillContractForm($this->admin, $this->project, awardForm($barred))
        ->call('save');

    expect($component->get('failure'))->toContain('Stalled Works Ltd')
        ->and(Contract::query()->count())->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* Varying */
/* -------------------------------------------------------------------------- */

it('records a variation as its own row and leaves the award untouched', function () {
    $award = seedAward($this->project, $this->contractor, $this->admin);

    fillContractForm($this->admin, $this->project, variationForm($award))
        ->call('save')
        ->assertHasNoErrors();

    $variation = Contract::query()->where('contract_number', 'CTR-2026-0001-VO1')->firstOrFail();
    $original = Contract::query()->whereKey($award->getKey())->firstOrFail();
    $project = Project::query()->whereKey($this->project->getKey())->firstOrFail();

    expect($variation->varies_contract_id)->toBe($award->id)
        ->and($variation->sum->toDecimalString())->toBe('12000000.00')
        ->and($variation->variation_reason)->toContain('Ground conditions')
        // Executed by the firm holding the original — a different firm would
        // be a new award, not an amendment.
        ->and($variation->contractor_id)->toBe($award->contractor_id)
        // The award itself is exactly as it was.
        ->and($original->sum->toDecimalString())->toBe('450000000.00')
        ->and($original->scope_of_works)->toBe($award->scope_of_works)
        ->and($project->contract_value_total?->toDecimalString())->toBe('462000000.00');
});

it('records an omission as a negative delta', function () {
    $award = seedAward($this->project, $this->contractor, $this->admin);

    fillContractForm($this->admin, $this->project, variationForm($award, [
        'contractNumber' => 'CTR-2026-0001-VO2',
        'variationDirection' => ContractCreate::DIRECTION_DECREASE,
        'variationAmount' => '20000000.00',
        'variationReason' => 'Walkway section omitted from the scope by the ministry’s instruction.',
    ]))
        ->call('save')
        ->assertHasNoErrors();

    $variation = Contract::query()->where('contract_number', 'CTR-2026-0001-VO2')->firstOrFail();
    $project = Project::query()->whereKey($this->project->getKey())->firstOrFail();

    expect($variation->sum->toDecimalString())->toBe('-20000000.00')
        ->and($project->contract_value_total?->toDecimalString())->toBe('430000000.00');
});

it('refuses an omission larger than the contract is currently worth', function () {
    $award = seedAward($this->project, $this->contractor, $this->admin);

    fillContractForm($this->admin, $this->project, variationForm($award, [
        'variationDirection' => ContractCreate::DIRECTION_DECREASE,
        'variationAmount' => '999000000.00',
    ]))
        ->call('save')
        ->assertHasErrors('variationAmount');

    expect(Contract::query()->count())->toBe(1);
});

it('requires a stated reason on every variation', function () {
    $award = seedAward($this->project, $this->contractor, $this->admin);

    fillContractForm($this->admin, $this->project, variationForm($award, ['variationReason' => 'Ground.']))
        ->call('save')
        ->assertHasErrors('variationReason');

    expect(Contract::query()->count())->toBe(1);
});

it('refuses to vary a variation', function () {
    $award = seedAward($this->project, $this->contractor, $this->admin);

    $variation = Contract::factory()->variation($award)->create([
        'project_id' => $this->project->id,
        'contract_number' => 'CTR-2026-0001-VO1',
        'created_by_id' => $this->admin->id,
    ]);

    // The picker only ever lists head contracts, so a variation's ULID is not
    // a value the form will resolve.
    fillContractForm($this->admin, $this->project, variationForm($award, [
        'variesUlid' => $variation->ulid,
        'contractNumber' => 'CTR-2026-0001-VO2',
    ]))
        ->call('save')
        ->assertHasErrors('variesUlid');
});

/* -------------------------------------------------------------------------- */
/* Authorization matrix */
/* -------------------------------------------------------------------------- */

it('serves the contract screens to the M&E officer', function () {
    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $award = seedAward($this->project, $this->contractor, $this->admin);

    $this->actingAs($officer)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/contracts/create'))
        ->assertOk();

    $this->actingAs($officer)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/contracts/'.$award->ulid))
        ->assertOk();
});

it('refuses both contract screens to a field role assigned to the project', function (Role $role) {
    $award = seedAward($this->project, $this->contractor, $this->admin);

    $user = memberOf(User::factory()->create(), $this->works, $role);

    ProjectAssignment::factory()->create([
        'tenant_id' => $this->works->id,
        'project_id' => $this->project->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/contracts/create'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/contracts/'.$award->ulid))
        ->assertForbidden();
})->with([[Role::Consultant], [Role::FieldMonitor]]);

it('hides the contracts tab from a role that may not read contract sums', function () {
    seedAward($this->project, $this->contractor, $this->admin);

    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    ProjectAssignment::factory()->create([
        'tenant_id' => $this->works->id,
        'project_id' => $this->project->id,
        'user_id' => $consultant->id,
    ]);

    // Asked for BY NAME: the tab must not open just because the query string
    // names it, or hiding the button would be decoration.
    $html = (string) $this->actingAs($consultant)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'?tab=contracts'))
        ->assertOk()
        ->getContent();

    // The tab's own panel is what must not render: its heading, its table and
    // the link into the contract record.
    expect($html)
        ->not->toContain('Award sums are immutable')
        ->not->toContain('Contracts awarded on this project')
        ->not->toContain(route('tenant.projects.contracts.create', [
            'tenant' => $this->works->slug,
            'project' => $this->project,
        ]));
});

it('refuses the award to a consultant who posts the form directly', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    ProjectAssignment::factory()->create([
        'tenant_id' => $this->works->id,
        'project_id' => $this->project->id,
        'user_id' => $consultant->id,
    ]);

    Livewire::actingAs($consultant)
        ->test(ContractCreate::class, ['project' => $this->project])
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Isolation */
/* -------------------------------------------------------------------------- */

it('404s when one workspace asks for another workspace’s contract', function () {
    $health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    [$theirProject, $theirContract] = app(CurrentTenant::class)->runAs($health, function () use ($health) {
        $project = Project::factory()->for($health)->draft()->create();
        $actor = memberOf(User::factory()->create(), $health, Role::MdaAdmin);

        return [$project, seedAward($project, Contractor::factory()->create(), $actor)];
    });

    actingOnTenant($this->works);

    // Through the route: the binder is what applies the scope.
    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$theirProject->ulid.'/contracts/'.$theirContract->ulid))
        ->assertNotFound();

    // And the contract ULID under a project this workspace CAN see is still
    // not resolvable — the scope refuses the child, not just the parent.
    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/contracts/'.$theirContract->ulid))
        ->assertNotFound();
});

it('404s a contract belonging to a different project in the same workspace', function () {
    $award = seedAward($this->project, $this->contractor, $this->admin);

    $sibling = Project::factory()->for($this->works)->draft()->create(['reference' => 'PRJ-CTR-03']);

    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$sibling->ulid.'/contracts/'.$award->ulid))
        ->assertNotFound();
});

/* -------------------------------------------------------------------------- */
/* The links that make the screens reachable */
/* -------------------------------------------------------------------------- */

it('links the project contracts tab to both contract screens by named route', function () {
    $award = seedAward($this->project, $this->contractor, $this->admin);

    $createUrl = route('tenant.projects.contracts.create', [
        'tenant' => $this->works->slug,
        'project' => $this->project,
    ]);

    $showUrl = route('tenant.projects.contracts.show', [
        'tenant' => $this->works->slug,
        'project' => $this->project,
        'contract' => $award,
    ]);

    $html = (string) $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'?tab=contracts'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain($createUrl)->toContain($showUrl);

    // And both links actually resolve — a href nobody follows in a test is a
    // href nobody proved.
    $this->actingAs($this->admin)->get($createUrl)->assertOk();
    $this->actingAs($this->admin)->get($showUrl)->assertOk();
});

it('offers a variation link from the contract record that lands on the variation form', function () {
    $award = seedAward($this->project, $this->contractor, $this->admin);

    $html = (string) $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/contracts/'.$award->ulid))
        ->assertOk()
        ->getContent();

    $variationUrl = route('tenant.projects.contracts.create', [
        'tenant' => $this->works->slug,
        'project' => $this->project,
        'mode' => ContractCreate::MODE_VARIATION,
        'varies' => $award->ulid,
    ]);

    expect($html)->toContain(e($variationUrl));

    $this->actingAs($this->admin)
        ->get($variationUrl)
        ->assertOk()
        ->assertSee('Record a contract variation');
});
