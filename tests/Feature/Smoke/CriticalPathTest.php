<?php

/**
 * One project, start to published — through the REAL Actions, in order.
 *
 * Every module has its own suite proving it works alone. This proves they work
 * TOGETHER: that approving a return actually moves the project's attested
 * figures, that certification actually depends on the lifecycle records before
 * it, and that publishing actually puts the thing on the portal. Those seams
 * are where a modular build breaks, and no single-module test looks at them.
 *
 * It is deliberately a narrative rather than a matrix: if this test fails, the
 * platform does not do its job, whatever the other 1,700 say.
 */

use App\Actions\Documents\AttachDocument;
use App\Actions\Lifecycle\IssueCommencementNotice;
use App\Actions\Lifecycle\IssueCompletionCertificate;
use App\Actions\Projects\AwardContract;
use App\Actions\Projects\TransitionProjectStatus;
use App\Actions\Publishing\PublishProjectToPortal;
use App\Actions\Reporting\ApproveProgressReport;
use App\Actions\Reporting\ReviewProgressReport;
use App\Actions\Reporting\SaveProgressReportDraft;
use App\Actions\Reporting\StartProgressReport;
use App\Actions\Reporting\SubmitProgressReport;
use App\Enums\CertificateType;
use App\Enums\ProgressReportStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\CommencementNotice;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    Notification::fake();
    seedPermissions();

    $this->tenant = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->tenant);

    // Three people in the chain, because the chain REQUIRES three: the officer
    // who files, an officer who reviews, and the director who signs. Collapse
    // any two and the chokepoint refuses — `reporting.require_separate_approver`
    // means approval is a second pair of eyes or it is nothing.
    $this->admin = memberOf(User::factory()->create(['name' => 'Director of M&E']), $this->tenant, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(['name' => 'Focal Officer']), $this->tenant, Role::MeOfficer);
    $this->reviewer = memberOf(User::factory()->create(['name' => 'Reviewing Officer']), $this->tenant, Role::MeOfficer);
    $this->contractor = Contractor::factory()->create(['name' => 'Kalu & Sons Ltd']);
});

