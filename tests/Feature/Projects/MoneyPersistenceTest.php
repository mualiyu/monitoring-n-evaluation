<?php

/**
 * Money across the database boundary (projects-module.md §1.1): the column is
 * decimal(18,2) so it reads correctly in an export or a DBA's console, the
 * model hands back integer-kobo Money, and aggregates agree with the exact
 * integer sum. Whatever the driver returns — MySQL DECIMAL strings, SQLite
 * NUMERIC ints/floats — stops being a driver quirk at the cast.
 */

use App\Models\Contract;
use App\Models\Project;
use App\Models\Tenant;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);
});

it('stores a naira figure as a readable decimal, not as kobo', function () {
    $project = Project::factory()->create(['budget_allocation' => '4500000.00']);

    $stored = DB::table('projects')->where('id', $project->id)->value('budget_allocation');

    expect((string) $stored)->toStartWith('4500000')   // never 450000000
        ->and(Money::fromDecimalString((string) $stored)->minor())->toBe(4_500_000_00);
});

it('round-trips a money value through the database without losing a kobo', function (string $decimal) {
    $project = Project::factory()->create(['budget_allocation' => $decimal]);

    expect($project->fresh()->budget_allocation->toDecimalString())->toBe($decimal);
})->with([
    ['0.00'],
    ['0.01'],
    ['45000.30'],
    ['4500000.00'],
    ['99999999999.99'], // ₦100bn — the order of a state's whole capital budget
    // NB: the top of decimal(18,2) is asserted in the Unit money test, not
    // here. The SQLite test driver gives NUMERIC columns REAL affinity, so
    // anything above ~₦90tn (2^53 kobo) comes back rounded by the driver
    // rather than by our code; MySQL stores and returns DECIMAL exactly.
]);

it('hands the model a Money value object rather than a raw column value', function () {
    $project = Project::factory()->create(['budget_allocation' => '4500000.00'])->fresh();

    expect($project->budget_allocation)->toBeInstanceOf(Money::class)
        ->and($project->budget_allocation->minor())->toBe(4_500_000_00)
        ->and($project->budget_allocation->format('NGN'))->toBe('₦4,500,000.00');
});

it('normalises whatever this database driver returns for a money column', function () {
    $project = Project::factory()->create(['budget_allocation' => '4500000.00']);

    $raw = DB::table('projects')->where('id', $project->id)->value('budget_allocation');

    // The driver may hand back a string (MySQL), an int or a float (SQLite);
    // the cast is what makes domain code indifferent to which.
    expect(get_debug_type($raw))->toBeIn(['string', 'int', 'float'])
        ->and($project->fresh()->budget_allocation->minor())->toBe(4_500_000_00);
});

it('refuses a float assigned to a money attribute on a real model', function () {
    $project = Project::factory()->create();

    expect(function () use ($project) {
        $project->budget_allocation = 45000.30;
    })->toThrow(InvalidArgumentException::class, 'Float assigned to money attribute [budget_allocation]');
});

it('accepts minor units, a decimal string or a Money when writing', function (mixed $value) {
    $project = Project::factory()->create();

    $project->budget_allocation = $value;
    $project->save();

    expect($project->fresh()->budget_allocation->toDecimalString())->toBe('45000.30');
})->with([
    'minor units' => [4_500_030],
    'a decimal string' => ['45000.30'],
    'a Money value object' => [fn () => Money::fromMinor(4_500_030)],
]);

it('keeps null distinct from zero for a project with no contract yet', function () {
    $project = Project::factory()->draft()->create();

    expect($project->contract_value_total)->toBeNull()
        ->and($project->expenditure_to_date->isZero())->toBeTrue()
        ->and($project->fresh()->contract_value_total)->toBeNull();
});

it('totals a portfolio exactly, where floating-point money would drift', function () {
    foreach (['4500000.25', '3200000.50', '5800000.00'] as $value) {
        Project::factory()->create(['budget_allocation' => $value]);
    }

    $exact = Project::query()->get()->reduce(
        fn (Money $carry, Project $project) => $carry->plus($project->budget_allocation),
        Money::zero(),
    );

    expect($exact->toDecimalString())->toBe('13500000.75');

    // ...and the database's own SUM agrees with it to the kobo.
    $aggregate = Project::query()->sum('budget_allocation');

    expect(sprintf('%.2F', (float) $aggregate))->toBe($exact->toDecimalString());
});

it('sums a hundred kobo-level rows without a rounding error', function () {
    Project::factory()->count(100)->create(['budget_allocation' => '0.01']);

    $exact = Project::query()->get()->reduce(
        fn (Money $carry, Project $project) => $carry->plus($project->budget_allocation),
        Money::zero(),
    );

    expect($exact->toDecimalString())->toBe('1.00')
        ->and(sprintf('%.2F', (float) Project::query()->sum('budget_allocation')))->toBe('1.00');
});

it('derives financial progress from expenditure against contract value', function () {
    $project = Project::factory()->create([
        'contract_value_total' => '4500000.00',
        'expenditure_to_date' => '2250000.00',
    ]);

    expect($project->financial_progress)->toBe(50.0);
});

it('reports no financial progress at all when there is no contract value to divide by', function () {
    $project = Project::factory()->draft()->create();

    expect($project->financial_progress)->toBeNull();
});

it('refuses to let anyone write the derived financial progress', function () {
    $project = Project::factory()->create();

    expect(function () use ($project) {
        $project->financial_progress = 42.0;
    })->toThrow(LogicException::class, 'financial_progress is derived');
});

it('keeps a contract sum as money too, and revises it through variations only', function () {
    $project = Project::factory()->awarded()->create();
    $contract = Contract::factory()->forProject($project)->create(['sum' => '4500000.00']);
    Contract::factory()->variation($contract)->forProject($project)->create(['sum' => '250000.50']);

    expect($contract->fresh()->sum->toDecimalString())->toBe('4500000.00')
        ->and($contract->fresh()->revisedValue()->toDecimalString())->toBe('4750000.50');
});
