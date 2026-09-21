<?php

use App\Actions\Feedback\SubmitFeedback;
use App\Actions\Portal\BuildPortalSummary;
use App\Actions\Portal\FindPublishedProject;
use App\Actions\Portal\ListPortalFilterOptions;
use App\Actions\Portal\ListPublishedFeedback;
use App\Actions\Portal\ListPublishedProjectPins;
use App\Actions\Portal\ListPublishedProjects;
use App\Exceptions\Feedback\FeedbackRuleViolation;
use App\Exceptions\Feedback\HoneypotTripped;
use App\Http\Controllers\Portal\PublishedPhotoController;
use App\Http\Middleware\PortalSecurityHeaders;
use App\Livewire\Portal\ProjectBrowser;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/*
|--------------------------------------------------------------------------
| Portal surface — apex domain
|--------------------------------------------------------------------------
| The public transparency portal. No authentication, and none is coming: the
| only thing a visitor can do besides read is submit feedback.
|
| Three properties hold for every route in this file, and the tests in
| tests/Feature/Portal enforce all three:
|
|  1. READ-ONLY. Every GET here resolves data through app/Actions/Portal/*,
|     which return published rows projected through
|     App\Support\Publishing\PublicProjectPayload. The single exception is the
|     POST below.
|  2. PUBLISHED-ONLY. Nothing on this surface can reach an unpublished record.
|     The published predicate lives in one place
|     (PublicProjectPayload::publishedOnly) and every portal read applies it,
|     so an unpublished project is a 404 on the detail page, absent from the
|     list, absent from the map, and absent from the counters.
|  3. NO MODEL BINDING. Projects are addressed by {ulid} as a plain string,
|     never by an implicit {project} parameter: implicit binding resolves under
|     the tenant scope, and this domain has no tenant bound, so it would answer
|     500 where it must answer 404.
|
| Route names are prefixed `portal.` by bootstrap/app.php.
*/

/*
| The feedback limiter, registered here because this is the file that owns the
| portal's single write. App\Actions\Feedback\SubmitFeedback consults the same
| key and the same ceiling from inside the Action, so the limit is one limit
| whichever way a submission arrives.
|
| Keyed on a HASH of the IP, not the IP: the limiter cache is not a government
| record store, and it should not end up holding a readable list of who
| contacted the state about which public project.
*/
RateLimiter::for(SubmitFeedback::LIMITER, fn (Request $request): Limit => Limit::perHour(SubmitFeedback::MAX_PER_HOUR)
    ->by(SubmitFeedback::throttleKey($request->ip())));

