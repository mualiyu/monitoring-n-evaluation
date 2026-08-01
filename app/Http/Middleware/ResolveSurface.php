<?php

namespace App\Http\Middleware;

use App\Enums\Surface;
use App\Models\Tenant;
use App\Tenancy\CurrentSurface;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantLocator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on Fortify's (domain-less) auth routes: decides which surface the
 * request landed on, binds tenant context for tenant hosts, and enforces the
 * per-surface auth-route allowlist.
 *
 * Host parsing is safe ONLY because TrustHosts (bootstrap/app.php) already
 * rejected anything outside our domain — the passkey origin write below
 * inherits that guarantee; loosening TrustHosts would turn it into origin
 * confusion for WebAuthn.
 */
class ResolveSurface
{
    /** Fortify route names that live exclusively on the apex (portal). */
    private const APEX_ONLY = [
        'password.request', 'password.email', 'password.reset', 'password.update',
    ];

    public function __construct(private readonly TenantLocator $locator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $base = config('platform.domain');
        $slug = $this->locator->slugForHost($host);

        $surface = match (true) {
            $host === $base => Surface::Portal,
            $slug === config('platform.oversight_subdomain', 'oversight') => Surface::Oversight,
            default => Surface::Tenant,
        };

        if ($surface === Surface::Tenant) {
            $tenant = $this->locator->bySlug($slug);
            abort_unless($tenant instanceof Tenant, 404);

            app(CurrentTenant::class)->set($tenant);
            URL::defaults(['tenant' => $tenant->slug]);
            view()->share('tenant', $tenant);
        } else {
            // Oversight and portal run in the GLOBAL permission team by
            // contract. Pinning it explicitly (rather than relying on a fresh
            // registrar per request) keeps role checks on the oversight
            // surface correct under Octane, where the registrar is a
            // long-lived singleton that could still carry a tenant team.
            app(CurrentTenant::class)->forget();
        }

        app(CurrentSurface::class)->set($surface);

        $routeName = (string) $request->route()?->getName();

        // Password reset lives ONLY on the apex: reset links are built in
        // queue workers with no request host, so a single deterministic host
        // removes the wrong-host/CSRF-mismatch bug class entirely.
        if (in_array($routeName, self::APEX_ONLY, true) && $surface !== Surface::Portal) {
            $scheme = app()->isProduction() ? 'https' : 'http';

            return redirect()->away($scheme.'://'.$base.$request->getRequestUri());
        }

        if ($surface === Surface::Portal && $routeName !== '' && ! in_array($routeName, self::APEX_ONLY, true)) {
            abort(404); // no interactive login on the public portal (Phase 0)
        }

        // Passkeys: one credential valid on every subdomain requires the
        // current origin here; safe only under TrustHosts (see class docblock).
        config(['fortify.passkeys.allowed_origins' => [$request->schemeAndHttpHost()]]);

        return $next($request);
    }
}
