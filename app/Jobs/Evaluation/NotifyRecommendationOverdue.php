<?php

namespace App\Jobs\Evaluation;

use App\Enums\Role;
use App\Jobs\Concerns\TenantAware;
use App\Models\Recommendation;
use App\Models\User;
use App\Notifications\Evaluation\RecommendationOverdue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Announces an overdue recommendation to the addressee AND to the MDA admin.
 *
 * BOTH, not just the addressee: the whole point of escalating is that someone
 * accountable for the register learns of a silent addressee without having to
 * run a report. A recommendation addressed to a body rather than to a person
 * still reaches the MDA admin, which is what stops "addressed to the Ministry"
 * from meaning "addressed to nobody".
 *
 * Sent once — the gate is `overdue_flagged_at` on the row, set by
 * FlagOverdueRecommendations under the same lock as this dispatch.
 *
 * IDS, NOT MODELS — see NotifyEvaluationTeam for why.
 */
class NotifyRecommendationOverdue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public function __construct(private readonly int $recommendationId)
    {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $recommendation = Recommendation::query()
            ->with(['tenant', 'addressee'])
            ->find($this->recommendationId);

        if ($recommendation === null || ! $recommendation->isOutstanding()) {
            // Implemented or closed between the sweep and the worker — the
            // row is the authority, not the dispatch.
            return;
        }

        $daysLate = max(0, ($recommendation->daysToDue() ?? 0) * -1);

        $recipients = $this->recipients($recommendation);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new RecommendationOverdue($recommendation, $daysLate));
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(Recommendation $recommendation): Collection
    {
        // spatie resolves the `role` scope against the CURRENT permission
        // team, which SetTenantContext has already bound — so this can only
        // ever return users of this MDA.
        $admins = User::query()
            ->role([Role::MdaAdmin->value])
            ->where('is_active', true)
            ->get();

        if ($recommendation->addressee_id === null) {
            return $admins;
        }

        $addressee = User::query()
            ->whereKey($recommendation->addressee_id)
            ->where('is_active', true)
            ->get();

        return $admins->merge($addressee)->unique('id')->values();
    }
}
