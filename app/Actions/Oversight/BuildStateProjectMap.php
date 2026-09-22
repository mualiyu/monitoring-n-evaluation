<?php

namespace App\Actions\Oversight;

use App\Enums\ProjectStatus;
use App\Models\Lga;
use App\Models\Project;
use App\Models\Sector;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Gis\ProjectMapBuilder;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The state-wide GIS dashboard: every MDA's project sites on one map.
 *
 * A cross-tenant read, so it lives here and re-checks the same GLOBAL
 * permission as the portfolio before bypassing tenancy (rules/tenancy.md).
 * bypass() rather than withoutTenancy(): the eager-loaded locations are
 * tenant-owned too, and would each hit the fail-closed scope otherwise.
 */
class BuildStateProjectMap
{
    /**
     * @param  array{tenant?: Tenant|null, status?: ProjectStatus|null, sector?: Sector|null, lga?: Lga|null, overdue?: bool}  $filters
     * @return array<string, mixed>
     */
    public function __invoke(User $actor, array $filters = []): array
    {
        if (! $actor->holdsGlobalPermission('oversight.portfolio.view')) {
            throw new AuthorizationException('The state project map requires oversight authority.');
        }

        return app(CurrentTenant::class)->bypass(
            fn (): array => (new ProjectMapBuilder)(Project::query(), $filters, withEntity: true),
        );
    }
}
