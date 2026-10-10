<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Push;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;

/**
 * AH-102 (Q2/Q3/Q11) — which notification types may be pushed, and which of those
 * the recipient can switch off. Static, by TYPE only: the dispatcher asks this
 * first, so an ineligible type (every agency-only type, `live_verified`,
 * `application.rejected`, the not-yet-live cases) costs zero queries.
 *
 * The lists are plain `NotificationType::X` lines on purpose — the SPA parity
 * spec (`templates.spec.ts`) reads them from this source, the same technique it
 * uses for the backend `NotificationType` enum, so the FE registry and this
 * policy cannot drift. `PushEligibilityTest` pins both to the ruled sets.
 *
 * Out, by ruling: `assignment.live_verified` (machine verification; mock-driver
 * risk), `campaign_application.rejected` (auto-reject bursts, low urgency), all
 * five agency-only types (no agency app → no agency token).
 */
final class PushEligibility
{
    /**
     * Eligible and user-toggleable (a `push` cell in the preference matrix).
     * `campaign.job_posted` is in this list but defaults OFF per type
     * ({@see NotificationChannel::defaultEnabledFor()}).
     *
     * @var list<NotificationType>
     */
    public const array TOGGLEABLE = [
        NotificationType::AssignmentInvited,
        NotificationType::AssignmentRevisionRequested,
        NotificationType::AssignmentDraftApproved,
        NotificationType::AssignmentDraftRejected,
        NotificationType::AssignmentCompletedOnApproval,
        NotificationType::AssignmentManuallyVerified,
        NotificationType::MessageReceivedByCreator,
        NotificationType::MessageRelationshipReceivedByCreator,
        NotificationType::CampaignApplicationAccepted,
        NotificationType::CampaignJobPosted,
    ];

    /**
     * Eligible and ALWAYS delivered — no toggle, and the dispatcher never reads a
     * push preference for these (so a stray stored row cannot suppress them).
     * Matches how their email behaves: account-lifecycle / relationship news the
     * creator must not be able to miss.
     *
     * @var list<NotificationType>
     */
    public const array ALWAYS_ON = [
        NotificationType::CreatorApproved,
        NotificationType::CreatorRejected,
        NotificationType::RelationAdminConnected,
        NotificationType::RelationDisconnected,
    ];

    public static function isEligible(NotificationType $type): bool
    {
        return in_array($type, self::TOGGLEABLE, true) || in_array($type, self::ALWAYS_ON, true);
    }

    public static function isAlwaysOn(NotificationType $type): bool
    {
        return in_array($type, self::ALWAYS_ON, true);
    }

    public static function isToggleable(NotificationType $type): bool
    {
        return in_array($type, self::TOGGLEABLE, true);
    }
}
