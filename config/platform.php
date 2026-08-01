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

];
