<?php

/**
 * Step 5 of the monitoring lifecycle (digest §8): the completion certificate.
 *
 * The property that matters most here is NEGATIVE — that
 * IssueCompletionCertificate never writes Project::$status itself, but goes
 * through App\Actions\Projects\TransitionProjectStatus, so the transition
 * table, the `projects.certify` permission and the typed status ledger all
 * still apply. The ledger assertions below are how that is proved from the
 * outside.
 */

use App\Actions\Lifecycle\IssueCompletionCertificate;
use App\Actions\Lifecycle\RevokeCertificate;
use App\Enums\CertificateType;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Exceptions\Lifecycle\LifecycleRuleViolation;
use App\Models\Certificate;
use App\Models\Project;
use App\Models\ProjectStatusEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Lifecycle\CompletionCertificateIssued;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('documents');
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    $this->project = Project::factory()->completed()->create(['title' => 'Township Road Rehabilitation']);
    $this->issue = new IssueCompletionCertificate;
});

/**
 * A completed final inspection, written straight into the inspections
 * module's table. A fixture rather than that module's factory on purpose:
 * `site_inspections` is a SOFT dependency here (no foreign key, read behind
 * Schema::hasTable), and coupling this suite to another module's model would
 * make its absence a failure in this one.
 */
function fakeFinalInspection(Project $project, User $inspector, string $status = 'reviewed'): int
{
    $ulid = (string) Str::ulid();

    DB::table('site_inspections')->insert([
        'ulid' => $ulid,
        'tenant_id' => $project->tenant_id,
        'project_id' => $project->id,
        'type' => 'final',
        'status' => $status,
        'scheduled_date' => CarbonImmutable::now()->subWeek()->toDateString(),
        'conducted_at' => CarbonImmutable::now()->subWeek(),
        'lead_inspector_id' => $inspector->id,
        'generated_by' => 'manual',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (int) DB::table('site_inspections')->where('ulid', $ulid)->value('id');
}

/* -------------------------------------------------------------------------- */
/* Issuing */
/* -------------------------------------------------------------------------- */

it('issues a certificate, mints its reference, files the PDF and certifies the project', function () {
    $certificate = ($this->issue)(
        $this->project,
        CertificateType::PracticalCompletion,
        $this->admin,
        'Works inspected and accepted.',
        CarbonImmutable::now()->addMonths(12),
    );

    expect($certificate->type)->toBe(CertificateType::PracticalCompletion)
        ->and($certificate->issued_by_id)->toBe($this->admin->id)
        ->and($certificate->tenant_id)->toBe($this->works->id)
        // <SLUG>/<PC>/<year>/<0000> — the slug is DATA, never a name typed
        // into the codebase.
        ->and($certificate->reference)->toBe('WORKS/PC/'.CarbonImmutable::now()->year.'/0001')
        ->and($certificate->defects_liability_ends_on)->not->toBeNull()
        ->and($certificate->isRevoked())->toBeFalse();

    $media = $certificate->getMedia('certificate');

    expect($media)->toHaveCount(1)
        ->and($media->first()->disk)->toBe('documents')
        ->and($media->first()->file_name)->toEndWith('.pdf');

    Storage::disk('documents')->assertExists($media->first()->id.'/'.$media->first()->file_name);

    // The chokepoint ran: the status moved AND the typed ledger row exists.
    // A direct column write would satisfy the first assertion and fail this one.
    $project = Project::query()->whereKey($this->project->getKey())->firstOrFail();

    expect($project->status)->toBe(ProjectStatus::Certified);

    $event = ProjectStatusEvent::query()
        ->where('project_id', $project->id)
        ->where('to_status', ProjectStatus::Certified)
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->from_status)->toBe(ProjectStatus::Completed)
        ->and($event->actor_id)->toBe($this->admin->id)
        ->and($event->reason)->toContain($certificate->reference);
});

it('numbers certificates sequentially per entity, type and year', function () {
    ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin);

    $second = Project::factory()->completed()->create();
    $certificate = ($this->issue)($second, CertificateType::PracticalCompletion, $this->admin);

    expect($certificate->reference)->toBe('WORKS/PC/'.CarbonImmutable::now()->year.'/0002');
});

it('notifies the entity administrator and state oversight', function () {
    $stateAdmin = userWithRole(Role::StateAdmin);

    ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin);

    Notification::assertSentTo($this->admin, CompletionCertificateIssued::class);
    Notification::assertSentTo($stateAdmin, CompletionCertificateIssued::class);
});

it('refuses to certify a project whose works are not recorded as complete', function () {
    $ongoing = Project::factory()->ongoing()->create();

    expect(fn () => ($this->issue)($ongoing, CertificateType::PracticalCompletion, $this->admin))
        ->toThrow(LifecycleRuleViolation::class, 'cannot be certified');

    expect(Certificate::query()->count())->toBe(0);
});

it('refuses a second certificate of a type already in force', function () {
    ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin);

    $project = Project::query()->whereKey($this->project->getKey())->firstOrFail();

    expect(fn () => ($this->issue)($project, CertificateType::PracticalCompletion, $this->admin))
        ->toThrow(LifecycleRuleViolation::class, 'already in force');

    expect(Certificate::query()->count())->toBe(1);
});

it('refuses final completion before practical completion has been issued', function () {
    expect(fn () => ($this->issue)($this->project, CertificateType::FinalCompletion, $this->admin))
        ->toThrow(LifecycleRuleViolation::class, 'issue the practical completion certificate first');
});

