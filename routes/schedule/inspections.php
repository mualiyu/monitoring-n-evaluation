<?php

use Illuminate\Support\Facades\Schedule;

/*
| Inspections engine (plan §4). Both sweeps iterate tenants through
| CurrentTenant::runAs(), so tenancy travels into every queued notification.
|
| Neither needs the overlap guard to be CORRECT — proposals are keyed by a
| deterministic schedule_key behind a unique index, and the overdue notice is
| gated by a stamp written under a row lock in the same transaction as the
| dispatch. The guard stops a slow sweep across 40 MDAs stacking up behind
| itself.
|
| Proposals run before the overdue sweep and both before the working day, so a
| monitor opens their phone to a diary that is already correct.
*/
Schedule::command('inspections:propose-routine')
    ->dailyAt('05:30')
    ->withoutOverlapping();

Schedule::command('inspections:flag-overdue')
    ->dailyAt('06:30')
    ->withoutOverlapping();
