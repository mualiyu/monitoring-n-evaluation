<?php

namespace App\Models;

use App\Enums\FirmType;
use Database\Factories\ContractorFactory;
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
 * The state's vendor registry — the one table in this module that looks like a
 * tenancy violation and is not.
 *
 * A contractor is a legal entity in the STATE's registry, identified by RC
 * number. A per-MDA contractor table would let a firm blacklisted by Works
 * keep winning contracts in Health — the exact failure the procurement
 * lifecycle exists to prevent. What stays tenant-owned is the *relationship*:
 * Contract carries tenant_id, so who engaged whom for how much is never
 * cross-visible.
 *
 * ⚠ `created_by_tenant_id` is PROVENANCE ONLY — never a scope key. This model
 * deliberately has NO BelongsToTenant trait and NO global scope: every MDA
 * sees every contractor by design (accepted, tested risk). Reviewers reliably
 * misread this column as tenancy; it records which workspace first registered
 * the firm, nothing more. Write rules: any tenant user with `contractors.create`
 * may add a firm (deduped on rc_number); editing and blacklisting are
 * oversight-only (`contractors.manage`), because one MDA must not rewrite a
 * vendor record another MDA's contracts depend on.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string|null $rc_number
 * @property FirmType $type
 * @property string|null $category
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $address
 * @property bool $is_blacklisted
 * @property string|null $blacklist_reason
 * @property string|null $performance_score
 * @property int $created_by_id
 * @property int|null $created_by_tenant_id
 */
#[Fillable([
    'name', 'rc_number', 'type', 'category', 'contact_name', 'contact_email',
    'contact_phone', 'address', 'is_blacklisted', 'blacklist_reason',
    'performance_score', 'created_by_id', 'created_by_tenant_id',
])]
class Contractor extends Model
{
    /** @use HasFactory<ContractorFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Contractor $contractor): void {
            $contractor->ulid ??= (string) Str::ulid();
        });
    }

    /**
     * A blacklisting decides which firms may win public work state-wide, so
     * who flipped the flag and what reason they gave is the audit question
     * this log has to answer.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('contractors')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'type' => FirmType::class,
            'is_blacklisted' => 'boolean',
            'performance_score' => 'decimal:2',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<Contract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The workspace that first registered this firm — provenance for the
     * audit trail, NOT a scope key.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function createdByTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'created_by_tenant_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEligible(Builder $query): Builder
    {
        return $query->where('is_blacklisted', false);
    }
}
