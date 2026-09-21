<?php

namespace App\Models;

use App\Enums\CertificateType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasDocuments;
use Carbon\CarbonImmutable;
use Database\Factories\CertificateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;

/**
 * A completion certificate — tenant-owned (digest §8, step 5).
 *
 * Guarded-by-omission: `reference`, `issued_by_id`, `issued_at` and the
 * revocation columns are written ONLY by App\Actions\Lifecycle\
 * IssueCompletionCertificate / RevokeCertificate. A certificate number that a
 * form payload could set is a certificate number that can be made to collide
 * with, or impersonate, one already in the register.
 *
 * `site_inspection_id` is a SOFT reference with no foreign key: the
 * inspections module owns that table and ships independently, so this column
 * is read defensively (see App\Actions\Lifecycle\Concerns\FindsFinalInspection)
 * rather than through a constraint that would couple the two migrations.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $project_id
 * @property CertificateType $type
 * @property string $reference
 * @property int|null $site_inspection_id
 * @property string|null $narrative
 * @property CarbonImmutable|null $defects_liability_ends_on
 * @property int|null $issued_by_id
 * @property CarbonImmutable $issued_at
 * @property int|null $revoked_by_id
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revocation_reason
 * @property int|null $created_by_id
 */
#[Fillable([
    'project_id', 'type', 'site_inspection_id', 'narrative',
    'defects_liability_ends_on', 'created_by_id',
])]
class Certificate extends Model implements HasMedia
{
    use BelongsToTenant;
    use HasDocuments;

    /** @use HasFactory<CertificateFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * The signed certificate itself — a single-file collection, because there
     * is exactly one document that IS the certificate (config/documents.php).
     *
     * @return list<string>
     */
    public function documentCollections(): array
    {
        return ['certificate'];
    }

    protected static function booted(): void
    {
        static::creating(function (Certificate $certificate): void {
            $certificate->ulid ??= (string) Str::ulid();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('certificates')
            ->logFillable()
            ->logOnly([
                'reference', 'issued_by_id', 'issued_at',
                'revoked_by_id', 'revoked_at', 'revocation_reason',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'type' => CertificateType::class,
            'defects_liability_ends_on' => 'immutable_date',
            'issued_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
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

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** A certificate that still attests to something. */
    public function isActive(): bool
    {
        return ! $this->isRevoked();
    }

    /**
     * Whether the defects-liability period is still running — the months in
     * which the state can still compel the contractor back to site.
     */
    public function isUnderDefectsLiability(?Carbon $asOf = null): bool
    {
        if ($this->isRevoked() || $this->defects_liability_ends_on === null) {
            return false;
        }

        return $this->defects_liability_ends_on->endOfDay()->isAfter($asOf ?? now());
    }

    /**
     * Certificates still standing. Used by the issuing Action's duplicate
     * guard and by every list that means "the certificate for this project" —
     * a revoked one is history, not an answer.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * Certificates a user may see, narrowed by the same project-visibility
     * rule every other list uses (see CommencementNotice::scopeVisibleTo).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn(
            'project_id',
            Project::query()->visibleTo($user)->select('id'),
        );
    }

    /**
     * The next certificate number for a tenant, type and year —
     * `<SLUG>/<PC|FC>/<year>/<0000>`.
     *
     * The slug is DATA (the workspace's own subdomain label), never a state or
     * ministry name typed into code: the white-label constraint applies to
     * generated references exactly as it applies to Blade.
     *
     * Trashed and revoked rows count: a reference that was ever minted is
     * never re-used, or two documents in the same register would answer to one
     * number. The unique index is the real guarantee — this is the allocator.
     */
    public static function mintReference(Tenant $tenant, CertificateType $type, CarbonImmutable $issuedAt): string
    {
        $year = $issuedAt->year;

        $used = static::query()
            ->withTrashed()
            ->where('type', $type)
            ->whereYear('issued_at', $year)
            ->count();

        return sprintf(
            '%s/%s/%d/%04d',
            Str::upper($tenant->slug),
            $type->referenceSegment(),
            $year,
            $used + 1,
        );
    }
}
