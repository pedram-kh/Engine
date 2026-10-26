# AH-104d — In-app creator sign-up — PLAN (plan-pause)

- **Status:** **CLEARED 2026-10-26 with the amendments in §0, which supersede the body where they differ.** (Originally written as a plan-pause.) Written at `HEAD` `ca969f90` after Pedram accepted the inventory
  ([`in-app-signup-inventory.md`](in-app-signup-inventory.md)) and ruled Q1 to Q10 (§1). Mode A (full loop): a new rate limiter on a live endpoint, new and changed
  i18n keys, a route-presentation change, a security-relevant gate.
- **PROD-DATA RISK: NONE.** No migration, no schema, no backfill, no row modified or deleted. Sign-up writes exactly what it writes today (one `users` row, one
  `creators` row, one queued mail). The only new stored thing is a rate-limiter counter in the cache (key holds a hash of the email, not the email).
  **Availability note (not data):** the limiter sits on a live endpoint. A defect could answer real sign-ups, including web invitation acceptance, with 429. That is
  why the limiter has its own break-reverts and a scenario in the walk (§7).
- **Split:** 8 sub-steps (S1 to S8), each ending green on its own: Pest, Pint `--all`, PHPStan with **no path argument**, Vitest on `apps/main`, `vue-tsc`, ESLint,
  Prettier, locale parity. Scoped runs are for sub-step green; the full board runs before the push.
- **Standards applied:** `WORKING-PROCESS` §2 Mode A, §4 (break-revert with the run's exit code read, §5.34 negatives, real-rendering tests, 24-locale gate, **no
  English fallback in the flaky 10**), §5 (no schema, nothing flag-gated is un-gated), §6 (alarm rule). Local Pest needs
  `SANCTUM_STATEFUL_DOMAINS='localhost,localhost:5173,127.0.0.1,127.0.0.1:5173,127.0.0.1:5174'`. Global Pest helper names are prefixed. The auth module is held at
  100% branch coverage (`apps/main/vitest.config.ts`); every new branch needs both arms covered.

---

## 0. Clearance record and amendments (2026-10-26) — these win over the body

| #       | Ruling                                                                                                                                                                                                                                                                                                                                                                                                     |
| ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| C1–C6   | As proposed. C4: the sign-in page prefills the email from `?email=`.                                                                                                                                                                                                                                                                                                                                       |
| C7      | **The limiter is 5 per hour, not 3.** Every "3", "4th attempt", "first three" and "four attempts" below reads 5, 6th, first five, six attempts. Specs and W13 are written to 5.                                                                                                                                                                                                                            |
| C8      | The 27-key flow set F. The rest (about 48 keys) stays in the AH-001 i18n-completeness row.                                                                                                                                                                                                                                                                                                                 |
| C9      | **Privacy only for now.** The consent line links Privacy only; the Terms link is added when a page exists (tech-debt row). So: no `FOOTER_TERMS_HREF`, no `auth.ui.consent.terms` key, the sentence has one `{privacy}` slot, and the new-key count is 7, not 8 (§5). W3 taps Privacy only. The wording is a notice that names the privacy policy; it does not claim agreement to terms that have no page. |
| C10     | As proposed; W3 decides.                                                                                                                                                                                                                                                                                                                                                                                   |
| Mailhog | The local mail catcher is Mailhog, UI at `localhost:8025`. For the phone walk the SMTP and the UI must be reachable on the LAN address, and `FRONTEND_MAIN_URL` must be the LAN address.                                                                                                                                                                                                                   |

Process: the user instructed commit-and-push of the inventory and this plan, then S1 to S8, then a stop at review. Two-commit pair for the build (feat + docs), push of the
build held until the review clears it.

## 1. Rulings recorded (2026-10-26) and what each becomes

