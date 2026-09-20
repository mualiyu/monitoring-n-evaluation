<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces 2FA ENROLMENT (not just challenge) for roles that mandate it.
 * Middleware, not a Fortify pipeline action, because the rule must hold on
 * every request — including sessions predating the policy and users promoted
 * mid-session. Grace runs from users.two_factor_required_at (stamped by
 * AssignRole); SuperAdmin gets no grace.
 *
 * An account carrying two_factor_exempted_at (written only by the audited
 * ExemptFromTwoFactor action) is released from the mandate entirely: it is
 * never redirected, never shown the countdown, and the setup page treats it
 * as a voluntary visitor.
 */
class RequireTwoFactor
{
    /** Route-name suffixes reachable while enrolment is being forced. */
    private const ALLOWED = [
        'two-factor.', 'password.confirm', 'logout', 'user-password.',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            $user === null
            || $user->two_factor_confirmed_at !== null
            || $user->two_factor_exempted_at !== null
        ) {
            return $next($request);
        }

        $requiredRoles = array_filter(
            Role::cases(),
            fn (Role $role) => $role->requiresTwoFactor() && $user->hasRole($role->value),
        );

        if ($requiredRoles === []) {
            return $next($request);
        }

        $graceDays = min(array_map(fn (Role $role) => $role->twoFactorGraceDays(), $requiredRoles));
        $anchor = $user->two_factor_required_at ?? $user->created_at;
        $deadline = $anchor->addDays($graceDays);

        $routeName = (string) $request->route()?->getName();
        foreach (self::ALLOWED as $allowed) {
            if (str_contains($routeName, $allowed)) {
                // The enrolment pages must state the mandate themselves: the
                // user may arrive voluntarily, mid-grace, or hard-redirected,
                // and only this middleware knows the rule applies to them.
                view()->share('twoFactorMandatory', true);
                view()->share('twoFactorDeadline', $deadline);

                return $next($request);
            }
        }

        if (now()->lessThan($deadline)) {
            session()->flash('two_factor_deadline', $deadline);

            return $next($request);
        }

        return redirect('/two-factor/setup')
            ->with('warning', __('Two-factor authentication is required for your role. Set it up to continue.'));
    }
}
