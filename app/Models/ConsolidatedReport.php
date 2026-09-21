<?php

namespace App\Models;

use App\Enums\ConsolidatedReportType;
use App\Enums\ConsolidationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ConsolidatedReportFactory;
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
 * The secretariat's roll-up of every MDA's returns for one window — the state
 * artifact the Commissioner is handed (manual digest §1, §4).
 *
 * ⚠ GLOBAL, and deliberately carrying no tenant_id. Reviewers reliably flag
 * this as a tenancy hole; it is the opposite. A consolidation spans every MDA
 * by definition, so a tenancy key would both misstate whose record it is and
 * hide 39 of 40 entities' figures from the state that compiled them. The
 * INPUTS are tenant-owned and scoped; the cross-tenant reads that produce this
 * row happen in app/Actions/Oversight/ behind an explicit bypass.
 *
 * Guarded-by-omission, exactly as ProgressReport is: `status` is written ONLY
 * by App\Actions\Consolidation\TransitionConsolidationStatus, and with it the
 * whole chain (`compiled_*`, `submitted_*`, `approved_*`, `published_*`,
 * `returned_*`) plus `snapshot`, `totals` and the roll-up counters. None of
 * them is fillable, so no ->update($validated) can reach them.
 *
 * @property int $id
 * @property string $ulid
 * @property string $reference
 * @property string $title
 * @property ConsolidatedReportType $type
 * @property ConsolidationStatus $status
 * @property int $reporting_period_id
 * @property array<string, mixed>|null $snapshot
 * @property CarbonImmutable|null $snapshot_taken_at
 * @property array<string, mixed>|null $totals
 * @property int $entity_count
 * @property int $denominator
 * @property int $created_by_id
 * @property int|null $compiled_by_id
 * @property CarbonImmutable|null $compiled_at
 * @property int|null $submitted_by_id
 * @property CarbonImmutable|null $submitted_at
 * @property int|null $approved_by_id
 * @property CarbonImmutable|null $approved_at
 * @property int|null $published_by_id
 * @property CarbonImmutable|null $published_at
 * @property int|null $returned_by_id
 * @property CarbonImmutable|null $returned_at
 * @property string|null $return_reason
 */
#[Fillable(['reference', 'title', 'type', 'reporting_period_id', 'created_by_id'])]
class ConsolidatedReport extends Model
{
    /** @use HasFactory<ConsolidatedReportFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (ConsolidatedReport $report): void {
            $report->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Everything auditable (rules/architecture.md). `snapshot` is logged by
     * name and not by value — the frozen figures are megabytes of JSON and
     * they already live, immutably, on the row itself; what an auditor needs
     * from the log is WHEN it was taken and by whom.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('consolidation')
            ->logFillable()
            ->logOnly([
                'status', 'compiled_by_id', 'compiled_at', 'submitted_by_id', 'submitted_at',
                'approved_by_id', 'approved_at', 'published_by_id', 'published_at',
                'returned_by_id', 'returned_at', 'return_reason', 'snapshot_taken_at',
                'entity_count', 'denominator',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'type' => ConsolidatedReportType::class,
            'status' => ConsolidationStatus::class,
            'snapshot' => 'array',
            'totals' => 'array',
            'entity_count' => 'integer',
            'denominator' => 'integer',
            'snapshot_taken_at' => 'immutable_datetime',
            'compiled_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<ReportingPeriod, $this> */
    public function reportingPeriod(): BelongsTo
    {
        return $this->belongsTo(ReportingPeriod::class);
    }

    /** @return HasMany<ConsolidatedReportSection, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(ConsolidatedReportSection::class)->orderBy('position');
    }

    /** @return HasMany<ConsolidatedReportEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(ConsolidatedReportEntry::class);
    }

    /** @return HasMany<ConsolidationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ConsolidationEvent::class);
    }

    /** @return HasMany<ReportExport, $this> */
    public function exports(): HasMany
    {
        return $this->hasMany(ReportExport::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function compiledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'compiled_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_id');
    }

