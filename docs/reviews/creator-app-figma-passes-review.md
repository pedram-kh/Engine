# Creator app: Figma-faithful page passes, walkthrough findings and the real app icon (AH-100b) — REVIEW

- **Status: Closed — approved.** Verdict (Claude, independent review, 2026-10-08; decision owned by
  Pedram): D1–D4 and Q1–Q5 verified as ruled. The native-gating mechanism is accepted: scoped sheets
  with unchanged scope ids, `NativeCard`'s web-bare-slot render, and the registered-sheets scoping spec.
  The D2/D3 both-polarity pins are verified, including the disclosed `/auth/logout` spec edit as the
  Q-ruled change. The §6 icon work is accepted as the asset-generation standard: contact-sheet
  evidence, generator defects fixed and gated, reproducible and idempotent. The §3 17-item deviation
  list is accepted as the record, with per-item rulings to follow from Pedram's eyes-on. The
  zero-new-strings i18n reasoning is verified. The S1 re-run closed the one evidence gap (§7).
  Close condition: CI green at the pushed tip (§5.41), the wizard-spec flake judged against its cited
  baseline; any other red is diagnosed.
- **Commits:** feat `6c2b86ec` · docs (this review, the log entry, the deploy-log PENDING entry, the
  tech-debt entry, the Part 2 refresh). CI at the pushed tip is recorded below once it lands.
- **Provenance:** kickoff and rulings by Claude, decision-owned by Pedram, built by Cursor,
  independent review closure by Claude.
- **Chunk:** Creator App arc, chunk 3 of 8. Entry **AH-100b** (split from AH-100 at its Q4).
- **Scope fences held:** no push, camera, deep links or biometrics (AH-102/103); no sign-up (AH-104b);
  no store metadata beyond icon and splash (AH-105); **no web visual change**; no backend; no
  migration. **PROD-DATA RISK: NONE.** Zero `apps/api` / `apps/admin` diff.
- **Acceptance instrument:** Pedram's simulator and device eyes-on (rebuild steps in §9). No simulator
  ran in this session, so every visual claim below is by token and CSS reasoning, not by looking.

## 1. What was built (S1–S10)

| Step | Part | What                                                                                               |
| ---- | ---- | -------------------------------------------------------------------------------------------------- |
| S1   | D3   | The session-lapse banner, shown only on **involuntary** termination. `/auth/logout` is exempt.     |
| S2   | D2   | Native sign-in: hero headline only, the form in a card, the create-account pointer.                |
| S3   | D4   | Real app icon and dark splash for both platforms, reproducible from committed sources.             |
| S4   | D1   | Native component language in `native.css` (fields, buttons, chips, tabs, cards), one global layer. |
| S5   | D1   | Wizard body: `onboardingNative.css` plus `NativeCard` (frames 02–05, 06, 06b, 06c).                |
| S6   | D1   | Review and submit, master agreement (06b, 06c).                                                    |
| S7   | D1   | Creator pages: `creatorNative.css` (frames 09, 10, 11, 14).                                        |
| S8   | D1   | Edit profile (frame 13), through the same file. Two dialogs become bottom sheets.                  |
| S9   | —    | The board (§7).                                                                                    |
| S10  | —    | This file, the log, the deploy-log disposition, tech-debt, the Part 2 refresh.                     |

### D1 mechanism

- **Native-only, from the outside.** `onboardingNative.css` is bound by `.onboarding-native__body` and
  `creatorNative.css` by `.creator-main--native`. Both are classes the layouts render only inside the
  app. Pages keep their markup, copy and test ids.
- **Both sheets are `<style scoped src>` blocks on layouts that already had a scoped block**, so the
  scope id is unchanged and the web DOM gains no attribute.
- **`NativeCard`** (`apps/main/src/components/NativeCard.ts`) is a render function: a `div.native-card`
  on native, and its bare slot on the web, so the web DOM is identical.
