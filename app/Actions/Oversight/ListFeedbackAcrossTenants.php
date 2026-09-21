<?php

namespace App\Actions\Oversight;

use App\Enums\FeedbackStatus;
use App\Models\Feedback;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The state-wide moderation queue behind oversight /feedback.
 *
 * `feedback` is global, so the TABLE needs no bypass — but the eager load of
 * `project` (and through it the owning MDA) is a read of a tenant-owned model
 * with no tenant bound, which is exactly the cross-MDA privilege that belongs
 * here in app/Actions/Oversight/, next to the authorization check that
 * justifies it.
 *
 * This queue is wider than any MDA's in one specific way that matters:
 * feedback with no project attached appears ONLY here. A citizen who wrote
 * about "the road by the market" without picking a project from the list still
 * gets read — by the secretariat, which is the office that can work out whose
 * road it is.
 */
class ListFeedbackAcrossTenants
{
    /**
     * @param  array{status?: FeedbackStatus|null, search?: string|null, flagged?: bool, unattached?: bool, tenant?: Tenant|null}  $filters
     * @return LengthAwarePaginator<int, Feedback>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $this->assertAuthorized($actor);

        return app(CurrentTenant::class)->bypass(
            fn (): LengthAwarePaginator => $this->query($filters)->paginate($perPage),
        );
    }

    /**
     * @return array<string, int>
     */
    public function counts(User $actor): array
    {
        $this->assertAuthorized($actor);

        /** @var array<string, int> $counts */
        $counts = Feedback::query()
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

    /** Resolve one row for a write, with its project loaded across tenancy. */
    public function find(User $actor, string $ulid): ?Feedback
    {
        $this->assertAuthorized($actor);

        return app(CurrentTenant::class)->bypass(
            fn (): ?Feedback => $this->query()->where('ulid', $ulid)->first(),
        );
    }

    private function assertAuthorized(User $actor): void
    {
        if (! $actor->holdsGlobalPermission('feedback.view')) {
            throw new AuthorizationException('Reading stakeholder feedback across MDAs requires oversight authority.');
        }
    }

    /**
     * @param  array{status?: FeedbackStatus|null, search?: string|null, flagged?: bool, unattached?: bool, tenant?: Tenant|null}  $filters
     * @return Builder<Feedback>
     */
    private function query(array $filters = []): Builder
    {
        return Feedback::query()
            ->with([
                'project:id,ulid,title,tenant_id,published_at',
                'project.tenant:id,name,slug',
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
                $filters['unattached'] ?? false,
                fn (Builder $query) => $query->whereNull('project_id'),
            )
            ->when(
                ($filters['tenant'] ?? null) instanceof Tenant,
                // Through the relation, never a hand-written tenant clause.
                fn (Builder $query) => $query->whereHas(
                    'project',
                    fn (Builder $project) => $project->whereBelongsTo($filters['tenant']),
                ),
            )
            ->when(
                ($filters['search'] ?? null) !== null && $filters['search'] !== '',
                fn (Builder $query) => $query->where(
                    fn (Builder $match) => $match
                        ->where('subject', 'like', '%'.$filters['search'].'%')
                        ->orWhere('body', 'like', '%'.$filters['search'].'%'),
                ),
            )
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [FeedbackStatus::Pending->value])
            ->orderBy('created_at');
    }
}
