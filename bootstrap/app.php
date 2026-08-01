<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureTenantMembership;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\ResolveSurface;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        using: function (): void {
            $domain = config('platform.domain');

            // Order matters: named subdomains must register before the
            // {tenant} wildcard so they are never captured as tenant slugs.
            // ResolveSurface runs on the app surfaces as well as on Fortify's
            // domain-less routes: anything that redirects "to this surface's
            // dashboard" (login, invitation acceptance) needs CurrentSurface
            // bound, otherwise it silently falls back to the public portal.
            // The portal group is deliberately excluded — it is the default
            // surface, and ResolveSurface's apex rule 404s non-auth routes.
            Route::middleware(['web', ResolveSurface::class])
                ->domain('oversight.'.$domain)
                ->name('oversight.')
                ->group(base_path('routes/oversight.php'));

            Route::middleware('web')
                ->domain('www.'.$domain)
                ->get('/{any?}', fn (Request $request) => redirect()->away(
                    (app()->isProduction() ? 'https' : 'http').'://'.config('platform.domain')
                        .$request->getRequestUri(),
                    301
                ))->where('any', '.*');

            Route::middleware(['web', ResolveSurface::class, ResolveTenant::class])
                ->domain('{tenant}.'.$domain)
                ->where(['tenant' => config('platform.tenant_slug_pattern')])
                ->name('tenant.')
                ->group(base_path('routes/tenant.php'));

            Route::middleware('web')
                ->domain($domain)
                ->name('portal.')
                ->group(base_path('routes/portal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Routing is Host-header-driven; only accept hosts on our domain so
        // domain-less routes (health, Horizon, Fortify) can't be reached — or
        // have reset links poisoned — via arbitrary Host headers.
        $middleware->trustHosts(at: fn (): array => [
            '^([a-z0-9-]{1,63}\.)?'.preg_quote(config('platform.domain'), '/').'$',
        ], subdomains: false);

        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
            'tenant.member' => EnsureTenantMembership::class,
            '2fa.require' => RequireTwoFactor::class,
            'role' => RoleMiddleware::class,
        ]);

        // Tenant/surface resolution must run BEFORE authentication: an
        // unknown subdomain is a 404, never a login redirect — and auth
        // checks need the permission team already bound to the right tenant.
        // NB: the framework's priority list carries the AuthenticatesRequests
        // CONTRACT, not the concrete Authenticate class — targeting the class
        // would silently append these at the END of the list instead.
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            ResolveTenant::class,
        );
        $middleware->prependToPriorityList(
            AuthenticatesRequests::class,
            ResolveSurface::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
