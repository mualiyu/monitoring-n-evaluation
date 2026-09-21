<?php

/**
 * The screens that WRITE: capturing a figure, setting a target, activating an
 * indicator, and attaching an indicator to a result statement.
 *
 * THE POINT OF THIS FILE is the pair of cases that read the RENDERED HTML and
 * feed the value they find straight back through validation. Livewire::test()
 * sets properties directly and never renders an <option> or crosses HTTP, and
 * three shipped defects in this project passed a green suite that way — one of
 * them being every record-picking <select> submitting the row's NAME where an
 * id was expected, which made the project wizard unusable while the tests
 * stayed green. So: read the markup the browser gets, pull the submitted value
 * out of it, and prove the form accepts exactly that.
 *
 * The rest of the file covers the screen's own guards — it re-authorizes on
 * every mutating call, because route middleware does not protect a Livewire
 * update POST by itself.
 */

use App\Actions\Indicators\SetIndicatorTarget;
use App\Enums\IndicatorReadingStatus;
use App\Enums\IndicatorTier;
use App\Enums\MeasurementFrequency;
use App\Enums\ReadingSourceType;
use App\Enums\Role;
use App\Livewire\Tenant\Indicators\FrameworkBuilder;
use App\Livewire\Tenant\Indicators\IndicatorDetail;
use App\Models\Indicator;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorReading;
use App\Models\IndicatorTarget;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ResultFramework;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    actingOnTenant($this->works);

    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);
    $this->statement = ResultFramework::factory()->forProject($this->project)->create();

    $this->indicator = Indicator::factory()->active()->inFramework($this->statement)->create([
        'name' => 'Boreholes commissioned and handed over',
        'baseline_value' => '0.0000',
    ]);
});

/**
 * Every [value => label] pair of one named <select> in a chunk of markup, with
 * the placeholder (value="") dropped — it is chrome, not a choice.
 *
 * Scoped to the named select so an assertion cannot pass on a coincidental
 * match elsewhere on a page that carries a dozen dropdowns.
 *
 * @return array<string, string>
 */
function capturedSelectOptions(string $html, string $name): array
{
    preg_match('/<select\b[^>]*\bname="'.preg_quote($name, '/').'"[^>]*>(.*?)<\/select>/s', $html, $block);

    preg_match_all('/<option value="([^"]*)"[^>]*>\s*(.*?)\s*<\/option>/s', $block[1] ?? '', $matches, PREG_SET_ORDER);

    return collect($matches)
        ->mapWithKeys(fn (array $m): array => [
            html_entity_decode($m[1], ENT_QUOTES) => html_entity_decode(trim($m[2]), ENT_QUOTES),
        ])
        ->reject(fn (string $label, string $value): bool => $value === '')
        ->all();
}

/* -------------------------------------------------------------------------- */
/* The rendered form, fed back through validation */
/* -------------------------------------------------------------------------- */

it('renders the reading-capture source picker as the values the Action expects, and accepts exactly what it submits', function () {
    $this->actingAs($this->officer);

    $html = (string) $this->get(tenantUrl($this->works, '/indicators/'.$this->indicator->ulid))
        ->assertOk()
        ->getContent();

    $options = capturedSelectOptions($html, 'sourceType');

    // The enum VALUES, keyed to their labels — not the labels as values. The
    // distinction between a primary collection and a secondary source is the
    // first thing a data-quality reviewer checks, so it has to survive the
    // round trip intact.
    expect($options)->toBe([
        ReadingSourceType::Primary->value => ReadingSourceType::Primary->label(),
        ReadingSourceType::Secondary->value => ReadingSourceType::Secondary->label(),
    ]);

    // Pull the submitted value out of the real markup rather than assuming it.
    $submitted = (string) array_search(ReadingSourceType::Secondary->label(), $options, true);

    Livewire::actingAs($this->officer)
        ->test(IndicatorDetail::class, ['indicator' => $this->indicator])
        ->set('periodStart', '2026-01-01')
        ->set('periodEnd', '2026-03-31')
        ->set('actualValue', '37')
        ->set('sourceType', $submitted)
        ->call('saveReading')
        ->assertHasNoErrors()
        ->assertSet('failure', null);

    $reading = IndicatorReading::query()->where('indicator_id', $this->indicator->id)->firstOrFail();

    expect($reading->source_type)->toBe(ReadingSourceType::Secondary)
        ->and($reading->status)->toBe(IndicatorReadingStatus::Draft)
        ->and($reading->actual_value)->toBe('37.0000');
});

