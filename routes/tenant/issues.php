<?php

use App\Livewire\Tenant\Issues\ExceptionDetail;
use App\Livewire\Tenant\Issues\ExceptionIndex;
use App\Livewire\Tenant\Issues\IssueCreate;
use App\Livewire\Tenant\Issues\IssueDetail;
use App\Livewire\Tenant\Issues\IssueIndex;
use Illuminate\Support\Facades\Route;

/*
| Challenges register + exception reports (plan §4, §8).
|
| This file is required inside routes/tenant.php's authenticated group, so
| `auth`, `active`, `tenant.member` and `2fa.require` already apply — it
| declares no middleware and no group of its own.
|
| `/issues/create` is declared BEFORE `/issues/{issue}`: the router walks
| same-path routes in registration order, so "create" registered after the
| sibling wildcard would bind as an issue ULID and 404. `{issue}` and
| `{exceptionReport}` resolve by ULID through the TenantScope, so another
| MDA's public id is a 404 rather than a leak.
*/
Route::get('/issues', IssueIndex::class)->name('issues.index');
Route::get('/issues/create', IssueCreate::class)->name('issues.create');
Route::get('/issues/{issue}', IssueDetail::class)->name('issues.show');

Route::get('/exceptions', ExceptionIndex::class)->name('exceptions.index');
Route::get('/exceptions/{exceptionReport}', ExceptionDetail::class)->name('exceptions.show');
