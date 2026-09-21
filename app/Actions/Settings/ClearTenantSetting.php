<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Drops a workspace override so the setting inherits the instance value again.
 *
 * Deleting the row — rather than writing the instance value into it — is what
 * makes inheritance live: an MDA that clears its own reminder ladder should
 * follow the state the next time the state retunes it, not freeze today's
 * number under its own name. The row is configuration, not a domain record,
 * and the act itself is preserved in the append-only activity log.
 */
class ClearTenantSetting
{
    public function __invoke(User $actor, string $group, string $key, Tenant $tenant): void
    {
        $definition = SettingDefinitions::find($group, $key);

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown setting [{$group}.{$key}].");
        }

        $current = app(CurrentTenant::class);

        if (! $current->bound() || $current->idOrFail() !== $tenant->id) {
            throw new AuthorizationException('A workspace override may only be cleared from that workspace.');
        }

        if (! $actor->can('settings.manage')) {
            throw new AuthorizationException('Changing workspace settings requires settings.manage in this workspace.');
        }

        $override = TenantSetting::query()->where('group', $group)->where('key', $key)->first();

        if ($override === null) {
            return; // Idempotent: no phantom audit rows for a no-op.
        }

        $previous = $override->value;
        $override->delete();

        activity('settings')
            ->causedBy($actor)
            ->withProperties([
                'old' => ['value' => $previous],
                'attributes' => ['value' => null],
                'group' => $group,
                'key' => $key,
                'scope' => $tenant->slug,
            ])
            ->log('setting.override_cleared');
    }
}
