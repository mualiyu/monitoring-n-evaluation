<?php

/**
 * The configuration chain every domain number goes through
 * (projects-module.md §0.1): tenant override → instance value → config
 * default.
 *
 * It exists because a state may run a 12-month post-completion window where
 * another runs 6, and one MDA may need a different mid-term trigger from its
 * neighbour — so an Action that reads `config()` directly has hard-coded a
 * policy decision.
 */

use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;

beforeEach(function () {
    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->settings = new SettingsRepository;
});

it('falls back to the config default when nothing overrides it', function () {
    actingOnTenant($this->works);

    expect($this->settings->int('monitoring', 'post_completion_review_months', 6))->toBe(6);
});

it('prefers an instance setting over the config default', function () {
    Setting::create(['group' => 'monitoring', 'key' => 'post_completion_review_months', 'value' => 12]);

    actingOnTenant($this->works);

    expect($this->settings->int('monitoring', 'post_completion_review_months', 6))->toBe(12);
});

it('prefers this MDA’s override over the instance setting, and leaves the next MDA alone', function () {
    Setting::create(['group' => 'monitoring', 'key' => 'mid_term_trigger_percent', 'value' => 40]);

    app(CurrentTenant::class)->runAs($this->works, function () {
        TenantSetting::create(['group' => 'monitoring', 'key' => 'mid_term_trigger_percent', 'value' => 25]);
    });

    actingOnTenant($this->works);
    expect($this->settings->int('monitoring', 'mid_term_trigger_percent', 50))->toBe(25);

    actingOnTenant($this->health);
    expect($this->settings->int('monitoring', 'mid_term_trigger_percent', 50))->toBe(40);
});

it('reads instance and config values with no tenant bound at all', function () {
    actingWithoutTenant();

    expect($this->settings->bool('monitoring', 'require_final_inspection_for_certification', false))->toBeFalse();

    Setting::create([
        'group' => 'monitoring',
        'key' => 'require_final_inspection_for_certification',
        'value' => true,
    ]);

    expect($this->settings->bool('monitoring', 'require_final_inspection_for_certification', false))->toBeTrue();
});

it('falls through a null-valued row rather than treating null as an answer', function () {
    Setting::create(['group' => 'monitoring', 'key' => 'post_completion_review_months', 'value' => null]);

    actingOnTenant($this->works);

    expect($this->settings->int('monitoring', 'post_completion_review_months', 6))->toBe(6);
});
