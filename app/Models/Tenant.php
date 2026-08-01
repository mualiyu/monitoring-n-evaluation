<?php

namespace App\Models;

use App\Enums\TenantType;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An MDA (Ministry, Department or Agency) — the tenant unit of the platform.
 *
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $slug
 * @property TenantType $type
 * @property array<string, mixed>|null $branding
 * @property bool $is_active
 */
#[Fillable(['name', 'slug', 'type', 'branding', 'is_active'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Tenant $tenant): void {
            $tenant->ulid ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'branding' => 'array',
            'is_active' => 'boolean',
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

    public function url(string $path = '/'): string
    {
        $scheme = app()->isProduction() ? 'https' : 'http';

        return $scheme.'://'.$this->slug.'.'.config('platform.domain').$path;
    }
}
