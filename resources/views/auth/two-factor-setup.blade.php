{{--
    Guided two-factor enrolment, served at /two-factor/setup on the tenant and
    oversight surfaces (Route::view, so this page receives no controller data).

    Layout is chosen from the resolved tenant: ResolveTenant shares $tenant on the
    MDA subdomain and nowhere else. A controller may override with $layout.

    Talks straight to Fortify's JSON endpoints, all of which sit behind
    password.confirm. A 423 is therefore an expected, routine response: it opens the
    in-page confirmation modal and replays the action that triggered it, so the user
    never loses their place in the wizard. /user/confirm-password stays as the no-JS
    fallback.
--}}
@php
    $title = __('Set up two-factor authentication');
    $layout = $layout ?? (isset($tenant) && $tenant ? 'layouts.tenant' : 'layouts.oversight');

    $account = auth()->user();
    $alreadyEnrolled = filled(data_get($account, 'two_factor_confirmed_at'));

    $prefix = trim((string) config('fortify.prefix'), '/');
    $path = fn (string $segment) => url(trim($prefix.'/'.$segment, '/'));

    $wizardSteps = [
        ['label' => __('Get ready'), 'description' => __('Install an authenticator app')],
        ['label' => __('Scan & confirm'), 'description' => __('Link the app to your account')],
        ['label' => __('Recovery codes'), 'description' => __('Save your backup codes')],
    ];

    $setupConfig = [
        'routes' => [
            'enable' => $path('user/two-factor-authentication'),
            'confirm' => $path('user/confirmed-two-factor-authentication'),
            'qrCode' => $path('user/two-factor-qr-code'),
            'secretKey' => $path('user/two-factor-secret-key'),
            'recoveryCodes' => $path('user/two-factor-recovery-codes'),
            'confirmPassword' => $path('user/confirm-password'),
            'dashboard' => url('/'),
        ],
        'download' => [
            'filename' => 'recovery-codes-'.now()->format('Y-m-d').'.txt',
            'heading' => __('Two-factor recovery codes').' — '.(config('platform.instance.name') ?? config('app.name')),
            'note' => __('Each code can be used once. Store them somewhere only you can reach.'),
        ],
        'messages' => [
            'generic' => __('Something went wrong. Please try again.'),
            'offline' => __('We could not reach the server. Check your connection and try again.'),
            'expired' => __('Your session expired. Reload the page and sign in again.'),
            'setupFailed' => __('Setup could not be started. Try again, or contact your administrator.'),
            'invalidCode' => __('That code is not valid. Check the app and enter the current code.'),
            'invalidPassword' => __('The provided password was incorrect.'),
        ],
    ];
@endphp

@extends($layout)

