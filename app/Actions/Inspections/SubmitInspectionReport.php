<?php

namespace App\Actions\Inspections;

use App\Enums\InspectionOutcome;
use App\Enums\InspectionStatus;
use App\Models\SiteInspection;
use App\Models\User;

/**
 * Files the Field Trip Report (digest §3). The visit stops being editable
 * here: findings, checklist and photographs freeze, because evidence that can
 * change after it has been read is not evidence.
 *
 * Every guard lives in the chokepoint — the verdict exists, the findings
 * section is written, every required checklist item is answered, and
 * `inspections.require_photo_evidence` is satisfied. This Action's own job is
 * to carry the last of the inspector's typing into the same transaction as the
 * status write, so a submission cannot half-land: a report whose verdict saved
 * but whose status did not is one an M&E officer never sees in their queue.
 */
class SubmitInspectionReport
{
    /**
     * @param  array<string, mixed>  $report  the closing Field Trip Report fields
     */
    public function __invoke(
        SiteInspection $inspection,
        User $actor,
        InspectionOutcome $outcome,
        array $report = [],
    ): SiteInspection {
        $changes = array_intersect_key($report, array_flip([
            'people_met', 'methods', 'findings', 'comparison_with_previous',
            'conclusions', 'recommendations', 'risk_flags',
        ]));

        // These ARE fillable (an officer types them), but they travel through
        // the chokepoint's $extraChanges so the whole filing is one write.
        $changes['outcome'] = $outcome;

        return app(TransitionInspectionStatus::class)(
            $inspection,
            InspectionStatus::Submitted,
            $actor,
            null,
            $changes,
        );
    }
}