- **Two bottom sheets** (Q4): the job-apply form in `CreatorJobDetailPage` and the draft-link form in
  `CreatorAssignmentDetailPage`. `<component :is="isNative ? VBottomSheet : VDialog">`, same fields and
  buttons, same test ids.
- **Specs that gate the sheets:** `native-page-css-scoping.spec.ts` (every selector in a registered
  sheet starts with its root class; no at-rules), plus the existing `native-css-scoping.spec.ts`. New
  native sheets register in the `SHEETS` array of the former.

## 2. Figma deltas against the token table

Frames pulled through `get_design_context` this chunk (skill `figma-design-to-code` loaded first):
06b (23:401), 06c (17:492), 09 (20:510), 10 (20:621), 11 (22:277), 13 (22:558),
14 (23:590). Frames 02–06 were pulled in the earlier segment of this chunk. 01, 07, 08 and 12 were
AH-100's.

- **Radii in the frames:** `sm 4`, `md 6`, `lg 8`, `xl 10`, `2xl 12`, `full 999`. All present in
  `nativePalette`; no new radius was needed.
- **Type styles in the frames** (Display 28/36, H3 18/24, H4 15/20, Body/Default 14/20, Body/Strong
  14/20, Body/Small 13/19, Caption 12/16, Caption/Strong, Overline 11/14 +0.6, Button 14/20,
  Button/Primary 14/20 600) are all in `nativeCssVariables`. No new type token.
- **Colours:** every colour in the frames is a token reference (`bg/*`, `border/*`, `text/*`,
  `accent/*`, `status/*`, `usp/6`). No new colour.
- **The one open delta is the `status-*-subtle` alphas.** The frames' status pills and banners fill
  the status colour at a per-node opacity that the React export does not carry (it prints the bare
  `bg-[var(--status/info)]`). The pulled output gives no number, so none was claimed. The uniform 16%
  stays, still in the tech-debt entry (§8, item 1).

## 3. Deviations from the frames — the complete list, for Pedram's per-item ruling

Each item is a place where the built app differs from the frame. "Why" is the constraint. Items marked
**copy** would need new strings ×24 locales (real MT) and a decision first; items marked **behaviour**
need new logic, not styling.

### Dialogs (Q4)

1. **Other dialogs stay dialogs.** Only the two named ones became sheets. The remaining native
   dialogs (availability is already a sheet; the confirm and reject dialogs on the agency-facing
   screens are not creator-app screens) are unchanged. _Ruling needed per dialog if any is wanted._

### Wizard (02–06c)

2. **06b — the reading-progress hint and bar are not built.** The frame shows "Scroll to the end of the
   agreement to continue", "12% read" and a 6px progress bar. The app only tracks "reached the end".
   **behaviour + copy.**
3. **06b — the info banner sits inside the card as body-small text.** The frame has a separate info
   banner above the card ("No separate contract needed right now"). The page's one sentence is
   restyled, not promoted to a banner.
