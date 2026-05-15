import { expect, test } from '@playwright/test'

import { dt, testIds } from '../helpers/selectors'
import {
  neutralizeThrottle,
  resetClock,
  restoreThrottle,
  seedAgencyAdmin,
  seedAgencyInvitation,
  signOutViaApi,
  signUpUser,
} from '../fixtures/test-helpers'

/**
 * AcceptInvitationPage error-path coverage — Sprint 3 Chunk 4 sub-step 8.
 *
 * Closes the Sprint 2 § e carry-forward: two of AcceptInvitationPage's
 * 10 states (email-mismatch + already-member) were reachable only via
 * the accept error path and had no automated test driving them. This
 * file drives both via production code paths (no extra test helpers
 * for membership seeding — the already-member state is reached by
 * accepting one invitation, then accepting a SECOND invitation for
 * the same user/agency pair).
 *
 * Chunk-7.1 saga conventions applied from first commit:
 *   - `auth-ip` rate-limiter neutralised in beforeEach; restored in
 *     afterEach so spec ordering can't poison sign-in throttling.
 *   - `setClock` is NOT used in this file; we still pair the
 *     `resetClock` afterEach as defence-in-depth (some siblings DO
 *     pin time and the cleanup is idempotent on a never-set clock).
 *   - `defaultHeaders` lives in `test-helpers.ts` and is forwarded by
 *     every wrapper; specs never spell the header set themselves.
 *   - No parent `data-test` fall-through — each error state is
 *     anchored on the state-specific `data-test` attribute
 *     (`accept-invitation-email-mismatch`, `accept-invitation-already-
 *     member`) rather than the page-root selector.
 */

test.describe('AcceptInvitationPage — error paths', () => {
  let agencyUlid: string

  test.beforeEach(async ({ page }) => {
    const request = page.context().request
    await neutralizeThrottle(request, 'auth-ip')

    // seedAgencyAdmin provisions an agency + admin we never log in as
    // (the tests sign in as the invitee instead). We only need the
    // returned `agencyUlid` to seed downstream invitations against.
    const setup = await seedAgencyAdmin(request)
    agencyUlid = setup.agencyUlid
  })

  test.afterEach(async ({ page }) => {
    const request = page.context().request
    await restoreThrottle(request, 'auth-ip')
    await signOutViaApi(request)
    await resetClock(request)
  })

  test('signed-in invitee whose email does not match the invitation sees the email-mismatch state', async ({
    page,
  }) => {
    const request = page.context().request

    // The invitation is bound to an "alice" email; user B ("bob")
    // signs in instead and tries to accept. Backend returns 403 +
    // `invitation.email_mismatch`; the page transitions to the
    // dedicated state.
    const aliceEmail = `alice-${Date.now()}@catalyst-test.dev`
    const bobEmail = `bob-${Date.now()}@catalyst-test.dev`
    const bobPassword = 'Cata1yst-E2E-Bob!'

    await signUpUser(request, bobEmail, bobPassword, 'Bob B')

    const { acceptUrl } = await seedAgencyInvitation(request, agencyUlid, {
      email: aliceEmail,
      role: 'agency_manager',
    })

    // Sign in as Bob via the SPA.
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(bobEmail)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(bobPassword)
    await page.locator(dt(testIds.signInSubmit)).click()
    await page.waitForTimeout(1000)

    // Navigate to the accept URL and try to accept while signed in as Bob.
    await page.goto(acceptUrl)
    await expect(page.locator(dt(testIds.acceptInvitationPending))).toBeVisible({ timeout: 8000 })
    await page.locator(dt(testIds.acceptInvitationBtn)).click()

    // The page must transition to the email-mismatch state, anchored
    // on the state-specific data-test (not the page-root selector).
    await expect(page.locator(dt(testIds.acceptInvitationEmailMismatch))).toBeVisible({
      timeout: 8000,
    })
    // Belt-and-suspenders: none of the other state cards is rendered
    // at the same time. The page is supposed to render exactly one
    // card at a time; a regression that double-renders would surface
    // here.
    await expect(page.locator(dt(testIds.acceptInvitationPending))).not.toBeVisible()
    await expect(page.locator(dt(testIds.acceptInvitationSuccess))).not.toBeVisible()
  })

  test('user already a member of the agency sees the already-member state when accepting a second invitation', async ({
    page,
  }) => {
    const request = page.context().request

    const userEmail = `already-member-${Date.now()}@catalyst-test.dev`
    const userPassword = 'Cata1yst-E2E-AM!'
    await signUpUser(request, userEmail, userPassword, 'Already Member')

    // First invitation → user accepts it → they become a member.
    const { acceptUrl: firstAcceptUrl } = await seedAgencyInvitation(request, agencyUlid, {
      email: userEmail,
      role: 'agency_manager',
    })

    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(userEmail)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(userPassword)
    await page.locator(dt(testIds.signInSubmit)).click()
    await page.waitForTimeout(1000)

    await page.goto(firstAcceptUrl)
    await expect(page.locator(dt(testIds.acceptInvitationPending))).toBeVisible({ timeout: 8000 })
    await page.locator(dt(testIds.acceptInvitationBtn)).click()
    await expect(page.locator(dt(testIds.acceptInvitationSuccess))).toBeVisible({ timeout: 10000 })

    // Now seed a SECOND invitation for the same email + agency. The
    // backend's `InvitationController::accept` flow checks
    // `already_member` BEFORE flipping the invitation — so the new
    // invitation can be in pending state and the membership check
    // still fires.
    const { acceptUrl: secondAcceptUrl } = await seedAgencyInvitation(request, agencyUlid, {
      email: userEmail,
      role: 'agency_manager',
    })

    await page.goto(secondAcceptUrl)
    await expect(page.locator(dt(testIds.acceptInvitationPending))).toBeVisible({ timeout: 8000 })
    await page.locator(dt(testIds.acceptInvitationBtn)).click()

    // The page transitions to the already-member state. Anchored on
    // the state-specific data-test attribute per the chunk-7.1 "no
    // parent data-test fall-through" convention.
    await expect(page.locator(dt(testIds.acceptInvitationAlreadyMember))).toBeVisible({
      timeout: 8000,
    })
    await expect(page.locator(dt(testIds.acceptInvitationPending))).not.toBeVisible()
    await expect(page.locator(dt(testIds.acceptInvitationSuccess))).not.toBeVisible()
  })
})
