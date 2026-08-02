<?php

namespace App\Actions\Reporting;

use App\Exceptions\Reporting\ReportRuleViolation;
use App\Models\ProgressReport;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Gate;

/**
 * The autosave target of the reporting wizard (progress-reporting.md §1.3).
 *
 * There is no separate draft store: the `progress_reports` row IS the draft,
 * and this Action updates it and stamps `autosaved_at`. A JSON payload column
 * alongside the real columns would give the same data two shapes and two sets
 * of validation rules, and the shape the reviewer sees would be the one that
 * was never validated.
 *
 * Author-only and editable-states-only — both enforced by the policy plus the
 * explicit state check here, because an autosave endpoint is the softest way
 * into a record and a reviewed report must not move under its reviewer.
 */
class SaveProgressReportDraft
{
    /**
     * @param  array<string, mixed>  $attributes  narrative + figure fields only
     */
    public function __invoke(ProgressReport $report, User $actor, array $attributes): ProgressReport
    {
        Gate::forUser($actor)->authorize('update', $report);

        if (! $report->isEditable()) {
            throw ReportRuleViolation::notEditable($report->status);
        }

        $allowed = array_intersect_key($attributes, array_flip([
            'narrative_work_done', 'narrative_challenges', 'narrative_mitigation',
            'narrative_next_period', 'physical_progress_claimed',
            'progress_decrease_reason', 'period_expenditure',
        ]));

        if (array_key_exists('physical_progress_claimed', $allowed)) {
            $this->assertPercentage((string) $allowed['physical_progress_claimed']);
        }

        if (array_key_exists('period_expenditure', $allowed) && is_string($allowed['period_expenditure'])) {
            // Parsed at the boundary: a malformed amount is rejected here, not
            // stored and discovered by the reviewer.
            $allowed['period_expenditure'] = Money::fromDecimalString($allowed['period_expenditure']);
        }

        $report->fill($allowed);
        $report->forceFill(['autosaved_at' => now()])->save();

        return $report;
    }

    private function assertPercentage(string $value): void
    {
        if (! is_numeric($value)) {
            throw ReportRuleViolation::progressOutOfRange($value);
        }

        // decimal(5,2) — integer basis points keep the range test off floats.
        $basisPoints = (int) round(((float) $value) * 100);

        if ($basisPoints < 0 || $basisPoints > 10_000) {
            throw ReportRuleViolation::progressOutOfRange($value);
        }
    }
}
