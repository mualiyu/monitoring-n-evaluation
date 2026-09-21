<?php

/**
 * The mandatory isolation proof for the work-plan slice (rules/tenancy.md).
 *
 * Two MDAs, a plan and activities in each, then every READ PATH a user can
 * reach is taken from inside tenant A: the register, the detail builder, the
 * Gantt, the CSV export and the model layer underneath them. None may carry a
 * row of B's, and B's public ULID typed into A's URL bar must 404 — that is
 * what makes route-model binding a boundary rather than a suggestion.
 *
 * The oversight board is the deliberate exception and is asserted as one: it
 * spans both MDAs, because that is its whole purpose, and it does so through
 * an Action that checked global authority before the bypass.
 */

use App\Actions\Oversight\ListWorkplansAcrossTenants;
use App\Enums\Role;
use App\Livewire\Tenant\Workplans\WorkplanBuilder;
use App\Livewire\Tenant\Workplans\WorkplanGantt;
use App\Livewire\Tenant\Workplans\WorkplanIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workplan;
use App\Models\WorkplanActivity;
use App\Models\WorkplanEvent;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\CrossTenantWriteException;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

dataset('tenant-owned workplan models', [
    'work plans' => [Workplan::class, fn () => Workplan::factory()->create()],
    'work-plan activities' => [WorkplanActivity::class, fn () => WorkplanActivity::factory()->create()],
    'work-plan events' => [WorkplanEvent::class, fn () => WorkplanEvent::factory()->create()],
]);

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    $this->worksPlan = $this->current->runAs($this->works, function (): Workplan {
        $plan = Workplan::factory()->forYear(2026)->create(['title' => 'Works road programme 2026']);

        WorkplanActivity::factory()->forWorkplan($plan)->create(['title' => 'Resurface the Ondo–Akure corridor']);

        return $plan;
    });

    $this->healthPlan = $this->current->runAs($this->health, function (): Workplan {
        $plan = Workplan::factory()->forYear(2026)->create(['title' => 'Health primary care programme 2026']);

        WorkplanActivity::factory()->forWorkplan($plan)->create(['title' => 'Refurbish twelve primary health centres']);

        return $plan;
    });

    actingWithoutTenant();
});

/* -------------------------------------------------------------------------- */
/* The model layer */
/* -------------------------------------------------------------------------- */

it('shows one MDA only its own work-plan rows, never the other MDA\'s', function (string $model, Closure $create) {
    /** @var Model $inWorks */
    $inWorks = $this->current->runAs($this->works, $create);
    /** @var Model $inHealth */
    $inHealth = $this->current->runAs($this->health, $create);

    $this->current->runAs($this->works, function () use ($model, $inWorks, $inHealth) {
        expect($model::query()->whereKey($inWorks->getKey())->exists())->toBeTrue()
            ->and($model::query()->find($inHealth->getKey()))->toBeNull();
    });

    $this->current->runAs($this->health, function () use ($model, $inWorks, $inHealth) {
        expect($model::query()->whereKey($inHealth->getKey())->exists())->toBeTrue()
            ->and($model::query()->find($inWorks->getKey()))->toBeNull();
    });
})->with('tenant-owned workplan models');

it('stamps every work-plan row with the tenant that created it, without the factory naming one', function (string $model, Closure $create) {
    /** @var Model $inWorks */
    $inWorks = $this->current->runAs($this->works, $create);
    /** @var Model $inHealth */
    $inHealth = $this->current->runAs($this->health, $create);

    expect($inWorks->tenant_id)->toBe($this->works->id)
        ->and($inHealth->tenant_id)->toBe($this->health->id);
})->with('tenant-owned workplan models');

it('refuses to read a tenant-owned work-plan model with no workspace bound', function (string $model, Closure $create) {
    $this->current->runAs($this->works, $create);

    actingWithoutTenant();

    expect(fn () => $model::query()->count())->toThrow(TenantNotResolvedException::class);
})->with('tenant-owned workplan models');

it('refuses to file an activity into another MDA\'s plan from inside this one', function () {
    $this->current->runAs($this->works, function () {
        expect(fn () => WorkplanActivity::factory()->create([
            'workplan_id' => $this->healthPlan->id,
            'tenant_id' => $this->healthPlan->tenant_id,
        ]))->toThrow(CrossTenantWriteException::class);
    });
});

it('resolves nothing for another MDA\'s public plan id', function () {
    $this->current->runAs($this->works, function () {
        expect(Workplan::query()->where('ulid', $this->healthPlan->ulid)->first())->toBeNull()
            ->and(Workplan::query()->where('ulid', $this->worksPlan->ulid)->first())->not->toBeNull();
    });
});

/* -------------------------------------------------------------------------- */
/* The screens — list, detail, Gantt, export */
/* -------------------------------------------------------------------------- */

