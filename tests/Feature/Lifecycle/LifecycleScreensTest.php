<?php

/**
 * The lifecycle screens.
 *
 * Every screen is asserted BOTH ways: an authorized 200 over REAL HTTP on the
 * real subdomain that actually renders the record, and the denial. A suite of
 * denials proves only that nothing works — and Livewire::test() sets
 * properties directly without ever rendering a page or crossing HTTP, so it
 * cannot catch a route that does not dispatch or a view that throws.
 *
 * Domain rules themselves are proved in the Action tests; repeating them here
 * would be theatre.
 */

use App\Enums\CertificateType;
use App\Enums\CommencementNoticeStatus;
use App\Enums\ProjectRole;
use App\Enums\Role;
use App\Livewire\Oversight\Lifecycle\CertificateRegister as OversightRegister;
use App\Livewire\Tenant\Lifecycle\CertificateRegister;
use App\Livewire\Tenant\Lifecycle\CertifyProject;
use App\Livewire\Tenant\Lifecycle\ProjectCommencement;
use App\Livewire\Tenant\Lifecycle\ProjectLifecyclePanel;
use App\Models\Certificate;
use App\Models\CommencementNotice;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    Storage::fake('documents');
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    actingOnTenant($this->works);

    $this->admin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);
    $this->officer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);
    $this->consultant = memberOf(User::factory()->create(), $this->works, Role::Consultant);

    $this->contractor = Contractor::factory()->create(['name' => 'Ilesa Civil Works Limited']);

    $this->project = Project::factory()->ongoing()->create([
        'title' => 'Township Road Rehabilitation',
        'reference' => 'WKS/2026/001',
    ]);

    $this->contract = Contract::factory()->forProject($this->project)->create([
        'contractor_id' => $this->contractor->id,
        'contract_number' => 'CTR-44821',
        'award_date' => CarbonImmutable::now()->subDays(2)->toDateString(),
        'commencement_date' => null,
    ]);
});

/* -------------------------------------------------------------------------- */
/* The routes reach the screens */
/* -------------------------------------------------------------------------- */

it('renders the commencement screen for a project over HTTP', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/projects/'.$this->project->ulid.'/commencement'))
        ->assertOk()
        ->assertSeeLivewire(ProjectCommencement::class)
        ->assertSee('Ilesa Civil Works Limited')
        ->assertSee('CTR-44821')
        ->assertSee('Not yet issued');
});

it('renders the certification screen for a project over HTTP', function () {
    $completed = Project::factory()->completed()->create(['title' => 'Model Secondary School']);

    $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$completed->ulid.'/certify'))
        ->assertOk()
        ->assertSeeLivewire(CertifyProject::class)
        ->assertSee('Before certifying')
        ->assertSee('Works recorded as completed');
});

it('renders the workspace certificate register over HTTP', function () {
    $completed = Project::factory()->completed()->create(['title' => 'Model Secondary School']);
    Certificate::factory()->forProject($completed)->create(['reference' => 'WORKS/PC/2026/0009']);

    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/certificates'))
        ->assertOk()
        ->assertSeeLivewire(CertificateRegister::class)
        ->assertSee('Model Secondary School')
        ->assertSee('WORKS/PC/2026/0009');
});

it('renders the state certificate register over HTTP', function () {
    $completed = Project::factory()->completed()->create(['title' => 'Model Secondary School']);
    Certificate::factory()->forProject($completed)->create(['reference' => 'WORKS/PC/2026/0009']);

    $stateAdmin = userWithRole(Role::StateAdmin);
    $stateAdmin->forceFill(['two_factor_required_at' => now()])->save(); // inside the grace window

    app(CurrentTenant::class)->forget();

    $this->actingAs($stateAdmin)
        ->get(oversightUrl('/certificates'))
        ->assertOk()
        ->assertSeeLivewire(OversightRegister::class)
        ->assertSee('Ministry of Works')
        ->assertSee('WORKS/PC/2026/0009');
});

it('shows an empty state rather than a blank screen', function () {
    $this->actingAs($this->officer)
        ->get(tenantUrl($this->works, '/certificates'))
        ->assertOk()
        ->assertSee('Nothing certified yet');
});

/* -------------------------------------------------------------------------- */
/* Rendered HTML → validation */
/* -------------------------------------------------------------------------- */

