<?php

namespace App\Actions\Issues;

use App\Enums\IssueSeverity;
use App\Exceptions\Issues\IssueRuleViolation;
use App\Models\Issue;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * What will be done about the obstruction, and by when — the manual's
 * corrective-action loop (§7 feature cue 9: recommendations feeding
 * decisions), and the half of the register that turns a list of complaints
 * into a plan.
 *
 * The due date is what the overdue flag and the register's "past its deadline"
 * stat run on. An issue with no due date is never overdue, which is correct:
 * a deadline nobody set is not a deadline missed, and flagging one would teach
 * officers to ignore the flag.
 *
 * Severity is revisable here too. It is a judgement, not a fact — "no cash
 * released" is critical on a road that is 90% built and routine on one that
 * has not mobilised — and it drives the escalation ladder, so an M&E officer
 * must be able to correct a raiser's reading of it.
 */
class RecordCorrectiveAction
{
    /**
     * @param  array{
     *     corrective_action?: string|null,
     *     due_date?: CarbonImmutable|string|null,
     *     severity?: IssueSeverity|null,
     * }  $attributes
     */
    public function __invoke(Issue $issue, User $actor, array $attributes): Issue
    {
        Gate::forUser($actor)->authorize('update', $issue);

        if (! $issue->status->isEditable()) {
            throw IssueRuleViolation::notEditable($issue->status);
        }

        if (array_key_exists('corrective_action', $attributes)) {
            $plan = $attributes['corrective_action'];
            $plan = is_string($plan) ? trim($plan) : null;

            $issue->corrective_action = $plan === '' ? null : $plan;
        }

        if (array_key_exists('due_date', $attributes)) {
            $issue->due_date = $attributes['due_date'];
        }

        if (($attributes['severity'] ?? null) instanceof IssueSeverity) {
            $issue->severity = $attributes['severity'];
        }

        $issue->save();

        return $issue;
    }
}
