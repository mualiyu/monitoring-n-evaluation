<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * After "send the verification link again". Fortify flashes its constant
 * `verification-link-sent`, and layouts.auth prints session('status') as the
 * banner text — so the person was shown a code, not a sentence.
 */
class VerificationLinkSentResponse implements EmailVerificationNotificationSentResponse
{
    public function toResponse($request): Response
    {
        return $request->wantsJson()
            ? new JsonResponse('', 202)
            : back()->with('status', __('A new verification link is on its way to your email address.'));
    }
}
