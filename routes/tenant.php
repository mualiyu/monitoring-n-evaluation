<?php

use App\Actions\Iam\ListUserWorkspaces;
use App\Http\Controllers\Iam\InvitationController;
use App\Livewire\Tenant\Projects\ContractorIndex;
use App\Livewire\Tenant\Projects\ProjectCreate;
use App\Livewire\Tenant\Projects\ProjectDetail;
use App\Livewire\Tenant\Projects\ProjectIndex;
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
});

Route::middleware(['guest', 'throttle:invitations'])->group(function () {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'store'])->name('invitations.accept');
});
