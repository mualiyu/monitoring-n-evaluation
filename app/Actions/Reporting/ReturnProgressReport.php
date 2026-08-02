<?php

namespace App\Actions\Reporting;

use App\Enums\ProgressReportStatus;
use App\Models\ProgressReport;
use App\Models\User;

/**
 * Sends a return back for rework, with a reason — the rejection path of the
 * chain (progress-reporting.md §2).
 *
 * Named for the status it writes (`returned`), not "reject": the report is not
 * refused, it goes back to its author, who fixes it and files it again. The
 * distinction matters on the compliance board — the obligation stays fulfilled
 * from its first submission, because the MDA did report on time and a reviewer's
 * turnaround must not retroactively make them late (§9.6).
 *
 * Reviewer or approver may return, and which permission is required depends on
 * where the report currently sits — the chokepoint decides that from the origin
 * status. The reason is mandatory there: an author who is told "returned" and
 * not told why cannot fix anything.
 */
class ReturnProgressReport
{
    public function __construct(private readonly TransitionProgressReportStatus $transition) {}

    public function __invoke(ProgressReport $report, User $actor, string $reason): ProgressReport
    {
        return ($this->transition)($report, ProgressReportStatus::Returned, $actor, $reason);
    }
}
