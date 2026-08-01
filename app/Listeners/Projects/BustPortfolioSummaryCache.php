<?php

namespace App\Listeners\Projects;

use App\Actions\Oversight\BuildPortfolioSummary;
use App\Events\Projects\ProjectStatusChanged;
use Illuminate\Support\Facades\Cache;

/**
 * Synchronous on purpose (design §6): the oversight board caches its grouped
 * query for five minutes, and the one event that certainly invalidates it is a
 * status change. Queueing this would leave the Governor's dashboard showing a
 * suspended project as in-progress for however long the worker backlog is —
 * for the sake of deferring a single cache DELETE.
 */
class BustPortfolioSummaryCache
{
    public function handle(ProjectStatusChanged $event): void
    {
        Cache::forget(BuildPortfolioSummary::CACHE_KEY);
    }
}
