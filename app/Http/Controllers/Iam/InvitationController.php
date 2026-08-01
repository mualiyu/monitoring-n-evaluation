<?php

namespace App\Http\Controllers\Iam;

use App\Actions\Iam\AcceptInvitation;
use App\Enums\Surface;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Tenancy\CurrentSurface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * Guest-facing invitation acceptance. The token is the credential: never
 * logged, never echoed back except in the form action, stored only hashed.
 */
class InvitationController extends Controller
{
    public function show(string $token): View
    {
        $invitation = Invitation::query()
            ->where('token_hash', Invitation::hashToken($token))
            ->first();

        abort_unless($invitation !== null, 404);
        abort_unless($invitation->isPending(), 410, __('This invitation is no longer valid. Ask your administrator for a new one.'));

        return view('auth.accept-invitation', [
            'invitation' => $invitation,
            'needsAccount' => User::query()->where('email', $invitation->email)->doesntExist(),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $profile = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'password' => ['sometimes', 'required', 'confirmed', Password::defaults()],
        ]);

        $user = (new AcceptInvitation)($token, $profile);

        Auth::login($user); // current host only — host-scoped cookies

        $request->session()->regenerate();

        $surface = app(CurrentSurface::class)->get();

        return redirect()->route(
            $surface === Surface::Portal ? 'portal.home' : $surface->dashboardRoute()
        );
    }
}
