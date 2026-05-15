import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  mintTotpCodeForEmail,
  neutralizeThrottle,
  restoreThrottle,
  seedAgencyAdmin,
  seedAgencyInvitation,
  signOutViaApi,
  signUpUser,
} from '../fixtures/test-helpers'

/**
 * Permission gating spec.
 *
 * Verifies that:
 *   - agency_staff cannot see the "Invite user" button on the team page.
 *   - agency_staff is redirected away from /agency-users (guard enforced).
 *   - Only agency_admin can access invitation form.
 *
 * Chunk-7.1 conventions (all applied from first commit):
 *   - auth-ip neutralised + restored
 *   - No parent data-test attribute fall-through
 */

test.describe('Permission gating', () => {
  let adminEmail: string
  let adminPassword: string
  let agencyUlid: string

  test.beforeEach(async ({ page }) => {
    const request = page.context().request
    await neutralizeThrottle(request, 'auth-ip')

    // Sprint 3 Chunk 4 sub-step 5 added `requireMfaEnrolled` to the
    // `agency-users.list` route chain. The admin-side test below drives
    // the SPA through that route, so the seeded admin must come
    // pre-enrolled in MFA.
    const setup = await seedAgencyAdmin(request, { enroll2fa: true })
    adminEmail = setup.email
    adminPassword = setup.password
    agencyUlid = setup.agencyUlid
  })

  test.afterEach(async ({ page }) => {
    const request = page.context().request
    await restoreThrottle(request, 'auth-ip')
    await signOutViaApi(request)
  })

  test('agency_staff cannot see Invite user button and is redirected from /agency-users', async ({
    page,
  }) => {
    const request = page.context().request

    // Create a staff member by seeding an invitation + accepting it.
    const staffEmail = `staff-${Date.now()}@catalyst-test.dev`
    const staffPassword = 'Cata1yst-E2E-Staff!'

    // Create the staff user account.
    await signUpUser(request, staffEmail, staffPassword, 'Staff User')

    // Seed an invitation for the staff user.
    const { acceptUrl } = await seedAgencyInvitation(request, agencyUlid, {
      email: staffEmail,
      role: 'agency_staff',
    })

    // Sign in as staff user.
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(staffEmail)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(staffPassword)
    await page.locator(dt(testIds.signInSubmit)).click()

    // Wait for auth to settle.
    await page.waitForTimeout(1000)

    // Accept the invitation (staff user must do this first to join the agency).
    await page.goto(acceptUrl)
    await expect(page.locator(dt(testIds.acceptInvitationPending))).toBeVisible({ timeout: 10000 })
    await page.locator(dt(testIds.acceptInvitationBtn)).click()
    await expect(page.locator(dt(testIds.acceptInvitationSuccess))).toBeVisible({ timeout: 8000 })

    // Now navigate to the team page — staff should be redirected.
    //
    // Sprint 3 Chunk 4 sub-step 5 promoted the `agency-users.list` guard
    // chain to `requireAuth → requireMfaEnrolled → requireAgencyAdmin`.
    // The staff user has no MFA enrolled, so the chain fails at
    // `requireMfaEnrolled` BEFORE reaching `requireAgencyAdmin` and the
    // user is bounced to the 2FA-enable page rather than `/brands`. The
    // staff-cannot-reach-/agency-users contract still holds — only the
    // landing surface changed.
    await page.goto('/agency-users')
    await expect(page).toHaveURL(/\/auth\/2fa\/enable/, { timeout: 10000 })
    await expect(page.locator(dt(testIds.enableTotpPage))).toBeVisible({ timeout: 10000 })

    // Defence-in-depth: even if the staff user lands on /brands directly
    // (the legacy redirect target), the agency layout must hide the
    // Invite-user CTA.
    await page.goto('/brands')
    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 10000 })
    await expect(page.locator(dt(testIds.inviteUserBtn))).not.toBeVisible()
  })

  test('agency_admin sees Invite user button on /agency-users', async ({ page }) => {
    const request = page.context().request

    // Sign in as admin — email + password → MFA challenge → TOTP code.
    // The MFA hop is mandatory now that `agency-users.list` is gated by
    // `requireMfaEnrolled` (Sprint 3 Chunk 4 sub-step 5). The
    // `seedAgencyAdmin` helper enrolled the admin in MFA above; the
    // `mintTotpCodeForEmail` helper reads the persisted secret and
    // returns the current 6-digit code.
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(adminEmail)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(adminPassword)
    await page.locator(dt(testIds.signInSubmit)).click()

    await expect(page.locator(dt(testIds.signInTotp))).toBeVisible({ timeout: 10000 })
    const { code } = await mintTotpCodeForEmail(request, adminEmail)
    await page.locator(dt(testIds.signInTotp)).locator('input').fill(code)
    await page.locator(dt(testIds.signInSubmit)).click()

    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 10000 })

    // Admin can reach /agency-users.
    await page.goto('/agency-users')
    await expect(page.locator(dt(testIds.agencyUsersPage))).toBeVisible({ timeout: 10000 })
    await expect(page.locator(dt(testIds.inviteUserBtn))).toBeVisible({ timeout: 8000 })
  })
})
