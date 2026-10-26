# AH-104d — In-app creator sign-up — INVENTORY (read-only)

- **Status:** **ACCEPTED 2026-10-26** by Pedram; rulings Q1 to Q10 are recorded at the end (§8). Read-only inventory: no code, no plan, no edits were made to produce it.
- **Written:** 2026-10-26 at `HEAD` `ca969f90` (`docs(erasure): AH-104c C3 walk record and close-out`); tree clean, `origin/main` equals `HEAD`.
- **Chunk:** AH-104d, in-app sign-up (creator only; formerly AH-104b, the older entries and closed reviews keep the old name, read it as AH-104d). Gated on AH-104
  deletion, which is built. Context: production is deployed through AH-103; AH-106/107/108/103b/103d/109/104a/104b-del/104c are pushed, not deployed
  (one combined PENDING deploy-log entry); the deploy and TestFlight build 3 come after AH-105.
- **Limits:** the Apple and Play rules (5.1.1(v), 4.8) are quoted from memory here, as in the AH-104 inventory, and are marked as such. I did not run sign-up
  from a device. Production flag values cannot be read from the repo.

## 1. Web sign-up today, and what the native shell does instead

**Web flow**

- Route `/sign-up` (`auth.sign-up`) is guest-only (`apps/main/src/modules/auth/routes.ts:106-110`).
- `SignUpPage.vue` has five fields: first name, last name, email, password and confirmation. It also sends `preferred_language` from the active locale and an
  auto-detected browser `timezone` (`SignUpPage.vue:92-112`).
- On success it goes to `auth.verify-email.pending?email=…` (`:125-128`).
- An optional `?token=` invitation path exists (`:76-82`, `:113-124`). It is reached only from the web `/auth/accept-invite` link.
- The page has **no `isNative` fork** and no terms or privacy consent control.
- `auth.sign-up` is not a hero route (`AuthLayout.vue:38`). In the app it renders as the plain web card, not the Figma sheet. Sign-in gets the sheet because
  `usesNativeSheet(isHero)` requires `isHero` (`layouts/nativeSheet.ts:13-14`).

**Backend**

- `POST /api/v1/auth/sign-up` sits in the `auth` group with `throttle:auth-ip`, 10 per minute per IP (`Identity/Routes/api.php:87-90`, `:116-117`;
  `IdentityServiceProvider.php:119`).
- It is creator-only by construction (`SignUpService.php:117`, `UserType::Creator`).
- It never authenticates the user: no cookie, no token (`SignUpService.php:40-46`, `SignUpController.php:13-21`).
- It queues the verification mail (`SignUpService.php:140-148`, `:271-290`).

**What native does instead (the Q8 / AH-100 decision)**

- The app shows no sign-up link. Instead it shows the pointer line `auth.ui.native.create_account`: "New to Catalyst? Create your account at {site}, then sign in
  here." The site is the hard-coded `platform.catalyst-growth.com` (`SignInPage.vue:138`, `:531-546`).
- Q8 recorded this as coupled to in-app deletion, with the exit "in-app sign-up is AH-104b, gated on AH-104's deletion"
  (`docs/reviews/creator-app-client-shell-review.md:142-145`). That is the AH-104d now being built.
- The AH-101 Q7 comment says the Figma frame-02 "Create an account" button was left undrawn and "returns with real in-app sign-up (AH-104d)"
  (`SignInPage.vue:531-535`).
- The hero panel drops its sign-up pitch on native (`AuthHeroPanel.vue:14-17`). The web "Create account" link is already `v-if="!isNative"`
  (`SignInPage.vue:516-523`).

**Transport on native**

- Native uses bearer mode: no cookies and no CSRF preflight (`packages/api-client/src/http.ts:27-29`, `:159-193`).
- The store's `signUp` (`useAuthStore.ts:488-497`) goes through the same `authApi` that `tokenLogin` uses. Sign-up would send no `Authorization` header because
  there is no token yet.
- CORS and stateful-origin handling are already proven for `/auth/token`. The native origins are CORS-only and must never be in `SANCTUM_STATEFUL_DOMAINS`
  (`apps/api/.env.example:62-77`).
- Not seen: this endpoint hit from the native origin on a device.

## 2. Email verification on a phone

