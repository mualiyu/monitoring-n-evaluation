<?php

namespace App\Actions\Oversight;

use App\Enums\ProgressReportStatus;
use App\Models\ProgressReport;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * How many returns are sitting unapproved across every MDA — the number on the
 * oversight sidebar's "Reports awaiting review" badge.
 *
 * A sidebar badge renders on EVERY page of the surface, so two properties
 * matter more than they would on a screen: it is one indexed COUNT (served by
 * `(tenant_id, status)`), and it is cached for a minute. A nav badge that is
 * sixty seconds stale is fine; a nav badge that costs a cross-MDA scan per
 * page view is not.
 *
 * Like every other cross-tenant read in the module this lives in
 * app/Actions/Oversight — the only place `withoutTenancy()` is sanctioned —
 * and it reads authority from the GLOBAL permission team. It returns null
 * rather than throwing for a user without that authority: this is chrome, and
 * a layout is the wrong place to raise an authorization exception. The screens
 * behind the badge do throw.
 */
class CountReportsAwaitingReview
{
    public const CACHE_KEY = 'oversight:reports-awaiting-review:v1';

    public const TTL_SECONDS = 60;

    public function __invoke(User $actor): ?int
    {
        if (! $actor->holdsGlobalPermission('oversight.compliance.view')) {
            return null;
        }

        return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, fn (): int => ProgressReport::query()
            ->withoutTenancy()
            ->whereIn('status', [
                ProgressReportStatus::Submitted->value,
                ProgressReportStatus::Reviewed->value,
            ])
            ->count());
    }
}
