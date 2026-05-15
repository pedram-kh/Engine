import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  mintTotpCodeForEmail,
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  setQueueMode,
  clearQueueMode,
  signOutViaApi,
} from '../fixtures/test-helpers'

/**
 * Critical-path E2E #9 — agency admin bulk-invites 5 creators.
 *
 * Sprint 3 Chunk 4 sub-step 11 (the sprint closer's critical-path
 * coverage). The spec drives the full agency-side bulk-invite flow as
 * a UI-only journey:
 *
 *   1. Sign up an agency_admin via the chunk-2 setup helper, with
 *      2FA enrolled inline (the bulk-invite route chain is
 *      `requireAuth → requireMfaEnrolled → requireAgencyAdmin`).
 *   2. Sign in via the SPA: email + password → MFA challenge → TOTP code
 *      minted from the persisted secret via the chunk-7.1 helper.
 *   3. From the agency-users page, click "Bulk-invite creators".
 *   4. On `/creator-invitations/bulk`, attach a CSV with 5 valid emails.
 *   5. Submit and observe the tracking surface, then the complete
 *      surface with `invited: 5`, `already_invited: 0`, `failed: 0`.
 *
 * Queue mode pinned to `sync` so the dispatched `BulkCreatorInvitationJob`
 * fires inline before the SPA's first poll lands — keeps the spec to a
 * single 3-second polling window. Pair with `clearQueueMode()` in
 * `afterEach` (same convention as setClock/resetClock).
 *
 * Auth-ip throttle neutralised to absorb the cumulative sign-in +
 * navigation calls across CI retries. Pair with `restoreThrottle()`
 * in afterEach.
 *
 * Assertions anchor on `data-test` attributes and URLs — never on
 * English copy — so a locale change does not flake the spec.
 */

const PASSWORD = 'Cata1yst-Bulk-Invite-E2E!'

function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`
}

test.describe('Sprint 3 Chunk 4 — bulk-invite critical path', () => {
  // The end-to-end journey is ~10 navigations + a poll cycle. CI cold
  // runs comfortably fit in 60s; budget 120s as a buffer so a transient
  // network hiccup does not cause a flake.
  test.describe.configure({ timeout: 120_000 })

  test.beforeEach(async ({ request }) => {
    await neutralizeThrottle(request, 'auth-ip')
    await setQueueMode(request, 'sync')
  })

  test.afterEach(async ({ request }) => {
    await clearQueueMode(request)
    await restoreThrottle(request, 'auth-ip')
    await signOutViaApi(request)
  })

  test('agency_admin bulk-invites 5 creators from a CSV', async ({ page }) => {
    const request = page.context().request

    // Seed an agency_admin with 2FA enrolled so requireMfaEnrolled
    // passes on first sign-in (no inline enrollment flow needed).
    const admin = await seedAgencyAdmin(request, {
      email: uniqueEmail('bulk-admin'),
      password: PASSWORD,
      enroll2fa: true,
    })

    if (admin.twoFactorSecret === null) {
      throw new Error('seedAgencyAdmin returned a null twoFactorSecret despite enroll2fa: true')
    }

    // --------------------------------------------------------------
    // Sign in: email + password → MFA challenge → TOTP code.
    // --------------------------------------------------------------
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(admin.email)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(PASSWORD)
    await page.locator(dt(testIds.signInSubmit)).click()

    // SPA reveals the TOTP field after the backend returns
    // `auth.mfa_required`. Mint a fresh code from the persisted
    // secret and submit.
    await expect(page.locator(dt(testIds.signInTotp))).toBeVisible({ timeout: 10_000 })
    const { code } = await mintTotpCodeForEmail(request, admin.email)
    await page.locator(dt(testIds.signInTotp)).locator('input').fill(code)
    await page.locator(dt(testIds.signInSubmit)).click()

    // Land in the agency shell (chunk-2 layout marker).
    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 15_000 })

    // --------------------------------------------------------------
    // Navigate to the bulk-invite page.
    //
    // Vuetify's `v-list-item :to` (sidebar) and `v-btn :to` (CTA) bindings
    // are intermittently flaky in Playwright — the inner router-link
    // does not always fire when the wrapper is clicked. The project
    // pattern (commit 043355e) is to assert visibility, then navigate
    // via `page.goto()` for determinism. The CTA's existence and
    // discoverability remain covered — we still `toBeVisible` it on
    // /agency-users before going direct.
    // --------------------------------------------------------------
    await page.goto('/agency-users')
    await expect(page.locator(dt(testIds.agencyUsersPage))).toBeVisible({ timeout: 10_000 })
    await expect(page.locator(dt(testIds.bulkInviteCreatorsBtn))).toBeVisible()

    await page.goto('/creator-invitations/bulk')
    await expect(page.locator(dt(testIds.bulkInvitePage))).toBeVisible({ timeout: 10_000 })

    // --------------------------------------------------------------
    // Attach a 5-row CSV and submit.
    // --------------------------------------------------------------
    const inviteeEmails = [
      uniqueEmail('invitee-1'),
      uniqueEmail('invitee-2'),
      uniqueEmail('invitee-3'),
      uniqueEmail('invitee-4'),
      uniqueEmail('invitee-5'),
    ]
    const csvContent = ['email', ...inviteeEmails].join('\n')

    // The v-file-input wrapper carries the data-test attribute; the
    // actual <input type="file"> is nested inside. Scope to that input
    // explicitly so setInputFiles targets the right element.
    const fileInput = page.locator(`${dt(testIds.bulkInviteFileInput)} input[type="file"]`)
    await fileInput.setInputFiles({
      name: 'invites.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from(csvContent, 'utf-8'),
    })

    // The page parses client-side and shows the preview heading with
    // the parsed row count. The submit button enables once parsing
    // completes with no fatal error.
    await expect(page.locator(dt(testIds.bulkInvitePreviewHeading))).toBeVisible({
      timeout: 10_000,
    })
    await expect(page.locator(dt(testIds.bulkInviteSubmit))).toBeEnabled()
    await page.locator(dt(testIds.bulkInviteSubmit)).click()

    // --------------------------------------------------------------
    // Tracking → complete (sync queue mode fires the job inline; the
    // first poll lands on `complete`). The poll cadence is 3s so a
    // 20s wait window is generous.
    // --------------------------------------------------------------
    await expect(page.locator(dt(testIds.bulkInviteComplete))).toBeVisible({ timeout: 20_000 })

    await expect(page.locator(dt(testIds.bulkInviteStatInvited))).toContainText('5')
    await expect(page.locator(dt(testIds.bulkInviteStatAlreadyInvited))).toContainText('0')
    await expect(page.locator(dt(testIds.bulkInviteStatFailed))).toContainText('0')
  })
})
