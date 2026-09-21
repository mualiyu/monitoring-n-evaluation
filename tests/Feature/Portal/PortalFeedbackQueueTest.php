<?php

/**
 * The two moderation desks behind the portal: an MDA's and the state's.
 *
 * `feedback` is a GLOBAL table with no tenancy column, so the isolation proof
 * here is not "does the scope hold" but "does the relation narrowing hold" —
 * an MDA's queue is confined by whereHas('project'), and if that ever slips,
 * every ministry reads every other ministry's complaints. That is the test
 * this file exists for.
 *
 * Everything is asserted the authorized way first, over real HTTP on the real
 * subdomain, and only then denied: a suite of denials proves that nothing
 * works.
 */

use App\Actions\Feedback\ModerateFeedback;
use App\Actions\Oversight\ListFeedbackAcrossTenants;
use App\Enums\FeedbackStatus;
use App\Enums\Role;
use App\Exceptions\Feedback\FeedbackRuleViolation;
use App\Livewire\Oversight\Feedback\FeedbackQueue as StateQueue;
use App\Livewire\Tenant\Feedback\FeedbackQueue as WorkspaceQueue;
use App\Models\Feedback;
use App\Models\FeedbackResponse;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function () {
    seedPermissions();

    $this->current = app(CurrentTenant::class);

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $this->current->runAs($this->works, function (): void {
        $this->worksOfficer = memberOf(User::factory()->create(['name' => 'Bello Adeyemi']), $this->works, Role::MeOfficer);
        $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

        $this->roads = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);

        $this->worksFeedback = Feedback::factory()->forProject($this->roads)->pending()->create([
            'subject' => 'Work stopped on the township road',
            'body' => 'Nobody has been on this site since the second week of March.',
        ]);
    });

    $this->current->runAs($this->health, function (): void {
        $this->healthOfficer = memberOf(User::factory()->create(), $this->health, Role::MeOfficer);

        $this->clinic = Project::factory()->ongoing()->create(['title' => 'Cottage Hospital Rewiring']);

        $this->healthFeedback = Feedback::factory()->forProject($this->clinic)->pending()->create([
            'subject' => 'The clinic still has no power',
            'body' => 'The rewiring was reported finished but the theatre has no power at night.',
        ]);
    });

    $this->current->forget();

    // No project, no MDA anchor: the state secretariat's pile, and nobody
    // else's.
    $this->orphan = Feedback::factory()->unattached()->pending()->create([
        'subject' => 'The road by the market',
        'body' => 'Somebody started grading the road behind the market and then left.',
    ]);

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->execViewer = userWithRole(Role::ExecutiveViewer);
});

/* -------------------------------------------------------------------------- */
/* The MDA queue */
/* -------------------------------------------------------------------------- */

it('renders an MDA moderation queue over real HTTP', function () {
    $this->actingAs($this->worksOfficer)
        ->get(tenantUrl($this->works, '/feedback'))
        ->assertOk()
        ->assertSeeLivewire(WorkspaceQueue::class)
        ->assertSee('Public feedback')
        ->assertSee('Work stopped on the township road')
        ->assertSee('Township Road Rehabilitation')
        ->assertSee('Awaiting moderation');
});

it('shows one MDA nothing of another MDA, and nothing of the state pile', function () {
    $this->actingAs($this->worksOfficer)
        ->get(tenantUrl($this->works, '/feedback'))
        ->assertOk()
        ->assertSee('Work stopped on the township road')
        ->assertDontSee('The clinic still has no power')
        ->assertDontSee('The road by the market');

    $this->actingAs($this->healthOfficer)
        ->get(tenantUrl($this->health, '/feedback'))
        ->assertOk()
        ->assertSee('The clinic still has no power')
        ->assertDontSee('Work stopped on the township road')
        ->assertDontSee('The road by the market');
});

it('refuses to moderate another MDA\'s feedback even when its identifier is known', function () {
    actingOnTenant($this->works);

    // The ULID is a real one — it simply does not resolve through this
    // workspace's narrowing, and "not found" is the honest answer.
    Livewire::actingAs($this->worksOfficer)
        ->test(WorkspaceQueue::class)
        ->call('publish', $this->healthFeedback->ulid)
        ->assertStatus(404);

    expect(Feedback::query()->whereKey($this->healthFeedback->id)->value('status'))
        ->toBe(FeedbackStatus::Pending);
});

