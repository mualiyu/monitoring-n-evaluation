<?php

namespace App\Http\Responses;

use App\Enums\Surface;
use App\Tenancy\CurrentSurface;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request)
    {
        // Portal has no login; tenant/oversight logouts land on their own
        // host's login form.
        return app(CurrentSurface::class)->is(Surface::Portal)
            ? redirect()->route('portal.home')
            : redirect('/login');
    }
}
