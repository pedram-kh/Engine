<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\ResendVerificationRequest;
use App\Modules\Identity\Services\EmailVerificationService;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/auth/resend-verification
 *
 * Always returns 204, whether the email belongs to a real user or not
 * (user-enumeration defence). The 1/min/email rate limit is applied at
 * the route layer via the `auth-resend-verification` named limiter.
 */
final class ResendVerificationController
{
    public function __invoke(ResendVerificationRequest $request, EmailVerificationService $service): Response
    {
        $service->resend($request->emailInput(), $request);

        return new Response(status: 204);
    }
}