it('still rejects a source-type LABEL submitted in place of its value', function () {
    // The guard must keep biting: the fix for a wrong option value is the
    // option value, never a loosened rule.
    Livewire::actingAs($this->officer)
        ->test(IndicatorDetail::class, ['indicator' => $this->indicator])
        ->set('periodStart', '2026-01-01')
        ->set('periodEnd', '2026-03-31')
        ->set('actualValue', '37')
        ->set('sourceType', 'Secondary Source')
        ->call('saveReading')
        ->assertHasErrors('sourceType');

    expect(IndicatorReading::query()->count())->toBe(0);
});

it('renders the library picker keyed by id, and instantiates the entry that id names', function () {
    $wanted = IndicatorDefinition::factory()->create([
        'code' => 'EDU-001',
        'name' => 'Classrooms completed and in use',
    ]);

    IndicatorDefinition::factory()->create(['code' => 'HLT-004', 'name' => 'Facilities equipped']);

    $this->actingAs($this->officer);

    $html = (string) $this->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/framework'))
        ->assertOk()
        ->getContent();

    $options = capturedSelectOptions($html, 'definitionId');

    // An id-keyed map: ->pluck-style integer keys are still keys, and the
    // select has to render them as the option VALUES. Rendering the name here
    // is the exact defect that made the project wizard unusable.
    expect($options)->toBe([
        (string) $wanted->id => 'EDU-001 — Classrooms completed and in use',
        (string) IndicatorDefinition::query()->where('code', 'HLT-004')->value('id') => 'HLT-004 — Facilities equipped',
    ]);

    $submitted = (string) array_search('EDU-001 — Classrooms completed and in use', $options, true);

    expect($submitted)->toBe((string) $wanted->id);

    Livewire::actingAs($this->officer)
        ->test(FrameworkBuilder::class, ['project' => $this->project])
        ->call('startIndicator', $this->statement->ulid)
        ->set('indicatorSource', 'library')
        ->set('definitionId', $submitted)
        ->call('saveIndicator')
        ->assertHasNoErrors()
        ->assertSet('failure', null);

    $created = Indicator::query()
        ->where('result_framework_id', $this->statement->id)
        ->where('indicator_definition_id', $wanted->id)
        ->firstOrFail();

    // The template is COPIED, not referenced — the state may reword the entry
    // next year and a figure published under the old wording must keep
    // meaning what it meant.
    expect($created->name)->toBe('Classrooms completed and in use')
        ->and($created->unit)->toBe($wanted->unit)
        // …and it stays inactive until this entity agrees its own baseline.
        ->and($created->is_active)->toBeFalse();
});

it('rejects a library entry named by its code instead of its id', function () {
    IndicatorDefinition::factory()->create(['code' => 'EDU-001']);

    Livewire::actingAs($this->officer)
        ->test(FrameworkBuilder::class, ['project' => $this->project])
        ->call('startIndicator', $this->statement->ulid)
        ->set('indicatorSource', 'library')
        ->set('definitionId', 'EDU-001')
        ->call('saveIndicator')
        ->assertHasErrors('definitionId');
});

/* -------------------------------------------------------------------------- */
/* Capture validation */
/* -------------------------------------------------------------------------- */

it('refuses a measurement that is not a plain figure', function (string $value) {
    // `numeric` alone accepts '5.' and '1e5', which are not figures anybody
    // typed on purpose and which land in a decimal column as noise.
    Livewire::actingAs($this->officer)
        ->test(IndicatorDetail::class, ['indicator' => $this->indicator])
        ->set('periodStart', '2026-01-01')
        ->set('periodEnd', '2026-03-31')
        ->set('sourceType', ReadingSourceType::Primary->value)
        ->set('actualValue', $value)
        ->call('saveReading')
        ->assertHasErrors('actualValue');
})->with([
    'a bare decimal point' => ['5.'],
    'scientific notation' => ['1e5'],
    'more places than the column holds' => ['1.234567'],
    'words' => ['about forty'],
    'nothing at all' => [''],
]);

it('refuses a measurement period that ends before it starts, on the form', function () {
    Livewire::actingAs($this->officer)
        ->test(IndicatorDetail::class, ['indicator' => $this->indicator])
        ->set('periodStart', '2026-03-31')
        ->set('periodEnd', '2026-01-01')
        ->set('actualValue', '37')
        ->set('sourceType', ReadingSourceType::Primary->value)
        ->call('saveReading')
        ->assertHasErrors('periodEnd');
});

