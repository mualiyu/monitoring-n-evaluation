<?php

/**
 * The demo dataset, exercised as a citizen would meet it.
 *
 * DemoFeedbackSeeder does two jobs — it publishes a handful of each MDA's
 * projects and then seeds a feedback register across every moderation state —
 * and both are load-bearing for a client demo: without published projects the
 * whole portal is an empty state, and without a spread of moderation states the
 * queue demonstrates nothing.
 *
 * This is a smoke test with teeth: it renders the real screens off the real
 * seeded data, and asserts that the internal note the seeder plants stays
 * internal. A demo dataset that leaks is worse than one that is thin.
 */

use App\Actions\Portal\BuildPortalSummary;
use App\Enums\FeedbackStatus;
use App\Models\Feedback;
use App\Models\FeedbackResponse;
use App\Models\Project;
use App\Tenancy\CurrentTenant;
use Database\Seeders\ContractorSeeder;
use Database\Seeders\DemoFeedbackSeeder;
use Database\Seeders\DemoProjectSeeder;
use Database\Seeders\DemoTenantSeeder;
use Database\Seeders\FundingSourceSeeder;
use Database\Seeders\LgaWardSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SectorSeeder;

beforeEach(function () {
    $this->seed([
        RoleSeeder::class,
        PermissionSeeder::class,
        SectorSeeder::class,
        FundingSourceSeeder::class,
        LgaWardSeeder::class,
        DemoTenantSeeder::class,
        ContractorSeeder::class,
        DemoProjectSeeder::class,
        DemoFeedbackSeeder::class,
    ]);

    actingWithoutTenant();
});

it('leaves the portal with something on it after seeding', function () {
    $summary = (new BuildPortalSummary)();

    expect($summary['projects'])->toBeGreaterThan(0)
        ->and($summary['contract_value']->minor())->toBeGreaterThan(0);

    $this->get(portalUrl('/'))->assertOk()->assertDontSee('Nothing has been published yet');
    $this->get(portalUrl('/projects'))->assertOk()->assertDontSee('Nothing has been published yet');
    $this->get(portalUrl('/map'))->assertOk()->assertDontSee('No project sites have been published yet');
});

it('seeds a feedback register that spans every moderation state', function () {
    foreach (FeedbackStatus::cases() as $case) {
        expect(Feedback::query()->where('status', $case)->count())
            ->toBeGreaterThan(0, "no demo feedback in state [{$case->value}]");
    }

    // The pile that appears on the state queue and nowhere else.
    expect(Feedback::query()->whereNull('project_id')->count())->toBeGreaterThan(0);
});

it('publishes the seeded reply and keeps the seeded internal note internal', function () {
    $internal = FeedbackResponse::query()->where('is_public', false)->firstOrFail();
    $public = FeedbackResponse::query()->where('is_public', true)->firstOrFail();

    $projectId = Feedback::query()->whereKey($public->feedback_id)->firstOrFail()->project_id;

    // No tenant is bound on the portal surface, so reaching a tenant-owned
    // model here is the same explicit privilege the portal's own reads take.
    $project = app(CurrentTenant::class)->bypass(
        fn (): Project => Project::query()->whereKey($projectId)->firstOrFail(),
    );

    $this->get(portalUrl('/projects/'.$project->ulid))
        ->assertOk()
        ->assertSee(e($public->body), escape: false)
        ->assertDontSee(e($internal->body), escape: false);
});
