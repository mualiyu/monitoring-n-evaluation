<?php

namespace App\Models\Builders;

use App\Enums\IndicatorReadingStatus;
use App\Models\IndicatorReading;
use App\Support\SettingsRepository;
use App\Tenancy\TenantSafeBuilder;

/**
 * The reading query, with `countable()` as a real method rather than a scope.
 *
 * A magic scope is invisible on a bare `Builder`, which matters in exactly one
 * place and it is the place that counts: `Indicator::latestCountableReading`
 * passes a constraint closure to `ofMany()`, and the closure's parameter is
 * typed as the generic builder. Reaching for a first-class callable
 * (`IndicatorReading::countable(...)`) to satisfy static analysis there is a
 * TRAP — a static scope call starts a FRESH query and silently discards the
 * builder it was handed, so the relation quietly stops filtering and the
 * dashboard starts quoting draft figures.
 *
 * With the rule living here, the closure can be typed and the constraint is
 * applied to the query that was actually passed.
 *
 * @extends TenantSafeBuilder<IndicatorReading>
 */
class IndicatorReadingBuilder extends TenantSafeBuilder
{
    /**
     * Readings that may be quoted as fact.
     *
     * Whether an un-reviewed figure counts is a policy decision a state takes
     * for itself (`indicators.require_validation_for_dashboards`), answered
     * here once rather than re-decided by every screen that draws a traffic
     * light. Drafts never count either way: a draft is a working note, not a
     * return.
     */
    public function countable(): self
    {
        if (app(SettingsRepository::class)->bool('indicators', 'require_validation_for_dashboards', true)) {
            return $this->validated();
        }

        return $this->where('status', '!=', IndicatorReadingStatus::Draft);
    }

    /** Validated or published — a figure somebody has stood behind. */
    public function validated(): self
    {
        return $this->whereIn('status', [
            IndicatorReadingStatus::Validated,
            IndicatorReadingStatus::Published,
        ]);
    }
}