- **The mail link** is `{frontend_main_url}/auth/verify-email?token=…`, valid 24 hours (`SignUpService.php:327-330`, `EmailVerificationToken.php:33`).
- **The link opens in the browser, not the app.** The AASA file scopes universal links to `/creator/*` only
  (`apps/main/public/.well-known/apple-app-site-association`). The AH-103b plan lists `/auth/*` (verify-email included) as out of scope and says they "stay web
  flows" (`docs/reviews/universal-links-plan.md:31`, `:115`). The deep-links inventory recommended excluding emailed-token paths "until the app has a…" handler
  (`deep-links-inventory.md:469`).
- **Android:** there is no `assetlinks.json` by ruling, so the link opens in the browser there regardless.
- **After verification.** `EmailVerificationConfirmPage.vue:23-41` calls the API and shows "verified". The only exit is "Back to sign in", a router link to the
  web sign-in (`:73-77`). Verification does not sign anyone in (`EmailVerificationService.php:35-72`). The user must switch back to the app by hand.
- **The pending page in the app.** It has a resend button (limit one per minute per email, `IdentityServiceProvider.php:145-151`) and a "Back to sign in" link
  (`EmailVerificationPendingPage.vue:97-101`). It has no "I've verified" action and no polling. It is also unstyled for native.
- **Precedent.** Password reset already works this way: the mail opens a web page, then the user returns to the app. No native-aware copy on the reset page.
- **Unverified sign-in on native.** An unverified creator who signs in is sent to the pending page (`SignInPage.vue:241-262`). Without that, the wizard bootstrap
  would 403.

## 3. The onboarding wizard on native

- **Visible steps:** profile, connections (social and portfolio merged), contract, review. KYC, tax and payout are build-time hidden (`WizardStep.php:69`). Their
  pages exist and use `window.location.href` vendor redirects (`Step5KycPage.vue:75`, `Step7PayoutPage.vue:67`), but are not reachable while hidden.
- **Contract:** with `contract_signing_enabled` off, the step falls back to a click-through (`docs/feature-flags.md:45`). The vendor redirect at
  `Step8ContractPage.vue:96-101` is the navigate-away risk if that flag is ever turned on.
- **Native chrome:** a Figma-based header with an inline "Save and exit" (`OnboardingLayout.vue:257-264`); no menu and **no locale switcher** on native
  (`:266-277`; AH-101 Q10 "locale switching is lost mid-wizard"). `WelcomeBackPage` has a first-time branch (`:183`, `:260-262`). Social connect has a native spec
  (`ConnectionsSocialSection.native.spec.ts`).
- **Not done:** a brand-new account was not walked through the wizard on a device. The native wizard evidence is from existing creators.
- **Post-submit:** a new account lands `incomplete`, then submitted, then waits for admin approval. App Review needs the review demo account that M6.5 already lists
  (`creator-app-inventory.md:520-527`).

## 4. AH-104b-del's sign-up wording

- **Shipped copy:** `auth.signup.email_taken` (`apps/api/lang/en/auth.php:61`) is one static string for every taken address: "…Sign in to use it, or reset your
  password. If you asked for this account to be deleted, signing in lets you restore it."
- **Pinned by tests** (`AccountDeletionSignUpTest.php`): a pending-deletion address and an ordinary taken address give a byte-identical 422 (`:50-72`); the string
  has no state in it and exists in all 24 locales (`:92-107`).
- **Decision:** reuse the same 422 rather than the leaky "scheduled for deletion" wording; after an erasure the address is freed and sign-up succeeds
  (`account-deletion-request-plan.md:220-231`).
- **Native impact:** the field error renders through `extractFieldErrors` on the email field (`SignUpPage.vue:133-139`), so it works unchanged in the app. Nothing
  needs editing.
- **Edge case:** a bulk-invited prospect already has a pre-created user row. If they download the app and sign up without the token, they get the same "already
  exists… reset your password" answer. Invitation sign-up needs `?token=`, which has no way into the app.

## 5. Apple 5.1.1(v), 4.8, privacy

**5.1.1(v), account creation requires in-app deletion (from memory)**

- **Built:** the deletion route is `POST /me/account-deletion` (`Identity/Routes/api.php:78-81`). The Security page is at `/creator/security`
  (`creators/routes.ts:131-141`). It has a native More entry (`CreatorDashboardLayout.vue:458-465`) and a public `/delete-account` page (`auth/routes.ts:152-158`).
- **Gap 1, the flag:** deletion is OFF by default (`AccountDeletionEnabled.php:28-30`). If sign-up ships while it is off, there is account creation without in-app
  deletion. Pedram turns it on before build 3 (`deploy-log.md:97`).
