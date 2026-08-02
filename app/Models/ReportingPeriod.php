<?php

namespace App\Models;

use App\Enums\ReportingCadence;
use Carbon\CarbonImmutable;
use Database\Factories\ReportingPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One window of the statutory reporting calendar — GLOBAL, and deliberately
 * carrying no tenant_id (progress-reporting.md §1.1).
 *
 * ⚠ Reviewers reliably flag this as a tenancy hole. It is not. The calendar is
 * state-wide by statute: every MDA reports against the same windows, and the
 * compliance league table is only meaningful if its denominator is identical
 * across MDAs. What is tenant-owned is the *obligation* to report against a
 * window (ReportObligation) and the *report* itself (ProgressReport) — both
 * carry tenant_id and both use BelongsToTenant.
 *
 * Openness is DERIVED, never stored: a status column on a statutory deadline
 * needs a cron to stay true, and a stale one is a compliance defect.
 *
 * @property int $id
 * @property string $ulid
 * @property string $code
 * @property ReportingCadence $cadence
 * @property string $label
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $opens_at
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $closes_at
 * @property string $generated_by
 */
#[Fillable([
    'code', 'cadence', 'label', 'period_start', 'period_end',
    'opens_at', 'due_at', 'closes_at', 'generated_by',
])]
class ReportingPeriod extends Model
{
    /** @use HasFactory<ReportingPeriodFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (ReportingPeriod $period): void {
            $period->ulid ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'cadence' => ReportingCadence::class,
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'opens_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'closes_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<ReportObligation, $this> */
    public function obligations(): HasMany
    {
        return $this->hasMany(ReportObligation::class);
    }

    /** @return HasMany<ProgressReport, $this> */
    public function progressReports(): HasMany
    {
        return $this->hasMany(ProgressReport::class);
    }

    /** Submissions have opened and the hard close (if any) has not passed. */
    public function isOpen(?CarbonImmutable $asOf = null): bool
    {
        $asOf ??= CarbonImmutable::now();

        return ! $this->opens_at->isAfter($asOf)
            && ($this->closes_at === null || $this->closes_at->isAfter($asOf));
    }

    public function isUpcoming(?CarbonImmutable $asOf = null): bool
    {
        return $this->opens_at->isAfter($asOf ?? CarbonImmutable::now());
    }

    /** A hard close that has passed — null closes_at never closes. */
    public function isClosed(?CarbonImmutable $asOf = null): bool
    {
        return $this->closes_at !== null && ! $this->closes_at->isAfter($asOf ?? CarbonImmutable::now());
    }

    public function isOverdue(?CarbonImmutable $asOf = null): bool
    {
        return $this->due_at->isBefore($asOf ?? CarbonImmutable::now());
    }

    /**
     * Windows accepting submissions right now — the set
     * reporting:generate-obligations walks.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query, ?CarbonImmutable $asOf = null): Builder
    {
        $asOf ??= CarbonImmutable::now();

        return $query->where('opens_at', '<=', $asOf)
            ->where(fn (Builder $q) => $q->whereNull('closes_at')->orWhere('closes_at', '>', $asOf));
    }
}
