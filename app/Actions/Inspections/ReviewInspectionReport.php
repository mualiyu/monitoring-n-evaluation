<?php

namespace App\Actions\Inspections;

use App\Enums\InspectionStatus;
use App\Models\SiteInspection;
use App\Models\User;

/**
 * An M&E officer signs off a filed Field Trip Report — the assurance step.
 *
 * Note what this does NOT do: it never edits the findings. A reviewer who
 * disagrees with what the inspector observed records that disagreement in
 * `review_notes`, against the original, and orders another visit. The
 * alternative — a review that can rewrite an observation — turns the state's
 * own record of a site into something negotiable after the fact, which is the
 * precise failure mode an inspection regime exists to prevent. That is also
 * why the lifecycle has no `returned` rung.
 *
 * The separation guard (the inspector never reviews their own inspection) is
 * enforced in the chokepoint, not here and not only by permission: an M&E
 * officer holds both `inspections.conduct` and `inspections.review` and would
 * otherwise file and sign off the same visit.
 */
class ReviewInspectionReport
{
    public function __invoke(SiteInspection $inspection, User $actor, ?string $notes = null): SiteInspection
    {
        $notes = $notes === null || trim($notes) === '' ? null : trim($notes);

        return app(TransitionInspectionStatus::class)(
            $inspection,
            InspectionStatus::Reviewed,
            $actor,
            $notes,
            ['review_notes' => $notes],
        );
    }
}