- **Gap 2, the entry point:** the Security entry appears only in `CreatorDashboardLayout` (`:350`, `:461`). `OnboardingLayout` has none, so a newly created
  account in the wizard has no visible way to delete itself. A reviewer who creates an account would stop exactly there.
- **The server side is fine:** an unverified creator can already request deletion (`account-deletion-request-inventory.md:98`), and the route guard only checks the
  flag (`guards.ts:340-347`). Only the navigation entry is missing.

**4.8, Sign in with Apple (from memory)**

- No third-party login exists. The Instagram, TikTok and YouTube OAuth flags are account connect, not login (`docs/feature-flags.md:40-42`;
  `creator-app-inventory.md:526-527`). A code search found no Socialite or SSO. 4.8 is not triggered.

**Privacy and consent**

- The sign-up form has no terms or privacy link or checkbox. The marketing footer, the only creator-facing privacy link found, is hidden on native
  (`layouts/marketingTail.ts:16-20`). No in-app privacy link in the creator surfaces.
- Whether Apple needs a link at sign-up is for AH-105 and Pedram's call; not verified.
- **Meta Pixel:** loads on the web sign-in page only and never on native (`SignInPage.vue:185-193`).

## 6. i18n ×24 impact

- **Keys:** the existing web keys already exist in all 24 locales. A native sign-up screen needs new native-only keys.
- **Retiring:** the `auth.ui.native.create_account` pointer key (`auth.json:200`) would be retired. Other references not checked at inventory time.
- **Pre-existing English in the flaky 10** (bg, el, et, fi, ga, hu, lt, lv, mt, ro): six strings used by the post-sign-up screens equal the English text (values
  compared in `auth.json`): `verify_email_pending` (heading and description), `verification_email_sent`, `resend_verification`, `verify_email_confirm`,
  `email_verified`; the invitation banner `completing_invitation` is also English. `el`, `mt` and `ro` also match on a few short words that may be genuine cognates.
- **The mail text is fine:** the API verification-mail and `email_taken` strings differ from English in all 10.
- (Plan-time note, not a correction to this inventory: the full English-identical set in `auth.json` is larger than these; see the plan, P8.)

## 7. Web-shared vs native-only

**Shared and unchanged:** the backend endpoint, request, service, mail and rate limit; `SignUpPage` logic, the api-client `signUp`, the store action and the verify
pages; the `email_taken` wording and the 24 locales' existing keys.

**Native-only, would be new:** a sign-up entry on sign-in (the Q7 button); a native presentation of the sign-up page (it is not hero); native styling and handling of
the pending and confirm pages; new native copy ×24; a deletion entry reachable from the wizard; retiring the pointer line.

**Not in scope as assumed:** the invitation `?token=` path stays web-only.

**Tests likely touched:** `SignUpPage.spec.ts` and the `SignInPage` Q7 and pointer specs; 18 Playwright specs use the sign-up helper; the `/creator/security` guard
spec if the entry moves.

## 8. Open questions as asked, and the rulings (2026-10-26)

| Q   | Question                         | Ruling                                                                                                                                                                                    |
| --- | -------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Q1  | Deletion flag at sign-up         | Covered by the build-3 "flag ON before submission" step; no new unauthenticated signal                                                                                                    |
| Q2  | Deletion access for new accounts | In 104d: the account menu (with the Security entry) is available in `OnboardingLayout`, so a new account can reach deletion                                                               |
| Q3  | Verification handoff             | (a) + (c): the confirm page gets a native-aware "Verified — return to the Catalyst app and sign in" line; the in-app pending page gets an "I've verified, sign in" button. No AASA change |
| Q4  | Sign-up screen design            | Native sheet like sign-in (make `auth.sign-up` a hero route); follow sign-in's visual style                                                                                               |
| Q5  | Invited creators                 | Stay web-only; tech-debt row                                                                                                                                                              |
| Q6  | Consent and privacy              | Yes: a consent line under the form with Terms and Privacy links (external URLs, system browser on native), ×24                                                                            |
| Q7  | Existing English strings         | Fix the six flaky-10 English strings in this chunk (real MT)                                                                                                                              |
| Q8  | Production flags                 | `contract_signing_enabled` OFF (click-through); social OAuth all mock/OFF                                                                                                                 |
| Q9  | Rate limit                       | Add a per-email sign-up limiter, 3/hour, 429 only, next to `auth-ip`; add to the neutralizer allow-list                                                                                   |
| Q10 | Password autofill                | `webcredentials` out of scope; tech-debt row                                                                                                                                              |
| —   | The pointer line                 | Retire `auth.ui.native.create_account`                                                                                                                                                    |