it('shows one workspace nothing of another workspace\'s plans in the register', function () {
    actingOnTenant($this->works);
    URL::defaults(['tenant' => $this->works->slug]);

    $worksOfficer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    $this->actingAs($worksOfficer)
        ->get(tenantUrl($this->works, '/workplans'))
        ->assertOk()
        ->assertSee('Works road programme 2026')
        ->assertDontSee('Health primary care programme 2026');

    // And the same question asked of the other subdomain answers the other way.
    actingOnTenant($this->health);
    URL::defaults(['tenant' => $this->health->slug]);

    $healthOfficer = memberOf(User::factory()->create(), $this->health, Role::MeOfficer);

    $this->actingAs($healthOfficer)
        ->get(tenantUrl($this->health, '/workplans'))
        ->assertOk()
        ->assertSee('Health primary care programme 2026')
        ->assertDontSee('Works road programme 2026');
});

it('404s the detail screen for another MDA\'s plan ULID', function () {
    actingOnTenant($this->works);

    $worksOfficer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    $this->actingAs($worksOfficer)
        ->get(tenantUrl($this->works, '/workplans/'.$this->healthPlan->ulid))
        ->assertNotFound();
});

it('404s the Gantt for another MDA\'s plan ULID', function () {
    actingOnTenant($this->works);

    $worksOfficer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    $this->actingAs($worksOfficer)
        ->get(tenantUrl($this->works, '/workplans/'.$this->healthPlan->ulid.'/gantt'))
        ->assertNotFound();
});

it('refuses to hydrate another MDA\'s plan into the builder or the Gantt component', function () {
    actingOnTenant($this->works);
    URL::defaults(['tenant' => $this->works->slug]);

    $worksOfficer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    // The route binder 404s a foreign ULID (asserted above). Handed the model
    // directly — the only way past the binder — the POLICY is the second
    // boundary: ChecksTenantAuthority compares the record's tenant_id with the
    // bound workspace, so neither screen renders another ministry's plan.
    foreach ([WorkplanBuilder::class, WorkplanGantt::class] as $component) {
        Livewire::actingAs($worksOfficer)
            ->test($component, ['workplan' => $this->healthPlan])
            ->assertForbidden();
    }
});

it('refuses to act on another MDA\'s activity ULID from inside this workspace', function () {
    actingOnTenant($this->works);
    URL::defaults(['tenant' => $this->works->slug]);

    $worksAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    $foreignActivity = $this->current->runAs(
        $this->health,
        fn (): WorkplanActivity => WorkplanActivity::query()->where('workplan_id', $this->healthPlan->id)->sole(),
    );

    actingOnTenant($this->works);

    // WorkplanBuilder::findActivity() goes through the plan's OWN relation, so
    // a foreign ULID resolves to nothing and firstOrFail() refuses — the
    // mutation never reaches an Action with somebody else's row.
    foreach (['editActivity', 'startRecording', 'removeActivity'] as $method) {
        expect(fn () => Livewire::actingAs($worksAdmin)
            ->test(WorkplanBuilder::class, ['workplan' => $this->worksPlan])
            ->call($method, $foreignActivity->ulid))
            ->toThrow(ModelNotFoundException::class);
    }

    // The foreign row is untouched — not edited, not soft-deleted.
    $this->current->runAs($this->health, function () use ($foreignActivity) {
        expect(WorkplanActivity::query()->whereKey($foreignActivity->getKey())->exists())->toBeTrue();
    });
});

it('keeps the CSV export inside the workspace that asked for it', function () {
    actingOnTenant($this->works);
    URL::defaults(['tenant' => $this->works->slug]);

    $worksOfficer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    // ->instance()->export(), not ->call('export'): calling it through the
    // Livewire testable returns the TESTABLE, whose body is the component's
    // JSON payload — the bytes the user actually receives would never be
    // asserted. Same pattern as tests/Feature/Lifecycle/TenantIsolationTest.
    $response = Livewire::actingAs($worksOfficer)
        ->test(WorkplanIndex::class)
        ->instance()
        ->export();

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain('Works road programme 2026')
        ->and($csv)->not->toContain('Health primary care programme 2026');
});

/* -------------------------------------------------------------------------- */
/* The sanctioned exception */
/* -------------------------------------------------------------------------- */

it('lets an oversight bypass — and only a bypass — read both MDAs\' plans', function () {
    actingWithoutTenant();

    $stateAdmin = userWithRole(Role::StateAdmin);

    $page = (new ListWorkplansAcrossTenants)($stateAdmin);

    expect($page->total())->toBe(2)
        ->and($page->pluck('title')->all())
        ->toContain('Works road programme 2026', 'Health primary care programme 2026');

    // …while the same list inside a workspace stays inside it.
    expect($this->current->runAs($this->works, fn (): int => Workplan::query()->count()))->toBe(1);
});
