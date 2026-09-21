<?php

use App\Livewire\Oversight\Indicators\IndicatorLibrary;
use App\Livewire\Oversight\Indicators\ValidationQueue;
use Illuminate\Support\Facades\Route;

/*
| State indicator library + the Data Quality Reviewer's validation queue.
|
| This file is required inside routes/oversight.php's authenticated group, so
| `auth`, `active`, the oversight `role:` gate and `2fa.require` already apply
| — it declares no middleware and no group of its own. The role middleware
| admits every oversight role; the components gate precisely on
| `indicators.library.manage` and `oversight.validation.review` in the GLOBAL
| permission team, which is where an oversight role's authority lives.
*/
Route::get('/indicator-library', IndicatorLibrary::class)->name('indicator-library.index');
Route::get('/validation', ValidationQueue::class)->name('validation.index');
