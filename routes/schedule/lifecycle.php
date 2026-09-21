<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Commencement notices + completion certification (plan §8)
|--------------------------------------------------------------------------
| One sweep: awards that have gone past `monitoring.commencement_notice_days`
| with no notice served on the contractor.
|
| It does not need the overlap guard to be CORRECT — the notice row's own
| existence and its `overdue_notified_at` stamp, both written under a row lock
| in the same transaction as the dispatch, make a double run silent. The guard
| is there to stop a slow sweep across 40 MDAs from stacking up behind itself.
|
| 07:30, a quarter of an hour after the reporting overdue sweep: an MDA admin
| should not receive "your return is overdue" and "your notice is overdue" in
| the same minute from two different windows.
*/

Schedule::command('lifecycle:flag-overdue-notices')
    ->dailyAt('07:30')
    ->withoutOverlapping();
