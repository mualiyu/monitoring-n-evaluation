<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Lockout;

/**
 * There is no permanent account lock (an attacker knowing an official's
 * email could otherwise deny them access before a deadline) — so lockouts
 * must at least be VISIBLE: every one is an audit entry.
 */
class RecordLockout
{
    public function handle(Lockout $event): void
    {
        activity()
            ->withProperties([
                'email' => (string) $event->request->input('email'),
                'ip' => $event->request->ip(),
                'host' => $event->request->getHost(),
                'user_agent' => (string) $event->request->userAgent(),
            ])
            ->log('auth.lockout');
    }
}
