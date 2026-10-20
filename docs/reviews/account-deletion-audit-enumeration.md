# AH-104a Stage 0 — audit action and metadata enumeration

> **Generated file — do not edit by hand.** Regenerate with:
>
> ```
> cd apps/api && php tools/enumerate-audit-actions.php > ../../docs/reviews/account-deletion-audit-enumeration.md
> ```
>
> Source commit: `01fde6b0e9e76aeb75a0abc652bde01dcc910334`. Static analysis only (nikic/php-parser); no Laravel boot, no DB, no application code executed. Scanned 712 PHP files under `apps/api/app`.

## 1. Summary

| Measure | Count |
|---|---:|
| `AuditAction` cases | 134 |
| …with `requiresReason() === true` | 20 |
| Audit log call sites found | 99 |
| …with a literal `AuditAction::X` action | 95 |
| …with a dynamic action (needs a manual read) | 4 |
| Cases with a direct call site | 87 |
| Cases referenced only indirectly (passed through a helper, a map, or a listener) | 30 |
| Cases with no reference in `app/` outside the enum (dead, unless the `Audited` trait derives them) | 17 |
| `AuditAction::from/tryFrom` dynamic resolvers | 3 |
| Models using the `Audited` trait | 6 |
| Parse errors | 0 |

## 2. Every case

"Direct" = the case is the literal `action:` argument of an audit log call. "Other refs" = the case appears elsewhere (helper argument, `match` map, listener filter, query). A case with only "other refs" is emitted indirectly; the emitting call is in section 3 (dynamic action) or section 4 (Audited trait). Key lists are the literal top-level keys only; anything non-literal is in the last column and needs a manual read.

