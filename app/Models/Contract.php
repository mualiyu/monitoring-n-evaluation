<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An engagement between an MDA and a firm — tenant-owned, even though the
 * contractor registry it points at is global. This is where the tenancy line
 * sits: every MDA sees every firm, no MDA sees another's contracts.
 *
 * The award `sum` is immutable. Revisions are separate rows pointing at the
 * contract they vary (`varies_contract_id`) — the manual's amendment-register
 * pattern, and the reason Project::$contract_sum is a maintained sum rather
 * than a copied figure.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $project_id
 * @property int $contractor_id
 * @property string $contract_number
 * @property ContractType $type
 * @property ContractStatus $status
 * @property Money $sum
 * @property string|null $scope_of_works
 * @property CarbonImmutable $award_date
 * @property CarbonImmutable|null $commencement_date
 * @property int|null $duration_days
 * @property CarbonImmutable|null $expected_completion_date
 * @property string|null $retention_percentage
 * @property int|null $varies_contract_id
 * @property string|null $variation_reason
 * @property int $created_by_id
 */
#[Fillable([
    'project_id', 'contractor_id', 'contract_number', 'type', 'status', 'sum',
    'scope_of_works', 'award_date', 'commencement_date', 'duration_days',
    'expected_completion_date', 'retention_percentage', 'varies_contract_id',
    'variation_reason', 'created_by_id',
])]
class Contract extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ContractFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Contract $contract): void {
            $contract->ulid ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'type' => ContractType::class,
            'status' => ContractStatus::class,
            'sum' => MoneyCast::class,
            'retention_percentage' => 'decimal:2',
            'award_date' => 'immutable_date',
            'commencement_date' => 'immutable_date',
            'expected_completion_date' => 'immutable_date',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Contractor, $this> */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * The contract this row varies (null for an original award).
     *
     * @return BelongsTo<self, $this>
     */
    public function variesContract(): BelongsTo
    {
        return $this->belongsTo(self::class, 'varies_contract_id');
    }

    /** @return HasMany<self, $this> */
    public function variations(): HasMany
    {
        return $this->hasMany(self::class, 'varies_contract_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function isVariation(): bool
    {
        return $this->varies_contract_id !== null;
    }

    /**
     * Award sum plus every variation raised against it — the figure a
     * commissioner means by "what is this contract worth now". The original
     * `sum` stays untouched, so both numbers remain readable.
     *
     * Eager-load `variations` before calling in a list: lazy loading is
     * prevented outside production, which is the point.
     */
    public function revisedValue(): Money
    {
        return $this->variations->reduce(
            fn (Money $carry, self $variation): Money => $carry->plus($variation->sum),
            $this->sum,
        );
    }
}
