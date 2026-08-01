<?php

namespace App\Models;

use App\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Invitation to join the platform. tenant_id null = oversight invitation.
 * Nullable tenant_id ⇒ deliberately NOT BelongsToTenant (sanctioned
 * exception — reads go through the Iam actions only). Rows are permanent
 * audit; lifecycle is pending → accepted | revoked | expired (derived).
 *
 * @property int $id
 * @property string $ulid
 * @property int|null $tenant_id
 * @property string $email
 * @property Role $role
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property Tenant|null $tenant
 * @property User|null $invitedBy
 */
#[Fillable(['tenant_id', 'email', 'role', 'token_hash', 'invited_by_id', 'expires_at'])]
class Invitation extends Model
{
    protected static function booted(): void
    {
        static::creating(function (Invitation $invitation): void {
            $invitation->ulid ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public static function hashToken(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
