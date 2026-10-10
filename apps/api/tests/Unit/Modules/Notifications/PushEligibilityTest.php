<?php

declare(strict_types=1);

use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Push\PushEligibility;
use Tests\TestCase;

uses(TestCase::class);

/**
 * AH-102 — the push eligibility policy, pinned to the ruled sets (Q2 + Q11).
 * 14 eligible: 10 toggleable (job_posted among them, default OFF per type) and
 * 4 always-on. Everything else is out.
 */
function pushValues(array $types): array
{
    $values = array_map(static fn (NotificationType $t): string => $t->value, $types);
    sort($values);

    return $values;
}

it('the toggleable set is exactly the ruled ten', function (): void {
    expect(pushValues(PushEligibility::TOGGLEABLE))->toBe([
        'assignment.completed_on_approval',
        'assignment.draft_approved',
        'assignment.draft_rejected',
        'assignment.invited',
        'assignment.manually_verified',
        'assignment.revision_requested',
        'campaign.job_posted',
        'campaign_application.accepted',
        'message.received_by_creator',
        'message.relationship_received_by_creator',
    ]);
});

it('the always-on set is exactly the ruled four', function (): void {
    expect(pushValues(PushEligibility::ALWAYS_ON))->toBe([
        'agency_creator_relation.admin_connected',
        'agency_creator_relation.disconnected',
        'creator.approved',
        'creator.rejected',
    ]);
});

it('the two sets are disjoint and together make fourteen eligible types', function (): void {
    $both = array_intersect(pushValues(PushEligibility::TOGGLEABLE), pushValues(PushEligibility::ALWAYS_ON));
    $eligible = array_filter(NotificationType::cases(), PushEligibility::isEligible(...));

    expect($both)->toBe([])
        ->and($eligible)->toHaveCount(14);
});

it('every NOT-eligible type is out — agency-only, live_verified, application.rejected, the non-live cases', function (): void {
    $out = [
        // ruled out (Q2)
        NotificationType::AssignmentLiveVerified,
        NotificationType::CampaignApplicationRejected,
        // agency-only recipients — no agency app, no token
        NotificationType::AssignmentDraftSubmitted,
        NotificationType::AssignmentContracted,
        NotificationType::MessageReceivedByAgency,
        NotificationType::MessageRelationshipReceivedByAgency,
        NotificationType::CampaignApplicationSubmitted,
        // not live
        NotificationType::AssignmentDeclined,
        NotificationType::AssignmentCountered,
        NotificationType::AssignmentAccepted,
        NotificationType::AssignmentCancelled,
        NotificationType::AssignmentPaymentFunded,
        NotificationType::AssignmentPaymentReleased,
    ];

    foreach ($out as $type) {
        expect(PushEligibility::isEligible($type))->toBeFalse("{$type->value} must not be push-eligible")
            ->and(PushEligibility::isAlwaysOn($type))->toBeFalse()
            ->and(PushEligibility::isToggleable($type))->toBeFalse();
    }

    // 27 cases = 14 eligible + 13 out: the enumeration above is complete.
    expect(count(NotificationType::cases()))->toBe(14 + count($out));
});

it('always-on and toggleable are mutually exclusive per type', function (): void {
    foreach (NotificationType::cases() as $type) {
        expect(PushEligibility::isAlwaysOn($type) && PushEligibility::isToggleable($type))->toBeFalse();
    }
});
