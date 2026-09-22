<?php

/**
 * "Take me to the thing this is about" — for every notification the platform
 * actually sends, on both surfaces.
 *
 * NotificationLink's own contract is that a notification that cannot be
 * clicked is one somebody has to go and look for, which in practice means it
 * is ignored. Several payloads name a record whose detail screen does not
 * exist (recommendations, certificates) or does not exist on the state surface
 * (evaluations, work plans); they used to render as dead rows. The register
 * the record lives in is always a better answer than nothing.
 *
 * Payloads are produced by the notifications' own toArray(), not hand-written,
 * so a payload key renamed in a notification cannot leave these tests passing
 * against a shape nobody sends any more.
 */

use App\Enums\EvaluationStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Enums\WorkplanStatus;
use App\Models\Evaluation;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workplan;
use App\Notifications\Concerns\NotificationLink;
use App\Notifications\Evaluation\EvaluationChainUpdated;
use App\Notifications\Evaluation\RecommendationAssigned;
use App\Notifications\Issues\IssueAssigned;
use App\Notifications\Projects\ProjectStatusUpdated;
use App\Notifications\Workplans\WorkplanChainUpdated;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);
    URL::defaults(['tenant' => $this->works->slug]);

    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
});

/** Deliver $notification to the officer's inbox and hand back the stored row. */
function delivered(User $user, Notification $notification): DatabaseNotification
{
    $user->notifyNow($notification, ['database']);

    /** @var DatabaseNotification $row */
    $row = $user->notifications()->latest()->firstOrFail();

    return $row;
}

it('links a recommendation to the follow-up register on the workspace', function () {
    $recommendation = Recommendation::factory()->create(['title' => 'Re-sequence the drainage works']);

    $row = delivered($this->officer, new RecommendationAssigned($recommendation));

    expect(NotificationLink::for($row, onTenantSurface: true))
        ->toBe('http://works.mne.test/recommendations');
});

it('links a recommendation to the state follow-up register on the oversight surface', function () {
    $recommendation = Recommendation::factory()->create();

    $row = delivered($this->officer, new RecommendationAssigned($recommendation));

    expect(NotificationLink::for($row, onTenantSurface: false))
        ->toBe('http://oversight.mne.test/recommendations');
});

it('links an evaluation to its own page on the workspace and to the state register on oversight', function () {
    $evaluation = Evaluation::factory()->underReview()->create();

    $row = delivered($this->officer, new EvaluationChainUpdated($evaluation, EvaluationStatus::UnderReview));

    expect(NotificationLink::for($row, onTenantSurface: true))
        ->toBe('http://works.mne.test/evaluations/'.$evaluation->ulid)
        ->and(NotificationLink::for($row, onTenantSurface: false))
        ->toBe('http://oversight.mne.test/evaluations');
});

it('links a work plan to its page on the workspace and to the state register on oversight', function () {
    $workplan = Workplan::factory()->submitted()->create();

    $row = delivered($this->officer, new WorkplanChainUpdated($workplan, WorkplanStatus::Submitted));

    expect(NotificationLink::for($row, onTenantSurface: true))
        ->toBe('http://works.mne.test/workplans/'.$workplan->ulid)
        ->and(NotificationLink::for($row, onTenantSurface: false))
        ->toBe('http://oversight.mne.test/workplans');
});

it('still prefers a record’s own page over any register', function () {
    $project = Project::factory()->ongoing()->create();

    $row = delivered($this->officer, new ProjectStatusUpdated($project, ProjectStatus::InProgress, ProjectStatus::Suspended));

    expect(NotificationLink::for($row, onTenantSurface: true))
        ->toBe('http://works.mne.test/projects/'.$project->ulid)
        ->and(NotificationLink::for($row, onTenantSurface: false))
        ->toBe('http://oversight.mne.test/projects/'.$project->ulid);
});

/* -------------------------------------------------------------------------- */
/* The row's summary line names the record, not the notification kind again */
/* -------------------------------------------------------------------------- */

it('summarises a notification by the record it concerns, not by repeating its headline', function (Closure $make, string $expected) {
    $row = delivered($this->officer, $make());

    expect(NotificationLink::summary($row))->toContain($expected)
        ->and(NotificationLink::summary($row))->not->toBe(NotificationLink::headline($row));
})->with([
    'recommendation' => [
        fn () => new RecommendationAssigned(Recommendation::factory()->create(['title' => 'Re-sequence the drainage works'])),
        'Re-sequence the drainage works',
    ],
    'evaluation' => [
        fn () => new EvaluationChainUpdated(Evaluation::factory()->create(['title' => 'Mid-term evaluation of township roads']), EvaluationStatus::InProgress),
        'Mid-term evaluation of township roads',
    ],
    'work plan' => [
        fn () => new WorkplanChainUpdated(Workplan::factory()->submitted()->create(['title' => 'Annual Work Plan 2026']), WorkplanStatus::Submitted),
        'Annual Work Plan 2026',
    ],
    'issue' => [
        fn () => new IssueAssigned(Issue::factory()->create(['title' => 'Culvert collapse at chainage 2+150'])),
        'Culvert collapse at chainage 2+150',
    ],
]);
