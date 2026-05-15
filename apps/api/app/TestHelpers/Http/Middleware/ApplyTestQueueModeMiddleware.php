<?php

declare(strict_types=1);

namespace App\TestHelpers\Http\Middleware;

use App\TestHelpers\Http\Controllers\SetQueueModeController;
use App\TestHelpers\TestHelpersServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads the queue-mode override set by the
 * `/_test/queue-mode` endpoint and applies it to
 * `config('queue.default')` for the duration of the current request.
 *
 * Sprint 3 Chunk 3 sub-step 4.
 *
 * The middleware is pushed onto the api group by
 * {@see TestHelpersServiceProvider} ONLY when the test-helpers gate
 * is open (`local`/`testing` + TEST_HELPERS_TOKEN). It additionally
 * re-checks the gate at request time so a runtime config flip
 * (token removed in `.env`) closes the override surface immediately
 * — even though removing the token already disables the
 * /_test/queue-mode controllers, in-cache state would otherwise
 * survive.
 *
 * #40 break-revert: change the gate check to always-pass and
 * confirm a production-environment test exposes the override.
 */
final class ApplyTestQueueModeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! TestHelpersServiceProvider::gateOpen()) {
            return $next($request);
        }

        $mode = Cache::store('file')->get(SetQueueModeController::CACHE_KEY);
        if (is_string($mode) && in_array($mode, SetQueueModeController::ALLOWED_MODES, true)) {
            config()->set('queue.default', $mode);
        }

        return $next($request);
    }
}