it('refuses the queue to a consultant, who moderates nothing about their own work', function () {
    $this->actingAs($this->consultant)
        ->get(tenantUrl($this->works, '/feedback'))
        ->assertForbidden();
});

it('refuses the queue component itself to a consultant', function () {
    actingOnTenant($this->works);

    Livewire::actingAs($this->consultant)
        ->test(WorkspaceQueue::class)
        ->assertForbidden();
});

/* -------------------------------------------------------------------------- */
/* Moderating */
/* -------------------------------------------------------------------------- */

it('publishes a comment in one click and records who did it', function () {
    actingOnTenant($this->works);

    Livewire::actingAs($this->worksOfficer)
        ->test(WorkspaceQueue::class)
        ->call('publish', $this->worksFeedback->ulid)
        ->assertHasNoErrors();

    $moderated = Feedback::query()->whereKey($this->worksFeedback->id)->firstOrFail();

    expect($moderated->status)->toBe(FeedbackStatus::Published)
        ->and($moderated->moderated_by_id)->toBe($this->worksOfficer->id)
        ->and($moderated->moderated_at)->not->toBeNull();
});

it('will not refuse a comment without a stated reason', function () {
    actingOnTenant($this->works);

    Livewire::actingAs($this->worksOfficer)
        ->test(WorkspaceQueue::class)
        ->call('startModeration', $this->worksFeedback->ulid, FeedbackStatus::Rejected->value)
        ->set('reason', '')
        ->call('confirmModeration')
        ->assertHasErrors(['reason' => 'required']);

    expect(Feedback::query()->whereKey($this->worksFeedback->id)->value('status'))
        ->toBe(FeedbackStatus::Pending);
});

it('records a refusal with its reason on the row', function () {
    actingOnTenant($this->works);

    Livewire::actingAs($this->worksOfficer)
        ->test(WorkspaceQueue::class)
        ->call('startModeration', $this->worksFeedback->ulid, FeedbackStatus::Rejected->value)
        ->set('reason', 'Names a private individual and repeats an unverified allegation.')
        ->call('confirmModeration')
        ->assertHasNoErrors();

    $moderated = Feedback::query()->whereKey($this->worksFeedback->id)->firstOrFail();

    expect($moderated->status)->toBe(FeedbackStatus::Rejected)
        ->and($moderated->moderation_reason)->toContain('private individual');
});

/* -------------------------------------------------------------------------- */
/* The state machine */
/* -------------------------------------------------------------------------- */

it('allows every transition the table allows', function (FeedbackStatus $from, FeedbackStatus $to) {
    actingOnTenant($this->works);

    $feedback = Feedback::query()->whereKey($this->worksFeedback->id)->firstOrFail();
    $feedback->forceFill(['status' => $from])->save();

    (new ModerateFeedback)($feedback, $to, $this->worksOfficer, $to->requiresReason() ? 'A stated reason.' : null);

    expect(Feedback::query()->whereKey($feedback->id)->firstOrFail()->status)->toBe($to);
})->with([
    [FeedbackStatus::Pending, FeedbackStatus::Published],
    [FeedbackStatus::Pending, FeedbackStatus::Rejected],
    [FeedbackStatus::Pending, FeedbackStatus::Spam],
    // Withdrawal has to stay possible: publishing something defamatory is a
    // mistake the state must be able to undo within the minute.
    [FeedbackStatus::Published, FeedbackStatus::Rejected],
    [FeedbackStatus::Published, FeedbackStatus::Spam],
    [FeedbackStatus::Rejected, FeedbackStatus::Published],
    [FeedbackStatus::Rejected, FeedbackStatus::Spam],
    [FeedbackStatus::Spam, FeedbackStatus::Rejected],
]);

it('refuses to publish something already classified as spam', function () {
    actingOnTenant($this->works);

    $feedback = Feedback::query()->whereKey($this->worksFeedback->id)->firstOrFail();
    $feedback->forceFill(['status' => FeedbackStatus::Spam])->save();

    // Publishing junk to a government website is never one mis-click: it has
    // to travel back through `rejected` first.
    expect(fn () => (new ModerateFeedback)($feedback, FeedbackStatus::Published, $this->worksOfficer))
        ->toThrow(FeedbackRuleViolation::class);

    expect(Feedback::query()->whereKey($feedback->id)->firstOrFail()->status)->toBe(FeedbackStatus::Spam);
});