| Case | Value | Reason req. | Direct sites | Other refs | `metadata` keys | `before` keys | `after` keys | Dynamic payload parts |
|---|---|:--:|---|---|---|---|---|---|
| `AccountErased` | `account.erased` | yes | `apps/api/app/Modules/Erasure/Run/ErasureRun.php:166` | — | `audit_rows_scrubbed`, `counts`, `digest`, `files_deleted`, `ledger_ulid` | — | — | — |
| `AdminImpersonationEnded` | `admin.impersonation_ended` |  | `apps/api/app/Modules/Identity/Services/ImpersonationService.php:235` | — | `impersonated_user_id`, `impersonation_ulid` | — | — | — |
| `AdminImpersonationStarted` | `admin.impersonation_started` | yes | `apps/api/app/Modules/Identity/Services/ImpersonationService.php:108` | — | `impersonated_user_id`, `impersonation_ulid` | — | — | — |
| `AgencyCreatorRelationAdminConnected` | `agency_creator_relation.admin_connected` | yes | `apps/api/app/Modules/Creators/Services/CreatorAgencyDirectConnectService.php:166` | — | `agency_id`, `automation_rule_id`, `fired_by`, `mode` | — | — | — |
| `AgencyCreatorRelationAdminRequested` | `agency_creator_relation.admin_requested` |  | `apps/api/app/Modules/Creators/Http/Controllers/Admin/AdminCreatorConnectionController.php:360` | — | `agency_id`, `mode` | — | — | — |
| `AgencyCreatorRelationCreated` | `agency_creator_relation.created` |  | — | `apps/api/app/Modules/Agencies/Models/AgencyCreatorRelation.php:216` | — | — | — | — |
| `AgencyCreatorRelationDeleted` | `agency_creator_relation.deleted` |  | — | `apps/api/app/Modules/Agencies/Models/AgencyCreatorRelation.php:218` | — | — | — | — |
| `AgencyCreatorRelationDisconnected` | `agency_creator_relation.disconnected` | yes | `apps/api/app/Modules/Creators/Http/Controllers/Admin/AdminCreatorConnectionController.php:208` | — | `agency_id`, `pool_memberships_deleted` | — | — | — |
| `AgencyCreatorRelationNotesUpdated` | `agency_creator_relation.notes_updated` |  | `apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorDetailController.php:86` | — | — | — | — | — |
| `AgencyCreatorRelationUpdated` | `agency_creator_relation.updated` |  | — | `apps/api/app/Modules/Agencies/Models/AgencyCreatorRelation.php:217`<br>`apps/api/app/Modules/Agencies/Models/AgencyCreatorRelation.php:219` | — | — | — | — |
| `AgencyFileDeleted` | `agency_file.deleted` |  | `apps/api/app/Modules/Agencies/Http/Controllers/AgencyFileController.php:192` | — | — | — | — | `before: $before @ apps/api/app/Modules/Agencies/Http/Controllers/AgencyFileController.php:192` |
| `AgencyFileUploaded` | `agency_file.uploaded` |  | `apps/api/app/Modules/Agencies/Http/Controllers/AgencyFileController.php:149` | — | — | — | — | `after: $file->toArray() @ apps/api/app/Modules/Agencies/Http/Controllers/AgencyFileController.php:149` |
| `AgencyReactivated` | `agency.reactivated` |  | `apps/api/app/Modules/Admin/Http/Controllers/AdminAgencyController.php:187` | — | — | — | — | — |
| `AgencySettingsUpdated` | `agency_settings.updated` |  | `apps/api/app/Modules/Agencies/Http/Controllers/AgencySettingsController.php:62` | — | — | — | — | `before: $before @ apps/api/app/Modules/Agencies/Http/Controllers/AgencySettingsController.php:62`<br>`after: $after @ apps/api/app/Modules/Agencies/Http/Controllers/AgencySettingsController.php:62` |
| `AgencySuspended` | `agency.suspended` | yes | `apps/api/app/Modules/Admin/Http/Controllers/AdminAgencyController.php:147` | — | — | — | — | — |
| `AssignmentAccepted` | `assignment.accepted` |  | — | `apps/api/app/Modules/Campaigns/Listeners/CreateAssignmentAvailabilityBlock.php:42`<br>`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:217` | — | — | — | — |
| `AssignmentCancelled` | `assignment.cancelled` | yes | — | `apps/api/app/Modules/Boards/Services/BoardCardService.php:157`<br>`apps/api/app/Modules/Boards/Support/BoardDefaults.php:63`<br>`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:617` | — | — | — | — |
| `AssignmentCompletedOnApproval` | `assignment.completed_on_approval` |  | — | `apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php:119`<br>`apps/api/app/Modules/Campaigns/Listeners/WriteSystemMessage.php:46`<br>`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:436` | — | — | — | — |
| `AssignmentContracted` | `assignment.contracted` |  | — | `apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php:120`<br>`apps/api/app/Modules/Campaigns/Listeners/WriteSystemMessage.php:37`<br>`apps/api/app/Modules/Campaigns/Listeners/WriteSystemMessage.php:91`<br>`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:263` | — | — | — | — |
| `AssignmentCountered` | `assignment.countered` |  | — | `apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:106` | — | — | — | — |
| `AssignmentDeclined` | `assignment.declined` |  | — | `apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:87` | — | — | — | — |
| `AssignmentDraftApproved` | `assignment.draft_approved` |  | — | `apps/api/app/Modules/Boards/Services/BoardCardService.php:151`<br>`apps/api/app/Modules/Boards/Support/BoardDefaults.php:56`<br>`apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php:111`<br>`apps/api/app/Modules/Campaigns/Listeners/WriteSystemMessage.php:39`<br>`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:357` | — | — | — | — |
| `AssignmentDraftOverdue` | `assignment.draft_overdue` |  | — | `apps/api/app/Modules/Boards/Services/OverdueScanService.php:92` | — | — | — | — |
| `AssignmentDraftRejected` | `assignment.draft_rejected` | yes | — | `apps/api/app/Modules/Boards/Support/BoardDefaults.php:66`<br>`apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php:118`<br>`apps/api/app/Modules/Campaigns/Listeners/WriteSystemMessage.php:41`<br>`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:395` | — | — | — | — |
| `AssignmentDraftSubmitted` | `assignment.draft_submitted` |  | — | `apps/api/app/Modules/Boards/Services/BoardCardService.php:141`<br>`apps/api/app/Modules/Boards/Support/BoardDefaults.php:55`<br>`apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php:110`<br>`apps/api/app/Modules/Campaigns/Listeners/WriteSystemMessage.php:38`<br>`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:311` | — | — | — | — |
| `AssignmentInvited` | `assignment.invited` |  | `apps/api/app/Modules/Campaigns/Services/CampaignInvitationService.php:112` | `apps/api/app/Modules/Boards/Listeners/CreateBoardCard.php:41`<br>`apps/api/app/Modules/Boards/Services/BoardCardService.php:88`<br>`apps/api/app/Modules/Boards/Services/BoardCardService.php:140`<br>`apps/api/app/Modules/Boards/Support/BoardDefaults.php:54`<br>`apps/api/app/Modules/Campaigns/Listeners/CreateMessageThread.php:33`<br>`apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php:123`<br>`apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php:333`<br>`apps/api/app/Modules/Campaigns/Services/CampaignInvitationService.php:127` | `agreed_fee_currency`, `agreed_fee_minor_units`, `from`, `to` | — | — | — |
| `AssignmentLiveVerified` | `assignment.live_verified` |  | — | `apps/api/app/Modules/Boards/Support/BoardDefaults.php:58`<br>`apps/api/app/Modules/Boards/Support/BoardDefaults.php:87`<br>`apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php:122`<br>`apps/api/app/Modules/Campaigns/Listeners/WriteSystemMessage.php:48`<br>`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:495` | — | — | — | — |
| `AssignmentManuallyVerified` | `assignment.manually_verified` | yes | — | `apps/api/app/Modules/Boards/Support/BoardDefaults.php:59`<br>`apps/api/app/Modules/Boards/Support/BoardDefaults.php:88`<br>`apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php:121`<br>`apps/api/app/Modules/Campaigns/Listeners/WriteSystemMessage.php:49`<br>`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:539` | — | — | — | — |
| `AssignmentPaymentFunded` | `assignment.payment_funded` |  | — | — | — | — | — | — |
| `AssignmentPaymentReleased` | `assignment.payment_released` |  | — | `apps/api/app/Modules/Boards/Services/BoardCardService.php:156`<br>`apps/api/app/Modules/Boards/Support/BoardDefaults.php:62`<br>`apps/api/app/Modules/Campaigns/Listeners/WriteSystemMessage.php:51` | — | — | — | — |
| `AssignmentPostedByCreator` | `assignment.posted_by_creator` |  | — | `apps/api/app/Modules/Boards/Services/BoardCardService.php:154`<br>`apps/api/app/Modules/Boards/Support/BoardDefaults.php:57`<br>`apps/api/app/Modules/Boards/Support/BoardDefaults.php:86`<br>`apps/api/app/Modules/Campaigns/Listeners/DispatchPostedContentVerification.php:34`<br>`apps/api/app/Modules/Campaigns/Listeners/WriteSystemMessage.php:47`<br>`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:458` | — | — | — | — |
| `AssignmentPostedContentUpdated` | `assignment.posted_content_updated` |  | `apps/api/app/Modules/Creators/Http/Controllers/CreatorAssignmentDraftController.php:539` | — | `platform`, `posted_content_id` | — | — | — |
| `AssignmentPostingOverdue` | `assignment.posting_overdue` |  | — | `apps/api/app/Modules/Boards/Services/OverdueScanService.php:66` | — | — | — | — |
| `AssignmentProducing` | `assignment.producing` |  | — | `apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:289` | — | — | — | — |
| `AssignmentReInvited` | `assignment.re_invited` |  | — | `apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php:123`<br>`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:141`<br>`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:188` | — | — | — | — |
| `AssignmentResubmitRequested` | `assignment.resubmit_requested` |  | — | `apps/api/app/Modules/Boards/Support/BoardDefaults.php:60`<br>`apps/api/app/Modules/Campaigns/Listeners/WriteSystemMessage.php:50`<br>`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:570` | — | — | — | — |
| `AssignmentResubmitRequestedInPlace` | `assignment.resubmit_requested_in_place` |  | `apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentResolutionController.php:132` | — | `status` | — | — | — |
| `AssignmentRevisionRequested` | `assignment.revision_requested` |  | — | `apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php:117`<br>`apps/api/app/Modules/Campaigns/Listeners/WriteSystemMessage.php:40`<br>`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:338` | — | — | — | — |
| `AuthAccountLockedSuspended` | `auth.account_locked.suspended` |  | `apps/api/app/Modules/Identity/Services/AccountLockoutService.php:92` | — | `threshold`, `window_hours` | — | — | — |
| `AuthAccountUnlocked` | `auth.account_unlocked` | yes | — | — | — | — | — | — |
| `AuthEmailVerificationSent` | `auth.email.verification_sent` |  | `apps/api/app/Modules/Identity/Listeners/WriteAuthAuditLog.php:126` | — | — | — | — | — |
| `AuthEmailVerified` | `auth.email.verified` |  | `apps/api/app/Modules/Identity/Listeners/WriteAuthAuditLog.php:137` | — | — | — | — | — |
| `AuthLoginFailed` | `auth.login.failed` |  | `apps/api/app/Modules/Identity/Listeners/WriteAuthAuditLog.php:78` | — | `email`, `reason` | — | — | — |
| `AuthLoginSucceeded` | `auth.login.succeeded` |  | `apps/api/app/Modules/Identity/Listeners/WriteAuthAuditLog.php:51` | `apps/api/app/Modules/Admin/Http/Controllers/AdminCreatorAppController.php:77`<br>`apps/api/app/Modules/Audit/Database/Factories/AuditLogFactory.php:30` | `guard`, `mfa` | — | — | — |
| `AuthLogout` | `auth.logout` |  | `apps/api/app/Modules/Identity/Listeners/WriteAuthAuditLog.php:66` | — | `guard` | — | — | — |
| `AuthPasswordChanged` | `auth.password.changed` |  | — | — | — | — | — | — |
| `AuthPasswordResetCompleted` | `auth.password.reset_completed` |  | `apps/api/app/Modules/Identity/Listeners/WriteAuthAuditLog.php:104` | — | — | — | — | — |
| `AuthPasswordResetRequested` | `auth.password.reset_requested` |  | `apps/api/app/Modules/Identity/Listeners/WriteAuthAuditLog.php:93` | — | — | — | — | — |
| `AuthSignedUp` | `auth.signup` |  | `apps/api/app/Modules/Identity/Listeners/WriteAuthAuditLog.php:115` | — | — | — | — | — |
| `AutomationRuleCreated` | `automation_rule.created` | yes | `apps/api/app/Modules/Admin/Http/Controllers/AdminAutomationRuleController.php:77` | — | — | — | — | `after: $this->snapshot($rule) @ apps/api/app/Modules/Admin/Http/Controllers/AdminAutomationRuleController.php:77` |
| `AutomationRuleDeleted` | `automation_rule.deleted` | yes | `apps/api/app/Modules/Admin/Http/Controllers/AdminAutomationRuleController.php:168` | — | — | — | — | `before: $before @ apps/api/app/Modules/Admin/Http/Controllers/AdminAutomationRuleController.php:168` |
| `AutomationRuleToggled` | `automation_rule.toggled` | yes | `apps/api/app/Modules/Admin/Http/Controllers/AdminAutomationRuleController.php:138` | — | `enabled`, `rule_id` | `enabled` | `enabled` | — |
| `AutomationRuleUpdated` | `automation_rule.updated` | yes | `apps/api/app/Modules/Admin/Http/Controllers/AdminAutomationRuleController.php:108` | — | — | — | — | `before: $before @ apps/api/app/Modules/Admin/Http/Controllers/AdminAutomationRuleController.php:108`<br>`after: $this->snapshot($rule->refresh()) @ apps/api/app/Modules/Admin/Http/Controllers/AdminAutomationRuleController.php:108` |
| `BoardCardMovedManually` | `board.card_moved_manually` |  | `apps/api/app/Modules/Boards/Services/BoardCardMoveService.php:56` | — | `assignment_id`, `from_column_id`, `to_column_id` | — | — | — |
| `BoardReset` | `board.reset` |  | `apps/api/app/Modules/Boards/Services/BoardResetService.php:82` | — | `campaign_id`, `columns_removed`, `columns_seeded` | — | — | — |
| `BrandArchived` | `brand.archived` |  | `apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:158` | — | — | — | `status` | `before: $before @ apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:158`<br>`after: $brand->fresh()?->toArray() ?? [] @ apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:158` |
| `BrandCreated` | `brand.created` |  | `apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:85` | — | — | — | — | `after: $brand->toArray() @ apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:85` |
| `BrandCreatorBlacklistCreated` | `brand_creator_blacklist.created` |  | — | `apps/api/app/Modules/Agencies/Models/BrandCreatorBlacklist.php:125`<br>`apps/api/app/Modules/Agencies/Models/BrandCreatorBlacklist.php:127` | — | — | — | — |
| `BrandCreatorBlacklistDeleted` | `brand_creator_blacklist.deleted` |  | — | `apps/api/app/Modules/Agencies/Models/BrandCreatorBlacklist.php:126` | — | — | — | — |
| `BrandRestored` | `brand.restored` |  | `apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:213` | — | — | — | — | `before: $before @ apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:213`<br>`after: $brandModel->fresh()?->toArray() ?? [] @ apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:213` |
| `BrandUpdated` | `brand.updated` |  | `apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:126`<br>`apps/api/app/Modules/Brands/Http/Controllers/BrandLogoController.php:98`<br>`apps/api/app/Modules/Brands/Http/Controllers/BrandLogoController.php:123` | — | — | — | — | `before: $before @ apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:126`<br>`after: $brand->fresh()?->toArray() ?? [] @ apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:126`<br>`before: $before @ apps/api/app/Modules/Brands/Http/Controllers/BrandLogoController.php:98`<br>`after: $brand->fresh()?->toArray() ?? [] @ apps/api/app/Modules/Brands/Http/Controllers/BrandLogoController.php:98`<br>`before: $before @ apps/api/app/Modules/Brands/Http/Controllers/BrandLogoController.php:123`<br>`after: $brand->fresh()?->toArray() ?? [] @ apps/api/app/Modules/Brands/Http/Controllers/BrandLogoController.php:123` |
| `BulkInviteCompleted` | `bulk_invite.completed` |  | `apps/api/app/Modules/Creators/Jobs/BulkCreatorInvitationJob.php:101` | — | — | — | — | `metadata: $stats @ apps/api/app/Modules/Creators/Jobs/BulkCreatorInvitationJob.php:101` |
| `BulkInviteFailed` | `bulk_invite.failed` |  | `apps/api/app/Modules/Creators/Jobs/BulkCreatorInvitationJob.php:116` | — | `error` | — | — | — |
| `BulkInviteStarted` | `bulk_invite.started` |  | `apps/api/app/Modules/Creators/Jobs/BulkCreatorInvitationJob.php:64` | — | `row_count` | — | — | — |
| `CampaignApplicationAccepted` | `campaign_application.accepted` |  | `apps/api/app/Modules/Campaigns/Services/CampaignApplicationDecisionService.php:54` | — | `campaign_id`, `creator_id` | — | — | — |
| `CampaignApplicationRejected` | `campaign_application.rejected` |  | `apps/api/app/Modules/Campaigns/Services/CampaignApplicationDecisionService.php:79` | — | `campaign_id`, `cause`, `creator_id` | — | — | — |
| `CampaignApplicationSubmitted` | `campaign_application.submitted` |  | `apps/api/app/Modules/Creators/Http/Controllers/CreatorJobBoardController.php:272` | — | `campaign_id`, `creator_id`, `has_note` | — | — | — |
| `CampaignAttachmentAdded` | `campaign_attachment.added` |  | `apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAttachmentController.php:103` | — | — | — | `agency_file_id`, `visible_to_creators` | — |
| `CampaignAttachmentRemoved` | `campaign_attachment.removed` |  | `apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAttachmentController.php:153` | — | — | `agency_file_id` | — | — |
| `CampaignAttachmentVisibilityChanged` | `campaign_attachment.visibility_changed` |  | `apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAttachmentController.php:131` | — | — | `visible_to_creators` | `visible_to_creators` | — |
| `CampaignCreated` | `campaign.created` |  | `apps/api/app/Modules/Campaigns/Http/Controllers/CampaignController.php:130` | — | — | — | — | `after: $this->auditableSnapshot($campaign) @ apps/api/app/Modules/Campaigns/Http/Controllers/CampaignController.php:130` |
| `CampaignJobPosted` | `campaign.job_posted` |  | — | — | — | — | — | — |
| `CampaignUpdated` | `campaign.updated` |  | `apps/api/app/Modules/Campaigns/Http/Controllers/CampaignController.php:245` | — | — | — | — | `before: $before @ apps/api/app/Modules/Campaigns/Http/Controllers/CampaignController.php:245`<br>`after: $this->auditableSnapshot($campaign->fresh() ?? $campaign) @ apps/api/app/Modules/Campaigns/Http/Controllers/CampaignController.php:245` |
| `CreatorAdminFieldUpdated` | `creator.admin.field_updated` |  | `apps/api/app/Modules/Creators/Services/AdminCreatorUpdateService.php:66` | `apps/api/app/Modules/Erasure/Selection/AuditRowScrub.php:124` | `after`, `before`, `field`, `reason` | — | — | — |
| `CreatorApplicationReopened` | `creator.application.reopened` |  | `apps/api/app/Modules/Creators/Services/CreatorWizardService.php:385` | — | — | — | — | — |
| `CreatorApproved` | `creator.approved` |  | `apps/api/app/Modules/Creators/Http/Controllers/Admin/AdminCreatorController.php:278` | `apps/api/app/Modules/Erasure/Selection/AuditRowScrub.php:125` | `welcome_message` | — | — | — |
| `CreatorBlacklisted` | `creator.blacklisted` |  | `apps/api/app/Modules/Agencies/Http/Controllers/CreatorBlacklistController.php:323` | — | — | — | — | `metadata: $metadata @ apps/api/app/Modules/Agencies/Http/Controllers/CreatorBlacklistController.php:323` |
| `CreatorCreated` | `creator.created` |  | — | — | — | — | — | — |
| `CreatorDeleted` | `creator.deleted` |  | — | — | — | — | — | — |
| `CreatorInvitationAccepted` | `creator.invitation_accepted` |  | `apps/api/app/Modules/Identity/Services/SignUpService.php:252` | — | — | — | — | — |
| `CreatorInvited` | `creator.invited` |  | `apps/api/app/Modules/Creators/Services/BulkInviteService.php:86` | — | — | — | — | — |
| `CreatorKycManuallyVerified` | `creator.kyc.manually_verified` |  | `apps/api/app/Modules/Creators/Http/Controllers/Admin/AdminCreatorController.php:424` | — | `note` | — | — | — |
| `CreatorPayoutMethodCreated` | `creator_payout_method.created` |  | — | `apps/api/app/Modules/Creators/Models/CreatorPayoutMethod.php:85` | — | — | — | — |
| `CreatorPayoutMethodDeleted` | `creator_payout_method.deleted` |  | — | `apps/api/app/Modules/Creators/Models/CreatorPayoutMethod.php:87` | — | — | — | — |
| `CreatorPayoutMethodUpdated` | `creator_payout_method.updated` |  | — | `apps/api/app/Modules/Creators/Models/CreatorPayoutMethod.php:86`<br>`apps/api/app/Modules/Creators/Models/CreatorPayoutMethod.php:88` | — | — | — | — |
| `CreatorRejected` | `creator.rejected` |  | `apps/api/app/Modules/Creators/Http/Controllers/Admin/AdminCreatorController.php:349` | — | `rejection_reason` | — | — | — |
| `CreatorSubmitted` | `creator.submitted` |  | `apps/api/app/Modules/Creators/Services/CreatorWizardService.php:348` | — | — | — | — | — |
| `CreatorTaxProfileCreated` | `creator_tax_profile.created` |  | — | `apps/api/app/Modules/Creators/Models/CreatorTaxProfile.php:107` | — | — | — | — |
| `CreatorTaxProfileDeleted` | `creator_tax_profile.deleted` |  | — | `apps/api/app/Modules/Creators/Models/CreatorTaxProfile.php:109` | — | — | — | — |
| `CreatorTaxProfileUpdated` | `creator_tax_profile.updated` |  | — | `apps/api/app/Modules/Creators/Models/CreatorTaxProfile.php:108`<br>`apps/api/app/Modules/Creators/Models/CreatorTaxProfile.php:110` | — | — | — | — |
| `CreatorUpdated` | `creator.updated` |  | — | — | — | — | — | — |
| `CreatorWizardClickThroughAccepted` | `creator.wizard.click_through_accepted` |  | `apps/api/app/Modules/Creators/Services/CreatorWizardService.php:484` | — | — | — | — | — |
| `CreatorWizardContractCompleted` | `creator.wizard.contract_completed` |  | `apps/api/app/Modules/Creators/Jobs/ProcessEsignWebhookJob.php:123`<br>`apps/api/app/Modules/Creators/Services/WizardCompletionService.php:142` | — | `integration_event_id`, `origin` | — | — | — |
| `CreatorWizardContractInitiated` | `creator.wizard.contract_initiated` |  | `apps/api/app/Modules/Creators/Services/CreatorWizardService.php:293` | — | `envelope_id` | — | — | — |
| `CreatorWizardKycCompleted` | `creator.wizard.kyc_completed` |  | `apps/api/app/Modules/Creators/Jobs/ProcessKycWebhookJob.php:110`<br>`apps/api/app/Modules/Creators/Services/WizardCompletionService.php:85` | — | `integration_event_id`, `previous_status` | — | — | — |
| `CreatorWizardKycInitiated` | `creator.wizard.kyc_initiated` |  | `apps/api/app/Modules/Creators/Services/CreatorWizardService.php:193` | — | — | — | — | — |
| `CreatorWizardPayoutCompleted` | `creator.wizard.payout_completed` |  | `apps/api/app/Modules/Creators/Jobs/ProcessStripeWebhookJob.php:134`<br>`apps/api/app/Modules/Creators/Services/WizardCompletionService.php:177` | — | `charges_enabled`, `integration_event_id`, `payouts_enabled` | — | — | — |
| `CreatorWizardPayoutInitiated` | `creator.wizard.payout_initiated` |  | `apps/api/app/Modules/Creators/Services/CreatorWizardService.php:271` | — | — | — | — | — |
| `CreatorWizardPortfolioCompleted` | `creator.wizard.portfolio_completed` |  | — | — | — | — | — | — |
| `CreatorWizardProfileCompleted` | `creator.wizard.profile_completed` |  | `apps/api/app/Modules/Creators/Services/CreatorWizardService.php:78` | — | — | — | — | — |
| `CreatorWizardSocialCompleted` | `creator.wizard.social_completed` |  | `apps/api/app/Modules/Creators/Services/CreatorWizardService.php:122` | — | — | — | — | — |
| `CreatorWizardTaxCompleted` | `creator.wizard.tax_completed` |  | `apps/api/app/Modules/Creators/Services/CreatorWizardService.php:230` | — | — | — | — | — |
| `FeatureFlagToggled` | `feature_flag.toggled` | yes | `apps/api/app/Modules/Admin/Http/Controllers/AdminFeatureFlagController.php:151` | — | `enabled`, `flag` | `enabled` | `enabled` | — |
| `IntegrationWebhookProcessed` | `integration.webhook.processed` |  | `apps/api/app/Modules/Creators/Jobs/ProcessEsignWebhookJob.php:137`<br>`apps/api/app/Modules/Creators/Jobs/ProcessKycWebhookJob.php:126`<br>`apps/api/app/Modules/Creators/Jobs/ProcessStripeWebhookJob.php:151` | — | `event_type`, `integration_event_id`, `payout_status`, `provider` | — | — | — |
| `IntegrationWebhookReceived` | `integration.webhook.received` |  | `apps/api/app/Modules/Creators/Services/InboundWebhookIngestor.php:100` | — | `event_type`, `integration_event_id`, `provider`, `provider_event_id` | — | — | — |
| `IntegrationWebhookSignatureFailed` | `integration.webhook.signature_failed` |  | `apps/api/app/Modules/Creators/Services/InboundWebhookIngestor.php:77` | — | `provider` | — | — | — |
| `InvitationAccepted` | `invitation.accepted` |  | `apps/api/app/Modules/Agencies/Services/AgencyInvitationService.php:138` | — | — | — | `agency_id`, `role` | — |
| `InvitationAcceptedWithNewAccount` | `invitation.accepted_with_new_account` |  | `apps/api/app/Modules/Agencies/Services/AgencyInvitationService.php:242` | — | — | — | `agency_id`, `role`, `user_id` | — |
| `InvitationCreated` | `invitation.created` |  | `apps/api/app/Modules/Agencies/Services/AgencyInvitationService.php:64` | — | — | — | `email`, `role` | — |
| `InvitationExpiredOnAttempt` | `invitation.expired_on_attempt` |  | `apps/api/app/Modules/Agencies/Http/Controllers/InvitationController.php:161`<br>`apps/api/app/Modules/Agencies/Http/Controllers/InvitationController.php:258` | — | — | — | — | — |
| `MessageReceivedByAgency` | `message.received_by_agency` |  | — | — | — | — | — | — |
| `MessageReceivedByCreator` | `message.received_by_creator` |  | — | — | — | — | — | — |
| `MessageRelationshipReceivedByAgency` | `message.relationship_received_by_agency` |  | — | — | — | — | — | — |
| `MessageRelationshipReceivedByCreator` | `message.relationship_received_by_creator` |  | — | — | — | — | — | — |
| `MfaConfirmed` | `mfa.confirmed` |  | `apps/api/app/Modules/Identity/Listeners/WriteAuthAuditLog.php:159` | — | — | — | — | — |
| `MfaDisabled` | `mfa.disabled` |  | `apps/api/app/Modules/Identity/Listeners/WriteAuthAuditLog.php:170` | — | — | — | — | — |
| `MfaEnabled` | `mfa.enabled` |  | `apps/api/app/Modules/Identity/Listeners/WriteAuthAuditLog.php:148` | — | — | — | — | — |
| `MfaEnrollmentSuspended` | `mfa.enrollment_suspended` |  | `apps/api/app/Modules/Identity/Services/TwoFactorVerificationThrottle.php:122` | — | `threshold`, `window_minutes` | — | — | — |
| `MfaRecoveryCodeConsumed` | `mfa.recovery_code_consumed` |  | `apps/api/app/Modules/Identity/Listeners/WriteAuthAuditLog.php:203` | — | `remaining_count` | — | — | — |
| `MfaRecoveryCodesRegenerated` | `mfa.recovery_codes_regenerated` |  | `apps/api/app/Modules/Identity/Listeners/WriteAuthAuditLog.php:186` | — | `code_count` | — | — | — |
| `TalentPoolArchived` | `talent_pool.archived` |  | `apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:154` | — | — | — | — | `before: $before @ apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:154`<br>`after: $talentPool->fresh()?->toArray() ?? [] @ apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:154` |
| `TalentPoolCreated` | `talent_pool.created` |  | `apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:78` | — | — | — | — | `after: $pool->toArray() @ apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:78` |
| `TalentPoolCreatorAdded` | `talent_pool.creator_added` |  | `apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolMembershipController.php:132` | — | — | — | `creator_id` | — |
| `TalentPoolCreatorRemoved` | `talent_pool.creator_removed` |  | `apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolMembershipController.php:163` | — | — | `creator_id` | — | — |
| `TalentPoolRestored` | `talent_pool.restored` |  | `apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:197` | — | — | — | — | `before: $before @ apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:197`<br>`after: $pool->fresh()?->toArray() ?? [] @ apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:197` |
| `TalentPoolUpdated` | `talent_pool.updated` |  | `apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:127` | — | — | — | — | `before: $before @ apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:127`<br>`after: $talentPool->fresh()?->toArray() ?? [] @ apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:127` |
| `UserCreated` | `user.created` |  | — | — | — | — | — | — |
| `UserDeleted` | `user.deleted` | yes | — | — | — | — | — | — |
| `UserMfaExempted` | `user.mfa_exempted` | yes | `apps/api/app/Modules/Admin/Http/Controllers/AdminAgencyMemberController.php:239` | — | `agency_id` | — | — | — |
| `UserMfaExemptionRemoved` | `user.mfa_exemption_removed` | yes | `apps/api/app/Modules/Admin/Http/Controllers/AdminAgencyMemberController.php:286` | — | `agency_id` | — | — | — |
| `UserMfaReset` | `user.mfa_reset` | yes | `apps/api/app/Modules/Admin/Http/Controllers/AdminAgencyMemberController.php:176` | — | `agency_id` | — | — | — |
| `UserSuspended` | `user.suspended` | yes | — | — | — | — | — | — |
| `UserUnsuspended` | `user.unsuspended` | yes | — | — | — | — | — | — |
| `UserUpdated` | `user.updated` |  | — | — | — | — | — | — |

