<?php

declare(strict_types=1);

namespace App\Modules\Creators\Exceptions;

use App\Core\Errors\ErrorResponse;
use App\Modules\Creators\Models\Creator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * AH-104b-del (C4): a NEW agency-side action was aimed at a creator who is
 * scheduled for deletion or already erased.
 *
 * Renders itself as the neutral 422 `creator.unavailable`. The title and the
 * code never say WHY (pending or erased): an agency that holds a creator id
 * learns "not available", the same word for both states, so the request is
 * not a way to find out that a person asked to delete their account (D5).
 * Existing threads, assignments and roster rows are NOT refused through this:
 * only the doors that START something are (invite, connection request, pool
 * add, accepting an application). See {@see Creator::assertAvailableToAgencies()}.
 */
final class CreatorUnavailableException extends RuntimeException
{
    public const string CODE = 'creator.unavailable';

    public function __construct()
    {
        parent::__construct('This creator is not available.');
    }

    public function render(Request $request): JsonResponse
    {
        return ErrorResponse::single(
            request: $request,
            status: Response::HTTP_UNPROCESSABLE_ENTITY,
            code: self::CODE,
            title: 'This creator is not available.',
        );
    }
}
