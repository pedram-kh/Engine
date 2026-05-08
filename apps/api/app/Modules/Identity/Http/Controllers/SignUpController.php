<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Requests\SignUpRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Services\SignUpService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/auth/sign-up
 *
 *   - 201 Created with the {@see UserResource}, no `Set-Cookie` and no
 *     authentication side effect — the user must verify their email and
 *     then sign in separately.
 *   - 422 with the standard validation envelope when the Form Request
 *     rejects the payload (length, format, breached password, duplicate
 *     email).
 */
final class SignUpController
{
    public function __invoke(SignUpRequest $request, SignUpService $service): JsonResponse
    {
        $user = $service->register($request->validatedAttributes(), $request);

        return UserResource::make($user)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
