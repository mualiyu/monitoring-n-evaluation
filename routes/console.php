<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Deadline engine (progress-reporting.md §3)
|--------------------------------------------------------------------------
| Five sweeps, all ->withoutOverlapping(), all iterating tenants through
| CurrentTenant::runAs() so tenancy travels into every queued notification.
|
| None of them needs the overlap guard to be CORRECT — every send is gated by
| a monotonic counter advanced under a row lock in the same transaction as the
| dispatch, so a double run cannot double-send. The guard is there to stop a
| slow sweep across 40 MDAs from stacking up behind itself.
|
| Order within the morning is deliberate: obligations are generated overnight,
| reminders go out before the working day, and the overdue sweep runs a quarter
| of an hour later so an MDA never receives "due tomorrow" and "overdue" in the
| same minute from two different windows.
*/

Schedule::command('reporting:generate-periods')
    ->yearlyOn(12, 1, '00:10')
    ->withoutOverlapping();

Schedule::command('reporting:close-periods')
    ->dailyAt('01:00')
    ->withoutOverlapping();

Schedule::command('reporting:generate-obligations')
    ->dailyAt('00:30')
    ->withoutOverlapping();

Schedule::command('reporting:send-reminders')
    ->dailyAt('07:00')
    ->withoutOverlapping();

Schedule::command('reporting:flag-overdue')
    ->dailyAt('07:15')
    ->withoutOverlapping();
