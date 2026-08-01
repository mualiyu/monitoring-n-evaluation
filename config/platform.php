<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform base domain
    |--------------------------------------------------------------------------
    | The apex domain this deployment runs on. Tenant (MDA) workspaces live on
    | {slug}.<domain>, the state oversight app on oversight.<domain>, and the
    | public portal on the apex itself. Per-client deployments override this
    | via PLATFORM_DOMAIN (e.g. mne.ondostate.gov.ng).
    */

    'domain' => env('PLATFORM_DOMAIN', 'mne.test'),

    /*
    | Anchored single DNS label (max 63 chars, no dots, no edge hyphens).
    | Used everywhere a {tenant} route parameter is constrained — Laravel's
    | default domain-param regex matches dots, which would let multi-label
    | hosts like evil.works.<domain> reach tenant routes.
    */

    'tenant_slug_pattern' => '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?',

    /*
    |--------------------------------------------------------------------------
    | Reserved subdomains
    |--------------------------------------------------------------------------
    | Subdomains that can never be claimed as tenant slugs.
    */

    'reserved_subdomains' => [
        'www', 'oversight', 'api', 'mail', 'admin', 'horizon', 'portal', 'app',
        'smtp', 'ftp', 'assets', 'cdn', 'static', 'status', 'docs', 'help',
        'ns1', 'ns2', 'autodiscover',
    ],

    /*
    |--------------------------------------------------------------------------
    | Instance identity (white-label)
    |--------------------------------------------------------------------------
    | Display branding for this deployment. Never hard-code a state's name in
    | code or views — read it from here (seeded/overridden per client).
    */

    'instance' => [
        'name' => env('PLATFORM_INSTANCE_NAME', 'M&E Platform'),
        'short_name' => env('PLATFORM_INSTANCE_SHORT_NAME', 'M&E'),
        'currency' => env('PLATFORM_CURRENCY', 'NGN'),
        'timezone' => env('PLATFORM_TIMEZONE', 'Africa/Lagos'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication policy
    |--------------------------------------------------------------------------
    | check_compromised_passwords calls the HIBP range API; it must be
    | switchable off for deployments behind restrictive government egress
    | (and stays off in tests).
    */

    'auth' => [
        'invitation_ttl_days' => env('PLATFORM_INVITATION_TTL_DAYS', 7),
        'two_factor_grace_days' => env('PLATFORM_2FA_GRACE_DAYS', 7),
        'password_min_length' => 12,
        'check_compromised_passwords' => env('PLATFORM_CHECK_COMPROMISED_PASSWORDS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring domain numbers (projects-module.md §0.1)
    |--------------------------------------------------------------------------
    | The last fallback in the settings chain: a tenant override
    | (tenant_settings) wins over an instance value (settings), which wins over
    | these defaults. Read them through App\Support\SettingsRepository — never
    | as literals in an Action, because every one of them is a policy decision
    | a state can take differently.
    */

    'monitoring' => [
        // Days a contractor has to receive the commencement notice (Phase 2).
        'commencement_notice_days' => 3,
        // Days from commencement to the first site inspection (Phase 2).
        'initial_inspection_days' => 10,
        // Physical progress that raises the mid-term evaluation flag — an
        // event, never a lifecycle status.
        'mid_term_trigger_percent' => 50,
        // Post-completion monitoring window: actual_end_date + this many
        // months is when a certified project may be closed.
        'post_completion_review_months' => 6,
        // Phase 2 guard point: flip to true once site inspections exist and
        // certification will require a final inspection record.
        'require_final_inspection_for_certification' => false,
    ],

];
