<?php

use App\Http\Controllers\Iam\InvitationController;
use App\Livewire\Oversight\Projects\ContractorRegistry;
use App\Livewire\Oversight\Projects\Portfolio;
use App\Livewire\Oversight\Projects\ProjectView;
use App\Livewire\Oversight\Projects\TenantPortfolio;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Oversight surface — oversight.<platform.domain>
|--------------------------------------------------------------------------
| State-level M&E secretariat, executives, data-quality reviewers and the
| platform super admin. Requires an oversight role (checked in the GLOBAL
| permission team — no tenant is ever bound here). Cross-MDA reads happen
| here, and only here, via explicit Model::withoutTenancy() calls inside
| oversight Actions/components.
*/

Route::middleware([
    'auth',
    'active',
    'role:super-admin|state-admin|executive-viewer|data-quality-reviewer',
    '2fa.require',
])->group(function () {
    Route::get('/', function () {
        return view('oversight.dashboard');
    })->name('dashboard');

    Route::view('/two-factor/setup', 'auth.two-factor-setup')->name('two-factor.setup');

    /*
    | Portfolio (design §5). Cross-MDA reads happen inside the Oversight
    | Actions, which re-check `oversight.portfolio.view` in the GLOBAL
    | permission team before any tenancy bypass.
    */
    Route::get('/portfolio', Portfolio::class)->name('portfolio.index');
    Route::get('/portfolio/{tenant:slug}', TenantPortfolio::class)->name('portfolio.tenant');
    // `{ulid}`, not `{project}`: a parameter named after the model would be
    // picked up by Livewire's implicit route binding and resolved before this
    // surface's middleware runs — with no tenant bound, that is a 500 for
    // everyone rather than a 403 for the wrong people. See ProjectView::mount().
    Route::get('/projects/{ulid}', ProjectView::class)->name('projects.show');
    Route::get('/contractors', ContractorRegistry::class)->name('contractors.index');
});

Route::middleware(['guest', 'throttle:invitations'])->group(function () {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'store'])->name('invitations.accept');
});
