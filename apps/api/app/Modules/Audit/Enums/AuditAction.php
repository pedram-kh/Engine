<?php

declare(strict_types=1);

namespace App\Modules\Audit\Enums;

use App\Modules\Audit\Concerns\Audited;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Identity\Models\User;

/**
 * Catalogue of audit-loggable actions.
 *
 * Sprint 1 / chunk 2 ships only the auth-related verbs needed by the
 * Identity, Audit, and Authorization modules plus the minimum `user.*`
 * verbs required to exercise the {@see Audited}
 * trait on the {@see User} model.
 *
 * Subsequent chunks (3-8) extend this enum with their own verbs (creator.*,
 * agency.*, campaign.*, payment.*, etc.) when they implement those modules.
 *
 * Naming convention (docs/03-DATA-MODEL.md §12, docs/05-SECURITY-COMPLIANCE.md §3.2):
 * `<subject>.<verb>` in lowercase dot notation. Subject is the singular,
 * lower-case form of the model or domain noun the action operates on.
 */
enum AuditAction: string
{
    case AuthSignedUp = 'auth.signup';
    case AuthLoginSucceeded = 'auth.login.succeeded';
    case AuthLoginFailed = 'auth.login.failed';
    case AuthLogout = 'auth.logout';
    case AuthPasswordResetRequested = 'auth.password.reset_requested';
    case AuthPasswordResetCompleted = 'auth.password.reset_completed';
    case AuthPasswordChanged = 'auth.password.changed';
    case AuthEmailVerificationSent = 'auth.email.verification_sent';
    case AuthEmailVerified = 'auth.email.verified';
    case AuthAccountLockedSuspended = 'auth.account_locked.suspended';
    case AuthAccountUnlocked = 'auth.account_unlocked';

    // MFA verbs (chunk 5). Per-attempt TOTP verification is intentionally
    // NOT audited — the LoginSucceeded row carries `mfa: true` metadata
    // when 2FA was used, and only the more interesting state changes
    // (enable/confirm/disable/regenerate/consume/enrollment-suspend) emit
    // their own audit rows.
    case MfaEnabled = 'mfa.enabled';
    case MfaConfirmed = 'mfa.confirmed';
    case MfaDisabled = 'mfa.disabled';
    case MfaRecoveryCodesRegenerated = 'mfa.recovery_codes_regenerated';
    case MfaRecoveryCodeConsumed = 'mfa.recovery_code_consumed';
    case MfaEnrollmentSuspended = 'mfa.enrollment_suspended';

    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserDeleted = 'user.deleted';
    case UserSuspended = 'user.suspended';
    case UserUnsuspended = 'user.unsuspended';

    // Brand verbs (Sprint 2 Chunk 1).
    case BrandCreated = 'brand.created';
    case BrandUpdated = 'brand.updated';
    case BrandArchived = 'brand.archived';
    case BrandRestored = 'brand.restored';

    // Agency user invitation verbs (Sprint 2 Chunk 1).
    case InvitationCreated = 'invitation.created';
    case InvitationAccepted = 'invitation.accepted';
    case InvitationExpiredOnAttempt = 'invitation.expired_on_attempt';

    // Agency settings verbs (Sprint 2 Chunk 1).
    case AgencySettingsUpdated = 'agency_settings.updated';

    // Creator domain (Sprint 3 Chunk 1).
    // Bootstrap on sign-up — emitted by CreatorBootstrapService.
    case CreatorCreated = 'creator.created';
    case CreatorUpdated = 'creator.updated';
    case CreatorDeleted = 'creator.deleted';

    // Wizard step completions — emitted on first-successful state transition
    // (#6 idempotency: re-submitting a completed step does NOT re-emit).
    case CreatorWizardProfileCompleted = 'creator.wizard.profile_completed';
    case CreatorWizardSocialCompleted = 'creator.wizard.social_completed';
    case CreatorWizardPortfolioCompleted = 'creator.wizard.portfolio_completed';
    case CreatorWizardKycInitiated = 'creator.wizard.kyc_initiated';
    case CreatorWizardTaxCompleted = 'creator.wizard.tax_completed';
    case CreatorWizardPayoutInitiated = 'creator.wizard.payout_initiated';
    case CreatorWizardContractInitiated = 'creator.wizard.contract_initiated';
    case CreatorSubmitted = 'creator.submitted';

    // Wizard step completion-pairs — emitted by status-poll / webhook
    // processing on the FIRST successful state transition to the
    // terminal "done" value. Idempotent (#6) — re-polling after
    // completion does not re-emit. Sprint 3 Chunk 2 sub-step 6
    // (status-poll) + sub-step 7 (Process*WebhookJob).
    case CreatorWizardKycCompleted = 'creator.wizard.kyc_completed';
    case CreatorWizardContractCompleted = 'creator.wizard.contract_completed';
    case CreatorWizardPayoutCompleted = 'creator.wizard.payout_completed';

