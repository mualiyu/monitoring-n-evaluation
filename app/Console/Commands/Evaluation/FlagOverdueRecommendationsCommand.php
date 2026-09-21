<?php

namespace App\Console\Commands\Evaluation;

use App\Actions\Evaluation\FlagOverdueRecommendations;
use Illuminate\Console\Command;

/**
 * Announces recommendations that have passed their due date without being
 * implemented — once each, to the addressee and to the MDA admin.
 *
 * The gate is `overdue_flagged_at` on the row, set under the same lock as the
 * dispatch, so a second run the same day is silent and a replayed job cannot
 * double-send.
 */
class FlagOverdueRecommendationsCommand extends Command
{
    protected $signature = 'evaluations:flag-overdue-recommendations';

    protected $description = 'Notify addressees and MDA admins of overdue recommendations (idempotent)';

    public function handle(FlagOverdueRecommendations $flag): int
    {
        $totals = $flag();

        $this->info("{$totals['flagged']} overdue recommendation notice(s) dispatched.");

        return self::SUCCESS;
    }
}
