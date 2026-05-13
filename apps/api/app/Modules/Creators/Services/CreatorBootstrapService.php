<?php

declare(strict_types=1);

namespace App\Modules\Creators\Services;

use App\Modules\Creators\Enums\ApplicationStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Enums\VerificationLevel;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\UserType;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns Creator-row creation at sign-up time.
 *
 * Why this seam exists (D-pause-4):
 *   The Identity module's SignUpService creates the User row. Per spec
 *   §5 every creator-typed user MUST also have a Creator row from
 *   sign-up onwards (so wizard-step writes can rely on the row existing
 *   instead of creating-on-first-write). The natural home for that
 *   creation is the Creators module, not Identity.
 *
 *   SignUpService delegates to this service inside its existing
 *   transaction. The single transaction guarantees that no
 *   User-without-Creator state ever exists at the database level.
 *
 * Module-seam discipline:
 *   This service is the ONLY public path the Identity module uses
 *   to mutate creator state. Identity calls this method; nothing else
 *   in Identity touches the Creators module.
 */
final class CreatorBootstrapService
{
    /**
     * Create the Creator satellite row for a freshly-created User.
     *
     * Idempotent: if the user already has a creator row (e.g. resubmit
     * after a partial transaction failure that committed only the user
     * row — not currently possible given the wrapping transaction, but
     * defensive), the existing row is returned unchanged.
     *
     * @throws RuntimeException When the user is not creator-typed.
     */
    public function bootstrapForUser(User $user): Creator
    {
        if ($user->type !== UserType::Creator) {
            throw new RuntimeException(
                "CreatorBootstrapService::bootstrapForUser called for non-creator user (type: {$user->type->value}).",
            );
        }

        return DB::transaction(function () use ($user): Creator {
            $existing = Creator::query()->where('user_id', $user->id)->first();
            if ($existing !== null) {
                return $existing;
            }

            // The Audited trait emits creator.created via the model's
            // create event. We do NOT re-emit a wizard-step event here —
            // bootstrap is not a wizard step (Step 1 is the sign-up
            // endpoint, which emits auth.signup separately).
            return Creator::query()->create([
                'user_id' => $user->id,
                'verification_level' => VerificationLevel::Unverified,
                'application_status' => ApplicationStatus::Incomplete,
                'kyc_status' => KycStatus::None,
                'profile_completeness_score' => 0,
                'tax_profile_complete' => false,
                'payout_method_set' => false,
                // primary_language seeded from the user's preference so the
                // wizard form has a sensible default. Wizard Step 2 may
                // change it.
                'primary_language' => $user->preferred_language,
            ]);
        });
    }
}