it('issues final completion against an already-certified project without a second transition', function () {
    ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin);

    $project = Project::query()->whereKey($this->project->getKey())->firstOrFail();

    $final = ($this->issue)($project, CertificateType::FinalCompletion, $this->admin);

    expect($final->type)->toBe(CertificateType::FinalCompletion)
        // Final completion closes the defects window; it does not open one.
        ->and($final->defects_liability_ends_on)->toBeNull();

    // One certification event, not two: the lifecycle has a single certified
    // state, and asking for a transition that does not exist would refuse a
    // legitimate second certificate.
    expect(ProjectStatusEvent::query()
        ->where('project_id', $project->id)
        ->where('to_status', ProjectStatus::Certified)
        ->count())->toBe(1);
});

/* -------------------------------------------------------------------------- */
/* The final-inspection rule */
/* -------------------------------------------------------------------------- */

it('certifies without a final inspection while the instance does not require one', function () {
    config()->set('platform.monitoring.require_final_inspection_for_certification', false);

    $certificate = ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin);

    expect($certificate->site_inspection_id)->toBeNull();
});

it('refuses certification when the instance requires a final inspection and none is recorded', function () {
    config()->set('platform.monitoring.require_final_inspection_for_certification', true);

    expect(fn () => ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin))
        ->toThrow(LifecycleRuleViolation::class, 'requires a completed final inspection');

    expect(Certificate::query()->count())->toBe(0);
});

it('reads the inspections module defensively and snapshots the inspection it rests on', function () {
    // The soft dependency in both directions: the table may be absent, and
    // when it is present only a COMPLETED final visit counts.
    expect(Schema::hasTable('site_inspections'))->toBeTrue();

    fakeFinalInspection($this->project, $this->officer, status: 'scheduled');

    $notCounted = ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin);

    expect($notCounted->site_inspection_id)->toBeNull();

    $second = Project::factory()->completed()->create();
    $inspectionId = fakeFinalInspection($second, $this->officer);

    $certificate = ($this->issue)($second, CertificateType::PracticalCompletion, $this->admin);

    expect($certificate->site_inspection_id)->toBe($inspectionId);
});

/* -------------------------------------------------------------------------- */
/* Atomicity */
/* -------------------------------------------------------------------------- */

it('leaves no certificate behind when filing the generated PDF fails', function () {
    // A vault refusal mid-transaction. The two failure modes of issuing are an
    // orphan file on a private disk, or a certificate recorded with no
    // document behind it — the second is a government record that lies, so the
    // whole thing is one transaction.
    config()->set('documents.collections.certificate.max_kb', 0);

    expect(fn () => ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin))
        ->toThrow(ValidationException::class);

    expect(Certificate::query()->count())->toBe(0);

    $project = Project::query()->whereKey($this->project->getKey())->firstOrFail();

    expect($project->status)->toBe(ProjectStatus::Completed);
});

/* -------------------------------------------------------------------------- */
/* Withdrawal */
/* -------------------------------------------------------------------------- */

it('withdraws a certificate on the record, and never twice', function () {
    $revoke = new RevokeCertificate;
    $certificate = ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin);

    $revoked = $revoke($certificate, $this->admin, 'Issued against the wrong contract lot.');

    expect($revoked->isRevoked())->toBeTrue()
        ->and($revoked->revoked_by_id)->toBe($this->admin->id)
        ->and($revoked->revocation_reason)->toBe('Issued against the wrong contract lot.');

    expect(fn () => $revoke($revoked, $this->admin, 'Again.'))
        ->toThrow(LifecycleRuleViolation::class, 'already been revoked');
});

it('refuses a withdrawal with no stated reason', function () {
    $revoke = new RevokeCertificate;
    $certificate = ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin);

    expect(fn () => $revoke($certificate, $this->admin, '   '))
        ->toThrow(LifecycleRuleViolation::class, 'requires a stated reason');
});

it('leaves the project certified after a withdrawal and never re-uses the number', function () {
    $revoke = new RevokeCertificate;
    $certificate = ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin);

    $revoke($certificate, $this->admin, 'Issued against the wrong contract lot.');

    $project = Project::query()->whereKey($this->project->getKey())->firstOrFail();

    // The lifecycle table gives `certified` one exit — `closed` — so there is
    // no transition back, and manufacturing one here would be a second writer
    // for a column with a designated chokepoint.
    expect($project->status)->toBe(ProjectStatus::Certified);

    $replacement = ($this->issue)($project, CertificateType::PracticalCompletion, $this->admin);

    expect($replacement->reference)->not->toBe($certificate->reference)
        ->and($replacement->reference)->toBe('WORKS/PC/'.CarbonImmutable::now()->year.'/0002');
});

/* -------------------------------------------------------------------------- */
/* Authorization */
/* -------------------------------------------------------------------------- */

it('refuses certification to an M&E officer, who does not sign completion certificates', function () {
    expect(fn () => ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->officer))
        ->toThrow(AuthorizationException::class);

    expect(Certificate::query()->count())->toBe(0);
});

it('refuses withdrawal to an M&E officer', function () {
    $revoke = new RevokeCertificate;
    $certificate = ($this->issue)($this->project, CertificateType::PracticalCompletion, $this->admin);

    expect(fn () => $revoke($certificate, $this->officer, 'Not mine to withdraw.'))
        ->toThrow(AuthorizationException::class);
});
