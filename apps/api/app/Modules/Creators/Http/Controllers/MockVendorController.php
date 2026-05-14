<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Modules\Creators\Integrations\Mock\MockEsignProvider;
use App\Modules\Creators\Integrations\Mock\MockKycProvider;
use App\Modules\Creators\Integrations\Mock\MockPaymentProvider;
use App\Modules\Creators\Jobs\SimulateEsignWebhookJob;
use App\Modules\Creators\Jobs\SimulateKycWebhookJob;
use App\Modules\Creators\Services\InboundWebhookIngestor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;

/**
 * Local mock-vendor pages — the Blade-rendered redirect-bounce
 * targets that stand in for real KYC / e-sign / Stripe Connect
 * hosted flows in dev + CI (Sprint 3 Chunk 2 sub-step 5).
 *
 * Mounted at `/_mock-vendor/{kind}/{session}` and `.../complete`,
 * outside `/api/v1/` because they render HTML pages directly. The
 * routes are tenant-less + unauthenticated by design — Playwright
 * (Chunk 3 E2E) drives them as part of the wizard's redirect
 * sequence; in dev a developer can manually click through them
 * without a creator session.
 *
 * Allowlisted in docs/security/tenancy.md § 4 (sub-step 11) as
 * tenant-less. The session token in the URL is
 * unguessable (mock_kyc_<ulid>) so the pages are not enumerable
 * without prior knowledge of a session ID, satisfying #42.
 *
 * Q-mock-webhook-dispatch decision = (b): completion POSTs
 * dispatch a Simulate*WebhookJob (per kind) that drives the
 * production webhook controller's logic via
 * {@see InboundWebhookIngestor}.
 * The mock-vendor page does NOT make an outbound HTTP call back
 * into the application.
 */
final class MockVendorController
{
    /**
     * GET /_mock-vendor/kyc/{session}
     */
    public function showKyc(Request $request, string $session): Response
    {
        return $this->renderOrUnknown(
            view: 'mock-vendor.kyc',
            session: $session,
            cacheKey: MockKycProvider::sessionCacheKey($session),
        );
    }

    /**
     * GET /_mock-vendor/esign/{session}
     */
    public function showEsign(Request $request, string $session): Response
    {
        return $this->renderOrUnknown(
            view: 'mock-vendor.esign',
            session: $session,
            cacheKey: MockEsignProvider::envelopeCacheKey($session),
        );
    }

    /**
     * GET /_mock-vendor/stripe/{session}
     */
    public function showStripe(Request $request, string $session): Response
    {
        return $this->renderOrUnknown(
            view: 'mock-vendor.stripe',
            session: $session,
            cacheKey: MockPaymentProvider::accountCacheKey($session),
        );
    }

    /**
     * POST /_mock-vendor/kyc/{session}/complete
     */
    public function completeKyc(Request $request, string $session): RedirectResponse
    {
        $cacheKey = MockKycProvider::sessionCacheKey($session);
        $entry = Cache::get($cacheKey);

        if (! is_array($entry)) {
            return redirect('/');
        }

        $outcome = (string) $request->input('outcome', 'cancel');
        $newState = match ($outcome) {
            'success' => 'success',
            'fail' => 'fail',
            default => 'cancelled',
        };

        $entry['state'] = $newState;
        $entry['completed_at'] = now()->toIso8601String();
        Cache::put($cacheKey, $entry, MockKycProvider::SESSION_TTL_SECONDS);

        if ($newState !== 'cancelled' && isset($entry['creator_ulid']) && is_string($entry['creator_ulid'])) {
            SimulateKycWebhookJob::dispatch(
                $entry['creator_ulid'],
                $newState === 'success' ? 'verified' : 'rejected',
            );
        }

        return redirect(url('/api/v1/creators/me/wizard/kyc/return?session='.$session));
    }

    /**
     * POST /_mock-vendor/esign/{session}/complete
     */
    public function completeEsign(Request $request, string $session): RedirectResponse
    {
        $cacheKey = MockEsignProvider::envelopeCacheKey($session);
        $entry = Cache::get($cacheKey);

        if (! is_array($entry)) {
            return redirect('/');
        }

        $outcome = (string) $request->input('outcome', 'cancel');
        $newState = match ($outcome) {
            'success' => 'signed',
            'fail' => 'declined',
            default => 'cancelled',
        };

        $entry['state'] = $newState;
        $entry['completed_at'] = now()->toIso8601String();
        Cache::put($cacheKey, $entry, MockEsignProvider::SESSION_TTL_SECONDS);

        if ($newState !== 'cancelled' && isset($entry['creator_ulid']) && is_string($entry['creator_ulid'])) {
            SimulateEsignWebhookJob::dispatch(
                $entry['creator_ulid'],
                $newState,
            );
        }

        return redirect(url('/api/v1/creators/me/wizard/contract/return?session='.$session));
    }

    /**
     * POST /_mock-vendor/stripe/{session}/complete
     *
     * No webhook is dispatched in Sprint 3 (Q-stripe-no-webhook-
     * acceptable). The wizard's status-poll picks up the cached
     * state on the next call.
     */
    public function completeStripe(Request $request, string $session): RedirectResponse
    {
        $cacheKey = MockPaymentProvider::accountCacheKey($session);
        $entry = Cache::get($cacheKey);

        if (! is_array($entry)) {
            return redirect('/');
        }

        $outcome = (string) $request->input('outcome', 'cancel');
        $newState = match ($outcome) {
            'success' => 'complete',
            default => 'cancelled',
        };

        $entry['state'] = $newState;
        Cache::put($cacheKey, $entry, MockPaymentProvider::SESSION_TTL_SECONDS);

        return redirect(url('/api/v1/creators/me/wizard/payout/return?session='.$session));
    }

    private function renderOrUnknown(string $view, string $session, string $cacheKey): Response
    {
        if (! Cache::has($cacheKey)) {
            return new Response(
                __('mock-vendor.session_unknown'),
                404,
                ['Content-Type' => 'text/plain; charset=utf-8'],
            );
        }

        return new Response(
            View::make($view, ['sessionId' => $session])->render(),
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
        );
    }
}
