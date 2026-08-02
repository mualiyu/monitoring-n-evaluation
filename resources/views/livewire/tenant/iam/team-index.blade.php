{{--
    MDA team management, workspace surface (App\Livewire\Tenant\Iam\TeamIndex).
--}}
<div>
    <x-ui.page-header
        :title="__('Users & roles')"
        :description="__('Who can enter this workspace, what they may do here, and the invitations still waiting on an answer.')"
    />

    @if (session('status'))
        <x-ui.alert variant="positive" class="mb-5" dismissible>{{ session('status') }}</x-ui.alert>
    @endif

    @if ($failure)
        <x-ui.alert variant="critical" class="mb-5" :title="__('That action could not be completed')">{{ $failure }}</x-ui.alert>
    @endif

    <div class="mb-4 grid gap-3 sm:grid-cols-3">
        <x-ui.stat :label="__('Active members')" :value="number_format($this->activeMembers->count())" icon="users" />
        <x-ui.stat
            :label="__('Pending invitations')"
            :value="number_format($this->invitations->count())"
            icon="paper-airplane"
            :hint="__('links not yet accepted')"
        />
        <x-ui.stat
            :label="__('Access revoked')"
            :value="number_format($this->suspendedMembers->count())"
            icon="pause-circle"
            :hint="__('kept as the audit record')"
        />
    </div>

    @can('users.invite')
        <x-ui.card
            class="mb-4"
            :title="__('Invite someone into this workspace')"
            :subtitle="__('They get an emailed link; their account and access are created when they accept.')"
        >
            <form wire:submit="invite" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.form.group name="inviteEmail" :label="__('Email address')" required>
                    <x-ui.form.input
                        name="inviteEmail"
                        type="email"
                        :placeholder="__('name@example.gov.ng')"
                        wire:model="inviteEmail"
                    />
                </x-ui.form.group>

                <x-ui.form.group
                    name="inviteRole"
                    :label="__('Role in this workspace')"
                    :hint="__('You can only offer roles below your own.')"
                    required
                >
                    <x-ui.form.select
                        name="inviteRole"
                        :placeholder="__('Choose a role…')"
                        :options="$this->invitableRoles"
                        has-hint
                        wire:model="inviteRole"
                    />
                </x-ui.form.group>

                <div class="flex items-start pt-6">
                    <x-ui.button type="submit" icon="paper-airplane" loading="invite">{{ __('Send invitation') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endcan

    @if ($this->invitations->isNotEmpty())
        <x-ui.card class="mb-4" flush :title="__('Pending invitations')">
            <x-ui.table
                :caption="__('Invitations awaiting acceptance')"
                class="p-4 sm:p-0"
                :headings="[__('Invited'), __('Role offered'), __('Invited by'), __('Expires'), '']"
            >
                @foreach ($this->invitations as $invitation)
                    <x-ui.table.row wire:key="invitation-{{ $invitation->id }}">
                        <x-ui.table.cell :label="__('Invited')" primary>{{ $invitation->email }}</x-ui.table.cell>

                        <x-ui.table.cell :label="__('Role offered')">
                            <span class="inline-flex items-center rounded-md bg-neutral-soft px-2 py-0.5 text-xs font-medium text-ink">
                                {{ $invitation->role->label() }}
                            </span>
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Invited by')">
                            <span class="text-ink-muted">{{ $invitation->invitedBy?->name ?? '—' }}</span>
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Expires')">
                            <span class="text-ink-muted">{{ $invitation->expires_at->diffForHumans() }}</span>
                        </x-ui.table.cell>

                        <x-ui.table.cell align="right">
                            @can('users.invite')
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
                            @endcan
                        </x-ui.table.cell>
                    </x-ui.table.row>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    <x-ui.card flush class="mb-4" :title="__('Active members')">
        @if ($this->activeMembers->isEmpty())
            <x-ui.empty-state
                icon="users"
                :title="__('No active members')"
                :description="__('Invite colleagues, consultants and field monitors — they appear here once they accept.')"
            />
        @else
            <x-ui.table
                :caption="__('Active workspace members')"
                class="p-4 sm:p-0"
                :headings="[__('Member'), __('Roles in this workspace'), __('Joined'), __('Last sign-in'), '']"
            >
                @foreach ($this->activeMembers as $membership)
                    <x-ui.table.row wire:key="member-{{ $membership->id }}">
                        <x-ui.table.cell :label="__('Member')" primary>
                            {{ $membership->user->name }}
                            <span class="block text-xs font-normal text-ink-muted">{{ $membership->user->email }}</span>
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Roles in this workspace')">
                            <span class="flex flex-wrap gap-1">
                                @forelse ($membership->user->roles as $role)
                                    <span class="inline-flex items-center rounded-md bg-neutral-soft px-2 py-0.5 text-xs font-medium text-ink">
                                        {{ \App\Enums\Role::tryFrom($role->name)?->label() ?? $role->name }}
                                    </span>
                                @empty
                                    <span class="text-xs text-ink-muted">{{ __('No role assigned') }}</span>
                                @endforelse
                            </span>
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Joined')">
                            <span class="text-ink-muted">
                                {{ $membership->joined_at ? \App\Support\InstanceTime::local($membership->joined_at)->format('j M Y') : '—' }}
                            </span>
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Last sign-in')">
                            <span class="text-ink-muted">
                                {{ $membership->user->last_login_at ? \App\Support\InstanceTime::local($membership->user->last_login_at)->diffForHumans() : __('Never') }}
                            </span>
                        </x-ui.table.cell>

                        <x-ui.table.cell align="right">
                            @can('users.manage')
                                @unless ($membership->user->is(auth()->user()))
                                    <x-ui.button
                                        variant="ghost"
                                        size="sm"
                                        icon="x-circle"
                                        wire:click="confirmRevoke({{ $membership->user_id }})"
                                    >{{ __('Revoke access') }}</x-ui.button>
                                @endunless
                            @endcan
                        </x-ui.table.cell>
                    </x-ui.table.row>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    @if ($this->suspendedMembers->isNotEmpty())
        <x-ui.card
            flush
            :title="__('Suspended members')"
            :subtitle="__('Access revoked — the record stays, because “this person once had access” is the audit trail.')"
        >
            <x-ui.table
                :caption="__('Members whose access was revoked')"
                class="p-4 sm:p-0"
                :headings="[__('Member'), __('Status'), __('Suspended'), '']"
            >
                @foreach ($this->suspendedMembers as $membership)
                    <x-ui.table.row wire:key="suspended-{{ $membership->id }}">
                        <x-ui.table.cell :label="__('Member')" primary>
                            {{ $membership->user->name }}
                            <span class="block text-xs font-normal text-ink-muted">{{ $membership->user->email }}</span>
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Status')">
                            <x-ui.badge status="on_hold" :label="__('Suspended')" size="sm" />
                        </x-ui.table.cell>

                        <x-ui.table.cell :label="__('Suspended')">
                            <span class="text-ink-muted">
                                {{ $membership->suspended_at ? \App\Support\InstanceTime::local($membership->suspended_at)->format('j M Y') : '—' }}
                            </span>
                        </x-ui.table.cell>

                        <x-ui.table.cell align="right">
                            <span class="text-xs text-ink-muted">{{ __('Re-invite to restore access') }}</span>
                        </x-ui.table.cell>
                    </x-ui.table.row>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endif

    {{-- Revoke access — always confirmed, always with a reason --}}
    <x-ui.modal
        name="revoke-access"
        :title="__('Revoke workspace access?')"
        :description="$this->revokingUser
            ? __(':name loses entry to this workspace immediately and their roles here are removed. Their account and any other workspaces are untouched.', ['name' => $this->revokingUser->name])
            : __('The member loses entry to this workspace immediately and their roles here are removed.')"
        max-width="md"
    >
        <x-ui.form.group
            name="revokeReason"
            :label="__('Reason')"
            :hint="__('Required and kept permanently in the audit log.')"
            required
        >
            <x-ui.form.textarea
                name="revokeReason"
                rows="3"
                maxlength="1000"
                has-hint
                :placeholder="__('e.g. Engagement ended 30 June; final report approved and contract closed out.')"
                wire:model="revokeReason"
            />
        </x-ui.form.group>

        <x-slot:footer>
            <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', 'revoke-access')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button
                variant="destructive"
                icon="x-circle"
                wire:click="revokeAccess"
                loading="revokeAccess"
            >{{ __('Revoke access') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
