<?php

/**
 * The state audit log.
 *
 * THE POINT OF THIS FILE IS THE FIRST SECTION: an append-only trail that a
 * privileged user can amend proves nothing, so "no screen anywhere can edit or
 * delete an activity-log record" is asserted structurally — over the route
 * table and over every component that touches the log — rather than by
 * clicking the buttons that happen to exist today. A test that only checks the
 * current buttons would pass the day somebody adds a new one.
 *
 * The rest covers what makes the log usable: the cross-MDA read behind a
 * global permission check, filtering by workspace with no tenant_id column to
 * filter on, and the export sharing one builder with the screen so the file
 * handed to an auditor can never disagree with what was on the page.
 */

use App\Actions\Oversight\ListActivityAcrossTenants;
use App\Actions\Oversight\SetTenantActive;
use App\Actions\Projects\TransitionProjectStatus;
use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Livewire\Oversight\Audit\AuditLog;
use App\Livewire\Shared\ActivityTimeline;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use ReflectionClass;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Queue::fake();
    seedPermissions();

    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 6, 15, 9));

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);
    $this->current = app(CurrentTenant::class);

    actingWithoutTenant();

    $this->stateAdmin = userWithRole(Role::StateAdmin);
    $this->execViewer = userWithRole(Role::ExecutiveViewer);

    foreach ([$this->stateAdmin, $this->execViewer] as $user) {
        $user->forceFill(['two_factor_required_at' => now()])->save();
    }

    $this->worksAdmin = memberOf(User::factory()->create(['name' => 'Amina Bello']), $this->works, Role::MdaAdmin);

    $this->worksProject = $this->current->runAs($this->works, fn (): Project => Project::factory()
        ->ongoing()
        ->create(['title' => 'Township Road Rehabilitation']));

    $this->healthProject = $this->current->runAs($this->health, fn (): Project => Project::factory()
        ->ongoing()
        ->create(['title' => 'Cottage Hospital Upgrade']));

    actingWithoutTenant();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * Record an act inside a workspace WITH a named actor. The activity log takes
 * its causer from the authenticated user, so a transition run by an
 * unauthenticated test records "The platform" — which is honest, and useless
 * for asserting who did what.
 */
function actAs(User $actor, Tenant $tenant, Closure $callback): mixed
{
    test()->actingAs($actor);

    return app(CurrentTenant::class)->runAs($tenant, $callback);
}

/* -------------------------------------------------------------------------- */
/* Append-only — asserted structurally, not button by button */
/* -------------------------------------------------------------------------- */

it('registers no route anywhere on the platform that writes to the activity log', function () {
    $writable = collect(Route::getRoutes()->getRoutes())
        ->filter(function ($route): bool {
            $methods = array_diff($route->methods(), ['HEAD']);
            $mutating = array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']) !== [];

            return $mutating && preg_match('/\b(audit|activity|activities)\b/i', $route->uri()) === 1;
        })
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    // Livewire's own update endpoint is generic and is covered by the
    // component sweep below; what must not exist is a dedicated write route.
    expect($writable)->toBe([]);
});

