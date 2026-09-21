<?php

namespace App\Actions\Oversight;

use App\Models\Indicator;
use App\Models\IndicatorReading;
use App\Models\IndicatorTarget;
use App\Models\ReportingPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SettingsRepository;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Performance against the predetermined indicator list, across every MDA
 * (manual digest §2, §4 — the measurement the Annual Performance Report is
 * built on).
 *
 * ONE ROW PER READING, not per indicator: an indicator measured quarterly has
 * four answers in a year and the state is entitled to see all four. Each row
 * carries its own target (matched on the exact window, which
 * `indicator_targets` is uniquely keyed on) and the achievement band that
 * target puts it in.
 *
 * THE SINGLE SOURCE. Both the report builder's `indicators` dataset and the
 * consolidation's per-MDA indicator counts fold these same rows — the counts
 * are a GROUP BY done in PHP over the list, never a second SQL path that could
 * quietly disagree with the list it summarises.
 *
 * Cross-tenant reads are an explicit oversight privilege, so the bypass lives
 * next to the authority check that justifies it, and authority is read from
 * the GLOBAL permission team (rules/tenancy.md, enforced by the discipline
 * test). The bypass has to wrap the EXECUTION, not the builder: the eager
 * loads run as separate queries and would each meet the fail-closed scope with
 * no tenant bound.
 */
class AggregateForIndicatorPerformance
{
    /** Bands an achievement percentage can fall into. */
    public const BAND_ON_TRACK = 'on_track';

    public const BAND_AT_RISK = 'at_risk';

    public const BAND_OFF_TRACK = 'off_track';

    public const BAND_NO_TARGET = 'no_target';

    /**
     * @param  array{tenant?: Tenant|null, indicator?: Indicator|null, search?: string|null, band?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function __invoke(User $actor, ?ReportingPeriod $period = null, array $filters = [], ?int $limit = null): array
    {
        $rows = [];

        $this->chunk($actor, $period, $filters, function (array $chunk) use (&$rows, $limit): bool {
            foreach ($chunk as $row) {
                $rows[] = $row;

                if ($limit !== null && count($rows) >= $limit) {
                    return false; // stop chunking — the preview has enough
                }
            }

            return true;
        });

        return $rows;
    }

    /**
     * The same rows, streamed. `$callback` returning false stops the walk, so
     * a 25-row preview costs one page rather than a full scan.
     *
     * @param  array{tenant?: Tenant|null, indicator?: Indicator|null, search?: string|null, band?: string|null}  $filters
     * @param  callable(list<array<string, mixed>>): bool  $callback
     */
    public function chunk(
        User $actor,
        ?ReportingPeriod $period,
        array $filters,
        callable $callback,
        int $size = 500,
    ): void {
        $this->authorize($actor);

        $bands = $this->bands();
        $validatedOnly = app(SettingsRepository::class)
            ->bool('indicators', 'require_validation_for_dashboards', true);
        $band = $filters['band'] ?? null;

        app(CurrentTenant::class)->bypass(function () use ($period, $filters, $callback, $size, $bands, $validatedOnly, $band): void {
            $names = Tenant::query()->get(['id', 'name', 'slug'])->keyBy('id');

            $this->query($period, $filters)
                ->chunkById($size, function (Collection $readings) use ($callback, $names, $bands, $validatedOnly, $band, $period): bool {
                    $rows = [];

                    foreach ($readings as $reading) {
                        $row = $this->shape($reading, $names, $bands, $validatedOnly, $period);

                        // A band filter narrows the rows AFTER the band has
                        // been computed: the band is a derived judgement about
                        // a reading against its target, and there is no column
                        // to push it down to.
                        if ($band !== null && $band !== '' && $row['band'] !== $band) {
                            continue;
                        }

                        $rows[] = $row;
                    }

                    return $callback($rows);
                });
        });
    }

    private function authorize(User $actor): void
    {
        // Read from the GLOBAL team: oversight authority is never granted
        // inside an MDA workspace, so an MDA admin holds no cross-MDA
        // indicator permission however the request arrived.
        if (! $actor->holdsGlobalPermission('oversight.reports.view')) {
            throw new AuthorizationException('Reading indicator performance across MDAs requires oversight authority.');
        }
    }

    /**
     * The achievement band boundaries, in percent of target. Read through the
     * settings chain (tenant override → instance setting → config default),
     * never as literals: what counts as "on track" is a policy decision a
     * state takes for itself.
     *
     * @return array{on_track: int, at_risk: int}
     */
    private function bands(): array
    {
        $settings = app(SettingsRepository::class);

        return [
            'on_track' => $settings->int('indicators', 'on_track_percent', 90),
            'at_risk' => $settings->int('indicators', 'at_risk_percent', 70),
        ];
    }

