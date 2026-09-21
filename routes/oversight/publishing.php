<?php

use App\Livewire\Oversight\Publishing\PublishingQueue;
use Illuminate\Support\Facades\Route;

/*
| The state-wide publishing queue (rules/security.md §"Public portal is
| read-only"): which projects the public can see, which are waiting on a
| decision, and a preview of the exact payload a publication would expose.
|
| This file is required inside routes/oversight.php's authenticated group, so
| `auth`, `active`, `role:…` and `2fa.require` already apply. Do not add a
| group here.
|
| The screen itself re-checks `projects.publish` in the GLOBAL permission team
| — the oversight role middleware lets ExecutiveViewer and
| DataQualityReviewer through, and neither of them decides what the state
| publishes.
*/

Route::get('/publishing', PublishingQueue::class)->name('publishing.index');
