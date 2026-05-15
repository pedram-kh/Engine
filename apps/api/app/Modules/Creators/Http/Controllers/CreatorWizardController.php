<?php

declare(strict_types=1);

namespace App\Modules\Creators\Http\Controllers;

use App\Core\Errors\ErrorResponse;
use App\Modules\Creators\Enums\SocialPlatform;
use App\Modules\Creators\Enums\TaxFormType;
use App\Modules\Creators\Http\Requests\ConnectSocialRequest;
use App\Modules\Creators\Http\Requests\UpdateProfileRequest;
use App\Modules\Creators\Http\Requests\UpsertTaxProfileRequest;
use App\Modules\Creators\Http\Resources\CreatorResource;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Services\CompletenessScoreCalculator;
use App\Modules\Creators\Services\ContractTermsRenderer;
use App\Modules\Creators\Services\CreatorWizardService;
use App\Modules\Identity\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * The 8 wizard step endpoints + GET /me bootstrap.
 *
 *   GET    /api/v1/creators/me                   bootstrap (CreatorResource)
 *   PATCH  /api/v1/creators/me/wizard/profile    Step 2
 *   POST   /api/v1/creators/me/wizard/social     Step 3
 *   POST   /api/v1/creators/me/wizard/portfolio  Step 4 (delegates to PortfolioController)
 *   POST   /api/v1/creators/me/wizard/kyc        Step 5
 *   PATCH  /api/v1/creators/me/wizard/tax        Step 6
 *   POST   /api/v1/creators/me/wizard/payout     Step 7
 *   POST   /api/v1/creators/me/wizard/contract   Step 8
 *   POST   /api/v1/creators/me/wizard/submit     Step 9
 *
 * Each endpoint resolves the authenticated user's Creator row via the
 * `User->creator` relationship. The bootstrap endpoint returns a 404
 * if the user has no Creator row (defensive — sign-up always provisions
 * one via CreatorBootstrapService, so this is a should-never-happen
 * branch with a clear error code).
 *
 * Provider-backed steps (kyc, payout, contract) propagate the
 * ProviderNotBoundException as a 503-style error during Sprint 3
 * Chunk 1; Chunk 2 binds the Mock providers.
 */
final class CreatorWizardController
{
    public function __construct(
        private readonly CreatorWizardService $wizardService,
        private readonly CompletenessScoreCalculator $calculator,
        private readonly ContractTermsRenderer $contractTermsRenderer,
    ) {}

    /**
     * GET /api/v1/creators/me — bootstrap response. Stable shape with
     * GET /api/v1/admin/creators/{creator} per Q2 (resume UX).
     */
    public function show(Request $request): JsonResponse
    {
        $creator = $this->resolveCreator($request);

        if ($creator === null) {
            return ErrorResponse::single(
                $request,
                404,
                'creator.not_found',
                'No creator profile is associated with this user.',
            );
        }

        return (new CreatorResource($creator, $this->calculator))
            ->response();
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        $this->wizardService->updateProfile($creator, $request->validated());

        return (new CreatorResource($creator->refresh(), $this->calculator))
            ->response();
    }

    public function connectSocial(ConnectSocialRequest $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        $this->wizardService->connectSocial($creator, [
            'platform' => SocialPlatform::from((string) $request->string('platform')),
            'handle' => (string) $request->string('handle'),
            'profile_url' => (string) $request->string('profile_url'),
        ]);

        return (new CreatorResource($creator->refresh(), $this->calculator))
            ->response();
    }

