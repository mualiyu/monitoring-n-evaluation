<?php

use App\Livewire\Oversight\Feedback\FeedbackQueue;
use Illuminate\Support\Facades\Route;

/*
| The state-wide stakeholder-feedback queue.
|
| Wider than any MDA's in one way that matters: feedback submitted without a
| project attached appears ONLY here. Someone who wrote about "the road by the
| market" without picking a project from a list still gets read — by the
| secretariat, which is the office that can work out whose road it is.
|
| This file is required inside routes/oversight.php's authenticated group, so
| `auth`, `active`, `role:…` and `2fa.require` already apply. Do not add a
| group here. The component re-checks `feedback.view` in the GLOBAL permission
| team, and every write re-checks through FeedbackPolicy.
*/

Route::get('/feedback', FeedbackQueue::class)->name('feedback.index');
