<?php

namespace App\Jobs\Evaluation;

use App\Jobs\Concerns\TenantAware;
use App\Models\Recommendation;
use App\Models\User;
use App\Notifications\Evaluation\RecommendationAssigned;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the addressee that a recommendation has been put to them.
 *
 * Only a recommendation addressed to a USER can be delivered — one addressed
 * to a body ("Ministry of Finance") is on the register and on the board, but
 * has no inbox to reach, which is exactly why the register exists.
 *
 * IDS, NOT MODELS — see NotifyEvaluationTeam for why.
 */
class NotifyRecommendationAssigned implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public function __construct(private readonly int $recommendationId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $recommendation = Recommendation::query()
            ->with('tenant')
            ->find($this->recommendationId);

        if ($recommendation === null || $recommendation->addressee_id === null) {
            return;
        }

        $addressee = User::query()
            ->whereKey($recommendation->addressee_id)
            ->where('is_active', true)
            ->first();

        if ($addressee === null) {
            return;
        }

        $addressee->notify(new RecommendationAssigned($recommendation));
    }
}
