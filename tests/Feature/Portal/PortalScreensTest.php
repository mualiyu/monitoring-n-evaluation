<?php

/**
 * Every public portal screen, rendered over real HTTP on the apex domain.
 *
 * The brief's first rule: a denial-only suite lets a broken screen pass. Each
 * route here is actually fetched and its content asserted, because the portal
 * is the one surface where a 500 is a headline rather than a bug report — and
 * because Livewire::test() would never render the layout, the security headers
 * or the <noscript> fallback, which is where three of these assertions live.
 */

use App\Http\Middleware\PortalSecurityHeaders;
use App\Models\Lga;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->lga = Lga::factory()->create(['name' => 'Ilorin West']);

    $this->project = Project::factory()->ongoing()->create([
        'title' => 'Rehabilitation of Township Roads',
        'reference' => 'PRJ-10001',
    ]);

    ProjectLocation::factory()->primary()->at($this->lga)->create([
        'project_id' => $this->project->id,
        'site_name' => 'Township Section',
        'latitude' => '8.496400',
        'longitude' => '4.542700',
    ]);

    $publisher = User::factory()->create();
    $this->project->forceFill([
        'published_at' => now()->subDay(),
        'published_by_id' => $publisher->id,
    ])->save();

    actingWithoutTenant();
});

it('renders the landing page with the published counters', function () {
    $this->get(portalUrl('/'))
        ->assertOk()
        ->assertSee('Published portfolio at a glance')
        ->assertSee('Rehabilitation of Township Roads')
        // The counters are computed over published rows, not invented.
        ->assertSee('Recently published');
});

it('renders the published-projects browser', function () {
    $this->get(portalUrl('/projects'))
        ->assertOk()
        ->assertSee('Published projects')
        ->assertSee('Rehabilitation of Township Roads')
        ->assertSee('PRJ-10001');
});

it('renders a published project detail page', function () {
    $this->get(portalUrl('/projects/'.$this->project->ulid))
        ->assertOk()
        ->assertSee('Rehabilitation of Township Roads')
        ->assertSee('Ministry of Works')
        ->assertSee('Ilorin West')
        ->assertSee('Contract value');
});

it('renders the map with its pins and a no-JavaScript list of the same sites', function () {
    $response = $this->get(portalUrl('/map'))->assertOk();

    $response->assertSee('Project map')
        // The pin data the map draws from…
        ->assertSee('portal-map-pins', escape: false)
        ->assertSee('8.4964', escape: false)
        // …and the identical sites as plain HTML, inside <noscript>.
        ->assertSee('<noscript>', escape: false)
        ->assertSee('Ilorin West');

    $html = $response->getContent();
    $noscript = substr($html, (int) strpos($html, '<noscript>', (int) strpos($html, 'portal-map-pins')));

    expect($noscript)->toContain('Rehabilitation of Township Roads');
});

it('loads Leaflet from the allow-listed CDN on the map and nowhere else', function () {
    $this->get(portalUrl('/map'))
        ->assertOk()
        ->assertSee(PortalSecurityHeaders::LEAFLET_CDN.'/leaflet@1.9.4/dist/leaflet.js', escape: false)
        // Subresource integrity, so a compromised CDN serves nobody.
        ->assertSee('integrity="sha256-', escape: false);

    foreach (['/', '/projects', '/reports', '/feedback'] as $path) {
        $this->get(portalUrl($path))
            ->assertOk()
            ->assertDontSee(PortalSecurityHeaders::LEAFLET_CDN, escape: false);
    }
});

it('renders the published-reports screen as a designed empty state', function () {
    $this->get(portalUrl('/reports'))
        ->assertOk()
        ->assertSee('No report has been published yet')
        ->assertSee('What will appear here');
});

it('renders the feedback form and the thank-you page', function () {
    $this->get(portalUrl('/feedback'))
        ->assertOk()
        ->assertSee('Tell the monitoring team what you see')
        ->assertSee('name="subject"', escape: false)
        ->assertSee('name="body"', escape: false);

    $this->get(portalUrl('/feedback/thanks'))
        ->assertOk()
        ->assertSee('your comment has been received');
});

it('names the project on the feedback form when one is linked, resolved under the published predicate', function () {
    $this->get(portalUrl('/feedback?project='.$this->project->ulid))
        ->assertOk()
        ->assertSee('Rehabilitation of Township Roads')
        ->assertSee('value="'.$this->project->ulid.'"', escape: false);

    // An unknown ULID is not an error and not a hint: it is simply a general
    // comment form.
    $this->get(portalUrl('/feedback?project=not-a-real-ulid'))
        ->assertOk()
        ->assertDontSee('Rehabilitation of Township Roads');
});

it('sends the portal security headers on every portal route', function () {
    foreach (['/', '/projects', '/projects/'.$this->project->ulid, '/map', '/reports', '/feedback', '/feedback/thanks'] as $path) {
        $response = $this->get(portalUrl($path))->assertOk();

        expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Security-Policy'))
            ->toContain("frame-ancestors 'none'")
            ->toContain("form-action 'self'")
            ->toContain(PortalSecurityHeaders::LEAFLET_CDN);
    }
});

it('links every portal surface with named routes rather than hand-built strings', function () {
    $this->get(portalUrl('/'))
        ->assertOk()
        ->assertSee(route('portal.projects.index'), escape: false)
        ->assertSee(route('portal.map'), escape: false)
        ->assertSee(route('portal.feedback.create'), escape: false)
        ->assertSee(route('portal.projects.show', ['ulid' => $this->project->ulid]), escape: false);
});

it('performs no write on any portal GET route', function () {
    $writes = [];

    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    foreach (['/', '/projects', '/projects/'.$this->project->ulid, '/map', '/reports', '/feedback', '/feedback/thanks'] as $path) {
        $this->get(portalUrl($path))->assertOk();
    }

    expect($writes)->toBe([]);
});
