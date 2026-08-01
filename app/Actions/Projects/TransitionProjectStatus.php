<?php

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Enums\Role;
use App\Events\Projects\ProjectStatusChanged;
use App\Exceptions\Projects\InvalidStatusTransition;
use App\Exceptions\Projects\ProjectRuleViolation;
use App\Models\Project;
use App\Models\ProjectStatusEvent;
use App\Models\User;
use App\Support\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * THE single writer of Project::$status (design §2.1). Nothing else in the
 * codebase assigns that column — which is why it is not fillable, why this
 * class is greppable as the one chokepoint, and why the guards below cannot be
 * routed around by a form payload.
 *
 * Order is deliberate and fails closed at the cheapest question first:
 *   1. the transition table (an impossible move is impossible for everyone),
 *   2. authorization (per TARGET status — see abilityFor()),
 *   3. domain preconditions (a contract exists; the works are actually done;
 *      a reason was given; the review window has passed),
 *   4. the write + the typed ledger row, in one transaction,
 *   5. the event, once the transaction has closed.
 */
class TransitionProjectStatus
{
    public function __invoke(
        Project $project,
        ProjectStatus $to,
        User $actor,
        ?string $reason = null,
        bool $override = false,
        ?CarbonInterface $actualEndDate = null,
    ): Project {
        $from = $project->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidStatusTransition::between($from, $to);
        }

        Gate::forUser($actor)->authorize($this->abilityFor($from, $to), $project);

        $reason = $reason === null ? null : trim($reason);
        $this->assertPreconditions($project, $to, $actor, $reason, $override);

        $changes = [
            'status' => $to,
            'status_changed_at' => now(),
        ];

        if ($to === ProjectStatus::Completed) {
            $completedOn = $actualEndDate ?? now();
            $changes['actual_end_date'] = $completedOn;
            $changes['post_completion_review_due_at'] = $this->reviewDueDate($completedOn);
        }

        // Certification is the second chance to stamp the review clock: a
        // project completed before this Action existed (or imported mid-life)
        // reaches certification with an end date and no due date, and closure
        // is gated on that date being present.
        if ($to === ProjectStatus::Certified
            && $project->post_completion_review_due_at === null
            && $project->actual_end_date !== null) {
            $changes['post_completion_review_due_at'] = $this->reviewDueDate($project->actual_end_date);
        }

        DB::transaction(function () use ($project, $from, $to, $actor, $reason, $changes): void {
            // forceFill: these columns are deliberately not fillable, so the
            // assignment is explicit and the chokepoint stays greppable.
            $project->forceFill($changes)->save();

            $event = new ProjectStatusEvent([
                'project_id' => $project->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
            // Explicit property write (tenant_id is not fillable): on the
            // oversight surface (suspend/close/cancel run inside a bypass with
            // no tenant bound) auto-fill cannot supply it; the project itself
            // is always the authority.
            $event->tenant_id = $project->tenant_id;
            $event->save();
        });

        // Fired after the write closes, never inside it. When this Action runs
        // nested in AwardContract's transaction the event still precedes that
        // outer commit by microseconds — accepted: both listeners are
        // idempotent (a cache delete and a queued notification).
        ProjectStatusChanged::dispatch($project, $from, $to, $actor->id, $reason);

        return $project;
    }

    /**
     * Authorization is per TARGET status (design §2), because what the move
     * costs politically is a property of where it lands: awarding is
     * procurement authority, certifying is the completion certificate,
     * closing ends the record.
     *
     * The one edge that is keyed on its ORIGIN is suspended → in_progress:
     * §2's table gives the suspended row `projects.suspend` (MdaAdmin,
     * StateAdmin), so resuming suspended works belongs to the authority that
     * can suspend it — not to any officer holding the generic status
     * permission. Reading it the other way would let an M&E officer quietly
     * restart works a state administrator stopped.
     */
    private function abilityFor(ProjectStatus $from, ProjectStatus $to): string
    {
        if ($from === ProjectStatus::Suspended && $to === ProjectStatus::InProgress) {
            return 'suspend';
        }

        return match ($to) {
            ProjectStatus::Awarded => 'award',
            ProjectStatus::Certified => 'certify',
            ProjectStatus::Closed => 'close',
            ProjectStatus::Suspended => 'suspend',
            ProjectStatus::Cancelled => 'cancel',
            default => 'updateStatus',
        };
    }

    private function assertPreconditions(
        Project $project,
        ProjectStatus $to,
        User $actor,
        ?string $reason,
        bool $override,
    ): void {
        // "Awarded" without a contract is a status claiming a document that
        // does not exist.
        if ($to === ProjectStatus::Awarded && ! $project->contracts()->exists()) {
            throw ProjectRuleViolation::awardWithoutContract();
        }

        // Completion means the works are done — attested at 100%, not
        // "nearly", because certification and payment follow this flag.
        if ($to === ProjectStatus::Completed && ! $this->isComplete($project)) {
            throw ProjectRuleViolation::completionBeforeFullProgress($project->physical_progress);
        }

        // Phase 2 guard point, wired now and switched by a setting: when final
        // inspections exist, certification will require one.
        if ($to === ProjectStatus::Certified
            && app(SettingsRepository::class)->bool('monitoring', 'require_final_inspection_for_certification', false)) {
            throw ProjectRuleViolation::certificationRequiresFinalInspection();
        }

        // Stopping or abandoning public works is a decision someone signs for.
        if (in_array($to, [ProjectStatus::Suspended, ProjectStatus::Cancelled], true)
            && ($reason === null || $reason === '')) {
            throw ProjectRuleViolation::reasonRequired($to);
        }

        if ($to === ProjectStatus::Closed) {
            $this->assertClosable($project, $actor, $reason, $override);
        }
    }

    /**
     * Closure ends post-completion monitoring, so it waits for the window to
     * end. A state-level administrator may override — with a reason, on the
     * record — because real programmes do close early (a facility handed to a
     * federal agency, a cancelled successor phase).
     */
    private function assertClosable(Project $project, User $actor, ?string $reason, bool $override): void
    {
        $dueAt = $project->post_completion_review_due_at;

        if ($dueAt !== null && $dueAt->isPast()) {
            return;
        }

        if (! $override || $reason === null || $reason === '') {
            throw ProjectRuleViolation::closureBeforeReviewWindow($dueAt);
        }

        if (! $actor->holdsGlobalRole(Role::SuperAdmin, Role::StateAdmin)) {
            throw ProjectRuleViolation::closureOverrideRequiresStateAuthority();
        }
    }

    /**
     * physical_progress is decimal(5,2); comparing the minor units as integers
     * keeps the completion test off floats entirely.
     */
    private function isComplete(Project $project): bool
    {
        return (int) round(((float) $project->physical_progress) * 100) === 10_000;
    }

    private function reviewDueDate(CarbonInterface $actualEnd): CarbonInterface
    {
        return $actualEnd->copy()->addMonths(
            app(SettingsRepository::class)->int('monitoring', 'post_completion_review_months', 6),
        );
    }
}
