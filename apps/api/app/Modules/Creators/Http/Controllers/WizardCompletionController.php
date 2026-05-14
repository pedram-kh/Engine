<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Creators\Integrations\Contracts\EsignProvider;
use App\Modules\Creators\Integrations\Contracts\KycProvider;
use App\Modules\Creators\Integrations\Contracts\PaymentProvider;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\WizardCompletionService;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Wizard-completion endpoints (Sprint 3 Chunk 2 sub-step 6).
 *
 * Six endpoints across three vendor-gated steps:
 *
 *   GET /api/v1/creators/me/wizard/kyc/status
 *   GET /api/v1/creators/me/wizard/kyc/return
 *   GET /api/v1/creators/me/wizard/contract/status
 *   GET /api/v1/creators/me/wizard/contract/return
 *   GET /api/v1/creators/me/wizard/payout/status
 *   GET /api/v1/creators/me/wizard/payout/return
 *
 * The status-poll endpoints are called by the frontend after the
 * creator returns from the vendor's hosted flow (or in
 * non-webhook flows, periodically while the creator stays on the
 * wizard). The return endpoints are the redirect-bounce targets
 * that the mock-vendor pages (sub-step 5) redirect to. Both
 * shapes call the same {@see WizardCompletionService} method;
 * only the response shape differs (status returns the status
 * payload; return is intended to redirect to the next wizard
 * step in Chunk 3 — for Chunk 2's backend-only scope, both
 * return JSON).
 *
 * Auth shape inherited from the `creators.me.*` route group:
 *   - auth:web      (authenticated session)
 *   - tenancy.set   (populates context if creator also belongs to an agency)
 *   - verified      (P1 sub-step 1 gate)
 */
final class WizardCompletionController
{
    public function __construct(
        private readonly WizardCompletionService $service,
    ) {}

    public function kycStatus(Request $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        try {
            $result = $this->service->pollKyc($creator, app(KycProvider::class));
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'creator.wizard.feature_disabled')) {
                return $this->featureDisabledResponse($request, 'kyc');
            }
            throw $e;
        }

        return new JsonResponse([
            'data' => [
                'status' => $result['status']->value,
                'transitioned' => $result['transitioned'],
            ],
        ]);
    }

    public function kycReturn(Request $request): JsonResponse
    {
        return $this->kycStatus($request);
    }

    public function contractStatus(Request $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        try {
            $result = $this->service->pollContract($creator, app(EsignProvider::class));
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'creator.wizard.feature_disabled')) {
                return $this->featureDisabledResponse($request, 'contract');
            }
            throw $e;
        }

        return new JsonResponse([
            'data' => [
                'status' => $result['status']->value,
                'transitioned' => $result['transitioned'],
            ],
        ]);
    }

    public function contractReturn(Request $request): JsonResponse
    {
        return $this->contractStatus($request);
    }

    public function payoutStatus(Request $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        try {
            $result = $this->service->pollPayout($creator, app(PaymentProvider::class));
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'creator.wizard.feature_disabled')) {
                return $this->featureDisabledResponse($request, 'payout');
            }
            throw $e;
        }

        return new JsonResponse([
            'data' => [
                'fully_onboarded' => $result['status']->isFullyOnboarded(),
                'charges_enabled' => $result['status']->chargesEnabled,
                'payouts_enabled' => $result['status']->payoutsEnabled,
                'details_submitted' => $result['status']->detailsSubmitted,
                'requirements_currently_due' => $result['status']->requirementsCurrentlyDue,
                'transitioned' => $result['transitioned'],
            ],
        ]);
    }

    public function payoutReturn(Request $request): JsonResponse
    {
        return $this->payoutStatus($request);
    }

    private function featureDisabledResponse(Request $request, string $step): JsonResponse
    {
        return ErrorResponse::single(
            $request,
            409,
            'creator.wizard.feature_disabled',
            "The {$step} step is currently disabled by feature flag.",
        );
    }

    private function requireCreator(Request $request): Creator
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(ErrorResponse::single($request, 401, 'auth.unauthenticated', 'Authentication required.'));
        }

        $creator = $user->creator;

        if (! $creator instanceof Creator) {
            abort(ErrorResponse::single($request, 404, 'creator.not_found', 'No creator profile is associated with this user.'));
        }

        return $creator;
    }
}
