<?php

namespace App\Actions\Iam;

use App\Enums\MembershipStatus;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\Iam\UserInvited;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issues an invitation. The plaintext token exists only in the notification
 * URL — the database stores its sha256. "One pending invitation per
 * (tenant, email)" is enforced HERE, in the transaction, because a unique
 * index over a nullable tenant_id would enforce nothing.
 */
class InviteUser
{
    public function __invoke(User $inviter, string $email, Role $role, ?Tenant $tenant = null): Invitation
    {
        $inviterRoles = array_values(array_filter(
            Role::cases(),
            fn (Role $r) => $inviter->hasRole($r->value),
        ));

        if (! in_array($role, Role::invitableBy($inviterRoles), true)) {
            throw new InvalidArgumentException(
                "None of the inviter's roles may invite [{$role->value}]."
            );
        }

        $isTenantRole = in_array($role, Role::tenantRoles(), true);

        if ($isTenantRole && $tenant === null) {
            throw new InvalidArgumentException("Tenant role [{$role->value}] requires a tenant.");
        }

        // An MDA admin may only staff a workspace they actively belong to;
        // oversight inviters (who invite MdaAdmins) are exempt from membership.
        // NB: enum cases must be compared by identity — array_intersect casts
        // its operands to string and fatals on backed-enum objects.
        $oversightRoles = Role::oversightRoles();
        $isOversightInviter = array_filter(
            $inviterRoles,
            fn (Role $r) => in_array($r, $oversightRoles, true),
        ) !== [];

        // $tenant is non-null here: the guard above rejects a tenant role without one.
        if ($isTenantRole && ! $isOversightInviter) {
            $isMember = TenantMembership::query()
                ->where('tenant_id', $tenant->id) // sanctioned: gate table (see model docblock)
                ->where('user_id', $inviter->id)
                ->where('status', MembershipStatus::Active)
                ->exists();

            if (! $isMember) {
                throw new InvalidArgumentException(
                    'Inviters must hold an active membership in the workspace they are inviting into.'
                );
            }
        }

        if (! $isTenantRole && $tenant !== null) {
            throw new InvalidArgumentException("Oversight role [{$role->value}] cannot be scoped to a tenant.");
        }

        $email = Str::lower(trim($email));
        $plaintext = Str::random(64);

        $invitation = DB::transaction(function () use ($inviter, $email, $role, $tenant, $plaintext) {
            Invitation::query()
                ->where('tenant_id', $tenant?->id) // sanctioned: invitations are pre-tenancy (see model docblock)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return Invitation::create([
                'tenant_id' => $tenant?->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => Invitation::hashToken($plaintext),
                'invited_by_id' => $inviter->id,
                'expires_at' => now()->addDays((int) config('platform.auth.invitation_ttl_days')),
            ]);
        });

        Notification::route('mail', $email)->notify(new UserInvited($invitation, $plaintext));

        return $invitation;
    }
}