| Ruling | As ruled                                                                                          | Becomes                                                                                    |
| ------ | ------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| Q1     | Covered by the build-3 "flag ON before submission" step; no new unauthenticated signal            | Nothing built. The deploy-log build-3 checklist line gains "sign-up ships in build 3" (S8) |
| Q2     | The account menu (with Security) is available in `OnboardingLayout`                               | S6 (P6, C6), and S5 for the unverified case (P5, C5)                                       |
| Q3     | (a) confirm-page line, (c) pending-page button; no AASA change                                    | S3 and S5; **the literal "native-aware" line cannot work where it is shown (P3, C3)**      |
| Q4     | Native sheet like sign-in, make `auth.sign-up` a hero route; follow sign-in's style               | S4; **"hero route" as worded would change the web page (P1, C1)**                          |
| Q5     | Invited creators stay web-only; tech-debt row                                                     | S8 row                                                                                     |
| Q6     | Consent line under the form with Terms and Privacy links, external, system browser on native, ×24 | S3 (P9: the Terms URL is not in the repo, C9; P10: native link behaviour is unproven)      |
| Q7     | Fix the six flaky-10 English strings, real MT                                                     | S2; **the English set is larger than six (P8, C8)**                                        |
| Q8     | Production: `contract_signing_enabled` OFF, social OAuth all mock/OFF                             | Recorded as the wizard's production shape for the walk and the review; no code             |
| Q9     | Per-email sign-up limiter, 3/hour, 429 only, next to `auth-ip`; add to the neutralizer allow-list | S1 (P7, C7)                                                                                |
| Q10    | `webcredentials` out of scope; tech-debt row                                                      | S8 row                                                                                     |
| Retire | `auth.ui.native.create_account` pointer                                                           | S4 (the key leaves all 24 locales; `CREATOR_SIGNUP_SITE` leaves `SignInPage.vue`)          |

## 2. What the plan found that the inventory did not

Each is settled here or is a confirmation in §9.

**P1. "Make `auth.sign-up` a hero route" changes the web page.** `isHero` is not only the native sheet's switch. In `AuthLayout.vue` it drives the hero headline
panel (`:77`), the marketing tail (brand wall, guide CTA, footer: `:39`, `marketingTail.ts:16-20`) and the `auth-layout--hero` class (`:51`). Adding `auth.sign-up` to
the `isHero` expression (`AuthLayout.vue:38`) would give the **web** sign-up the sign-in landing's hero and marketing tail. Intent (§5.32): the creator app's sign-up
looks like its sign-in; the web sign-up does not change. Reinterpretation: `isHero` stays as it is (the two sign-in routes). `layouts/nativeSheet.ts` gains a pure
`isSheetRoute(name)` (the two sign-in names, `auth.sign-up`, and, per C2, `auth.verify-email.pending`) and the layout's one line becomes
`usesNativeSheet(isSheetRoute(route.name))`. `AuthLayout.vue` is 247 lines against a 250 cap (`auth-layout-shape.spec.ts:87`): the change is one line plus one import
changed, no new function, no logic in the SFC. A spec pins that the web render of `auth.sign-up` is unchanged. **C1.**

**P2. The pending page is the next screen after the sign-up sheet.** If only sign-up is a sheet, the user goes from the sheet to a centred web card in one tap. I
recommend `auth.verify-email.pending` joins the sheet routes (it only takes effect on native, the same predicate). The confirm page is never in the app, so it is not.
Forgot-password stays a card (out of scope). **C2.**

**P3. The confirm page is opened by the phone's browser, not the app, so "native-aware" cannot be literal.** `isNativePlatform()` is false in Safari, so a branch on it
never fires on the page that needs the line (inventory §2: the link opens in the browser). A branch on a mobile user agent would also tell a mobile-web sign-up (no app)
to return to an app. Proposed: show the line to every visitor, worded so it is true for everyone: after the success alert, "Using the Catalyst app? Return to it and
sign in." (new key `auth.ui.banners.email_verified_app`). Desktop and web users read it as an aside. The existing "Back to sign in" link stays. The ruled sentence,
"Verified — return to the Catalyst app and sign in", is my second choice (it reads wrongly on a desktop). **C3.**

**P4. The pending page's button has two states, and one of them is a trap.** After sign-up the user is signed out. After an _unverified sign-in_ the user is signed in
(`SignInPage.vue:241-262` sends them to this page holding a token), and `requireGuest` sends a signed-in user to `app.dashboard` (`guards.ts:98-106`), where the agency
guard bounces a creator to the wizard, whose bootstrap 403s for an unverified creator. So one button cannot just `router.push('auth.sign-in')`:

- **Signed out:** go to `auth.sign-in` with `?email=` so the field is filled (C4).
- **Signed in:** call `auth.refreshSession()` (`useAuthStore.ts:290`, which the app already runs on every foreground, `appResume.ts:51-58`); if
  `email_verified_at` is now set, go to `onboarding.welcome-back`; if not, show "We could not see the verification yet" (new key `auth.ui.errors.not_verified_yet`)
  and stay. No polling, no auto-advance in this chunk (a one-line `watch` would do it; recorded as tech-debt, not built).

The button is native-only (ruled). The sign-in page reads `?email=` into the field only: trimmed, capped at 320, never trusted for anything else, never logged, and a
spec shows it cannot change the flow. **C4: prefill, yes or no.**

