<?php

namespace App\Actions\Consolidation;

use App\Exceptions\Consolidation\ConsolidationRuleViolation;
use App\Models\ConsolidatedReport;
use App\Models\ConsolidatedReportSection;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Write one narrative chapter of a consolidation.
 *
 * Sections are keyed by the skeleton the TYPE defines
 * (ConsolidatedReportType::sectionSkeleton), so an unknown key is refused
 * rather than silently creating a chapter no template renders — the skeleton
 * is a contract about what an APR contains, and a state is judged on it.
 *
 * The narrative closes when the figures do. Once the report has left the
 * secretariat's desk (`in_review` and beyond), editing goes through a return,
 * because text that can change after a reviewer read it is not text they
 * reviewed.
 */
class RecordConsolidationSection
{
    public function __invoke(
        ConsolidatedReport $report,
        string $key,
        ?string $body,
        User $actor,
    ): ConsolidatedReportSection {
        Gate::forUser($actor)->authorize('update', $report);

        if (! $report->isEditable()) {
            throw ConsolidationRuleViolation::notEditable($report->status->label());
        }

        $skeleton = $report->type->sectionSkeleton();

        if (! array_key_exists($key, $skeleton)) {
            throw ConsolidationRuleViolation::notEditable($key);
        }

        $position = array_search($key, array_keys($skeleton), true);

        /** @var ConsolidatedReportSection $section */
        $section = ConsolidatedReportSection::query()->updateOrCreate(
            [
                'consolidated_report_id' => $report->id,
                'key' => $key,
            ],
            [
                // The heading follows the skeleton, not the form: an officer
                // may write anything into a chapter, but not rename the
                // chapters an APR is required to have.
                'heading' => $skeleton[$key],
                'body' => $body === null || trim($body) === '' ? null : trim($body),
                'position' => $position === false ? 0 : $position,
                'updated_by_id' => $actor->id,
            ],
        );

        // The parent may be holding a `sections` collection loaded earlier in
        // this same request — loadMissing() will not re-read a relation that
        // is already there, and the very next guard in the chain
        // (TransitionConsolidationStatus::assertReviewable) asks THAT
        // collection whether the summary has been written. Without this, an
        // officer who writes the summary and sends the roll-up up in one
        // request is told the chapter is empty.
        $report->unsetRelation('sections');

        return $section;
    }
}
