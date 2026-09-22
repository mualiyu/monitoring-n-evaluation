<?php

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Models\Lga;
use App\Models\Project;
use App\Models\Sector;
use App\Models\User;
use App\Support\Gis\ProjectMapBuilder;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The workspace GIS dashboard: this MDA's project sites on a map.
 *
 * The TenantScope confines the read to the bound workspace, and visibleTo()
 * narrows it by role — the same definition the project register uses, so a
 * consultant's map shows exactly the projects their register lists.
 */
class BuildProjectMap
{
    /**
     * @param  array{status?: ProjectStatus|null, sector?: Sector|null, lga?: Lga|null, overdue?: bool}  $filters
     * @return array<string, mixed>
     */
    public function __invoke(User $actor, array $filters = []): array
    {
        if (! $actor->can('viewAny', Project::class)) {
            throw new AuthorizationException('Viewing the project map requires access to this workspace’s projects.');
        }

        return (new ProjectMapBuilder)(Project::query()->visibleTo($actor), $filters);
    }
}
