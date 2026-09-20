<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksTenantAuthority;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Authorization for a file, answered through the record it hangs off.
 *
 * The `media` table carries no tenant_id — it cannot, because the same table
 * holds files for tenant-owned records and for global ones — so the global
 * scope cannot protect it and a route binding by uuid will happily hand this
 * policy another MDA's evidence. The owning model is therefore the only
 * safe source of truth here, and a media row whose owner has vanished is
 * refused rather than shown.
 */
class MediaPolicy
{
    use ChecksTenantAuthority;

    public function view(User $user, Media $media): bool
    {
        $owner = $this->owner($media);

        if ($owner === null) {
            return false;
        }

        return $this->permits($user, 'documents.view', $this->tenantOwned($owner));
    }

    public function delete(User $user, Media $media): bool
    {
        $owner = $this->owner($media);

        if ($owner === null) {
            return false;
        }

        return $this->permits($user, 'documents.delete', $this->tenantOwned($owner));
    }

    /**
     * The owning record, loaded through its own model — which means through
     * its own global scope. A cross-tenant uuid resolves to null here, not to
     * a record, because the TenantScope refuses to load it.
     */
    private function owner(Media $media): ?Model
    {
        try {
            $owner = $media->model;
        } catch (\Throwable) {
            return null;
        }

        return $owner instanceof Model ? $owner : null;
    }

    /**
     * Only hand `permits()` a record when it actually carries tenancy: a
     * global record (none exist with documents today, but the vault is
     * generic) must fall through to the permission check alone rather than
     * fail a tenant comparison against a null column.
     */
    private function tenantOwned(Model $owner): ?Model
    {
        return $owner->getAttribute('tenant_id') !== null ? $owner : null;
    }
}
