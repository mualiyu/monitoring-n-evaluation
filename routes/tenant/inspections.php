<?php

use App\Livewire\Tenant\Inspections\InspectionConduct;
use App\Livewire\Tenant\Inspections\InspectionDetail;
use App\Livewire\Tenant\Inspections\InspectionIndex;
use App\Livewire\Tenant\Inspections\InspectionSchedule;
use Illuminate\Support\Facades\Route;

/*
| Site inspections (plan §4 "Monitoring lifecycle"). Four full-page Livewire
| components, each of which authorizes in mount() AND on every mutating method
| — route middleware does not gate a Livewire update POST by itself.
|
| This file is required inside routes/tenant.php's authenticated group, so
| `auth`, `active`, `tenant.member` and `2fa.require` already apply. No group
| and no middleware are declared here.
|
| Two ordering rules are load-bearing:
|  - `/inspections/create` is declared BEFORE `/inspections/{inspection}`; the
|    router walks same-path routes in registration order, so a literal segment
|    placed after its sibling wildcard binds as a ULID and 404s.
|  - `{inspection}` resolves by ULID through the TenantScope, so another MDA's
|    public id is a 404 rather than a leak — and an auto-increment id in a URL
|    would be an enumeration handle over another MDA's field-work volumes.
*/

Route::get('/inspections', InspectionIndex::class)->name('inspections.index');

Route::get('/inspections/create', InspectionSchedule::class)->name('inspections.create');

Route::get('/inspections/{inspection}', InspectionDetail::class)->name('inspections.show');

/*
| The field form. Its own route rather than a mode of the detail screen: an
| inspector on 3G loads this and nothing else, and a monitor who bookmarks the
| visit they are walking into gets the form, not a dashboard.
*/
Route::get('/inspections/{inspection}/conduct', InspectionConduct::class)->name('inspections.conduct');
