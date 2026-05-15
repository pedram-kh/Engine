/**
 * Single source of truth for the `data-test` selectors the chunk-6.8
 * Playwright specs touch.
 *
 * Why centralised:
 *   - A renamed selector breaks the test compile (`testIds.signInPage`
 *     no longer resolves) instead of the test runtime (selector
 *     never matches and Playwright times out), which makes the
 *     failure trivially diagnosable.
 *   - Specs read these as named imports, so a refactor that renames
 *     a `data-test` attribute requires editing this one file rather
 *     than chasing string literals across the spec tree.
 *
 * Anchored on the `data-test="…"` attributes already shipped by the
 * chunk 6.6 / 6.7 page components — none of the values below are
 * new; they mirror what is in the .vue templates.
 *
 * Building selector strings:
 *   - `dt(id)` returns `[data-test="<id>"]` — the standard CSS
 *     attribute selector Playwright's `page.locator()` accepts.
 *   - Specs can compose selectors via Playwright's locator API:
 *     `page.locator(dt(testIds.signInError)).innerText()`.
 */

export const testIds = {
  // ---------------------------------------------------------------
  // Sign-in (apps/main/src/modules/auth/pages/SignInPage.vue)
  // ---------------------------------------------------------------
  signInPage: 'sign-in-page',
  signInHeading: 'sign-in-heading',
  signInEmail: 'sign-in-email',
  signInPassword: 'sign-in-password',
  signInTotp: 'sign-in-totp',
  signInError: 'sign-in-error',
  signInSubmit: 'sign-in-submit',
  signInSignupLink: 'sign-in-signup-link',

  // ---------------------------------------------------------------
  // Sign-up (apps/main/src/modules/auth/pages/SignUpPage.vue)
  // ---------------------------------------------------------------
  signUpPage: 'sign-up-page',
  signUpHeading: 'sign-up-heading',
  signUpName: 'sign-up-name',
  signUpEmail: 'sign-up-email',
  signUpPassword: 'sign-up-password',
  signUpPasswordConfirmation: 'sign-up-password-confirmation',
  signUpError: 'sign-up-error',
  signUpSubmit: 'sign-up-submit',

  // ---------------------------------------------------------------
  // Email verification pending (post-sign-up landing page)
  // ---------------------------------------------------------------
  emailVerificationPendingPage: 'email-verification-pending-page',
  emailVerificationPendingHeading: 'email-verification-pending-heading',

  // ---------------------------------------------------------------
  // Email verification confirm (link-target page)
  // ---------------------------------------------------------------
  emailVerificationConfirmPage: 'email-verification-confirm-page',
  emailVerificationConfirmSuccess: 'email-verification-confirm-success',
  emailVerificationConfirmError: 'email-verification-confirm-error',

  // ---------------------------------------------------------------
  // Enable 2FA (apps/main/src/modules/auth/pages/EnableTotpPage.vue)
  // ---------------------------------------------------------------
  enableTotpPage: 'enable-totp-page',
  enableTotpHeading: 'enable-totp-heading',
  enableTotpQr: 'enable-totp-qr',
  enableTotpManualKey: 'enable-totp-manual-key',
  enableTotpCode: 'enable-totp-code',
  enableTotpError: 'enable-totp-error',
  enableTotpSubmit: 'enable-totp-submit',

  // ---------------------------------------------------------------
  // Recovery codes (chunk 6.7 component)
  // ---------------------------------------------------------------
  recoveryCodesDisplay: 'recovery-codes-display',
  recoveryCodesList: 'recovery-codes-list',
  recoveryCodesCountdown: 'recovery-codes-countdown',
  recoveryCodesConfirm: 'recovery-codes-confirm',

  // ---------------------------------------------------------------
  // Auth shell (AuthLayout.vue) — the brand mark is a stable
  // anchor that proves AuthLayout mounted, distinct from the
  // routed page underneath.
  // ---------------------------------------------------------------
  authBrand: 'auth-brand',

  // ---------------------------------------------------------------
  // Agency layout shell (AgencyLayout.vue) — Sprint 2 Chunk 2
  // ---------------------------------------------------------------
  agencyLayout: 'agency-layout',
  agencySidebar: 'agency-sidebar',
  agencyTopbar: 'agency-topbar',
  agencyMain: 'agency-main',
  sidebarWorkspaceName: 'sidebar-workspace-name',
  navDashboard: 'nav-dashboard',
  navBrands: 'nav-brands',
  navAgencyUsers: 'nav-agencyUsers',
  navSettings: 'nav-settings',
  workspaceSwitcher: 'workspace-switcher',
  userMenuBtn: 'user-menu-btn',
  userMenuName: 'user-menu-name',
  userMenu: 'user-menu',
  userMenuLocaleSwitcher: 'user-menu-locale-switcher',
  signOutBtn: 'sign-out-btn',

  // ---------------------------------------------------------------
  // Brand pages — Sprint 2 Chunk 2
  // ---------------------------------------------------------------
  brandListPage: 'brand-list-page',
  brandListHeading: 'brand-list-heading',
  brandCreateBtn: 'brand-create-btn',
  brandStatusFilter: 'brand-status-filter',
  brandTable: 'brand-table',
  brandEmptyState: 'brand-empty-state',
  brandEmptyCta: 'brand-empty-cta',
  brandEmptyFiltered: 'brand-empty-filtered',
  brandListError: 'brand-list-error',
  brandListSkeleton: 'brand-list-skeleton',

  brandCreatePage: 'brand-create-page',
  brandCreateHeading: 'brand-create-heading',

  brandDetailPage: 'brand-detail-page',
  brandDetailHeading: 'brand-detail-heading',
  brandDetailCard: 'brand-detail-card',
  brandDetailSkeleton: 'brand-detail-skeleton',
  brandDetailStatus: 'brand-detail-status',
  brandEditBtn: 'brand-edit-btn',
  brandArchiveBtn: 'brand-archive-btn',
  brandDetailArchiveDialog: 'brand-detail-archive-dialog',
  brandDetailArchiveConfirm: 'brand-detail-archive-confirm',
  brandDetailArchiveCancel: 'brand-detail-archive-cancel',

  brandEditPage: 'brand-edit-page',
  brandEditHeading: 'brand-edit-heading',
  brandEditSkeleton: 'brand-edit-skeleton',

  // Brand form (shared)
  brandForm: 'brand-form',
  brandName: 'brand-name',
  brandSlug: 'brand-slug',
  brandDescription: 'brand-description',
  brandIndustry: 'brand-industry',
  brandWebsiteUrl: 'brand-website-url',
  brandDefaultCurrency: 'brand-default-currency',
  brandDefaultLanguage: 'brand-default-language',
  brandFormSubmit: 'brand-form-submit',
  brandFormError: 'brand-form-error',

  // Archive dialog (in list page)
  archiveDialog: 'archive-dialog',
  archiveDialogTitle: 'archive-dialog-title',
  archiveDialogMessage: 'archive-dialog-message',
  archiveDialogConfirm: 'archive-dialog-confirm',
  archiveDialogCancel: 'archive-dialog-cancel',

  // ---------------------------------------------------------------
  // Agency users / invitations — Sprint 2 Chunk 2
  // ---------------------------------------------------------------
  agencyUsersPage: 'agency-users-page',
  agencyUsersHeading: 'agency-users-heading',
  inviteUserBtn: 'invite-user-btn',
  membersTable: 'members-table',
  inviteSuccessAlert: 'invite-success-alert',

  // Invite modal
  inviteUserModal: 'invite-user-modal',
  inviteModalTitle: 'invite-modal-title',
  inviteForm: 'invite-form',
  inviteEmail: 'invite-email',
  inviteRole: 'invite-role',
  inviteSubmit: 'invite-submit',
  inviteCancel: 'invite-cancel',
  inviteError: 'invite-error',

  // Accept invitation page
  acceptInvitationPage: 'accept-invitation-page',
  acceptInvitationSkeleton: 'accept-invitation-skeleton',
  acceptInvitationPending: 'accept-invitation-pending',
  acceptInvitationDescription: 'accept-invitation-description',
  acceptInvitationBtn: 'accept-invitation-btn',
  acceptInvitationExpired: 'accept-invitation-expired',
  acceptInvitationAlreadyAccepted: 'accept-invitation-already-accepted',
  acceptInvitationUnauthenticated: 'accept-invitation-unauthenticated',
  acceptSignInBtn: 'accept-sign-in-btn',
  acceptSignUpLink: 'accept-sign-up-link',
  acceptInvitationSuccess: 'accept-invitation-success',
  acceptInvitationSuccessMsg: 'accept-invitation-success-msg',
  acceptInvitationEmailMismatch: 'accept-invitation-email-mismatch',
  acceptInvitationNotFound: 'accept-invitation-not-found',
  acceptInvitationAlreadyMember: 'accept-invitation-already-member',
  alreadyAcceptedSignIn: 'already-accepted-sign-in',

  // ---------------------------------------------------------------
  // Onboarding wizard — Welcome Back surface (Sprint 3 Chunk 3
  // sub-step 2). The wizard step pages themselves use `data-testid="…"`
  // attributes; only the WelcomeBackPage shipped with the legacy
  // `data-test="…"` attribute so it lands here. New wizard E2E selectors
  // should use the `data-testid` attribute directly via
  // `page.locator('[data-testid="step-foo"]')`.
  // ---------------------------------------------------------------
  welcomeBackPage: 'welcome-back-page',
  welcomeBackHeading: 'welcome-back-heading',
  welcomeBackContinueBtn: 'welcome-back-continue-btn',

  // ---------------------------------------------------------------
  // Bulk-invite page — Sprint 3 Chunk 4 sub-step 11
  // (apps/main/src/modules/creator-invitations/pages/BulkInvitePage.vue)
  // ---------------------------------------------------------------
  bulkInvitePage: 'bulk-invite-page',
  bulkInviteFileInput: 'bulk-invite-file-input',
  bulkInvitePreviewHeading: 'bulk-invite-preview-heading',
  bulkInviteSubmit: 'bulk-invite-submit',
  bulkInviteTracking: 'bulk-invite-tracking',
  bulkInviteComplete: 'bulk-invite-complete',
  bulkInviteStatInvited: 'bulk-invite-stat-invited',
  bulkInviteStatAlreadyInvited: 'bulk-invite-stat-already-invited',
  bulkInviteStatFailed: 'bulk-invite-stat-failed',
  bulkInviteCreatorsBtn: 'bulk-invite-creators-btn',

  // ---------------------------------------------------------------
  // Settings page — Sprint 2 Chunk 2
  // ---------------------------------------------------------------
  settingsPage: 'settings-page',
  settingsHeading: 'settings-heading',
  settingsSkeleton: 'settings-skeleton',
  settingsForm: 'settings-form',
  settingsCurrency: 'settings-currency',
  settingsLanguage: 'settings-language',
  settingsSaveBtn: 'settings-save-btn',
  settingsSuccess: 'settings-success',
  settingsSaveError: 'settings-save-error',
  settingsReadonlyNotice: 'settings-readonly-notice',
} as const

export type TestId = (typeof testIds)[keyof typeof testIds]

/**
 * `dt('sign-in-page')` → `[data-test="sign-in-page"]`. Spec-side
 * convenience so the locator builder is one short call instead of a
 * template string.
 */
export function dt(id: TestId): string {
  return `[data-test="${id}"]`
}
