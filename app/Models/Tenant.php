<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\TenantType;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * An MDA (Ministry, Department or Agency) — the tenant unit of the platform.
 *
 * Global by definition: the register of workspaces cannot itself be scoped by
 * the workspace it is registering, so this model carries no BelongsToTenant.
 * Everything that writes it lives in app/Actions/Oversight and re-checks
 * `tenants.manage` in the GLOBAL permission team first.
 *
 * `is_active`, `onboarded_at`, `deactivated_at` and `deactivated_reason` are
 * deliberately NOT fillable: suspending a workspace closes a subdomain for an
 * entire ministry, so it is written only by App\Actions\Oversight\SetTenantActive
 * and never by a form payload.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string|null $short_name
 * @property string $slug
 * @property TenantType $type
 * @property int|null $sector_id
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property array<string, mixed>|null $branding
 * @property bool $is_active
 * @property Carbon|null $onboarded_at
 * @property Carbon|null $deactivated_at
 * @property string|null $deactivated_reason
 * @property-read Sector|null $sector
 * @property-read int|null $users_count
 * @property-read int|null $projects_count
 */
#[Fillable([
    'name', 'short_name', 'slug', 'type', 'sector_id',
    'contact_name', 'contact_email', 'contact_phone', 'branding',
])]
class Tenant extends Model implements HasMedia
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, InteractsWithMedia, LogsActivity, SoftDeletes;

    /**
     * The workspace logo.
     *
     * DELIBERATELY NOT the document vault. Every collection in
     * config/documents.php is a government record on the private disk, served
     * through a signed 15-minute URL — correct for an award letter, wrong for
     * chrome that has to render in an <img> on every page of the workspace and
     * (Phase 3) on the anonymous public portal, where there is no session to
     * sign for. The controls that matter for an upload are kept regardless:
     * the mime type is sniffed and allow-listed, the size is capped, and the
     * name on disk is generated — see UpdateTenantProfile.
     */
    public const LOGO_COLLECTION = 'logo';

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            $tenant->ulid ??= (string) Str::ulid();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('tenancy')
            ->logFillable()
            ->logOnly(['is_active', 'onboarded_at', 'deactivated_at', 'deactivated_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::LOGO_COLLECTION)
            ->useDisk('public')
            // One lockup per workspace: a second upload replaces the first
            // rather than leaving two files both claiming to be the crest.
            ->singleFile();
    }

    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'branding' => 'array',
            'is_active' => 'boolean',
            'onboarded_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return HasMany<TenantSetting, $this> */
    public function settings(): HasMany
    {
        return $this->hasMany(TenantSetting::class);
    }

    /** @return BelongsTo<Sector, $this> */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * The people who may enter this workspace.
     *
     * Through the membership pivot, never through the membership MODEL: that
     * model has exactly two sanctioned readers (the Iam actions) and this is a
     * register count, not a gate decision. Soft-deleted accounts drop out via
     * the User model's own global scope.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->wherePivot('status', MembershipStatus::Active->value);
    }

    /**
     * The workspace's projects. Project is tenant-scoped, so any read through
     * this relation from the oversight surface has to happen inside an
     * explicit bypass — which is why the register's counts are assembled in
     * app/Actions/Oversight/ and nowhere else.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function url(string $path = '/'): string
    {
        $scheme = app()->isProduction() ? 'https' : 'http';

        return $scheme.'://'.$this->slug.'.'.config('platform.domain').$path;
    }

    /** The name to show where space is tight — sidebar, breadcrumb, chip. */
    public function displayName(): string
    {
        $branded = $this->branding['display_name'] ?? null;

        return is_string($branded) && $branded !== '' ? $branded : $this->name;
    }
}
