import { expect, test } from '../fixtures/test'

import { dt, testIds } from '../helpers/selectors'
import {
  mintTotpCodeForEmail,
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
 *   - If unauthenticated AND an account already exists for that email →
 *     shown the sign-in prompt (D1's existing-account path, byte-identical
 *     to before AH-095).
 *   - If unauthenticated AND no account exists → AH-095: shown the inline
 *     set-password form; submitting creates the account, activates the
 *     membership, and signs the user in — no detour through /sign-up.
 *   - An already-authenticated invitee lands back on the accept page →
 *     accepts via the existing `pending` card.
 *   - Lands in workspace either way.
 *
 * Test coverage also covers the expired-invitation state.
 *
 * AH-095 note: the test formerly named 'unauthenticated invitee sees
 * sign-in prompt on accept page' seeded a brand-new invitee email that
 * had never signed up — i.e. exactly the `account_exists: false`
 * precondition. Before AH-095 that was the ONLY unauthenticated branch,
 * so the test's assertion (sign-in prompt) was correct for its own
 * precondition. AH-095 changed what that precondition renders (the
 * set-password form, not the sign-in prompt) — the test is FIXED below,
 * not extended, and a new leg now covers the still-sign-in-prompt
 * `account_exists: true` case its old name implied but its body never
 * actually set up.
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

    // Sprint 3 Chunk 4 sub-step 5 added `requireMfaEnrolled` to the
    // `agency-users.list` route chain. The "agency_admin can invite"
    // test below drives the SPA through that route, so the seeded
    // admin must come pre-enrolled in MFA. Other tests in this file
    // don't traverse `/agency-users` — they exercise the magic-link
    // accept page — so the extra enrollment is inert for them.
    const setup = await seedAgencyAdmin(request, { enroll2fa: true })
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

    // Navigate to the team page. Vuetify's `v-list-item :to` click is
    // intermittently flaky in Playwright (see commit 043355e); the
    // project pattern is to assert the nav link is visible and then
    // `page.goto()` for determinism.
    await expect(page.locator(dt(testIds.navAgencyUsers))).toBeVisible()
    await page.goto('/agency-users')
    await expect(page.locator(dt(testIds.agencyUsersPage))).toBeVisible({ timeout: 10000 })

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

  test('AH-095: unauthenticated stranger invitee (no account exists) sees the set-password form, creates the account, and lands signed-in in the workspace', async ({
    page,
  }) => {
    const request = page.context().request

    // A brand-new email with NO prior sign-up — the D1 `account_exists:
    // false` branch. This is the exact precondition the pre-AH-095 test
    // used; the fix is what it now asserts, not the seeding.
    const inviteeEmail = `invitee-${Date.now()}@catalyst-test.dev`
    const { acceptUrl } = await seedAgencyInvitation(request, agencyUlid, {
      email: inviteeEmail,
      role: 'agency_manager',
    })

    // Navigate to accept URL without signing in.
    await page.goto(acceptUrl)

    // The stranger path: the set-password form, NOT the sign-in prompt.
    await expect(page.locator(dt(testIds.acceptInvitationCreateAccount))).toBeVisible({
      timeout: 8000,
    })
    await expect(page.locator(dt(testIds.acceptInvitationUnauthenticated))).not.toBeVisible()

    // The email field renders the invited email, read-only — the
    // structural email-immutability pin at the E2E layer.
    const emailInput = page.locator(dt(testIds.createAccountEmail)).locator('input')
    await expect(emailInput).toHaveValue(inviteeEmail)
    await expect(emailInput).toHaveAttribute('readonly', '')

    // Fill and submit the set-password form.
    const password = 'Correct-Horse-Battery-Staple-42'
    await page.locator(dt(testIds.createAccountFirstName)).locator('input').fill('Stella')
    await page.locator(dt(testIds.createAccountLastName)).locator('input').fill('Stranger')
    await page.locator(dt(testIds.createAccountPassword)).locator('input').fill(password)
    await page
      .locator(dt(testIds.createAccountPasswordConfirmation))
      .locator('input')
      .fill(password)
    await page.locator(dt(testIds.createAccountSubmit)).click()

    // One submit = account created + membership activated + token
    // consumed + session established, all in the same call — success
    // renders immediately, no second round trip.
    await expect(page.locator(dt(testIds.acceptInvitationSuccess))).toBeVisible({ timeout: 8000 })

    // The page self-redirects to the agency dashboard ~2s after success
    // (see AcceptInvitationPage.vue's `createAccountAndAccept`); the
    // invitee lands signed-in in the real workspace, not just a message.
    await expect(page.locator(dt(testIds.agencyLayout))).toBeVisible({ timeout: 8000 })
  })

  test('AH-095: unauthenticated invitee whose account already exists still sees the sign-in prompt (byte-identical existing-account path)', async ({
    page,
  }) => {
    const request = page.context().request

    const inviteeEmail = `invitee-existing-${Date.now()}@catalyst-test.dev`
    const inviteePassword = 'Cata1yst-E2E-Invitee!'

    // The account is created FIRST — the invitation is then seeded for
    // the SAME email, so the preview's `account_exists` reads true.
    await signUpUser(request, inviteeEmail, inviteePassword, 'Existing Invitee')

    const { acceptUrl } = await seedAgencyInvitation(request, agencyUlid, {
      email: inviteeEmail,
      role: 'agency_manager',
    })

    // Navigate to accept URL without signing in.
    await page.goto(acceptUrl)

    // Should show the (unchanged) unauthenticated state with sign-in CTA
    // — NOT the set-password form, which is reserved for the no-account
    // branch.
    await expect(page.locator(dt(testIds.acceptInvitationUnauthenticated))).toBeVisible({
      timeout: 8000,
    })
    await expect(page.locator(dt(testIds.acceptSignInBtn))).toBeVisible()
    await expect(page.locator(dt(testIds.acceptInvitationCreateAccount))).not.toBeVisible()

    // Sign-in button should carry the redirect parameter.
    const signInHref = await page.locator(dt(testIds.acceptSignInBtn)).getAttribute('href')
    expect(signInHref).toContain('/sign-in')
    expect(signInHref).toContain('redirect=')
  })

  test('AH-095: the collision race — an account created for the invited email between page-load and submit re-branches to sign-in', async ({
    page,
  }) => {
    const request = page.context().request

    const inviteeEmail = `invitee-race-${Date.now()}@catalyst-test.dev`
    const { acceptUrl } = await seedAgencyInvitation(request, agencyUlid, {
      email: inviteeEmail,
      role: 'agency_manager',
    })

    // Load the accept page BEFORE the account exists — the set-password
    // form renders, exactly like the happy-path leg above.
    await page.goto(acceptUrl)
    await expect(page.locator(dt(testIds.acceptInvitationCreateAccount))).toBeVisible({
      timeout: 8000,
    })

    // Now simulate the race: an account is created for the SAME email
    // via a completely separate channel (production sign-up) while the
    // stale form is still on screen.
    await signUpUser(request, inviteeEmail, 'Cata1yst-E2E-Racer!', 'Race Condition')

    // Submit the (now-stale) form. The backend's layer-1 pre-check
    // catches this and returns 409 `invitation.account_exists`; the SPA
    // re-branches to the sign-in state rather than a dead-end error.
    const password = 'Correct-Horse-Battery-Staple-42'
    await page.locator(dt(testIds.createAccountFirstName)).locator('input').fill('Stella')
    await page.locator(dt(testIds.createAccountLastName)).locator('input').fill('Stranger')
    await page.locator(dt(testIds.createAccountPassword)).locator('input').fill(password)
    await page
      .locator(dt(testIds.createAccountPasswordConfirmation))
      .locator('input')
      .fill(password)
    await page.locator(dt(testIds.createAccountSubmit)).click()

    await expect(page.locator(dt(testIds.acceptInvitationUnauthenticated))).toBeVisible({
      timeout: 8000,
    })
    await expect(page.locator(dt(testIds.acceptInvitationCreateAccount))).not.toBeVisible()
  })

  test('authenticated invitee can accept invitation and land in workspace', async ({ page }) => {
    const request = page.context().request

    const inviteeEmail = `invitee-${Date.now()}@catalyst-test.dev`
    const inviteePassword = 'Cata1yst-E2E-Invitee!'

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

    // Wait for auth to SETTLE, not for a second to pass: a fixed sleep here
    // raced the sign-in round trip under suite load and navigated to the accept
    // URL still unauthenticated, where the page correctly renders its
    // "sign in to accept" branch and the pending card never appears (AH-058).
    await expect(page).not.toHaveURL(/\/sign-in/, { timeout: 10_000 })

    // Navigate to accept URL while authenticated.
    await page.goto(acceptUrl)

    // Should show pending state with accept button.
    await expect(page.locator(dt(testIds.acceptInvitationPending))).toBeVisible({ timeout: 8000 })
    await expect(page.locator(dt(testIds.acceptInvitationBtn))).toBeVisible()
    // Description is i18n-translated, so role renders as the user-facing label
    // ("Manager") not the raw enum value ("agency_manager").
    await expect(page.locator(dt(testIds.acceptInvitationDescription))).toContainText('Manager')

    // Accept the invitation.
    await page.locator(dt(testIds.acceptInvitationBtn)).click()

    // Success state should show.
    await expect(page.locator(dt(testIds.acceptInvitationSuccess))).toBeVisible({ timeout: 8000 })
  })

  test('AH-086b: the /agency/sign-in door forwards to the SAME accept flow, preserving token+agency', async ({
    page,
  }) => {
    const request = page.context().request

    const inviteeEmail = `invitee-door-${Date.now()}@catalyst-test.dev`
    const inviteePassword = 'Cata1yst-E2E-Invitee!'
    await signUpUser(request, inviteeEmail, inviteePassword, 'Door Invitee')

    const { token, agencyUlid: invitedAgencyUlid } = await seedAgencyInvitation(
      request,
      agencyUlid,
      {
        email: inviteeEmail,
        role: 'agency_staff',
      },
    )

    // This is the exact shape `AgencyInvitationService::invite()` now
    // mints for the team-invite email's CTA — the door this chunk adds.
    const doorUrl = `/agency/sign-in?token=${token}&agency=${invitedAgencyUlid}`

    // Sign in first, then follow the door while authenticated — proves
    // the forward preserves both params through to the SAME accept
    // page/flow, and that acceptance itself still completes exactly as
    // it did before this chunk (byte-identical downstream of the door).
    await page.goto('/sign-in')
    await page.locator(dt(testIds.signInEmail)).locator('input').fill(inviteeEmail)
    await page.locator(dt(testIds.signInPassword)).locator('input').fill(inviteePassword)
    await page.locator(dt(testIds.signInSubmit)).click()
    await expect(page).not.toHaveURL(/\/sign-in/, { timeout: 10_000 })

    await page.goto(doorUrl)

    // The forward lands on the SAME accept-invitation page, carrying the
    // SAME token + agency — never rendering the sign-in form for this hop.
    await expect(page).toHaveURL(/\/accept-invitation\?/, { timeout: 8000 })
    expect(page.url()).toContain(`token=${token}`)
    expect(page.url()).toContain(`agency=${invitedAgencyUlid}`)
    await expect(page.locator(dt(testIds.acceptInvitationPending))).toBeVisible({ timeout: 8000 })

    await page.locator(dt(testIds.acceptInvitationBtn)).click()
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
