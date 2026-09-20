<?php

/**
 * <x-ui.form.select> option values, and the record pickers built on them.
 *
 * Why this file exists: every select that picks a row by id rendered the row's
 * NAME as the option value, because the component classified an id-keyed map
 * (->pluck('name', 'id'), integer keys) as a plain list of strings. The project
 * wizard was unusable — "The selected sector is invalid" — while the suite
 * stayed green, because Livewire::test() sets properties directly and never
 * renders an <option>. So these cases read the MARKUP the browser gets, then
 * feed that exact value back through validation.
 */

use App\Enums\Role;
use App\Livewire\Tenant\Projects\ProjectCreate;
use App\Models\FundingSource;
use App\Models\Lga;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\FundingSourceSeeder;
use Database\Seeders\LgaWardSeeder;
use Database\Seeders\SectorSeeder;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/** Every [value => label] pair inside a chunk of <option> markup. */
function optionPairs(string $html): array
{
    preg_match_all('/<option value="([^"]*)"[^>]*>\s*(.*?)\s*<\/option>/s', $html, $matches, PREG_SET_ORDER);

    return collect($matches)
        ->mapWithKeys(fn (array $m): array => [html_entity_decode($m[1], ENT_QUOTES) => html_entity_decode(trim($m[2]), ENT_QUOTES)])
        ->all();
}

/** Render the component in isolation and return its [value => label] options. */
function renderedOptions(mixed $options): array
{
    return optionPairs(Blade::render(
        '<x-ui.form.select name="field" :options="$options" />',
        ['options' => $options],
    ));
}

/**
 * The options of one named <select> in a page, so an assertion cannot pass on
 * a coincidental match elsewhere in the markup. The placeholder (value="") is
 * dropped — it is chrome, not a choice.
 *
 * @return array<string, string>
 */
function selectOptions(string $html, string $name): array
{
    preg_match('/<select\b[^>]*\bname="'.preg_quote($name, '/').'"[^>]*>(.*?)<\/select>/s', $html, $block);

    return collect(optionPairs($block[1] ?? ''))
        ->reject(fn (string $label, string $value): bool => $value === '')
        ->all();
}

/*
|--------------------------------------------------------------------------
| The component contract, one case per documented option shape
|--------------------------------------------------------------------------
| No tenancy needed: these render the component directly.
*/

it('uses the key as the option value for an id-keyed map', function () {
    // The regression: integer keys are still keys, not a plain list.
    expect(renderedOptions([7 => 'Education', 12 => 'Health']))
        ->toBe(['7' => 'Education', '12' => 'Health']);
});

it('uses the key as the option value for a string-keyed map', function () {
    expect(renderedOptions(['capital' => 'Capital', 'programme' => 'Programme']))
        ->toBe(['capital' => 'Capital', 'programme' => 'Programme']);
});

it('uses each entry as both value and label for a plain list', function () {
    expect(renderedOptions(['Draft', 'Submitted']))
        ->toBe(['Draft' => 'Draft', 'Submitted' => 'Submitted']);
});

it('honours an explicit value/label list', function () {
    expect(renderedOptions([
        ['value' => 'a', 'label' => 'Alpha'],
        ['value' => 'b', 'label' => 'Beta'],
    ]))->toBe(['a' => 'Alpha', 'b' => 'Beta']);
});

it('takes the keys of an id-keyed Collection, not only an array', function () {
    expect(renderedOptions(collect([3 => 'Works & Transport'])))
        ->toBe(['3' => 'Works & Transport']);
});

/*
|--------------------------------------------------------------------------
| The real screens
|--------------------------------------------------------------------------
| actingOnTenant binds the tenancy context Livewire::test() resolves against,
| the same setup the other project screen tests use.
*/

describe('project wizard record pickers', function () {
    beforeEach(function () {
        seedPermissions();
        (new SectorSeeder)->run();
        (new FundingSourceSeeder)->run();
        (new LgaWardSeeder)->run();

        $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
        actingOnTenant($this->works);

        $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    });

    it('renders the step-1 sector picker keyed by id, not by name', function () {
        $this->actingAs($this->admin);

        $html = (string) $this->get(tenantUrl($this->works, '/projects/create'))->assertOk()->getContent();

        $sectors = Sector::query()->where('is_active', true)->orderBy('name')->get();

        expect(selectOptions($html, 'sector_id'))->toBe(
            $sectors->mapWithKeys(fn (Sector $s): array => [(string) $s->id => $s->name])->all(),
        );
    });

    it('renders the later-step record pickers keyed by id too', function () {
        // Steps 2 and 3 are not in the first paint, so walk the wizard and read
        // the markup Livewire actually renders at each step.
        $component = Livewire::actingAs($this->admin)->test(ProjectCreate::class)
            ->set('title', 'Township Road Rehabilitation Phase II')
            ->set('reference', 'PRJ-STEPS-01')
            ->set('sector_id', (string) Sector::query()->orderBy('name')->firstOrFail()->id)
            ->call('next')
            ->assertSet('step', 2);

        $funding = FundingSource::query()->where('is_active', true)->orderBy('name')->get();

        expect(selectOptions($component->html(), 'funding.0.funding_source_id'))->toBe(
            $funding->mapWithKeys(fn (FundingSource $f): array => [(string) $f->id => $f->name])->all(),
        );

        $lgas = Lga::query()->where('is_active', true)->orderBy('name')->get();

        expect(selectOptions($component->call('next')->assertSet('step', 3)->html(), 'lga_id'))->toBe(
            $lgas->mapWithKeys(fn (Lga $l): array => [(string) $l->id => $l->name])->all(),
        );
    });

    it('accepts the sector value exactly as the rendered form submits it', function () {
        $this->actingAs($this->admin);

        $sector = Sector::query()->where('code', 'EDU')->firstOrFail();

        // Pull the submitted value out of the real markup rather than assuming it.
        // Cast because PHP coerces numeric array keys to int on the way in —
        // the attribute in the HTML is a string either way.
        $html = (string) $this->get(tenantUrl($this->works, '/projects/create'))->getContent();
        $submitted = (string) array_search($sector->name, selectOptions($html, 'sector_id'), true);

        expect($submitted)->toBe((string) $sector->id);

        Livewire::actingAs($this->admin)->test(ProjectCreate::class)
            ->set('title', 'Basic Education Block Rehabilitation')
            ->set('reference', 'PRJ-EDU-01')
            ->set('sector_id', $submitted)
            ->call('next')
            ->assertHasNoErrors('sector_id')
            ->assertSet('step', 2);
    });

    it('still rejects a sector name submitted in place of an id', function () {
        // The guard that produced the original error message must keep biting —
        // the fix is the option value, not a loosened rule.
        Livewire::actingAs($this->admin)->test(ProjectCreate::class)
            ->set('title', 'Basic Education Block Rehabilitation')
            ->set('reference', 'PRJ-EDU-02')
            ->set('sector_id', 'Education')
            ->call('next')
            ->assertHasErrors('sector_id')
            ->assertSet('step', 1);
    });
});
