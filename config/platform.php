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

    /*
    |--------------------------------------------------------------------------
    | Reporting calendar + deadline engine (progress-reporting.md §0.1)
    |--------------------------------------------------------------------------
    | The statutory reporting calendar is a POLICY decision, not a constant: a
    | state may give MDAs 14 days after month end where its neighbour gives 7,
    | and one MDA may need a different reminder ladder. Every number here is
    | read through App\Support\SettingsRepository (tenant override → instance
    | setting → this default), so a secretariat can retune the deadline engine
    | from a settings screen without a release.
    |
    | Due-date rules come from the manual digest §4: monthly and quarterly
    | returns are due N days after the period ends; the six-monthly return is
    | due at the END OF THE MONTH FOLLOWING the half (H1 → 31 Jul, H2 → 31 Jan);
    | the annual return is due within the first quarter of the following year.
    */

    'reporting' => [
        // Cadence a project reports on when it names none of its own.
        'default_frequency' => 'monthly',
        // Days after period_end that a monthly / quarterly return is due.
        'monthly_due_days' => 7,
        'quarterly_due_days' => 14,
        // Statutory rules — named, not numeric, because they are calendar
        // rules rather than day offsets. `end_of_period` (due on the last day
        // of the window itself) is the only alternative implemented; anything
        // unrecognised falls back to the statutory rule.
        'biannual_due_rule' => 'end_of_following_month',
        'annual_due_rule' => 'end_of_q1',
        // Reminder ladder, in days before due. Each rung fires exactly once
        // per obligation (the monotonic reminder_stage counter, §3).
        'reminder_days_before' => [7, 3, 1],
        // Days past due at which an unmet obligation escalates: first to the
        // MDA admin, then to state oversight.
        'overdue_escalation_days' => [1, 7],
        // Accept a late return with a flag rather than hard-closing the
        // window — a blocked MDA simply never reports, which is worse data.
        // When false, periods are generated with closes_at = due_at and
        // submission after it is refused.
        'allow_late_submission' => true,
        // Whether the approver must be someone other than the reviewer. Off
        // only for a single-officer MDA where nobody else can sign.
        'require_separate_approver' => true,
        // Which project statuses owe progress reports. `completed` is included
        // until certification: retention-period work still reports.
        'obligation_statuses' => ['mobilized', 'in_progress', 'completed'],
    ],

];
