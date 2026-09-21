<?php

namespace App\Actions\Portal;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectLocation;
use App\Support\Money;
use App\Support\Publishing\PublicProjectPayload;
use App\Tenancy\PortalRead;
use Illuminate\Database\Eloquent\Builder;

/**
 * The counters on the portal landing page: published projects, published
 * contract value, how many are finished, how many LGAs are covered.
 *
 * Every figure is computed over PUBLISHED rows only, so the headline number a
 * citizen quotes back at a commissioner is the same number the portal would
 * let them drill into. A summary counting the whole portfolio while the list
 * showed a published subset would be a transparency claim the platform cannot
 * support.
 *
 * Deliberately NOT cached. The oversight portfolio caches its aggregate for
 * five minutes and that is right there — but here a stale figure would keep
 * counting a project the state withdrew, on the one surface where "remove it
 * now" has to mean now. Caching this becomes correct the day PublishProject
 * and UnpublishProject both bust the key; until then, two indexed aggregates
 * per landing-page hit is the cheaper mistake.
 */
class BuildPortalSummary
{
    /** @return array{projects: int, contract_value: Money, completed: int, lgas: int} */
    public function __invoke(): array
    {
        return (new PortalRead)(function (): array {
            $finished = [
                ProjectStatus::Completed->value,
                ProjectStatus::Certified->value,
                ProjectStatus::Closed->value,
            ];

            // Three figures in one round trip, the way the portfolio summary
            // does it — this is the most-hit unauthenticated page on the
            // platform and every extra query is served to strangers.
            $totals = PublicProjectPayload::publishedOnly(Project::query())
                ->toBase()
                ->selectRaw('COUNT(*) as aggregate_count')
                ->selectRaw('COALESCE(SUM(contract_value_total), 0) as aggregate_value')
                ->selectRaw(
                    'SUM(CASE WHEN status IN (?, ?, ?) THEN 1 ELSE 0 END) as aggregate_completed',
                    $finished,
                )
                ->first();

            return [
                'projects' => (int) ($totals->aggregate_count ?? 0),
                'contract_value' => $this->money($totals->aggregate_value ?? null),
                'completed' => (int) ($totals->aggregate_completed ?? 0),
                // DISTINCT lga_id over sites of published projects only: a
                // 12-site project must not count as 12 LGAs, and an
                // unpublished project's site must not widen the claim.
                'lgas' => ProjectLocation::query()
                    ->whereNotNull('lga_id')
                    ->whereHas('project', function (Builder $project): void {
                        /** @var Builder<Project> $project */
                        PublicProjectPayload::publishedOnly($project);
                    })
                    ->distinct()
                    ->count('lga_id'),
            ];
        });
    }

    /**
     * SUM() over a DECIMAL column comes back as a string on MySQL and a float
     * on SQLite. The float stops here, at the boundary, exactly as MoneyCast
     * does it for a column read.
     */
    private function money(mixed $value): Money
    {
        return match (true) {
            $value === null => Money::zero(),
            is_float($value) => Money::fromDecimalString(sprintf('%.2F', $value)),
            default => Money::fromDecimalString((string) $value),
        };
    }
}