it('shows the Action’s own words when a period already carries a figure', function () {
    IndicatorReading::factory()
        ->forIndicator($this->indicator)
        ->forPeriod('2026-01-01', '2026-03-31')
        ->create();

    $component = Livewire::actingAs($this->officer)
        ->test(IndicatorDetail::class, ['indicator' => $this->indicator])
        ->set('periodStart', '2026-01-01')
        ->set('periodEnd', '2026-03-31')
        ->set('actualValue', '41')
        ->set('sourceType', ReadingSourceType::Primary->value)
        ->call('saveReading')
        ->assertHasNoErrors();

    expect($component->get('failure'))->toContain('already has a live reading');
});

it('files a draft for review from the screen, and refuses the same click twice', function () {
    $reading = IndicatorReading::factory()
        ->forIndicator($this->indicator)
        ->recordedBy($this->officer)
        ->create();

    $component = Livewire::actingAs($this->officer)
        ->test(IndicatorDetail::class, ['indicator' => $this->indicator])
        ->call('submitReading', $reading->ulid)
        ->assertSet('failure', null);

    expect(IndicatorReading::query()->whereKey($reading->getKey())->firstOrFail()->status)
        ->toBe(IndicatorReadingStatus::Submitted);

    $component->call('submitReading', $reading->ulid);

    expect($component->get('failure'))->toContain('cannot move from [submitted]');
});

it('resolves nothing when a screen method names a reading from another indicator', function () {
    $other = Indicator::factory()->active()->create(['name' => 'Culverts installed']);
    $foreign = IndicatorReading::factory()->forIndicator($other)->create();

    $component = Livewire::actingAs($this->officer)
        ->test(IndicatorDetail::class, ['indicator' => $this->indicator]);

    // Resolved through the indicator's own relation, so a ULID outside it is
    // "not found" — not a refusal that would confirm the row exists somewhere.
    expect(fn () => $component->call('submitReading', $foreign->ulid))
        ->toThrow(ModelNotFoundException::class);

    expect(IndicatorReading::query()->whereKey($foreign->getKey())->firstOrFail()->status)
        ->toBe(IndicatorReadingStatus::Draft);
});

