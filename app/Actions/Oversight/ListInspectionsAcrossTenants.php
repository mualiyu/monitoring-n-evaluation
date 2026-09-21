<?php

namespace App\Actions\Oversight;

use App\Enums\InspectionOutcome;
use App\Enums\InspectionStatus;
use App\Enums\InspectionType;
use App\Models\Project;
use App\Models\SiteInspection;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cross-MDA inspection board behind oversight /inspections — the ONLY
 * tenancy bypass in this module. A cross-tenant read is an explicit oversight
 * privilege, not a convenience (rules/tenancy.md, enforced by the discipline
 * sweep, which permits `withoutTenancy(`/`->bypass(` only here and in
 * app/Livewire/Oversight).
 *
 * THE PERMISSION IS RE-CHECKED IN THE GLOBAL TEAM BEFORE THE BYPASS, and the
 * order is the whole point: `$user->can()` answers in whatever permission team
 * happens to be bound, and on this surface that is the global team — but an
 * Action is not entitled to assume its caller's context. `holdsGlobalPermission()`
 * asks the question that cannot be answered by accident, and it is asked
 * BEFORE any scope comes off. An MDA officer holds `inspections.view` in their
 * own workspace's team and nothing in the global one, so they are refused here
 * even if they reach this code with a tenant bound.
 *
 * Eager-loads what the row actually renders: tenant, project, lead inspector,
 * and a finding count. A cross-MDA list is exactly the query where an N+1
 * becomes hundreds of round trips.
 *
 * Read-only by construction: there is no oversight Action in this module that
 * writes an inspection. The state observes MDA field work; it does not conduct
 * it through the same screen.
 *
 * @see ListProjectsAcrossTenants — the pattern this follows
 */
class ListInspectionsAcrossTenants
{
    /**
     * @param  array{tenant?: Tenant|null, status?: InspectionStatus|null, type?: InspectionType|null, outcome?: InspectionOutcome|null, search?: string|null, overdue?: bool, escalated?: bool}  $filters
     * @return LengthAwarePaginator<int, SiteInspection>
     */
    public function __invoke(User $actor, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        if (! $actor->holdsGlobalPermission('inspections.view')) {
            throw new AuthorizationException('Viewing inspections across MDAs requires oversight authority.');
        }

        // bypass(), not just withoutTenancy(): the scope removal applies to
        // the query it is called on, while the eager loads below are separate
        // queries against tenant-owned models (responses) that would each hit
        // the fail-closed scope with no tenant bound. bypass() is the
        // acknowledgment that this whole read is cross-tenant — which is what
        // the oversight surface is for, and why it is confined to this
        // directory.
        return app(CurrentTenant::class)->bypass(fn (): LengthAwarePaginator => $this->query($filters, $perPage));
    }

    /**
     * @param  array{tenant?: Tenant|null, status?: InspectionStatus|null, type?: InspectionType|null, outcome?: InspectionOutcome|null, search?: string|null, overdue?: bool, escalated?: bool}  $filters
     * @return LengthAwarePaginator<int, SiteInspection>
     */
    private function query(array $filters, int $perPage): LengthAwarePaginator
    {
        return SiteInspection::query()
            ->with([
                'tenant:id,name,slug',
                'project:id,ulid,title,reference,sector_id',
                'project.sector:id,name',
                'leadInspector:id,name',
            ])
            ->withCount(['responses as findings_count' => fn (Builder $q) => $q->where('is_finding', true)])
            ->when(
                ($filters['tenant'] ?? null) instanceof Tenant,
                // whereBelongsTo(), not a hand-written where('tenant_id', …).
                // Manual tenant clauses are banned platform-wide, and "except
                // in oversight code" is exactly the exception that stops being
                // read as an exception.
                fn (Builder $query) => $query->whereBelongsTo($filters['tenant']),
            )
            ->when(
                ($filters['status'] ?? null) instanceof InspectionStatus,
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                ($filters['type'] ?? null) instanceof InspectionType,
                fn (Builder $query) => $query->where('type', $filters['type']),
            )
            ->when(
                ($filters['outcome'] ?? null) instanceof InspectionOutcome,
                fn (Builder $query) => $query->where('outcome', $filters['outcome']),
            )
            ->when(
                ($filters['escalated'] ?? false),
                fn (Builder $query) => $query->whereIn('outcome', [
                    InspectionOutcome::MajorIssues,
                    InspectionOutcome::WorkStopped,
                ]),
            )
            ->when(
                ($filters['overdue'] ?? false),
                // Field work that has gone quiet: the visit happened, the
                // deadline passed, no report. The same rule
                // SiteInspection::isReportOverdue() applies to a single row.
                fn (Builder $query) => $query
                    ->whereIn('status', [InspectionStatus::Scheduled, InspectionStatus::InProgress])
                    ->whereNotNull('report_due_at')
                    ->where('report_due_at', '<', now()),
            )
            ->when(
                ($filters['search'] ?? null) !== null && $filters['search'] !== '',
                fn (Builder $query) => $query->whereIn(
                    'project_id',
                    // A subquery against a tenant-owned model, run inside the
                    // bypass above — so the LIKE spans every MDA exactly as
                    // the outer query does, rather than silently matching none.
                    Project::query()
                        ->where(fn (Builder $match) => $match
                            ->where('title', 'like', '%'.$filters['search'].'%')
                            ->orWhere('reference', 'like', '%'.$filters['search'].'%'))
                        ->select('id'),
                ),
            )
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