## 3. Call sites needing a manual read

### 3.1 Dynamic action (the case is chosen at runtime)

| Site | Action expression | `metadata` keys | Dynamic payload parts | reason? | actor? | subject? |
|---|---|---|---|:--:|:--:|:--:|
| `apps/api/app/Modules/Audit/Concerns/Audited.php:57` | `$model->auditAction('created')` | — | `after: $after` | y |  | y |
| `apps/api/app/Modules/Audit/Concerns/Audited.php:78` | `$model->auditAction('updated')` | — | `before: $original`<br>`after: $allowlistedChanges` | y |  | y |
| `apps/api/app/Modules/Audit/Concerns/Audited.php:90` | `$model->auditAction('deleted')` | — | `before: $before` | y |  | y |
| `apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:660` | `$action` | `from`, `to` | `metadata: $context` | y | y | y |

### 3.2 Direct calls whose payload has a non-literal part

| Site | Case | Param | Non-literal expression |
|---|---|---|---|
| `apps/api/app/Modules/Admin/Http/Controllers/AdminAutomationRuleController.php:108` | `AutomationRuleUpdated` | `after` | `$this->snapshot($rule->refresh())` |
| `apps/api/app/Modules/Admin/Http/Controllers/AdminAutomationRuleController.php:108` | `AutomationRuleUpdated` | `before` | `$before` |
| `apps/api/app/Modules/Admin/Http/Controllers/AdminAutomationRuleController.php:168` | `AutomationRuleDeleted` | `before` | `$before` |
| `apps/api/app/Modules/Admin/Http/Controllers/AdminAutomationRuleController.php:77` | `AutomationRuleCreated` | `after` | `$this->snapshot($rule)` |
| `apps/api/app/Modules/Agencies/Http/Controllers/AgencyFileController.php:149` | `AgencyFileUploaded` | `after` | `$file->toArray()` |
| `apps/api/app/Modules/Agencies/Http/Controllers/AgencyFileController.php:192` | `AgencyFileDeleted` | `before` | `$before` |
| `apps/api/app/Modules/Agencies/Http/Controllers/AgencySettingsController.php:62` | `AgencySettingsUpdated` | `after` | `$after` |
| `apps/api/app/Modules/Agencies/Http/Controllers/AgencySettingsController.php:62` | `AgencySettingsUpdated` | `before` | `$before` |
| `apps/api/app/Modules/Agencies/Http/Controllers/CreatorBlacklistController.php:323` | `CreatorBlacklisted` | `metadata` | `$metadata` |
| `apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:126` | `BrandUpdated` | `after` | `$brand->fresh()?->toArray() ?? []` |
| `apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:126` | `BrandUpdated` | `before` | `$before` |
| `apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:158` | `BrandArchived` | `after` | `$brand->fresh()?->toArray() ?? []` |
| `apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:158` | `BrandArchived` | `before` | `$before` |
| `apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:213` | `BrandRestored` | `after` | `$brandModel->fresh()?->toArray() ?? []` |
| `apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:213` | `BrandRestored` | `before` | `$before` |
| `apps/api/app/Modules/Brands/Http/Controllers/BrandController.php:85` | `BrandCreated` | `after` | `$brand->toArray()` |
| `apps/api/app/Modules/Brands/Http/Controllers/BrandLogoController.php:123` | `BrandUpdated` | `after` | `$brand->fresh()?->toArray() ?? []` |
| `apps/api/app/Modules/Brands/Http/Controllers/BrandLogoController.php:123` | `BrandUpdated` | `before` | `$before` |
| `apps/api/app/Modules/Brands/Http/Controllers/BrandLogoController.php:98` | `BrandUpdated` | `after` | `$brand->fresh()?->toArray() ?? []` |
| `apps/api/app/Modules/Brands/Http/Controllers/BrandLogoController.php:98` | `BrandUpdated` | `before` | `$before` |
| `apps/api/app/Modules/Campaigns/Http/Controllers/CampaignController.php:130` | `CampaignCreated` | `after` | `$this->auditableSnapshot($campaign)` |
| `apps/api/app/Modules/Campaigns/Http/Controllers/CampaignController.php:245` | `CampaignUpdated` | `after` | `$this->auditableSnapshot($campaign->fresh() ?? $campaign)` |
| `apps/api/app/Modules/Campaigns/Http/Controllers/CampaignController.php:245` | `CampaignUpdated` | `before` | `$before` |
| `apps/api/app/Modules/Creators/Jobs/BulkCreatorInvitationJob.php:101` | `BulkInviteCompleted` | `metadata` | `$stats` |
| `apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:127` | `TalentPoolUpdated` | `after` | `$talentPool->fresh()?->toArray() ?? []` |
| `apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:127` | `TalentPoolUpdated` | `before` | `$before` |
| `apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:154` | `TalentPoolArchived` | `after` | `$talentPool->fresh()?->toArray() ?? []` |
| `apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:154` | `TalentPoolArchived` | `before` | `$before` |
| `apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:197` | `TalentPoolRestored` | `after` | `$pool->fresh()?->toArray() ?? []` |
| `apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:197` | `TalentPoolRestored` | `before` | `$before` |
| `apps/api/app/Modules/TalentPools/Http/Controllers/TalentPoolController.php:78` | `TalentPoolCreated` | `after` | `$pool->toArray()` |

