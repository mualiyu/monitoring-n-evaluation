{{--
    Workspace switcher — docs/design/auth-surfaces.md §1.5, mitigation 3.

    Sessions are host-only by design, so this page cannot move a user between
    workspaces; it can only send them to the right door with their email already
    typed in. The hint line says so plainly rather than letting people discover it
    at a surprise login screen.

    $workspaces: Collection<TenantMembership> with ->tenant eager-loaded
    (App\Actions\Iam\ListUserWorkspaces). $tenant is the current workspace.
--}}
@php
    $title = __('Workspaces');

    $memberships = collect($workspaces ?? []);
    $currentTenantId = data_get($tenant, 'id');

    $currentMembership = $memberships->first(fn ($membership) => data_get($membership, 'tenant.id') === $currentTenantId);

    $otherMemberships = $memberships
        ->reject(fn ($membership) => data_get($membership, 'tenant.id') === $currentTenantId)
        ->sortBy(fn ($membership) => (string) data_get($membership, 'tenant.name'))
        ->values();

    $signedInEmail = auth()->user()?->email;

    // The email comes from the authenticated session, so pre-filling the target
    // host's login form leaks nothing the user does not already know.
    $deepLink = function ($workspaceTenant) use ($signedInEmail) {
        $path = '/login';

        if (filled($signedInEmail)) {
            $path .= '?email='.urlencode($signedInEmail);
        }

        return method_exists($workspaceTenant, 'url') ? $workspaceTenant->url($path) : '#';
    };

    $typeLabel = function ($workspaceTenant) {
        $type = data_get($workspaceTenant, 'type');

        return is_object($type) && method_exists($type, 'label') ? $type->label() : null;
    };
@endphp

@extends('layouts.tenant')

@section('content')
    <x-ui.page-header
        :title="__('Your workspaces')"
        :description="__('Every entity you have been granted access to. Each workspace keeps its own projects, reports and permissions.')"
    />

    @if ($otherMemberships->isNotEmpty())
        <x-ui.alert variant="info" class="mb-5" :title="__('Each workspace has its own sign-in')">
            {{ __('For security, a session on one workspace address is never valid on another. Opening a workspace below takes you to its sign-in page — your email address will already be filled in.') }}
        </x-ui.alert>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {{-- Current workspace first, always --}}
        @if ($currentMembership || $tenant)
            @php $currentTenant = data_get($currentMembership, 'tenant') ?? $tenant; @endphp

            <x-ui.card class="ring-2 ring-brand/40">
                <div class="flex items-start justify-between gap-3">
                    <x-ui.brand :tenant="$currentTenant" :subtitle="$typeLabel($currentTenant)" />
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-ui.badge status="approved" :label="__('Current workspace')" icon="check-circle" />
                </div>

                <p class="mt-3 font-mono text-xs break-all text-ink-muted">{{ request()->getHost() }}</p>

                <div class="mt-4 border-t border-line pt-4">
                    <x-ui.button variant="secondary" size="sm" :href="route('tenant.dashboard')" icon="squares" class="w-full sm:w-auto">
                        {{ __('Go to dashboard') }}
                    </x-ui.button>
                </div>
            </x-ui.card>
        @endif

        {{-- Other workspaces --}}
        @foreach ($otherMemberships as $membership)
            @php $workspaceTenant = $membership->tenant; @endphp

            <x-ui.card wire:key="workspace-{{ data_get($workspaceTenant, 'id') }}">
                <x-ui.brand :tenant="$workspaceTenant" :subtitle="$typeLabel($workspaceTenant)" />

                @if (data_get($membership, 'joined_at'))
                    <p class="mt-3 flex items-center gap-1.5 text-xs text-ink-muted">
                        <x-ui.icon name="calendar-days" class="size-3.5 shrink-0" />
                        {{ __('Member since :date', [
                            'date' => \Illuminate\Support\Carbon::parse($membership->joined_at)
                                ->timezone(config('app.timezone'))
                                ->translatedFormat('F Y'),
                        ]) }}
                    </p>
                @endif

                <p class="mt-1 font-mono text-xs break-all text-ink-muted">
                    {{ parse_url((string) $deepLink($workspaceTenant), PHP_URL_HOST) }}
                </p>

                <div class="mt-4 border-t border-line pt-4">
                    <x-ui.button
                        size="sm"
                        :href="$deepLink($workspaceTenant)"
                        trailing-icon="arrow-right"
                        class="w-full sm:w-auto"
                    >
                        {{ __('Open :workspace', ['workspace' => data_get($workspaceTenant, 'name')]) }}
                    </x-ui.button>

                    <p class="mt-2 text-xs text-ink-muted">{{ __('Opens that workspace’s sign-in page.') }}</p>
                </div>
            </x-ui.card>
        @endforeach
    </div>

    {{-- Single-membership state: nothing to switch to, so explain how access is granted. --}}
    @if ($otherMemberships->isEmpty())
        <x-ui.card class="mt-4" flush>
            <x-ui.empty-state
                icon="building-office"
                :title="__('This is your only workspace')"
                :description="__('You have access to one entity. Access to another workspace is granted by that entity’s administrator, who will send you an invitation by email.')"
            >
                <x-slot:actions>
                    <x-ui.button variant="secondary" :href="route('tenant.dashboard')" icon="arrow-left">
                        {{ __('Back to dashboard') }}
                    </x-ui.button>
                </x-slot:actions>
            </x-ui.empty-state>
        </x-ui.card>
    @endif
@endsection
