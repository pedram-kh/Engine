# AH-101 — notification preference matrix, completion notice, third UI batch — review

**Status:** **Closed.** Half A and Half B built and gated; **Pedram's device walk passed 2026-10-10** (sign-in
sheet, iOS splash, bell closes on navigation, composer above the tab bar, paperclip placements, no hamburger in
native) — the batch is accepted. **Pushed 2026-10-10:** `origin/main` at `b7a3342f` (docs close commit on top of `34d90a4f`, `6c32df5d`, `7d4f3e36`,
`811a0356`; base `a1a20ead`). **CI green at that tip, all four jobs** (Backend, Frontend, E2E main SPA, E2E admin SPA) —
[run `38024623651`](https://github.com/pedram-kh/Engine/actions/runs/38024623651). That run also executed the Playwright
matrix spec (§8.4's "unrun" caveat applied to my local machine only). One docs-only provenance commit follows it.
Pushing is not deploying: deploy timing is Pedram's call, and no flag is armed. Live push state is `origin/main`.
Inventory: [`notification-prefs-and-ui-batch-inventory.md`](notification-prefs-and-ui-batch-inventory.md) ·
Plan-pause: [`notification-prefs-and-ui-batch-plan.md`](notification-prefs-and-ui-batch-plan.md).

## 0. Two lines for the standing board

> ⚠ **STANDING-BOARD ITEM, independent of this chunk:** if production's `SOCIAL_PROVIDER` is **unset**, `social_verification_enabled` defaults **ON over the `mock` driver**, so posted content may be getting **mock-"verified" in production today**.

> ⚠ **BEHAVIOUR CHANGE ON DEPLOY:** the new guard **honours every stored `email=false` row immediately**, including rows that arrived any way other than this page. Before deploy, read the blast radius as a number (§6).

## 1. What shipped in Half A

### A1 — one email guard (`NotificationService::isEmailEnabled`)

`isEmailEnabled(User $recipient, NotificationType $type): bool` keys on the **recipient user** and the
**type**; absence of a row means ON; a stored `email=false` row stops that mail (Q1: honoured, never
cleared). It is called **after** each site's Pennant flag, so it never replaces a kill switch. Mail with no
`NotificationType` is structurally exempt.

| Site (file)                                                        | Types guarded                                                                                                                                                           |
| ------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SendAssignmentNotifications` (private `queueMail`)                | invited, completed_on_approval, manually_verified, live_verified, draft_approved / revision_requested / draft_rejected, draft_submitted (inviter), contracted (inviter) |
| `DebouncedMessageMailer` (after flag, **before** the debounce row) | `message.received_by_creator`, `message.relationship_received_by_creator` (type derived from the mailable's `context`)                                                  |
| `CampaignApplicationNotifier::queue`                               | `campaign_application.submitted` / `accepted` / `rejected`                                                                                                              |
| `JobPostedFanOutService::queueMail`                                | `campaign.job_posted` (the once-only stamp still happens regardless)                                                                                                    |

**Not guarded, by name** (the allowlist in `EmailPreferenceGuardArchitectureTest`, each with a reason): the
message digest command, sign-up verification, password reset, agency invitations, admin member invites,
bulk invite, the incomplete-creator nudge, the blacklist and connection-request mails, `creator.approved` /
`creator.rejected` (always on, Q2), the admin relation connect/disconnect mails, the verification-failed
mail, and the contract/resolution controller mails.

### A2 — `assignment.live_verified`

The success branch of verification was the only silent one. Now: creator-only (Q5); **in-app ungated**; the
**mail is behind `post_verified_mail_enabled`, default OFF** (new flag in `Creators/Features`, registered in
`CreatorsServiceProvider`, the admin flag list and `docs/feature-flags.md`). New `PostVerifiedMail` + markdown
view; `campaigns.assignment_notifications.post_verified.email.*` in **24 locales** (real translations; the
render test asserts each non-English subject and body differs from English and keeps its interpolations and
deep link). The copy claims only that the post was found at the submitted link: no payment, no approval.
**The flag does not arm until the read-back in §6 confirms a real verification driver.**

### A3 — the matrix

`LIVE_TYPES` stays the single registry. `channels` per type now lists `email` **only** where a guarded send
site exists (12 creator types plus the agency `campaign_application.submitted`). Columns are derived by
`preferenceColumns()` from the union of channels, sorted by one constant, `PREFERENCE_CHANNEL_ORDER`
(`in_app`, `email`, `digest`) — **adding `push` for AH-102 is one entry there, the enum case and a label**.
`creator.approved` / `creator.rejected` keep their in-app toggle and show "Always on" in the email column
(`alwaysOnChannels`). The page is a grid on web at md+ and **stacked cards per type** in the native app
and at narrow web widths (Q6). **Save semantics are unchanged:** same `enabled` map, same full-visible-set
payload, same single Save. Existing `data-test` ids are kept. New strings ×24: `channels.email`, `alwaysOn`,
the reworded `description`, `typeLabels.assignment_live_verified`, `types.assignment_live_verified`.

## 2. Decisions recorded (Q1–Q10, Half A)

Q1 honour crafted rows, never clear · Q2 `creator.*` mails always on, caption only on the email cell, in-app
toggle kept · Q3 no creator-matrix cell for agency-inviter mails; guard stays generic per recipient; an
agency-side preferences surface is a named follow-up · Q4 A2 ships mail dark · Q5 completion notice to the
creator only · Q6 stacked cards on native, no new save model.

## 3. Evidence

- **Both polarities at every site** (`EmailPreferenceGuardTest`, 15 sites): no rows → every site sends; **one**
  `email=false` row → exactly that site stops and the other 14 still send. Plus: the in-app row survives an
  email opt-out; an explicit `email=true` row sends; a suppressed chat mail never writes the debounce row.
- **Break-reverts, restored and re-run green:** (1) `isEmailEnabled` forced to `false` → **17 failed** (the
  no-rows test and every dataset row); (2) the guard call removed from `JobPostedFanOutService` → **1 failed**,
  `campaign.job_posted ignored the recipient's email=false row`, the other 14 sites green; (3) an extra
  `email` channel added to `assignment.draft_submitted` in the FE registry → **5 failed** across the registry,
  parity and page specs; (4) a new unguarded `Mail::to(...)` file under `app/` → the architecture test fails
  naming the file.
- **Inertness proof:** the existing Campaigns / Messaging / Notifications / Admin / Creators / Unit suites run
  **unedited**. The only reds are 11 in `AdminImpersonationCoreTest` and `EnforceImpersonationTest` (HTTP 500
  on the impersonation claim route), and **they fail identically on a clean checkout** (stashed this chunk's
  changes, same 11) — pre-existing, not touched here, named rather than fixed.
- **A2:** flag off → row written, no mail; flag on → mail queued with the creator's locale; creator-only; email
  opt-out stops the mail and keeps the row; in-app opt-out stops the row and keeps the mail; a _different_
  type's opt-out does not stop it; all 24 locales render.
- **Gates:** Pint clean, PHPStan clean (tests included), `vue-tsc` clean, ESLint clean on the touched trees,
  the full `apps/main` vitest run had exactly three reds, all in the notifications parity spec, which pins the
  live set and the role split (it failed first on `live_verified`, exactly as designed); updated and re-run
  green. (The registry spec, `templates.spec.ts`, reads the backend enum from source and would have caught an
  unregistered type on its own.)
- **Playwright:** `playwright/specs/notification-preferences-matrix.spec.ts` is written (grid, derived columns,
  always-on caption, default ON, an email opt-out saved, reloaded through the real API and still off, then
  restored). **It has NOT been run.** The config deliberately refuses to reuse a server already on `:8000` /
  `:5173` (to protect the dev database), and Pedram's dev servers were up; I did not stop them. Run it
  with the dev servers down: `pnpm --filter ./apps/main test:e2e -- notification-preferences-matrix`.

## 4. Deviations and caveats

- The completion-notice **in-app copy equals `manually_verified`'s** ("Your post for {campaign_name} was
  verified."). Both are true statements; the preferences labels are distinct ("A post is verified" vs "A post
  passes automatic verification").
- **Pre-existing locale debt, not touched:** several `notifications.json` locales still hold English (el, et,
  fi, ga, hu, lt, lv, mt, ro) or Czech (hr, sk, sl) placeholders for older keys. The new keys are real
  translations in all 24, so they do not add to it.
- A guard is file-level in the architecture test and per-site in the behavioural test; a second mail added
  inside an already-guarded file would be caught only if it has a row in `guardSites()`.

## 5. Out of scope (stands) and named follow-ups

Mail-inside-transaction hazard · a real social adapter · AH-102's push worker · any change to who receives a
mail · **agency-side preferences surface** (the inviter mails are guarded but have no UI) · **agency success
fan-out** for the completion notice · **a locale row in Settings** (Q10, Half B) · **an Android boot screen** (Q8) · **the "Create an account" button, wired to real in-app sign-up (AH-104b, Q7)** · **a show/hide-password eye on the sign-in sheet** · **the 4px field-radius gap** (§8.3) · **the unrun Playwright walk** (needs the dev servers on :8000/:5173 stopped).

## 6. Production read-back (Pedram) — **before** deploy and **before** arming the flag

Run read-only on production. Table is `user_notification_preferences` (Postgres).

```sql
-- 1) The blast radius of "honour every stored row": how many rows exist at all, by channel and state.
SELECT channel, is_enabled, COUNT(*) FROM user_notification_preferences GROUP BY channel, is_enabled ORDER BY 1, 2;

-- 2) The number that matters: email opt-outs that will start suppressing mail on deploy, by type.
SELECT type, COUNT(*) AS users_opted_out FROM user_notification_preferences
WHERE channel = 'email' AND is_enabled = false GROUP BY type ORDER BY 2 DESC;
```

If query 2 returns rows, those are people who will stop receiving that mail on deploy — which is the intended
meaning of the row, but the count should be a known number, not a surprise. For the flag:

```bash
php artisan tinker --execute="echo config('integrations.social.driver').PHP_EOL; var_dump(Laravel\Pennant\Feature::active('social_verification_enabled'));"
```

- Driver **`mock`** (or unset → `mock`) with the flag **true** → the standing-board item in §0 is live: do not
  arm `post_verified_mail_enabled`, and treat existing "verified" posts as unverified.
- Arm `post_verified_mail_enabled` only once the driver is a real adapter.

## 7. Deploy obligations (Half A)

Backend deploy (code + 24 `lang/*/campaigns.php`); **no migration, no backfill, no one-shot command**.
Frontend: an `apps/main` SPA rebuild. Queue worker restart (new mailable class). One new flag, default OFF,
nothing to arm. See [`deploy-log.md`](../runbooks/deploy-log.md).

## 8. Half B — the third creator-app UI batch (native, SPA-only)

Everything here is gated on `isNativePlatform()` (or lives under a class only the app renders). **The web renders
as before, and each item pins both polarities** (a native test and a web test, and each was break-reverted: the
gate forced the wrong way, the test failed, the gate restored).

### 8.1 What changed

| Item   | What                                                                                                                                                                                                                                                      | Where                                                                                        | Pin                                                                                                                                             |
| ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| **U1** | The bell's menu closes on any route change (it stayed open behind "View all").                                                                                                                                                                            | `NotificationBell.vue`                                                                       | `NotificationBell.spec.ts`, four tests, real timers (vue-router hangs under fake ones)                                                          |
| **U3** | The relationship thread takes its height from the native layout tokens, not the web's fixed 140/180px offsets, so the composer sits above the tab bar.                                                                                                    | `RelationshipThreadView.vue` (rule placed after the `@media` block)                          | `rel-thread-native-height.spec.ts` — a source-inspection pin; **jsdom cannot lay this out, so it needs the device**                             |
| **U4** | The "+" attach menu is gone in the app. `ChatPanel`: the paperclip is the control and opens the picker in one tap. `RelationshipThreadView`: paperclip **and** link inline (the DM menu held two actions, so a lone paperclip would have been a removal). | `ChatPanel.vue`, `RelationshipThreadView.vue`                                                | `ChatPanel.native.spec.ts`, `RelationshipThreadView.native.spec.ts`; forced-false fails 8, forced-true fails 5                                  |
| **U5** | Onboarding drops the native hamburger **and** its drawer (it held only the locale switcher).                                                                                                                                                              | `OnboardingLayout.vue`                                                                       | `OnboardingLayout.native.spec.ts`. Vuetify renders a closed drawer's content (`inert`), so the pin is that the nodes are **absent**, not closed |
| **U2** | The sign-in becomes the Figma sheet (frame 02 / 02b), and the iOS splash becomes frame 01 (§8.2).                                                                                                                                                         | `AuthLayout.vue` + `authNative.css`, `SignInPage.vue` + `signInNative.css`, `nativeSheet.ts` | `AuthLayout.native.spec.ts`, `SignInPage.native.spec.ts`, `native-page-css-scoping.spec.ts` (both new sheets), `appIconAssets.spec.ts`          |

### 8.2 U2 in detail

- **Sheet:** brand mark (150px, 84px once the error banner has made the sheet taller) above a full-bleed
  sheet pinned to the bottom, 24px top corners, the 2px aurora hairline inset 24px, the language control as a
  pill with a globe, the hero headline gone, labels above the fields, field icons, "forgot password" in the
  password label row, the error as a tinted banner with the credentials outlined (not for the two-factor
  prompt), a 44px primary button, and the pointer line under a hairline with the site in the accent colour.
  The mark sits above the sheet by `bottom: 100%`, so it follows the sheet as it grows.
- **New tokens:** `--native-type-h1` (500 26/32) and `--native-radius-sheet` (24px), in `native.css` and kept in
  lockstep by `native.spec.ts`. **New string:** `auth.ui.native.sign_in_subtitle` ×24 (written, not
  machine-checked by a native speaker — the `fr` and `it` lines are the ones worth a glance).
- **`AuthLayout.vue` is at 247 lines**; the structural-shell cap rose 240 → 250 with a dated note in
  `auth-layout-shape.spec.ts`. The CSS lives in sibling files, each rooted at a class only the app renders; the
  scoping spec now also allows `${root}::before` (the glow band's placement on the layout root).
- **iOS splash:** Figma frame 01 is committed as `assets/source/splash-frame.png` (390×844 at 4×) and
  `app-icon-sources.mjs` places it at full height, centred, on the 2732 canvas. The storyboard fills the screen
  with the square image, so a 19.5:9 phone shows exactly that strip. **Android is unchanged (Q8):** a second
  `capacitor-assets` pass reads `assets/android-splash/` (the AH-100b icon-on-colour splash), and the generated
  Android drawables came out **byte-identical** to before (git shows no Android diff). The asset spec pins the
  placement, determinism and the Android splash.

### 8.3 Deviations from the frames, and findings

- **No "Create an account" button (Q7).** The pointer line carries it; a hairline stands where the divider is.
  The "New to Catalyst?" divider label is not drawn separately because the existing sentence already begins
  with it. The button returns with real in-app sign-up (AH-104b).
- **"Forgot password?" is the existing "Reset your password" string**, to avoid a new string ×24.
- **No placeholders and no show/hide-password eye** (the frames draw both). The placeholders need strings; the
  eye needs an accessible name ×24. Named follow-up.
- **The glow is the layout's aurora band, re-placed and dimmed**, not the frames' blurred mask. It reads close;
  it is not identical.
- **Error copy is the existing code-keyed text**, not the frame's sample sentence.
- **Field text stays 16px** (the AH-100c deviation).
- **Finding, not changed:** Vuetify 3.12 hard-codes `border-radius: 4px` on `.v-field`, so `native.css`'s
  `--v-field-border-radius: 8px` never reaches it — **every field in the app is 4px, not Figma's 8px**. The
  sign-in sheet sets the radius on the field itself; the rest of the app is untouched (changing it re-skins
  every screen and wants its own look). It is the same shape as AH-100d's button radius.
- **Locale switching is lost mid-wizard on native (Q10).** It remains at sign-in's language pill; a locale row in
  Settings is the named follow-up.
- **A hard edge on a splash wider than 19.5:9** (an iPhone SE, an iPad): the strip's sides meet the flat canvas.
  Left as drawn; look at it on the walk.

### 8.4 Gates, and what is not proven

- `apps/main` vitest **217 files / 2207 tests pass**; `packages/design-tokens` 42 pass (1 todo); `vue-tsc`,
  eslint on the changed files and prettier clean; `vite build` succeeds. Backend suites unchanged by Half B.
- **Playwright did not run locally** (the config refuses to reuse :8000/:5173, which are Pedram's dev servers); it
  **ran green in CI** at the pushed tip (run `38024623651`).
- **Visual smoke, not a device:** I rendered the sign-in sheet in a 390×844 browser view with the Capacitor
  bridge faked. It matched frame 02 closely (pill, mark, hairline, labels, field radius, button, pointer line).
  That is **not** the WKWebView/WebView, the native theme colours (the mint was the web theme's), the safe
  areas or the keyboard.
- **Not walked at all:** U3 (needs the tab bar and keyboard), the error state's banner, the iOS splash on a
  device. **A new iOS splash needs a clean build and often an app delete/reinstall** — iOS caches launch
  screens. No new plugin, so no `cap sync` is needed for this half.

**Pedram's walk, in order:** (1) bell: open it, tap "View all" — the menu closes. (2) A relationship thread: the
composer sits above the tab bar, with and without the keyboard. (3) A campaign chat: one tap on the paperclip
opens the picker. A relationship thread: paperclip and link both inline; the link opens its dialog. (4)
Onboarding: no hamburger. (5) Sign in: the sheet, the language pill opens the 24-language menu, a wrong
password shows the banner and shrinks the mark, the keyboard doesn't bury the button, a short phone doesn't put
the mark over the pill. (6) Cold start on iOS: the splash; then an iPhone SE-class device if you have one.

---

## Addendum (post-close, 2026-10-10) — correction from AH-102

AH-101 claimed that adding `push` would be **"one column constant plus one enum case"** with **"no page change"**
(the AH-101 plan, and the comment above `PREFERENCE_CHANNEL_ORDER` in `templates.ts`). **That is not true.** Push is
native-only, so the matrix must be able to hide the column on web: `preferenceGroupsForRole()` gains an
`includeNativeOnly` option (default `false`, so the existing web render and the AH-101 specs are unchanged), the page
passes a platform predicate, and a native-only state row ("Push is off on this device — Enable") is added. The page
therefore **does** change, in AH-102 Stage 3. The AH-101 chunk itself is unaffected: it shipped no push column and no
push enum case. See [`push-notifications-plan.md`](push-notifications-plan.md) §4.5.
