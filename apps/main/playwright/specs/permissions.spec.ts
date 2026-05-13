import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
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

    const setup = await seedAgencyAdmin(request)
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
    const staffPassword = 'Password123!'

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

    // Now navigate to the team page — staff should be redirected (guard).
    await page.goto('/agency-users')

    // The requireAgencyAdmin guard should redirect to /brands.
    await expect(page.locator(dt(testIds.brandListPage))).toBeVisible({ timeout: 10000 })

    // Verify: even if staff navigates to the agency layout, they see no invite button.
    // Navigate to brands (agency-wrapped route) to confirm layout renders.
    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible()
    await expect(page.locator(dt(testIds.inviteUserBtn))).not.toBeVisible()
  })

  test('agency_admin sees Invite user button on /agency-users', async ({ page }) => {
    // Sign in as admin.
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(adminEmail)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(adminPassword)
    await page.locator(dt(testIds.signInSubmit)).click()

    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 10000 })

    // Admin can reach /agency-users.
    await page.goto('/agency-users')
    await expect(page.locator(dt(testIds.agencyUsersPage))).toBeVisible({ timeout: 10000 })
    await expect(page.locator(dt(testIds.inviteUserBtn))).toBeVisible({ timeout: 8000 })
  })
})
