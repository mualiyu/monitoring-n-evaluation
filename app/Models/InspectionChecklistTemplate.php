<?php

namespace App\Models;

use App\Enums\InspectionType;
use Database\Factories\InspectionChecklistTemplateFactory;
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
 * A state-wide inspection instrument — GLOBAL reference data, and therefore
 * deliberately WITHOUT BelongsToTenant.
 *
 * The same call as ReportingPeriod: a checklist every MDA answers differently
 * makes the cross-MDA question ("how did road projects score on drainage this
 * quarter") unanswerable, which is the only reason oversight wants the data.
 * The discipline sweep asserts both halves — this table has no tenant_id, and
 * this model must not carry the trait.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string|null $description
 * @property InspectionType|null $inspection_type
 * @property int|null $sector_id
 * @property bool $is_active
 */
#[Fillable(['name', 'description', 'inspection_type', 'sector_id', 'is_active'])]
class InspectionChecklistTemplate extends Model
{
    /** @use HasFactory<InspectionChecklistTemplateFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (InspectionChecklistTemplate $template): void {
            $template->ulid ??= (string) Str::ulid();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('inspections')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'inspection_type' => InspectionType::class,
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<InspectionChecklistTemplateItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InspectionChecklistTemplateItem::class)->orderBy('position');
    }

    /** @return BelongsTo<Sector, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
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
     * The instruments offered for a given visit. A template with no type is
     * the general-purpose sweep and is always offered; a sector-specific one
     * is offered only for its sector — a clinic checklist on a road project is
     * how a checklist stops being answered honestly.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFor(Builder $query, InspectionType $type, ?int $sectorId = null): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q
                ->whereNull('inspection_type')
                ->orWhere('inspection_type', $type))
            ->where(fn (Builder $q) => $q
                ->whereNull('sector_id')
                ->orWhere('sector_id', $sectorId));
    }
}
