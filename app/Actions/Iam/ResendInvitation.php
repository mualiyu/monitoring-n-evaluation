<?php

namespace App\Actions\Iam;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\Iam\UserInvited;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Re-sends a pending invitation with a ROTATED token and fresh expiry —
 * the old link dies the moment a new one is issued. Throttled per invitation.
 */
class ResendInvitation
{
    public function __invoke(User $actor, Invitation $invitation): Invitation
    {
        if (! $invitation->isPending()) {
            throw new InvalidArgumentException('Only pending invitations can be resent.');
        }

        $key = 'invitation-resend:'.$invitation->id;
        abort_if(RateLimiter::tooManyAttempts($key, 3), 429);
        RateLimiter::hit($key, 3600);

        $plaintext = Str::random(64);

        $invitation->forceFill([
            'token_hash' => Invitation::hashToken($plaintext),
            'expires_at' => now()->addDays((int) config('platform.auth.invitation_ttl_days')),
        ])->save();

        Notification::route('mail', $invitation->email)->notify(new UserInvited($invitation, $plaintext));

        activity()
            ->causedBy($actor)
            ->performedOn($invitation)
            ->log('invitation.resent');

        return $invitation;
    }
}
