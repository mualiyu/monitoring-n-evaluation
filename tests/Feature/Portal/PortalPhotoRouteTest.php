<?php

/**
 * The one way an image reaches the public portal.
 *
 * Evidence lives on a private disk and normally leaves the platform only
 * through a signed, authenticated, policy-checked download. The portal has no
 * user to authenticate, so the authorization IS the publishing decision, and it
 * is re-checked on every request. Three separate refusals are proved here, plus
 * the 200 — because a route that only ever 404s in tests is a route nobody has
 * confirmed works at all.
 */

use App\Actions\Documents\AttachDocument;
use App\Enums\Role;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('documents');
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->project = Project::factory()->ongoing()->create(['title' => 'Township Road Rehabilitation']);

    $this->photo = app(AttachDocument::class)(
        $this->project,
        'project_photos',
        UploadedFile::fake()->image('site.jpg', 1024, 768),
        $this->officer,
        'Northern section, March visit',
    );

    // A document in the OTHER collection: an award letter is not site
    // photography and must not be reachable through the photo route.
    $this->document = app(AttachDocument::class)(
        $this->project,
        'project_documents',
        UploadedFile::fake()->createWithContent('award-letter.pdf', '%PDF-1.4'."\n".str_repeat('a', 400)),
        $this->officer,
        'Award letter',
    );

    $this->project->forceFill([
        'published_at' => now()->subDay(),
        'published_by_id' => $this->officer->id,
    ])->save();

    actingWithoutTenant();
});

function photoUrl(Project $project, string $uuid): string
{
    return portalUrl('/projects/'.$project->ulid.'/photos/'.$uuid);
}

it('serves a published project photograph to an anonymous visitor', function () {
    $response = $this->get(photoUrl($this->project, (string) $this->photo->uuid));

    $response->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=3600');
});

it('links the photo from the project page with a named route', function () {
    $this->get(portalUrl('/projects/'.$this->project->ulid))
        ->assertOk()
        ->assertSee(route('portal.projects.photo', [
            'ulid' => $this->project->ulid,
            'uuid' => (string) $this->photo->uuid,
        ]), escape: false)
        ->assertSee('Northern section, March visit');
});

it('refuses a uuid from the documents collection', function () {
    // An award letter, a BOQ or a contractor's correspondence is a 404 here,
    // never a download.
    $this->get(photoUrl($this->project, (string) $this->document->uuid))->assertNotFound();
});

it('stops serving every photo the instant the project is withdrawn', function () {
    $url = photoUrl($this->project, (string) $this->photo->uuid);

    $this->get($url)->assertOk();

    actingOnTenant($this->works);
    Project::query()->whereKey($this->project->id)->firstOrFail()
        ->forceFill(['published_at' => null, 'published_by_id' => null])->save();
    actingWithoutTenant();

    // Every URL that was ever shared stops working in the same instant.
    $this->get($url)->assertNotFound();
});

it('answers 404 for an unknown photo uuid on a published project', function () {
    $this->get(photoUrl($this->project, (string) Str::uuid()))->assertNotFound();
});
