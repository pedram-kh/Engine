<?php

declare(strict_types=1);

namespace App\Modules\Creators\Enums;

/**
 * The two variants of the incomplete-creator email nudge (D4). One command,
 * one stamp, two copies — selected by the recipient's `users.email_verified_at`:
 *
 *   - Verify  → email_verified_at IS NULL: a fresh verify-email link
 *               ({frontend_main_url}/auth/verify-email?token=…).
 *   - Finish  → email_verified_at IS NOT NULL: the finish-profile deep link
 *               ({frontend_main_url}/onboarding — the guard + next_step
 *               resumption does the routing; no step encoding, no magic-login).
 *
 * The value is the i18n sub-namespace (`creators.incomplete_nudge.<value>.*`)
 * AND the Blade template suffix (`mail.creators.incomplete-nudge-<value>`).
 */
enum IncompleteCreatorNudgeVariant: string
{
    case Verify = 'verify';
    case Finish = 'finish';
}
