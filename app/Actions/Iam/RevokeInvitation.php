<?php

namespace App\Actions\Iam;

use App\Models\Invitation;
use App\Models\User;
use InvalidArgumentException;

class RevokeInvitation
{
    public function __invoke(User $actor, Invitation $invitation): void
    {
        if (! $invitation->isPending()) {
            throw new InvalidArgumentException('Only pending invitations can be revoked.');
        }

        $invitation->forceFill(['revoked_at' => now()])->save();

        activity()
            ->causedBy($actor)
            ->performedOn($invitation)
            ->log('invitation.revoked');
    }
}
