<?php

use App\Livewire\Tenant\Gis\GisDashboard;
use Illuminate\Support\Facades\Route;

/*
| Workspace GIS dashboard — this entity's project sites on a map.
|
| Required inside routes/tenant.php's authenticated group, so `auth`,
| `active`, `tenant.member` and `2fa.require` already apply. The component
| authorizes viewAny on Project in mount(), and its Action on every update.
*/

Route::get('/gis', GisDashboard::class)->name('gis');