it('submits the certificate-type value the rendered form actually offers', function () {
    // The defect class this catches: <x-ui.form.select> renders an id-keyed
    // map's KEYS as option values and a plain list's VALUES — so a select can
    // silently submit a label where the validator expects an enum value, and
    // a Livewire::test() that ->set()s the property never notices. The value
    // fed back below is the one a browser would post.
    $completed = Project::factory()->completed()->create();

    $html = $this->actingAs($this->admin)
        ->get(tenantUrl($this->works, '/projects/'.$completed->ulid.'/certify'))
        ->assertOk()
        ->getContent();

    expect($html)->toBeString();

    preg_match('/<select[^>]*\bname="type"[^>]*>(.*?)<\/select>/s', (string) $html, $select);

    expect($select)->not->toBeEmpty('the certification form must render a type select');

    preg_match_all('/<option[^>]*value="([^"]*)"/', $select[1], $options);

    $values = array_values(array_filter($options[1], fn (string $value): bool => $value !== ''));

    expect($values)->not->toBeEmpty()
        ->and($values)->toContain(CertificateType::PracticalCompletion->value);

    Livewire::actingAs($this->admin)
        ->test(CertifyProject::class, ['project' => $completed])
        ->set('type', $values[0])
        ->call('certify')
        ->assertHasNoErrors();

    expect(Certificate::query()->where('project_id', $completed->id)->value('type'))
        ->toBe(CertificateType::from($values[0]));
});

/* -------------------------------------------------------------------------- */
/* Serving a notice from the screen */
/* -------------------------------------------------------------------------- */

it('serves a notice from the commencement screen', function () {
    Livewire::actingAs($this->admin)
        ->test(ProjectCommencement::class, ['project' => $this->project])
        ->assertOk()
        ->call('startIssue', $this->contract->ulid)
        ->set('commencementDate', CarbonImmutable::now()->toDateString())
        ->set('instructions', 'Site handover on commencement.')
        ->call('issue')
        ->assertHasNoErrors();

    $notice = CommencementNotice::query()->where('contract_id', $this->contract->id)->firstOrFail();

    expect($notice->status)->toBe(CommencementNoticeStatus::Issued)
        ->and($notice->instructions)->toBe('Site handover on commencement.');
});

it('refuses a commencement date that precedes the award, in the form', function () {
    Livewire::actingAs($this->admin)
        ->test(ProjectCommencement::class, ['project' => $this->project])
        ->call('startIssue', $this->contract->ulid)
        ->set('commencementDate', CarbonImmutable::now()->subMonth()->toDateString())
        ->call('issue')
        ->assertHasErrors(['commencementDate']);

    expect(CommencementNotice::query()->count())->toBe(0);
});

it('records receipt from the screen for the assigned contractor', function () {
    $notice = CommencementNotice::factory()->forContract($this->contract)->issued()->create();

    ProjectAssignment::factory()->create([
        'project_id' => $this->project->id,
        'user_id' => $this->consultant->id,
        'role' => ProjectRole::Consultant,
        'assigned_by_id' => $this->admin->id,
    ]);

    Livewire::actingAs($this->consultant->fresh())
        ->test(ProjectCommencement::class, ['project' => $this->project])
        ->call('startAcknowledge', $notice->ulid)
        ->set('acknowledgementNote', 'Signed copy returned by the site engineer.')
        ->call('confirmAcknowledge')
        ->assertHasNoErrors();

    expect(CommencementNotice::query()->whereKey($notice->id)->value('status'))
        ->toBe(CommencementNoticeStatus::Acknowledged);
});

/* -------------------------------------------------------------------------- */
/* Certifying from the screen */
/* -------------------------------------------------------------------------- */

it('blocks the certify button until the preconditions are met, and surfaces why', function () {
    // An in-progress project: works not complete, progress not 100%.
    $component = Livewire::actingAs($this->admin)
        ->test(CertifyProject::class, ['project' => $this->project])
        ->assertOk()
        ->assertSee('Certification is unavailable');

    expect($component->instance()->canCertify())->toBeFalse();
});

