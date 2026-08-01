<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Portal surface — apex domain
|--------------------------------------------------------------------------
| Public transparency portal. Read-only, published data only, rate-limited.
*/

Route::get('/', function () {
    return view('portal.home');
})->name('home');

if (app()->environment('local')) {
    Route::view('/styleguide', 'styleguide')->name('styleguide');
}
