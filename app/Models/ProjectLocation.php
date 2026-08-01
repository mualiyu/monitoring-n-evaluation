<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ProjectLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A site a project is executed at — tenant-owned. Projects are frequently
 * multi-site ("12 PHCs across 4 LGAs") and Phase 2 inspections are per-site;
 * single-site projects carry exactly one row.
 *
 * Coordinates are decimal strings, never floats: they are printed on
 * inspection reports and must round-trip unchanged.
 *
 * Exactly one row per project should carry `is_primary` — enforced by
 * SetPrimaryProjectLocation in a transaction, not by a unique index (MySQL has
 * no partial unique index, and unique(project_id, is_primary) would wrongly
 * cap non-primary sites at one).
 *
 * Any LGA/map aggregate over these rows must count DISTINCT project_id, or a
 * 12-site project inflates the state-wide count twelvefold.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $project_id
 * @property string|null $site_name
 * @property string|null $description
 * @property int|null $lga_id
 * @property int|null $ward_id
 * @property string|null $latitude
 * @property string|null $longitude
 * @property bool $is_primary
 */
#[Fillable([
    'project_id', 'site_name', 'description', 'lga_id', 'ward_id',
    'latitude', 'longitude', 'is_primary',
])]
class ProjectLocation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProjectLocationFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_primary' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Lga, $this> */
    public function lga(): BelongsTo
    {
        return $this->belongsTo(Lga::class);
    }

    /** @return BelongsTo<Ward, $this> */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
