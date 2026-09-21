<?php

namespace App\Policies;

use App\Actions\Oversight\ResolveMediaOwner;
use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Authorization for a file, answered through the record it hangs off.
 *
 * The `media` table carries no tenant_id — it cannot, because the same table
 * holds files for tenant-owned records and for global ones — so the global
 * scope cannot protect it, and a route binding will happily hand this policy
 * another MDA's evidence. The owning record is therefore the only safe source
 * of truth here, and a media row whose owner has vanished is refused rather
 * than shown.
 *
 * Two rules, and BOTH are needed:
 *
 *  1. permission + tenant match, via ChecksTenantAuthority; and
 *  2. the owner's OWN visibility rule. Without (2) a Consultant, who
 *     `ProjectPolicy::view` narrows to the projects they are assigned to,
 *     could still read the bill of quantities of a project they are not on —
 *     `documents.view` plus a tenant match says nothing about assignment.
 */
class MediaPolicy
{
    use ChecksTenantAuthority;

    public function view(User $user, Media $media): bool
    {
        return $this->permitsOnOwner($user, $media, 'documents.view');
    }

    public function delete(User $user, Media $media): bool
    {
        return $this->permitsOnOwner($user, $media, 'documents.delete');
    }

    private function permitsOnOwner(User $user, Media $media, string $permission): bool
    {
        $owner = $this->owner($user, $media);

        if ($owner === null) {
            return false;
        }

        if (! $this->permits($user, $permission, $this->tenantOwned($owner))) {
            return false;
        }

        // The owner's own rule, asked of the owner. Gate::allows() returns
        // true when a model has no policy at all, which is right: a record
        // with no visibility rule of its own is governed by (1) alone.
        return $user->can('view', $owner);
    }

    /**
     * The owning record, loaded through its own model — which means through
     * its own global scope. A cross-tenant uuid resolves to null here, not to
     * a record, because the TenantScope refuses to load it.
     *
     * With NO tenant bound we are on the oversight surface, where that same
     * scope throws rather than returning nothing. That case is delegated to
     * app/Actions/Oversight, which checks oversight authority in the global
     * team before bypassing tenancy — the bypass is not sanctioned here, and
     * doing it here would make every MDA's vault cross-tenant readable.
     */
    private function owner(User $user, Media $media): ?Model
    {
        if (! app(CurrentTenant::class)->bound()) {
            return app(ResolveMediaOwner::class)($user, $media);
        }

        $owner = $media->model;

        return $owner instanceof Model ? $owner : null;
    }

    /**
     * Only hand `permits()` a record when it actually carries tenancy: a
     * global record must fall through to the permission check alone rather
     * than fail a tenant comparison against a null column.
     */
    private function tenantOwned(Model $owner): ?Model
    {
        return $owner->getAttribute('tenant_id') !== null ? $owner : null;
    }
}
