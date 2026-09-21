<?php

namespace App\Models;

use App\Enums\IndicatorReadingStatus;
use App\Enums\ReadingSourceType;
use App\Models\Builders\IndicatorReadingBuilder;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\IndicatorReadingFactory;
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
 * An actual measurement of an indicator for one period — tenant-owned.
 *
 * Submission, validation and publication are three distinct hops with three
 * distinct actors: the collector records and submits, a data-quality reviewer
 * validates, and publication is a further explicit act, so nothing reaches a
 * public surface merely by being entered.
 *
 * `status` and every chain stamp on this model are written ONLY by
 * App\Actions\Indicators\TransitionIndicatorReadingStatus — which is why none
 * of them is fillable and why that class is greppable as the one chokepoint.
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
 * @property int|null $recorded_by_id
 * @property IndicatorReadingStatus $status
 * @property int|null $submitted_by_id
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $validated_by_id
 * @property CarbonImmutable|null $validated_at
 * @property CarbonImmutable|null $published_at
 * @property int|null $published_by_id
 * @property int|null $rejected_by_id
 * @property CarbonImmutable|null $rejected_at
 * @property string|null $rejection_reason
 */
// Guarded by omission: status, the chain stamps (submitted/validated/
// published/rejected) and their actor columns are NOT fillable. The
// transition Action assigns them by forceFill, so no ->update($validated)
// anywhere can walk a figure past data-quality review.
#[Fillable([
    'indicator_id', 'period_start', 'period_end', 'actual_value', 'source_type',
    'collection_method', 'notes', 'recorded_by_id',
])]
class IndicatorReading extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<IndicatorReadingFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * Everything auditable (rules/architecture.md). `logFillable()` covers the
     * figure itself; the explicit list adds back the chain columns that are
     * deliberately NOT fillable — "who put this number past review, and when"
     * is precisely the question a disputed figure provokes.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('indicator_readings')
            ->logFillable()
            ->logOnly([
                'status', 'submitted_by_id', 'submitted_at', 'validated_by_id',
                'validated_at', 'published_at', 'published_by_id',
                'rejected_by_id', 'rejected_at', 'rejection_reason',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

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
            'rejected_at' => 'immutable_datetime',
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
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
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

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_id');
    }

    /** @return HasMany<IndicatorReadingEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(IndicatorReadingEvent::class);
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    public function newEloquentBuilder($query): IndicatorReadingBuilder
    {
        return new IndicatorReadingBuilder($query);
    }

    /**
     * Figures that have cleared data-quality review — the only ones a report
     * or a public surface may quote.
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

    /**
     * Sitting in the Data Quality Reviewer's queue.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAwaitingValidation(Builder $query): Builder
    {
        return $query->where('status', IndicatorReadingStatus::Submitted);
    }

    /**
     * The identity the separation guard weighs: whoever measured the figure,
     * falling back to whoever filed it. A reviewer may be neither.
     */
    /** @return list<int> */
    public function originators(): array
    {
        return array_values(array_filter([$this->recorded_by_id, $this->submitted_by_id]));
    }
}
