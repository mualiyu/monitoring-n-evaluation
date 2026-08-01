<?php

namespace App\Tenancy;

use App\Models\Tenant;

/**
 * The single lookup rule for resolving a subdomain slug to a servable tenant.
 * Both ResolveTenant and ResolveSurface go through here — one place to add
 * caching later, one place the "active, not deleted, not reserved" rule lives.
 */
class TenantLocator
{
    public function bySlug(?string $slug): ?Tenant
    {
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        if (in_array($slug, config('platform.reserved_subdomains'), true)) {
            return null;
        }

        return Tenant::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();
    }

    /**
     * The tenant slug for a host under the platform domain, or null for the
     * apex / non-matching hosts. Multi-label prefixes are rejected.
     */
    public function slugForHost(string $host): ?string
    {
        $base = config('platform.domain');
        $host = strtolower(rtrim($host, '.'));

        if ($host === $base || ! str_ends_with($host, '.'.$base)) {
            return null;
        }

        $prefix = substr($host, 0, -strlen('.'.$base));

        return str_contains($prefix, '.') ? null : $prefix;
    }
}
