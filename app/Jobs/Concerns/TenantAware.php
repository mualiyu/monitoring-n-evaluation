<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\SetTenantContext;
use App\Tenancy\CurrentTenant;

/**
 * Use on every queued job that touches tenant-owned data. Call
 * $this->captureTenant() in the job constructor; the middleware rebinds the
 * tenant inside the worker. Jobs without a captured tenant run unscoped only
 * if they never query tenant-owned models (the fail-closed scope enforces it).
 */
trait TenantAware
{
    public ?int $tenantId = null;

    public function captureTenant(): void
    {
        $this->tenantId = app(CurrentTenant::class)->id();
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new SetTenantContext];
    }
}
