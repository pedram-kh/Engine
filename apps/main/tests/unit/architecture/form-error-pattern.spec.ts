/**
 * Form-error-pattern architecture test (Sprint 3.5 Chunk 2 — § 1.6).
 *
 * Pins the canonical per-field 422 binding pattern: forms that submit user
 * input and surface validation errors do so by importing
 * `extractFieldErrors` from `@catalyst/api-client` and binding the result
 * to each input's `error-messages`. This is the pattern established in
 * Sprint 3 Chunk 5 + the stabilization audit (SignUp / ResetPassword /
 * InviteUserModal). It is the safeguard against the failure mode where a
 * new form renders the generic banner for a `validation.failed` envelope
 * and the per-rule message stays trapped in `details[]`.
 *
 * Detection strategy (Q-chunk-2-4 = (c) allowlist-driven):
 *   A hard-coded list of the files that legitimately bind per-field 422.
 *   Each MUST keep its `extractFieldErrors` import. Heuristic "file uses an
 *   input + talks to an API" detection was rejected: it false-positives on
 *   view-only pages with filter selects (e.g. BrandForm receives errors via
 *   props; search/filter selects never submit). The allowlist grows
 *   deliberately at code-review time when a new submitting form lands.
 *
 * Break-revert (standing standard #40): delete the `extractFieldErrors`
 * import from any allowlisted file → this test fails → revert.
 *
 * Admin SPA — intentionally NOT mirrored (Q-chunk-2-4):
 *   The admin SPA has ZERO `extractFieldErrors` consumers today; its
 *   auth-page 422 parity is a documented deferral ("Audit remaining
 *   auth-flow pages for per-field 422 rendering parity" in
 *   docs/tech-debt.md). Mirroring this test to admin would assert an empty
 *   / contradictory set, so the invariant is scoped to the main SPA where
 *   the canonical consumers live. When the admin deferral is resolved, an
 *   admin mirror with its own allowlist becomes the natural counterpart.
 */

import { promises as fs } from 'node:fs'
import path from 'node:path'

import { describe, expect, it } from 'vitest'

const SRC_ROOT = path.resolve(__dirname, '../../../src')

/**
 * Files that submit input and bind per-field 422 errors. Each MUST import
 * `extractFieldErrors` from `@catalyst/api-client`. Adding a row requires a
 * code review (a new submitting form), per the allowlist-discipline
 * standing standard (5.15).
 */
const CANONICAL_422_FILES: readonly string[] = [
  'modules/auth/pages/SignUpPage.vue',
  'modules/auth/pages/ResetPasswordPage.vue',
  'modules/brands/pages/BrandCreatePage.vue',
  'modules/brands/pages/BrandEditPage.vue',
  'modules/campaigns/pages/CampaignCreatePage.vue',
  'modules/campaigns/pages/CampaignDetailPage.vue',
  'modules/agency-users/components/InviteUserModal.vue',
  'modules/onboarding/pages/Step2ProfileBasicsPage.vue',
  'modules/onboarding/pages/Step3SocialAccountsPage.vue',
  'modules/onboarding/pages/Step6TaxPage.vue',
  'modules/creators/availability/components/AvailabilityBlockDialog.vue',
  // Sprint 8 Chunk 2 (D-8): the creator counter fee-form binds the 422 from a
  // non-positive / wrong-currency fee onto the amount field.
  'modules/creators/pages/CreatorAssignmentsPage.vue',
  // Sprint 9 Chunk 1 (D-9): the creator draft-submit + posted-content forms bind
  // 422s onto their fields (caption/media, platform/post_url).
  'modules/creators/pages/CreatorAssignmentDetailPage.vue',
  // Re-invite UI chunk (D-4): the agency re-offer fee-form binds 422s onto the
  // amount field (counter-dialog shape, not the invite dialog's unbound field).
  'modules/campaigns/components/ReinviteDialog.vue',
  // Sprint 9 Chunk 2 (D-8): the agency review drawer binds the feedback-required
  // 422 onto the review_feedback textarea (request-revision / reject).
  'modules/campaigns/components/ReviewDraftDrawer.vue',
  // Sprint 11 (D-11): the shared chat compose form binds the send 422 (body /
  // attachments) onto its fields. Consumed by both the agency Messages tab and
  // the creator's inline thread.
  'modules/messaging/components/ChatPanel.vue',
  // Sprint 12 Chunk 2 (D-6): the board column add/edit form binds the create /
  // update 422 onto the name + color_token fields. (The column DELETE dialog
  // deliberately uses ApiError.code BANNERS instead — a whole-operation refusal
  // with no field to pin onto — so it is intentionally NOT on this allowlist.)
  'modules/boards/components/BoardColumnDialog.vue',
]

const IMPORTS_EXTRACT_FIELD_ERRORS =
  /import\s*\{[^}]*\bextractFieldErrors\b[^}]*\}\s*from\s*['"]@catalyst\/api-client['"]/s

describe('form-error pattern — canonical 422 binding is preserved', () => {
  it.each(CANONICAL_422_FILES)(
    '%s imports extractFieldErrors from @catalyst/api-client',
    async (relative) => {
      const absolute = path.join(SRC_ROOT, relative)
      // Fails loudly if the file is renamed/moved without updating the
      // allowlist (guards against a silent drop of a canonical form).
      const contents = await fs.readFile(absolute, 'utf8')
      expect(IMPORTS_EXTRACT_FIELD_ERRORS.test(contents)).toBe(true)
    },
  )
})
