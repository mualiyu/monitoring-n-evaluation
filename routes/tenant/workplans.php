<?php

use App\Livewire\Tenant\Workplans\WorkplanBuilder;
use App\Livewire\Tenant\Workplans\WorkplanCreate;
use App\Livewire\Tenant\Workplans\WorkplanGantt;
use App\Livewire\Tenant\Workplans\WorkplanIndex;
use Illuminate\Support\Facades\Route;

/*
| Annual work plans + Gantt (PROJECT_PLAN §8, Phase 2).
|
| This file is required inside routes/tenant.php's authenticated group, so
| `auth`, `active`, `tenant.member` and `2fa.require` already apply — it
| declares no middleware and opens no group of its own.
|
| `/workplans/create` is declared BEFORE `/workplans/{workplan}`: the router
| walks same-path routes in registration order, so a literal segment after a
| sibling wildcard would bind as a ULID and 404. `{workplan}` resolves by ULID
| through the TenantScope, so another MDA's public id is a 404, not a leak.
*/
Route::get('/workplans', WorkplanIndex::class)->name('workplans.index');
Route::get('/workplans/create', WorkplanCreate::class)->name('workplans.create');
Route::get('/workplans/{workplan}', WorkplanBuilder::class)->name('workplans.show');
Route::get('/workplans/{workplan}/gantt', WorkplanGantt::class)->name('workplans.gantt');
