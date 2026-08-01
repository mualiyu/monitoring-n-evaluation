<?php

namespace App\Jobs\Middleware;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Closure;

/**
 * Job middleware that re-initializes tenancy inside the queue worker from the
 * tenant id the job captured at dispatch time. Applied automatically by the
 * TenantAware trait. runAs() syncs the permission team and restores the
 * previous (global) context afterwards, so nothing bleeds into the next job.
 */
class SetTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        if (property_exists($job, 'tenantId') && $job->tenantId !== null) {
            $tenant = Tenant::query()
                ->where('is_active', true)
                ->find($job->tenantId);

            // Tenant deactivated or deleted after dispatch: its queued work
            // must not execute. Delete rather than fail — this is expected
            // lifecycle, not an error worth retries/failed_jobs noise.
            if ($tenant === null) {
                if (method_exists($job, 'delete')) {
                    $job->delete();
                }

                return null;
            }

            return app(CurrentTenant::class)->runAs($tenant, fn () => $next($job));
        }

        return $next($job);
    }
}