**P5. An unverified account has no way to Security, and the layout it would land in fails quietly for it.** The unverified user's only screen is the pending page (an
`AuthLayout` card). `/creator/security` opens for them by URL (the guard checks only the flag, `guards.ts:340-347`), but there is no entry, and
`CreatorDashboardLayout.vue:215-219` does `void onboardingStore.bootstrap()`, which rethrows the 403 for an unverified creator (`useOnboardingStore.ts:168-176`): an
unhandled rejection on every visit. Your walk line says deletion must work for an unverified account, so: the signed-in pending page shows a text link "Delete my
account" (existing key `creator.ui.security.delete.button`, no new copy) to `creator.security`, gated on `features.account_deletion` like the other entries, and the
layout's call gets a `.catch` that swallows the 403 (the page does not depend on `applicationStatus`). **C5.**

**P6. The wizard account menu re-adds what AH-101 U5 removed as "dead chrome".** U5 dropped the native hamburger because it held one thing (locale)
(`OnboardingLayout.vue:267-277`). A menu holding one thing (Security) would repeat that. Design, one component `OnboardingAccountMenu.vue` (avatar-initial button,
a menu with the account email and Security): shown in the desktop-web topbar and in the native header beside "Save and exit"; **renders nothing while the flag is off**
(so no empty menu); the web-mobile drawer gets a plain "Security" list item instead of a second menu. It does not carry locale, theme, notification settings or sign-out
(Save and exit already signs out, `OnboardingLayout.vue:201-205`). Security opens in the creator shell as it does today: the route is unchanged
(`creators/routes.ts:134-140`), the shell's back arrow returns through history (AH-106), and the creator tab bar shows on that page for an incomplete creator. That is
the existing layout and I am not changing it. Locale on native stays the AH-101 Q10 follow-up. **C6.**

**P7. What the 3/hour limiter protects, and what it costs.** It is registered next to `auth-ip` and keyed on the email, normalised (trim, lower-case) inside the limiter
because it runs before `SignUpRequest::prepareForValidation()`. Facts that shape it:

- Sign-up cannot be used to mail-bomb an address: the verification mail is queued only when a row is created (`SignUpService.php:140-148`), and a second sign-up for the
  same address fails the unique rule before any mail. What the limiter really limits is probing a specific address through the taken-or-not answer, and noise.
- A route throttle counts **every** request, including 422s. A person who is told "too short", then "breached", then fixes a typo has used the three. The 4th attempt
  returns 429 for up to an hour, on web and on the app, including a web invitee on the invitation path (the same endpoint). The ruling is "3/hour"; I build it as
  ruled and the walk shows the consequence (W13). The alternative (count only requests that pass the non-email rules) puts a hit inside a form request, a more
  fragile place for a security gate. **C7: keep 3 as ruled, or raise it.**
- **Empty and non-string email must not share one bucket.** `'verify:'.$email` with an empty email is one bucket for everyone in the existing resend limiter.
  Here an empty or non-string `email` falls back to a per-IP bucket (`signup-ip:`), never a shared empty key.
- 429 only: the shared `$rateLimitResponse` is reused (envelope `rate_limit.exceeded`, `meta.seconds`, `Retry-After`); it creates nothing, suspends nothing, writes no
  failed-login row. A taken address and a free one count identically, so the 429 itself leaks nothing.
- The cache key carries `sha256(normalised email)`, not the address (the existing limiters put raw emails in cache keys; I do not copy that).

**P8. The English left in the flaky 10 is much larger than six strings, and the 429 you just ruled lands in it.** A key-by-key comparison of `auth.json` against `en`
(values equal, longer than 12 characters, done for this plan) finds **61 keys English in all ten of bg, el, et, fi, ga, hu, lt, lv, mt, ro and 75 in at least one**.
That includes strings the new flow renders: `rate_limit.exceeded` (the sign-up 429), `auth.password.breached`, `too_short`, `too_long`, every `auth.ui.errors.*`, the
verification errors, `invalid_credentials` and the account-locked pair. Fixing six strings would leave a Hungarian user who mistypes a password reading English. Proposal:
**the flow set F, 27 keys**, derived by script in S2 from what sign-up, verify, resend, the new screens and the sign-in right after can render (my pre-read:
7 screens, 4 sign-up errors, 3 verification errors, 7 `auth.ui.errors.*`, 6 sign-in errors; the script, not this list, is the source). F ×10 locales is 270 values.
The other ~48 English keys (2FA, forgot and reset password, the hero footnote, the agency invitation pages) stay in the AH-001 i18n-completeness row with their `rg`
signature. **C8: the six, F (recommended), or all 75.** `el`, `mt` and `ro` also match English on the short words "email" and "password"; those are judged one by one
(`el` Greek speakers do write "Email"), not auto-changed.

