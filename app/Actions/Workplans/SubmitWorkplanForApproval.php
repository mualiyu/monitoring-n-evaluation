<?php

namespace App\Actions\Workplans;

use App\Enums\WorkplanStatus;
use App\Models\User;
use App\Models\Workplan;

/**
 * Sends a drafted plan up for the accounting officer's signature.
 *
 * A thin, named front door onto the chokepoint — the verb the UI and the tests
 * speak, so `submit` never has to be spelled as a raw status anywhere. Every
 * guard (the chain table, authorization on `submit`, the empty-plan rule, the
 * manual's indicator rule where the instance enforces it) lives in
 * TransitionWorkplanStatus, which stays the single writer.
 */
class SubmitWorkplanForApproval
{
    public function __construct(private readonly TransitionWorkplanStatus $transition) {}

    public function __invoke(Workplan $workplan, User $actor): Workplan
    {
        return ($this->transition)($workplan, WorkplanStatus::Submitted, $actor);
    }
}
