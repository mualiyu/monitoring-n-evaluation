<?php

use App\Livewire\Oversight\Gis\GisDashboard;
use Illuminate\Support\Facades\Route;

/*
| State GIS dashboard — every entity's project sites on one map.
|
| Required inside routes/oversight.php's authenticated group, so `auth`,
| `active`, the oversight role gate and `2fa.require` already apply. The
| component re-checks `oversight.portfolio.view` in mount(), and its Action on
| every update request.
*/

Route::get('/gis', GisDashboard::class)->name('gis');
