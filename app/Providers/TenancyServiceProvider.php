<?php

namespace App\Providers;

use App\Http\Middleware\ResolveTenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\RequireLivewireHeaders;
use Spatie\Permission\PermissionRegistrar;

class TenancyServiceProvider extends ServiceProvider
{
    /**
     * Livewire's HandleRequests::boot() registers a domain-less
     * POST /livewire/update fallback unless a custom update route already
     * exists — and a domain-less route would serve component updates for
     * every surface WITHOUT ResolveTenant, losing tenancy + permission-team
     * context on each interaction. App providers register before package
     * providers boot, so this replaces the fallback with per-surface routes.
     */
    public function register(): void
    {
        Livewire::setUpdateRoute(function (callable|array $handle, string $path) {
            $domain = config('platform.domain');
            $middleware = ['web', RequireLivewireHeaders::class];

            // Domain MUST be set via the registrar (before the route is added
            // to the collection): Route::post()->domain() keys the collection
            // without the domain, so same-path routes would overwrite each other.
            // The tenant/oversight routes are deliberately NOT named
            // "*livewire.update": Livewire generates the client-side URI from
            // the first route whose name ends with that suffix, and the
            // tenant one needs a {tenant} domain parameter non-tenant
            // surfaces can't provide. Inbound matching is by domain, so
            // these names are never used for dispatch.
            Route::domain('{tenant}.'.$domain)
                ->middleware([...$middleware, ResolveTenant::class])
                ->post($path, $handle)
                ->where(['tenant' => config('platform.tenant_slug_pattern')])
                ->name('tenant.livewire-update');

            Route::domain('oversight.'.$domain)
                ->middleware($middleware)
                ->post($path, $handle)
                ->name('oversight.livewire-update');

            // Returned route: Livewire names it "livewire.update" itself and
            // uses it for URI generation — it must stay parameter-free, so
            // the apex (portal) route is the one we hand back.
            return Route::domain($domain)
                ->middleware($middleware)
                ->post($path, $handle);
        });
    }

    public function boot(): void
    {
        // Default permission team is the global (oversight) context; the
        // ResolveTenant middleware / CurrentTenant override it per context.
        app(PermissionRegistrar::class)
            ->setPermissionsTeamId(CurrentTenant::GLOBAL_TEAM);
    }
}
