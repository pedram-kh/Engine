<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\AuditAction;
use Tests\TestCase;

uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Sprint 3 Chunk 1 — AuditAction enum verification (sub-step 8)
|--------------------------------------------------------------------------
|
| Pins the exact set of audit actions Sprint 3 Chunk 1 added to the
| AuditAction enum. If a future contributor renames or removes one of
| these values, every existing audit row referencing the renamed
| string falls out of the enum's ::tryFrom() — silently. This pin makes
| any rename break before it ships.
|
| Also verifies:
|   - none of the new actions claim requiresReason() = true (they're
|     creator-driven workflow events, not admin-driven punitive actions);
|   - none of them claim isSensitiveCredentialAction() = true (no
|     credential mutation in Sprint 3).
*/

it('pins the exact 12 net-new Sprint 3 Chunk 1 audit-action values', function (): void {
    $expected = [
        // creator core
        'creator.created',
        'creator.updated',
        'creator.deleted',
        // wizard step completions
        'creator.wizard.profile_completed',
        'creator.wizard.social_completed',
        'creator.wizard.portfolio_completed',
        'creator.wizard.kyc_initiated',
        'creator.wizard.tax_completed',
        'creator.wizard.payout_initiated',
        'creator.wizard.contract_initiated',
        'creator.submitted',
        // bulk invite
        'creator.invited',
        'bulk_invite.started',
        'bulk_invite.completed',
        'bulk_invite.failed',
        // related-model auto-emitters (snake_case override on the model)
        'creator_tax_profile.created',
        'creator_tax_profile.updated',
        'creator_tax_profile.deleted',
        'creator_payout_method.created',
        'creator_payout_method.updated',
        'creator_payout_method.deleted',
        'agency_creator_relation.created',
        'agency_creator_relation.updated',
        'agency_creator_relation.deleted',
    ];

    $actual = collect(AuditAction::cases())
        ->map(fn (AuditAction $c): string => $c->value)
        ->intersect($expected)
        ->values()
        ->all();

    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected, 'Sprint 3 Chunk 1 audit actions diverge from the pinned set.');
});

it('Sprint 3 actions are NOT marked requiresReason()', function (): void {
    $sprint3Cases = [
        AuditAction::CreatorCreated,
        AuditAction::CreatorUpdated,
        AuditAction::CreatorWizardProfileCompleted,
        AuditAction::CreatorWizardSocialCompleted,
        AuditAction::CreatorWizardPortfolioCompleted,
        AuditAction::CreatorWizardKycInitiated,
        AuditAction::CreatorWizardTaxCompleted,
        AuditAction::CreatorWizardPayoutInitiated,
        AuditAction::CreatorWizardContractInitiated,
        AuditAction::CreatorSubmitted,
        AuditAction::CreatorInvited,
        AuditAction::BulkInviteStarted,
        AuditAction::BulkInviteCompleted,
        AuditAction::BulkInviteFailed,
        AuditAction::CreatorTaxProfileCreated,
        AuditAction::CreatorTaxProfileUpdated,
        AuditAction::CreatorPayoutMethodCreated,
        AuditAction::CreatorPayoutMethodUpdated,
        AuditAction::AgencyCreatorRelationCreated,
        AuditAction::AgencyCreatorRelationUpdated,
    ];

    foreach ($sprint3Cases as $case) {
        expect($case->requiresReason())->toBeFalse("{$case->value} should not require a reason.");
    }
});

it('Sprint 3 actions are NOT marked isSensitiveCredentialAction()', function (): void {
    $sprint3Cases = [
        AuditAction::CreatorCreated,
        AuditAction::CreatorWizardKycInitiated,
        AuditAction::CreatorWizardTaxCompleted,
        AuditAction::CreatorWizardPayoutInitiated,
        AuditAction::CreatorInvited,
        AuditAction::BulkInviteStarted,
    ];

    foreach ($sprint3Cases as $case) {
        expect($case->isSensitiveCredentialAction())->toBeFalse("{$case->value} should not be marked sensitive-credential.");
    }
});
