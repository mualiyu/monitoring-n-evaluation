<?php

namespace App\Actions\Workplans;

use App\Enums\WorkplanStatus;
use App\Models\User;
use App\Models\Workplan;
use Illuminate\Support\Facades\DB;

/**
 * The accounting officer's signature on the year's programme.
 *
 * Two moves, not one, and both through the chokepoint: a plan whose period has
 * ALREADY BEGUN goes straight on to `active`, because a plan signed in March
 * for a year that started in January is the plan being worked to the moment it
 * is signed, and leaving it in `approved` would mean every screen reading
 * "not yet running" about the live programme. A plan approved ahead of its
 * period stays `approved` and is activated when the period opens — the detail
 * screen offers the control from period_start.
 *
 * Wrapped in one transaction so the pair is atomic: an approval that failed
 * halfway to activation would leave a ledger with a signature and no start.
 * Both notifications still fire, and that is deliberate — the owner hears
 * "approved" and then "active", which is exactly what happened.
 */
class ApproveWorkplan
{
    public function __construct(private readonly TransitionWorkplanStatus $transition) {}

    public function __invoke(Workplan $workplan, User $actor): Workplan
    {
        return DB::transaction(function () use ($workplan, $actor): Workplan {
            ($this->transition)($workplan, WorkplanStatus::Approved, $actor);

            if ($workplan->hasStarted()) {
                ($this->transition)($workplan, WorkplanStatus::Active, $actor);
            }

            return $workplan;
        });
    }
}
