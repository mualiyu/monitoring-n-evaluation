<?php

namespace App\Actions\Workplans;

use App\Enums\WorkplanStatus;
use App\Models\User;
use App\Models\Workplan;

/**
 * Sends a submitted plan back to its owner with a reason.
 *
 * The reason is not optional and the chokepoint enforces it: a plan returned
 * with no explanation is a plan that comes back unchanged.
 */
class RejectWorkplan
{
    public function __construct(private readonly TransitionWorkplanStatus $transition) {}

    public function __invoke(Workplan $workplan, User $actor, string $reason): Workplan
    {
        return ($this->transition)($workplan, WorkplanStatus::Rejected, $actor, $reason);
    }
}
