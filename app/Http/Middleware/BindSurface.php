<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Surface;
use App\Tenancy\CurrentSurface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the surface a route was DECLARED for: `BindSurface:tenant`.
 *
 * ResolveSurface derives the surface from the host, but it also carries the
 * Fortify auth-route allowlist, which 404s any named route on the apex — so it
 * cannot sit on the Livewire update routes. Those routes are registered once
 * per surface (TenancyServiceProvider), so the surface is already a fact of
 * the route and needs no host parsing at all.
 *
 * Without it CurrentSurface falls back to Portal on every component update,
 * and MatchLivewireComponentToSurface then 404s every tenant and oversight
 * form interaction.
 */
class BindSurface
{
    public function handle(Request $request, Closure $next, string $surface): Response
    {
        app(CurrentSurface::class)->set(Surface::from($surface));

        return $next($request);
    }
}
