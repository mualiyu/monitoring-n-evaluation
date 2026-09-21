<?php

namespace App\Actions\Workplans;

use App\Enums\WorkplanStatus;
use App\Models\User;
use App\Models\Workplan;

/**
 * Closes the year. Terminal: a closed plan is history, and history is amended
 * by opening next year's plan, never by reopening last year's.
 *
 * Reachable from `approved` as well as from `active`, because a programme
 * cancelled before its year began still has to be closed off rather than left
 * sitting in the approved list forever.
 */
class CloseWorkplan
{
    public function __construct(private readonly TransitionWorkplanStatus $transition) {}

    public function __invoke(Workplan $workplan, User $actor, ?string $reason = null): Workplan
    {
        return ($this->transition)($workplan, WorkplanStatus::Closed, $actor, $reason);
    }
}