it('refuses capture to a member holding no recording permission', function () {
    $stranger = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    setPermissionsTeamId($this->works->id);
    $stranger->roles()->detach();
    $stranger->forgetCachedPermissions();

    Livewire::actingAs($stranger)
        ->test(IndicatorDetail::class, ['indicator' => $this->indicator])
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Targets */
/* -------------------------------------------------------------------------- */

it('revises the target for a period instead of adding a second one', function () {
    $component = Livewire::actingAs($this->officer)
        ->test(IndicatorDetail::class, ['indicator' => $this->indicator])
        ->set('targetPeriodType', MeasurementFrequency::Annual->value)
        ->set('targetStart', '2026-01-01')
        ->set('targetEnd', '2026-12-31')
        ->set('targetValue', '100')
        ->call('saveTarget')
        ->assertHasNoErrors()
        ->assertSet('failure', null);

    // Same period, revised figure. An annual target and its four quarterly
    // milestones coexist because they are different periods; revising 2026
    // must rewrite 2026 rather than quietly add a second row some query would
    // later pick at random.
    $component
        ->set('targetValue', '120')
        ->call('saveTarget')
        ->assertHasNoErrors()
        ->assertSet('failure', null);

    $targets = IndicatorTarget::query()->where('indicator_id', $this->indicator->id)->get();

    expect($targets)->toHaveCount(1)
        ->and($targets->first()->target_value)->toBe('120.0000');
});

it('keeps an annual target and a quarterly milestone side by side', function () {
    (new SetIndicatorTarget)(
        $this->indicator, MeasurementFrequency::Annual, '2026-01-01', '2026-12-31', '100', $this->officer,
    );

    (new SetIndicatorTarget)(
        $this->indicator, MeasurementFrequency::Quarterly, '2026-01-01', '2026-03-31', '25', $this->officer,
    );

    expect(IndicatorTarget::query()->where('indicator_id', $this->indicator->id)->count())->toBe(2);
});

it('refuses a target that is not a plain figure', function () {
    Livewire::actingAs($this->officer)
        ->test(IndicatorDetail::class, ['indicator' => $this->indicator])
        ->set('targetPeriodType', MeasurementFrequency::Annual->value)
        ->set('targetStart', '2026-01-01')
        ->set('targetEnd', '2026-12-31')
        ->set('targetValue', '1e5')
        ->call('saveTarget')
        ->assertHasErrors('targetValue');
});

/* -------------------------------------------------------------------------- */
/* Activation — the baseline gate */
/* -------------------------------------------------------------------------- */

it('opens an indicator for measurement once it has a complete baseline', function () {
    $draft = Indicator::factory()->inFramework($this->statement)->create([
        'name' => 'Culverts installed',
        'baseline_value' => '0.0000',
        'baseline_date' => '2025-01-01',
        'baseline_source' => 'Project appraisal document',
    ]);

    Livewire::actingAs($this->officer)
        ->test(IndicatorDetail::class, ['indicator' => $draft])
        ->call('activate')
        ->assertSet('failure', null);

    expect(Indicator::query()->whereKey($draft->getKey())->firstOrFail()->is_active)->toBeTrue();
});

it('refuses activation, in the officer’s own words, when the baseline is half drafted', function () {
    $draft = Indicator::factory()->withoutBaseline()->inFramework($this->statement)->create([
        'name' => 'Culverts installed',
    ]);

    $component = Livewire::actingAs($this->officer)
        ->test(IndicatorDetail::class, ['indicator' => $draft])
        ->call('activate');

    // An explicit null baseline, never a fabricated zero — and the screen says
    // why rather than doing nothing.
    expect($component->get('failure'))->not->toBeNull()
        ->and(Indicator::query()->whereKey($draft->getKey())->firstOrFail()->is_active)->toBeFalse();
});

/* -------------------------------------------------------------------------- */
/* The logframe's own rules, through the screen */
/* -------------------------------------------------------------------------- */

it('offers only the tiers that can measure the statement being added to', function () {
    $outcome = ResultFramework::factory()->outcomeOf($this->statement)->create();

    $component = Livewire::actingAs($this->officer)
        ->test(FrameworkBuilder::class, ['project' => $this->project])
        ->call('startIndicator', $outcome->ulid);

    // Offering an output tier on an outcome statement would be offering a
    // refusal: an output measure nailed to an outcome is how a logframe
    // quietly becomes a task list.
    expect(array_keys($component->instance()->tierOptions()))
        ->toBe([IndicatorTier::Pdo->value, IndicatorTier::Intermediate->value]);
});

it('refuses a result statement too short to say what changes', function () {
    Livewire::actingAs($this->officer)
        ->test(FrameworkBuilder::class, ['project' => $this->project])
        ->call('startStatement')
        ->set('statementText', 'Road')
        ->call('saveStatement')
        ->assertHasErrors('statementText');
});

it('refuses to dismantle a branch of the logframe that still carries indicators', function () {
    $component = Livewire::actingAs($this->officer)
        ->test(FrameworkBuilder::class, ['project' => $this->project])
        ->call('deleteStatement', $this->statement->ulid);

    // The FK would refuse it anyway — as a 500. Refusing here turns "the site
    // broke" into the sentence the officer needs.
    expect($component->get('failure'))->toContain('leaf-first')
        ->and(ResultFramework::query()->whereKey($this->statement->getKey())->exists())->toBeTrue();
});

it('opens the logframe builder to an assigned consultant and refuses them every write on it', function () {
    // Assigned, so `Project::visibleTo` admits them and the screen opens:
    // they report figures against this framework and must see what those
    // figures are for. Everything that SHAPES it is refused on the method —
    // hiding the button is a courtesy, a Livewire endpoint is a public one.
    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'assigned_by_id' => $this->officer->id,
    ]);

    $component = Livewire::actingAs($this->consultant->refresh())
        ->test(FrameworkBuilder::class, ['project' => $this->project])
        ->assertOk();

    $component->call('startStatement')->assertForbidden();

    Livewire::actingAs($this->consultant)
        ->test(FrameworkBuilder::class, ['project' => $this->project])
        ->call('deleteStatement', $this->statement->ulid)
        ->assertForbidden();

    Livewire::actingAs($this->consultant)
        ->test(FrameworkBuilder::class, ['project' => $this->project])
        ->call('startIndicator', $this->statement->ulid)
        ->assertForbidden();
});
