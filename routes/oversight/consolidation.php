<?php

use App\Http\Controllers\Exports\DownloadReportExportController;
use App\Livewire\Oversight\Consolidation\ConsolidationEditor;
use App\Livewire\Oversight\Consolidation\ConsolidationWorkspace;
use App\Livewire\Oversight\Exports\ExportRegister;
use App\Livewire\Oversight\Exports\ReportBuilder;
use Illuminate\Support\Facades\Route;

/*
| Consolidation workspace, the ad-hoc report builder and the generated-artifact
| register (plan §8 — "Consolidation workspace + state APR export", "Report
| builder with PDF/Excel exports").
|
| This file is required inside routes/oversight.php's authenticated group, so
| `auth`, `active`, `role:…` and `2fa.require` already apply. Do not add a
| group here.
|
| Ordering: `/consolidation` is declared BEFORE `/consolidation/{…}` and
| `/reports/builder` before any sibling wildcard, because the router walks
| same-path routes in registration order and a literal segment placed after
| its wildcard binds as a ULID and 404s. Module files are required first in
| routes/oversight.php precisely so these literals beat the wildcards declared
| after them.
|
| `{consolidatedReport}` IS bound as a model here, unlike `/projects/{ulid}` on
| this surface. The difference is deliberate: a ConsolidatedReport is GLOBAL
| and carries no tenant scope, so implicit binding — which runs before this
| surface's middleware — cannot hit the fail-closed TenantScope with no tenant
| bound. A tenant-owned model in the same position would 500 for everyone
| instead of 403ing the wrong people, which is why ProjectView takes a raw
| {ulid}.
*/

Route::get('/consolidation', ConsolidationWorkspace::class)
    ->name('consolidation.index');

Route::get('/consolidation/{consolidatedReport}', ConsolidationEditor::class)
    ->name('consolidation.show');

/*
| The ad-hoc builder. NOT `/reports` — that name belongs to the cross-MDA
| reports desk, which is a different module's screen.
*/
Route::get('/reports/builder', ReportBuilder::class)
    ->name('reports.builder');

Route::get('/exports', ExportRegister::class)
    ->name('exports.index');

/*
| The ONLY way a generated artifact leaves the platform: signed (a pasted link
| expires), authenticated by the group above, and policy-checked in the
| controller — the same three gates the document vault applies. Bound by ULID,
| never by the auto-increment id, which would be an enumeration handle over how
| much data the state has been exporting.
*/
Route::get('/exports/{reportExport}/download', DownloadReportExportController::class)
    ->middleware('signed')
    ->name('exports.download');
