<?php

namespace App\Jobs\Lifecycle\Concerns;

use App\Enums\ProjectRole;
use App\Enums\Role;
use App\Models\ProjectAssignment;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who hears about a lifecycle event.
 *
 * The three audiences live in different places and that is the subtlety worth
 * writing down: the contractor's people are rows in `project_assignments`, the
 * MDA admins hold their role in the TENANT permission team that
 * SetTenantContext has bound, and state oversight holds its role in the GLOBAL
 * team where no tenant is bound at all. Reading the third group from inside
 * the tenant context returns nobody, silently — so it is read inside
 * runWithoutTenant(), which is a permission-team switch and NOT a tenancy
 * bypass: `users` is not a tenant-scoped table.
 *
 * Deliberately never "everyone in the MDA": a notice that reaches people who
 * cannot act on it is how a ministry learns to filter this sender into a
 * folder nobody opens.
 */
trait ResolvesLifecycleRecipients
{
    /**
     * The contractor's own people on a project — the consultants actively
     * assigned to it, who are who a commencement notice is addressed to.
     *
     * @return Collection<int, User>
     */
    protected function contractorsOn(int $projectId): Collection
    {
        $userIds = ProjectAssignment::query()
            ->where('project_id', $projectId)
            ->where('role', ProjectRole::Consultant)
            ->active()
            ->pluck('user_id')
            ->unique()
            ->all();

        if ($userIds === []) {
            return new Collection;
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->where('is_active', true)
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    protected function mdaAdmins(): Collection
    {
        return User::query()
            ->role(Role::MdaAdmin->value)
            ->where('is_active', true)
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    protected function stateOversight(): Collection
    {
        return app(CurrentTenant::class)->runWithoutTenant(
            fn (): Collection => User::query()
                ->role([Role::StateAdmin->value, Role::SuperAdmin->value])
                ->where('is_active', true)
                ->get(),
        );
    }
}
