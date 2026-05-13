<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * Wizard step identifiers used in the GET /api/v1/creators/me bootstrap
 * response (the next_step hint and the per-step completion map).
 *
 * Step 1 (account creation) is NOT in this enum — it's handled by the
 * Identity module's sign-up endpoint and is "complete" by definition the
 * moment the creator can authenticate.
 *
 * Q2 (resume UX): the bootstrap response keys per-step status by these
 * identifiers (not by step number) so the order can change without
 * breaking the API contract. The frontend (Chunk 3) decides whether to
 * auto-advance, show an intermediate page, or hybrid.
 *
 * Wizard step ordering is currently:
 *   profile (2) → social (3) → portfolio (4) → kyc (5) → tax (6) →
 *   payout (7) → contract (8) → review (9, the submit action)
 */
enum WizardStep: string
{
    case Profile = 'profile';
    case Social = 'social';
    case Portfolio = 'portfolio';
    case Kyc = 'kyc';
    case Tax = 'tax';
    case Payout = 'payout';
    case Contract = 'contract';
    case Review = 'review';

    /**
     * Wizard steps in display order. Used by the bootstrap response and
     * by CompletenessScoreCalculator's source-inspection regression test.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [
            self::Profile,
            self::Social,
            self::Portfolio,
            self::Kyc,
            self::Tax,
            self::Payout,
            self::Contract,
            self::Review,
        ];
    }
}