4. **06b — the document header row** (file tile, "Creator Terms and Conditions", "Version 1.1 · Effective
   17 Jul 2026", a download button) is not built; the app shows the version line under the text.
   **behaviour + copy** (download).
5. **06b — the bottom fade over the agreement text** is not built.
6. **06c — "To reach 100%" list and the centred footnote are not built.** **copy + behaviour.**
7. **06c — the step rows keep the existing check icon** (`mdi-check-circle`), not a 24px accent disc, and
   keep "Step N" beside the name rather than above it. The Edit button is restyled ghost.
8. **Undesigned wizard steps** (KYC, tax, payout, and any state Figma does not draw) take the
   **token-derived set**: the same field language, card grouping and type scale, with no frame behind
   them. Recorded so nobody reads them as Figma-checked.

### Creator pages

9. **09 Notifications — the All / Unread segmented control and its count are not built.** Filtering is
   new behaviour and "Unread" is new copy. The feed is one list, as before. **behaviour + copy.**
10. **09 — the type icon discs, the relative time ("2h") and the title/subtitle split are not built.**
    The API's rows carry a body and an optional detail, not a title, subtitle, type or timestamp in
    that shape. The unread tint and dot are built. **data shape.** (The shape was read from
    `NotificationCenter.vue`'s template, not from the API; the claim stands only for what the template
    renders.)
11. **09 — "You're all caught up" footer is not built.** **copy.**
12. **10 Campaign invitations — the brand initials tile, the details panel (Fee / Dates / Deliverables
    as label-value rows), the "Note from the brand" bar and "Invitations expire 7 days…" are not
    built.** The page renders the campaign name, a status chip, one meta line and the offer text. The
    action order matches the frame (Accept full width, then View and Decline side by side). **data
    shape + copy.**
13. **10 — Decline is the existing outlined error button,** not the frame's tinted fill.
14. **11 Messages — the header "New message" soft button, the search field, the ringed icon and the
    title "No conversations yet" are not built.** The empty state keeps its sentence and its start
    button; the button is restyled primary. **copy + behaviour** (search).
15. **14 Job posts — the search field and the empty state's icon, title and "Browse invitations" button
    are not built** for the same reason. The shared empty-state component is restyled (ringed icon
    slot, H4 title, body-small sentence), so any page that supplies an icon and title gets the frame's
    look. **copy + behaviour.**
16. **13 Edit profile — the Profile basics, Contact details, social and portfolio groupings follow the
    page's existing sections, not the frame's card split.** The page's four sections become the
    frame's cards; the form's own inner cards flatten inside them so nothing is double-carded. The
    Master agreement card's "Accepted" pill is the existing section, restyled.

### Not seen in any frame

17. **Visual verification of the new CSS has not happened.** The float legends, the `:has` tile tint,
    the 46px field height (`--v-input-control-height`) and the field radius via `--v-field-border-radius`
    are token-correct and spec-scoped but unlooked-at. Pedram's eyes-on is the check.

## 4. D2 — native sign-in content set (pinned both directions)

Hero headline only, the card, the create-account pointer (Q1). `AuthLayout.native.spec.ts` and
`SignInPage.native.spec.ts` pin what **is** present on native and what is **absent** (marketing tail,
sign-up link, Meta Pixel), and that the web renders its full set. The 240-line cap on `AuthLayout.vue`
held by extracting `marketingTail.ts` (239 lines), not by raising the cap.

## 5. D3 — the lapse banner (two polarities)

- **On:** the native 401 policy acts only when there is a session to expire (user or token present and
  not `isLoggingOut`), sets a one-shot flag (Q2), and the sign-in page shows the banner once.
- **Off:** `/auth/logout` joins the exempt paths, and a voluntary sign-out never shows it.
  `native-logout-race.spec.ts` pins the race-agnostic posture (the outcome is the same whichever of
  the logout response and a stray 401 lands first).
- **One spec edit to disclose:** the AH-100 native-policy spec changed because `/auth/logout` moved
  from "handled" to "exempt". The behaviour it pinned for every other path is unchanged.

## 6. D4 — icon and splash

- **Source (cited):** `apps/main/src/modules/auth/assets/catalyst-logo.svg`, the lockup used in the
  sign-in header. **There is no "mail logo from AH-064".** AH-064 is the Meta Pixel work, the mail theme
  has no image (`vendor/mail/html/header.blade.php` prints the slot text) and nothing under `apps/api`
  references a logo file. The kickoff's claim is corrected here, not worked around.
- **Crop decision (Q3):** the mark alone, cropped from the lockup, on `#0b0b0c` (the native canvas,
  pinned equal to `nativePalette['bg-canvas']` by spec). An icon is the symbol, not the lockup.
- **Resolution, honestly:** the source is a vector, so there is no upscaling. Sources are rendered at
  1024 (icon) and 2732 (splash) by `apps/main/scripts/app-icon-sources.mjs` into `apps/main/assets/`.
- **40px blur check (mandatory):** passed. The Gaussian-blur core stays legible at 40px; the evidence
  contact sheet is `docs/reviews/assets/ah-100b-app-icon-sheet.png`.
- **Reproducible:** `pnpm --filter @catalyst/main assets:generate` (sources → `capacitor-assets` → the
  Android fix-up), idempotent (run twice, identical output). Afterwards run
  `git checkout -- android/app/src/main/AndroidManifest.xml`, which the generator edits.
- **Generator defects and the fix-up script** (`app-icon-android-fixup.mjs`):
  1. The adaptive foreground came out at 192px, upscaled 1.5× from the 108dp spec. Re-cut at true
     108dp sizes (ldpi 81 … xxxhdpi 432).
  2. The legacy square and round icons had a constant 8px transparent border. Re-cut from the
     opaque source (square rounded at 14%).
  3. The launcher background and the splash were white. Both are now `#0B0B0C`
     (`values/ic_launcher_background.xml`, `windowSplashScreenBackground`).
- **A correction I made to my own work:** the adaptive safe zone is 66dp of 108 (≈ 61%), not 66%.
  The first mark touched the mask edge. The mark is now sized to the same share of the 72dp visible
  window as on iOS, which the contact sheet shows.
- **Gate:** `appIconAssets.spec.ts`, 17 tests (canvas colour, committed sources reproduced
  pixel-for-pixel into `test-results/`, sizes, alpha, safe zone, corners, Splash set complete and no
  orphans, Android layer sizes, adaptive XML exact, dark launcher background, idempotent helper).
- **Out of scope, named follow-up:** the Android 13 themed (monochrome) icon.

## 7. Evidence

### Break-reverts (mutate, watch the right spec fail, restore byte-identical by sha256, re-green)

| Artifact                          | Mutation                                            | Result          |
| --------------------------------- | --------------------------------------------------- | --------------- |
| `creatorNative.css`               | first selector loses its `.creator-main--native`    | 1 red, restored |
| `onboardingNative.css`            | first selector loses its `.onboarding-native__body` | 1 red, restored |
| `NativeCard.ts`                   | always render the card (also on the web)            | 1 red, restored |
| `NativeCard.ts`                   | drop the `native-card` class                        | 1 red, restored |
| `CreatorJobDetailPage.vue`        | always `VDialog`                                    | 1 red, restored |
| `CreatorJobDetailPage.vue`        | always `VBottomSheet` (breaks the web)              | 1 red, restored |
| `CreatorAssignmentDetailPage.vue` | always `VDialog`                                    | 1 red, restored |
| `CreatorAssignmentDetailPage.vue` | always `VBottomSheet` (breaks the web)              | 1 red, restored |

### S1 re-run: the native 401-policy gates (captured, per test)

The original S1 list of 17 was not recorded, so this is a **reconstructed set of 19** covering every
behaviour line S1 added: `/auth/logout` exemption, the live-session predicate and its reader, the
production wiring, the token store's lapse flag, the store's bootstrap, login and logout handling of the
flag, and the sign-in page's read of it. Each was applied to a clean file, the five S1 specs plus
`transport-mode.spec.ts` were run (baseline 122/122), the exact red tests were taken from Vitest's JSON
reporter (kept locally in the gitignored `apps/main/test-results/s1-br/`), and the file was restored byte-identical (sha256 checked for
every one; final run 122/122 green).

| #    | Mutation                                                        | Red (exact tests)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| ---- | --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| BR1  | `index.ts`: /auth/logout removed from exempt list               | **3** — `index`: exempts /auth/logout; `index`: touches nothing on the exempt path /auth/logout; `native-logout-race`: double-tapped Sign out: the second revoke is refused with a 401 and that is not an expiry                                                                                                                                                                                                                                                                                                                                                                                                          |
| BR2  | `index.ts`: hasLive: isLoggingOut ignored                       | **3** — `index`: () {"isLoggingOut":true,"hasUser":true,"hasToken":true} → false; `index`: session gate () touches nothing with a deliberate sign-out in flight; `native-logout-race`: held-open request: a 401 landing WHILE the revoke is in flight acts on nothing                                                                                                                                                                                                                                                                                                                                                     |
| BR3  | `index.ts`: hasLive: user only (token ignored)                  | **2** — `index`: () {"isLoggingOut":false,"hasUser":false,"hasToken":true} → true; `native-logout-race`: a token still held with no user (the window around bootstrap) is a session to expire                                                                                                                                                                                                                                                                                                                                                                                                                             |
| BR4  | `index.ts`: hasLive: token only (user ignored)                  | **3** — `index`: () {"isLoggingOut":false,"hasUser":true,"hasToken":false} → true; `native-logout-race`: a user still held with the token already unreadable is a session to expire; `native-logout-race`: a token store that throws on read reads as "no token" and does not crash the policy                                                                                                                                                                                                                                                                                                                            |
| BR5  | `index.ts`: hasLive: user AND token required                    | **5** — `index`: () {"isLoggingOut":false,"hasUser":true,"hasToken":false} → true; `index`: () {"isLoggingOut":false,"hasUser":false,"hasToken":true} → true; `native-logout-race`: a token still held with no user (the window around bootstrap) is a session to expire; `native-logout-race`: a user still held with the token already unreadable is a session to expire; `native-logout-race`: a token store that throws on read reads as "no token" and does not crash the policy                                                                                                                                     |
| BR6  | `index.ts`: policy: session guard disabled                      | **5** — `index`: session gate () touches nothing with no session at all; `index`: session gate () touches nothing with a deliberate sign-out in flight; `native-logout-race`: held-open request: a 401 landing WHILE the revoke is in flight acts on nothing; `native-logout-race`: held-open request: a 401 landing just AFTER the sign-out finished acts on nothing; `native-logout-race`: a revoke that failed still ends with no session, so a late 401 stays quiet                                                                                                                                                   |
| BR7  | `index.ts`: reader: hasUser inverted                            | **6** — `index`: () reads the logout flag and user from the store and the token from the token store; `index`: () reads "no user" and "no token" as false, and a throwing token store as no token; `native-logout-race`: held-open request: a 401 landing just AFTER the sign-out finished acts on nothing; `native-logout-race`: a revoke that failed still ends with no session, so a late 401 stays quiet; `native-logout-race`: a user still held with the token already unreadable is a session to expire; `native-logout-race`: a token store that throws on read reads as "no token" and does not crash the policy |
| BR8  | `index.ts`: reader: hasToken inverted                           | **5** — `index`: () reads the logout flag and user from the store and the token from the token store; `index`: () reads "no user" and "no token" as false, and a throwing token store as no token; `native-logout-race`: held-open request: a 401 landing just AFTER the sign-out finished acts on nothing; `native-logout-race`: a revoke that failed still ends with no session, so a late 401 stays quiet; `native-logout-race`: a token still held with no user (the window around bootstrap) is a session to expire                                                                                                  |
| BR9  | `index.ts`: reader: unreadable token store throws               | **2** — `index`: () reads "no user" and "no token" as false, and a throwing token store as no token; `native-logout-race`: a token store that throws on read reads as "no token" and does not crash the policy                                                                                                                                                                                                                                                                                                                                                                                                            |
| BR10 | `index.ts`: production wiring: getSessionState not supplied     | **1** — `transport-mode`: () a 401 with no user and no token (a late reply after sign-out) does nothing                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| BR11 | `tokenStore.ts`: tokenStore: expiry does not set lapsed         | **1** — `tokenStore`: () is true once an EXPIRED token has been discarded by read()                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| BR12 | `tokenStore.ts`: tokenStore: clear() keeps lapsed               | **1** — `tokenStore`: () an explicit clear() (logout) resets it, so a deliberate sign-out is never a lapse                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| BR13 | `tokenStore.ts`: tokenStore: write() keeps lapsed               | **1** — `tokenStore`: () a fresh write() resets it                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| BR14 | `useAuthStore.ts`: bootstrap: no-token branch ignores hasLapsed | **3** — `useAuthStore.native`: sessionLapsed ( — the one-shot lapse flag) cold start whose token EXPIRED while the app was closed raises the flag; `useAuthStore.native`: sessionLapsed ( — the one-shot lapse flag) login() clears it; `useAuthStore.native`: sessionLapsed ( — the one-shot lapse flag) logout() clears it                                                                                                                                                                                                                                                                                              |
| BR15 | `useAuthStore.ts`: bootstrap: /me 401 does not set lapsed       | **1** — `useAuthStore.native`: sessionLapsed ( — the one-shot lapse flag) cold start whose token was refused by /me (revoked or suspended) raises the flag                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| BR16 | `useAuthStore.ts`: login() keeps lapsed                         | **1** — `useAuthStore.native`: sessionLapsed ( — the one-shot lapse flag) login() clears it                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| BR17 | `useAuthStore.ts`: logout() keeps lapsed                        | **1** — `useAuthStore.native`: sessionLapsed ( — the one-shot lapse flag) logout() clears it                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| BR18 | `SignInPage.vue`: SignInPage: lapse flag not read               | **2** — `SignInPage.native`: () shows it for a lapsed cold start (the bootstrap raised the lapse flag); `SignInPage.native`: () the banner goes once the lapse flag is cleared (login or logout clears it)                                                                                                                                                                                                                                                                                                                                                                                                                |
| BR19 | `SignInPage.vue`: SignInPage: lapse flag read on web too        | **1** — `SignInPage.native`: a raised lapse flag paints no banner on web — only ?reason=session_expired does                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |

- **BR10 survived the first pass (0 red).** The production wiring (`getSessionState:` handed to the
  policy) sits in a `c8 ignore` block, and both policy factories are tested with fakes, so nothing
  noticed when the real policy was built without the guard. **A survivor on the chunk's one auth-behaviour
  seam, so it was pinned, not waved through:** two tests in `transport-mode.spec.ts` now drive the real
  `onUnauthorized` that `core/api` hands the HTTP client (no session, so nothing happens; user held, so
  the session is cleared and sign-in routed). BR10 then re-ran red as shown. The first-pass JSON is kept
  in `results-first-pass.json`.
- The icon spec's mutations (earlier in the chunk) found two survivors, S3-BR8 (the committed background
  XML was unpinned: now pinned) and S3-BR10 (an unreachable guard: removed).