it('surfaces an Action refusal verbatim rather than a stack trace', function () {
    config()->set('platform.monitoring.require_final_inspection_for_certification', true);

    $completed = Project::factory()->completed()->create();

    Livewire::actingAs($this->admin)
        ->test(CertifyProject::class, ['project' => $completed])
        ->call('certify')
        ->assertHasNoErrors()
        ->assertSee('requires a completed final inspection');

    expect(Certificate::query()->count())->toBe(0);
});

it('withdraws a certificate from the screen, with a reason', function () {
    $completed = Project::factory()->certified()->create();
    $certificate = Certificate::factory()->forProject($completed)->create();

    Livewire::actingAs($this->admin)
        ->test(CertifyProject::class, ['project' => $completed])
        ->call('startRevoke', $certificate->ulid)
        ->set('revocationReason', 'Issued against the wrong contract lot.')
        ->call('confirmRevoke')
        ->assertHasNoErrors();

    expect(Certificate::query()->whereKey($certificate->id)->value('revoked_at'))->not->toBeNull();
});

it('requires a reason before it will withdraw anything', function () {
    $completed = Project::factory()->certified()->create();
    $certificate = Certificate::factory()->forProject($completed)->create();

    Livewire::actingAs($this->admin)
        ->test(CertifyProject::class, ['project' => $completed])
        ->call('startRevoke', $certificate->ulid)
        ->set('revocationReason', 'too short')
        ->call('confirmRevoke')
        ->assertHasErrors(['revocationReason']);

    expect(Certificate::query()->whereKey($certificate->id)->value('revoked_at'))->toBeNull();
});

/* -------------------------------------------------------------------------- */
/* Register + export */
/* -------------------------------------------------------------------------- */

it('filters the register and exports exactly what is on screen', function () {
    $roadProject = Project::factory()->completed()->create(['title' => 'Township Road Rehabilitation II']);
    $schoolProject = Project::factory()->completed()->create(['title' => 'Model Secondary School']);

    Certificate::factory()->forProject($roadProject)->practicalCompletion()->create();
    Certificate::factory()->forProject($schoolProject)->finalCompletion()->create();

    $component = Livewire::actingAs($this->officer)
        ->test(CertificateRegister::class)
        ->assertOk()
        ->assertSee('Township Road Rehabilitation II')
        ->assertSee('Model Secondary School')
        ->set('type', CertificateType::FinalCompletion->value)
        ->assertSee('Model Secondary School')
        ->assertDontSee('Township Road Rehabilitation II');

    // ->instance()->export(), not ->call('export'): calling it through the
    // Livewire testable hands back the TESTABLE, whose body is the component's
    // JSON payload with the file base64'd inside it — so an assertion on the
    // bytes the user receives silently reads the rendered HTML instead, and
    // passes without ever looking at the CSV.
    $csv = extractCsv($component->instance()->export());

    expect($csv)->toContain('Model Secondary School')
        ->and($csv)->not->toContain('Township Road Rehabilitation II');
});

it('hides withdrawn certificates from the register until they are asked for', function () {
    $project = Project::factory()->certified()->create(['title' => 'Withdrawn Works']);
    Certificate::factory()->forProject($project)->revoked()->create();

    Livewire::actingAs($this->officer)
        ->test(CertificateRegister::class)
        ->assertDontSee('Withdrawn Works')
        ->set('status', 'revoked')
        ->assertSee('Withdrawn Works');
});

/* -------------------------------------------------------------------------- */
/* The embeddable panel */
/* -------------------------------------------------------------------------- */

it('renders the lifecycle panel for embedding in the project record', function () {
    CommencementNotice::factory()->forContract($this->contract)->acknowledged()->create();

    Livewire::actingAs($this->officer)
        ->test(ProjectLifecyclePanel::class, ['project' => $this->project])
        ->assertOk()
        ->assertSee('Monitoring lifecycle')
        ->assertSee('1 of 1 served')
        ->assertSee('Not certified');
});

it('shows the panel a red flag when an award has gone past its window', function () {
    CommencementNotice::factory()->forContract($this->contract)->overdue()->create();

    Livewire::actingAs($this->officer)
        ->test(ProjectLifecyclePanel::class, ['project' => $this->project])
        ->assertOk()
        ->assertSee('past its statutory notice window');
});

/**
 * The streamed body of a download response, as a string. Typed, so a Livewire
 * testable handed in by mistake fails loudly here rather than quietly passing
 * an assertion against a JSON payload.
 */
function extractCsv(StreamedResponse $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}
