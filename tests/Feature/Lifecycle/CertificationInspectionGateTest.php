<?php

/**
 * The seam between the two modules of the monitoring lifecycle: certification
 * (step 5) resting on a final inspection (step 4).
 *
 * CertificationTest already proves the gate against a hand-written
 * `site_inspections` row — deliberately, because the table is a SOFT
 * dependency read behind Schema::hasTable() and that suite must keep passing
 * if the inspections module is absent. What was untested is the seam itself:
 * that a REAL inspection, scheduled and conducted and signed off through the
 * inspections module's own Actions, is what
 * `monitoring.require_final_inspection_for_certification` accepts — and that
 * with the setting ON and a final inspection on the record, certification
 * actually completes rather than being refused twice over by two different
 * guards.
 *
 * Every assertion about the setting reads it from the SettingsRepository the
 * Actions read, never from a literal: it is policy a state switches, and a
 * test that hard-codes the default passes against a platform that has stopped
 * honouring it.
 */

use App\Actions\Documents\AttachDocument;
use App\Actions\Inspections\ReviewInspectionReport;
use App\Actions\Inspections\ScheduleInspection;
use App\Actions\Inspections\StartInspection;
use App\Actions\Inspections\SubmitInspectionReport;
use App\Actions\Lifecycle\IssueCompletionCertificate;
use App\Enums\CertificateType;
use App\Enums\InspectionOutcome;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Exceptions\Lifecycle\LifecycleRuleViolation;
use App\Models\Certificate;
use App\Models\Project;
use App\Models\SiteInspection;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    Notification::fake();
    seedPermissions();

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-21 09:00:00'));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->monitor = memberOf(User::factory()->create(), $this->works, Role::FieldMonitor);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->project = Project::factory()->completed()->create(['title' => 'Township Road Rehabilitation']);

    $this->issue = app(IssueCompletionCertificate::class);
    $this->settings = app(SettingsRepository::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A final inspection carried all the way through the inspections module's own
 * lifecycle — scheduled, conducted, filed, signed off by somebody other than
 * the inspector. Not a fixture row: the point of this file is the seam, and a
 * hand-written row would prove the two modules agree about a shape rather than
 * about a fact.
 */
function certifiableFinalInspection(Project $project, User $inspector, User $officer): SiteInspection
{
    $inspection = app(ScheduleInspection::class)(
        project: $project,
        type: InspectionType::Final,
        scheduledDate: CarbonImmutable::now(),
        leadInspector: $inspector,
        actor: $officer,
    );

    app(StartInspection::class)($inspection, $inspector);

    app(AttachDocument::class)(
        $inspection,
        'inspection_photos',
        UploadedFile::fake()->image('completed-works.jpg', 800, 600),
        $inspector,
    );

    app(SubmitInspectionReport::class)(
        SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail(),
        $inspector,
        InspectionOutcome::Satisfactory,
        ['findings' => 'The works are complete across the full 3.4 km and match the bill of quantities.'],
    );

    app(ReviewInspectionReport::class)(
        SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail(),
        $officer,
        'Final inspection accepted; the project may be certified.',
    );

    return SiteInspection::query()->whereKey($inspection->getKey())->firstOrFail();
}

/* -------------------------------------------------------------------------- */
/* The gate, switched on */
/* -------------------------------------------------------------------------- */

it('refuses certification when the state requires a final inspection and none exists', function () {
    config()->set('platform.monitoring.require_final_inspection_for_certification', true);

    expect($this->settings->bool('monitoring', 'require_final_inspection_for_certification', false))
        ->toBeTrue();

    expect(fn () => ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin))
        ->toThrow(LifecycleRuleViolation::class);

    expect(Certificate::query()->count())->toBe(0)
        ->and(Project::query()->whereKey($this->project->getKey())->value('status'))
        ->toBe(ProjectStatus::Completed);
});

it('certifies a project whose final inspection was actually conducted and signed off', function () {
    config()->set('platform.monitoring.require_final_inspection_for_certification', true);

    $inspection = certifiableFinalInspection($this->project, $this->monitor, $this->officer);

    expect($inspection->status)->toBe(InspectionStatus::Reviewed)
        ->and($inspection->type)->toBe(InspectionType::Final);

    $certificate = ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin);

    expect($certificate->reference)->not->toBeEmpty()
        // The certificate names the visit it rests on — which is the whole
        // point of requiring one.
        ->and($certificate->site_inspection_id)->toBe($inspection->id)
        ->and(Project::query()->whereKey($this->project->getKey())->value('status'))
        ->toBe(ProjectStatus::Certified);
});