### The board

- `lint:frontend`: 0 errors, 2 pre-existing `v-html` warnings.
- `typecheck:frontend`: clean. (One `TS2345` in my own new spec, `CreatorJobDetailPage.spec.ts:584`,
  surfaced by `vue-tsc` and fixed with the file's own `as never` idiom. Vitest does not typecheck.)
- `test:frontend`: design-tokens 40 (+1 todo), api-client 221, ui 43, admin 488, **main 1941 at the board run, 1943 after the two tests the S1 re-run added** — all
  green.
- Architecture suite: 207/207, including `i18n-locale-parity` and `i18n-used-keys-resolve`.
- `format:check`: 4 files, all pre-existing and not mine (`apps/admin/index.html`,
  `apps/main/index.html`, `docs/reviews/sprint-4-chunk-2-review.md`, `scripts/i18n/verify-locale.mjs`).
  `marketingTail.ts` was flagged and fixed.
- **Web untouched:** the AH-100 pin set is green and unedited. Every added CSS selector is
  native-gated (two scoping specs). The two layout SFCs already had a scoped style block, so their
  scope id, and therefore the web DOM, is unchanged.
- **Playwright, full, dev API brought down first, isolated `catalyst_e2e` database (the config's
  default), logs in `apps/*/playwright-report/ah-100b-e2e-*.log`:**
  - `apps/main`: **31 passed, 2 failed on the first pass.** The two are the known cold-start flake
    class: `creator-wizard-happy-path.spec.ts` (the pre-existing flake that AH-100's CI also retried)
    and `2fa-enrollment-and-sign-in.spec.ts` (#19). **Both passed when re-run alone** (2/2). I flag this
    plainly because the wizard spec traverses the surface this chunk edited: the green re-run, the
    web-identical pins and the zero-web-diff CSS argument are the evidence it is the flake and not a
    regression, but CI at the tip is the real check, and the reviewer should read it that way.
  - `apps/admin`: **1 passed, 1 failed on the first pass**: `admin-sign-in.spec.ts` (the inline TOTP
    reveal), the known admin flake. `apps/admin` has zero diff in this chunk. Green alone (1/1).
  - Local Playwright runs `retries: 0`; CI runs 2.
  - The harness needed two environment facts that are now in the RESUMPTION block: `TEST_HELPERS_TOKEN`
    must be exported, and `PLAYWRIGHT_BROWSERS_PATH` must point at the real browser cache
    (`~/Library/Caches/ms-playwright`), because the agent sandbox's own cache has no browsers. My first
    two attempts failed on exactly that (33/33 red, instantly, "Executable doesn't exist"); they are not
    results and are not counted.
  - The dev API on `:8000` was stopped for the run and restarted after; `/up` returns 200.
  - **Not verified:** I did not count rows in the dev `catalyst` database before and after, so I do not
    claim that isolation held beyond what the config enforces (`catalyst_e2e`).

### i18n

**No new user-facing string was added in this chunk, so no locale file changed.** The D1 passes restyle
existing copy, and the D3 banner reuses the existing session-expired text (the `?reason=session_expired`
path web already shows). The ×24 and flaky-10 real-MT discipline therefore had nothing to apply to;
every copy-bearing deviation in §3 is held back for exactly that reason. Parity and used-keys gates are
green.

## 8. Disclosures (one line each)

- **Build-script allowlist:** root `package.json` `pnpm.onlyBuiltDependencies` gains **`sharp`**, so its
  install step can fetch the prebuilt libvips.
- **New devDependencies, pinned exactly:** `@capacitor/assets` **3.0.5** and `sharp` **0.32.6** in
  `apps/main`. The tree is +95 packages in `pnpm-lock.yaml`, including a second `@capacitor/cli` 5.x
  under the assets tool. Both are build-time only; nothing ships in the web bundle.
- **Spec edits to existing files:** the AH-100 native-policy spec (§5) and
  `native-css-scoping.spec.ts` (selectors are whitespace-collapsed before the check, because prettier
  wraps a long selector over two lines).
- **One more spec edit, from the S1 re-run:** `tests/unit/core/api/transport-mode.spec.ts` gains two tests
  that pin the production policy wiring (§7).
- **Dead code, one tech-debt line:** `useIdleTimeout` is not called from anywhere on native.
- **Walkthrough findings (the AH-100 §9 walkthrough, discharged):** iOS 7/7; Android sign-in through
  the 419-guarded path, tabs, availability and token persistence. Recorded as a dated note on the
  AH-100 log entry.

## 9. Rebuild steps (Pedram)

```
pnpm --filter @catalyst/main build:native:dev
cd apps/main && pnpm cap sync
```

Then relaunch on both platforms. Check, in order: the home-screen icon and the splash, sign-in (headline,
card, create-account pointer), the wizard (every step, especially 06b and 06c), notifications,
invitations, messages, job posts, edit profile, and the two bottom sheets (apply to a job, add a
draft link). Rule on each item in §3.