    // Click-through contract acceptance — emitted by sub-step 9's
    // `/wizard/contract/click-through-accept` endpoint when the
    // `contract_signing_enabled` flag is OFF and the creator
    // accepts terms via the fallback flow. Distinct from
    // CreatorWizardContractCompleted (envelope-mode); the two
    // are mutually exclusive per wizard run.
    case CreatorWizardClickThroughAccepted = 'creator.wizard.click_through_accepted';

    // Inbound webhook lifecycle — emitted by the webhook handler
    // controllers + Process*WebhookJob. Sprint 3 Chunk 2 sub-step
    // 7. The `signature_failed` case is a security event — admins
    // surface these in the audit-review queue; the response body
    // intentionally does NOT differentiate failure modes (single
    // error code per the chunk-2 plan's "Decisions documented for
    // future chunks" section). Distinct from IntegrationEvent
    // rows (which are vendor-payload archaeology, not audit
    // history) per Refinement 5.
    case IntegrationWebhookReceived = 'integration.webhook.received';
    case IntegrationWebhookProcessed = 'integration.webhook.processed';
    case IntegrationWebhookSignatureFailed = 'integration.webhook.signature_failed';

    // Bulk roster invitation (Sprint 3 Chunk 1, agency-side).
    case CreatorInvited = 'creator.invited';
    case BulkInviteStarted = 'bulk_invite.started';
    case BulkInviteCompleted = 'bulk_invite.completed';
    case BulkInviteFailed = 'bulk_invite.failed';

    // Magic-link invitation acceptance (Sprint 3 Chunk 4). Emitted by
    // SignUpService::acceptInvitationOnSignUp() when an invitee completes
    // sign-up via the magic-link path: User row gains a real password +
    // email_verified_at; AgencyCreatorRelation transitions prospect → roster.
    case CreatorInvitationAccepted = 'creator.invitation_accepted';

    // Admin per-field edit + approve / reject (Sprint 3 Chunk 4).
    // CreatorAdminFieldUpdated is emitted by AdminCreatorUpdateService
    // once per state-flipping field change. Same-value updates are
    // no-ops per #6 idempotency — they neither touch updated_at nor
    // emit this row. Metadata: {field, old_value, new_value, reason?}.
    case CreatorAdminFieldUpdated = 'creator.admin.field_updated';
    case CreatorApproved = 'creator.approved';
    case CreatorRejected = 'creator.rejected';

    // Auto-emitted by Audited trait on related models. Each model
    // overrides auditAction() to produce snake_case subject naming
    // (the trait's default class_basename lowercase produces
    // unreadable names like 'creatortaxprofile.created').
    case CreatorTaxProfileCreated = 'creator_tax_profile.created';
    case CreatorTaxProfileUpdated = 'creator_tax_profile.updated';
    case CreatorTaxProfileDeleted = 'creator_tax_profile.deleted';
    case CreatorPayoutMethodCreated = 'creator_payout_method.created';
    case CreatorPayoutMethodUpdated = 'creator_payout_method.updated';
    case CreatorPayoutMethodDeleted = 'creator_payout_method.deleted';
    case AgencyCreatorRelationCreated = 'agency_creator_relation.created';
    case AgencyCreatorRelationUpdated = 'agency_creator_relation.updated';
    case AgencyCreatorRelationDeleted = 'agency_creator_relation.deleted';

    /**
     * True when the action requires a non-empty reason at the service layer.
     *
     * Mirrors docs/05-SECURITY-COMPLIANCE.md §3.3. The HTTP layer enforces
     * this via the `action.reason` middleware (X-Action-Reason header).
     * The service layer enforces it in {@see AuditLogger::log()}.
     */
    public function requiresReason(): bool
    {
        return match ($this) {
            self::AuthAccountUnlocked,
            self::UserSuspended,
            self::UserUnsuspended,
            self::UserDeleted => true,
            default => false,
        };
    }

    /**
     * True when the action describes a sensitive credential mutation whose
     * before/after snapshot must NEVER contain the underlying secret/code
     * material in plaintext. Asserted by chunk 5's TwoFactorAuditTest in
     * addition to the {@see User::auditableAllowlist()} exclusion.
     */
    public function isSensitiveCredentialAction(): bool
    {
        return match ($this) {
            self::MfaEnabled,
            self::MfaConfirmed,
            self::MfaDisabled,
            self::MfaRecoveryCodesRegenerated,
            self::MfaRecoveryCodeConsumed,
            self::AuthPasswordChanged,
            self::AuthPasswordResetCompleted => true,
            default => false,
        };
    }
}
