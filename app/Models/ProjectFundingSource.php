<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Database\Factories\ProjectFundingSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * How one project is funded, one row per source — tenant-owned.
 *
 * A scalar `projects.funding_source_id` could not represent donor +
 * counterpart funding, which is near-universal on multilateral projects, and
 * would have forced every future report to lie about one of them.
 *
 * This is a real model rather than a bare pivot precisely so BelongsToTenant
 * fills tenant_id on create: `$project->fundingSources()->attach()` writes the
 * row through the query builder and would skip that, which is why the
 * BelongsToMany side is documented read-only.
 *
 * NO SOFT DELETES, deliberately (migration review §10): a unique
 * (project_id, funding_source_id) index plus soft deletes means re-adding a
 * donor you removed last month collides with its own tombstone. Financial
 * attribution still leaves a trace — the activity log below records the
 * deleted row's amount and percentage, which is what the audit question
 * ("who removed the counterpart split, and what was it?") actually needs.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $project_id
 * @property int $funding_source_id
 * @property Money|null $amount
 * @property string|null $percentage
 * @property bool $is_primary
 */
#[Fillable(['project_id', 'funding_source_id', 'amount', 'percentage', 'is_primary'])]
class ProjectFundingSource extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProjectFundingSourceFactory> */
    use HasFactory, LogsActivity;

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'percentage' => 'decimal:2',
            'is_primary' => 'boolean',
        ];
    }

    /** The audit trail that stands in for soft deletes here (see above). */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('projects')
            ->logFillable()
            ->useAttributeRawValues(['amount'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<FundingSource, $this> */
    public function fundingSource(): BelongsTo
    {
        return $this->belongsTo(FundingSource::class);
    }
}