    public function initiateKyc(Request $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        try {
            $result = $this->wizardService->initiateKyc($creator);
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'creator.wizard.feature_disabled')) {
                return $this->featureDisabledResponse($request, 'kyc');
            }
            throw $e;
        }

        return response()->json([
            'data' => [
                'session_id' => $result->sessionId,
                'hosted_flow_url' => $result->hostedFlowUrl,
                'expires_at' => $result->expiresAt,
            ],
        ]);
    }

    public function upsertTaxProfile(UpsertTaxProfileRequest $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        $this->wizardService->upsertTaxProfile($creator, [
            'tax_form_type' => TaxFormType::from((string) $request->string('tax_form_type')),
            'legal_name' => (string) $request->string('legal_name'),
            'tax_id' => (string) $request->string('tax_id'),
            'address' => $request->collect('address')->toArray(),
        ]);

        return (new CreatorResource($creator->refresh(), $this->calculator))
            ->response();
    }

    public function initiatePayout(Request $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        try {
            $result = $this->wizardService->initiatePayout($creator);
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'creator.wizard.feature_disabled')) {
                return $this->featureDisabledResponse($request, 'payout');
            }
            throw $e;
        }

        return response()->json([
            'data' => [
                'account_id' => $result->accountId,
                'onboarding_url' => $result->onboardingUrl,
                'expires_at' => $result->expiresAt,
            ],
        ]);
    }

    public function initiateContract(Request $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        try {
            $result = $this->wizardService->initiateContract($creator);
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'creator.wizard.feature_disabled')) {
                return $this->featureDisabledResponse($request, 'contract');
            }
            throw $e;
        }

        return response()->json([
            'data' => [
                'envelope_id' => $result->envelopeId,
                'signing_url' => $result->signingUrl,
                'expires_at' => $result->expiresAt,
            ],
        ]);
    }

    /**
     * GET /api/v1/creators/me/wizard/contract/terms
     *
     * Server-rendered master agreement HTML — Sprint 3 Chunk 3
     * sub-step 4. The SPA's click-through fallback uses this as the
     * canonical source so an attacker who can poison the SPA bundle
     * still cannot inject content into the legally-binding terms
     * surface (#40 break-revert: try replacing the response with a
     * raw markdown string and confirm the SPA's scrollable region
     * fails to render the formatted document).
     *
     * The endpoint is creator-scoped (auth + verified) like the
     * rest of the `creators/me/*` group. The `?locale=` query
     * parameter is optional; the renderer falls back to `en` when
     * the requested locale has no source file.
     */
    public function getContractTerms(Request $request): JsonResponse
    {
        // Ensure a creator exists before serving the terms — the
        // endpoint is otherwise generic, but gating on the creator
        // existence matches the rest of the /creators/me/* group.
        $this->requireCreator($request);

        $requested = (string) $request->query('locale', $request->getPreferredLanguage(['en', 'pt', 'it']) ?? 'en');
        $rendered = $this->contractTermsRenderer->render($requested);

        return response()->json([
            'data' => [
                'html' => $rendered['html'],
                'version' => $rendered['version'],
                'locale' => $rendered['locale'],
            ],
        ]);
    }

    /**
     * POST /api/v1/creators/me/wizard/contract/click-through-accept
     *
     * Click-through-acceptance fallback for the master-contract
     * step when `contract_signing_enabled` is OFF
     * (Q-flag-off-2 = (a)). The endpoint refuses with 409 if the
     * flag is ON — envelope mode is the canonical path in that
     * state and the click-through is the operator-bypass route.
     * Idempotent on re-submit.
     */
    public function clickThroughAcceptContract(Request $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        try {
            $this->wizardService->acceptClickThroughContract($creator);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'creator.wizard.feature_enabled') {
                return ErrorResponse::single(
                    $request,
                    409,
                    'creator.wizard.feature_enabled',
                    'Click-through acceptance is only available while contract signing is disabled. Use the envelope flow instead.',
                );
            }
            throw $e;
        }

        return (new CreatorResource($creator->refresh(), $this->calculator))
            ->response();
    }

    public function submit(Request $request): JsonResponse
    {
        $creator = $this->requireCreator($request);

        try {
            $this->wizardService->submit($creator);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'creator.wizard.incomplete') {
                return ErrorResponse::single(
                    $request,
                    409,
                    'creator.wizard.incomplete',
                    'Cannot submit: one or more wizard steps are incomplete.',
                );
            }
            throw $e;
        }

        return (new CreatorResource($creator->refresh(), $this->calculator))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    private function featureDisabledResponse(Request $request, string $step): JsonResponse
    {
        return ErrorResponse::single(
            $request,
            409,
            'creator.wizard.feature_disabled',
            "The {$step} step is currently disabled by feature flag. Route to the skip-path UI instead.",
        );
    }

    private function resolveCreator(Request $request): ?Creator
    {
        /** @var User $user */
        $user = $request->user();

        return $user->creator;
    }

    private function requireCreator(Request $request): Creator
    {
        $creator = $this->resolveCreator($request);

        if ($creator === null) {
            abort(response()->json([
                'errors' => [[
                    'status' => '404',
                    'code' => 'creator.not_found',
                    'detail' => 'No creator profile is associated with this user.',
                ]],
            ], 404));
        }

        return $creator;
    }
}
