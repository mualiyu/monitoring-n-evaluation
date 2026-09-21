<?php

namespace App\Models;

use App\Enums\IndicatorReadingStatus;
use App\Enums\IndicatorTier;
use App\Enums\IndicatorUnit;
use App\Enums\MeasurementFrequency;
use App\Enums\TargetType;
use App\Models\Concerns\BelongsToTenant;
use App\Support\IndicatorAchievement;
use Carbon\CarbonImmutable;
use Database\Factories\IndicatorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An indicator definition — tenant-owned, carrying the full definition sheet
 * from the M&E manual (definition, focus, unit, frequency, data source,
 * collector, means of verification, baseline, target type, SMART statement).
 *
 * `project_id` is nullable: MDA-programme indicators exist that belong to no
 * single project. `result_framework_id` attaches the indicator to the result
 * statement it measures, `parent_indicator_id` to the measure it rolls up
 * into, and `indicator_definition_id` to the state library entry it was
 * instantiated from (null for a locally defined indicator the library has no
 * entry for yet — the Q4 indicator retreat is where those get promoted).
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
 * @property int|null $parent_indicator_id
 * @property int|null $indicator_definition_id
 * @property IndicatorTier|null $tier
 * @property string $name
 * @property string|null $definition
 * @property string|null $focus
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
    'project_id', 'result_framework_id', 'parent_indicator_id',
    'indicator_definition_id', 'tier', 'name', 'definition', 'focus', 'unit',
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

    protected static function booted(): void
    {
        static::creating(function (Indicator $indicator): void {
            $indicator->ulid ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'tier' => IndicatorTier::class,
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
     * The result statement this indicator measures.
     *
     * @return BelongsTo<ResultFramework, $this>
     */
    public function resultFramework(): BelongsTo
    {
        return $this->belongsTo(ResultFramework::class);
    }

    /**
     * The state library entry this was instantiated from. Named
     * `libraryDefinition` and not `definition`, because `definition` is a
     * column on this table — the indicator's own definition text.
     *
     * @return BelongsTo<IndicatorDefinition, $this>
     */
    public function libraryDefinition(): BelongsTo
    {
        return $this->belongsTo(IndicatorDefinition::class, 'indicator_definition_id');
    }

    /**
     * The measure this one rolls up into (output → intermediate → PDO).
     *
     * @return BelongsTo<self, $this>
     */
    public function parentIndicator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_indicator_id');
    }

    /** @return HasMany<self, $this> */
    public function childIndicators(): HasMany
    {
        return $this->hasMany(self::class, 'parent_indicator_id');
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

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAtTier(Builder $query, IndicatorTier $tier): Builder
    {
        return $query->where('tier', $tier);
    }

    /**
     * Where this indicator stands against its target — the ONE definition,
     * computed in App\Support\IndicatorAchievement and never re-derived in a
     * Blade file or a dashboard query.
     *
     * Relies on `latestTarget` / `latestCountableReading` being eager-loaded
     * by the caller where it is used in a list (preventLazyLoading is on
     * outside production, so an N+1 here fails loudly rather than quietly).
     */
    public function achievement(): IndicatorAchievement
    {
        return IndicatorAchievement::for(
            $this,
            $this->latestCountableReading?->actual_value,
            $this->latestTarget?->target_value,
        );
    }

    /**
     * The most recent target, whatever its period — what a register row shows
     * when it has room for one number.
     *
     * @return HasOne<IndicatorTarget, $this>
     */
    public function latestTarget(): HasOne
    {
        // ofMany with an explicit tie-break on id: two targets can share a
        // period_end (an annual target and the Q4 milestone that closes on the
        // same day), and "whichever the database happened to return" is not a
        // number to put in front of a governor.
        return $this->hasOne(IndicatorTarget::class)->ofMany(['period_end' => 'max', 'id' => 'max']);
    }

    /**
     * The most recent reading that may be QUOTED. Which readings qualify is a
     * policy decision (`indicators.require_validation_for_dashboards`), so it
     * is answered once, in IndicatorReading::scopeCountable().
     *
     * @return HasOne<IndicatorReading, $this>
     */
    public function latestCountableReading(): HasOne
    {
        // The constraint goes INSIDE ofMany's subquery, not on the outer
        // relation: applied outside, the subquery would pick the latest
        // reading of any status and the outer filter would then discard it,
        // reporting "no data" for an indicator that has a perfectly good
        // validated figure from the period before.
        return $this->hasOne(IndicatorReading::class)->ofMany(
            ['period_end' => 'max', 'id' => 'max'],
            fn (Builder $query) => $query->countable(),
        );
    }

    /**
     * Readings this indicator is still waiting on a reviewer for — what the
     * detail screen and the validation queue both count.
     *
     * @return HasMany<IndicatorReading, $this>
     */
    public function submittedReadings(): HasMany
    {
        return $this->readings()->where('status', IndicatorReadingStatus::Submitted);
    }
}
