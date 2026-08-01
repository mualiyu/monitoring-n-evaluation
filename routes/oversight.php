<?php

use App\Http\Controllers\Iam\InvitationController;
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
});

Route::middleware(['guest', 'throttle:invitations'])->group(function () {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'store'])->name('invitations.accept');
});
