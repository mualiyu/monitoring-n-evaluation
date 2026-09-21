<?php

use App\Livewire\Tenant\Indicators\FrameworkBuilder;
use App\Livewire\Tenant\Indicators\IndicatorDetail;
use App\Livewire\Tenant\Indicators\IndicatorIndex;
use Illuminate\Support\Facades\Route;

/*
| Results framework & indicators (plan §4, manual digest §2).
|
| This file is required inside routes/tenant.php's authenticated group, so
| `auth`, `active`, `tenant.member` and `2fa.require` already apply — it
| declares no middleware and no group of its own.
|
| Literal before wildcard: `/indicators` is registered before
| `/indicators/{indicator}` so a literal segment is never bound as a ULID.
| `{indicator}` resolves by ULID through the TenantScope, so another MDA's
| public id is a 404 rather than a leak.
*/
Route::get('/indicators', IndicatorIndex::class)->name('indicators.index');
Route::get('/indicators/{indicator}', IndicatorDetail::class)->name('indicators.show');

// The logframe builder for one project's framework: impact → outcome →
// output, with indicators instantiated from the state library onto the
// statements they measure.
Route::get('/projects/{project}/framework', FrameworkBuilder::class)->name('projects.framework');
