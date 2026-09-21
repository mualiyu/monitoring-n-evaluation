<?php

namespace App\Models;

use App\Enums\Role;
use App\Tenancy\CurrentTenant;
use Closure;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Global identity. Tenant membership/authority comes exclusively from
 * team-scoped roles (spatie/laravel-permission, team = tenant_id) — a role
 * in MDA A grants nothing in MDA B. Oversight roles carry a null tenant_id.
 *
 * @property array<string, array<string, bool>>|null $notification_preferences
 */
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_recovery_codes', 'two_factor_secret'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_required_at' => 'datetime',
            'two_factor_exempted_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            // Notification mutes, { category: { channel: false } }. ABSENT
            // MEANS ON, so a category added later is delivered by default.
            // Deliberately not fillable: written only by
            // App\Actions\Settings\SaveNotificationPreferences.
            'notification_preferences' => 'array',
        ];
    }

    /**
     * Whether this user has muted a notification category on a channel.
     * Read by App\Notifications\Concerns\RespectsPreferences inside via().
     */
    public function hasMutedNotifications(string $category, string $channel): bool
    {
        $preferences = $this->notification_preferences;

        if (! is_array($preferences)) {
            return false;
        }

        $forCategory = $preferences[$category] ?? null;

        return is_array($forCategory) && ($forCategory[$channel] ?? true) === false;
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Whether the user holds one of these roles in the GLOBAL (oversight)
     * team — the question "is this person state-level authority?", which a
     * tenant-context check can never answer: spatie resolves roles against the
     * bound permission team, so a StateAdmin browsing an MDA workspace holds
     * no roles there at all.
     *
     * The relations are dropped either side of the switch because spatie bakes
     * the team id into the loaded relation; a cached one would answer for the
     * wrong team.
     */
    public function holdsGlobalRole(Role ...$roles): bool
    {
        return $this->inGlobalContext(fn (): bool => $this->hasAnyRole(
            array_map(fn (Role $role): string => $role->value, $roles),
        ));
    }

    /**
     * Whether the user holds a permission in the GLOBAL (oversight) team.
     * Used for authority over global tables — the contractor registry belongs
     * to the state, so the permission that governs it is read from the state's
     * team, never from whichever MDA workspace the request happens to be on.
     */
    public function holdsGlobalPermission(string $permission): bool
    {
        return $this->inGlobalContext(fn (): bool => $this->can($permission));
    }

    /**
     * @param  Closure(): bool  $check
     */
    private function inGlobalContext(Closure $check): bool
    {
        return app(CurrentTenant::class)->runWithoutTenant(function () use ($check): bool {
            $this->unsetRelation('roles')->unsetRelation('permissions');

            try {
                return $check();
            } finally {
                $this->unsetRelation('roles')->unsetRelation('permissions');
            }
        });
    }
}
