<?php

namespace App\Policies;

use App\Models\User;
use App\Tenancy\CurrentTenant;

/**
 * Two levels of configuration authority, kept apart on purpose.
 *
 * `manage` is the INSTANCE scope: the state's terminology, its statutory
 * deadline rules, its indicator bands. Read from the GLOBAL permission team,
 * so an MDA admin — who legitimately holds `settings.manage` inside their own
 * workspace — cannot retune the state's floor.
 *
 * `override` is the WORKSPACE scope: the same permission, read from the bound
 * workspace, over that workspace's own override rows. It requires a bound
 * tenant, which is what stops an oversight-surface request (no tenant bound)
 * from writing an override for nobody in particular.
 *
 * Neither ability reaches a record, so both are class-level; the settings
 * tables hold configuration, not domain records, and the record-level tenancy
 * check would have nothing to compare.
 */
class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('settings.manage') || $user->holdsGlobalPermission('settings.manage');
    }

    public function manage(User $user): bool
    {
        return $user->holdsGlobalPermission('settings.manage');
    }

    public function override(User $user): bool
    {
        return app(CurrentTenant::class)->bound() && $user->can('settings.manage');
    }
}
