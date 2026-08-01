<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Builds absolute URLs to a specific surface from CONFIG, never from the
 * current request — queued notifications run in workers where there is no
 * request host, and falling back to APP_URL sends users to the wrong
 * subdomain (the reset-link class of bug).
 */
class SurfaceUrl
{
    public static function base(?Tenant $tenant = null): string
    {
        $scheme = app()->isProduction() ? 'https' : 'http';
        $host = $tenant
            ? $tenant->slug.'.'.config('platform.domain')
            : config('platform.domain');

        return $scheme.'://'.$host;
    }

    public static function oversight(string $path = '/'): string
    {
        $scheme = app()->isProduction() ? 'https' : 'http';

        return $scheme.'://oversight.'.config('platform.domain').$path;
    }

    /**
     * Acceptance URL for an invitation — tenant subdomain for tenant
     * invitations, oversight subdomain for oversight ones.
     */
    public static function invitation(?Tenant $tenant, string $plaintextToken): string
    {
        $path = '/invitations/'.$plaintextToken;

        return $tenant ? self::base($tenant).$path : self::oversight($path);
    }
}
