<?php

/**
 * Vendor registry screen (projects-module.md §1.3, §5).
 *
 * The registry is global on purpose, so the things worth proving here are that
 * the screen is honest about that: it shows every entity's firms, it refuses to
 * create a duplicate when the RC number already exists, and it does not offer
 * write actions to a workspace user who lacks the permission.
 */

use App\Enums\Role;
use App\Livewire\Tenant\Projects\ContractorIndex;
use App\Models\Contractor;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
});

it('lists firms registered by any entity, because the register is state-wide', function () {
    Contractor::factory()->create(['name' => 'Harmony Civil Works Ltd']);
    Contractor::factory()->create(['name' => 'Nova Hydrotech Nigeria Ltd']);

    Livewire::actingAs($this->admin)
        ->test(ContractorIndex::class)
        ->assertOk()
        ->assertSee('Harmony Civil Works Ltd')
        ->assertSee('Nova Hydrotech Nigeria Ltd');
});

it('filters the register by search term and blacklist state', function () {
    Contractor::factory()->create(['name' => 'Harmony Civil Works Ltd', 'is_blacklisted' => false]);
    Contractor::factory()->create(['name' => 'Rayfield Energy Systems', 'is_blacklisted' => true]);

    Livewire::actingAs($this->admin)
        ->test(ContractorIndex::class)
        ->set('search', 'Harmony')
        ->assertSee('Harmony Civil Works Ltd')
        ->assertDontSee('Rayfield Energy Systems')
        ->set('search', '')
        ->set('blacklistedOnly', true)
        ->assertSee('Rayfield Energy Systems')
        ->assertDontSee('Harmony Civil Works Ltd');
});

it('registers a firm through the action', function () {
    Livewire::actingAs($this->admin)
        ->test(ContractorIndex::class)
        ->set('name', 'Bridgeline Consortium Ltd')
        ->set('rcNumber', 'RC-884120')
        ->set('firmType', 'contractor')
        ->set('category', 'civil works')
        ->call('register')
        ->assertHasNoErrors();

    $contractor = Contractor::query()->where('rc_number', 'RC-884120')->first();

    expect($contractor)->not->toBeNull()
        ->and($contractor->name)->toBe('Bridgeline Consortium Ltd')
        ->and($contractor->is_blacklisted)->toBeFalse()
        ->and($contractor->created_by_tenant_id)->toBe($this->works->id);
});

it('links to the existing firm instead of duplicating an RC number', function () {
    Contractor::factory()->create(['name' => 'Harmony Civil Works Ltd', 'rc_number' => 'RC-100200']);

    $component = Livewire::actingAs($this->admin)
        ->test(ContractorIndex::class)
        ->set('name', 'Harmony Civil Works Limited')
        ->set('rcNumber', 'RC-100200')
        ->call('register')
        ->assertHasNoErrors();

    expect(Contractor::query()->where('rc_number', 'RC-100200')->count())->toBe(1)
        ->and($component->get('notice'))->toContain('already registered');
});

it('denies the whole registry to a role without contractors.view', function () {
    $monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);

    Livewire::actingAs($monitor)
        ->test(ContractorIndex::class)
        ->assertForbidden();
});
