<?php

namespace App\Providers;

use App\Enums\Surface;
use App\Http\Middleware\BindSurface;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureTenantMembership;
use App\Http\Middleware\MatchLivewireComponentToSurface;
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
            // Every surface's update route carries it: a component may only be
            // driven from the surface it belongs to, whatever host a replayed
            // snapshot is posted to. See the middleware's own docblock — the
            // apex route is the reason it exists, because that one cannot
            // carry `auth` (the public portal has a Livewire component).
            $middleware = ['web', RequireLivewireHeaders::class, MatchLivewireComponentToSurface::class];

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
            | EnsureTenantMembership is on this list too, and its absence was a
            | CROSS-TENANT LEAK. The old reasoning — "revoked membership is
            | already covered, because RevokeTenantAccess removes the tenant
            | roles and every Policy check then fails on permission" — is wrong
            | twice. It only covers a REVOKED member, not one who was never a
            | member; and it assumes every read ends at a Policy, while a list
            | screen's reads end at a query scope, because Livewire does not
            | re-run mount() on an update.
            |
            | The exploit it allowed: log in at a foreign MDA's host (Fortify is
            | domain-less, so authentication succeeds there even though the
            | workspace answers 403), then POST a snapshot legitimately obtained
            | from your OWN workspace to that host's update endpoint. The
            | snapshot checksum covers the snapshot, not the host, so it
            | validates; ResolveTenant binds the neighbour; and the component
            | renders their register. Project::scopeVisibleTo now fails closed
            | as well — two independent gates, because this one cost a leak.
            */
            //
            // `auth` leads the list. No component on either app surface is
            // ever served to a guest (login, 2FA challenge and invitations are
            // plain Blade), and without it a guest holding a lifted snapshot
            // reached any component whose authorization lived in mount() —
            // the shared activity timeline returned audit diffs to anyone.
            // The apex route cannot take it: the portal's component is public.
            $accountGates = ['auth', EnsureAccountIsActive::class, RequireTwoFactor::class];

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
            //
            // BindSurface comes first on each: the surface guard above reads
            // CurrentSurface, and ResolveSurface (which binds it on the page
            // routes) does not run here. Unbound, it falls back to Portal and
            // every tenant/oversight component update is a 404.
            $surface = fn (Surface $surface): string => BindSurface::class.':'.$surface->value;

            Route::domain('oversight.'.$domain)
                ->middleware([$surface(Surface::Oversight), ...$middleware, ...$accountGates])
                ->post($path, $handle)
                ->name('oversight.livewire-update');

            // Gates AFTER ResolveTenant: RequireTwoFactor reads the user's
            // roles, which spatie resolves against the bound permission team.
            Route::domain('{tenant}.'.$domain)
                ->middleware([$surface(Surface::Tenant), ...$middleware, ResolveTenant::class, EnsureTenantMembership::class, ...$accountGates])
                ->post($path, $handle)
                ->where(['tenant' => config('platform.tenant_slug_pattern')])
                ->name('tenant.livewire-update');

            // Returned route: Livewire names it "livewire.update" itself and
            // uses it for URI generation — it must stay parameter-free, so
            // the apex (portal) route is the one we hand back.
            return Route::domain($domain)
                ->middleware([$surface(Surface::Portal), ...$middleware])
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
