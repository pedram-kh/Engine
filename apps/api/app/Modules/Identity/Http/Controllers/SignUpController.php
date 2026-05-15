<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Identity\Exceptions\InvitationAcceptException;
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
 *   - 422 + `invitation.*` code when the optional `invitation_token`
 *     path fails post-submit (token not found / expired / already
 *     accepted / email_mismatch — Sprint 3 Chunk 4).
 */
final class SignUpController
{
    public function __invoke(SignUpRequest $request, SignUpService $service): JsonResponse
    {
        try {
            $user = $service->register($request->validatedAttributes(), $request);
        } catch (InvitationAcceptException $e) {
            return ErrorResponse::single(
                $request,
                422,
                $e->errorCode,
                $e->getMessage(),
                source: $e->errorCode === 'invitation.email_mismatch'
                    ? ['pointer' => '/data/attributes/email']
                    : ['pointer' => '/data/attributes/invitation_token'],
            );
        }

        return UserResource::make($user)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
