<?php

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use App\Tenancy\Exceptions\CrossTenantWriteException;
use App\Tenancy\Exceptions\TenantNotResolvedException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Proves the BelongsToTenant contract using a throwaway table + model, so the
 * guarantee is locked in before any real domain model exists.
 */
#[Fillable(['name', 'tenant_id'])]
class ScopeProbe extends Model
{
    use BelongsToTenant;

    protected $table = 'scope_probes';
}

beforeEach(function () {
    Schema::create('scope_probes', function ($table) {
        $table->id();
        $table->foreignId('tenant_id')->constrained();
        $table->string('name');
        $table->timestamps();
    });

    $this->tenantA = Tenant::factory()->create(['slug' => 'alpha']);
    $this->tenantB = Tenant::factory()->create(['slug' => 'beta']);

    app(CurrentTenant::class)->runAs($this->tenantA, fn () => ScopeProbe::create(['name' => 'A record']));
    app(CurrentTenant::class)->runAs($this->tenantB, fn () => ScopeProbe::create(['name' => 'B record']));

    actingWithoutTenant();
});

it('scopes every query to the bound tenant', function () {
    actingOnTenant($this->tenantA);

    expect(ScopeProbe::all())->toHaveCount(1)
        ->and(ScopeProbe::first()->name)->toBe('A record')
        ->and(ScopeProbe::where('name', 'B record')->exists())->toBeFalse();
});

it('auto-fills tenant_id from the bound tenant on create', function () {
    actingOnTenant($this->tenantA);

    $probe = ScopeProbe::create(['name' => 'auto-filled']);

    expect($probe->tenant_id)->toBe($this->tenantA->id);
});

it('throws — not silently returns everything — when queried with no tenant bound', function () {
    ScopeProbe::count();
})->throws(TenantNotResolvedException::class);

it('throws when creating with no tenant bound and no bypass', function () {
    ScopeProbe::create(['name' => 'orphan']);
})->throws(TenantNotResolvedException::class);

it('refuses to create a record for a foreign tenant', function () {
    actingOnTenant($this->tenantA);

    ScopeProbe::create(['name' => 'smuggled', 'tenant_id' => $this->tenantB->id]);
})->throws(CrossTenantWriteException::class);

it('treats tenant_id as immutable', function () {
    actingOnTenant($this->tenantA);

    $probe = ScopeProbe::first();
    $probe->tenant_id = $this->tenantB->id;
    $probe->save();
})->throws(CrossTenantWriteException::class);

it('blocks mass updates that would move rows across tenants', function () {
    actingOnTenant($this->tenantA);

    ScopeProbe::query()->update(['tenant_id' => $this->tenantB->id]);
})->throws(CrossTenantWriteException::class);

it('requires an explicit bypass for event-less bulk writes', function () {
    actingOnTenant($this->tenantA);

    ScopeProbe::insert([
        ['name' => 'raw', 'tenant_id' => $this->tenantB->id, 'created_at' => now(), 'updated_at' => now()],
    ]);
})->throws(CrossTenantWriteException::class);

it('cannot reach another tenant record by id even when the id is known', function () {
    actingOnTenant($this->tenantB);
    $foreign = ScopeProbe::first();

    actingOnTenant($this->tenantA);

    expect(ScopeProbe::find($foreign->id))->toBeNull();
});

it('exposes all tenants records only through the explicit withoutTenancy bypass', function () {
    expect(ScopeProbe::withoutTenancy()->count())->toBe(2);
});

it('allows unscoped reads and explicit-tenant writes inside a bypass block', function () {
    $created = app(CurrentTenant::class)->bypass(function () {
        expect(ScopeProbe::count())->toBe(2);

        return ScopeProbe::create(['name' => 'backfilled', 'tenant_id' => $this->tenantB->id]);
    });

    expect($created->tenant_id)->toBe($this->tenantB->id);

    actingOnTenant($this->tenantB);
    expect(ScopeProbe::where('name', 'backfilled')->exists())->toBeTrue();
});

it('restores the previous context after runAs', function () {
    $current = app(CurrentTenant::class);
    $current->set($this->tenantA);

    $current->runAs($this->tenantB, function () {
        expect(ScopeProbe::first()->name)->toBe('B record');
    });

    expect($current->id())->toBe($this->tenantA->id)
        ->and(ScopeProbe::first()->name)->toBe('A record');
});
