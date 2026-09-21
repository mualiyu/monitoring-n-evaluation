<?php

namespace App\Policies;

use App\Models\Feedback;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;
use App\Tenancy\CurrentTenant;

/**
 * Permission AND tenant match, like every other policy here — but the tenant
 * match has to be found rather than read.
 *
 * `feedback` is a global table (see its migration: a citizen does not know
 * which MDA owns the clinic they are complaining about). It therefore carries
 * no tenancy column for ChecksTenantAuthority to compare, and passing the
 * Feedback itself to permits() would compare against a null and refuse every
 * MDA moderator on the platform.
 *
 * The anchor is the PROJECT. This policy resolves it and then asks the
 * ordinary question — "this permission, over a record of this workspace" —
 * about that. Three cases, all of them deliberate:
 *
 *  - Project loaded (the list queries eager-load it): compare against it.
 *  - Project not loaded, a tenant bound: re-read it through the tenant scope,
 *    so another MDA's feedback resolves to nothing and falls through to the
 *    oversight branch, which a tenant user cannot satisfy.
 *  - No project at all (unattached feedback, or a purged project): only
 *    oversight authority applies. Unowned public correspondence belongs to the
 *    secretariat.
 *
 * Note that nothing here grants a CONSULTANT anything: they hold none of the
 * feedback permissions by design — a contractor moderating complaints about
 * their own work is exactly the conflict this portal exists to expose.
 */
class FeedbackPolicy
{
    use ChecksTenantAuthority;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'feedback.view');
    }

    public function view(User $user, Feedback $feedback): bool
    {
        return $this->permitsOnAnchor($user, 'feedback.view', $feedback);
    }

    /** Publish, refuse, or mark as spam. */
    public function moderate(User $user, Feedback $feedback): bool
    {
        return $this->permitsOnAnchor($user, 'feedback.moderate', $feedback);
    }

    /** Write an official reply on the thread. */
    public function respond(User $user, Feedback $feedback): bool
    {
        return $this->permitsOnAnchor($user, 'feedback.respond', $feedback);
    }

    private function permitsOnAnchor(User $user, string $permission, Feedback $feedback): bool
    {
        $project = $this->anchor($feedback);

        return $project instanceof Project
            ? $this->permits($user, $permission, $project)
            : $user->holdsGlobalPermission($permission);
    }

    /**
     * The tenancy anchor, resolved without ever lazy-loading: the list
     * screens eager-load `project`, and Model::preventLazyLoading() is on
     * outside production, so an implicit `$feedback->project` here would throw
     * on exactly the page that works in every manual test.
     *
     * The re-read is deliberately confined to a bound tenant context. With no
     * tenant bound (the oversight surface, a console command) the tenant scope
     * would throw rather than answer, and the right answer there is "this is
     * an oversight decision" — which is what returning null produces.
     */
    private function anchor(Feedback $feedback): ?Project
    {
        if ($feedback->relationLoaded('project')) {
            /** @var Project|null $loaded */
            $loaded = $feedback->getRelation('project');

            return $loaded;
        }

        if ($feedback->project_id === null || ! app(CurrentTenant::class)->bound()) {
            return null;
        }

        return Project::query()->whereKey($feedback->project_id)->first();
    }
}
