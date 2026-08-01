<?php

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Project;
use App\Models\User;
use App\Support\Money;
use App\Support\SettingsRepository;
use Illuminate\Support\Facades\Gate;

/**
 * The only writer of `physical_progress` and `expenditure_to_date` (design
 * §2.4). Both figures are HUMAN-ATTESTED, never derived, and the permission
 * behind them (`projects.progress.update`) is deliberately not held by
 * consultants: a contractor does not declare their own project 80% done. In
 * Phase 2 the numbers arrive by approving a progress report — through this
 * same Action.
 *
 * It also raises the mid-term evaluation flag, which is an EVENT and not a
 * status (design §2.2, and the finding that removed `mid_term` from the enum):
 * the project stays in_progress, a flag is stamped once, and Phase 2
 * evaluations resolve it.
 *
 * Progress is NOT forced to be monotonic. Neither the design nor the review
 * asks for it, and verified downward revisions are real — an inspection that
 * finds the reported 60% is actually 45% must be recordable, or the number
 * everyone trusts becomes the number nobody can correct.
 */
class RecordProjectProgress
{
    /** Lifecycle states in which a progress figure means anything. */
    private const REPORTABLE = [
        ProjectStatus::Awarded,
        ProjectStatus::Mobilized,
        ProjectStatus::InProgress,
        ProjectStatus::Suspended,
        ProjectStatus::Completed,
    ];

    public function __invoke(
        Project $project,
        User $actor,
        string $physicalProgress,
        Money|string|null $expenditureToDate = null,
    ): Project {
        Gate::forUser($actor)->authorize('updateProgress', $project);

        if (! in_array($project->status, self::REPORTABLE, true)) {
            throw ProjectRuleViolation::progressOnInactiveProject($project->status);
        }

        if (! is_numeric($physicalProgress)) {
            throw ProjectRuleViolation::progressOutOfRange($physicalProgress);
        }

        // decimal(5,2) percentage — integer basis points keep the comparison
        // off floating point.
        $basisPoints = (int) round(((float) $physicalProgress) * 100);

        if ($basisPoints < 0 || $basisPoints > 10_000) {
            throw ProjectRuleViolation::progressOutOfRange($physicalProgress);
        }

        $changes = ['physical_progress' => $physicalProgress];

        if ($expenditureToDate !== null) {
            $changes['expenditure_to_date'] = $expenditureToDate;
        }

        $trigger = app(SettingsRepository::class)->int('monitoring', 'mid_term_trigger_percent', 50);

        // Stamped once, the first time the threshold is crossed: a flag that
        // re-raises every quarter is a flag people switch off.
        if (! $project->isMidTermFlagged() && $basisPoints >= $trigger * 100) {
            $changes['mid_term_flagged_at'] = now();
        }

        // forceFill: these columns are not fillable precisely so that this is
        // the only place they are assigned.
        $project->forceFill($changes)->save();

        return $project;
    }
}
