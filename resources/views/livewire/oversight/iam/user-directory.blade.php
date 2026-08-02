{{--
    State user directory, oversight surface (App\Livewire\Oversight\Iam\UserDirectory).
--}}
<div>
    <x-ui.page-header
        :title="__('Users & roles')"
        :description="__('Every account on the platform, the authority it holds, and the workspaces it can enter. Deactivation locks an account out of every surface at once.')"
    />

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-5" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" class="mb-5" :title="__('That action could not be completed')">{{ $failure }}</x-ui.alert>
    @endif

    @if ($this->actorCanInvite())
        <x-ui.card
            class="mb-4"
            :title="__('Invite someone onto the platform')"
            :subtitle="__('Oversight roles serve the whole state; an entity administrator is invited into a specific workspace.')"
        >
            <form wire:submit="invite" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.form.group name="inviteEmail" :label="__('Email address')" required>
                    <x-ui.form.input
                        name="inviteEmail"
                        type="email"
                        :placeholder="__('name@example.gov.ng')"
                        wire:model="inviteEmail"
                    />
                </x-ui.form.group>

                <x-ui.form.group name="inviteRole" :label="__('Role')" required>
                    <x-ui.form.select
                        name="inviteRole"
                        :placeholder="__('Choose a role…')"
                        :options="$this->invitableRoles"
                        wire:model.live="inviteRole"
                    />
                </x-ui.form.group>

                @if ($this->inviteRoleNeedsTenant())
                    <x-ui.form.group name="inviteTenantId" :label="__('Workspace')" required>
                        <x-ui.form.select
                            name="inviteTenantId"
                            :placeholder="__('Choose a workspace…')"
                            :options="$this->tenantOptions"
                            wire:model="inviteTenantId"
                        />
                    </x-ui.form.group>
                @endif

                <div class="flex items-start pt-6">
                    <x-ui.button type="submit" icon="paper-airplane" loading="invite">{{ __('Send invitation') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endif

    @if ($this->invitations->isNotEmpty())
        <x-ui.card class="mb-4" flush :title="__('Pending invitations')">
            <x-ui.table
                :caption="__('Invitations awaiting acceptance, platform-wide')"
                class="p-4 sm:p-0"
                :headings="[__('Invited'), __('Role offered'), __('Workspace'), __('Invited by'), __('Expires'), '']"
            >
                @foreach ($this->invitations as $invitation)
                    <x-ui.table.row wire:key="invitation-{{ $invitation->id }}">
                        <x-ui.table.cell :label="__('Invited')" primary>{{ $invitation->email }}</x-ui.table.cell>

                        <x-ui.table.cell :label="__('Role offered')">
                            <span class="inline-flex items-center rounded-md bg-neutral-soft px-2 py-0.5 text-xs font-medium text-ink">
                                {{ $invitation->role->label() }}
                            </span>
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Workspace')">
                            <span class="text-ink-muted">{{ $invitation->tenant?->name ?? __('State-level') }}</span>
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Invited by')">
                            <span class="text-ink-muted">{{ $invitation->invitedBy?->name ?? '—' }}</span>
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Expires')">
                            <span class="text-ink-muted">{{ $invitation->expires_at->diffForHumans() }}</span>
                        </x-ui.table.cell>

                        <x-ui.table.cell align="right">
                            @if ($this->actorCanInvite())
                                <span class="inline-flex items-center gap-1">
                                    <x-ui.button
                                        variant="ghost"
                                        size="sm"
                                        icon="arrow-path"
                                        wire:click="resendInvitation({{ $invitation->id }})"
                                        loading="resendInvitation"
                                    >{{ __('Re-send') }}</x-ui.button>

                                    <x-ui.button
                                        variant="ghost"
                                        size="sm"
                                        icon="x-circle"
                                        wire:click="revokeInvitation({{ $invitation->id }})"
                                        loading="revokeInvitation"
                                    >{{ __('Revoke') }}</x-ui.button>
                                </span>
                            @endif
                        </x-ui.table.cell>
                    </x-ui.table.row>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    <x-ui.card class="mb-4" flush>
        <div class="p-4">
            <x-ui.form.group name="search" :label="__('Search')">
                <x-ui.form.input
                    name="search"
                    type="search"
                    icon="magnifying-glass"
                    :placeholder="__('Name or email address…')"
                    wire:model.live.debounce.300ms="search"
                />
            </x-ui.form.group>
        </div>
    </x-ui.card>

    <x-ui.card flush>
        <div wire:loading.delay.long.flex class="hidden p-4">
            <x-ui.skeleton variant="table" :rows="5" />
        </div>

        <div wire:loading.delay.long.remove wire:target="search,gotoPage,previousPage,nextPage">
            @if ($this->users->isEmpty())
                <x-ui.empty-state
                    variant="filtered"
                    icon="users"
                    :description="__('No accounts match this search.')"
                />
            @else
                <x-ui.table
                    :caption="__('Platform user directory')"
                    class="p-4 sm:p-0"
                    :headings="[__('User'), __('Roles'), __('Workspaces'), __('Status'), __('Last sign-in'), '']"
                >
                    @foreach ($this->users as $user)
                        <x-ui.table.row wire:key="user-{{ $user->id }}">
                            <x-ui.table.cell :label="__('User')" primary>
                                {{ $user->name }}
                                <span class="block text-xs font-normal text-ink-muted">{{ $user->email }}</span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Roles')">
                                <span class="flex max-w-xs flex-wrap gap-1">
                                    @forelse ($this->rolesFor($user) as $roleLabel)
                                        <span class="inline-flex items-center rounded-md bg-neutral-soft px-2 py-0.5 text-xs font-medium text-ink">
                                            {{ $roleLabel }}
                                        </span>
                                    @empty
                                        <span class="text-xs text-ink-muted">{{ __('No role') }}</span>
                                    @endforelse
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Workspaces')">
                                <span class="flex max-w-xs flex-wrap gap-1">
                                    @forelse ($this->workspacesFor($user) as $membership)
                                        <span class="inline-flex items-center gap-1 text-xs text-ink-muted">
                                            <x-ui.icon name="building-office" class="size-3.5" />
                                            {{ $membership->tenant?->name }}
                                        </span>
                                    @empty
                                        <span class="text-xs text-ink-muted">—</span>
                                    @endforelse
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Status')">
                                @if ($user->is_active)
                                    <x-ui.badge status="approved" :label="__('Active')" size="sm" />
                                @else
                                    <x-ui.badge status="rejected" :label="__('Deactivated')" size="sm" />
                                @endif
                            </x-ui.table.cell>

                            <x-ui.table.cell :label="__('Last sign-in')">
                                <span class="text-ink-muted">
                                    {{ $user->last_login_at ? \App\Support\InstanceTime::local($user->last_login_at)->diffForHumans() : __('Never') }}
                                </span>
                            </x-ui.table.cell>

                            <x-ui.table.cell align="right">
                                @if ($this->actorCanManage() && ! $user->is(auth()->user()))
                                    @if ($user->is_active)
                                        <x-ui.button
                                            variant="ghost"
                                            size="sm"
                                            icon="pause-circle"
                                            wire:click="confirmSetActive({{ $user->id }}, false)"
                                        >{{ __('Deactivate') }}</x-ui.button>
                                    @else
                                        <x-ui.button
                                            variant="ghost"
                                            size="sm"
                                            icon="check-circle"
                                            wire:click="confirmSetActive({{ $user->id }}, true)"
                                        >{{ __('Activate') }}</x-ui.button>
                                    @endif
                                @endif
                            </x-ui.table.cell>
                        </x-ui.table.row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>

        @if ($this->users->isNotEmpty())
            <x-slot:footer>
                <x-ui.pagination :paginator="$this->users" :label="__('Directory pages')" />
            </x-slot:footer>
        @endif
    </x-ui.card>

    {{-- Activate / deactivate confirmation --}}
    <x-ui.modal
        name="set-user-active"
        :title="$togglingTo ? __('Reactivate this account?') : __('Deactivate this account?')"
        :description="$this->togglingUser
            ? ($togglingTo
                ? __(':name will be able to sign in again on every surface; their workspaces and roles were never removed.', ['name' => $this->togglingUser->name])
                : __(':name is signed out of everything and cannot sign in anywhere until reactivated. Their workspaces and roles are kept.', ['name' => $this->togglingUser->name]))
            : null"
        max-width="md"
    >
        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'set-user-active')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button
                :variant="$togglingTo ? 'primary' : 'destructive'"
                :icon="$togglingTo ? 'check-circle' : 'pause-circle'"
                wire:click="applySetActive"
                loading="applySetActive"
            >{{ $togglingTo ? __('Reactivate account') : __('Deactivate account') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
