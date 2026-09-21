<?php

namespace App\Actions\Feedback;

use App\Enums\FeedbackStatus;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * An MDA's moderation queue: feedback about ITS projects, and nothing else.
 *
 * The tenancy narrowing is `whereHas('project')` and nothing more. `feedback`
 * is a global table with no tenancy column of its own, so the scoping comes
 * from the relation: the subquery runs against Project, whose TenantScope
 * confines it to the resolved workspace. That is why this module needs no
 * hand-written tenant clause anywhere — if it ever seems to, the answer is a
 * relation, not a where.
 *
 * Feedback with a null project is INVISIBLE here, by the same mechanism and
 * deliberately: an unattached public comment has no MDA anchor, and it belongs
 * to the state secretariat's queue (ListFeedbackAcrossTenants), not to
 * whichever ministry happens to look first.
 */
class ListFeedbackForTenant
{
    /**
     * @param  array{status?: FeedbackStatus|null, search?: string|null, flagged?: bool}  $filters
     * @return LengthAwarePaginator<int, Feedback>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        Gate::forUser($actor)->authorize('viewAny', Feedback::class);

        return $this->query($filters)->paginate($perPage);
    }

    /**
     * Counts per moderation state, for the summary row. One grouped query —
     * a moderation queue is opened dozens of times a day.
     *
     * @return array<string, int>
     */
    public function counts(User $actor): array
    {
        Gate::forUser($actor)->authorize('viewAny', Feedback::class);

        /** @var array<string, int> $counts */
        $counts = Feedback::query()
            ->whereHas('project')
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $totals = [];

        foreach (FeedbackStatus::cases() as $case) {
            $totals[$case->value] = (int) ($counts[$case->value] ?? 0);
        }

        return $totals;
    }

    /** Resolve one row for a write, under the same tenancy narrowing. */
    public function find(User $actor, string $ulid): ?Feedback
    {
        Gate::forUser($actor)->authorize('viewAny', Feedback::class);

        return $this->query()->where('ulid', $ulid)->first();
    }

    /**
     * @param  array{status?: FeedbackStatus|null, search?: string|null, flagged?: bool}  $filters
     * @return Builder<Feedback>
     */
    private function query(array $filters = []): Builder
    {
        return Feedback::query()
            ->whereHas('project')
            ->with([
                'project:id,ulid,title,tenant_id,published_at',
                'moderatedBy:id,name',
                'responses.respondedBy:id,name',
            ])
            ->when(
                ($filters['status'] ?? null) instanceof FeedbackStatus,
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                $filters['flagged'] ?? false,
                fn (Builder $query) => $query->where('flagged_as_spam', true),
            )
            ->when(
                ($filters['search'] ?? null) !== null && $filters['search'] !== '',
                fn (Builder $query) => $query->where(
                    fn (Builder $match) => $match
                        ->where('subject', 'like', '%'.$filters['search'].'%')
                        ->orWhere('body', 'like', '%'.$filters['search'].'%'),
                ),
            )
            // Unmoderated first, oldest first within that: a queue is a
            // promise about response time, and the oldest unanswered citizen
            // has been waiting longest.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [FeedbackStatus::Pending->value])
            ->orderBy('created_at');
    }
}
