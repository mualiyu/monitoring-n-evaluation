<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use App\Tenancy\TenantLocator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the {tenant} subdomain into the bound CurrentTenant.
 * Unknown, inactive, or reserved subdomains are a 404 — never a fallback.
 */
class ResolveTenant
{
    public function __construct(private readonly TenantLocator $locator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('tenant');

        // Domain-parameter-less entry points (e.g. surface-shared paths) fall
        // back to parsing the Host header — safe because TrustHosts pinned it.
        if (! is_string($slug)) {
            $slug = $this->locator->slugForHost($request->getHost());
        }

        $tenant = $this->locator->bySlug($slug);

        abort_unless($tenant instanceof Tenant, 404);

        // set() also syncs the spatie permission team to this tenant.
        app(CurrentTenant::class)->set($tenant);

        // The subdomain is context, not a controller argument.
        $request->route()?->forgetParameter('tenant');
        URL::defaults(['tenant' => $tenant->slug]);
        view()->share('tenant', $tenant);

        return $next($request);
    }
}
