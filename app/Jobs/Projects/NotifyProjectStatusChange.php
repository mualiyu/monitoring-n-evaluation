<?php

namespace App\Jobs\Projects;

use App\Enums\ProjectStatus;
use App\Jobs\Concerns\TenantAware;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use App\Notifications\Projects\ProjectStatusUpdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the people accountable for a project that its status moved.
 *
 * IDS, NOT MODELS, on purpose: SerializesModels restores relations in
 * __unserialize — i.e. BEFORE the job middleware binds tenancy — so a
 * serialized Project would be re-queried with no tenant bound and the
 * fail-closed scope would throw before handle() ever ran. Everything is
 * therefore loaded inside handle(), where SetTenantContext has already put the
 * worker in the right workspace.
 */
class NotifyProjectStatusChange implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantAware;

    public function __construct(
        private readonly int $projectId,
        private readonly ?string $fromStatus,
        private readonly string $toStatus,
        private readonly int $actorId,
        private readonly ?string $reason = null,
    ) {
        $this->captureTenant();
    }

    public function handle(): void
    {
        // `tenant` is eager-loaded because the mail builds the workspace URL
        // from it and lazy loading is prevented outside production.
        $project = Project::query()->with('tenant')->find($this->projectId);

        if ($project === null) {
            return; // archived between dispatch and execution — nothing to say
        }

        $recipientIds = ProjectAssignment::query()
            ->where('project_id', $project->id)
            ->active()
            ->pluck('user_id')
            ->push($project->manager_id)
            ->filter()
            // The actor knows what they just did; telling them is noise, and
            // noise is how people learn to ignore this channel.
            ->reject(fn (int $id): bool => $id === $this->actorId)
            ->unique()
            ->all();

        if ($recipientIds === []) {
            return;
        }

        $recipients = User::query()
            ->whereIn('id', $recipientIds)
            ->where('is_active', true)
            ->get();

        Notification::send($recipients, new ProjectStatusUpdated(
            $project,
            $this->fromStatus === null ? null : ProjectStatus::from($this->fromStatus),
            ProjectStatus::from($this->toStatus),
            $this->reason,
        ));
    }
}