    /**
     * @param  array{tenant?: Tenant|null, indicator?: Indicator|null, search?: string|null, band?: string|null}  $filters
     * @return Builder<IndicatorReading>
     */
    private function query(?ReportingPeriod $period, array $filters): Builder
    {
        return IndicatorReading::query()
            ->with([
                'indicator:id,tenant_id,project_id,name,tier,unit,baseline_value,measurement_frequency',
                'indicator.project:id,title,reference',
            ])
            // The reading's own target for its own window. A correlated
            // subquery rather than a join: indicator_targets is uniquely keyed
            // on (indicator_id, period_start, period_end), so this is an index
            // seek per row and it cannot multiply rows the way a join on a
            // non-unique key silently would.
            ->addSelect(['matched_target_value' => IndicatorTarget::query()
                ->whereColumn('indicator_targets.indicator_id', 'indicator_readings.indicator_id')
                ->whereColumn('indicator_targets.period_start', 'indicator_readings.period_start')
                ->whereColumn('indicator_targets.period_end', 'indicator_readings.period_end')
                ->select('target_value')
                ->limit(1),
            ])
            ->addSelect('indicator_readings.*')
            ->when(
                $period instanceof ReportingPeriod,
                // Readings whose measurement window sits INSIDE the reporting
                // window. A monthly reading counts towards the annual report;
                // an annual reading does not count towards one month of it.
                fn (Builder $query) => $query
                    ->where('period_start', '>=', $period?->period_start)
                    ->where('period_end', '<=', $period?->period_end),
            )
            ->when(
                ($filters['tenant'] ?? null) instanceof Tenant,
                // whereBelongsTo(), never a hand-written tenant clause —
                // "except in oversight code" is exactly the exception that
                // stops being read as an exception.
                fn (Builder $query) => $query->whereBelongsTo($filters['tenant']),
            )
            ->when(
                ($filters['indicator'] ?? null) instanceof Indicator,
                fn (Builder $query) => $query->whereBelongsTo($filters['indicator']),
            )
            ->when(
                ($filters['search'] ?? null) !== null && $filters['search'] !== '',
                fn (Builder $query) => $query->whereIn(
                    'indicator_id',
                    $this->indicatorMatches((string) $filters['search'])
                ),
            );
    }

    /**
     * Indicators matching the search box, as a subquery inside the same
     * bypass — one scope decision, not a second read that quietly disagrees.
     *
     * @return Builder<Indicator>
     */
    private function indicatorMatches(string $search): Builder
    {
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($search)).'%';

        return Indicator::query()->where('name', 'like', $term)->select('id');
    }

    /**
     * @param  Collection<int, Tenant>  $names
     * @param  array{on_track: int, at_risk: int}  $bands
     * @return array<string, mixed>
     */
    private function shape(
        IndicatorReading $reading,
        Collection $names,
        array $bands,
        bool $validatedOnly,
        ?ReportingPeriod $period,
    ): array {
        $indicator = $reading->indicator;
        $tenantId = (int) $reading->tenant_id;

        /** @var string|null $rawTarget */
        $rawTarget = $reading->getAttribute('matched_target_value');
        $target = $rawTarget === null ? null : (float) $rawTarget;
        $actual = (float) $reading->actual_value;

        // A RATIO, not a measurement — the one division in this Action, and it
        // produces no stored value. A target of zero has no meaningful
        // achievement percentage and gets null rather than a division by zero
        // dressed up as 0%.
        $achievement = $target !== null && $target != 0.0
            ? round($actual * 100 / $target, 2)
            : null;

        return [
            'tenant_id' => $tenantId,
            'entity' => $names[$tenantId]->name ?? null,
            'entity_slug' => $names[$tenantId]->slug ?? null,
            'indicator' => $indicator->name,
            'indicator_ulid' => $indicator->ulid,
            'tier' => $indicator->tier?->label(),
            'unit' => $indicator->unit->label(),
            'project' => $indicator->project?->title,
            'window' => $period?->label ?? $reading->period_start->format('M Y'),
            'period_start' => $reading->period_start->toDateString(),
            'period_end' => $reading->period_end->toDateString(),
            'baseline' => $indicator->baseline_value,
            'target' => $rawTarget,
            'actual' => $reading->actual_value,
            'achievement' => $achievement,
            'band' => $this->band($achievement, $bands),
            'reading_status' => $reading->status->label(),
            // Whether this reading may move a dashboard. When the instance
            // requires data-quality validation, an unvalidated figure is
            // reported but not counted — which is the whole point of having a
            // Data Quality Reviewer role.
            'counts_towards_achievement' => ! $validatedOnly || $reading->status->isValidated(),
            'is_validated' => $reading->status->isValidated(),
        ];
    }

    /**
     * @param  array{on_track: int, at_risk: int}  $bands
     */
    private function band(?float $achievement, array $bands): string
    {
        return match (true) {
            $achievement === null => self::BAND_NO_TARGET,
            $achievement >= $bands['on_track'] => self::BAND_ON_TRACK,
            $achievement >= $bands['at_risk'] => self::BAND_AT_RISK,
            default => self::BAND_OFF_TRACK,
        };
    }

    /** Human labels for the bands, for filter selects and table cells. */
    public static function bandLabel(string $band): string
    {
        return match ($band) {
            self::BAND_ON_TRACK => __('On track'),
            self::BAND_AT_RISK => __('At risk'),
            self::BAND_OFF_TRACK => __('Off track'),
            default => __('No target set'),
        };
    }
}
