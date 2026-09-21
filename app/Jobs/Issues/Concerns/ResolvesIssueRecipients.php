<?php

namespace App\Jobs\Issues\Concerns;

use App\Enums\Role;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who hears about a challenge.
 *
 * MDA admins for the escalations and the critical raises, because those are
 * statements to the office that owns the project, not to the person who filed
 * them. spatie resolves the `role` scope against the CURRENT permission team,
 * which SetTenantContext has already bound — so tenantUsersWithRole() can only
 * ever return users of this MDA.
 *
 * Deliberately not every user in the workspace: a notice that reaches people
 * who cannot act on it is the fastest way to teach a ministry to filter this
 * sender into a folder nobody opens.
 */
trait ResolvesIssueRecipients
{
    /**
     * @param  list<Role>  $roles
     * @return Collection<int, User>
     */
    protected function tenantUsersWithRole(array $roles): Collection
    {
        return User::query()
            ->role(array_map(fn (Role $role): string => $role->value, $roles))
            ->where('is_active', true)
            ->get();
    }

    /** The office accountable for a workspace's delivery record. */
    /** @return Collection<int, User> */
    protected function mdaAdmins(): Collection
    {
        return $this->tenantUsersWithRole([Role::MdaAdmin]);
    }

    /**
     * State oversight — for the one notice that leaves the MDA (a critical
     * incident).
     *
     * Resolved inside runWithoutTenant(), because oversight roles are assigned
     * in the GLOBAL permission team and spatie's `role` scope filters on
     * whatever team is currently bound. Inside a worker that has just bound an
     * MDA, asking without the switch would return nobody at all — silently,
     * which is the worst possible failure for an escalation channel. The
     * previous context is restored on the way out.
     *
     * @return Collection<int, User>
     */
    protected function stateOversight(): Collection
    {
        return app(CurrentTenant::class)->runWithoutTenant(
            fn (): Collection => User::query()
                ->role(array_map(fn (Role $role): string => $role->value, Role::oversightRoles()))
                ->where('is_active', true)
                ->get()
        );
    }
}
