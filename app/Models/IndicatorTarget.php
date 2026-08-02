<?php

namespace App\Models;

use App\Enums\MeasurementFrequency;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\IndicatorTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * What an indicator is expected to reach in one period — tenant-owned.
 *
 * The indicator carries the target *type* (continuous / time-bound /
 * percentage achievement); this table carries the numbers, one row per period,
 * so an annual target and its four quarterly milestones coexist without
 * overwriting each other.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $indicator_id
 * @property MeasurementFrequency $period_type
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property string $target_value
 * @property string|null $notes
 */
#[Fillable(['indicator_id', 'period_type', 'period_start', 'period_end', 'target_value', 'notes'])]
class IndicatorTarget extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<IndicatorTargetFactory> */
    use HasFactory, LogsActivity;

    /**
     * Everything auditable (rules/architecture.md). A target quietly lowered to
     * meet the actual is the classic M&E fabrication; the before/after pair on
     * `target_value` is what makes it visible after the fact.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('indicator_targets')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'period_type' => MeasurementFrequency::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'target_value' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Indicator, $this> */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }
}
