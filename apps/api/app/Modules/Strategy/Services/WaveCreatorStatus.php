<?php

declare(strict_types=1);

namespace App\Modules\Strategy\Services;

use App\Modules\Campaigns\Enums\AssignmentStatus;
use App\Modules\Campaigns\Enums\CampaignApplicationStatus;
use App\Modules\Campaigns\Models\CampaignApplication;
use App\Modules\Campaigns\Models\CampaignAssignment;

/**
 * The status of a creator in a wave, READ from the campaign and never stored (PL-3.1 plan 1.4, P4). One pure function and one
 * table; the list loads the wave's applications and live assignments in two queries and calls it per slot.
 *
 * The assignment wins when there is one; otherwise the application decides. Every `AssignmentStatus` case and every
 * `CampaignApplicationStatus` case has a row in {@see self::ASSIGNMENT} and {@see self::APPLICATION}; a test iterates both
 * enums and fails when a case has none, so a case added later forces a decision here.
 */
final class WaveCreatorStatus
{
    public const SHORTLISTED = 'shortlisted';

    public const CONTACTED = 'contacted';

    public const CONFIRMED = 'confirmed';

    public const DECLINED = 'declined';

    /** @var list<string> in display order */
    public const ALL = [self::SHORTLISTED, self::CONTACTED, self::CONFIRMED, self::DECLINED];

    /** @var array<string, array{0: string, 1: string}> assignment status value => [status, detail] */
    public const ASSIGNMENT = [
        'invited' => [self::CONTACTED, 'invited'],
        'countered' => [self::CONTACTED, 'countered'],
        'accepted' => [self::CONFIRMED, 'accepted'],
        'contracted' => [self::CONFIRMED, 'contracted'],
        'producing' => [self::CONFIRMED, 'in_work'],
        'draft_submitted' => [self::CONFIRMED, 'in_work'],
        'revision_requested' => [self::CONFIRMED, 'in_work'],
        'approved' => [self::CONFIRMED, 'in_work'],
        'posted' => [self::CONFIRMED, 'delivered'],
        'live_verified' => [self::CONFIRMED, 'delivered'],
        'manually_verified' => [self::CONFIRMED, 'delivered'],
        'payment_held' => [self::CONFIRMED, 'delivered'],
        'payment_released' => [self::CONFIRMED, 'delivered'],
        'completed_on_approval' => [self::CONFIRMED, 'delivered'],
        'declined' => [self::DECLINED, 'declined'],
        'rejected' => [self::DECLINED, 'draft_rejected'],
        'cancelled' => [self::DECLINED, 'cancelled'],
    ];

    /** @var array<string, array{0: string, 1: string}> application status value => [status, detail] (no live assignment) */
    public const APPLICATION = [
        'pending' => [self::SHORTLISTED, 'applied'],
        // The assignment is missing or soft-deleted: should not occur. Shown as Shortlisted, not as a lost creator.
        'accepted' => [self::SHORTLISTED, 'applied'],
        'rejected' => [self::DECLINED, 'application_rejected'],
    ];

    /**
     * @return array{status: string, detail: string}
     */
    public static function derive(?CampaignApplication $application, ?CampaignAssignment $assignment): array
    {
        if ($assignment !== null) {
            [$status, $detail] = self::ASSIGNMENT[$assignment->status->value];

            // An invitation to a creator whose application the agency accepted is the "Offer sent" step of PL-1.2.
            if ($assignment->status === AssignmentStatus::Invited && $application?->status === CampaignApplicationStatus::Accepted) {
                $detail = 'offer_sent';
            }

            return ['status' => $status, 'detail' => $detail];
        }

        if ($application !== null) {
            [$status, $detail] = self::APPLICATION[$application->status->value];

            return ['status' => $status, 'detail' => $detail];
        }

        return ['status' => self::DECLINED, 'detail' => 'no_source'];
    }
}
