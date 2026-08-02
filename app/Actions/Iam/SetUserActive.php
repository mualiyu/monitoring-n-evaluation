<?php

namespace App\Actions\Iam;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Flips a user's platform-wide `is_active` flag — the switch the `active`
 * middleware reads on every authenticated request, so deactivation locks the
 * account out of every surface at once (and revokes nothing: memberships and
 * roles survive for the day the person returns from leave or secondment).
 *
 * Authority is read from the GLOBAL permission team: suspending a PERSON is a
 * state-level act. An MDA that wants someone gone from its workspace revokes
 * workspace access instead (RevokeTenantAccess), which touches only its own
 * gate.
 *
 * Actors can never flip their own account — an admin who deactivated
 * themselves mid-session would leave the instance without the hands to undo
 * it, and "deactivate the person on the screen" one row off is exactly how
 * that happens.
 */
class SetUserActive
{
    public function __invoke(User $actor, User $user, bool $active): void
    {
        if (! $actor->holdsGlobalPermission('users.manage')) {
            throw new AuthorizationException('Managing user accounts requires state-level users.manage authority.');
        }

        if ($actor->is($user)) {
            throw new InvalidArgumentException('You cannot activate or deactivate your own account.');
        }

        if ($user->is_active === $active) {
            return; // Idempotent: no phantom audit rows for a no-op.
        }

        $user->forceFill(['is_active' => $active])->save();

        activity()
            ->causedBy($actor)
            ->performedOn($user)
            ->log($active ? 'user.activated' : 'user.deactivated');
    }
}
