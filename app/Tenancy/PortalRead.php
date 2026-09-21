<?php

namespace App\Tenancy;

use Closure;

/**
 * The public portal's cross-tenant read chokepoint — and the only one.
 *
 * The portal is state-wide by construction: a citizen browsing published
 * projects is looking at every MDA at once, on the apex domain, where no
 * subdomain has resolved a tenant. Every portal read is therefore a
 * cross-tenant read, and rules/tenancy.md says a cross-tenant read is an
 * explicit privilege that lives in exactly one sanctioned place per surface.
 * app/Actions/Oversight/ is that place for the oversight surface; this class
 * is it for the portal, which is why it sits in app/Tenancy/ (the directory
 * the discipline sweep sanctions for scope bypasses) rather than being
 * sprinkled through app/Actions/Portal/.
 *
 * Three properties make the bypass safe here, and all three are load-bearing:
 *
 *  1. It is READ-ONLY by construction. Callers are
 *     app/Actions/Portal/*, every one of which returns query results; the
 *     portal's single write (feedback submission) never comes through here,
 *     because `feedback` is a global table that needs no bypass at all.
 *  2. Every query run inside it is filtered to PUBLISHED rows by the caller
 *     (App\Support\Publishing\PublicProjectPayload::publishedOnly()), and the
 *     results are projected through that same whitelist before a view sees
 *     them. The bypass widens which tenants are visible; it never widens which
 *     rows or which columns are.
 *  3. It forgets any bound tenant for the duration of the read and restores it
 *     afterwards. A leftover tenant context (a queue worker, an Octane
 *     iteration, a test that bound one earlier) must not be able to turn a
 *     state-wide public list into one MDA's list — that would be a silently
 *     wrong page, which on a transparency portal is worse than an error.
 */
final class PortalRead
{
    /**
     * Run a published-data read with no tenant bound and the tenant scope
     * bypassed, restoring the previous context afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $read
     * @return TReturn
     */
    public function __invoke(Closure $read): mixed
    {
        $current = app(CurrentTenant::class);

        return $current->runWithoutTenant(
            fn (): mixed => $current->bypass($read),
        );
    }
}
