<?php

declare(strict_types=1);

namespace App\Modules\Creators\Features;

use App\Modules\Campaigns\Listeners\SendAssignmentNotifications;
use Closure;

/**
 * Pennant feature flag — gates the MAIL leg of the creator's "your post was
 * verified" notice (AH-101 ⑦, `assignment.live_verified`).
 *
 * ⚠ It gates MAIL ONLY. The in-app row is written regardless (honouring the
 * recipient's own `in_app` preference) — the same "flag gates mail; in-app is the
 * always-works half" rule every prior mail flag in this registry states
 * ({@see MissingCreatorMailsEnabled}, {@see ApplicationNotificationsEnabled}).
 *
 * ⚠ DO NOT ARM until production is confirmed to run a REAL (non-mock)
 * verification driver. `social_verification_enabled` is driver-based and defaults
 * ON over the `mock` driver ({@see SocialVerificationEnabled::default()}); if
 * `SOCIAL_PROVIDER` is unset in production, posts are being mock-"verified" today
 * and an email telling a creator their post was verified would be false. The
 * read-back that clears this is recorded in the AH-101 review.
 *
 * Checked INSIDE {@see SendAssignmentNotifications} — the single consumer.
 *
 * Default state = OFF: the mail ships dark. Single-recipient (one creator per
 * verified post), so there is no per-run cap to size.
 *
 * Lives in the CREATORS feature registry despite gating a CAMPAIGNS-module
 * listener — the existing one-registry convention.
 */
final class PostVerifiedMailEnabled
{
    /**
     * Snake-cased registry name (docs/feature-flags.md). Every
     * `Feature::active(...)` call site MUST reference this constant.
     */
    public const NAME = 'post_verified_mail_enabled';

    /**
     * Default resolver. MUST return a Closure (Pennant stores a non-Closure
     * second argument of `define()` as the literal value).
     */
    public static function default(): Closure
    {
        return static fn (mixed $scope = null): bool => false;
    }
}
