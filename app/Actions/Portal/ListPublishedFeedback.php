<?php

namespace App\Actions\Portal;

use App\Models\Feedback;
use Illuminate\Database\Eloquent\Collection;

/**
 * The feedback shown under a published project's public page.
 *
 * Three gates, all of them applied here rather than in a view:
 *  1. The PROJECT is re-resolved through FindPublishedProject, so a thread
 *     cannot outlive its project's publication — unpublish the project and
 *     the conversation about it goes with it.
 *  2. Only feedback a moderator MOVED to `published`. Pending submissions are
 *     never visible, so the portal can never be used as an unmoderated
 *     megaphone against a named contractor.
 *  3. Only responses marked public. An internal note written on a complaint
 *     must not surface because the complaint was published afterwards.
 *
 * No tenancy bypass for the feedback itself: `feedback` is a global table (see
 * its migration), so nothing tenant-owned is queried here at all.
 *
 * What the view renders from these rows — subject, body, submitter label,
 * responses — is USER-GENERATED CONTENT and goes through `{{ }}` only. There
 * is no `{!! !!}` anywhere on the portal, for anything, ever.
 */
class ListPublishedFeedback
{
    /** Most recent conversations first, capped: a public page, not an archive. */
    public const MAX = 20;

    /** @return Collection<int, Feedback> */
    public function __invoke(string $projectUlid): Collection
    {
        $projectId = (new FindPublishedProject)->id($projectUlid);

        if ($projectId === null) {
            /** @var Collection<int, Feedback> $empty */
            $empty = new Collection;

            return $empty;
        }

        return Feedback::query()
            ->published()
            ->where('project_id', $projectId)
            // publicResponses() already orders by responded_at; naming it here
            // keeps the eager load explicit rather than implied.
            ->with('publicResponses')
            ->orderByDesc('created_at')
            ->limit(self::MAX)
            ->get();
    }
}