Route::middleware(PortalSecurityHeaders::class)->group(function (): void {
    /*
    | Landing page. Counters and the three most recent publications, so the
    | page is honest on day one: zeros and an empty state until the first
    | publishing run, never invented figures.
    */
    Route::get('/', fn (): View => view('portal.home', [
        'summary' => (new BuildPortalSummary)(),
        'recent' => (new ListPublishedProjects)([], 3)->items(),
    ]))->name('home');

    /*
    | The published-projects browser. A Livewire component for the filter
    | experience, but the filter bar is a real <form method="GET"> underneath,
    | so the screen still filters with JavaScript switched off — which on a
    | public portal is not a nicety.
    */
    Route::get('/projects', ProjectBrowser::class)->name('projects.index');

    Route::get('/projects/{ulid}', function (string $ulid): View {
        $project = (new FindPublishedProject)($ulid);

        // 404, not 403: "we have nothing published under that address" is the
        // truthful answer, and a 403 would confirm the record exists.
        abort_if($project === null, 404);

        return view('portal.project', [
            'project' => $project,
            'feedback' => (new ListPublishedFeedback)($ulid),
        ]);
    })->name('projects.show');

    /*
    | Site photography. The publishing decision IS the authorization — see
    | PublishedPhotoController. Bound by uuid, serving the re-encoded
    | conversion only.
    */
    Route::get('/projects/{ulid}/photos/{uuid}', PublishedPhotoController::class)
        ->name('projects.photo');

    /*
    | The map. Leaflet comes from a CDN in the portal layout ONLY (allow-listed
    | in PortalSecurityHeaders), and the same pins render as a plain list
    | inside <noscript> — a map that is blank without JavaScript is a dead end,
    | and plenty of the people this portal is for browse with it off or on a
    | device that gives up on it.
    */
    Route::get('/map', fn (): View => view('portal.map', [
        'pins' => (new ListPublishedProjectPins)(),
        'filters' => (new ListPortalFilterOptions)(),
    ]))->name('map');

    /*
    | Published reports. No report type carries a publication decision yet —
    | progress reports are approved, which is an internal act, not a public
    | one. Rather than quietly publishing approved returns (which would break
    | the rule this whole module exists to enforce), the screen ships with a
    | designed empty state that says what will appear here and why it has not.
    */
    Route::get('/reports', fn (): View => view('portal.reports'))->name('reports.index');

    /*
    | Feedback. A plain HTML form and a plain POST — deliberately NOT Livewire:
    |  - it works with JavaScript off,
    |  - `throttle:` on the route genuinely applies (a Livewire update POST
    |    does not travel through a portal route's middleware at all), and
    |  - it keeps the portal's only write on a single, named, testable route
    |    instead of behind a shared component endpoint.
    */
    Route::get('/feedback', function (Request $request): View {
        // The `?project=` ULID a project page links with, resolved HERE under
        // the published predicate — so the form can name what it is about
        // without trusting a visitor-supplied id, and without confirming that
        // an unpublished project exists. Anything unknown or withdrawn simply
        // arrives as null and the visitor writes a general comment.
        $requested = $request->query('project');
        $ulid = is_string($requested) ? trim($requested) : '';

        return view('portal.feedback', [
            'project' => $ulid === '' ? null : (new FindPublishedProject)($ulid),
        ]);
    })->name('feedback.create');

    Route::post('/feedback', function (Request $request): RedirectResponse {
        $data = $request->validate([
            'project' => ['nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'min:10', 'max:4000'],
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc', 'max:180'],
            'phone' => ['nullable', 'string', 'max:32'],
            // The honeypot. Deliberately permissive: a filled honeypot must
            // PASS validation so the bot receives the ordinary thank-you page
            // instead of a field error telling it which field gave it away.
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        // The project arrives as its PUBLIC ULID and is resolved here, under
        // the published predicate. No id supplied by a visitor is ever
        // trusted, and feedback cannot be attached to an unpublished project.
        $projectUlid = isset($data['project']) ? (string) $data['project'] : '';
        $projectId = $projectUlid === '' ? null : (new FindPublishedProject)->id($projectUlid);

        try {
            (new SubmitFeedback)(
                [
                    'project_id' => $projectId,
                    'subject' => (string) $data['subject'],
                    'body' => (string) $data['body'],
                    'submitter_name' => isset($data['name']) ? (string) $data['name'] : null,
                    'submitter_email' => isset($data['email']) ? (string) $data['email'] : null,
                    'submitter_phone' => isset($data['phone']) ? (string) $data['phone'] : null,
                ],
                isset($data['website']) ? (string) $data['website'] : null,
                $request->ip(),
                $request->userAgent(),
            );
        } catch (HoneypotTripped) {
            // Silence, deliberately: a bot that learns which of its
            // submissions were dropped is a bot that gets tuned. A human never
            // reaches this branch — the field is hidden from sight and from
            // screen readers alike.
            return redirect()->route('portal.feedback.thanks');
        } catch (FeedbackRuleViolation $violation) {
            // The rate limit and an empty submission are a real person hitting
            // a real wall, and deserve to be told.
            return back()->withInput($request->except(['website']))
                ->withErrors(['body' => $violation->getMessage()]);
        }

        return redirect()->route('portal.feedback.thanks');
    })->middleware('throttle:'.SubmitFeedback::LIMITER)->name('feedback.store');

    Route::get('/feedback/thanks', fn (): View => view('portal.thanks'))->name('feedback.thanks');
});

if (app()->environment('local')) {
    Route::view('/styleguide', 'styleguide')->name('styleguide');
}
