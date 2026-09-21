<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Challenges register + exception reports (plan §8 feature cue 4)
|--------------------------------------------------------------------------
| One sweep: measure every project under delivery against the configured
| deviation tolerances, then walk the issue escalation ladder.
|
| 07:30 is deliberate. The reporting deadline engine flags overdue obligations
| at 07:15, and the reporting-overdue exception is measured from exactly those
| rows — running earlier would read yesterday's state and report a project as
| compliant on the morning it stopped being so.
|
| It does not need the overlap guard to be CORRECT: the exception duplicate
| gate and the issues.escalated_at counter are each checked under a row lock in
| the same transaction as the write they guard, so a double run cannot
| double-raise. The guard stops a slow sweep across forty MDAs from stacking up
| behind itself.
*/
Schedule::command('issues:evaluate-thresholds')
    ->dailyAt('07:30')
    ->withoutOverlapping();
