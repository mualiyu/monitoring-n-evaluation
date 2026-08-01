<?php

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create(['slug' => 'alpha']);
    $this->tenantB = Tenant::factory()->create(['slug' => 'beta']);

    app(CurrentTenant::class)->runAs($this->tenantA, fn () => TenantSetting::factory()
        ->create(['group' => 'branding', 'key' => 'primary_color', 'value' => '#0f5132']));
    app(CurrentTenant::class)->runAs($this->tenantB, fn () => TenantSetting::factory()
        ->create(['group' => 'branding', 'key' => 'primary_color', 'value' => '#1e3a8a']));

    actingWithoutTenant();
});

it('isolates settings between tenants', function () {
    actingOnTenant($this->tenantA);

    expect(TenantSetting::count())->toBe(1)
        ->and(TenantSetting::first()->value)->toBe('#0f5132');

    actingOnTenant($this->tenantB);

    expect(TenantSetting::count())->toBe(1)
        ->and(TenantSetting::first()->value)->toBe('#1e3a8a');
});

it('allows the same group and key across tenants but not within one', function () {
    actingOnTenant($this->tenantA);

    TenantSetting::create(['group' => 'branding', 'key' => 'primary_color', 'value' => 'dup']);
})->throws(UniqueConstraintViolationException::class);
