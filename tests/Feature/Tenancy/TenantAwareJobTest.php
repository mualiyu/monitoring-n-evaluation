<?php

use App\Jobs\Concerns\TenantAware;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public static ?int $observedTenantId = null;

    public function __construct()
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        self::$observedTenantId = app(CurrentTenant::class)->id();
    }
}

beforeEach(function () {
    RecordTenantJob::$observedTenantId = null;
    actingWithoutTenant();
});

it('re-initializes the captured tenant inside the queue worker', function () {
    $tenant = Tenant::factory()->create();

    $job = app(CurrentTenant::class)->runAs($tenant, fn () => new RecordTenantJob);

    expect($job->tenantId)->toBe($tenant->id);

    dispatch($job); // sync driver runs through job middleware immediately

    expect(RecordTenantJob::$observedTenantId)->toBe($tenant->id)
        ->and(app(CurrentTenant::class)->bound())->toBeFalse();
});

it('runs without tenant context when none was captured', function () {
    dispatch(new RecordTenantJob);

    expect(RecordTenantJob::$observedTenantId)->toBeNull();
});

it('restores the dispatching tenant context after handling a job for another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $current = app(CurrentTenant::class);

    $current->runAs($tenantA, function () use ($current, $tenantA, $tenantB) {
        $job = $current->runAs($tenantB, fn () => new RecordTenantJob);

        dispatch($job); // sync: handled inline through the job middleware

        expect(RecordTenantJob::$observedTenantId)->toBe($tenantB->id)
            ->and($current->id())->toBe($tenantA->id);
    });
});

it('drops queued work for a deactivated tenant instead of executing it', function () {
    $tenant = Tenant::factory()->create();
    $job = app(CurrentTenant::class)->runAs($tenant, fn () => new RecordTenantJob);

    // Property write, not ->update(): is_active is guarded-by-omission on
    // Tenant — only App\Actions\Oversight\SetTenantActive assigns it, so no
    // ->update($request->validated()) can deactivate a workspace by accident.
    $tenant->forceFill(['is_active' => false])->save();

    dispatch($job);

    expect(RecordTenantJob::$observedTenantId)->toBeNull();
});
