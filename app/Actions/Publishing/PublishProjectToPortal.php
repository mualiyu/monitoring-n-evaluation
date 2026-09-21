<?php

namespace App\Actions\Publishing;

use App\Actions\Projects\PublishProject;
use App\Enums\ProjectStatus;
use App\Exceptions\Publishing\PublishingRuleViolation;
use App\Models\Project;
use App\Models\User;

/**
 * The publishing gate's one precondition, in front of the existing writer.
 *
 * App\Actions\Projects\PublishProject stays exactly as it is — it owns the
 * authorization check and the two non-fillable columns, and widening it with a
 * portal rule would put a transparency decision inside the projects module.
 * This Action adds the single question the portal cares about and delegates:
 * *should this project be on a public website at all?*
 *
 * Draft and cancelled are the two answers of "no":
 *  - a DRAFT project has no award, no contractor and no attested progress. Its
 *    figures are a plan, and a plan printed on a government transparency
 *    portal reads as a commitment.
 *  - a CANCELLED project was abandoned. Listing it among projects being
 *    delivered misrepresents the portfolio; the honest place for it is a
 *    cancellation register, which is not this screen.
 *
 * Everything from `awarded` onward is publishable, including suspended works —
 * a stalled project is precisely what the public most needs to be able to see.
 */
class PublishProjectToPortal
{
    /**
     * Statuses the state may publish. Read this list, not a query: both
     * publishing queues filter their candidates by it, so the screen and the
     * guard can never disagree about which projects are offered.
     *
     * @var list<ProjectStatus>
     */
    public const PUBLISHABLE_STATUSES = [
        ProjectStatus::Awarded,
        ProjectStatus::Mobilized,
        ProjectStatus::InProgress,
        ProjectStatus::Completed,
        ProjectStatus::Certified,
        ProjectStatus::Closed,
        ProjectStatus::Suspended,
    ];

    public static function allows(ProjectStatus $status): bool
    {
        return in_array($status, self::PUBLISHABLE_STATUSES, true);
    }

    /** @return list<string> */
    public static function publishableValues(): array
    {
        return array_map(
            fn (ProjectStatus $status): string => $status->value,
            self::PUBLISHABLE_STATUSES,
        );
    }

    public function __invoke(Project $project, User $actor): Project
    {
        if (! self::allows($project->status)) {
            throw PublishingRuleViolation::notPublishable($project->status);
        }

        // The authorization check, the already-published guard and the two
        // non-fillable columns all live in there. One writer, still.
        return (new PublishProject)($project, $actor);
    }
}
