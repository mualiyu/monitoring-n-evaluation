<?php

namespace App\Jobs\Evaluation;

use App\Enums\EvaluationStatus;
use App\Enums\Role;
use App\Jobs\Concerns\TenantAware;
use App\Models\Evaluation;
use App\Models\EvaluationTeamMember;
use App\Models\User;
use App\Notifications\Evaluation\EvaluationChainUpdated;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the next person in the evaluation chain that a report has moved.
 *
 * Recipients are a property of the STEP:
 *   under_review → whoever may approve in this MDA
 *   draft_report → the team, whose report has come back
 *   approved     → the MDA admin AND state oversight (the secretariat
 *                  commissions and consumes evaluations; a finding it never
 *                  hears about is a finding that changes nothing)
 *   published    → the MDA admin and state oversight
 *   cancelled    → the team, who should stop working
 *
 * IDS, NOT MODELS — see NotifyEvaluationTeam for why.
 */
class NotifyEvaluationChain implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public function __construct(
        private readonly int $evaluationId,
        private readonly string $toStatus,
        private readonly int $actorId,
        private readonly ?string $reason = null,
    ) {
        $this->captureTenant();
    }

    public function handle(): void
    {
        $evaluation = Evaluation::query()
            ->with('tenant')
            ->find($this->evaluationId);

        if ($evaluation === null) {
            return;
        }

        $to = EvaluationStatus::from($this->toStatus);

        // THE ROW IS THE AUTHORITY, NOT THE CONSTRUCTOR ARGUMENT. A step that
        // was rolled back after its dispatch has already queued its mail;
        // trusting the argument would announce an approval the database never
        // recorded, and nobody would ever correct that mail. Re-reading costs
        // one query and makes the job idempotent under replay as well. The
        // trade-off is deliberate: a step the chain has since moved past goes
        // unannounced rather than announced wrongly — and by then the move
        // that superseded it has sent its own notification.
        if ($evaluation->status !== $to) {
            return;
        }

        $recipients = $this->recipientsFor($evaluation, $to)
            // The actor knows what they just did; telling them is noise, and
            // noise is how people learn to ignore this channel.
            ->reject(fn (User $user): bool => $user->id === $this->actorId)
            ->filter(fn (User $user): bool => $user->is_active);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new EvaluationChainUpdated($evaluation, $to, $this->reason));
    }

    /**
     * @return Collection<int, User>
     */
    private function recipientsFor(Evaluation $evaluation, EvaluationStatus $to): Collection
    {
        return match ($to) {
            EvaluationStatus::UnderReview => $this->tenantUsersWithRole([Role::MdaAdmin]),
            EvaluationStatus::DraftReport, EvaluationStatus::Cancelled => $this->team($evaluation),
            EvaluationStatus::Approved, EvaluationStatus::Published => $this->tenantUsersWithRole([Role::MdaAdmin])
                ->merge($this->stateOversight())
                ->unique('id')
                ->values(),
            EvaluationStatus::Planned, EvaluationStatus::InProgress => $this->team($evaluation),
        };
    }

    /**
     * The workspace's holders of a role. spatie resolves the `role` scope
     * against the CURRENT permission team, which SetTenantContext has already
     * bound — so this can only ever return users of this MDA.
     *
     * @param  list<Role>  $roles
     * @return Collection<int, User>
     */
    private function tenantUsersWithRole(array $roles): Collection
    {
        return User::query()
            ->role(array_map(fn (Role $role): string => $role->value, $roles))
            ->where('is_active', true)
            ->get();
    }

    /**
     * State oversight — resolved in the GLOBAL permission team, because an
     * oversight role is held with a null tenant and the `role` scope above
     * would otherwise look for it inside this MDA and find nobody.
     *
     * @return Collection<int, User>
     */
    private function stateOversight(): Collection
    {
        return app(CurrentTenant::class)->runWithoutTenant(
            fn (): Collection => User::query()
                ->role([Role::StateAdmin->value])
                ->where('is_active', true)
                ->get(),
        );
    }

    /**
     * The evaluators with accounts. External members are on the record but
     * have no inbox here.
     *
     * @return Collection<int, User>
     */
    private function team(Evaluation $evaluation): Collection
    {
        $ids = EvaluationTeamMember::query()
            ->where('evaluation_id', $evaluation->id)
            ->accountHolders()
            ->pluck('user_id')
            ->all();

        $ids[] = $evaluation->created_by_id;

        return User::query()->whereIn('id', array_values(array_unique(array_filter($ids))))->get();
    }
}