### 3.3 `AuditAction::from` / `tryFrom` resolvers

| Site | Call |
|---|---|
| `apps/api/app/Modules/Admin/Http/Controllers/AdminAuditLogController.php:51` | `\App\Modules\Audit\Enums\AuditAction::tryFrom($action)` |
| `apps/api/app/Modules/Audit/Concerns/Audited.php:101` | `\App\Modules\Audit\Enums\AuditAction::from(Str::lower(class_basename($this)) . '.' . $event)` |
| `apps/api/app/Modules/Notifications/Enums/NotificationType.php:173` | `\App\Modules\Audit\Enums\AuditAction::from($this->value)` |

### 3.4 Calls naming a case the enum does not declare

None.

## 4. Models using the `Audited` trait

The trait emits `created` / `updated` / `deleted` rows from model events: `after` (allowlisted attributes) on create, `before` + `after` (changed allowlisted attributes) on update, `before` on delete. The action comes from `auditAction($event)`: the trait default is `AuditAction::from(lower(class_basename).".".event)`; a model may override it.

| Model | Allowlist (snapshot keys) | `auditAction()` | Derived/declared actions exist in the enum? |
|---|---|---|---|
| `App\Modules\Agencies\Models\AgencyCreatorRelation`<br>`apps/api/app/Modules/Agencies/Models/AgencyCreatorRelation.php:65` | `agency_id`, `creator_id`, `relationship_status`, `is_blacklisted`, `blacklist_scope`, `blacklist_type`, `blacklisted_at`, `blacklisted_by_user_id`, `invitation_sent_at`, `invitation_expires_at`, `invited_by_user_id`, `internal_rating`, `last_engaged_at` | overridden → `AgencyCreatorRelationCreated`, `AgencyCreatorRelationUpdated`, `AgencyCreatorRelationDeleted` | yes (literal cases) |
| `App\Modules\Agencies\Models\BrandCreatorBlacklist`<br>`apps/api/app/Modules/Agencies/Models/BrandCreatorBlacklist.php:57` | `brand_id`, `creator_id`, `blacklist_type`, `blacklisted_at`, `blacklisted_by_user_id` | overridden → `BrandCreatorBlacklistCreated`, `BrandCreatorBlacklistDeleted` | yes (literal cases) |
| `App\Modules\Creators\Models\Creator`<br>`apps/api/app/Modules/Creators/Models/Creator.php:79` | `display_name`, `country_code`, `primary_language`, `categories`, `verification_level`, `tier`, `application_status`, `approved_at`, `approved_by_user_id`, `rejected_at`, `profile_completeness_score`, `kyc_status`, `kyc_verified_at`, `kyc_method`, `verified_by_user_id`, `tax_profile_complete`, `payout_method_set`, `submitted_at`, `last_active_at`, `deleted_at` | trait default → `creator.created ✓`, `creator.updated ✓`, `creator.deleted ✓` | yes |
| `App\Modules\Creators\Models\CreatorPayoutMethod`<br>`apps/api/app/Modules/Creators/Models/CreatorPayoutMethod.php:37` | `provider`, `provider_account_id`, `currency`, `is_default`, `status`, `verified_at` | overridden → `CreatorPayoutMethodCreated`, `CreatorPayoutMethodUpdated`, `CreatorPayoutMethodDeleted` | yes (literal cases) |
| `App\Modules\Creators\Models\CreatorTaxProfile`<br>`apps/api/app/Modules/Creators/Models/CreatorTaxProfile.php:45` | `tax_form_type`, `tax_id_country`, `submitted_at`, `verified_at`, `verified_by_user_id` | overridden → `CreatorTaxProfileCreated`, `CreatorTaxProfileUpdated`, `CreatorTaxProfileDeleted` | yes (literal cases) |
| `App\Modules\Identity\Models\User`<br>`apps/api/app/Modules/Identity/Models/User.php:57` | `email`, `email_verified_at`, `type`, `name`, `last_name`, `preferred_language`, `preferred_currency`, `timezone`, `theme_preference`, `last_login_at`, `last_login_ip`, `mfa_required`, `mfa_exempted_at`, `is_suspended`, `suspended_reason`, `suspended_at`, `deleted_at` | trait default → `user.created ✓`, `user.updated ✓`, `user.deleted ✓` | yes |

