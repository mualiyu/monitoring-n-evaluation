<?php

namespace App\Models;

use App\Enums\IndicatorReadingStatus;
use App\Enums\ReadingSourceType;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\IndicatorReadingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An actual measurement of an indicator for one period — tenant-owned.
 *
 * Submission, validation and publication are three distinct hops with three
 * distinct actors: the collector submits, a data-quality reviewer validates,
 * and publication is a further explicit act, so nothing reaches a public
 * surface merely by being entered.
 *
 * Readings attach to certified and closed projects too — post-completion
 * monitoring is exactly when outcome indicators get their most useful values.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $indicator_id
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property string $actual_value
 * @property ReadingSourceType $source_type
 * @property string|null $collection_method
 * @property string|null $notes
 * @property IndicatorReadingStatus $status
 * @property int|null $submitted_by_id
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $validated_by_id
 * @property CarbonImmutable|null $validated_at
 * @property CarbonImmutable|null $published_at
 */
#[Fillable([
    'indicator_id', 'period_start', 'period_end', 'actual_value', 'source_type',
    'collection_method', 'notes', 'status', 'submitted_by_id', 'submitted_at',
    'validated_by_id', 'validated_at',
])]
class IndicatorReading extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<IndicatorReadingFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (IndicatorReading $reading): void {
            $reading->ulid ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'actual_value' => 'decimal:4',
            'source_type' => ReadingSourceType::class,
            'status' => IndicatorReadingStatus::class,
            'submitted_at' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Indicator, $this> */
    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by_id');
    }

    /**
     * Figures that have cleared data-quality review — the only ones a report
     * or dashboard may quote.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeValidated(Builder $query): Builder
    {
        return $query->whereIn('status', [
            IndicatorReadingStatus::Validated,
            IndicatorReadingStatus::Published,
        ]);
    }
}
