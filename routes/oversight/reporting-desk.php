<?php

use App\Livewire\Oversight\Reporting\ReportDesk;
use App\Livewire\Oversight\Reporting\TenantCompliance;
use Illuminate\Support\Facades\Route;

/*
| Cross-MDA reporting (progress-reporting.md §6, "Oversight").
|
| Required inside routes/oversight.php's authenticated group, so `auth`,
| `active`, the oversight `role:` gate and `2fa.require` already apply. Every
| cross-tenant read below happens inside app/Actions/Oversight/, which
| re-checks the oversight permission in the GLOBAL permission team before any
| tenancy bypass — and each component authorizes in mount() as well.
|
| `{tenant:slug}`, never a tenant id. Tenant is not a tenant-scoped model, so
| binding it here is safe; binding by SLUG is what makes the link shareable
| and stops an auto-increment id from advertising how many entities the state
| has provisioned. (The portfolio drill-down shipped with a tenant_id handed to
| a slug binding once already — every row 404'd.)
*/

// Every return the state has been sent, read-only, across all entities.
Route::get('/reports', ReportDesk::class)->name('reports.index');

// One entity's compliance record — the league table's drill-down.
Route::get('/compliance/{tenant:slug}', TenantCompliance::class)->name('compliance.tenant');