## 5. Union of literal payload keys

"In §4.1 scrub list" = the key is already named by the PII map. "PII-name heuristic" = the key name looks like it could carry personal data and is **not** on the list — each of these needs a ruling or an explicit "not personal".

| Key | In §4.1 scrub list | PII-name heuristic | Used in | Sites |
|---|:--:|:--:|---|---:|
| `after` |  | **review** | `metadata` | 1 |
| `agency_file_id` |  |  | `after`, `before` | 2 |
| `agency_id` |  |  | `metadata`, `after`, `audited allowlist` | 9 |
| `agreed_fee_currency` |  |  | `metadata` | 1 |
| `agreed_fee_minor_units` |  |  | `metadata` | 1 |
| `application_status` |  |  | `audited allowlist` | 1 |
| `approved_at` |  |  | `audited allowlist` | 1 |
| `approved_by_user_id` |  |  | `audited allowlist` | 1 |
| `assignment_id` |  |  | `metadata` | 1 |
| `audit_rows_scrubbed` |  |  | `metadata` | 1 |
| `automation_rule_id` |  |  | `metadata` | 1 |
| `before` |  | **review** | `metadata` | 1 |
| `blacklist_scope` |  |  | `audited allowlist` | 1 |
| `blacklist_type` |  |  | `audited allowlist` | 2 |
| `blacklisted_at` |  |  | `audited allowlist` | 2 |
| `blacklisted_by_user_id` |  |  | `audited allowlist` | 2 |
| `brand_id` |  |  | `audited allowlist` | 1 |
| `campaign_id` |  |  | `metadata` | 4 |
| `categories` |  |  | `audited allowlist` | 1 |
| `cause` |  |  | `metadata` | 1 |
| `charges_enabled` |  |  | `metadata` | 2 |
| `code_count` |  |  | `metadata` | 1 |
| `columns_removed` |  |  | `metadata` | 1 |
| `columns_seeded` |  |  | `metadata` | 1 |
| `country_code` |  |  | `audited allowlist` | 1 |
| `counts` |  |  | `metadata` | 1 |
| `creator_id` |  |  | `metadata`, `after`, `before`, `audited allowlist` | 7 |
| `currency` |  |  | `audited allowlist` | 1 |
| `deleted_at` |  |  | `audited allowlist` | 2 |
| `digest` |  |  | `metadata` | 1 |
| `display_name` | yes |  | `audited allowlist` | 1 |
| `email` | yes |  | `after`, `metadata`, `audited allowlist` | 3 |
| `email_verified_at` |  | **review** | `audited allowlist` | 1 |
| `enabled` |  |  | `metadata`, `before`, `after` | 6 |
| `envelope_id` |  |  | `metadata` | 1 |
| `error` |  |  | `metadata` | 1 |
| `event_type` |  |  | `metadata` | 4 |
| `field` |  | **review** | `metadata` | 1 |
| `files_deleted` |  |  | `metadata` | 1 |
| `fired_by` |  |  | `metadata` | 1 |
| `flag` |  |  | `metadata` | 1 |
| `from` |  |  | `metadata` | 2 |
| `from_column_id` |  |  | `metadata` | 1 |
| `guard` |  |  | `metadata` | 2 |
| `has_note` |  | **review** | `metadata` | 1 |
| `impersonated_user_id` |  |  | `metadata` | 2 |
| `impersonation_ulid` |  |  | `metadata` | 2 |
| `integration_event_id` |  |  | `metadata` | 7 |
| `internal_rating` |  |  | `audited allowlist` | 1 |
| `invitation_expires_at` |  |  | `audited allowlist` | 1 |
| `invitation_sent_at` |  |  | `audited allowlist` | 1 |
| `invited_by_user_id` |  |  | `audited allowlist` | 1 |
| `is_blacklisted` |  |  | `audited allowlist` | 1 |
| `is_default` |  |  | `audited allowlist` | 1 |
| `is_suspended` |  |  | `audited allowlist` | 1 |
| `kyc_method` |  |  | `audited allowlist` | 1 |
| `kyc_status` |  |  | `audited allowlist` | 1 |
| `kyc_verified_at` |  |  | `audited allowlist` | 1 |
| `last_active_at` |  |  | `audited allowlist` | 1 |
| `last_engaged_at` |  |  | `audited allowlist` | 1 |
| `last_login_at` |  |  | `audited allowlist` | 1 |
| `last_login_ip` | yes |  | `audited allowlist` | 1 |
| `last_name` | yes |  | `audited allowlist` | 1 |
| `ledger_ulid` |  |  | `metadata` | 1 |
| `mfa` |  |  | `metadata` | 1 |
| `mfa_exempted_at` |  |  | `audited allowlist` | 1 |
| `mfa_required` |  |  | `audited allowlist` | 1 |
| `mode` |  |  | `metadata` | 2 |
| `name` | yes |  | `audited allowlist` | 1 |
| `note` |  | **review** | `metadata` | 1 |
| `origin` |  |  | `metadata` | 1 |
| `payout_method_set` |  |  | `audited allowlist` | 1 |
| `payout_status` |  |  | `metadata` | 1 |
| `payouts_enabled` |  |  | `metadata` | 2 |
| `platform` |  |  | `metadata` | 1 |
| `pool_memberships_deleted` |  |  | `metadata` | 1 |
| `posted_content_id` |  |  | `metadata` | 1 |
| `preferred_currency` |  |  | `audited allowlist` | 1 |
| `preferred_language` |  |  | `audited allowlist` | 1 |
| `previous_status` |  |  | `metadata` | 2 |
| `primary_language` |  |  | `audited allowlist` | 1 |
| `profile_completeness_score` |  |  | `audited allowlist` | 1 |
| `provider` |  |  | `metadata`, `audited allowlist` | 6 |
| `provider_account_id` | yes |  | `audited allowlist` | 1 |
| `provider_event_id` |  |  | `metadata` | 1 |
| `reason` |  | **review** | `metadata` | 2 |
| `rejected_at` |  |  | `audited allowlist` | 1 |
| `rejection_reason` |  | **review** | `metadata` | 1 |
| `relationship_status` |  |  | `audited allowlist` | 1 |
| `remaining_count` |  |  | `metadata` | 1 |
| `role` |  |  | `after` | 3 |
| `row_count` |  |  | `metadata` | 1 |
| `rule_id` |  |  | `metadata` | 1 |
| `status` |  |  | `after`, `metadata`, `audited allowlist` | 3 |
| `submitted_at` |  |  | `audited allowlist` | 2 |
| `suspended_at` |  |  | `audited allowlist` | 1 |
| `suspended_reason` | yes |  | `audited allowlist` | 1 |
| `tax_form_type` |  | **review** | `audited allowlist` | 1 |
| `tax_id_country` |  | **review** | `audited allowlist` | 1 |
| `tax_profile_complete` |  | **review** | `audited allowlist` | 1 |
| `theme_preference` |  |  | `audited allowlist` | 1 |
| `threshold` |  |  | `metadata` | 2 |
| `tier` |  |  | `audited allowlist` | 1 |
| `timezone` |  |  | `audited allowlist` | 1 |
| `to` |  |  | `metadata` | 2 |
| `to_column_id` |  |  | `metadata` | 1 |
| `type` |  |  | `audited allowlist` | 1 |
| `user_id` |  |  | `after` | 1 |
| `verification_level` |  |  | `audited allowlist` | 1 |
| `verified_at` |  |  | `audited allowlist` | 2 |
| `verified_by_user_id` |  |  | `audited allowlist` | 2 |
| `visible_to_creators` |  |  | `after`, `before` | 3 |
| `welcome_message` |  | **review** | `metadata` | 1 |
| `window_hours` |  |  | `metadata` | 1 |
| `window_minutes` |  |  | `metadata` | 1 |

