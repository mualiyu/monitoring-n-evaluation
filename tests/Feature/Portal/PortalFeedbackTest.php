<?php

/**
 * The portal's ONLY write, and the moderation gate in front of what it
 * produces.
 *
 * Four things are proved here and each one has a specific failure in mind:
 *
 *  - the honeypot drops a bot WHILE RETURNING THE ORDINARY THANK-YOU PAGE. A
 *    silent control that answers differently has stopped being silent, and a
 *    bot that learns which submissions were dropped is a bot that gets tuned.
 *  - the rate limit actually applies to the HTTP route. It is `throttle:` on a
 *    real POST precisely because a Livewire update POST never travels through
 *    a portal route's middleware at all.
 *  - nothing a stranger types is public until a moderator publishes it, and an
 *    internal note under a published comment stays internal.
 *  - the form's OWN rendered value round-trips through validation. Setting
 *    properties in a component test would never have caught a hidden field
 *    whose name or value the view got wrong.
 */

use App\Actions\Feedback\SubmitFeedback;
use App\Enums\FeedbackStatus;
use App\Models\Feedback;
use App\Models\FeedbackResponse;
use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $lga = Lga::factory()->create(['name' => 'Ilorin West']);

    $this->project = Project::factory()->ongoing()->create([
        'title' => 'Construction of Ward 3 Clinic',
        'reference' => 'PRJ-30001',
    ]);

    ProjectLocation::factory()->primary()->at($lga)->create(['project_id' => $this->project->id]);

    $this->project->forceFill([
        'published_at' => now()->subDay(),
        'published_by_id' => User::factory()->create()->id,
    ])->save();

    $this->moderator = User::factory()->create(['name' => 'Desk Officer']);

    actingWithoutTenant();
});

function submission(array $overrides = []): array
{
    return [
        'subject' => 'Work stopped at the site',
        'body' => 'Nobody has been on this site since the second week of March and the excavation is unfenced.',
        ...$overrides,
    ];
}

it('accepts a submission, lands it pending, and shows the thank-you page', function () {
    $this->post(portalUrl('/feedback'), submission(['project' => $this->project->ulid]))
        ->assertRedirect(route('portal.feedback.thanks'));

    $feedback = Feedback::query()->firstOrFail();

    expect($feedback->status)->toBe(FeedbackStatus::Pending)
        ->and($feedback->project_id)->toBe($this->project->id)
        // Forensic columns come off the request, never off the payload.
        ->and($feedback->ip_address)->not->toBeNull();

    $this->get(portalUrl('/feedback/thanks'))
        ->assertOk()
        ->assertSee('your comment has been received');
});

it('takes the project ULID from the form it actually rendered and resolves it through validation', function () {
    // Read the value out of the RENDERED form rather than assuming it. A
    // component test that sets the property directly would pass against a view
    // that never emitted the field at all.
    $html = (string) $this->get(portalUrl('/feedback?project='.$this->project->ulid))->assertOk()->getContent();

    // The form's own action and method, as the browser would read them…
    expect(preg_match('/<form method="(POST)" action="([^"]+)"/', $html, $form))->toBe(1)
        ->and(html_entity_decode($form[2]))->toBe(route('portal.feedback.store'));

    // …and the ULID the view actually emitted, not the one the test assumed.
    expect(preg_match('/<input type="hidden" name="project" value="([^"]*)"/', $html, $matches))->toBe(1)
        ->and($matches[1])->toBe($this->project->ulid);

    // Fed straight back through the real route, so a misnamed field or a
    // mis-rendered value fails here rather than in production.
    $this->call($form[1], html_entity_decode($form[2]), submission(['project' => $matches[1]]))
        ->assertRedirect(route('portal.feedback.thanks'));

    expect(Feedback::query()->firstOrFail()->project_id)->toBe($this->project->id);
});

it('never trusts a project reference a visitor supplies', function () {
    actingOnTenant($this->works);
    $unpublished = Project::factory()->ongoing()->create(['title' => 'Unpublished Works']);
    actingWithoutTenant();

    // An unpublished project's ULID resolves to nothing, so the comment lands
    // unattached rather than attached to a record the state has not opened.
    $this->post(portalUrl('/feedback'), submission(['project' => $unpublished->ulid]))
        ->assertRedirect(route('portal.feedback.thanks'));

    expect(Feedback::query()->firstOrFail()->project_id)->toBeNull();
});

it('drops a honeypot submission while answering exactly as it answers a person', function () {
    $human = $this->post(portalUrl('/feedback'), submission(['project' => $this->project->ulid]))
        ->assertRedirect(route('portal.feedback.thanks'))
        ->assertSessionHasNoErrors();

    Feedback::query()->delete();

    $bot = $this->post(portalUrl('/feedback'), submission([
        'project' => $this->project->ulid,
        'website' => 'https://buy-cement.test',
    ]));

    // Same destination, same status, and no field error naming the trap: a bot
    // that can tell its submission was dropped is a bot that gets tuned.
    expect($bot->headers->get('Location'))->toBe($human->headers->get('Location'))
        ->and($bot->getStatusCode())->toBe($human->getStatusCode());

    $bot->assertRedirect(route('portal.feedback.thanks'))->assertSessionHasNoErrors();

    $this->get(route('portal.feedback.thanks'))
        ->assertOk()
        ->assertSee('your comment has been received')
        ->assertDontSee('honeypot')
        ->assertDontSee('rejected');

    // And nothing was written.
    expect(Feedback::query()->count())->toBe(0);
});

