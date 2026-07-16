<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Requests\ToggleFeatureFlagRequest;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Facades\Audit;
use App\Modules\Creators\Features\ContractSigningEnabled;
use App\Modules\Creators\Features\CreatorPayoutMethodEnabled;
use App\Modules\Creators\Features\IncompleteCreatorNudgeEnabled;
use App\Modules\Creators\Features\KycVerificationEnabled;
use App\Modules\Creators\Features\PerCampaignContractEnabled;
use App\Modules\Creators\Features\SocialVerificationEnabled;
use App\Modules\Identity\Enums\UserType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * Admin feature-flag toggle (Sprint 13, D-6).
 *
 *   GET  /api/v1/admin/feature-flags             — list every flag + state
 *   POST /api/v1/admin/feature-flags/{flag}      — flip it (reason required)
 *
 * The toggle is the RUNTIME mutation path (docs/feature-flags.md): flags
 * are DB-backed Pennant (the `database` store), so `Feature::activate()` /
 * `Feature::deactivate()` write the global (scope-less) row and the change
 * is live without a deploy. Phase 1 is platform-level on/off only — no
 * per-tenant overrides (tech-debt), so we toggle with no scope argument.
 *
 * Every flip writes a transactional `feature_flag.toggled` audit row with
 * a MANDATORY reason and `{flag, enabled}` metadata — the audit log is the
 * only record of WHY (the `features` table holds only the value).
 *
 * Cross-agency / tenant-less BY DESIGN — a platform capability switch, not
 * an agency-scoped resource. Gated by the platform_admin bounded bypass.
 */
final class AdminFeatureFlagController
{
    /**
     * The admin-toggleable flag registry. Keyed by the Pennant name (the
     * `*Enabled::NAME` constant — the single source of the string), valued
     * with display metadata for the SPA. This is the allowlist: a name not
     * present here is rejected by `toggle()` (a typo can't flip an
     * arbitrary Pennant key).
     *
     * @var array<string, array{label: string, description: string}>
     */
    private const FLAGS = [
        KycVerificationEnabled::NAME => [
            'label' => 'KYC verification',
            'description' => 'Gates the creator KYC step + the /integrations/kyc surface.',
        ],
        CreatorPayoutMethodEnabled::NAME => [
            'label' => 'Creator payout method',
            'description' => 'Gates creator payout-method capture in the wizard.',
        ],
        ContractSigningEnabled::NAME => [
            'label' => 'Contract signing',
            'description' => 'Gates the contract-signing step across the creator surface.',
        ],
        SocialVerificationEnabled::NAME => [
            'label' => 'Social verification',
            'description' => 'Gates social-account verification in the wizard.',
        ],
        PerCampaignContractEnabled::NAME => [
            'label' => 'Per-campaign contracts',
            'description' => 'Switches contract scope to per-campaign instead of per-creator.',
        ],
        IncompleteCreatorNudgeEnabled::NAME => [
            'label' => 'Incomplete-creator nudge',
            'description' => 'Sends a one-time email to creators sitting incomplete for 48+ hours.',
        ],
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        $data = [];
        foreach (self::FLAGS as $name => $meta) {
            $data[] = [
                'id' => $name,
                'type' => 'feature_flags',
                'attributes' => [
                    'name' => $name,
                    'label' => $meta['label'],
                    'description' => $meta['description'],
                    'enabled' => Feature::active($name),
                ],
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function toggle(ToggleFeatureFlagRequest $request, string $flag): JsonResponse
    {
        $this->authorizePlatformAdmin($request);

        if (! array_key_exists($flag, self::FLAGS)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $admin = $request->user();
        $enabled = $request->enabled();
        $reason = $request->reason();
        $before = Feature::active($flag);

        DB::transaction(function () use ($flag, $enabled, $reason, $admin, $before): void {
            if ($enabled) {
                Feature::activate($flag);
            } else {
                Feature::deactivate($flag);
            }

            Audit::log(
                action: AuditAction::FeatureFlagToggled,
                actor: $admin,
                reason: $reason,
                before: ['enabled' => $before],
                after: ['enabled' => $enabled],
                metadata: ['flag' => $flag, 'enabled' => $enabled],
            );
        });

        return response()->json([
            'data' => [
                'id' => $flag,
                'type' => 'feature_flags',
                'attributes' => [
                    'name' => $flag,
                    'label' => self::FLAGS[$flag]['label'],
                    'description' => self::FLAGS[$flag]['description'],
                    'enabled' => Feature::active($flag),
                ],
            ],
        ]);
    }

    private function authorizePlatformAdmin(Request $request): void
    {
        $user = $request->user();
        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }
        if ($user->type !== UserType::PlatformAdmin) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }
}
