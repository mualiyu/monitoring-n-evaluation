<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers for the public transparency portal.
 *
 * The portal is the one surface on this platform that strangers reach without
 * authenticating, which makes it the cheapest place to attack the state's
 * credibility: an embedded copy of the portal inside a page that says
 * something else, or an injected script that rewrites a contract sum, is a
 * headline — not a bug report.
 *
 * Applied inside routes/portal.php's own group rather than globally, so the
 * app surfaces keep their own policy (dashboards are intentionally embeddable
 * via signed URLs; this one is not).
 *
 * X-Frame-Options: DENY *and* frame-ancestors 'none' — the header for old
 * browsers, the directive for current ones. They say the same thing twice on
 * purpose.
 *
 * WHAT THE CSP HAS TO ALLOW, and why each entry is there (if you tighten one,
 * load /map and the feedback form before you believe it):
 *  - script-src 'unsafe-inline': the theme script in layouts/partials/head
 *    runs before first paint to avoid a flash of the wrong palette, and
 *    Livewire injects inline bootstrapping.
 *  - script-src 'unsafe-eval': Alpine evaluates its x-* expressions with
 *    `new Function`. Removing it means removing Alpine from the portal.
 *  - the LEAFLET CDN (script-src + style-src): the map library is loaded from
 *    a CDN in the portal layout only, per the stack decision. Self-hosting it
 *    through Vite would let both of these origins go; until then the origin is
 *    pinned here, and nowhere else on the platform allows it.
 *  - img-src the OpenStreetMap tile hosts + data:/blob:: map tiles are images
 *    fetched from the tile CDN, and Leaflet builds some markers as data URIs.
 *  - style-src 'unsafe-inline': the per-tenant branding token block and
 *    Laravel's inline font manifest are both <style> elements.
 *
 * Everything else is 'self' or 'none', including form-action — the feedback
 * form must not be re-pointable at another host.
 */
class PortalSecurityHeaders
{
    /**
     * The Leaflet CDN. Kept as a constant so the allow-list and any future
     * <script src> agree by construction, and so a reviewer can see in one
     * grep every third-party origin the public portal trusts.
     */
    public const LEAFLET_CDN = 'https://unpkg.com';

    /** OpenStreetMap's tile servers (a.–c.tile.openstreetmap.org). */
    public const TILE_HOSTS = 'https://*.tile.openstreetmap.org';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Full URLs of a portal page are not secret, but the referrer a
        // citizen leaks when they click through to a contractor's website
        // should not say which project they were reading.
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set(
            'Permissions-Policy',
            'geolocation=(), camera=(), microphone=(), payment=(), usb=()',
        );
        $response->headers->set('Content-Security-Policy', $this->policy());

        return $response;
    }

    private function policy(): string
    {
        $script = ["'self'", "'unsafe-inline'", "'unsafe-eval'", self::LEAFLET_CDN];
        $style = ["'self'", "'unsafe-inline'", self::LEAFLET_CDN];
        $connect = ["'self'", self::TILE_HOSTS];

        // The Vite dev server serves modules and a hot-reload websocket from
        // its own origin. Development only — never widened in production,
        // where assets come from the build manifest on 'self'.
        if (! app()->isProduction()) {
            $dev = 'http://localhost:5173';
            $script[] = $dev;
            $style[] = $dev;
            $connect[] = $dev;
            $connect[] = 'ws://localhost:5173';
        }

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            'script-src '.implode(' ', $script),
            'style-src '.implode(' ', $style),
            'connect-src '.implode(' ', $connect),
            "font-src 'self' data:",
            "img-src 'self' data: blob: ".self::TILE_HOSTS,
        ];

        if (app()->isProduction()) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives);
    }
}
