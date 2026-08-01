<?php

/**
 * The state vendor registry (projects-module.md §1.3): global on purpose, and
 * the one table in this module without a tenant_id.
 *
 * What must hold: every MDA sees every firm (that is the point — a firm barred
 * by Works must not keep winning in Health), while the CONTRACTS that name
 * those firms stay tenant-owned and invisible across workspaces. Adding a firm
 * is a workspace act; editing or barring one is state authority.
 */

use App\Actions\Projects\BlacklistContractor;
use App\Actions\Projects\LiftContractorBlacklist;
use App\Actions\Projects\RegisterContractor;
use App\Actions\Projects\UpdateContractor;
use App\Enums\FirmType;
use App\Enums\Role;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->stateAdmin = userWithRole(Role::StateAdmin);
});

it('registers a firm from a workspace and records which workspace registered it', function () {
    $contractor = (new RegisterContractor)($this->admin, [
        'name' => 'Northgate Construction Ltd',
        'rc_number' => 'rc123456',
        'type' => FirmType::Contractor,
        'category' => 'Civil Works',
    ]);

    expect($contractor->exists)->toBeTrue()
        ->and($contractor->rc_number)->toBe('RC123456')       // normalised
        ->and($contractor->created_by_id)->toBe($this->admin->id)
        ->and($contractor->created_by_tenant_id)->toBe($this->works->id) // provenance, not a scope key
        ->and($contractor->is_blacklisted)->toBeFalse();
});

it('dedupes on RC number instead of creating a second row for one legal entity', function () {
    $first = (new RegisterContractor)($this->admin, ['name' => 'Hilltop Works Ltd', 'rc_number' => 'RC999111']);

    $healthAdmin = memberOf(User::factory()->create(), $this->health, Role::MdaAdmin);
    app(CurrentTenant::class)->set($this->health);

    $second = (new RegisterContractor)($healthAdmin->fresh(), [
        'name' => 'Hilltop Works Limited',  // the same firm, spelled differently
        'rc_number' => 'RC999111',
    ]);

    expect($second->id)->toBe($first->id)
        ->and(Contractor::query()->count())->toBe(1)
        // a "register" call is never an edit of a record another MDA relies on
        ->and($second->name)->toBe('Hilltop Works Ltd');
});

it('refuses a registration payload that tries to assert a blacklisting', function () {
    $contractor = (new RegisterContractor)($this->admin, [
        'name' => 'Lakeside Supplies Ltd',
        'rc_number' => 'RC777000',
        'is_blacklisted' => true,
        'blacklist_reason' => 'Self-declared',
    ]);

    expect($contractor->is_blacklisted)->toBeFalse()
        ->and($contractor->blacklist_reason)->toBeNull();
});

it('shows every firm in every workspace while keeping contracts tenant-owned', function () {
    $contractor = (new RegisterContractor)($this->admin, ['name' => 'Central Roads Ltd', 'rc_number' => 'RC444555']);

    $worksContract = Contract::factory()->create([
        'contractor_id' => $contractor->id,
        'created_by_id' => $this->admin->id,
    ]);

    app(CurrentTenant::class)->set($this->health);

    expect(Contractor::query()->whereKey($contractor->id)->exists())->toBeTrue()  // global, deliberately
        ->and(Contract::query()->whereKey($worksContract->id)->exists())->toBeFalse(); // tenant-owned
});

it('lets state oversight blacklist a firm and refuses the same to an MDA admin', function () {
    $contractor = Contractor::factory()->create();

    expect(fn () => (new BlacklistContractor)($contractor, $this->admin, 'Abandoned two sites.'))
        ->toThrow(AuthorizationException::class);

    (new BlacklistContractor)($contractor, $this->stateAdmin, 'Abandoned two sites without notice.');

    expect($contractor->fresh()->is_blacklisted)->toBeTrue()
        ->and($contractor->fresh()->blacklist_reason)->toBe('Abandoned two sites without notice.')
        ->and(Contractor::query()->eligible()->whereKey($contractor->id)->exists())->toBeFalse();
});

it('requires a reason to bar a firm and to re-admit one', function () {
    $contractor = Contractor::factory()->create();

    expect(fn () => (new BlacklistContractor)($contractor, $this->stateAdmin, '   '))
        ->toThrow(InvalidArgumentException::class);

    (new BlacklistContractor)($contractor, $this->stateAdmin, 'Repeated abandonment.');

    expect(fn () => (new LiftContractorBlacklist)($contractor->fresh(), $this->stateAdmin, ''))
        ->toThrow(InvalidArgumentException::class);

    (new LiftContractorBlacklist)($contractor->fresh(), $this->stateAdmin, 'Tribunal ruling of 12 May.');

    expect($contractor->fresh()->is_blacklisted)->toBeFalse();
});

it('refuses to let a details update flip eligibility behind the reason requirement', function () {
    $contractor = Contractor::factory()->blacklisted()->create();

    expect(fn () => (new UpdateContractor)($contractor, $this->stateAdmin, ['is_blacklisted' => false]))
        ->toThrow(InvalidArgumentException::class, 'BlacklistContractor');

    expect($contractor->fresh()->is_blacklisted)->toBeTrue();
});

it('keeps editing a vendor record with state oversight, not with the MDA that added it', function () {
    $contractor = (new RegisterContractor)($this->admin, ['name' => 'Riverside Ltd', 'rc_number' => 'RC222333']);

    expect(fn () => (new UpdateContractor)($contractor, $this->admin, ['contact_phone' => '08010000000']))
        ->toThrow(AuthorizationException::class);

    (new UpdateContractor)($contractor, $this->stateAdmin, ['contact_phone' => '08010000000']);

    expect($contractor->fresh()->contact_phone)->toBe('08010000000');
});
