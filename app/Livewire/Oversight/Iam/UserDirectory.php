<?php

declare(strict_types=1);

namespace App\Livewire\Oversight\Iam;

use App\Actions\Iam\InviteUser;
use App\Actions\Iam\ListPendingInvitations;
use App\Actions\Iam\ListUserWorkspaces;
use App\Actions\Iam\ResendInvitation;
use App\Actions\Iam\RevokeInvitation;
use App\Actions\Iam\SetUserActive;
use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The state's user directory (auth-surfaces.md §2–3): every account on the
 * platform, the authority each one holds, the workspaces it can enter — and
 * the two levers oversight owns: provisioning (invitations, per the
 * Role::invitableBy chain) and the platform-wide is_active switch.
 *
 * User is a GLOBAL identity (no TenantScope), so listing users here needs no
 * tenancy bypass; workspaces come from ListUserWorkspaces, the sanctioned
 * membership reader — one call per row on the page, accepted deliberately:
 * the gate table has exactly one sanctioned reader per direction and 25 tiny
 * indexed lookups beat a second reader that could drift from it.
 *
 * `users.view` (global team) opens the page, `users.invite` works the
 * invitation panel, `users.manage` flips accounts. ExecutiveViewer and
 * DataQualityReviewer hold none of these — staffing is administration, not
 * analysis.
 */
