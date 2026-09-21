<?php

namespace App\Models;

use App\Enums\FrameworkLevel;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ResultFrameworkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One RESULT STATEMENT in a logframe — tenant-owned.
 *
 * A "framework" is not a row here: it is the tree of rows sharing a
 * `project_id` (or, with a null project_id, the MDA's programme-level tree),
 * nested impact → outcome → output. Indicators hang off the statements they
 * measure.
 *
 * The statement tree and the indicator tree are deliberately separate:
 * `parent_id` says which result serves which, `Indicator::$parent_indicator_id`
 * says which measure rolls into which, and those are not always the same shape
 * (two outputs under different outcomes can feed one PDO indicator).
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int|null $project_id
 * @property int|null $parent_id
 * @property FrameworkLevel $level
 * @property string|null $code
 * @property string $statement
 * @property string|null $description
 * @property string|null $assumptions
 * @property int $sort_order
 * @property int $created_by_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, self> $children
 * @property-read Collection<int, Indicator> $indicators
 */
#[Fillable([
    'project_id', 'parent_id', 'level', 'code', 'statement', 'description',
    'assumptions', 'sort_order', 'created_by_id',
])]
class ResultFramework extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ResultFrameworkFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (ResultFramework $framework): void {
            $framework->ulid ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'level' => FrameworkLevel::class,
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /**
     * Everything auditable (rules/architecture.md). A result statement quietly
     * reworded after the fact is how a missed outcome becomes a met one, so
     * the before/after pair on `statement` is the point of this log.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('result_frameworks')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * Null for an MDA programme framework that belongs to no single project.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Indicator, $this> */
    public function indicators(): HasMany
    {
        return $this->hasMany(Indicator::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The statements of one project's framework. A null project means the MDA
     * programme tree — `whereNull`, not "no filter", so a programme framework
     * never absorbs every project's statements.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForProject(Builder $query, ?Project $project): Builder
    {
        return $project === null
            ? $query->whereNull('project_id')
            : $query->whereBelongsTo($project);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAtLevel(Builder $query, FrameworkLevel $level): Builder
    {
        return $query->where('level', $level);
    }

    /** Whether this statement may still be dismantled: no children, no indicators. */
    public function isRemovable(): bool
    {
        return ! $this->children()->exists() && ! $this->indicators()->exists();
    }
}
