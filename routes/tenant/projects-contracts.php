<?php

use App\Livewire\Tenant\Projects\ContractCreate;
use App\Livewire\Tenant\Projects\ContractDetail;
use Illuminate\Support\Facades\Route;

/*
| Contracts on a project (projects-module.md §5). Two screens, both full-page
| Livewire components that authorize in mount() AND on every mutating method —
| route middleware does not gate a Livewire update POST by itself.
|
| This file is required inside routes/tenant.php's authenticated group, so
| `auth`, `active`, `tenant.member` and `2fa.require` already apply. Do not add
| a group here.
|
| Two ordering rules are load-bearing:
|  - `/contracts/create` is declared BEFORE `/contracts/{contract}`; the router
|    walks same-path routes in registration order, so a literal segment placed
|    after its sibling wildcard binds as a ULID and 404s.
|  - both are bound by ULID, never by the auto-increment id: an integer in a
|    URL is an enumeration handle over another MDA's contract volumes.
|
| scopeBindings() on the detail route makes `{contract}` resolve THROUGH
| `$project->contracts()`, so a contract ULID belonging to a different project
| in the same workspace 404s instead of rendering under the wrong project's
| header. The TenantScope already stops the cross-MDA case at the binder.
*/

Route::get('/projects/{project}/contracts/create', ContractCreate::class)
    ->name('projects.contracts.create');

Route::get('/projects/{project}/contracts/{contract}', ContractDetail::class)
    ->scopeBindings()
    ->name('projects.contracts.show');