it('carries one project from award to published, through the real Actions', function () {
    /* ---------------------------------------------------------------- */
    /* 1. A project is registered and a contract awarded */
    /* ---------------------------------------------------------------- */
    $project = Project::factory()->create([
        'title' => 'Township Road Rehabilitation',
        'status' => ProjectStatus::Draft,
        'physical_progress' => '0.00',
    ]);

    $contract = app(AwardContract::class)($project, $this->contractor, $this->admin, [
        'contract_number' => 'WKS/CON/2026/014',
        'sum' => '250000000.00',
        'award_date' => now()->subMonths(2)->toDateString(),
        'duration_days' => 300,
        'scope_of_works' => '12km of township roads, drainage and signage.',
    ]);

    // The award is what moves the project out of draft, and the contract sum
    // is denormalised onto it — two modules, one transaction.
    $project = Project::query()->whereKey($project->getKey())->firstOrFail();

    expect($project->status)->toBe(ProjectStatus::Awarded)
        ->and($project->contract_value_total?->toDecimalString())->toBe('250000000.00');

    /* ---------------------------------------------------------------- */
    /* 2. The commencement notice is served, with its generated artifact */
    /* ---------------------------------------------------------------- */
    $notice = app(IssueCommencementNotice::class)($contract, $this->officer, 'Mobilise to site within 7 days.');

    expect($notice)->toBeInstanceOf(CommencementNotice::class)
        ->and($notice->tenant_id)->toBe($this->tenant->id)
        // The PDF is a real stored artifact on the private disk, not a promise.
        ->and($notice->getMedia('commencement_notice'))->toHaveCount(1)
        ->and($notice->getFirstMedia('commencement_notice')?->disk)->toBe('documents');

    app(TransitionProjectStatus::class)($project, ProjectStatus::Mobilized, $this->admin);
    app(TransitionProjectStatus::class)(
        Project::query()->whereKey($project->getKey())->firstOrFail(),
        ProjectStatus::InProgress,
        $this->admin,
    );

    /* ---------------------------------------------------------------- */
    /* 3. A progress return runs the whole approval chain */
    /* ---------------------------------------------------------------- */
    $project = Project::query()->whereKey($project->getKey())->firstOrFail();
    // Last month's window and this month's, both open: a window that has not
    // started yet refuses submissions, which is the calendar doing its job.
    $period = ReportingPeriod::factory()->monthly(
        year: (int) now()->subMonth()->year,
        month: (int) now()->subMonth()->month,
    )->create();

    $report = app(StartProgressReport::class)($project, $period, $this->officer);

    app(SaveProgressReportDraft::class)($report, $this->officer, [
        'narrative_work_done' => 'Sub-base laid on 7.4km; drainage 60% complete.',
        'physical_progress_claimed' => '62.00',
        'period_expenditure' => '90000000.00',
    ]);

    // Evidence rides with the return, through the same vault every other
    // module uses.
    app(AttachDocument::class)(
        $report,
        'report_evidence',
        UploadedFile::fake()->image('chainage-4km.jpg'),
        $this->officer,
    );

    $report = app(SubmitProgressReport::class)($report, $this->officer);
    expect($report->status)->toBe(ProgressReportStatus::Submitted);

    // A different officer reviews, and the director approves. Handing both
    // steps to one person is refused by the chokepoint, not by a screen.
    $report = app(ReviewProgressReport::class)($report, $this->reviewer);
    expect($report->status)->toBe(ProgressReportStatus::Reviewed);

    $report = app(ApproveProgressReport::class)($report, $this->admin);

    /* ---------------------------------------------------------------- */
    /* 4. Approval is what moves the project's attested figures */
    /* ---------------------------------------------------------------- */
    $project = Project::query()->whereKey($project->getKey())->firstOrFail();

    // THE SEAM. A reported figure is a claim until somebody with authority
    // approves it; only then does it become the project's own number. If this
    // assertion ever fails, the platform is a form, not a monitoring system.
    expect($report->status)->toBe(ProgressReportStatus::Approved)
        ->and($project->physical_progress)->toBe('62.00')
        ->and($project->expenditure_to_date->toDecimalString())->toBe('90000000.00');

    /* ---------------------------------------------------------------- */
    /* 5. A project cannot be "completed" on a claim of 62% */
    /* ---------------------------------------------------------------- */
    // The lifecycle reads the ATTESTED figure, not an intention. This is the
    // second seam: reporting is what makes completion possible, so the final
    // return has to run the whole chain again before the status can move.
    expect(fn () => app(TransitionProjectStatus::class)($project, ProjectStatus::Completed, $this->admin))
        ->toThrow(ProjectRuleViolation::class, 'completion means 100%');

    $finalPeriod = ReportingPeriod::factory()->monthly()->create();
    $final = app(StartProgressReport::class)($project, $finalPeriod, $this->officer);

    app(SaveProgressReportDraft::class)($final, $this->officer, [
        'narrative_work_done' => 'Wearing course complete; signage and line marking done.',
        'physical_progress_claimed' => '100.00',
        'period_expenditure' => '160000000.00',
    ]);

    $final = app(SubmitProgressReport::class)($final, $this->officer);
    $final = app(ReviewProgressReport::class)($final, $this->reviewer);
    app(ApproveProgressReport::class)($final, $this->admin);

    $project = Project::query()->whereKey($project->getKey())->firstOrFail();
    expect($project->physical_progress)->toBe('100.00');

    /* ---------------------------------------------------------------- */
    /* 6. Completion, certification, publication */
    /* ---------------------------------------------------------------- */
    app(TransitionProjectStatus::class)($project, ProjectStatus::Completed, $this->admin, actualEndDate: now());

    $project = Project::query()->whereKey($project->getKey())->firstOrFail();

    $certificate = app(IssueCompletionCertificate::class)(
        $project,
        CertificateType::PracticalCompletion,
        $this->admin,
        'Works inspected and accepted; defects liability runs for 12 months.',
    );

    $project = Project::query()->whereKey($project->getKey())->firstOrFail();

    // Certification goes through the project's own chokepoint — the
    // certificate does not write `status` itself.
    expect($certificate->tenant_id)->toBe($this->tenant->id)
        ->and($project->status)->toBe(ProjectStatus::Certified);

    app(PublishProjectToPortal::class)($project, $this->admin);

    $project = Project::query()->whereKey($project->getKey())->firstOrFail();
    expect($project->published_at)->not->toBeNull();

    /* ---------------------------------------------------------------- */
    /* 7. And the public can now see it — and only what was published */
    /* ---------------------------------------------------------------- */
    actingWithoutTenant();

    $this->get(portalUrl('/projects'))
        ->assertOk()
        ->assertSee('Township Road Rehabilitation');

    $this->get(portalUrl('/projects/'.$project->ulid))
        ->assertOk()
        ->assertSee('Township Road Rehabilitation')
        // The contractor IS published, deliberately: who is building the road
        // is the question a transparency portal exists to answer.
        ->assertSee('Kalu &amp; Sons Ltd', escape: false)
        // The contract NUMBER is not on the whitelist. It is the internal
        // procurement handle, and a whitelist that leaks the field next to the
        // one it published is not a whitelist.
        ->assertDontSee('WKS/CON/2026/014');
});