it('does not accept a final inspection that is still only in the diary', function () {
    config()->set('platform.monitoring.require_final_inspection_for_certification', true);

    // Scheduled, not conducted: a certificate resting on a visit nobody has
    // made yet is exactly the failure this module exists to prevent.
    app(ScheduleInspection::class)(
        project: $this->project,
        type: InspectionType::Final,
        scheduledDate: CarbonImmutable::now()->addDays(3),
        leadInspector: $this->monitor,
        actor: $this->officer,
    );

    expect(fn () => ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin))
        ->toThrow(LifecycleRuleViolation::class);

    expect(Certificate::query()->count())->toBe(0);
});

it('does not accept a routine visit in place of a final one', function () {
    config()->set('platform.monitoring.require_final_inspection_for_certification', true);

    $routine = app(ScheduleInspection::class)(
        project: $this->project,
        type: InspectionType::Routine,
        scheduledDate: CarbonImmutable::now(),
        leadInspector: $this->monitor,
        actor: $this->officer,
    );

    app(StartInspection::class)($routine, $this->monitor);

    app(AttachDocument::class)(
        $routine,
        'inspection_photos',
        UploadedFile::fake()->image('progress.jpg', 800, 600),
        $this->monitor,
    );

    app(SubmitInspectionReport::class)(
        SiteInspection::query()->whereKey($routine->getKey())->firstOrFail(),
        $this->monitor,
        InspectionOutcome::Satisfactory,
        ['findings' => 'Periodic verification of reported progress; nothing outstanding.'],
    );

    expect(fn () => ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin))
        ->toThrow(LifecycleRuleViolation::class);
});

/* -------------------------------------------------------------------------- */
/* The gate, switched off */
/* -------------------------------------------------------------------------- */

it('still snapshots the final inspection when the state does not require one', function () {
    config()->set('platform.monitoring.require_final_inspection_for_certification', false);

    expect($this->settings->bool('monitoring', 'require_final_inspection_for_certification', true))
        ->toBeFalse();

    $inspection = certifiableFinalInspection($this->project, $this->monitor, $this->officer);

    $certificate = ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin);

    // The requirement is a gate, not the reason for the link: where a visit
    // exists, the certificate rests on it either way.
    expect($certificate->site_inspection_id)->toBe($inspection->id);
});

/* -------------------------------------------------------------------------- */
/* Post-completion monitoring survives certification */
/* -------------------------------------------------------------------------- */

it('still allows a post-completion visit against the certified project', function () {
    // Step 6 of the lifecycle happens six to twelve months AFTER the
    // certificate — so certification must not close the project to inspection.
    config()->set('platform.monitoring.require_final_inspection_for_certification', false);

    ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin);

    $certified = Project::query()->whereKey($this->project->getKey())->firstOrFail();

    expect($certified->status)->toBe(ProjectStatus::Certified);

    $inspection = app(ScheduleInspection::class)(
        project: $certified,
        type: InspectionType::PostCompletion,
        scheduledDate: CarbonImmutable::now()->addMonths(6),
        leadInspector: $this->monitor,
        actor: $this->officer,
    );

    expect($inspection->type)->toBe(InspectionType::PostCompletion)
        ->and($inspection->status)->toBe(InspectionStatus::Scheduled);
});

/* -------------------------------------------------------------------------- */
/* Who may sign */
/* -------------------------------------------------------------------------- */

it('refuses certification to a consultant, who never signs off their own delivery', function () {
    // The contractor delivering the works cannot be the authority that
    // declares them complete — the whole certificate would be self-attested.
    $consultant = User::query()->whereKey($this->consultant->id)->firstOrFail();

    expect($consultant->can('issue', [Certificate::class, $this->project]))->toBeFalse();

    expect(fn () => ($this->issue)($this->project, CertificateType::PracticalCompletion, $consultant))
        ->toThrow(AuthorizationException::class);

    expect(Certificate::query()->count())->toBe(0)
        ->and(Project::query()->whereKey($this->project->getKey())->value('status'))
        ->toBe(ProjectStatus::Completed);
});

it('refuses certification to the field monitor who conducted the final inspection', function () {
    config()->set('platform.monitoring.require_final_inspection_for_certification', true);

    certifiableFinalInspection($this->project, $this->monitor, $this->officer);

    $monitor = User::query()->whereKey($this->monitor->id)->firstOrFail();

    expect(fn () => ($this->issue)($this->project, CertificateType::PracticalCompletion, $monitor))
        ->toThrow(AuthorizationException::class);

    expect(Certificate::query()->count())->toBe(0);
});
