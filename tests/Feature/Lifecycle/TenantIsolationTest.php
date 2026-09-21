<?php

/**
 * The mandatory isolation proof for the two tenant-owned models this module
 * adds: records in two workspaces, and subdomain A never seeing B's — in the
 * list, on the detail screens, and in the export.
 *
 * A tenancy leak in a government system is a security incident, so the
 * assertions here are deliberately paranoid: the same ULID is tried from the
 * wrong subdomain, and the export is read as bytes rather than trusted to
 * match the screen.
 */

use App\Enums\Role;
use App\Livewire\Oversight\Lifecycle\CertificateRegister as OversightRegister;
use App\Livewire\Tenant\Lifecycle\CertificateRegister;
use App\Models\Certificate;
use App\Models\CommencementNotice;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('documents');
    Notification::fake();
    seedPermissions();

    $this->works = Tenant::factory()->create(['name' => 'Ministry of Works', 'slug' => 'works']);
    $this->health = Tenant::factory()->create(['name' => 'Ministry of Health', 'slug' => 'health']);

    $this->current = app(CurrentTenant::class);

    $this->current->runAs($this->works, function (): void {
        $this->worksProject = Project::factory()->completed()->create(['title' => 'Township Road Rehabilitation']);
        $this->worksContract = Contract::factory()->forProject($this->worksProject)->create();
        $this->worksNotice = CommencementNotice::factory()->forContract($this->worksContract)->issued()->create();
        $this->worksCertificate = Certificate::factory()
            ->forProject($this->worksProject)
            ->create(['reference' => 'WORKS/PC/2026/0001']);
    });

    $this->current->runAs($this->health, function (): void {
        $this->healthProject = Project::factory()->completed()->create(['title' => 'Cottage Hospital Upgrade']);
        $this->healthContract = Contract::factory()->forProject($this->healthProject)->create();
        $this->healthNotice = CommencementNotice::factory()->forContract($this->healthContract)->issued()->create();
        $this->healthCertificate = Certificate::factory()
            ->forProject($this->healthProject)
            ->create(['reference' => 'HEALTH/PC/2026/0001']);
    });

    $this->current->forget();
});

it('scopes the certificate register to the workspace it is opened on', function () {
    actingOnTenant($this->works);
    $worksOfficer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    Livewire::actingAs($worksOfficer)
        ->test(CertificateRegister::class)
        ->assertOk()
        ->assertSee('WORKS/PC/2026/0001')
        ->assertDontSee('HEALTH/PC/2026/0001')
        ->assertDontSee('Cottage Hospital Upgrade');
});

it('keeps a foreign certificate out of the export, not merely off the screen', function () {
    actingOnTenant($this->works);
    $worksOfficer = memberOf(User::factory()->create(), $this->works, Role::MeOfficer);

    // ->instance()->export(), not ->call('export'): calling it through the
    // Livewire testable returns the TESTABLE, whose body is the component's
    // JSON payload — the bytes the user receives never get asserted, and the
    // test passes on a file it never looked at. Same pattern as
    // tests/Feature/Projects/ProjectExportTest.php.
    $response = Livewire::actingAs($worksOfficer)
        ->test(CertificateRegister::class)
        ->instance()
        ->export();

    ob_start();
    $response->sendContent();
    $csv = (string) ob_get_clean();

    expect($csv)->toContain('WORKS/PC/2026/0001')
        ->and($csv)->not->toContain('HEALTH/PC/2026/0001')
        ->and($csv)->not->toContain('Cottage Hospital Upgrade');
});

it('404s a foreign project ULID on the commencement screen', function () {
    actingOnTenant($this->works);
    $worksAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    // Through the route, because the route-model binder is what applies the
    // scope. A leak here would be a 200 rendering another MDA's contracts.
    $this->actingAs($worksAdmin)
        ->get(tenantUrl($this->works, '/projects/'.$this->healthProject->ulid.'/commencement'))
        ->assertNotFound();
});

it('404s a foreign project ULID on the certification screen', function () {
    actingOnTenant($this->works);
    $worksAdmin = memberOf(User::factory()->create(), $this->works, Role::MdaAdmin);

    $this->actingAs($worksAdmin)
        ->get(tenantUrl($this->works, '/projects/'.$this->healthProject->ulid.'/certify'))
        ->assertNotFound();
});

it('refuses to load a foreign notice or certificate even by primary key', function () {
    actingOnTenant($this->works);

    // The global scope, asserted directly: everything above depends on it.
    expect(CommencementNotice::query()->find($this->healthNotice->id))->toBeNull()
        ->and(Certificate::query()->find($this->healthCertificate->id))->toBeNull()
        ->and(CommencementNotice::query()->count())->toBe(1)
        ->and(Certificate::query()->count())->toBe(1);
});

it('shows both workspaces to state oversight, which is what the surface is for', function () {
    $stateAdmin = userWithRole(Role::StateAdmin);
    $stateAdmin->forceFill(['two_factor_required_at' => now()])->save();

    $this->current->forget();

    Livewire::actingAs($stateAdmin)
        ->test(OversightRegister::class)
        ->assertOk()
        ->assertSee('WORKS/PC/2026/0001')
        ->assertSee('HEALTH/PC/2026/0001')
        ->assertSee('Ministry of Works')
        ->assertSee('Ministry of Health');
});

it('narrows the state register to one entity when asked', function () {
    $stateAdmin = userWithRole(Role::StateAdmin);
    $stateAdmin->forceFill(['two_factor_required_at' => now()])->save();

    $this->current->forget();

    Livewire::actingAs($stateAdmin)
        ->test(OversightRegister::class)
        ->set('tenantId', (string) $this->health->id)
        ->assertSee('HEALTH/PC/2026/0001')
        ->assertDontSee('WORKS/PC/2026/0001');
});
