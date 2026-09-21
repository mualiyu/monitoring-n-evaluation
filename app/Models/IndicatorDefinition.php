<?php

namespace App\Models;

use App\Enums\IndicatorTier;
use App\Enums\IndicatorUnit;
use App\Enums\MeasurementFrequency;
use App\Enums\TargetType;
use Carbon\CarbonImmutable;
use Database\Factories\IndicatorDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A reusable indicator definition from the STATE library — GLOBAL reference
 * data, no tenant_id, exactly like Sector and FundingSource.
 *
 * This is the manual's "predetermined indicator list" (digest §4): the
 * secretariat consolidates every MDA's annual return against one list, which
 * only works if "classrooms completed and handed over" means the same thing,
 * in the same unit, at the same frequency, in every ministry.
 *
 * A definition is a TEMPLATE. It carries no baseline, no target and no
 * reading — an MDA instantiates it into its own tenant-scoped Indicator, and
 * that is where the numbers live. Retirement is is_active = false, never
 * deletion: instantiated indicators hold a restrictOnDelete FK back here.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $definition
 * @property string|null $focus
 * @property int|null $sector_id
 * @property IndicatorUnit $unit
 * @property MeasurementFrequency $default_measurement_frequency
 * @property TargetType $default_target_type
 * @property IndicatorTier|null $default_tier
 * @property string|null $data_source
 * @property string|null $means_of_verification
 * @property string|null $responsible_collector_text
 * @property string|null $smart_statement
 * @property bool $is_active
 * @property int|null $created_by_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'code', 'name', 'definition', 'focus', 'sector_id', 'unit',
    'default_measurement_frequency', 'default_target_type', 'default_tier',
    'data_source', 'means_of_verification', 'responsible_collector_text',
    'smart_statement', 'is_active', 'created_by_id',
])]
class IndicatorDefinition extends Model
{
    /** @use HasFactory<IndicatorDefinitionFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'unit' => IndicatorUnit::class,
            'default_measurement_frequency' => MeasurementFrequency::class,
            'default_target_type' => TargetType::class,
            'default_tier' => IndicatorTier::class,
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Everything auditable. A library entry is state-wide: rewording the
     * definition of a measure every MDA already reports against changes what
     * years of figures mean, which is precisely a thing an auditor asks about.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('indicator_definitions')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Sector, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * Every MDA indicator instantiated from this definition. Reading this
     * relation from tenant code returns only the bound MDA's own rows — the
     * TenantScope on Indicator applies to the relation query like any other.
     *
     * @return HasMany<Indicator, $this>
     */
    public function indicators(): HasMany
    {
        return $this->hasMany(Indicator::class);
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
     * The template values an instantiation copies onto a new Indicator. Kept
     * here rather than in the Action so the library entry itself states what
     * "instantiate" means, and so the screens can preview it.
     *
     * @return array<string, mixed>
     */
    public function templateAttributes(): array
    {
        return [
            'indicator_definition_id' => $this->id,
            'name' => $this->name,
            'definition' => $this->definition,
            'focus' => $this->focus,
            'unit' => $this->unit,
            'measurement_frequency' => $this->default_measurement_frequency,
            'target_type' => $this->default_target_type,
            'tier' => $this->default_tier,
            'data_source' => $this->data_source,
            'means_of_verification' => $this->means_of_verification,
            'responsible_collector_text' => $this->responsible_collector_text,
            'smart_justification' => $this->smart_statement,
        ];
    }
}
