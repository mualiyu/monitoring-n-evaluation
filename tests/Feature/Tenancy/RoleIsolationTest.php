<?php

use App\Actions\Iam\AssignRole;
use App\Enums\Role;
use App\Models\Tenant;

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create(['slug' => 'alpha']);
    $this->tenantB = Tenant::factory()->create(['slug' => 'beta']);
});

it('grants a tenant role only inside its own tenant context', function () {
    $user = userWithRole(Role::MdaAdmin, $this->tenantA);

    actingOnTenant($this->tenantA);
    expect($user->fresh()->hasRole(Role::MdaAdmin->value))->toBeTrue();

    actingOnTenant($this->tenantB);
    expect($user->fresh()->hasRole(Role::MdaAdmin->value))->toBeFalse();

    actingWithoutTenant();
    expect($user->fresh()->hasRole(Role::MdaAdmin->value))->toBeFalse();
});

it('keeps oversight roles invisible inside tenant contexts', function () {
    $admin = userWithRole(Role::SuperAdmin);

    actingWithoutTenant();
    expect($admin->fresh()->hasRole(Role::SuperAdmin->value))->toBeTrue();

    actingOnTenant($this->tenantA);
    expect($admin->fresh()->hasRole(Role::SuperAdmin->value))->toBeFalse();
});

it('refuses to assign a tenant role without a tenant', function () {
    (new AssignRole)(userWithRole(Role::ExecutiveViewer), Role::MdaAdmin);
})->throws(InvalidArgumentException::class, 'requires a tenant');

it('refuses to scope an oversight role to a tenant', function () {
    (new AssignRole)(userWithRole(Role::ExecutiveViewer), Role::SuperAdmin, $this->tenantA);
})->throws(InvalidArgumentException::class, 'global team');

it('does not leak cached roles across a context switch on the same instance', function () {
    $user = userWithRole(Role::MdaAdmin, $this->tenantA);

    actingOnTenant($this->tenantA);
    $user->unsetRelation('roles');
    expect($user->hasRole(Role::MdaAdmin->value))->toBeTrue();

    actingOnTenant($this->tenantB);
    $user->unsetRelation('roles');
    expect($user->hasRole(Role::MdaAdmin->value))->toBeFalse();
});
