<?php

namespace App\Actions\Projects\Concerns;

use App\Actions\Iam\CheckTenantMembership;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Tenant;
use App\Models\User;

/**
 * `manager_id` is a plain FK to the GLOBAL `users` table, so nothing in the
 * schema stops an MDA naming a stranger — or a member of another ministry — as
 * the officer accountable for its project. That is the same "side door into an
 * MDA" AssignProjectMember refuses (design §3), and it is refused here for the
 * same reason and through the same gate.
 *
 * It lives in a concern rather than in one Action because both writers of the
 * column (RegisterProject, UpdateProjectDetails) must enforce it: a guard held
 * by only one of two doors is not a guard.
 */
trait ChecksProjectManager
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function assertManagerIsAMember(array $attributes, ?Tenant $tenant = null): void
    {
        // Absent means "not being set"; null means "cleared" — a project with
        // no named manager is a legitimate state (it is nullable in §1.4).
        if (! array_key_exists('manager_id', $attributes) || $attributes['manager_id'] === null) {
            return;
        }

        $manager = User::query()->find($attributes['manager_id']);

        if ($manager === null || ! (new CheckTenantMembership)($manager, $tenant)) {
            throw ProjectRuleViolation::managerNotAMember();
        }
    }
}
