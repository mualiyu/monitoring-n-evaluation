<?php

/**
 * The CSV export on the reporting desk (progress-reporting.md §6).
 *
 * Every other read on that screen is proven by what the Livewire response
 * renders. The export is not: it is a network-callable method that streams rows
 * straight past the view layer, so a scoping mistake there produces a file
 * nobody looks at on screen and everybody opens in Excel — and this particular
 * file is a ministry's compliance record. The rows it writes therefore get
 * their own proof: tenant boundary, role narrowing, the filters in force, and
 * the authorization gate on the method itself.
 *
 * Both lists are covered, because the desk exports whichever one is on top and
 * a guard on only one of them is a guard on neither.
 *
 * The assertions read the streamed bytes rather than a response body, because
 * that is the artifact the user actually receives.
 */

use App\Enums\Role;
use App\Livewire\Tenant\Reporting\ReportIndex;
use App\Models\ProgressReport;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ReportingPeriod;
use App\Models\ReportObligation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    // The calendar is state-wide: both ministries report against this window.
    $this->period = ReportingPeriod::factory()->monthly()->create();
});

/**
 * Run the export and return the bytes it streams. The callback writes to
 * php://output, so an output buffer is what captures the real file.
 */
function exportedReportCsv(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

/* -------------------------------------------------------------------------- */
/* Tenant boundary — the mandatory isolation proof for the export */
/* -------------------------------------------------------------------------- */

it('writes only the bound workspace’s obligations into the CSV', function () {
    $mine = Project::factory()->ongoing()->create([
        'reference' => 'WRK-0001',
        'title' => 'Township Road Rehabilitation',
    ]);

    ReportObligation::factory()->forProject($mine)->forPeriod($this->period)->create();

    app(CurrentTenant::class)->runAs($this->health, function () {
        $theirs = Project::factory()->ongoing()->create([
            'reference' => 'HLT-0001',
            'title' => 'Model Primary Health Centre',
        ]);

        ReportObligation::factory()->forProject($theirs)->forPeriod($this->period)->create();
    });

    actingOnTenant($this->works);

    $csv = exportedReportCsv(
        Livewire::actingAs($this->admin)->test(ReportIndex::class)->instance()->export()
    );

    expect($csv)->toContain('WRK-0001')
        ->and($csv)->toContain('Township Road Rehabilitation')
        // The rows that must never be in this file, by reference AND by title —
        // a leak that only drops the title is still a leak.
        ->and($csv)->not->toContain('HLT-0001')
        ->and($csv)->not->toContain('Model Primary Health Centre');
});

it('writes only the bound workspace’s filed returns into the CSV', function () {
    $mine = Project::factory()->ongoing()->create([
        'reference' => 'WRK-0002',
        'title' => 'Rural Water Scheme',
    ]);

    ProgressReport::factory()->forProject($mine)->forPeriod($this->period)->submitted()->create();

    app(CurrentTenant::class)->runAs($this->health, function () {
        $theirs = Project::factory()->ongoing()->create([
            'reference' => 'HLT-0002',
            'title' => 'Maternity Wing Expansion',
        ]);

        ProgressReport::factory()->forProject($theirs)->forPeriod($this->period)->submitted()->create();
    });

    actingOnTenant($this->works);

    $csv = exportedReportCsv(
        Livewire::actingAs($this->admin)->test(ReportIndex::class)->set('view', 'reports')->instance()->export()
    );

    expect($csv)->toContain('WRK-0002')
        ->and($csv)->toContain('Rural Water Scheme')
        ->and($csv)->not->toContain('HLT-0002')
        ->and($csv)->not->toContain('Maternity Wing Expansion');
});

it('exports nothing at all from a workspace with no reporting of its own', function () {
    app(CurrentTenant::class)->runAs($this->health, function () {
        $theirs = Project::factory()->ongoing()->create(['reference' => 'HLT-0003']);

        ReportObligation::factory()->count(3)->forProject($theirs)->forPeriod($this->period)->create();
    });

    actingOnTenant($this->works);

    $csv = exportedReportCsv(
        Livewire::actingAs($this->admin)->test(ReportIndex::class)->instance()->export()
    );

    // Header row + the UTF-8 BOM, and not one data line: an empty desk must
    // produce an empty file, never "everyone else's".
    expect(array_values(array_filter(explode("\n", trim($csv)))))->toHaveCount(1)
        ->and($csv)->toContain('Deadline')
        ->and($csv)->not->toContain('HLT-0003');
});

/* -------------------------------------------------------------------------- */
/* Role narrowing — the export runs the same visibleTo() query as the list */
/* -------------------------------------------------------------------------- */

it('narrows both lists to a consultant’s own assignments', function () {
    $assigned = Project::factory()->ongoing()->create([
        'reference' => 'ASG-0001',
        'title' => 'Assigned Road Project',
    ]);

    $unassigned = Project::factory()->ongoing()->create([
        'reference' => 'UNA-0001',
        'title' => 'Unassigned Bridge Project',
    ]);

    foreach ([$assigned, $unassigned] as $project) {
        ReportObligation::factory()->forProject($project)->forPeriod($this->period)->create();
        ProgressReport::factory()->forProject($project)->forPeriod($this->period)->submitted()->create();
    }

    $consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    ProjectAssignment::factory()->consultant()->create([
        'project_id' => $assigned->id,
        'user_id' => $consultant->id,
        'assigned_by_id' => $this->admin->id,
    ]);

    $consultant = $consultant->fresh();

    // Same workspace, same screen, same filter set — only the assignment
    // narrowing differs, which is exactly the rule under test.
    $obligations = exportedReportCsv(
        Livewire::actingAs($consultant)->test(ReportIndex::class)->instance()->export()
    );

    expect($obligations)->toContain('ASG-0001')
        ->and($obligations)->not->toContain('UNA-0001');

    $returns = exportedReportCsv(
        Livewire::actingAs($consultant)->test(ReportIndex::class)->set('view', 'reports')->instance()->export()
    );

    expect($returns)->toContain('ASG-0001')
        ->and($returns)->not->toContain('UNA-0001');
});

/* -------------------------------------------------------------------------- */
/* The file is the screen */
/* -------------------------------------------------------------------------- */

it('exports exactly the rows the active filter leaves on screen', function () {
    $solar = Project::factory()->ongoing()->create(['reference' => 'SOL-0001', 'title' => 'Solar Street Lighting']);
    $water = Project::factory()->ongoing()->create(['reference' => 'WAT-0001', 'title' => 'Rural Water Scheme']);

    ReportObligation::factory()->forProject($solar)->forPeriod($this->period)->create();
    ReportObligation::factory()->forProject($water)->forPeriod($this->period)->create();

    $component = Livewire::actingAs($this->admin)
        ->test(ReportIndex::class)
        ->set('search', 'Solar');

    // "The figures on screen, nothing else" — a filtered list whose export
    // quietly widens to the whole desk is a disclosure, not a convenience.
    $csv = exportedReportCsv($component->instance()->export());

    expect($csv)->toContain('SOL-0001')
        ->and($csv)->not->toContain('WAT-0001');
});

it('exports the list that is on top, not always the same one', function () {
    $project = Project::factory()->ongoing()->create(['reference' => 'SWI-0001']);

    ReportObligation::factory()->forProject($project)->forPeriod($this->period)->create();
    ProgressReport::factory()->forProject($project)->forPeriod($this->period)->submitted()->create();

    $obligations = exportedReportCsv(
        Livewire::actingAs($this->admin)->test(ReportIndex::class)->instance()->export()
    );

    $returns = exportedReportCsv(
        Livewire::actingAs($this->admin)->test(ReportIndex::class)->set('view', 'reports')->instance()->export()
    );

    // Distinguished by their headings: the obligations file answers "what do we
    // owe", the returns file answers "what did we file".
    expect($obligations)->toContain('Waiver reason')
        ->and($obligations)->not->toContain('Progress claimed')
        ->and($returns)->toContain('Progress claimed')
        ->and($returns)->not->toContain('Waiver reason');
});

it('writes the deadline as the date the state was given, not as UTC read it', function () {
    $project = Project::factory()->ongoing()->create(['reference' => 'TZ-0001']);

    ReportObligation::factory()->forProject($project)->forPeriod($this->period)->create();

    $csv = exportedReportCsv(
        Livewire::actingAs($this->admin)->test(ReportIndex::class)->instance()->export()
    );

    $due = $this->period->due_at->timezone(config('platform.instance.timezone'))->toDateString();

    expect($csv)->toContain($due);
});

/* -------------------------------------------------------------------------- */
/* The gate on the method itself */
/* -------------------------------------------------------------------------- */

it('refuses the export to a member holding no reporting permissions', function () {
    $project = Project::factory()->ongoing()->create(['reference' => 'SEC-0001']);
    ReportObligation::factory()->forProject($project)->forPeriod($this->period)->create();

    $stranger = memberOf(User::factory()->create(), $this->works, Role::Consultant);
    setPermissionsTeamId($this->works->id);
    $stranger->roles()->detach();
    $stranger->forgetCachedPermissions();

    Livewire::actingAs($stranger)
        ->test(ReportIndex::class)
        ->assertForbidden();
});

it('re-authorizes inside export(), not only at mount', function () {
    $project = Project::factory()->ongoing()->create(['reference' => 'SEC-0002']);
    ReportObligation::factory()->forProject($project)->forPeriod($this->period)->create();

    $officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    $component = Livewire::actingAs($officer)->test(ReportIndex::class)->assertOk();

    // export() is network-callable: a client can invoke it directly, long after
    // mount() passed and after the permission behind it was taken away. The
    // guard has to be on the method, which is what this proves.
    setPermissionsTeamId($this->works->id);
    $officer->roles()->detach();
    $officer->forgetCachedPermissions();
    // The authenticated instance is the one the component will ask, and it is
    // still holding the roles it loaded at mount — spatie caches them on the
    // model, so revoking in the database alone would prove nothing here.
    $officer->unsetRelation('roles')->unsetRelation('permissions');

    $component->call('export')->assertForbidden();
});
