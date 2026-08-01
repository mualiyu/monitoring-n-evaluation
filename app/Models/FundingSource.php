<?php

namespace App\Models;

use App\Enums\FundingSourceType;
use Database\Factories\FundingSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Funding sources (IGR, Federal Allocation, World Bank Credit…) — GLOBAL
 * reference data, seeded per instance. Projects attach to these through the
 * tenant-owned `project_funding_sources` pivot: co-funding (donor +
 * counterpart) is the normal case, so no report may assume one source.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property FundingSourceType $type
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'type', 'is_active'])]
class FundingSource extends Model
{
    /** @use HasFactory<FundingSourceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => FundingSourceType::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Project, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_funding_sources')
            ->withPivot(['id', 'amount', 'percentage', 'is_primary'])
            ->withTimestamps();
    }

    /** @return HasMany<ProjectFundingSource, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(ProjectFundingSource::class);
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
