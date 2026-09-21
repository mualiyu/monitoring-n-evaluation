<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ConsolidatedReportSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One narrative chapter of a consolidation — GLOBAL, like its parent.
 *
 * The chapter set comes from ConsolidatedReportType::sectionSkeleton() and is
 * seeded at OpenConsolidation. A section may hold no text, and the PDF says so
 * in as many words: an APR with an empty recommendations chapter is a finding
 * the reader is entitled to see, not something the template should hide.
 *
 * @property int $id
 * @property string $ulid
 * @property int $consolidated_report_id
 * @property string $key
 * @property string $heading
 * @property string|null $body
 * @property int $position
 * @property int|null $updated_by_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['consolidated_report_id', 'key', 'heading', 'body', 'position', 'updated_by_id'])]
class ConsolidatedReportSection extends Model
{
    /** @use HasFactory<ConsolidatedReportSectionFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (ConsolidatedReportSection $section): void {
            $section->ulid ??= (string) Str::ulid();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('consolidation')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<ConsolidatedReport, $this> */
    public function consolidatedReport(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedReport::class);
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    public function isWritten(): bool
    {
        return trim((string) $this->body) !== '';
    }
}
