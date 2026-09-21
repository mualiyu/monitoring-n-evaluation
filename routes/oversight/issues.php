<?php

use App\Livewire\Oversight\Issues\ExceptionBoard;
use Illuminate\Support\Facades\Route;

/*
| The cross-MDA deviation board (plan §8 feature cue 4). Read-only: the state
| watches deviations, the MDA answers for them — an oversight officer resolving
| another entity's exception report would be signing off work they did not do.
|
| Required inside routes/oversight.php's authenticated group, so the oversight
| role middleware already applies. The cross-MDA read itself happens inside
| App\Actions\Oversight\ListExceptionsAcrossTenants, which re-checks
| `exceptions.view` in the GLOBAL permission team before any tenancy bypass —
| the role middleware admits ExecutiveViewer and DataQualityReviewer too, and
| the permission is what actually decides.
|
| No detail route: a board row links into the owning MDA's workspace, where
| the record lives and where someone can act on it.
*/
Route::get('/exceptions', ExceptionBoard::class)->name('exceptions.index');