@section('content')
    <div class="mx-auto max-w-3xl">
        <x-ui.page-header
            :title="__('Two-factor authentication')"
            :description="__('An authenticator app generates a short code that changes every 30 seconds. With it, a stolen password alone is not enough to reach government project data.')"
        />

        {{-- $twoFactorMandatory is shared by RequireTwoFactor, which owns the rule. --}}
        @if (($twoFactorMandatory ?? false) && ! $alreadyEnrolled)
            <x-ui.alert variant="warning" class="mb-5" :title="__('Two-factor authentication required')">
                {{ session('warning') ?: __('Your role requires two-factor authentication. Finish enrolling to keep access.') }}
            </x-ui.alert>
        @elseif (session('warning'))
            <x-ui.alert variant="warning" class="mb-5" :title="__('Setup required to continue')">
                {{ session('warning') }}
            </x-ui.alert>
        @endif

        @if ($alreadyEnrolled)
            {{-- Voluntary revisit: nothing to enrol, so offer the useful actions instead. --}}
            <x-ui.card>
                <div class="flex flex-col items-start gap-4 sm:flex-row sm:items-center">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-positive-soft text-positive-ink">
                        <x-ui.icon name="shield-check" class="size-6" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-base font-semibold text-ink">{{ __('Two-factor authentication is active') }}</h2>
                        <p class="mt-1 text-sm text-ink-muted">
                            {{ __('Confirmed on :date. You will be asked for a code from your authenticator app each time you sign in.', [
                                'date' => \Illuminate\Support\Carbon::parse(data_get($account, 'two_factor_confirmed_at'))
                                    ->timezone(config('app.timezone'))
                                    ->translatedFormat('j F Y'),
                            ]) }}
                        </p>
                    </div>
                    <x-ui.badge status="approved" :label="__('Enabled')" />
                </div>

                <div class="mt-5 flex flex-wrap gap-2 border-t border-line pt-4">
                    <x-ui.button :href="url('/')" icon="arrow-left">{{ __('Back to dashboard') }}</x-ui.button>
                </div>

                <p class="mt-3 text-xs text-ink-muted">
                    {{ __('Changing or turning off two-factor authentication requires your password and is recorded in the audit log.') }}
                </p>
            </x-ui.card>
        @else
            <div x-data="twoFactorSetup(@js($setupConfig))">
                <x-ui.steps
                    :steps="$wizardSteps"
                    :current="1"
                    state="step"
                    :label="__('Two-factor setup progress')"
                    class="mb-6"
                />

                {{-- Page-level failures (network, session, unexpected status). --}}
                <template x-if="pageError">
                    <div class="mb-5">
                        <x-ui.alert variant="critical" :title="__('We could not complete that step')">
                            <span x-text="pageError"></span>
                        </x-ui.alert>
                    </div>
                </template>

                {{-- ---------------------------------------------------------- --}}
                {{-- Step 1 — get ready                                          --}}
                {{-- ---------------------------------------------------------- --}}
                <div x-show="step === 1">
                    <x-ui.card :title="__('Before you start')" :subtitle="__('You need an authenticator app on your phone')">
                        <ol class="space-y-4">
                            <li class="flex gap-3">
                                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand-ink">1</span>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">{{ __('Install an authenticator app') }}</p>
                                    <p class="mt-0.5 text-sm text-ink-muted">
                                        {{ __('Google Authenticator, Microsoft Authenticator and Authy all work. Any app that supports time-based codes (TOTP) is fine — it needs no internet connection to generate codes.') }}
                                    </p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand-ink">2</span>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">{{ __('Keep that device with you') }}</p>
                                    <p class="mt-0.5 text-sm text-ink-muted">
                                        {{ __('You will scan a code on the next screen, then confirm with the 6-digit code the app shows.') }}
                                    </p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-soft text-xs font-semibold text-brand-ink">3</span>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink">{{ __('Save your recovery codes') }}</p>
                                    <p class="mt-0.5 text-sm text-ink-muted">
                                        {{ __('At the end you receive one-time codes to use if you lose your phone. They are shown once.') }}
                                    </p>
                                </div>
                            </li>
                        </ol>

                        <div class="mt-6 border-t border-line pt-4">
                            <x-ui.button x-on:click="begin()" x-bind:disabled="busy" icon="shield-check">
                                <span x-show="! busy">{{ __('Begin setup') }}</span>
                                <span x-show="busy" x-cloak class="inline-flex items-center gap-2">
                                    <x-ui.icon name="arrow-path" class="size-4 animate-spin" />
                                    {{ __('Starting…') }}
                                </span>
                            </x-ui.button>
                            <p class="mt-2 text-xs text-ink-muted">
                                {{ __('You may be asked to re-enter your password first.') }}
                            </p>
                        </div>
                    </x-ui.card>
                </div>

                {{-- ---------------------------------------------------------- --}}
                {{-- Step 2 — scan and confirm                                   --}}
                {{-- ---------------------------------------------------------- --}}
                <div x-show="step === 2" x-cloak>
                    <x-ui.card :title="__('Link your authenticator app')" :subtitle="__('Scan the code, then enter the 6-digit code the app shows')">
                        <div class="grid gap-6 md:grid-cols-[auto_1fr]">
                            <div class="mx-auto md:mx-0">
                                {{-- The QR is a visual convenience; the setup key below is
                                     its accessible equivalent, so the image itself is
                                     hidden from assistive technology. --}}
                                <div
                                    x-show="! loadingKeys && qrSvg"
                                    x-cloak
                                    aria-hidden="true"
                                    class="inline-flex items-center justify-center rounded-xl border border-line bg-surface-raised p-3 [&_svg]:size-44 sm:[&_svg]:size-48"
                                >
                                    {{-- Server-generated SVG from our own Fortify endpoint (same
                                         origin, not user input): the one safe use of x-html. --}}
                                    <div x-html="qrSvg"></div>
                                </div>

                                <div x-show="loadingKeys" class="w-44 sm:w-48">
                                    <x-ui.skeleton variant="block" height="h-44 sm:h-48" />
                                </div>
                            </div>

                            <div class="min-w-0 space-y-4">
                                <div>
                                    <h3 class="text-sm font-semibold text-ink">{{ __('Cannot scan the code?') }}</h3>
                                    <p class="mt-1 text-sm text-ink-muted">
                                        {{ __('In your authenticator app choose “enter a setup key manually”, then type the key below. The account name is your work email address.') }}
                                    </p>

                                    <div class="mt-2 flex items-center gap-2">
                                        <code
                                            class="min-w-0 flex-1 rounded-lg border border-line bg-surface-sunken px-3 py-2 font-mono text-sm break-all text-ink select-all"
                                            x-text="secretKey || '…'"
                                        ></code>
                                        <x-ui.button
                                            variant="secondary"
                                            size="sm"
                                            icon="clipboard-check"
                                            icon-only
                                            x-on:click="copyText(secretKey, 'key')"
                                        >{{ __('Copy setup key') }}</x-ui.button>
                                    </div>

                                    <p class="mt-1 h-4 text-xs text-positive-ink" x-show="copied === 'key'" x-cloak>
                                        {{ __('Setup key copied.') }}
                                    </p>
                                </div>

                                <div class="border-t border-line pt-4">
                                    <form x-on:submit.prevent="confirmCode()" class="space-y-4">
                                        <x-ui.form.group
                                            name="code"
                                            :label="__('6-digit code from your app')"
                                            :hint="__('The code changes every 30 seconds — enter the one showing now.')"
                                            required
                                        >
                                            <x-ui.form.input
                                                name="code"
                                                type="text"
                                                inputmode="numeric"
                                                pattern="[0-9]*"
                                                maxlength="6"
                                                autocomplete="one-time-code"
                                                autocapitalize="none"
                                                spellcheck="false"
                                                has-hint
                                                x-model="code"
                                                x-bind:aria-invalid="codeError ? 'true' : null"
                                                x-bind:aria-describedby="codeError ? 'code-hint code-error' : 'code-hint'"
                                                class="text-center text-lg tracking-[0.4em] tabular-nums"
                                            />
                                            <x-ui.form.error name="code" state="codeError" />
                                        </x-ui.form.group>

                                        <div class="flex flex-wrap gap-2">
                                            <x-ui.button type="submit" x-bind:disabled="busy || code.length < 6" icon="check-circle">
                                                <span x-show="! busy">{{ __('Confirm and enable') }}</span>
                                                <span x-show="busy" x-cloak class="inline-flex items-center gap-2">
                                                    <x-ui.icon name="arrow-path" class="size-4 animate-spin" />
                                                    {{ __('Checking…') }}
                                                </span>
                                            </x-ui.button>

                                            <x-ui.button type="button" variant="ghost" x-on:click="step = 1" icon="arrow-left">
                                                {{ __('Back') }}
                                            </x-ui.button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </x-ui.card>
                </div>

                {{-- ---------------------------------------------------------- --}}
                {{-- Step 3 — recovery codes                                     --}}
                {{-- ---------------------------------------------------------- --}}
                <div x-show="step === 3" x-cloak>
                    <x-ui.card :title="__('Save your recovery codes')" :subtitle="__('Shown once — store them before you continue')">
                        <x-slot:actions>
                            <x-ui.badge status="approved" :label="__('Two-factor enabled')" />
                        </x-slot:actions>

                        <x-ui.alert variant="warning" class="mb-4" :title="__('These codes will not be shown again')">
                            {{ __('Each code works once, in place of your authenticator app. Keep them somewhere separate from your phone — a password manager or a locked drawer, not an unlocked device.') }}
                        </x-ui.alert>

                        <template x-if="recoveryCodes.length > 0">
                            <ul class="grid gap-2 rounded-xl border border-line bg-surface-sunken p-4 sm:grid-cols-2">
                                <template x-for="recoveryCode in recoveryCodes" :key="recoveryCode">
                                    <li class="font-mono text-sm tracking-wide text-ink select-all" x-text="recoveryCode"></li>
                                </template>
                            </ul>
                        </template>

                        <template x-if="recoveryCodes.length === 0">
                            <div>
                                <x-ui.empty-state
                                    compact
                                    icon="document-text"
                                    :title="__('No recovery codes yet')"
                                    :description="__('Generate a set now so you can still sign in if you lose your phone.')"
                                >
                                    <x-slot:actions>
                                        <x-ui.button size="sm" variant="secondary" icon="arrow-path" x-on:click="regenerate()">
                                            {{ __('Generate recovery codes') }}
                                        </x-ui.button>
                                    </x-slot:actions>
                                </x-ui.empty-state>
                            </div>
                        </template>

                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <x-ui.button variant="secondary" icon="clipboard-check" x-on:click="copyText(recoveryCodes.join('\n'), 'codes')">
                                {{ __('Copy all codes') }}
                            </x-ui.button>

                            <x-ui.button variant="secondary" icon="arrow-down-tray" x-on:click="downloadCodes()">
                                {{ __('Download as .txt') }}
                            </x-ui.button>

                            <span class="text-xs text-positive-ink" x-show="copied === 'codes'" x-cloak>{{ __('All codes copied.') }}</span>
                            <span class="text-xs text-positive-ink" x-show="downloaded" x-cloak>{{ __('File downloaded.') }}</span>
                        </div>

                        <div class="mt-5 border-t border-line pt-4">
                            <x-ui.form.checkbox
                                name="acknowledged"
                                x-model="acknowledged"
                                :label="__('I have saved my recovery codes somewhere safe')"
                                :description="__('You will not be able to see them again from this screen.')"
                            />

                            <div class="mt-4 flex flex-wrap items-center gap-2">
                                <x-ui.button
                                    x-bind:disabled="! acknowledged"
                                    x-on:click="if (acknowledged) window.location.href = config.routes.dashboard"
                                    trailing-icon="arrow-right"
                                >
                                    {{ __('Continue to dashboard') }}
                                </x-ui.button>

                                <x-ui.button
                                    variant="ghost"
                                    size="sm"
                                    x-show="! regenerateArmed && recoveryCodes.length > 0"
                                    x-on:click="regenerateArmed = true"
                                    icon="arrow-path"
                                >{{ __('Generate new codes') }}</x-ui.button>

                                <span x-show="regenerateArmed" x-cloak class="inline-flex flex-wrap items-center gap-2">
                                    <span class="text-sm text-ink-muted">{{ __('This replaces the codes above. Continue?') }}</span>
                                    <x-ui.button variant="destructive" size="sm" x-on:click="regenerate()" x-bind:disabled="busy">
                                        {{ __('Replace codes') }}
                                    </x-ui.button>
                                    <x-ui.button variant="ghost" size="sm" x-on:click="regenerateArmed = false">{{ __('Cancel') }}</x-ui.button>
                                </span>
                            </div>
                        </div>
                    </x-ui.card>
                </div>

                {{-- ---------------------------------------------------------- --}}
                {{-- Password confirmation (Fortify answers 423 until confirmed) --}}
                {{-- ---------------------------------------------------------- --}}
                <x-ui.modal
                    name="confirm-password"
                    :title="__('Confirm your password')"
                    :description="__('For your security, confirm your password before changing sign-in settings.')"
                    max-width="sm"
                >
                    <form x-on:submit.prevent="confirmPassword()" class="space-y-4" id="confirm-password-form">
                        <x-ui.form.group name="confirm_password" :label="__('Password')" required>
                            <x-ui.form.input
                                name="confirm_password"
                                type="password"
                                autocomplete="current-password"
                                x-model="password"
                                x-bind:aria-invalid="passwordError ? 'true' : null"
                            />
                            <x-ui.form.error name="confirm_password" state="passwordError" />
                        </x-ui.form.group>

                        <p class="text-xs text-ink-muted">
                            {{ __('Prefer a full page?') }}
                            <a href="{{ $path('user/confirm-password') }}" class="rounded font-medium text-brand-ink underline underline-offset-2 hover:no-underline">
                                {{ __('Confirm your password here') }}
                            </a>
                        </p>
                    </form>

                    <x-slot:footer>
                        <x-ui.button
                            variant="secondary"
                            x-on:click="$dispatch('close-modal', 'confirm-password'); pendingAction = null"
                        >{{ __('Cancel') }}</x-ui.button>

                        <x-ui.button type="submit" form="confirm-password-form" x-bind:disabled="passwordBusy" icon="shield-check">
                            <span x-show="! passwordBusy">{{ __('Confirm') }}</span>
                            <span x-show="passwordBusy" x-cloak class="inline-flex items-center gap-2">
                                <x-ui.icon name="arrow-path" class="size-4 animate-spin" />
                                {{ __('Checking…') }}
                            </span>
                        </x-ui.button>
                    </x-slot:footer>
                </x-ui.modal>
            </div>

            {{-- Registered before Livewire's bundle loads, so `alpine:init` is never
                 missed; the window.Alpine branch covers the reverse order too. --}}
            <script>
                (function () {
                    const factory = (config) => ({
                        config: config,
                        step: 1,
                        busy: false,
                        loadingKeys: false,
                        pageError: null,
                        codeError: null,
                        code: '',
                        qrSvg: '',
                        secretKey: '',
                        recoveryCodes: [],
                        acknowledged: false,
                        regenerateArmed: false,
                        copied: null,
                        downloaded: false,
                        password: '',
                        passwordError: null,
                        passwordBusy: false,
                        pendingAction: null,

                        async api(method, url, body) {
                            const token = document.querySelector('meta[name="csrf-token"]');

                            const response = await fetch(url, {
                                method: method,
                                credentials: 'same-origin',
                                headers: {
                                    Accept: 'application/json',
                                    'Content-Type': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'X-CSRF-TOKEN': token ? token.content : '',
                                },
                                body: body ? JSON.stringify(body) : undefined,
                            });

                            let data = null;
                            const text = await response.text();
                            if (text) {
                                try { data = JSON.parse(text); } catch (error) { data = null; }
                            }

                            return { ok: response.ok, status: response.status, data: data };
                        },

                        fail(status) {
                            this.pageError = status === 419
                                ? this.config.messages.expired
                                : this.config.messages.generic;
                        },

                        // 423 = "confirm your password first": park the action, ask, replay it.
                        requirePassword(action) {
                            this.pendingAction = action;
                            this.password = '';
                            this.passwordError = null;
                            this.$dispatch('open-modal', 'confirm-password');
                        },

                        async confirmPassword() {
                            if (this.passwordBusy) return;
                            this.passwordBusy = true;
                            this.passwordError = null;

                            try {
                                const result = await this.api('POST', this.config.routes.confirmPassword, { password: this.password });

                                if (result.ok) {
                                    this.password = '';
                                    this.$dispatch('close-modal', 'confirm-password');
                                    const action = this.pendingAction;
                                    this.pendingAction = null;
                                    if (action && typeof this[action] === 'function') {
                                        await this[action]();
                                    }
                                } else if (result.status === 422) {
                                    this.passwordError = (result.data && result.data.errors && result.data.errors.password)
                                        ? result.data.errors.password[0]
                                        : this.config.messages.invalidPassword;
                                } else {
                                    this.passwordError = this.config.messages.generic;
                                }
                            } catch (error) {
                                this.passwordError = this.config.messages.offline;
                            } finally {
                                this.passwordBusy = false;
                            }
                        },

                        async begin() {
                            if (this.busy) return;
                            this.busy = true;
                            this.pageError = null;

                            try {
                                const result = await this.api('POST', this.config.routes.enable);

                                if (result.status === 423) { this.requirePassword('begin'); return; }
                                if (! result.ok) { this.fail(result.status); return; }

                                await this.loadKeys();
                                if (! this.pageError) this.step = 2;
                            } catch (error) {
                                this.pageError = this.config.messages.offline;
                            } finally {
                                this.busy = false;
                            }
                        },

                        async loadKeys() {
                            this.loadingKeys = true;

                            try {
                                const results = await Promise.all([
                                    this.api('GET', this.config.routes.qrCode),
                                    this.api('GET', this.config.routes.secretKey),
                                ]);

                                const qr = results[0];
                                const secret = results[1];

                                if (qr.status === 423 || secret.status === 423) { this.requirePassword('loadKeys'); return; }

                                this.qrSvg = (qr.data && qr.data.svg) ? qr.data.svg : '';
                                this.secretKey = (secret.data && secret.data.secretKey) ? secret.data.secretKey : '';

                                if (! this.qrSvg && ! this.secretKey) {
                                    this.pageError = this.config.messages.setupFailed;
                                }
                            } catch (error) {
                                this.pageError = this.config.messages.offline;
                            } finally {
                                this.loadingKeys = false;
                            }
                        },

                        async confirmCode() {
                            if (this.busy || this.code.length < 6) return;
                            this.busy = true;
                            this.codeError = null;
                            this.pageError = null;

                            try {
                                const result = await this.api('POST', this.config.routes.confirm, { code: this.code });

                                if (result.status === 423) { this.requirePassword('confirmCode'); return; }
                                if (result.status === 422) {
                                    this.codeError = (result.data && result.data.errors && result.data.errors.code)
                                        ? result.data.errors.code[0]
                                        : this.config.messages.invalidCode;
                                    return;
                                }
                                if (! result.ok) { this.fail(result.status); return; }

                                await this.loadRecoveryCodes();
                                this.step = 3;
                            } catch (error) {
                                this.pageError = this.config.messages.offline;
                            } finally {
                                this.busy = false;
                            }
                        },

                        async loadRecoveryCodes() {
                            try {
                                const result = await this.api('GET', this.config.routes.recoveryCodes);
                                if (result.status === 423) { this.requirePassword('loadRecoveryCodes'); return; }
                                this.recoveryCodes = Array.isArray(result.data) ? result.data : [];
                            } catch (error) {
                                this.recoveryCodes = [];
                            }
                        },

                        async regenerate() {
                            if (this.busy) return;
                            this.busy = true;
                            this.pageError = null;

                            try {
                                const result = await this.api('POST', this.config.routes.recoveryCodes);

                                if (result.status === 423) { this.requirePassword('regenerate'); return; }
                                if (! result.ok) { this.fail(result.status); return; }

                                await this.loadRecoveryCodes();
                                this.regenerateArmed = false;
                                this.acknowledged = false;
                                this.copied = null;
                                this.downloaded = false;
                            } catch (error) {
                                this.pageError = this.config.messages.offline;
                            } finally {
                                this.busy = false;
                            }
                        },

                        async copyText(value, marker) {
                            if (! value) return;

                            try {
                                if (navigator.clipboard && window.isSecureContext) {
                                    await navigator.clipboard.writeText(value);
                                } else {
                                    // Plain http / older Android WebViews in the field.
                                    const area = document.createElement('textarea');
                                    area.value = value;
                                    area.setAttribute('readonly', '');
                                    area.style.position = 'fixed';
                                    area.style.opacity = '0';
                                    document.body.appendChild(area);
                                    area.select();
                                    document.execCommand('copy');
                                    document.body.removeChild(area);
                                }

                                this.copied = marker;
                                setTimeout(() => { if (this.copied === marker) this.copied = null; }, 4000);
                            } catch (error) {
                                this.pageError = this.config.messages.generic;
                            }
                        },

                        downloadCodes() {
                            if (this.recoveryCodes.length === 0) return;

                            const lines = [
                                this.config.download.heading,
                                this.config.download.note,
                                '',
                            ].concat(this.recoveryCodes);

                            const blob = new Blob([lines.join('\n') + '\n'], { type: 'text/plain;charset=utf-8' });
                            const url = URL.createObjectURL(blob);
                            const link = document.createElement('a');
                            link.href = url;
                            link.download = this.config.download.filename;
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                            setTimeout(() => URL.revokeObjectURL(url), 1000);

                            this.downloaded = true;
                        },
                    });

                    const register = (Alpine) => Alpine.data('twoFactorSetup', factory);

                    if (window.Alpine) {
                        register(window.Alpine);
                    } else {
                        document.addEventListener('alpine:init', () => register(window.Alpine));
                    }
                })();
            </script>
        @endif
    </div>
@endsection
