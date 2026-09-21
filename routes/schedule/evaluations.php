<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Evaluation module schedule
|--------------------------------------------------------------------------
| The follow-up register's nightly conscience. An overdue recommendation is
| announced once, to its addressee and to the MDA admin.
|
| 07:30 is deliberate: the reporting sweeps run at 07:00 and 07:15, and nobody
| should receive three different "you are late" mails in the same minute from
| three different windows. This is also the least urgent of the three — a
| recommendation's deadline is measured in months, not in days.
|
| The overlap guard is a courtesy, not the correctness story: every send is
| gated by a stamp advanced under a row lock in the same transaction as the
| dispatch, so a double run cannot double-send.
*/

Schedule::command('evaluations:flag-overdue-recommendations')
    ->dailyAt('07:30')
    ->withoutOverlapping();