    /** Whether the secretariat may still recompile and edit the narrative. */
    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }

    public function hasFigures(): bool
    {
        return $this->entity_count > 0;
    }

    /**
     * THE figures this consolidation states — the frozen snapshot once it has
     * been signed, the live roll-up before then.
     *
     * One accessor, because a screen reading live totals off an approved
     * report while the PDF reads the snapshot is precisely the defect the
     * snapshot exists to prevent.
     *
     * @return array<string, mixed>
     */
    public function figures(): array
    {
        if ($this->status->isFrozen() && is_array($this->snapshot)) {
            $totals = $this->snapshot['totals'] ?? null;

            return is_array($totals) ? $totals : [];
        }

        return $this->totals ?? [];
    }

    /**
     * THE per-entity figures this consolidation states — the frozen snapshot
     * once it has been signed, the live entries before then.
     *
     * The sibling of figures(), and for the same reason: an approved report
     * whose annex re-reads the live tables would quietly restate itself every
     * time an MDA edited an old return, which is exactly what the snapshot
     * exists to prevent.
     *
     * @return list<array<string, mixed>>
     */
    public function entityFigures(): array
    {
        if ($this->status->isFrozen() && is_array($this->snapshot)) {
            $entities = $this->snapshot['entities'] ?? null;

            return is_array($entities) ? array_values($entities) : [];
        }

        return $this->loadMissing('entries.subject')->entries
            ->map(fn (ConsolidatedReportEntry $entry): array => [
                'subject_tenant_id' => $entry->subject_tenant_id,
                'entity' => $entry->subject?->name,
                'entity_slug' => $entry->subject?->slug,
                'projects_total' => $entry->projects_total,
                'projects_by_status' => $entry->projects_by_status ?? [],
                'contract_value_total' => $entry->contract_value_total->toDecimalString(),
                'expenditure_total' => $entry->expenditure_total->toDecimalString(),
                'physical_progress_avg' => $entry->physical_progress_avg,
                'obligations_expected' => $entry->obligations_expected,
                'obligations_submitted' => $entry->obligations_submitted,
                'obligations_on_time' => $entry->obligations_on_time,
                'obligations_missed' => $entry->obligations_missed,
                'obligations_waived' => $entry->obligations_waived,
                'on_time_rate' => $entry->onTimeRate(),
                'reports_filed' => $entry->reports_filed,
                'reports_approved' => $entry->reports_approved,
                'period_expenditure_total' => $entry->period_expenditure_total->toDecimalString(),
                'indicators_reported' => $entry->indicators_reported,
                'indicators_on_track' => $entry->indicators_on_track,
                'indicators_at_risk' => $entry->indicators_at_risk,
                'indicators_off_track' => $entry->indicators_off_track,
                'readings_validated' => $entry->readings_validated,
                'figures' => $entry->figures,
            ])
            ->values()
            ->all();
    }

    /**
     * The narrative as it stands — frozen chapters once signed, live rows
     * before then. Same rule as figures() and entityFigures().
     *
     * @return list<array{key: string, heading: string, body: string|null}>
     */
    public function narrative(): array
    {
        if ($this->status->isFrozen() && is_array($this->snapshot)) {
            $sections = $this->snapshot['sections'] ?? null;

            if (is_array($sections)) {
                /** @var list<array{key: string, heading: string, body: string|null}> $frozen */
                $frozen = array_values($sections);

                return $frozen;
            }
        }

        return $this->loadMissing('sections')->sections
            ->map(fn (ConsolidatedReportSection $section): array => [
                'key' => $section->key,
                'heading' => $section->heading,
                'body' => $section->body,
            ])
            ->values()
            ->all();
    }

    /**
     * How much of the state actually answered: entities with figures over
     * entities expected to report. The manual's compliance question asked of
     * the consolidation itself — a roll-up covering 6 of 40 MDAs is a finding,
     * not a report.
     */
    public function coverageRate(): ?float
    {
        return $this->denominator > 0
            ? round($this->entity_count * 100 / $this->denominator, 1)
            : null;
    }

    /**
     * Consolidations released to readers outside the secretariat.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ConsolidationStatus::Published);
    }
}
