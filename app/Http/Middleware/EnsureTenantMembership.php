<?php

namespace App\Http\Middleware;

use App\Actions\Iam\ListUserWorkspaces;
use App\Enums\MembershipStatus;
use App\Models\TenantMembership;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hard gate: an authenticated user may only enter a workspace they hold
 * an ACTIVE membership in. Roles (the soft gate) decide nothing here.
 *
 * 403-vs-404 policy (cite this in module reviews): SURFACE-level denial is an
 * informative 403 — the workspace's existence is public via DNS and a silent
 * 404 for a signed-in civil servant only generates support tickets.
 * RECORD-level denial (a ULID from another tenant) stays a silent 404 —
 * cross-tenant existence must never be confirmable. Oversight roles get no
 * backdoor: a StateAdmin without membership is 403 like anyone else;
 * cross-MDA reads belong to the oversight surface via withoutTenancy().
 */
class EnsureTenantMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = app(CurrentTenant::class)->getOrFail();

        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->id) // sanctioned: membership is the gate deciding tenancy (see model docblock)
            ->where('user_id', $request->user()->id)
            ->first();

        if ($membership === null || $membership->status !== MembershipStatus::Active) {
            return response()->view('errors.no-workspace-access', [
                'tenant' => $tenant,
                'workspaces' => (new ListUserWorkspaces)($request->user()),
            ], 403);
        }

        return $next($request);
    }
}
