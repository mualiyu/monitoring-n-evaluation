<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\RequireTwoFactor;
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

            /*
            | The per-request account gates, re-applied on every component
            | update. Without them, `active` and `2fa.require` from
            | routes/tenant.php ran when the page was served and then never
            | again: an account deactivated — or pushed past its 2FA deadline —
            | while a tab sat open could keep POSTing writes through wire:click
            | until it next requested a full page. Policies do not close this,
            | because is_active is not a permission and SetUserActive
            | deliberately leaves roles and memberships intact.
            |
            | Livewire's own "persistent middleware" mechanism CANNOT do this
            | here, which is worth knowing before anyone tries: it fires only
            | when the matched route's name ends in `livewire.update`, and these
            | routes are deliberately named `*.livewire-update` (hyphen) so that
            | Livewire's client-side URI generation never picks the tenant one,
            | which carries a {tenant} domain parameter other surfaces cannot
            | supply. Registering them as persistent therefore looks like
            | protection and does nothing. Explicit route middleware it is.
            |
            | EnsureTenantMembership is deliberately absent: it answers with a
            | rendered 403 view, and revoked membership is already covered
            | because RevokeTenantAccess removes the tenant roles, so every
            | Policy check fails on permission.
            */
            $accountGates = [EnsureAccountIsActive::class, RequireTwoFactor::class];

            // Domain MUST be set via the registrar (before the route is added
            // to the collection): Route::post()->domain() keys the collection
            // without the domain, so same-path routes would overwrite each other.
            // The tenant/oversight routes are deliberately NOT named
            // "*livewire.update": Livewire generates the client-side URI from
            // the first route whose name ends with that suffix, and the
            // tenant one needs a {tenant} domain parameter non-tenant
            // surfaces can't provide. Those names are never used for dispatch.
            //
            // ORDER MATTERS, exactly as in bootstrap/app.php: the router walks
            // same-path routes in registration order, and '{tenant}.'.$domain
            // matches ANY single-label host — including 'oversight.'. Register
            // the wildcard first and every oversight component update is
            // captured by the tenant route, where ResolveTenant then 404s
            // because 'oversight' is a reserved subdomain and resolves to no
            // tenant. The named subdomain must come first.
            Route::domain('oversight.'.$domain)
                ->middleware([...$middleware, ...$accountGates])
                ->post($path, $handle)
                ->name('oversight.livewire-update');

            // Gates AFTER ResolveTenant: RequireTwoFactor reads the user's
            // roles, which spatie resolves against the bound permission team.
            Route::domain('{tenant}.'.$domain)
                ->middleware([...$middleware, ResolveTenant::class, ...$accountGates])
                ->post($path, $handle)
                ->where(['tenant' => config('platform.tenant_slug_pattern')])
                ->name('tenant.livewire-update');

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
