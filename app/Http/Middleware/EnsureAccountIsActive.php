<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivated accounts are ejected on their next request — one mechanism
 * covers both login-time and deactivation-mid-session (a login-pipeline
 * check would miss the latter). Message stays neutral on purpose.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Strict false: only an explicit deactivation blocks. An in-memory
        // instance that never loaded the DB default (null) must not eject.
        if ($user !== null && $user->is_active === false) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with(
                'status',
                __('This account is not active. Contact your administrator.'),
            );
        }

        return $next($request);
    }
}
