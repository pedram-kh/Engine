<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Core\Errors\ErrorResponse;
use App\Core\Impersonation\HardBlockedActions;
use App\Core\Impersonation\ImpersonationContext;
use App\Modules\Identity\Models\ImpersonationSession;
use App\Modules\Identity\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request impersonation enforcement on the MAIN SPA (Sprint 13, D-10).
 *
 * Appended to the `api` group so it runs on EVERY main-origin request after
 * the session is started. It self-gates: when the main session carries no
 * impersonation ulid (the overwhelming majority of requests) it is a pure
 * pass-through. When it DOES, it is the single server-authoritative gate:
 *
 *   1. TTL (Q2, §5.35) — the row's `expires_at` is the ONLY clock that
 *      counts. An expired (or ended / orphaned) session is REJECTED: the row
 *      is terminated, the `web` session is shredded, and the request gets a
 *      401. This is the break-revert seam — delete the `isExpired()` branch
 *      and an expired impersonation sails through (the
 *      EnforceImpersonationTest TTL case flips red, proving the check is
 *      load-bearing, not advisory).
 *
 *   2. Dual-audit (Q3) — for a LIVE session it pins
 *      {@see ImpersonationContext} (impersonator = the admin, actor = the
 *      impersonated `web` user), so every audit row written downstream is
 *      stamped with both, queryable by the first-class
 *      `audit_logs.impersonator_user_id` column.
 *
 *   3. Hard-blocks — four privilege-sensitive actions are refused at the API
 *      (403, no-op) WHILE impersonating, never merely hidden in the UI:
 *      password change, 2FA disable, contract signing, payment release. The
 *      block is by route NAME so a surface that ships later (payments are
 *      coming-soon this sprint) is covered the moment its route lands.
 *
 * No-escalation is layered: the impersonated `web` session can never reach
 * `api/v1/admin/*` (the two-cookie / `web_admin` isolation), cannot mutate
 * credentials (hard-blocks #1/#2), and cannot outlive its TTL or nest
 * (start refuses a second active session). This middleware owns the runtime
 * half; the route guards + start logic own the structural half.
 */
final class EnforceImpersonation
{
    public function __construct(
        private readonly ImpersonationService $impersonation,
        private readonly ImpersonationContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $ulid = $request->session()->get(ImpersonationService::SESSION_KEY);
        if (! is_string($ulid)) {
            return $next($request);
        }

        /** @var ImpersonationSession|null $session */
        $session = ImpersonationSession::query()->where('ulid', $ulid)->first();

        // Orphaned or explicitly-ended marker left in the session: shred it and
        // refuse. (An end on the admin side marks the row ended; the main tab
        // discovers it here on its next request — server-authoritative.)
        if ($session === null || $session->ended_at !== null) {
            $this->impersonation->tearDownMainSession($request);

            return $this->reject($request, 'admin.impersonation.session_invalid');
        }

        // TTL — server-authoritative. The break-revert seam: removing this
        // branch lets an expired impersonation continue.
        if ($session->isExpired()) {
            $this->impersonation->terminate($session, $session->admin);
            $this->impersonation->tearDownMainSession($request);

            return $this->reject($request, 'admin.impersonation.expired');
        }

        // Live: pin the dual-audit context so every downstream audit row
        // records the impersonator behind the impersonated actor.
        $this->context->set((int) $session->admin_user_id, $session->ulid);

        if ($this->isHardBlocked($request)) {
            return $this->reject(
                $request,
                'admin.impersonation.action_blocked',
                Response::HTTP_FORBIDDEN,
            );
        }

        return $next($request);
    }

    private function isHardBlocked(Request $request): bool
    {
        return HardBlockedActions::matches($request->route()?->getName());
    }

    private function reject(
        Request $request,
        string $code,
        int $status = Response::HTTP_UNAUTHORIZED,
    ): Response {
        $title = match ($code) {
            'admin.impersonation.action_blocked' => 'This action is blocked while impersonating a user.',
            'admin.impersonation.expired' => 'This impersonation session has expired.',
            default => 'This impersonation session is no longer valid.',
        };

        return ErrorResponse::single(
            request: $request,
            status: $status,
            code: $code,
            title: $title,
        );
    }
}
