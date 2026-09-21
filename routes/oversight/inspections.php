<?php

use App\Livewire\Oversight\Inspections\InspectionBoard;
use Illuminate\Support\Facades\Route;

/*
| The state's field-work board (plan §4). READ-ONLY: there is no oversight
| Action in this module that writes an inspection — the state observes MDA
| field work, it does not conduct it through this screen.
|
| This file is required inside routes/oversight.php's authenticated group, so
| `auth`, `active`, the oversight `role:` gate and `2fa.require` already apply.
| No group and no middleware are declared here.
|
| The cross-MDA read happens inside App\Actions\Oversight\ListInspectionsAcrossTenants,
| which re-checks `inspections.view` in the GLOBAL permission team BEFORE any
| tenancy bypass. The role middleware above admits ExecutiveViewer and
| DataQualityReviewer, who both hold that permission; an MDA officer holds it
| only in their own workspace's team and never reaches this surface at all.
*/

Route::get('/inspections', InspectionBoard::class)->name('inspections.index');
