<?php

namespace App\Console\Commands\Issues;

use App\Actions\Issues\EscalateStaleIssues;
use App\Actions\Issues\EvaluateProjectThresholds;
use Illuminate\Console\Command;

/**
 * The nightly deviation sweep (plan §8 feature cue 4).
 *
 * Two passes, in this order and deliberately so: measure the projects first,
 * then walk the issue ladder. A slippage exception raised tonight may be the
 * thing an officer turns into an issue tomorrow, and running the ladder first
 * would simply defer that by a day.
 *
 * Both passes are idempotent at the WRITE — the exception duplicate gate and
 * the `escalated_at` counter are each checked under a row lock in the same
 * transaction as the write they guard — so a second run the same day is
 * silent. The schedule's ->withoutOverlapping() is there to stop a slow sweep
 * across forty MDAs stacking up behind itself, not to make it correct.
 */
class EvaluateThresholdsCommand extends Command
{
    protected $signature = 'issues:evaluate-thresholds';

    protected $description = 'Raise exception reports for projects past their deviation tolerances and escalate stale issues (idempotent)';

    public function handle(EvaluateProjectThresholds $evaluate, EscalateStaleIssues $escalate): int
    {
        $totals = $evaluate();
        $escalated = $escalate();

        $this->info(
            "{$totals['evaluated']} project(s) measured, {$totals['raised']} exception report(s) raised, "
            ."{$escalated} issue(s) escalated."
        );

        return self::SUCCESS;
    }
}
