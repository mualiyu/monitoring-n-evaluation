<?php

namespace App\Models;

use Database\Factories\LgaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Local Government Area — GLOBAL reference data (FCT deployments label these
 * "Area Councils"; the label is a terminology concern, not a schema one).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'is_active'])]
class Lga extends Model
{
    /** @use HasFactory<LgaFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Ward, $this> */
    public function wards(): HasMany
    {
        return $this->hasMany(Ward::class);
    }

    /** @return HasMany<ProjectLocation, $this> */
    public function projectLocations(): HasMany
    {
        return $this->hasMany(ProjectLocation::class);
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
