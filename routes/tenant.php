<?php

use App\Actions\Iam\ListUserWorkspaces;
use App\Http\Controllers\Iam\InvitationController;
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
});

Route::middleware(['guest', 'throttle:invitations'])->group(function () {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'store'])->name('invitations.accept');
});
