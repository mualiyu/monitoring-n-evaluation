<?php

use App\Livewire\Tenant\Feedback\FeedbackQueue;
use Illuminate\Support\Facades\Route;

/*
| The MDA's stakeholder-feedback moderation queue: publish, refuse, mark as
| spam, and answer — for feedback about THIS workspace's projects only.
|
| The narrowing is the `project` relation, not a tenant clause: `feedback` is a
| global table (a citizen does not know which ministry owns the clinic), and
| its tenancy is carried by the project it points at. See
| App\Actions\Feedback\ListFeedbackForTenant.
|
| This file is required inside routes/tenant.php's authenticated group, so
| `auth`, `active`, `tenant.member` and `2fa.require` already apply. Do not add
| a group here.
*/

Route::get('/feedback', FeedbackQueue::class)->name('feedback.index');
