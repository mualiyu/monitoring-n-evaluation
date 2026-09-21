<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Work plans — overdue activity sweep
|--------------------------------------------------------------------------
| 07:45, after the reporting engine's own morning sweeps (07:00 reminders,
| 07:15 overdue). Deliberately last: a director who is already receiving "this
| MDA has not filed" should not get "and this activity has slipped" in the same
| minute from a different sweep — the two land as separate items in the inbox,
| which is how they read as separate problems.
|
| The overlap guard is a convenience, not the correctness argument: the notice
| is gated on `workplan_activities.overdue_notified_at`, null-checked and
| stamped under the same row lock as the dispatch, so a double run cannot
| double-send.
*/
Schedule::command('workplans:flag-overdue')
    ->dailyAt('07:45')
    ->withoutOverlapping();