it('rate-limits submissions from one address', function () {
    for ($attempt = 1; $attempt <= SubmitFeedback::MAX_PER_HOUR; $attempt++) {
        $this->post(portalUrl('/feedback'), submission([
            'project' => $this->project->ulid,
            'subject' => 'Report number '.$attempt,
        ]))->assertRedirect(route('portal.feedback.thanks'));
    }

    expect(Feedback::query()->count())->toBe(SubmitFeedback::MAX_PER_HOUR);

    $this->post(portalUrl('/feedback'), submission([
        'project' => $this->project->ulid,
        'subject' => 'One too many',
    ]))->assertStatus(429);

    expect(Feedback::query()->count())->toBe(SubmitFeedback::MAX_PER_HOUR);
});

it('tells a person what is wrong instead of silently dropping them', function () {
    $this->post(portalUrl('/feedback'), ['subject' => '', 'body' => 'short'])
        ->assertSessionHasErrors(['subject', 'body']);

    expect(Feedback::query()->count())->toBe(0);
});

it('marks obvious spam rather than eating it', function () {
    $this->post(portalUrl('/feedback'), submission([
        'project' => $this->project->ulid,
        'body' => 'Buy cement at https://a.test and https://b.test and https://c.test today.',
    ]))->assertRedirect(route('portal.feedback.thanks'));

    $feedback = Feedback::query()->firstOrFail();

    // A false positive costs a moderator two seconds; a false negative costs
    // nothing, because nothing is public without them anyway.
    expect($feedback->flagged_as_spam)->toBeTrue()
        ->and($feedback->status)->toBe(FeedbackStatus::Pending);
});

it('shows only published feedback on a project page, and only public responses under it', function () {
    $published = Feedback::factory()->forProject($this->project)->published($this->moderator)->create([
        'subject' => 'Drainage completed and working',
        'body' => 'The market road did not flood this year for the first time in four years.',
    ]);

    FeedbackResponse::factory()->on($published)->by($this->moderator)->public()->create([
        'body' => 'Thank you — the section was certified on 12 February.',
    ]);

    FeedbackResponse::factory()->on($published)->by($this->moderator)->internal()->create([
        'body' => 'Internal: hold the retention certificate pending re-measurement.',
    ]);

    Feedback::factory()->forProject($this->project)->pending()->create([
        'subject' => 'Pending allegation about the supervisor',
        'body' => 'This has not been reviewed by anybody yet and must never be public.',
    ]);

    Feedback::factory()->forProject($this->project)->rejected($this->moderator)->create([
        'subject' => 'Refused allegation naming a resident',
        'body' => 'A moderator declined to publish this, which has to mean it is not published.',
    ]);

    Feedback::factory()->forProject($this->project)->spam($this->moderator)->create([
        'subject' => 'CHEAP BUILDING MATERIALS',
        'body' => 'Visit https://spam.test for wholesale cement prices today.',
    ]);

    $this->get(portalUrl('/projects/'.$this->project->ulid))
        ->assertOk()
        ->assertSee('Drainage completed and working')
        ->assertSee('the section was certified on 12 February')
        // The three gates, each asserted on its own.
        ->assertDontSee('Pending allegation about the supervisor')
        ->assertDontSee('Refused allegation naming a resident')
        ->assertDontSee('CHEAP BUILDING MATERIALS')
        ->assertDontSee('hold the retention certificate');
});

it('escapes what a stranger typed instead of rendering it', function () {
    Feedback::factory()->forProject($this->project)->published($this->moderator)->create([
        'subject' => 'Script <script>alert(1)</script> attempt',
        'body' => 'Body with <img src=x onerror=alert(2)> in it.',
        'submitter_name' => '<b>Bold Name</b>',
    ]);

    $html = $this->get(portalUrl('/projects/'.$this->project->ulid))->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->not->toContain('<img src=x onerror=alert(2)>')
        ->not->toContain('<b>Bold Name</b>')
        ->toContain('&lt;script&gt;');
});

it('takes a published project\'s conversation down with it', function () {
    Feedback::factory()->forProject($this->project)->published($this->moderator)->create([
        'subject' => 'Drainage completed and working',
        'body' => 'This comment is published and the project is published.',
    ]);

    $this->get(portalUrl('/projects/'.$this->project->ulid))->assertOk()->assertSee('Drainage completed and working');

    actingOnTenant($this->works);
    Project::query()->whereKey($this->project->id)->firstOrFail()
        ->forceFill(['published_at' => null, 'published_by_id' => null])->save();
    actingWithoutTenant();

    // A thread cannot outlive its project's publication.
    $this->get(portalUrl('/projects/'.$this->project->ulid))->assertNotFound();
});