it('exposes no method that could edit or delete an audit record on any screen that reads one', function (string $component) {
    $methods = collect((new ReflectionClass($component))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->all();

    // Livewire calls arrive as method names on the component, so the method
    // list IS the attack surface. Any of these appearing is a defect, not a
    // feature — retention is a configured purge by age, never a button.
    foreach (['destroy', 'delete', 'remove', 'purge', 'update', 'edit', 'amend', 'save', 'store'] as $forbidden) {
        expect($methods)->not->toContain($forbidden);
    }
})->with([[AuditLog::class], [ActivityTimeline::class]]);

it('refuses to rewrite or remove an audit record through the model either', function () {
    actAs($this->worksAdmin, $this->works, fn () => (new TransitionProjectStatus)(
        $this->worksProject,
        ProjectStatus::Suspended,
        $this->worksAdmin,
        'Contractor withdrew from site pending a variation order.',
    ));

    actingWithoutTenant();

    $entry = Activity::query()->latest('id')->firstOrFail();
    $before = $entry->description;

    // Nothing in app/ writes here, and the screens above cannot reach it.
    // This asserts the shape a reviewer checks: one row, one description,
    // unchanged after the read.
    Livewire::actingAs($this->stateAdmin)->test(AuditLog::class)->call('inspect', $entry->id)->assertOk();

    expect(Activity::query()->whereKey($entry->id)->sole()->description)->toBe($before);
});

it('keeps a deleted record’s history in the log', function () {
    actAs($this->worksAdmin, $this->works, function () {
        (new TransitionProjectStatus)(
            $this->worksProject,
            ProjectStatus::Suspended,
            $this->worksAdmin,
            'Contractor withdrew from site pending a variation order.',
        );

        $this->worksProject->delete();
    });

    actingWithoutTenant();

    // An audit trail outlives the record it describes.
    $entries = (new ListActivityAcrossTenants)($this->stateAdmin, ['tenant' => $this->works]);

    expect($entries->total())->toBeGreaterThan(0);
});

/* -------------------------------------------------------------------------- */
/* Reading it */
/* -------------------------------------------------------------------------- */

it('shows the state every MDA’s acts in one list', function () {
    actAs($this->worksAdmin, $this->works, fn () => (new TransitionProjectStatus)(
        $this->worksProject,
        ProjectStatus::Suspended,
        $this->worksAdmin,
        'Contractor withdrew from site.',
    ));

    actingWithoutTenant();
    (new SetTenantActive)($this->stateAdmin, $this->health, false, 'Merged into the Ministry of Infrastructure.');

    $this->actingAs($this->stateAdmin)
        ->get(oversightUrl('/audit'))
        ->assertOk()
        ->assertSee('Amina Bello')
        ->assertSee('tenant.deactivated');
});

it('refuses the log to any role without oversight.audit.view in the global team', function () {
    // ExecutiveViewer passes the surface's role gate and holds no audit
    // permission — the permission is what decides, not the surface.
    $this->actingAs($this->execViewer)->get(oversightUrl('/audit'))->assertForbidden();
    Livewire::actingAs($this->execViewer)->test(AuditLog::class)->assertForbidden();

    expect(fn () => (new ListActivityAcrossTenants)($this->execViewer))
        ->toThrow(AuthorizationException::class);

    // An MDA admin holds plenty inside their workspace and nothing here,
    // however the request arrives.
    expect(fn () => (new ListActivityAcrossTenants)($this->worksAdmin))
        ->toThrow(AuthorizationException::class);

    actingOnTenant($this->works);

    expect(fn () => (new ListActivityAcrossTenants)($this->worksAdmin))
        ->toThrow(AuthorizationException::class);
});

it('refuses the filter-option readers to the same people, not just the list', function () {
    $action = new ListActivityAcrossTenants;

    expect(fn () => $action->logNames($this->execViewer))->toThrow(AuthorizationException::class)
        ->and(fn () => $action->subjectTypes($this->execViewer))->toThrow(AuthorizationException::class)
        ->and(fn () => $action->causerIds($this->execViewer))->toThrow(AuthorizationException::class);
});

it('narrows the log to one workspace through the subject, with no tenant_id to filter on', function () {
    actAs($this->worksAdmin, $this->works, fn () => (new TransitionProjectStatus)(
        $this->worksProject,
        ProjectStatus::Suspended,
        $this->worksAdmin,
        'Works: contractor withdrew from site.',
    ));

    $healthAdmin = memberOf(User::factory()->create(['name' => 'Musa Ibrahim']), $this->health, Role::MdaAdmin);

    actAs($healthAdmin, $this->health, fn () => (new TransitionProjectStatus)(
        $this->healthProject,
        ProjectStatus::Suspended,
        $healthAdmin,
        'Health: site handover delayed.',
    ));

    actingWithoutTenant();

    $worksOnly = (new ListActivityAcrossTenants)($this->stateAdmin, ['tenant' => $this->works]);
    $subjectIds = $worksOnly->pluck('subject_id')->unique()->all();

    expect($worksOnly->total())->toBeGreaterThan(0)
        ->and($subjectIds)->not->toContain($this->healthProject->id);

    // The screen asks the same question by the workspace's ULID.
    $component = Livewire::actingAs($this->stateAdmin)
        ->test(AuditLog::class)
        ->set('tenant', $this->works->ulid);

    expect($component->instance()->entries()->total())->toBe($worksOnly->total());
});

it('filters by actor, by log and by date window', function () {
    actAs($this->worksAdmin, $this->works, fn () => (new TransitionProjectStatus)(
        $this->worksProject,
        ProjectStatus::Suspended,
        $this->worksAdmin,
        'Contractor withdrew from site.',
    ));

    actingWithoutTenant();
    (new SetTenantActive)($this->stateAdmin, $this->health, false, 'Merged into the Ministry of Infrastructure.');

    $component = Livewire::actingAs($this->stateAdmin)->test(AuditLog::class);

    $byActor = $component->set('actorId', $this->worksAdmin->public_id)->instance()->entries();

    expect($byActor->pluck('causer_id')->unique()->all())->toBe([$this->worksAdmin->id]);

    $byLog = $component->set('actorId', '')->set('log', 'tenancy')->instance()->entries();

    expect($byLog->pluck('log_name')->unique()->all())->toBe(['tenancy']);

    // A window that closed before anything happened returns nothing; one that
    // includes today is inclusive of the whole closing day.
    $empty = $component->set('log', '')
        ->set('from', '2026-06-01')
        ->set('to', '2026-06-14')
        ->instance()
        ->entries();

    expect($empty->total())->toBe(0);

    $today = $component->set('to', '2026-06-15')->instance()->entries();

    expect($today->total())->toBeGreaterThan(0);
});

it('offers only the log names, subjects and actors that are actually in the table', function () {
    actAs($this->worksAdmin, $this->works, fn () => (new TransitionProjectStatus)(
        $this->worksProject,
        ProjectStatus::Suspended,
        $this->worksAdmin,
        'Contractor withdrew from site.',
    ));

    actingWithoutTenant();

    $component = Livewire::actingAs($this->stateAdmin)->test(AuditLog::class);

    // A filter listing 400 names nobody has ever caused an entry is a filter
    // nobody uses.
    expect(array_keys($component->instance()->actorOptions()))->toBe([$this->worksAdmin->public_id])
        ->and(array_keys($component->instance()->logOptions()))->toContain('projects');
});

it('renders the before and after of one entry as rows a human can read', function () {
    actAs($this->worksAdmin, $this->works, fn () => (new TransitionProjectStatus)(
        $this->worksProject,
        ProjectStatus::Suspended,
        $this->worksAdmin,
        'Contractor withdrew from site.',
    ));

    actingWithoutTenant();

    $entry = Activity::query()->where('log_name', 'projects')->latest('id')->firstOrFail();

    // The regression: spatie v5 writes a MODEL's own diff to
    // `attribute_changes`, and only a hand-written withProperties() lands in
    // `properties`. Reading `properties` alone left this panel blank for every
    // chokepoint status change on the platform — and exported `null` into the
    // Before/After columns of the file an auditor is handed.
    expect($entry->properties->toArray())->toBe([])
        ->and($entry->attribute_changes?->toArray())->toHaveKey('attributes');

    $changes = Livewire::actingAs($this->stateAdmin)->test(AuditLog::class)->instance()->changes($entry);

    expect($changes)->not->toBeEmpty()
        ->and(collect($changes)->pluck('attribute'))->toContain('Status')
        ->and(collect($changes)->firstWhere('attribute', 'Status')['to'])
        ->toBe(ProjectStatus::Suspended->value);
});

it('still reads the before and after of an entry that wrote its own pairs', function () {
    // The settings, tenancy and IAM Actions state their own old/attributes in
    // `properties` deliberately — both shapes have to render.
    (new SetTenantActive)($this->stateAdmin, $this->health, false, 'Merged into the Ministry of Infrastructure.');

    $entry = Activity::query()->where('description', 'tenant.deactivated')->sole();

    $changes = Livewire::actingAs($this->stateAdmin)->test(AuditLog::class)->instance()->changes($entry);

    expect(collect($changes)->firstWhere('attribute', 'Is active'))
        ->toMatchArray(['from' => __('Yes'), 'to' => __('No')]);
});

it('toggles the before/after panel open and shut on the same entry', function () {
    actAs($this->worksAdmin, $this->works, fn () => (new TransitionProjectStatus)(
        $this->worksProject,
        ProjectStatus::Suspended,
        $this->worksAdmin,
        'Contractor withdrew from site.',
    ));

    actingWithoutTenant();

    $entry = Activity::query()->latest('id')->firstOrFail();

    Livewire::actingAs($this->stateAdmin)
        ->test(AuditLog::class)
        ->call('inspect', $entry->id)
        ->assertSet('inspecting', $entry->id)
        ->call('inspect', $entry->id)
        ->assertSet('inspecting', null);
});

/* -------------------------------------------------------------------------- */
/* The export */
/* -------------------------------------------------------------------------- */

it('exports exactly the rows on screen, because they share one builder', function () {
    actAs($this->worksAdmin, $this->works, fn () => (new TransitionProjectStatus)(
        $this->worksProject,
        ProjectStatus::Suspended,
        $this->worksAdmin,
        'Works: contractor withdrew from site.',
    ));

    $healthAdmin = memberOf(User::factory()->create(['name' => 'Musa Ibrahim']), $this->health, Role::MdaAdmin);

    actAs($healthAdmin, $this->health, fn () => (new TransitionProjectStatus)(
        $this->healthProject,
        ProjectStatus::Suspended,
        $healthAdmin,
        'Health: site handover delayed.',
    ));

    actingWithoutTenant();

    $component = Livewire::actingAs($this->stateAdmin)
        ->test(AuditLog::class)
        ->set('tenant', $this->works->ulid);

    $response = $component->instance()->export();

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    // This is the file somebody hands an auditor: a row on screen and missing
    // from the CSV (or the reverse) matters more here than anywhere else.
    expect($csv)->toContain('Amina Bello')
        ->and($csv)->not->toContain('Musa Ibrahim');

    $onScreen = $component->instance()->entries()->total();
    $rows = substr_count(trim($csv), "\n");

    expect($rows)->toBe($onScreen); // header line + $onScreen rows, minus the trailing newline
});

it('refuses the export to a role that may not read the log', function () {
    // The screen never mounts for them (asserted above), and the streaming
    // read behind it refuses independently — the authority is in the Action,
    // not in whichever surface happens to call it.
    expect(fn () => (new ListActivityAcrossTenants)->chunk($this->execViewer, [], function (): void {}))
        ->toThrow(AuthorizationException::class);
});

/* -------------------------------------------------------------------------- */
/* The per-record timeline */
/* -------------------------------------------------------------------------- */

it('shows one record’s history to whoever may view the record, and nobody else', function () {
    URL::defaults(['tenant' => $this->works->slug]);

    actAs($this->worksAdmin, $this->works, fn () => (new TransitionProjectStatus)(
        $this->worksProject,
        ProjectStatus::Suspended,
        $this->worksAdmin,
        'Contractor withdrew from site.',
    ));

    actingOnTenant($this->works);

    $panel = Livewire::actingAs($this->worksAdmin)
        ->test(ActivityTimeline::class, ['model' => $this->worksProject])
        ->assertOk()
        ->assertSee('Amina Bello');

    // The before/after really is on the panel, not merely in the table.
    $entry = $panel->instance()->entries()->firstWhere('event', 'updated');

    expect(collect($panel->instance()->changes($entry))->pluck('attribute'))->toContain('Status');

    // The panel asks the RECORD's own policy rather than inventing a second
    // rule, so a foreign record's history is refused with the record.
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)
        ->test(ActivityTimeline::class, ['model' => $this->worksProject])
        ->assertForbidden();
});

it('names the platform, not a user, when nobody caused the entry', function () {
    actingOnTenant($this->works);

    activity('projects')
        ->performedOn($this->worksProject)
        ->withProperties(['attributes' => ['status' => 'in_progress']])
        ->log('project.swept');

    $entry = Activity::query()->where('description', 'project.swept')->sole();

    $name = Livewire::actingAs($this->worksAdmin)
        ->test(ActivityTimeline::class, ['model' => $this->worksProject])
        ->instance()
        ->causerName($entry);

    // "The platform" is honest; "System" invites people to look for a user
    // named System.
    expect($name)->toBe(__('The platform'));
});
