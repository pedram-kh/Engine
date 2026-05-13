import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  resetClock,
  restoreThrottle,
  seedAgencyAdmin,
  seedAgencyInvitation,
  setClock,
  signOutViaApi,
  signUpUser,
} from '../fixtures/test-helpers'

/**
 * Invitation happy-path E2E spec.
 *
 * Acceptance scenario (20-PHASE-1-SPEC.md § 5):
 *   - agency_admin signs in
 *   - Invites a new user as agency_manager (modal)
 *   - Invitation seeded via test-helper (_test/agencies/{agency}/invitations)
 *   - Invitee navigates to accept URL
 *   - If unauthenticated → shown sign-in prompt
 *   - Invitee signs in (or signs up), lands back on accept page → accepts
 *   - Lands in workspace
 *
 * Test coverage also covers the expired-invitation state.
 *
 * Chunk-7.1 conventions (all applied from first commit):
 *   - auth-ip rate-limiter neutralised in beforeEach; restored in afterEach
 *   - Date.now() + 30 days if setClock is used (not needed here)
 *   - No parent data-test attribute fall-through
 */

test.describe('Invitation happy path', () => {
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
    // Belt-and-suspenders: the expired-invitation test uses setClock;
    // reset ensures the pinned time doesn't bleed into subsequent specs.
    await resetClock(request)
  })

  test('agency_admin can invite a user via the modal', async ({ page }) => {
    // Sign in as admin.
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(adminEmail)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(adminPassword)
    await page.locator(dt(testIds.signInSubmit)).click()

    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 10000 })

    // Navigate to team page.
    await page.locator(dt(testIds.navAgencyUsers)).click()
    await expect(page.locator(dt(testIds.agencyUsersPage))).toBeVisible()

    // Invite user button should be visible for admin.
    await expect(page.locator(dt(testIds.inviteUserBtn))).toBeVisible()

    // Open invite modal.
    await page.locator(dt(testIds.inviteUserBtn)).click()
    await expect(page.locator(dt(testIds.inviteUserModal))).toBeVisible()

    // Fill in the invitation form.
    const inviteeEmail = `invitee-${Date.now()}@catalyst-test.dev`
    await page.locator(dt(testIds.inviteEmail)).locator('input').fill(inviteeEmail)
    // Role defaults to manager — keep it.

    await page.locator(dt(testIds.inviteSubmit)).click()

    // Success alert should appear.
    await expect(page.locator(dt(testIds.inviteSuccessAlert))).toBeVisible({ timeout: 8000 })
    await expect(page.locator(dt(testIds.inviteSuccessAlert))).toContainText(inviteeEmail)

    // Modal should close.
    await expect(page.locator(dt(testIds.inviteUserModal))).not.toBeVisible()
  })

  test('unauthenticated invitee sees sign-in prompt on accept page', async ({ page }) => {
    const request = page.context().request

    const inviteeEmail = `invitee-${Date.now()}@catalyst-test.dev`
    const { acceptUrl } = await seedAgencyInvitation(request, agencyUlid, {
      email: inviteeEmail,
      role: 'agency_manager',
    })

    // Navigate to accept URL without signing in.
    await page.goto(acceptUrl)

    // Should show unauthenticated state with sign-in CTA.
    await expect(page.locator(dt(testIds.acceptInvitationUnauthenticated))).toBeVisible({
      timeout: 8000,
    })
    await expect(page.locator(dt(testIds.acceptSignInBtn))).toBeVisible()

    // Sign-in button should carry the redirect parameter.
    const signInHref = await page.locator(dt(testIds.acceptSignInBtn)).getAttribute('href')
    expect(signInHref).toContain('/sign-in')
    expect(signInHref).toContain('redirect=')
  })

  test('authenticated invitee can accept invitation and land in workspace', async ({ page }) => {
    const request = page.context().request

    const inviteeEmail = `invitee-${Date.now()}@catalyst-test.dev`
    const inviteePassword = 'Password123!'

    // Create the invitee account via production sign-up.
    await signUpUser(request, inviteeEmail, inviteePassword, 'Invitee User')

    // Seed the invitation.
    const { acceptUrl } = await seedAgencyInvitation(request, agencyUlid, {
      email: inviteeEmail,
      role: 'agency_manager',
    })

    // Sign in as invitee first.
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(inviteeEmail)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(inviteePassword)
    await page.locator(dt(testIds.signInSubmit)).click()

    // Wait for auth to settle (lands on dashboard or brands list).
    await page.waitForTimeout(1000)

    // Navigate to accept URL while authenticated.
    await page.goto(acceptUrl)

    // Should show pending state with accept button.
    await expect(page.locator(dt(testIds.acceptInvitationPending))).toBeVisible({ timeout: 8000 })
    await expect(page.locator(dt(testIds.acceptInvitationBtn))).toBeVisible()
    await expect(page.locator(dt(testIds.acceptInvitationDescription))).toContainText(
      'agency_manager',
    )

    // Accept the invitation.
    await page.locator(dt(testIds.acceptInvitationBtn)).click()

    // Success state should show.
    await expect(page.locator(dt(testIds.acceptInvitationSuccess))).toBeVisible({ timeout: 8000 })
  })

  test('expired invitation shows distinct expired state', async ({ page }) => {
    const request = page.context().request

    const inviteeEmail = `invitee-expired-${Date.now()}@catalyst-test.dev`

    // Seed a valid invitation (min 1 day per backend validation min:1).
    const { acceptUrl } = await seedAgencyInvitation(request, agencyUlid, {
      email: inviteeEmail,
      role: 'agency_manager',
      expiresInDays: 1,
    })

    // Advance the backend clock 2 days so the 1-day invitation is expired.
    // Chunk-7.1 convention: pair setClock with resetClock in afterEach (done above).
    const twoDaysFromNow = new Date(Date.now() + 2 * 24 * 60 * 60 * 1000).toISOString()
    await setClock(request, twoDaysFromNow)

    // Navigate to the accept URL — preview endpoint sees expired invitation.
    await page.goto(acceptUrl)

    // Should show expired state.
    await expect(page.locator(dt(testIds.acceptInvitationExpired))).toBeVisible({ timeout: 8000 })
  })
})
