<?php

declare(strict_types=1);

namespace App\Modules\Campaigns\Enums;

use App\Modules\Campaigns\Mail\ApplicationRejectedMail;
use App\Modules\Notifications\Enums\NotificationType;

/**
 * WHY an application ended up rejected (AH-058, D4/D5/Q8).
 *
 * `campaign_application.rejected` has two emit sites — the agency's deliberate
 * reject and the campaign-terminal auto-reject — and one type. This enum is the
 * difference between them, and it is a CONTRACT, not an implementation detail:
 * its value travels in the in-app notification's `data.cause` (which the SPA
 * template may read) and in the audit row's metadata, and the blade appends it to
 * `body_` to choose the mail variant. That is why it is an enum rather than two
 * loose strings — a typo in either place would silently render the fallback copy.
 *
 * A third cause would need a body variant in 24 locales, so adding one is a
 * deliberate act; the enum is what makes it deliberate.
 *
 * @see ApplicationRejectedMail the mail variant selector
 * @see NotificationType::CampaignApplicationRejected the one type both sites emit
 */
enum ApplicationRejectionCause: string
{
    /** The agency reviewed the application and answered no (D4). */
    case AgencyRejected = 'agency_rejected';

    /**
     * The campaign went completed/cancelled while the application was still
     * pending, so it was auto-rejected with notice (D5). Never a human's choice
     * about this creator — the copy says so.
     */
    case CampaignClosed = 'campaign_closed';
}
