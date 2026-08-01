<?php

/**
 * The baseline gate (projects-module.md §1.10): mandatory, enforced at
 * activation rather than by a NOT NULL column.
 *
 * Both halves matter — a NOT NULL baseline would force a fabricated zero into
 * every half-drafted indicator, and an activation that skipped the check would
 * make every achievement percentage computed from it meaningless.
 */

use App\Actions\Projects\ActivateIndicator;
use App\Enums\Role;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Indicator;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->project = Project::factory()->ongoing()->create();
    $this->activate = new ActivateIndicator;
});

it('activates an indicator that carries a complete baseline', function () {
    $indicator = Indicator::factory()->forProject($this->project)->create();

    ($this->activate)($indicator, $this->officer);

    expect($indicator->fresh()->is_active)->toBeTrue()
        ->and($indicator->fresh()->activated_at)->not->toBeNull();
});

it('refuses activation without a baseline value, date and source', function (array $missing) {
    $indicator = Indicator::factory()->forProject($this->project)->create($missing);

    expect(fn () => ($this->activate)($indicator, $this->officer))
        ->toThrow(ProjectRuleViolation::class, 'baseline value');

    expect($indicator->fresh()->is_active)->toBeFalse();
})->with([
    'no baseline at all' => [['baseline_value' => null, 'baseline_date' => null, 'baseline_source' => null]],
    'a value with no date' => [['baseline_date' => null]],
    'a value with no source' => [['baseline_source' => null]],
]);

it('leaves an explicit null baseline in place rather than a fabricated zero', function () {
    $indicator = Indicator::factory()->withoutBaseline()->forProject($this->project)->create();

    expect($indicator->baseline_value)->toBeNull()
        ->and($indicator->hasCompleteBaseline())->toBeFalse()
        ->and($indicator->is_active)->toBeFalse();
});

it('refuses to activate the same indicator twice', function () {
    $indicator = Indicator::factory()->active()->forProject($this->project)->create();

    expect(fn () => ($this->activate)($indicator, $this->officer))
        ->toThrow(ProjectRuleViolation::class, 'already active');
});

it('refuses activation to a consultant — the framework is not theirs to open', function () {
    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    $indicator = Indicator::factory()->forProject($this->project)->create();

    expect(fn () => ($this->activate)($indicator, $consultant->fresh()))
        ->toThrow(AuthorizationException::class);
});
