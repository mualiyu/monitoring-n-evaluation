<?php

namespace App\Actions\Iam;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Redeems an invitation token: finds-or-creates the user, grants access, and
 * stamps the invitation — one transaction, row-locked against double accept.
 * 404 for unknown tokens (never confirm which part was wrong); 410 for
 * expired/revoked/used ones.
 */
class AcceptInvitation
{
    /**
     * @param  array{name?: string, password?: string}  $profile  used only when the user does not exist yet
     */
    public function __invoke(string $plaintextToken, array $profile = []): User
    {
        $user = DB::transaction(function () use ($plaintextToken, $profile) {
            $invitation = Invitation::query()
                ->where('token_hash', Invitation::hashToken($plaintextToken))
                ->lockForUpdate()
                ->first();

            abort_unless($invitation !== null, 404);

            if (! $invitation->isPending()) {
                throw new HttpException(410, __('This invitation is no longer valid. Ask your administrator for a new one.'));
            }

            $user = User::query()->where('email', $invitation->email)->first();

            if ($user === null) {
                abort_if($profile === [], 422, __('Name and password are required to create your account.'));

                $user = User::create([
                    'name' => $profile['name'],
                    'email' => $invitation->email,
                    'password' => Hash::make($profile['password']),
                ]);
                // The token reaching us proves the address is live.
                $user->forceFill(['email_verified_at' => now()])->save();

                event(new Registered($user));
            }

            $invitation->tenant === null
                ? (new AssignRole)($user, $invitation->role)
                : (new GrantTenantAccess)($user, $invitation->tenant, $invitation->role, $invitation->invitedBy);

            $invitation->forceFill([
                'accepted_at' => now(),
                'accepted_user_id' => $user->id,
            ])->save();

            return $user;
        });

        activity()
            ->causedBy($user)
            ->withProperties(['email' => $user->email])
            ->log('invitation.accepted');

        return $user;
    }
}
