<?php

namespace App\Models;

use App\Enums\IndicatorUnit;
use App\Enums\MeasurementFrequency;
use App\Enums\TargetType;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\IndicatorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An indicator definition — tenant-owned, carrying the full definition sheet
 * from the M&E manual (definition, unit, frequency, data source, collector,
 * means of verification, baseline, target type, SMART justification).
 *
 * `project_id` is nullable: MDA-programme indicators exist that belong to no
 * single project. `result_framework_id` and `tier` are present and
 * unconstrained so the Phase 2 results framework attaches without migrating
 * readings.
 *
 * BASELINE IS MANDATORY AT ACTIVATION, NOT BY `NOT NULL`. A NOT NULL baseline
 * would force a placeholder into every half-drafted indicator, and a
 * fabricated zero baseline is worse data quality than an explicit null.
 * ActivateIndicator refuses without value + date + source; only active
 * indicators accept readings or appear in reports.
 *
 * Values are decimal(18,4) strings — a measurement does not go through a float.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int|null $project_id
 * @property int|null $result_framework_id
 * @property string|null $tier
 * @property string $name
 * @property string|null $definition
 * @property IndicatorUnit $unit
 * @property MeasurementFrequency $measurement_frequency
 * @property string|null $data_source
 * @property string|null $means_of_verification
 * @property int|null $responsible_collector_id
 * @property string|null $responsible_collector_text
 * @property string|null $baseline_value
 * @property CarbonImmutable|null $baseline_date
 * @property string|null $baseline_source
 * @property TargetType $target_type
 * @property string|null $smart_justification
 * @property bool $is_active
 * @property CarbonImmutable|null $activated_at
 * @property int $created_by_id
 */
#[Fillable([
    'project_id', 'result_framework_id', 'tier', 'name', 'definition', 'unit',
    'measurement_frequency', 'data_source', 'means_of_verification',
    'responsible_collector_id', 'responsible_collector_text', 'baseline_value',
    'baseline_date', 'baseline_source', 'target_type', 'smart_justification',
    'is_active', 'activated_at', 'created_by_id',
])]
class Indicator extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<IndicatorFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /** Result-framework tiers (Phase 2 promotes these to an enum + FK). */
    public const TIERS = ['pdo', 'intermediate', 'output'];

    protected static function booted(): void
    {
        static::creating(function (Indicator $indicator): void {
            $indicator->ulid ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'unit' => IndicatorUnit::class,
            'measurement_frequency' => MeasurementFrequency::class,
            'target_type' => TargetType::class,
            'baseline_value' => 'decimal:4',
            'baseline_date' => 'immutable_date',
            'is_active' => 'boolean',
            'activated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * A baseline that moves after activation rewrites every achievement
     * percentage computed from it — which is precisely why the change has to
     * leave a record.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('indicators')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Null for MDA-programme indicators.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The platform user accountable for collecting this indicator;
     * `responsible_collector_text` names an office when it is not a user.
     *
     * @return BelongsTo<User, $this>
     */
    public function responsibleCollector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_collector_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return HasMany<IndicatorTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(IndicatorTarget::class);
    }

    /** @return HasMany<IndicatorReading, $this> */
    public function readings(): HasMany
    {
        return $this->hasMany(IndicatorReading::class);
    }

    /**
     * The precondition ActivateIndicator enforces: a baseline is a value, a
     * date it was measured on, and where it came from — all three or none.
     */
    public function hasCompleteBaseline(): bool
    {
        return $this->baseline_value !== null
            && $this->baseline_date !== null
            && $this->baseline_source !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