/* -------------------------------------------------------------------------- */
/* Responses */
/* -------------------------------------------------------------------------- */

it('publishes a reply under a published comment and keeps an internal note internal', function () {
    actingOnTenant($this->works);

    $published = Feedback::factory()->forProject($this->roads)->published($this->worksOfficer)->create([
        'subject' => 'Drainage completed and working',
        'body' => 'The market road did not flood this year.',
    ]);

    Livewire::actingAs($this->worksOfficer)
        ->test(WorkspaceQueue::class)
        ->call('startResponse', $published->ulid)
        ->set('responseBody', 'Thank you — the section was certified on 12 February.')
        ->set('responsePublic', true)
        ->call('submitResponse')
        ->assertHasNoErrors();

    Livewire::actingAs($this->worksOfficer)
        ->test(WorkspaceQueue::class)
        ->call('startResponse', $published->ulid)
        ->set('responseBody', 'Internal: hold the retention certificate pending re-measurement.')
        ->set('responsePublic', false)
        ->call('submitResponse')
        ->assertHasNoErrors();

    $responses = FeedbackResponse::query()->where('feedback_id', $published->id)->get();

    expect($responses)->toHaveCount(2)
        ->and($responses->where('is_public', true))->toHaveCount(1)
        ->and($responses->firstWhere('is_public', false)->responded_by_id)->toBe($this->worksOfficer->id);
});

it('refuses a public reply on a comment that is not published', function () {
    actingOnTenant($this->works);

    Livewire::actingAs($this->worksOfficer)
        ->test(WorkspaceQueue::class)
        ->call('startResponse', $this->worksFeedback->ulid)
        // Forcing the toggle on a pending comment is exactly the leak: an
        // internal note becoming visible because its parent was published
        // afterwards.
        ->set('responsePublic', true)
        ->set('responseBody', 'This must not be attachable as a public reply.')
        ->call('submitResponse')
        ->assertSet('failure', fn (?string $failure): bool => $failure !== null && str_contains($failure, 'published feedback'));

    expect(FeedbackResponse::query()->count())->toBe(0);
});

/* -------------------------------------------------------------------------- */
/* The state queue */
/* -------------------------------------------------------------------------- */

it('renders the state queue over real HTTP, including the comments no MDA owns', function () {
    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/feedback'))
        ->assertOk()
        ->assertSeeLivewire(StateQueue::class)
        ->assertSee('Work stopped on the township road')
        ->assertSee('The clinic still has no power')
        ->assertSee('The road by the market')
        ->assertSee('Ministry of Works')
        ->assertSee('Ministry of Health');
});

it('lets a read-only oversight role read the queue but not moderate it', function () {
    $this->actingAs($this->execViewer)
        ->get(oversightUrl('/feedback'))
        ->assertOk()
        ->assertSee('Work stopped on the township road');

    Livewire::actingAs($this->execViewer)
        ->test(StateQueue::class)
        ->call('publish', $this->worksFeedback->ulid)
        ->assertForbidden();

    expect(Feedback::query()->whereKey($this->worksFeedback->id)->value('status'))
        ->toBe(FeedbackStatus::Pending);
});

it('refuses the state queue to a workspace user, however senior in their own ministry', function () {
    $mdaAdmin = $this->current->runAs(
        $this->works,
        fn (): User => memberOf(User::factory()->create(), $this->works, Role::MdaAdmin),
    );

    $this->actingAs($mdaAdmin)
        ->get(oversightUrl('/feedback'))
        ->assertForbidden();
});

it('refuses the state queue component itself to a workspace user', function () {
    $mdaAdmin = $this->current->runAs(
        $this->works,
        fn (): User => memberOf(User::factory()->create(), $this->works, Role::MdaAdmin),
    );

    $this->current->forget();

    Livewire::actingAs(User::query()->whereKey($mdaAdmin->id)->firstOrFail())
        ->test(StateQueue::class)
        ->assertForbidden();
});

it('refuses a cross-MDA read to an actor without oversight authority', function () {
    expect(fn () => (new ListFeedbackAcrossTenants)($this->worksOfficer))
        ->toThrow(AuthorizationException::class);
});

it('filters the state queue down to the comments nobody owns', function () {
    Livewire::actingAs($this->stateAdmin)
        ->test(StateQueue::class)
        ->set('unattached', true)
        ->assertSee('The road by the market')
        ->assertDontSee('Work stopped on the township road');
});
