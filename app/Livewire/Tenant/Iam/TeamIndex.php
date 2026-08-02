<?php

declare(strict_types=1);

namespace App\Livewire\Tenant\Iam;

use App\Actions\Iam\InviteUser;
use App\Actions\Iam\ListPendingInvitations;
use App\Actions\Iam\ListTenantMembers;
use App\Actions\Iam\ResendInvitation;
use App\Actions\Iam\RevokeInvitation;
use App\Actions\Iam\RevokeTenantAccess;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The MDA's team desk (auth-surfaces.md §2–3): who can enter this workspace,
 * what they may do here, and the invitations still waiting on an answer.
 *
 * Every membership and invitation read goes through the sanctioned Iam
 * actions — TenantMembership and Invitation sit outside the TenantScope by
 * design (they gate/precede tenancy), so this component never queries either
 * table itself. Ids arriving over the wire are resolved AGAINST those lists,
 * which is what turns a foreign tenant's id into a 404 instead of an act.
 *
 * No pagination, deliberately: the sanctioned reader returns the whole
 * register, and an MDA's staff roster is tens of rows, not thousands — the
 * screen's job is "everyone, at a glance".
 *
 * `users.view` opens the page (MdaAdmin + MeOfficer), `users.invite` works
 * the invitation panel, `users.manage` revokes access. WHICH roles the
 * inviter may offer is the enum's chain (Role::invitableBy), enforced again
 * inside InviteUser — the form is a convenience, never the guard.
 */
#[Layout('layouts::tenant')]
class TeamIndex extends Component
{
    public string $inviteEmail = '';

    public string $inviteRole = '';

    /** The member a revocation is being confirmed for, and its reason. */
    public ?int $revokingUserId = null;

    public string $revokeReason = '';

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(): void
    {
        abort_unless($this->actor()->can('users.view'), 403);
    }

    /* ------------------------------------------------------------------ */
    /* Roster */
    /* ------------------------------------------------------------------ */

    /**
     * Every membership of THIS workspace, active and suspended, with each
     * user's roles-in-this-workspace loaded in one query — the permission
     * team is already bound to the current tenant, so the relation yields
     * exactly the roles that mean anything here.
     *
     * @return Collection<int, TenantMembership>
     */
    #[Computed]
    public function members(): Collection
    {
        // Eloquent collection wrapper: ListTenantMembers returns a base
        // Collection (values()), which has no loadMissing().
        $members = \Illuminate\Database\Eloquent\Collection::make((new ListTenantMembers)());

        $members->loadMissing('user.roles');

        return $members
            ->sortBy(fn (TenantMembership $membership): string => mb_strtolower($membership->user->name))
            ->values();
    }

    /** @return Collection<int, TenantMembership> */
    #[Computed]
    public function activeMembers(): Collection
    {
        return $this->members()->filter(
            fn (TenantMembership $membership): bool => $membership->isActive(),
        )->values();
    }

    /** @return Collection<int, TenantMembership> */
    #[Computed]
    public function suspendedMembers(): Collection
    {
        return $this->members()->reject(
            fn (TenantMembership $membership): bool => $membership->isActive(),
        )->values();
    }

    /* ------------------------------------------------------------------ */
    /* Invitations */
    /* ------------------------------------------------------------------ */

    /** @return Collection<int, Invitation> */
    #[Computed]
    public function invitations(): Collection
    {
        return (new ListPendingInvitations)(app(CurrentTenant::class)->getOrFail());
    }

    /**
     * The roles THIS actor may offer, per the invitation chain. Narrowed to
     * tenant roles because on this surface every invitation is into this
     * workspace — an oversight role could never be scoped to it.
     *
     * @return array<string, string> value => label
     */
    #[Computed]
    public function invitableRoles(): array
    {
        return collect(Role::invitableBy($this->actorRoles()))
            ->filter(fn (Role $role): bool => in_array($role, Role::tenantRoles(), true))
            ->mapWithKeys(fn (Role $role): array => [$role->value => $role->label()])
            ->all();
    }

