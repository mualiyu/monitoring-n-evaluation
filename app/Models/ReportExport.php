<?php

namespace App\Models;

use App\Enums\ExportFormat;
use App\Enums\ReportDataset;
use Carbon\CarbonImmutable;
use Database\Factories\ReportExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The generated-artifact register: every file this platform has produced, who
 * asked for it, under which filters, and where it went (plan §4).
 *
 * ⚠ GLOBAL, with `generated_for_tenant_id` as PROVENANCE rather than a scope
 * key — the register records oversight exports (which cross every MDA and
 * belong to none) alongside workspace exports (which do not), and a tenancy
 * key would hide the state's own artifacts from the state. The provenance is
 * ENFORCED, just not by the database: ReportExportPolicy refuses a download
 * when the bound workspace is not the one the file was generated for.
 *
 * Guarded-by-omission: `status`, the stored-file columns and `row_count` are
 * written only by App\Support\Exporting\ReportExporter. A payload that could
 * set `path` could point the signed download route at any file on the disk.
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $generated_for_tenant_id
 * @property ReportDataset $dataset
 * @property ExportFormat $format
 * @property string $surface
 * @property string $title
 * @property array<string, mixed>|null $filters
 * @property list<string>|null $columns
 * @property string|null $group_by
 * @property string $status
 * @property int|null $row_count
 * @property bool $truncated
 * @property string|null $error_message
 * @property string|null $disk
 * @property string|null $path
 * @property string $file_name
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property int|null $consolidated_report_id
 * @property int $generated_by_id
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 */
#[Fillable([
    'generated_for_tenant_id', 'dataset', 'format', 'surface', 'title',
    'filters', 'columns', 'group_by', 'file_name', 'consolidated_report_id',
    'generated_by_id', 'expires_at',
])]
class ReportExport extends Model
{
    /** @use HasFactory<ReportExportFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected static function booted(): void
    {
        static::creating(function (ReportExport $export): void {
            $export->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * Everything auditable. The interesting audit question about an export is
     * "who took this data out of the platform, and exactly which rows" — so
     * the filters and the row count are logged alongside the chokepoint
     * columns.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('report_exports')
            ->logFillable()
            ->logOnly(['status', 'row_count', 'truncated', 'size_bytes', 'completed_at', 'error_message'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'dataset' => ReportDataset::class,
            'format' => ExportFormat::class,
            'filters' => 'array',
            'columns' => 'array',
            'row_count' => 'integer',
            'truncated' => 'boolean',
            'size_bytes' => 'integer',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * The workspace this artifact was generated FOR. Named `generatedFor`, not
     * `tenant`, so nothing reads as though the register row were owned by that
     * workspace.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function generatedFor(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'generated_for_tenant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_id');
    }

    /** @return BelongsTo<ConsolidatedReport, $this> */
    public function consolidatedReport(): BelongsTo
    {
        return $this->belongsTo(ConsolidatedReport::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function hasFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function hasExpired(?CarbonImmutable $asOf = null): bool
    {
        return $this->expires_at !== null
            && ! $this->expires_at->isAfter($asOf ?? CarbonImmutable::now());
    }

    /**
     * Whether the stored file can still be handed over: ready, unexpired, and
     * actually present on the disk.
     *
     * The disk check is not defensive padding — a retention sweep, a restored
     * backup or a redeployed container can all leave a register row whose file
     * is gone, and a 500 from a streaming download is a worse answer than a
     * screen that says the artifact has been pruned.
     */
    public function isDownloadable(): bool
    {
        if (! $this->isReady() || $this->hasExpired() || $this->path === null || $this->disk === null) {
            return false;
        }

        return Storage::disk($this->disk)->exists($this->path);
    }

    /** Artifacts still on disk and still within retention. */
    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_READY);
    }
}
