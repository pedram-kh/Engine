<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Middleware;

use App\Core\Errors\ErrorResponse;
use App\Modules\Messaging\Features\MessagesV2EditSearchEnabled;
use Closure;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\Response;

/**
 * AH-119 Stage B — 404 `not_found` on the eight edit/delete routes and the message search while
 * `messages_v2_edit_search_enabled` is off. Runs after authentication and tenancy, so an anonymous
 * caller still gets 401 and a non-member still gets the tenancy 404; nothing else is evaluated, and
 * nothing is written, while the flag is off.
 */
final class RequireMessagesV2EditSearch
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Feature::active(MessagesV2EditSearchEnabled::NAME)) {
            return ErrorResponse::single($request, Response::HTTP_NOT_FOUND, 'not_found', 'Not found.');
        }

        return $next($request);
    }
}