    public function invite(): void
    {
        abort_unless($this->actor()->can('users.invite'), 403);

        $this->failure = null;

        $this->validate([
            'inviteEmail' => ['required', 'email:rfc', 'max:255'],
            'inviteRole' => ['required', Rule::in(array_keys($this->invitableRoles()))],
        ], [], [
            'inviteEmail' => __('email address'),
            'inviteRole' => __('role'),
        ]);

        try {
            (new InviteUser)(
                $this->actor(),
                $this->inviteEmail,
                Role::from($this->inviteRole),
                app(CurrentTenant::class)->getOrFail(),
            );
        } catch (InvalidArgumentException $e) {
            // The Action's refusal (the chain outranks any form state).
            $this->failure = $e->getMessage();

            return;
        }

        $this->reset(['inviteEmail', 'inviteRole']);
        unset($this->invitations);

        session()->flash('status', __('Invitation sent. The link expires in :days days.', [
            'days' => (int) config('platform.auth.invitation_ttl_days'),
        ]));
    }

    public function revokeInvitation(int $invitationId): void
    {
        abort_unless($this->actor()->can('users.invite'), 403);

        $this->failure = null;
        $invitation = $this->pendingInvitation($invitationId);

        (new RevokeInvitation)($this->actor(), $invitation);

        unset($this->invitations);
        session()->flash('status', __('Invitation to :email revoked — the link no longer works.', [
            'email' => $invitation->email,
        ]));
    }

    public function resendInvitation(int $invitationId): void
    {
        abort_unless($this->actor()->can('users.invite'), 403);

        $this->failure = null;
        $invitation = $this->pendingInvitation($invitationId);

        try {
            (new ResendInvitation)($this->actor(), $invitation);
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 429) {
                throw $e;
            }

            // The Action throttles re-sends (3 per invitation per hour) so a
            // misfiring click cannot turn the mailer into a spam cannon.
            $this->failure = __('This invitation has been re-sent too many times in the last hour. Try again later.');

            return;
        }

        unset($this->invitations);
        session()->flash('status', __('Invitation re-sent to :email with a fresh link — the old one is dead.', [
            'email' => $invitation->email,
        ]));
    }

    /**
     * Resolve a wire-supplied id against THIS workspace's pending list — the
     * sanctioned reader IS the lookup, so another tenant's invitation id (or
     * a spent one) is a 404, never an act on a foreign row.
     */
    private function pendingInvitation(int $invitationId): Invitation
    {
        $invitation = $this->invitations()->firstWhere('id', $invitationId);

        abort_if($invitation === null, 404);

        return $invitation;
    }

    /* ------------------------------------------------------------------ */
    /* Revoking workspace access — always confirmed, always with a reason */
    /* ------------------------------------------------------------------ */

    public function confirmRevoke(int $userId): void
    {
        abort_unless($this->actor()->can('users.manage'), 403);

        $this->resetErrorBag();
        $this->failure = null;
        $this->revokingUserId = $userId;
        $this->revokeReason = '';

        $this->dispatch('open-modal', 'revoke-access');
    }

    #[Computed]
    public function revokingUser(): ?User
    {
        return $this->members()->firstWhere('user_id', $this->revokingUserId)?->user;
    }

    public function revokeAccess(): void
    {
        abort_unless($this->actor()->can('users.manage'), 403);

        $membership = $this->members()->firstWhere('user_id', $this->revokingUserId);

        abort_if($membership === null, 404);

        $this->validate(
            ['revokeReason' => ['required', 'string', 'min:10', 'max:1000']],
            [],
            ['revokeReason' => __('reason')],
        );

        if ($membership->user->is($this->actor())) {
            // Locking yourself out of the workspace you administer is never
            // what was meant — another admin (or oversight) removes you.
            $this->failure = __('You cannot revoke your own workspace access.');
            $this->dispatch('close-modal', 'revoke-access');

            return;
        }

        (new RevokeTenantAccess)(
            $membership->user,
            app(CurrentTenant::class)->getOrFail(),
            $this->actor(),
            trim($this->revokeReason),
        );

        $this->revokingUserId = null;
        $this->revokeReason = '';
        unset($this->members, $this->activeMembers, $this->suspendedMembers, $this->revokingUser);

        $this->dispatch('close-modal', 'revoke-access');
        session()->flash('status', __(':name no longer has access to this workspace.', [
            'name' => $membership->user->name,
        ]));
    }

    /* ------------------------------------------------------------------ */

    /** @return list<Role> */
    private function actorRoles(): array
    {
        return array_values(array_filter(
            Role::cases(),
            fn (Role $role): bool => $this->actor()->hasRole($role->value),
        ));
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function render(): View
    {
        return view('livewire.tenant.iam.team-index');
    }
}
