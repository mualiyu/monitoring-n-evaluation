<?php

declare(strict_types=1);

namespace App\Actions\Oversight;

use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Load the record a file hangs off, from the oversight surface.
 *
 * The oversight surface binds no tenant, so loading a tenant-owned owner there
 * hits the fail-closed TenantScope and throws — which is why an oversight
 * reviewer with a perfectly valid signed URL was getting a 403 on every
 * document in the platform while the same file streamed fine inside the MDA's
 * own workspace.
 *
 * The obvious patch — catch the exception and bypass — would turn every MDA's
 * evidence vault into a cross-tenant read. So this lives here, in the only
 * place a tenancy bypass is sanctioned, and it does what every other Action in
 * this folder does: it asks for the oversight permission in the GLOBAL team
 * FIRST, and only then bypasses. A user without that authority gets null, and
 * MediaPolicy refuses them.
 */
class ResolveMediaOwner
{
    public function __invoke(User $actor, Media $media): ?Model
    {
        if (! $actor->holdsGlobalPermission('documents.view')) {
            return null;
        }

        return app(CurrentTenant::class)->bypass(function () use ($media): ?Model {
            $owner = $media->model;

            return $owner instanceof Model ? $owner : null;
        });
    }
}
