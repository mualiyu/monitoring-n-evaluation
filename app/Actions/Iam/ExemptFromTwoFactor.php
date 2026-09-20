<?php

namespace App\Actions\Iam;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Releases an account from — or returns it to — the role-mandated 2FA
 * enrolment that RequireTwoFactor enforces (auth-surfaces.md §4). The single
 * writer of users.two_factor_exempted_at.
 *
 * An exemption removes the OBLIGATION to enrol, nothing more: a second factor
 * the user has already confirmed keeps challenging at login, and the setup
 * page stays available to them voluntarily.
 *
 * Authority mirrors SetUserActive — exempting a PERSON from a state-wide
 * security rule is a state-level act, read from the GLOBAL permission team.
 * Nobody exempts themselves: an admin waving their own second factor is the
 * exact event the rule exists to prevent.
 *
 * A null actor is the platform itself (seeders, artisan) and is accepted only
 * from the console. A request handler passing null to skip the authority
 * check is a bug, and it fails loudly rather than silently.
 */
class ExemptFromTwoFactor
{
    public function __invoke(?User $actor, User $user, bool $exempt = true): void
    {
        if ($actor === null) {
            if (! app()->runningInConsole()) {
                throw new RuntimeException('A two-factor exemption without an actor is only valid from the console.');
            }
        } else {
            if (! $actor->holdsGlobalPermission('users.manage')) {
                throw new AuthorizationException('Changing a two-factor exemption requires state-level users.manage authority.');
            }

            if ($actor->is($user)) {
                throw new InvalidArgumentException('You cannot change the two-factor exemption on your own account.');
            }
        }

        if (($user->two_factor_exempted_at !== null) === $exempt) {
            return; // Idempotent: no phantom audit rows for a no-op.
        }

        $user->forceFill(['two_factor_exempted_at' => $exempt ? now() : null])->save();

        activity()
            ->causedBy($actor)
            ->performedOn($user)
            ->log($exempt ? 'user.two_factor_exempted' : 'user.two_factor_exemption_lifted');
    }
}
