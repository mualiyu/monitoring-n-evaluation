<?php

namespace App\Jobs\Evaluation;

use App\Jobs\Concerns\TenantAware;
use App\Models\Evaluation;
use App\Models\User;
use App\Notifications\Evaluation\EvaluationCommissioned;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Tells newly-assigned evaluators that they are on a team.
 *
 * IDS, NOT MODELS, on purpose: SerializesModels restores relations in
 * __unserialize — i.e. BEFORE the job middleware binds tenancy — so a
 * serialized Evaluation would be re-queried with no tenant bound and the
 * fail-closed scope would throw before handle() ever ran. Everything is loaded
 * inside handle(), where SetTenantContext has already put the worker in the
 * right workspace, which is also what makes the mail render with the right
 * MDA's branding and terminology.
 */
class NotifyEvaluationTeam implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    /**
     * @param  list<int>  $userIds
     */
    public function __construct(
        private readonly int $evaluationId,
        private readonly array $userIds,
    ) {
        $this->captureTenant();
    }

    public function handle(): void
    {
        if ($this->userIds === []) {
            return;
        }

        $evaluation = Evaluation::query()
            ->with('tenant')
            ->find($this->evaluationId);

        if ($evaluation === null) {
            return; // discarded between dispatch and execution — nothing to say
        }

        $recipients = User::query()
            ->whereIn('id', $this->userIds)
            ->where('is_active', true)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new EvaluationCommissioned($evaluation));
    }
}