**P9. The Terms URL is not in the repo, and the consent line is not a legal record.** The privacy URL is `https://www.catalyst-growth.com/legal/privacy`
(`footerLinks.ts:75`, `FOOTER_PRIVACY_HREF`). The footer's comment says it is "hosted on the marketing site alongside the terms", but no terms URL exists anywhere
(searched). I will not guess `/legal/terms`: App Review taps the link. **C9: the exact Terms URL, and that it is live.** The line is a notice ("By creating an account
you agree to…"), not a checkbox, and nothing records acceptance (no schema in this chunk; the wizard's click-through already stores the master-agreement acceptance).
Engineering review is not legal review; whether a notice without an acknowledgement is enough is yours to take.

**P10. External links from inside the app are proven nowhere in the repo.** The only precedents are creator pages with `target="_blank"` (job detail `:297,309`, assignments, attachments), with no walk record I could find. Capacitor is expected to hand a `_blank` link to the system browser (from memory), and
`capacitor.config.ts` sets no navigation allow-list. If the web view navigates in place instead, the user is stranded on a terms page with no back control. The walk
tests it (W3). The fallback would be `@capacitor/browser`, which opens an in-app Safari view (not the system browser you ruled), needs a new native dependency, a
`cap sync ios` (never a bare `npx cap sync`, R-7) and a new build. **Not built unless W3 fails; C10.**

**P11. The pointer's replacement.** The pointer block (`SignInPage.vue:532-546`, `signInNative.css:126-137`) is one `<p>`: "New to Catalyst? Create your account at
{site}, then sign in here." (`auth.ui.native.create_account`, en line 200), under a top border that already is frame 02's divider. It becomes the same divider, the line
"New to Catalyst?" (new key `auth.ui.native.new_to_catalyst`) and a secondary `Create account` button to `auth.sign-up`, label from the existing
`auth.ui.actions.sign_up`. The AH-101 Q7 comment at `:532-535` says the button "returns with real in-app sign-up (AH-104d)": this is that. I do not have frame 02's node
id, so the label is the existing "Create account", and the button follows sign-in's button radius (`native-button-radius.spec.ts`). The block keeps its
`v-if="isNative && pending === null"` (absent while the step-up and wrong-door states show). `auth.ui.native.create_account` leaves all 24 locales (parity needs matching
keysets, so the same commit); `CREATOR_SIGNUP_SITE` (`:138`) and `.sign-in-native__site` go with it; and the specs that pin the pointer change with it:
`SignInPage.native.spec.ts` `:123-131`, `:313-319` (the site span), `:407-413` and `:509` (absence on web and in the other states), `:527-541` ("Not now" restores the
pointer). The `data-test` id stays so the absence assertions keep their meaning.

**P12. Transport is proven only for the token door.** S1's Pest tests prove the limiter and the endpoint; they cannot prove that the native origins reach
`/auth/sign-up` (CORS allow-list, no CSRF on a non-stateful origin). It is the same configuration as `/auth/token`, but the first real call is W5.

## 3. The design in one page

**API (S1).** `RateLimiter::for('auth-signup-email', …)` in `IdentityServiceProvider::registerRateLimits()`, `Limit::perHour(3)`, keyed on `signup:` + sha256 of the
normalised email (per-IP fallback for an empty or non-string email), response through the shared `$rateLimitResponse`. Route: `throttle:auth-signup-email` added to the
`auth/sign-up` route, after the group's `throttle:auth-ip`. `RateLimiterNeutralizer::ALLOWED_NAMES` gains the name; both Playwright `test-helpers.ts` unions
(`apps/main/playwright/fixtures/test-helpers.ts:259`, `apps/admin/playwright/fixtures/test-helpers.ts:238`) gain it; the doc comments that count limiters
(`RateLimiterNeutralizer.php:38` "the four named", and the two `test-helpers.ts` headers at `:249` and `:228` "four production rate limiters"; the list already has five
names, so the count is stale today) are corrected, and `TestHelpersServiceProvider.php:92-93`, which lists the registrations it relies on, gets the new one. No new route, no controller change, no request change, no response shape
change, no new error code.

**Layout and sheet (S4).** `nativeSheet.ts`: `isSheetRoute(name)`; `AuthLayout.vue`: the one-line change (P1). `SignUpPage.vue` gets an `isNative` fork in the style of
`SignInPage.vue`: the mark (compact variant, to save height with five fields), title and one-sentence subtitle (new key `auth.ui.native.sign_up_subtitle`), labels
above the fields, the field icons, the `--native-*` tokens only. Styling lives in its own `signUpNative.css`, bound only through a `.sign-up-native` class (the
`native-css-scoping` and `native-page-css-scoping` specs apply). The web markup is unchanged apart from the consent line. The shared sheet rules in
`signInNative.css` are not edited: the sign-up sheet repeats the few it needs rather than coupling two pages through one file. Form behaviour is unchanged: same
fields, same payload, same `extractFieldErrors` path, same `autocomplete` values.

**Consent line (S3).** Below the form, above "back to sign in": an `<i18n-t>` sentence with two slots, each an anchor from `footerLinkAttrs()`
(`target="_blank"`, `rel="noopener noreferrer"`): `auth.ui.consent.line` ("By creating an account you agree to the {terms} and the {privacy}."),
`auth.ui.consent.terms`, `auth.ui.consent.privacy`. The two link labels are separate keys, not the footer's, because a label inside a sentence needs the case of that
sentence in the inflecting languages (Finnish, Hungarian, Baltic, Slavic). `FOOTER_TERMS_HREF` is added beside `FOOTER_PRIVACY_HREF` in `footerLinks.ts`, the one place
for both URLs. On web it renders in the web card too (one component, no fork).

**Verification screens (S3, S5).** Confirm page: the line from P3. Pending page, native only: the primary button of P4 (replacing the "Back to sign in" link on native,
which the button supersedes), the resend button stays; signed in and unverified, the delete link of P5. The pending page joins the sheet routes (C2).

**Wizard account menu (S6).** `OnboardingAccountMenu.vue` (P6), mounted from `OnboardingLayout.vue` in the desktop topbar and the native header, plus the drawer list
item. It reads `user.attributes.features.account_deletion` and nothing else.

## 4. Sub-steps

Each ends green on its own. After every sub-step: Pint `--all`, PHPStan with no path argument (S1 only), Vitest for the touched files, `vue-tsc`, ESLint, Prettier.

**S1 — the limiter (API).** Register, route, allow-list, doc comments, `docs/04-API-DESIGN.md` §13 and `docs/05-SECURITY-COMPLIANCE.md` limiter tables.
_Specs (new, `SignUpRateLimitTest.php`):_ 3 attempts pass and the 4th is 429 with `rate_limit.exceeded`, `meta.seconds` and `Retry-After`; **disjoint negatives
(§5.34), each eligible in every respect except one:** another email is unaffected; case and whitespace variants (` A@B.TEST`) share one bucket; a 422 (weak password) counts
(pinned as the ruled behaviour); the 4th attempt creates no `users` row, queues no mail, writes no failed-login or audit row, suspends nothing; a taken and a free address
both 429 identically; an empty email and a non-string (`email[]=x`) email never join a shared bucket; the invitation path (`invitation_token` set) is limited the same way;
`auth-ip` still applies first (the 11th request in a minute from one IP is 429 on another email); the limiter exists and is in `ALLOWED_NAMES` (the
`AccountDeletionRequestTest.php:490` shape); the route's middleware list contains it. Existing sign-up specs that post one address more than three times get the explicit
`RateLimiter::for('auth-signup-email', Limit::none())` in their `beforeEach`, as the suite already does for `auth-ip` (the list comes from running the full file set, not
from this plan). `AccountDeletionSignUpTest.php` must pass unedited (its three requests use two distinct normalised addresses).
_Break-reverts:_ remove the route middleware; key on the raw (un-normalised) email; key on IP instead of email; drop the empty-email fallback; 3 to 4; per-minute for per-hour;
respond 403 instead of 429; let a 429 reach `SignUpService`. Each must fail the right spec; restore; `git diff` clean; re-green.

**S2 — the English in the flaky 10 (i18n only, no UI).** Derive F by script from the used keys (`i18n-used-keys-resolve` machinery) intersected with the English-identical
set in the flaky 10; retranslate each key in each of the ten locales (real MT, never "match the surrounding English"); record the list, the count and the method in the review
file. _Specs:_ a new `i18n-signup-flow-strings.spec.ts` with an **explicit key list** asserting every F key differs from `en` in each of the ten, keeps its placeholders
(parity already does) and is non-empty. `el`, `mt` and `ro` short-word cases are decided and listed with the reason, not changed by the script. _Break-revert:_ put English
back in one key of one locale; the new spec fails by name. There is no machine-translation tool in the repo (`scripts/i18n/` only verifies); the translations are
produced in the session, so **Irish and Maltese are the least certain and no native speaker has read any of them**, the standing AH-001 caveat, said in the review file.
Gate: `node scripts/i18n/verify-locale.mjs <locale>` for the ten, parity spec, placeholders.

**S3 — the shared pages (consent line, confirm line).** `FOOTER_TERMS_HREF` (value from C9), the consent line on `SignUpPage.vue`, the confirm-page line (C3). New keys ×24
(§5). _Specs:_ the line renders, both anchors carry the exact URLs, `target="_blank"`, `rel="noopener noreferrer"`, and are outside the router (no `router-link`); the
web sign-up render is otherwise unchanged (snapshot of the form's field set and order); the confirm line shows on success only, not on the error and loading states; the
24 locales render the line with both slots filled (no `{terms}` left in any rendered string). _Break-reverts:_ drop `rel`; a router-link instead of an anchor; swap the two
URLs; show the confirm line on the error state; leave a slot unfilled. The `2fa-enrollment-and-sign-in.spec.ts` Playwright spec drives the web sign-up form: it must pass
untouched.

**S4 — the native sheet and the sign-in entry.** P1, P11 and the `SignUpPage` native fork. Removes `auth.ui.native.create_account` ×24 and `CREATOR_SIGNUP_SITE`; adds
`auth.ui.native.sign_up_subtitle` and `auth.ui.native.new_to_catalyst` ×24. _Specs:_ `isSheetRoute` (every name in, every other name out, including `auth.forgot-password`,
`auth.reset-password`, the confirm page); `AuthLayout.native.spec.ts` gains `auth.sign-up` and the pending page as sheets; `AuthLayout.spec.ts` (web) proves `auth.sign-up`
is **not** hero and renders no hero panel and no marketing tail; the sign-in native spec replaces the pointer test with: the button exists, goes to `auth.sign-up`, the
pointer and site string do not exist; web sign-in unchanged (the web link still goes to sign-up). `SignUpPage.native.spec.ts`: the sheet, labels above fields, same payload as
web (a parity test feeds one input to both shells and asserts the same request body), no Meta Pixel, a field error and the static `email_taken` message render in the sheet,
the 429 renders. Arch: the new CSS is scoped to `.sign-up-native` and uses tokens only; `auth-layout-shape` still passes. _Break-reverts:_ remove `auth.sign-up` from the
sheet set (native spec red, web unaffected); add it to `isHero` (the web spec goes red; this is the P1 proof); a hard-coded colour in the CSS; the sign-in button to the wrong
route; restore the pointer.

**S5 — the pending page.** The native primary button (P4), the signed-in branch with `refreshSession`, the delete link (P5), the sign-in `?email=` prefill (C4), the
`.catch` on the layout bootstrap. New keys `auth.ui.actions.verified_sign_in`, `auth.ui.errors.not_verified_yet` ×24. _Specs:_ signed out, button goes to sign-in with the
email in the query; signed in and now verified, goes to `onboarding.welcome-back`; signed in and still unverified, shows the message and stays; the button and the delete link
do not exist on web; the delete link exists only signed in and only with `features.account_deletion === true`; the prefill trims, caps at 320 and ignores an array, and the
field remains editable and the submit payload is what the user sees; the layout swallows a 403 from `bootstrap` (an unhandled rejection fails the run: the harness reads
the exit code). _Break-reverts:_ the signed-in branch removed (the `requireGuest` loop returns); the verified check inverted; the delete link ungated; the prefill trusts an
array; the `.catch` removed.

**S6 — the wizard account menu.** `OnboardingAccountMenu.vue`, the layout mounts, the drawer item. No new keys (reuses `creator.ui.security.title` and `app.nav.menu`).
_Specs:_ flag on: the menu shows on desktop web and native and opens to the account email and Security, which navigates to `creator.security`; flag off: nothing renders on any
of the three (not an empty button); the drawer item shows on web-mobile only; Save and exit still signs out; nothing else moved in the native header. _Break-reverts:_ ungate
the menu; point it at the wrong route; show it on the Welcome Back screen when the creator is null (the existing `creator !== null` header condition); remove it from native. Full
Playwright runs here and at the end: `OnboardingLayout` is an E2E-traversed surface (`creator-wizard-happy-path.spec.ts`).

**S7 — end to end, web.** One Playwright spec, `creator-signup-consent.spec.ts` (dev stack down, own E2E DB, output into `playwright-report/`, never `/tmp`): the web sign-up
form shows the consent line with both links, signs up, reaches the pending page; the confirm page (token minted through the `_test` helper, as the existing specs do) shows
the line; the wizard topbar shows the account menu and Security opens (flag armed through the test helper, restored after). The three limiters the spec touches are neutralised
and restored in `afterEach`. _Break-revert:_ remove the consent line; the spec fails.

**S8 — docs and close.** AH-104d ad-hoc entry (Why, What, Touched, commits); this plan and the review file; deploy-log: **stack onto the open `AH-106 + … — PENDING` entry**,
with the limiter as an API-side line (PHP-FPM reload, no migration, no flag, no command) and the web build; the build-3 checklist line (Q1); tech-debt rows (Q5 invited
creators, Q10 `webcredentials`, "consent is a notice and is not recorded", "no bot control on sign-up beyond the two limiters", "pending page does not auto-advance on
resume", "locale row on native wizard (AH-101 Q10) still open", the AH-001 row updated with the ~48 English keys left); `RESUMPTION-TEMPLATE.md` Part 2; the AH-105 note
(sign-up collects what web sign-up collects, no new category; the consent line is the only in-app privacy link so far). Two-commit pair (feat + docs); push held.

## 5. i18n inventory (×24, `apps/main/.../locales/*/auth.json`; no API `lang/` change, the 429 reuses `auth.login.rate_limited`)

**New (8 keys, 192 values):** `auth.ui.native.sign_up_subtitle`, `auth.ui.native.new_to_catalyst`, `auth.ui.consent.line`, `auth.ui.consent.terms`,
`auth.ui.consent.privacy`, `auth.ui.banners.email_verified_app`, `auth.ui.actions.verified_sign_in`, `auth.ui.errors.not_verified_yet`.
**Reused (no key):** `auth.ui.actions.sign_up`, `auth.ui.actions.back_to_sign_in`, `creator.ui.security.delete.button`, `creator.ui.security.title`, `app.nav.menu`.
**Retired (1 key, 24 values removed):** `auth.ui.native.create_account`.
**Retranslated (F, 27 keys × 10 flaky locales = 270 values):** list generated in S2 and pasted into the review file.
All new keys ship with a real MT baseline in every locale including the flaky 10, checked by a script that asserts every non-`en` value differs from `en` (the AH-100
precedent), plus parity, placeholder and plural gates. The consent sentence takes two named slots, so the placeholder gate also proves both survive in every locale.

## 6. Production posture

- **Schema / data:** none. No existing row is touched. The feature writes what web sign-up writes, through the same service.
- **New runtime state:** limiter counters in the cache, one key per normalised email per hour; lost on a cache flush, which only resets the limit.
- **Blast radius of a bug:** the limiter, too strict, 429s legitimate sign-ups (web and invitation acceptance) for up to an hour per address, with no data effect and no
  lockout of an existing account; too loose, the limit is not there and nothing else changes. The layout predicate, if wrong, changes how an auth page looks (web: pinned by
  spec). The pending-page and menu changes are navigation only.
- **Deploy:** rides the combined PENDING deploy (the API reload and the web build). No `queue:restart`, no migration, no new one-shot command. The web deploy is the order that
  matters little here: the new web build against the old API works (no new endpoint); the new API with the old web works (the old web page just sees a 429 shape it already
  renders).
- **Not verified in this chunk:** Android; TestFlight and production APNs (not related); the limiter behind production's real proxy chain (the key does not use the IP, the
  per-IP fallback does, and `auth-ip` already depends on the same trusted-proxy setting); the Terms page's content.

## 7. Device walk — a fresh account, end to end (Pedram, iPhone, Xcode install; written to `in-app-signup-device-walk.md` at S8)

**Prerequisites (named, because the first time here they are all new):** the local stack on the Mac's LAN address, as for AH-108; `.env.native-development` pointing at it;
`FRONTEND_MAIN_URL` set to the same LAN address so the mail link opens in Safari (the default `http://127.0.0.1:5173` is the phone itself); the web dev server reachable on the LAN;
a queue worker running (the mail is queued); a local mail catcher (**I do not know which one is configured locally; tell me, I will not assume**); `account_deletion_enabled` armed
locally; an Xcode build of the S4 to S6 state. After the walk: stop the stack, delete the walk accounts, restore `.env.native-development` and the flag.

| #   | Step                                                                                          | Expect                                                                                                                                                                                                                                                          |
| --- | --------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| W1  | Open the app signed out                                                                       | Sign-in sheet; a divider, "New to Catalyst?" and a **Create account** button; no "create your account at platform…" line                                                                                                                                        |
| W2  | Tap Create account                                                                            | A sign-up sheet in sign-in's style, language pill top right, five fields, the consent line, the button. With the keyboard up, every field, the consent line and the button are reachable by scrolling; Return moves to the next field                           |
| W3  | Tap **Terms**, come back, tap **Privacy**                                                     | Each opens in the **system browser** (Safari app); returning leaves the sign-up form as it was. If the app view navigates to the page instead: **stop, fail W3 (P10, C10)**                                                                                     |
| W4  | Submit: a 6-character password; mismatched confirmation; then an address that already exists  | Each error under its field, in the app's language; the taken address shows the one static message (same words as for any taken address)                                                                                                                         |
| W5  | Submit a valid new account                                                                    | The pending sheet with the address; the **first real call from the native origin to `/auth/sign-up` succeeds** (P12). Resend works once, then answers with the 1-per-minute message                                                                             |
| W6  | Open the mail on the phone, tap the link                                                      | Safari opens (not the app); the confirm page says verified **and** shows the Catalyst-app line (C3); nothing in the app has changed                                                                                                                             |
| W7  | Switch back to the app. Tap **I've verified, sign in**                                        | The sign-in sheet with the email filled (C4). Sign in: the first-time Welcome Back, then the profile step                                                                                                                                                       |
| W8  | In the wizard, tap the **account menu** (native header, beside Save and exit)                 | The email and **Security**. Open it: the Security page; the back arrow returns to the wizard step you were on                                                                                                                                                   |
| W9  | Delete my account: password, confirm                                                          | A 14-day request; signed out with the "scheduled for deletion" banner; signing in offers to restore. **Restore it** (the next step needs a fresh account)                                                                                                       |
| W10 | New sign-up, **do not verify**. Sign in                                                       | The pending page, signed in, with **Delete my account** (C5). Tap it: Security opens (no error, no blank), the request works, the same banner. Tap "I've verified" first with the mail unopened: the "could not see the verification yet" message, and you stay |
| W11 | Verify that account in Safari later, return, tap the button                                   | Signed in and verified: it goes to the wizard without asking for the password again                                                                                                                                                                             |
| W12 | Set the language pill to **Hungarian**, then **Maltese**, then **Irish**, and walk W2, W4, W5 | Every screen, error and the 429 text in that language, none in English (the F keys). Record which strings look wrong; no native speaker has reviewed them                                                                                                       |
| W13 | One address, four attempts in a row with a weak password                                      | The first three: the field error. The fourth: the "too many requests" message in the app, nothing created. This is the C7 cost, seen once. Clear the cache afterwards                                                                                           |
| W14 | Desktop browser: `/sign-up`, `/sign-in`, the confirm link, the wizard (flag on)               | `/sign-up` unchanged but for the consent line; `/sign-in` unchanged; the confirm page shows the line; the wizard topbar shows the account menu and Security opens                                                                                               |

**Pass:** W1 to W11 and W14 as expected; W12 and W13 are recorded, not judged. A fail on W3, W5 or W10 reopens the chunk. The walk is Pedram's; nothing in the review file will
read "passed" until he reports it.

## 8. Gates before the push

Full backend Pest (serial, `-d memory_limit=2G`, the `SANCTUM_STATEFUL_DOMAINS` env above); Pint `--all`; PHPStan with no path argument (`--memory-limit=1G`); full Vitest on
`apps/main` (and `api-client` if touched, expected not); `vue-tsc`; ESLint; Prettier; locale parity and the new flow-strings spec; full Playwright for both SPAs (dev stack down,
own E2E DB, output to `playwright-report/`); CI green at the pushed tip, run URL cited (§5.41). Break-revert record for every gate above, exit codes read, in
`in-app-signup-break-reverts.md`.

## 9. Confirmations needed before S1

| #   | Needed                                                                                                                                                                  | My recommendation             |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------- |
| C1  | Reinterpret "hero route" as a sheet-route set so the web sign-up does not change (P1)                                                                                   | Yes                           |
| C2  | The pending page joins the sheet routes (P2)                                                                                                                            | Yes                           |
| C3  | Confirm-page wording: a neutral line for everyone (P3), or your sentence for everyone, or a mobile-UA gate                                                              | Neutral line                  |
| C4  | Sign-in prefills the email from `?email=` after "I've verified, sign in"                                                                                                | Yes                           |
| C5  | An unverified, signed-in account gets a "Delete my account" link on the pending page, and the creator layout swallows the unverified 403 (P5)                           | Yes (your walk line needs it) |
| C6  | Wizard account menu = Security only, hidden while the flag is off, drawer item on web-mobile (P6)                                                                       | Yes                           |
| C7  | Limiter counts every attempt, 3/hour, as ruled, with the consequence for fixing typos and for web invitees (P7); or raise the number                                    | Keep 3, look at W13           |
| C8  | The English scope: the six (+ the invitation banner), the 27-key flow set F, or all 75 (P8)                                                                             | F                             |
| C9  | The exact **Terms URL**, confirmed live; and that a notice without a recorded acknowledgement is acceptable to you (P9)                                                 | Yours to give                 |
| C10 | If W3 shows the app view navigating in place: add `@capacitor/browser` (in-app Safari, a native dependency and a new build) or change the links to something else (P10) | Decide only if W3 fails       |

Also needed, not a decision: **which local mail catcher is configured** (W prerequisites).

**Stopping here for review. No code until the plan is cleared.**
