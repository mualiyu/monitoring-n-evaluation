<?php

declare(strict_types=1);

namespace App\Actions\Oversight;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Opens or closes a workspace's subdomain.
 *
 * DEACTIVATION DESTROYS NOTHING — and that is the whole design. TenantLocator
 * refuses to resolve an inactive slug, so the MDA's staff cannot sign in and
 * its subdomain 404s; the tenant row, its projects, its returns, its evidence
 * and its audit trail are all untouched, and flipping the flag back restores
 * the workspace exactly as it was. A government record is not deleted because
 * an agency was merged or a secretariat is investigating it.
 *
 * `deactivated_at` and `deactivated_reason` are stamped rather than inferred:
 * "when was this closed and on whose say-so" is the first question an auditor
 * asks, and the answer must survive a reactivation.
 */
class SetTenantActive
{
    public function __invoke(User $actor, Tenant $tenant, bool $active, ?string $reason = null): void
    {
        if (! $actor->holdsGlobalPermission('tenants.manage')) {
            throw new AuthorizationException('Suspending a workspace requires state-level tenants.manage authority.');
        }

        if ($tenant->is_active === $active) {
            return; // Idempotent: no phantom audit rows for a no-op.
        }

        $reason = is_string($reason) && trim($reason) !== '' ? trim($reason) : null;

        $tenant->forceFill($active
            ? ['is_active' => true, 'deactivated_at' => null, 'deactivated_reason' => null]
            : ['is_active' => false, 'deactivated_at' => now(), 'deactivated_reason' => $reason])
            ->save();

        activity('tenancy')
            ->causedBy($actor)
            ->performedOn($tenant)
            ->withProperties([
                'old' => ['is_active' => ! $active],
                'attributes' => ['is_active' => $active, 'reason' => $reason],
            ])
            ->log($active ? 'tenant.activated' : 'tenant.deactivated');
    }
}
