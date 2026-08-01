<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * The Login event is the only point that catches password, remember-me,
 * passkey and post-2FA logins alike. updateQuietly keeps the timestamp out
 * of the activity log as a model diff; the login itself is logged as an event
 * with host + IP, which is what an auditor actually asks for.
 */
class RecordSuccessfulLogin
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        // System-written columns, deliberately NOT in $fillable (user input
        // must never reach them), so they are assigned directly rather than
        // mass-assigned, then persisted with updateQuietly — the one sanctioned
        // silent write (auth-surfaces.md §5.4); saveQuietly stays banned.
        $user->last_login_at = now();
        $user->last_login_ip = request()->ip();
        $user->updateQuietly();

        activity()
            ->causedBy($user)
            ->withProperties([
                'ip' => request()->ip(),
                'host' => request()->getHost(),
                'guard' => $event->guard,
                'remember' => $event->remember,
            ])
            ->log('auth.login');
    }
}