#[Layout('layouts::oversight')]
class UserDirectory extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public string $inviteEmail = '';

    public string $inviteRole = '';

    public string $inviteTenantId = '';

    /** The account an activation flip is being confirmed for. */
    public ?int $togglingUserId = null;

    public bool $togglingTo = false;

    /** An Action's own refusal, shown verbatim. */
    public ?string $failure = null;

    public function mount(): void
    {
        abort_unless($this->actor()->holdsGlobalPermission('users.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /* ------------------------------------------------------------------ */
    /* Directory */
    /* ------------------------------------------------------------------ */

    /** @return LengthAwarePaginator<int, User> */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return User::query()
            ->when($this->search !== '', function (Builder $query): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%';

                $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->orderBy('name')
            ->paginate(25);
    }

    /**
     * Every role held by the users on this page, whichever team it lives in.
     * "Who is this person, everywhere" is the question the directory exists
     * to answer, and it cannot be asked through the team-bound relation —
     * spatie resolves roles against ONE team at a time, and a context switch
     * per user per workspace would cost a query storm. One indexed pivot read
     * for the whole page instead; query builder, not raw SQL.
     *
     * @return Collection<int|string, Collection<int, object{user_id: int, team_id: int, name: string}>>
     */
    #[Computed]
    public function roleAssignments(): Collection
    {
        /** @var Collection<int|string, Collection<int, object{user_id: int, team_id: int, name: string}>> */
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereIn('model_has_roles.model_id', $this->users()->getCollection()->modelKeys())
            ->orderBy('roles.name')
            ->get([
                'model_has_roles.model_id as user_id',
                'model_has_roles.tenant_id as team_id',
                'roles.name',
            ])
            ->groupBy('user_id');
    }

    /**
     * Human-readable role chips for one directory row: global roles plain,
     * tenant roles suffixed with the workspace they apply to.
     *
     * @return list<string>
     */
    public function rolesFor(User $user): array
    {
        return $this->roleAssignments()
            ->get($user->id, collect())
            ->map(function (object $row): string {
                $label = Role::tryFrom($row->name)?->label() ?? $row->name;

                return (int) $row->team_id === CurrentTenant::GLOBAL_TEAM
                    ? $label
                    : $label.' — '.($this->tenantNames()->get((int) $row->team_id) ?? __('Unknown workspace'));
            })
            ->values()
            ->all();
    }

    /**
     * The workspaces one row's user may enter, via the sanctioned reader.
     *
     * @return Collection<int, TenantMembership>
     */
    public function workspacesFor(User $user): Collection
    {
        return (new ListUserWorkspaces)($user);
    }

    /** @return Collection<int, string> tenant id => name */
    #[Computed]
    public function tenantNames(): Collection
    {
        return Tenant::query()->pluck('name', 'id');
    }

    /* ------------------------------------------------------------------ */
    /* Activate / deactivate — always confirmed, never your own account */
    /* ------------------------------------------------------------------ */

    public function confirmSetActive(int $userId, bool $active): void
    {
        abort_unless($this->actor()->holdsGlobalPermission('users.manage'), 403);

        $this->failure = null;
        $this->togglingUserId = $userId;
        $this->togglingTo = $active;

        $this->dispatch('open-modal', 'set-user-active');
    }

    #[Computed]
    public function togglingUser(): ?User
    {
        return $this->togglingUserId === null
            ? null
            : User::query()->find($this->togglingUserId);
    }

    public function applySetActive(): void
    {
        abort_unless($this->actor()->holdsGlobalPermission('users.manage'), 403);

        $user = User::query()->findOrFail($this->togglingUserId);
        $activating = $this->togglingTo;

        try {
            (new SetUserActive)($this->actor(), $user, $activating);
        } catch (InvalidArgumentException $e) {
            // The Action's refusal (e.g. flipping your own account).
            $this->failure = $e->getMessage();
            $this->dispatch('close-modal', 'set-user-active');

            return;
        }

        $this->togglingUserId = null;
        unset($this->users, $this->togglingUser);

        $this->dispatch('close-modal', 'set-user-active');
        session()->flash('status', $activating
            ? __(':name can sign in again on every surface.', ['name' => $user->name])
            : __(':name is deactivated and can no longer sign in anywhere.', ['name' => $user->name]));
    }

    /* ------------------------------------------------------------------ */
    /* Invitations — oversight provisions per the chain */
    /* ------------------------------------------------------------------ */

    /**
     * Every pending invitation platform-wide: the oversight-role ones this
     * surface issues AND the MdaAdmin ones it issues into workspaces.
     *
     * @return Collection<int, Invitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        return (new ListPendingInvitations)();
    }

    /** @return array<string, string> roles THIS actor may offer, value => label */
    #[Computed]
    public function invitableRoles(): array
    {
        return collect(Role::invitableBy($this->actorRoles()))
            ->mapWithKeys(fn (Role $role): array => [$role->value => $role->label()])
            ->all();
    }

    /** @return array<int, string> active workspaces a tenant-scoped invite may target */
    #[Computed]
    public function tenantOptions(): array
    {
        return Tenant::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])
            ->all();
    }

    /** Whether the chosen role is workspace-scoped and so needs a tenant. */
    public function inviteRoleNeedsTenant(): bool
    {
        $role = Role::tryFrom($this->inviteRole);

        return $role !== null && in_array($role, Role::tenantRoles(), true);
    }

    public function invite(): void
    {
        abort_unless($this->actor()->holdsGlobalPermission('users.invite'), 403);

        $this->failure = null;

        $this->validate([
            'inviteEmail' => ['required', 'email:rfc', 'max:255'],
            'inviteRole' => ['required', Rule::in(array_keys($this->invitableRoles()))],
            'inviteTenantId' => $this->inviteRoleNeedsTenant()
                ? ['required', Rule::in(array_keys($this->tenantOptions()))]
                : ['nullable'],
        ], [], [
            'inviteEmail' => __('email address'),
            'inviteRole' => __('role'),
            'inviteTenantId' => __('workspace'),
        ]);

        $tenant = $this->inviteRoleNeedsTenant()
            ? Tenant::query()->findOrFail((int) $this->inviteTenantId)
            : null;

        try {
            (new InviteUser)($this->actor(), $this->inviteEmail, Role::from($this->inviteRole), $tenant);
        } catch (InvalidArgumentException $e) {
            // The Action's refusal (the chain outranks any form state).
            $this->failure = $e->getMessage();

            return;
        }

        $this->reset(['inviteEmail', 'inviteRole', 'inviteTenantId']);
        unset($this->invitations);

        session()->flash('status', __('Invitation sent. The link expires in :days days.', [
            'days' => (int) config('platform.auth.invitation_ttl_days'),
        ]));
    }

    public function revokeInvitation(int $invitationId): void
    {
        abort_unless($this->actor()->holdsGlobalPermission('users.invite'), 403);

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
        abort_unless($this->actor()->holdsGlobalPermission('users.invite'), 403);

        $this->failure = null;
        $invitation = $this->pendingInvitation($invitationId);

        try {
            (new ResendInvitation)($this->actor(), $invitation);
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 429) {
                throw $e;
            }

            // The Action throttles re-sends (3 per invitation per hour).
            $this->failure = __('This invitation has been re-sent too many times in the last hour. Try again later.');

            return;
        }

        unset($this->invitations);
        session()->flash('status', __('Invitation re-sent to :email with a fresh link — the old one is dead.', [
            'email' => $invitation->email,
        ]));
    }

    /** Resolve a wire-supplied id against the pending list; anything else is a 404. */
    private function pendingInvitation(int $invitationId): Invitation
    {
        $invitation = $this->invitations()->firstWhere('id', $invitationId);

        abort_if($invitation === null, 404);

        return $invitation;
    }

    /* ------------------------------------------------------------------ */

    /** Whether the viewer may work the invitation panel (view drives markup only). */
    public function actorCanInvite(): bool
    {
        return $this->actor()->holdsGlobalPermission('users.invite');
    }

    /** Whether the viewer may flip accounts (view drives markup only). */
    public function actorCanManage(): bool
    {
        return $this->actor()->holdsGlobalPermission('users.manage');
    }

    /** @return list<Role> the actor's roles in the GLOBAL team (this surface binds no tenant) */
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
        return view('livewire.oversight.iam.user-directory');
    }
}
