<?php

use App\Livewire\Tenant\Publishing\PublishingQueue;
use Illuminate\Support\Facades\Route;

/*
| The MDA's own publishing queue. `projects.publish` is seeded to MdaAdmin as
| well as to the state roles, and without a screen on this surface that
| permission would be unreachable: an MDA would hold the authority to publish
| its own work and no way to exercise it.
|
| Same component shape as the oversight twin, one difference that matters: no
| tenancy bypass anywhere. TenantScope confines the queue to this workspace,
| so an MDA admin decides about their own projects and sees no one else's.
|
| This file is required inside routes/tenant.php's authenticated group, so
| `auth`, `active`, `tenant.member` and `2fa.require` already apply. Do not add
| a group here.
*/

Route::get('/publishing', PublishingQueue::class)->name('publishing.index');
