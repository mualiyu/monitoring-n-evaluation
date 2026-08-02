<?php

namespace App\Actions\Reporting;

use App\Enums\ProgressReportStatus;
use App\Models\ProgressReport;
use App\Models\User;

/**
 * The M&E focal officer's step: the return has been read against the works and
 * is fit to go to the director (progress-reporting.md §2).
 *
 * Review moves NO project figures — only approval does (§2.3). That separation
 * is the whole reason review exists as a distinct hop: an officer can say "this
 * is a coherent return" without thereby declaring the project 62% built.
 *
 * The reviewer-≠-submitter guard lives in the chokepoint, where it cannot be
 * skipped by a second caller.
 */
class ReviewProgressReport
{
    public function __construct(private readonly TransitionProgressReportStatus $transition) {}

    public function __invoke(ProgressReport $report, User $actor): ProgressReport
    {
        return ($this->transition)($report, ProgressReportStatus::Reviewed, $actor);
    }
}
