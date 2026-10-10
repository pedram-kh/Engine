<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use App\Modules\Notifications\Push\PushDispatcher;
use Closure;

/**
 * Pennant feature flag — the SEND kill-switch for push notifications (AH-102, Q12).
 *
 * ⚠ It gates SENDING ONLY. Device-token registration (`POST /me/push-tokens`) is
 * deliberately NOT behind it, so an app build that asks for permission and
 * registers a token before the flag is armed still works — the token simply
 * waits. The in-app row is never affected (push is independent of it).
 *
 * ⚠ DO NOT ARM until BOTH are true:
 *   1. the FCM credential is present and valid in the target environment
 *      (`FCM_PROJECT_ID` set; `FCM_SERVICE_ACCOUNT_JSON_B64` set and decodes to
 *      valid JSON), and
 *   2. `php artisan push:test --user=<id> --validate-only` passed there.
 * And arm it BEFORE the first push-capable store build reaches users, or those
 * users' first sessions register tokens that never receive anything.
 *
 * Checked INSIDE {@see PushDispatcher} — the single consumer, after the static
 * eligibility check (so an ineligible type never even reads the flag).
 *
 * Default state = OFF: push ships dark.
 *
 * Lives in the CREATORS feature registry despite gating a NOTIFICATIONS-module
 * dispatcher — the existing one-registry convention.
 */
final class PushNotificationsEnabled
{
    /**
     * Snake-cased registry name (docs/feature-flags.md). Every
     * `Feature::active(...)` call site MUST reference this constant.
     */
    public const NAME = 'push_notifications_enabled';

    /**
     * Default resolver. MUST return a Closure (Pennant stores a non-Closure
     * second argument of `define()` as the literal value).
     */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
