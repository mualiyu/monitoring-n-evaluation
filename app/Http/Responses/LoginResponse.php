<?php

namespace App\Http\Responses;

use App\Actions\Iam\ListUserWorkspaces;
use App\Enums\MembershipStatus;
use App\Enums\Surface;
use App\Models\TenantMembership;
use App\Tenancy\CurrentSurface;
use App\Tenancy\CurrentTenant;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Post-login routing per surface. intended() is honoured ONLY when its host
 * matches the current host — a cross-host intended URL is an open-redirect-
 * shaped hole with host-only sessions.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $surface = app(CurrentSurface::class)->get();
        $user = $request->user();

        $intended = $request->session()->pull('url.intended');
        if (is_string($intended) && parse_url($intended, PHP_URL_HOST) === $request->getHost()) {
            return redirect()->to($intended);
        }

        if ($surface === Surface::Tenant) {
            $tenant = app(CurrentTenant::class)->getOrFail();

            $isMember = TenantMembership::query()
                ->where('tenant_id', $tenant->id) // sanctioned: gate table (see model docblock)
                ->where('user_id', $user->id)
                ->where('status', MembershipStatus::Active)
                ->exists();

            if (! $isMember) {
                return response()->view('errors.no-workspace-access', [
                    'tenant' => $tenant,
                    'workspaces' => (new ListUserWorkspaces)($user),
                ], 403);
            }

            return redirect()->route('tenant.dashboard');
        }

        return redirect()->route($surface->dashboardRoute());
    }
}
