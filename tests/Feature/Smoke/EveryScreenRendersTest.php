<?php

/**
 * The link sweep: every GET route on every surface, rendered.
 *
 * This project has shipped three separate classes of dead screen past a green
 * suite — a route that did not exist behind a link that did, a wildcard that
 * swallowed a literal segment, and a binding key that 404'd every row. Each was
 * found by a human clicking, which is the wrong detector.
 *
 * So this walks the ROUTE TABLE rather than a hand-written list: a new screen
 * is covered the moment it is registered, and a screen that stops rendering
 * fails here even if nobody wrote a test for it. It asserts the weakest useful
 * thing — no 5xx, and no 404 on a route we supplied every parameter for —
 * because the per-module suites assert the content. What this catches is the
 * screen that was never reachable at all.
 */

use App\Enums\Role;
use App\Models\Certificate;
use App\Models\CommencementNotice;
use App\Models\ConsolidatedReport;
use App\Models\Contract;
use App\Models\Evaluation;
use App\Models\ExceptionReport;
use App\Models\Indicator;
use App\Models\Issue;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\Recommendation;
use App\Models\SiteInspection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workplan;
use App\Tenancy\CurrentTenant;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Routes this sweep deliberately does not walk, each with the reason it cannot
 * be walked rather than "it was failing". Growing this list is a decision.
 */
const SMOKE_SKIP = [
    // Signed, single-use artifact URLs: a bare GET is CORRECTLY a 403, and the
    // signed path has its own tests (tests/Feature/Documents/VaultSecurityTest).
    'tenant.documents.download',
    'oversight.documents.download',
    'oversight.exports.download',
    'portal.projects.photo',
    // Guest-only, and keyed on a one-time invitation token.
    'tenant.invitations.show',
    'oversight.invitations.show',
    // Local-only design reference, not part of the product.
    'portal.styleguide',
];

beforeEach(function () {
    seedPermissions();

    $this->tenant = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);

    app(CurrentTenant::class)->runAs($this->tenant, function () {
        // One record per bindable model, so every parameterised route has
        // something real to resolve. If a new module adds a bound route, the
        // sweep reports its parameter as unfillable rather than quietly
        // skipping the screen — that report is the prompt to add a line here.
        $this->project = Project::factory()->ongoing()->create();
        Contract::factory()->for($this->project)->create();
        Indicator::factory()->for($this->project)->create();
        ProgressReport::factory()->forProject($this->project)->create();
        SiteInspection::factory()->forProject($this->project)->create();
        Issue::factory()->forProject($this->project)->create();
        ExceptionReport::factory()->forProject($this->project)->create();
        Workplan::factory()->create();
        Evaluation::factory()->forProject($this->project)->create();
        Certificate::factory()->forProject($this->project)->create();
    });

    // Global (no tenant_id), so created outside any workspace context.
    ConsolidatedReport::factory()->create();
});

/**
 * Fill a route's parameters from the fixtures above. Returns null when a
 * parameter has no fixture — the test then reports it rather than skipping
 * silently, because an unfillable parameter usually means a new module landed
 * without demo data.
 *
 * @return array<string, string>|null
 */
function smokeParameters(RoutingRoute $route, Tenant $tenant, Project $project): ?array
{
    $values = [];

    foreach ($route->parameterNames() as $name) {
        $value = match ($name) {
            'tenant' => $tenant->slug,
            'project', 'ulid' => $project->ulid,
            'contract' => Contract::query()->value('ulid'),
            'report' => ProgressReport::query()->value('ulid'),
            'indicator' => Indicator::query()->value('ulid'),
            'inspection' => SiteInspection::query()->value('ulid'),
            'issue' => Issue::query()->value('ulid'),
            'exceptionReport' => ExceptionReport::query()->value('ulid'),
            'workplan' => Workplan::query()->value('ulid'),
            'evaluation' => Evaluation::query()->value('ulid'),
            'certificate' => Certificate::query()->value('ulid'),
            'commencementNotice' => CommencementNotice::query()->value('ulid'),
            'consolidatedReport' => ConsolidatedReport::query()->value('ulid'),
            'recommendation' => Recommendation::query()->value('ulid'),
            default => null,
        };

        if ($value === null) {
            return null;
        }

        $values[$name] = (string) $value;
    }

    return $values;
}

/**
 * Every registered GET route on one surface, as [name => path].
 *
 * @return list<RoutingRoute>
 */
function smokeRoutes(string $prefix): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RoutingRoute $route): bool => in_array('GET', $route->methods(), true)
            && is_string($route->getName())
            && str_starts_with($route->getName(), $prefix)
            && ! in_array($route->getName(), SMOKE_SKIP, true),
    ));
}

it('renders every workspace screen for an MDA administrator', function () {
    $admin = memberOf(User::factory()->create(), $this->tenant, Role::MdaAdmin);
    $admin->forceFill(['two_factor_exempted_at' => now()])->save();

    $routes = smokeRoutes('tenant.');
    expect($routes)->not->toBeEmpty();

    $broken = [];
    $unfillable = [];

    foreach ($routes as $route) {
        $parameters = smokeParameters($route, $this->tenant, $this->project);

        if ($parameters === null) {
            $unfillable[] = $route->getName();

            continue;
        }

        $url = route($route->getName(), $parameters);
        $status = $this->actingAs($admin)->get($url)->baseResponse->getStatusCode();

        // 403 is a legitimate answer on some screens for some roles; 5xx and
        // 404 never are, once every parameter has been supplied.
        if ($status >= 500 || $status === 404) {
            $broken[] = $route->getName().' → '.$status;
        }
    }

    expect($broken)->toBe([])
        // A route with no fixture is a gap in THIS test, not a pass. Naming it
        // keeps the sweep honest as modules land.
        ->and($unfillable)->toBe([]);
});

it('renders every oversight screen for a state M&E administrator', function () {
    $stateAdmin = userWithRole(Role::StateAdmin);
    $stateAdmin->forceFill(['two_factor_exempted_at' => now()])->save();

    $routes = smokeRoutes('oversight.');
    expect($routes)->not->toBeEmpty();

    $broken = [];
    $unfillable = [];

    foreach ($routes as $route) {
        $parameters = smokeParameters($route, $this->tenant, $this->project);

        if ($parameters === null) {
            $unfillable[] = $route->getName();

            continue;
        }

        $status = $this->actingAs($stateAdmin)
            ->get(route($route->getName(), $parameters))
            ->baseResponse->getStatusCode();

        if ($status >= 500 || $status === 404) {
            $broken[] = $route->getName().' → '.$status;
        }
    }

    expect($broken)->toBe([])->and($unfillable)->toBe([]);
});

it('renders every public portal screen to an anonymous visitor', function () {
    $routes = smokeRoutes('portal.');
    expect($routes)->not->toBeEmpty();

    $broken = [];

    foreach ($routes as $route) {
        $parameters = smokeParameters($route, $this->tenant, $this->project);

        if ($parameters === null) {
            continue; // a published-project detail page needs a published row
        }

        $status = $this->get(route($route->getName(), $parameters))
            ->baseResponse->getStatusCode();

        // The portal answers 404 for anything unpublished, and that is the
        // point — only a 5xx is a defect here.
        if ($status >= 500) {
            $broken[] = $route->getName().' → '.$status;
        }
    }

    expect($broken)->toBe([]);
});
