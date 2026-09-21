<?php

use App\Livewire\Oversight\Workplans\WorkplanBoard;
use Illuminate\Support\Facades\Route;

/*
| State-wide work-plan board (PROJECT_PLAN §8, Phase 2). Read-only: the
| secretariat sees every MDA's plan, its approval state and its tracking, and
| edits none of them.
|
| Required inside routes/oversight.php's authenticated group, so `auth`,
| `active`, the oversight `role:` gate and `2fa.require` already apply. The
| cross-tenant read happens inside
| App\Actions\Oversight\ListWorkplansAcrossTenants, which re-checks
| `workplans.view` in the GLOBAL permission team before any bypass.
*/
Route::get('/workplans', WorkplanBoard::class)->name('workplans.index');
