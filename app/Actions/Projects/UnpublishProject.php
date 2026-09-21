<?php

namespace App\Actions\Projects;

use App\Exceptions\Publishing\PublishingRuleViolation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Withdraw a project from the public portal — the other half of the publishing
 * gate, and the half that has to work under pressure.
 *
 * Unpublishing is not "hiding": it clears `published_at`, which is the single
 * predicate every portal read filters on
 * (App\Support\Publishing\PublicProjectPayload::publishedOnly), so the project
 * leaves the browser, the detail page, the map and the landing-page counters
 * in the same instant, with no cache to wait for and no second switch to
 * remember.
 *
 * It sits beside PublishProject rather than inside it deliberately: the two
 * are not one toggle. Publishing is an editorial decision made calmly;
 * unpublishing is usually an incident — wrong figure, wrong photograph, a name
 * that should not be on a public page — and it carries a mandatory reason
 * because "why did the state take this down" is the only question anyone will
 * ask afterwards.
 *
 * Same authority as publishing (`projects.publish`): whoever may show the
 * public something may take it back.
 */
class UnpublishProject
{
    public function __invoke(Project $project, User $actor, string $reason): Project
    {
        Gate::forUser($actor)->authorize('publish', $project);

        if ($project->published_at === null) {
            throw PublishingRuleViolation::notPublished();
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw PublishingRuleViolation::reasonRequired();
        }

        $publishedAt = $project->published_at;

        // forceFill: `published_at` / `published_by_id` are deliberately not
        // fillable, so the assignment is explicit and this Action stays the
        // greppable counterpart of PublishProject. Both columns clear — a
        // stale publisher on an unpublished record reads as though they were
        // the one who withdrew it.
        $project->forceFill([
            'published_at' => null,
            'published_by_id' => null,
        ])->save();

        // The columns record the state; the audit trail records the decision.
        // Activitylog already captures the published_at change (it is in
        // Project's logOnly list); this entry is what carries the reason and
        // how long the project had been public.
        activity('projects')
            ->performedOn($project)
            ->causedBy($actor)
            ->withProperties([
                'published_at' => $publishedAt?->toIso8601String(),
                'reason' => $reason,
            ])
            ->log('unpublished');

        return $project;
    }
}
