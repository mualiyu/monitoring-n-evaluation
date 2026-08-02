<?php

use App\Actions\Iam\ListUserWorkspaces;
use App\Http\Controllers\Iam\InvitationController;
use App\Livewire\Tenant\Iam\TeamIndex;
use App\Livewire\Tenant\Projects\ContractorIndex;
use App\Livewire\Tenant\Projects\ProjectCreate;
use App\Livewire\Tenant\Projects\ProjectDetail;
use App\Livewire\Tenant\Projects\ProjectIndex;
use App\Livewire\Tenant\Reporting\ReportForm;
use App\Livewire\Tenant\Reporting\ReportIndex;
use App\Livewire\Tenant\Reporting\ReportReview;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant surface — {tenant}.<platform.domain>
|--------------------------------------------------------------------------
| The MDA workspace. ResolveTenant has already bound the tenant; from here:
| auth (who are you) → active (may you use the platform) → tenant.member
| (may you enter THIS workspace) → 2fa.require (role-mandated enrolment).
*/

Route::middleware(['auth', 'active', 'tenant.member', '2fa.require'])->group(function () {
    Route::get('/', function () {
        return view('tenant.dashboard');
    })->name('dashboard');

    Route::view('/two-factor/setup', 'auth.two-factor-setup')->name('two-factor.setup');

    Route::get('/workspaces', function () {
        return view('tenant.workspaces', [
            'workspaces' => (new ListUserWorkspaces)(auth()->user()),
        ]);
    })->name('workspaces');

    /*
    | Projects (design §5). Full-page Livewire components; each one authorizes
    | in mount() as well as on every mutating method, so a direct POST to the
    | Livewire update endpoint is checked even if the route group ever changes.
    */
    Route::get('/projects', ProjectIndex::class)->name('projects.index');
    Route::get('/projects/create', ProjectCreate::class)->name('projects.create');
    Route::get('/projects/{project}', ProjectDetail::class)->name('projects.show');

    Route::get('/contractors', ContractorIndex::class)->name('contractors.index');

    /*
    | Progress reporting (progress-reporting.md §6). `/create` is declared
    | before `/{report}` — otherwise "create" binds as a report ULID and the
    | wizard 404s. `{report}` resolves by ULID through the TenantScope, so
    | another MDA's public id is a 404 rather than a leak.
    */
    Route::get('/reports', ReportIndex::class)->name('reports.index');
    Route::get('/reports/create', ReportForm::class)->name('reports.create');
    Route::get('/reports/{report}', ReportReview::class)->name('reports.show');
    Route::get('/reports/{report}/edit', ReportForm::class)->name('reports.edit');

    /*
    | Team management (auth-surfaces.md §2–3). One screen: roster, pending
    | invitations, invite form, revoke-access flow. Membership and invitation
    | reads go through the sanctioned Iam actions; the component gates on
    | users.view / users.invite / users.manage in the CURRENT tenant's team.
    */
    Route::get('/team', TeamIndex::class)->name('team.index');
});

Route::middleware(['guest', 'throttle:invitations'])->group(function () {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'store'])->name('invitations.accept');
});
