<?php

use App\Livewire\Tenant\Reporting\ReportInbox;
use App\Livewire\Tenant\Reporting\ReportingCalendar;
use Illuminate\Support\Facades\Route;

/*
| Progress reporting — the two desk screens (progress-reporting.md §6).
|
| THIS FILE EXISTS FOR ITS POSITION. routes/tenant.php requires every module
| file BEFORE its own declarations, and `/reports/inbox` is a literal segment
| that shares a path prefix with the `/reports/{report}` wildcard declared
| there. The router walks same-path routes in registration order, so a literal
| registered after its sibling wildcard binds as a model key and 404s — the
| screen would simply not exist, quietly. Literal-before-wildcard is the only
| safe order, and a module folder is how it stays true without two modules
| editing one file.
|
| Required inside routes/tenant.php's authenticated group, so `auth`,
| `active`, `tenant.member` and `2fa.require` already apply. Authority is
| re-checked in each component's mount() as well — route middleware does not
| gate a Livewire update POST by itself.
*/

// The review/approval queue: what is waiting on THIS user, right now.
Route::get('/reports/inbox', ReportInbox::class)->name('reports.inbox');

// The statutory calendar, and what this workspace owes against each window.
Route::get('/calendar', ReportingCalendar::class)->name('reports.calendar');
