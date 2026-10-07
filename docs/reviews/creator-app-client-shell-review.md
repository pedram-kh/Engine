# Creator app client, Capacitor wrap and native theme (chunk 2 of 7) — REVIEW

- **Status: Closed — approved.** Verdict (Pedram, 2026-10-07): D1–D7 and Q1–Q12 verified as
  ruled; the four-layer web-identical pin set accepted, with the bundle-versus-render honesty as the
  record; the 40-row break-revert table confirmed, including the S5-BR7 handling (test added, then
  red) as the model for survived break-reverts; the 0-removed / 74-added CSS diff accepted; the
  availability pure move proven by the untouched spec; the iOS simulator smoke accepted as the
  known-good base for Pedram's walkthrough; Android build-only recorded honestly; the Playwright
  harness-error disclosure accepted. Close condition recorded with the verdict: CI green at the
  pushed tip (§5.41), with retries of the known-flake pair judged against the `d1ab5820` baseline;
  any other red is diagnosed.
- **Scope:** built S1–S7 as cleared. S8 (the designed-page Figma passes and the remaining bottom
  sheets) is split off as **AH-100b** (Q4).
- **Provenance:** closed 2026-10-07 on Pedram's verdict; pushed the same day on his clearance.
- **Commits:** the feat + docs pair, pushed together as `d1ab5820..cf69f97b`: `75ce384d` (feat) ·
  `cf69f97b` (docs — this review, the AH-100 log entry, the deploy-log PENDING entry, the
  tech-debt entries, the Part 2 refresh). A third, docs-only provenance commit (the one that wrote
  this paragraph) follows them.
- **CI at the pushed tip (§5.41):** **green — all four jobs** (Backend Pint+Larastan+Pest,
  Frontend lint+typecheck+unit, E2E main SPA Playwright, E2E admin SPA Playwright) —
  [run `37561535504`](https://github.com/pedram-kh/Engine/actions/runs/37561535504) at `cf69f97b`,
  after `git rev-list --left-right --count origin/main...HEAD` → `0 0` (`origin/main` and `HEAD`
  both `cf69f97ba800b046d01b5654c45b318daefe3023`).
  - **Two CI-level flaky retries, both the known pre-existing pair.** Main:
    `creator-wizard-happy-path.spec.ts` (32 passed + 1 flaky); admin: `admin-sign-in.spec.ts`, the
    TOTP sign-in spec (1 passed + 1 flaky). Each failed its first attempt and passed on its single
    retry, the same shape as the pre-AH-099 baseline run
    ([run `37393941985`](https://github.com/pedram-kh/Engine/actions/runs/37393941985)) cited in the
    AH-099 review, and the same pair this chunk's local runs reproduced on `d1ab5820` (§8). No other
    spec flaked or failed, so nothing needed diagnosing.
- **Date:** 2026-10-06
- **Entry:** AH-100.
- **Built from:** the kickoff's D1–D7 and the D4 Figma addendum, the plan-pause rulings Q1–Q12,
  [`creator-app-inventory.md`](creator-app-inventory.md) §M1 / §M2.5 / §M3 / §M6, and the AH-099
  contract as shipped ([`creator-app-token-auth-review.md`](creator-app-token-auth-review.md)).
- **Base:** `d1ab5820` (= `origin/main` at kickoff). **Commits:** proposed at package time (see §10).
- **Reviewed against:** `docs/02-CONVENTIONS.md`, `docs/01-UI-UX.md`, `docs/07-TESTING.md`,
  `docs/tech-debt.md`.

---

## §0. What shipped, in one paragraph

The main SPA can now run inside a Capacitor shell as the **Catalyst creator app**
(`com.catalystgrowth.platform`, iOS + Android, Capacitor 8.5.2). Inside the shell the same client
authenticates with a **bearer token** from AH-099's `POST /auth/token` instead of a cookie
session; the token lives in the iOS Keychain / Android Keystore (`@aparajita/capacitor-secure-storage`
8.0.1), is cleared at logout and on any terminal 401, and a 401 routes to the app's own sign-in
screen. The app renders a **native dark theme** built from the Figma token set, a bottom-tab shell
**because it is native, not because it is narrow**, a native onboarding header, and a compact
availability month grid with an agenda list underneath. On the web **nothing renders differently**:
every native path is gated on one platform probe that reports `false` in a browser, and that is
pinned four ways (§2). The app does not deploy; it builds locally (§9).

---

## §1. Production posture

**PROD-DATA RISK: LOW.** No backend change, no migration, no existing row touched.

- **The web risk surface** is the one the kickoff named: the `@catalyst/api-client` transport gains a
  bearer mode the web build never configures, and the two layouts gain a platform input. Both are
  pinned with break-reverts (§2, §6).
- **What the app writes, once it points at production:** each successful sign-in **inserts one
  `personal_access_tokens` row**, and each bearer request updates `last_used_at`. Both are additive,
  revocable and AH-099's designed behaviour (its tech-debt entry covers the write-per-request).
- **The default app build points at the local API** (`.env.native-development`). Only a deliberate
  `build:native` with Pedram's uncommitted `.env.native-production` reaches production — and that is
  pointless until AH-099's PENDING deploy lands (§9).
- **No flag.** The bearer path has no web caller; the app is the only caller and it is not in any store.

---

## §2. Review priority 1 — the web renders byte-identically

The honest form of the claim: **the claim is that web renders byte-identically, not that its bundle is byte-identical.** The web JavaScript does change — the api-client code, the predicates, and `@capacitor/core`'s probe now ship in it. What does not change is what a browser renders or how it talks to the API. Four independent pins:

| Layer        | Pin                                                                                                                                                                                                                                                                                     | Break-revert                                                                                                   |
| ------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Transport    | `http.spec.ts`: with `auth` absent (or `{mode:'cookie'}`) the client issues the CSRF preflight, reads `XSRF-TOKEN`, sends `X-XSRF-TOKEN`, `withCredentials: true`, and **no** `Authorization` header. `transport-mode.spec.ts`: the web build passes only `baseUrl` + `onUnauthorized`. | S1-BR1 (bearer header leaks into cookie mode) → 3 red; S2-BR1 (web wired to bearer) → 1 red                    |
| Theme config | `vuetify.spec.ts`: `buildVuetifyOptions(false)` **deep-equals the literal config at `d1ab5820`**.                                                                                                                                                                                       | S4-BR1 (web `dark` slot → native palette), S4-BR2 (web VBtn radius) → red                                      |
| Native CSS   | `native-css-scoping.spec.ts`: every selector in `design-tokens/native.css` starts with `.catalyst-native`; no at-rules. `bootNativeShell.spec.ts`: on web no class is added, no CSS imported, no SystemBars call.                                                                       | S4-BR3 (unscoped `body{}`), S4-BR4 (`@media`), S4-BR5r2 (drop the web guard) → red                             |
| Layouts      | `CreatorDashboardLayout.spec.ts` + `OnboardingLayout.native.spec.ts`: web at desktop width shows the top bar / animated chrome; web at phone width shows the existing mobile shell with no app-only items; native at **desktop** width shows the bottom tabs / native header.           | S5-BR1 (`isMobile` drops `isNative \|\|`) → 4 red; S5-BR6/BR7 (app-only save button leaks to web mobile) → red |

**The built-output check (D7 (d)).** The web build was produced at `d1ab5820` (a worktree) and at
the working tree, with identical `VITE_*` values, and the emitted CSS compared after normalising
Vue's scope hashes:

- **0 rules removed, 74 added.** Every added rule is gated by a class only the native shell sets
  (`--native` modifiers, `.catalyst-native`, `.availability-native`, `.native-wizard-header`) or is
  `VBottomSheet`'s own stylesheet (now in the graph because the block dialog can render as a sheet).
- **The native token layer is not in the web's entry CSS.** It is a separate lazy chunk
  (`native-*.css`) that only `bootNativeShell` imports, and only on native.
- `index.html` differs only in hashed asset names.

**The web Playwright suite, untouched, is the behavioural proof:** main 33/33, admin 2/2 (§5).

---

## §3. Review priority 2 — the token lifecycle

- **Store** (`core/auth/tokenStore.ts`): `TokenStore { read, write, clear }` over a pluggable
  backend. Native backend: secure storage, Keychain access `whenUnlockedThisDeviceOnly` (never
  iCloud-synced), plugin loaded by **dynamic import**. `nativeTokenStore()` **throws off-native**,
  because the plugin's web fallback is `localStorage` and a bearer token must never land there
  (S2-BR4 red).
- **Read once per app-open,** then served from memory, so per-request `getToken()` never touches
  the Keychain (S2-BR5 red). An expired token reads as `null` and is cleared (S2-BR6 red). An
  unreadable item reads as "no token".
- **Written** only at token-login success (S3-BR7 red). **Cleared** at logout even when the revoke
  call fails (S3-BR5 red), on a `/me` 401 at bootstrap (S3-BR6 red), and by the native 401 policy
  on any other 401 (S2-BR3 red).
- **AH-103 compatibility (biometrics):** the transport knows only `getToken()`. A biometric gate is a
  decorator implementing the same three methods around `read()`; because of the once-per-app-open
  cache, the prompt fires once per open, not per request.
- **Native 401 policy** (`createNativeUnauthorizedPolicy`): exempts `/auth/token` (the
  wrong-password / MFA channel), `/me` (the cold-load probe; the store clears a rejected token
  itself) and `/auth/impersonation/status` (see deviation 2). Anything else: clear the token, clear
  the user, `router.push` to `auth.sign-in?reason=session_expired` — an in-app route, never a
  `window.location` jump. The web `createUnauthorizedPolicy` and its spec are untouched.

---

## §4. Review priority 3 — the D5 flow against the AH-099 contract as shipped

`SignInPage` gains a native branch; `useAuthStore.login()` dispatches to `loginWithToken()` on
native (S3-BR4 → 7 red). `platform` is sent from `nativePlatform()` (`ios` / `android`). Envelope
handling was written against the controller and `LoginFailureResponder` as they exist at
`d1ab5820`, not from memory:

| AH-099 response                                                   | App behaviour                                                                 |
| ----------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| `auth.mfa_required`                                               | Reveal the code field; resubmit with `mfa_code` (same inline reveal as web)   |
| `auth.mfa.invalid_code` / `rate_limited` / `enrollment_suspended` | Inline message (existing keys)                                                |
| `403 auth.wrong_spa`                                              | **New** app copy `auth.ui.native.wrong_door` (S3-BR3 red)                     |
| `auth.account_locked.suspended` / `.temporary`                    | Inline message, `temporary` with its minutes value                            |
| `auth.invalid_credentials`                                        | Inline message                                                                |
| Success, verified                                                 | Token stored; land on `onboarding.welcome-back` (same forwarding rule as web) |
| Success, unverified                                               | Verify-email pending page (same rule as web)                                  |

- **No Meta Pixel on native** (App Tracking Transparency; inventory §M3) — S3-BR1 red. Web unchanged.
- **No sign-up link in the app (Q8).** It is replaced by `auth.ui.native.create_account`, pointing
  creators to `platform.catalyst-growth.com` (S3-BR2 red). In-app account creation stays coupled to
  AH-104's in-app deletion.
- **i18n:** the two `auth.ui.native.*` keys, plus `availability.creatorNav.settings`,
  `availability.creatorNav.subtitles.*` and `availability.legend.today`, ×24 locales, real MT. A
  script asserted every non-`en` value differs from `en` (no English fallback, including the flaky
  10: bg, el, et, fi, ga, hu, lt, lv, mt, ro); parity and placeholder gates green.

---

## §5. Review priorities 4 and 5 — the availability swap and the fork predicates

**Availability (Q3: compact grid + agenda).** The fetch, timezone bucketing and month navigation
moved out of `AvailabilityCalendar.vue` into `useAvailabilityMonth()` as a **pure move**:
`AvailabilityCalendar.spec.ts` is **untouched** (`git diff --quiet`) and green, and S6-BR6
(collapse the occurrence key to the id) reddens three of its tests, so the moved code is still
guarded by the old spec. `AvailabilityCalendarNative.vue` reuses `CMonthGrid` (restyled with
`:deep()`, no `packages/ui` change) with hard/soft fills and dots, a legend, an Add button, and an
agenda `<ol>` of the displayed month's blocks by day. `AvailabilityPage` picks the shell on
`isNativePlatform()`. The block dialog renders as `VBottomSheet` on native, `VDialog` on web.

- **Same data, same rows:** the parity test feeds one fixture to both shells and asserts the web
  chip keys equal the native agenda keys (S6-BR3 red when the agenda drops timed entries).
- Padding days from adjacent months are marked on the grid but not listed (S6-BR2 red).

**Predicates.** `useMobileShell()` returns `isNative` and `isMobile = isNative || smAndDown`. Width
behaviour on web is exactly the old `smAndDown`; native gets the mobile shell at any width. Native
modifier classes are bound only when `isNative`. `OnboardingLayout`'s web chrome conditions are
textually back to pre-AH-100 apart from the `isMobile` source (a redundant `!isNative` was removed as
an equivalent mutant — the native header precedes it in the `v-if` chain).

---

## §6. Break-revert record

Every load-bearing gate was broken, the targeted spec run, and the file restored byte-identical
(sha256 checked before and after). **All 40 went red** on the first run except S5-BR7, which
survived because the drawer's content is lazy and untested; an open-drawer test was added and it
then went red.

| Step               | Breaks                                                                                                                                                                                                       | Result                                                   |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------- |
| S1 transport       | BR1 bearer header in cookie mode · BR2 preflight in bearer mode · BR3 cookie read in bearer mode · BR4 `withCredentials` in bearer mode · BR5 no bearer header                                               | 3 / 5 / 1 / 1 / 2 red                                    |
| S2 wiring + store  | BR1 web wired to bearer · BR2 `/auth/token` not exempt · BR3 policy keeps the token · BR4 store builds off-native · BR5 no per-open cache · BR6 no expiry check                                              | 1 / 2 / 1 / 1 / 2 / 2 red                                |
| S3 sign-in + store | BR1 Pixel on native · BR2 sign-up link in app · BR3 no wrong-door remap · BR4 no token dispatch · BR5 logout keeps token · BR6 `/me` 401 keeps token · BR7 token never written                               | 1 / 1 / 1 / 7 / 2 / 1 / 3 red                            |
| S4 theme           | BR1 web dark slot · BR2 web VBtn radius · BR3 unscoped selector · BR4 at-rule · BR5 web guard (redone twice against the final `bootNativeShell`) · BR6 CSS/TS accent drift                                   | 1 / 1 / 1 / 1 / 2 / 1 red                                |
| S5 shells          | BR1 width-only predicate · BR2 theme toggle unguarded · BR3 More subtitles reach web · BR4 native header forced off · BR5 segment fill off-by-one · BR6 inline save leaks to web · BR7 drawer save unguarded | 4 / 1 / 1 / 2 / 1 / 1 / 1 red (BR7 after the added test) |
| S6 availability    | BR1 hard/soft swapped · BR2 month filter · BR3 agenda drops timed · BR4 page swap off · BR5 dialog forced `VDialog` · BR6 key → id · BR7 day label loses weekday                                             | 1 / 1 / 3 / 1 / 1 / 6 / 2 red                            |
| S7 boot            | BR1 drop the SystemBars `.catch` · BR2 drop the SystemBars call                                                                                                                                              | 1 / 2 red                                                |

WCAG AA (normal text) holds on all 10 native critical pairs, pinned in `design-tokens/src/native.spec.ts`.

---

## §7. Review priority 6 — Figma faithfulness and deviations

The token contract is the plan-pause table (16 frames' variables, read via MCP). The native layer is
a **separate token set** (`design-tokens/src/native.ts` + `native.css`, lockstep-pinned), so no
existing token was overridden. On native, `buildVuetifyOptions(true)` swaps the `dark` theme's
values for the Figma palette and sets 8px button radius and 44/36px heights; the theme toggle is
hidden (Q5, Q6 — dark-only), so `useTheme`, `useThemePreference` and their admin mirrors are
untouched. Implemented against frames 01 (onboarding header), 07/08 (app bar, tabs, More menu) and
12 (availability). **Undesigned, derived from the tokens:** sign-in and 2FA entry, and the
availability agenda rows.

**Deviations (none silently applied):**

1. **Icons are Material Design Icons**, not the Figma SVGs (Q11).
2. **`/auth/impersonation/status` is exempt from the native 401 policy**, beyond the plan's two
   paths. It is the every-route banner probe that 401s for anonymous visitors by design (the web
   policy exempts it too); without the exemption the sign-in screen would bounce itself.
3. **The agenda's design is inferred.** Frame 12 draws only the list's empty state; populated rows
   follow the token set (day label, time range, block-type colour).
4. **The legend reuses `availability.blockType.{hard,soft}`**; only "Today" is a new key.
5. **The availability grid restyles `CMonthGrid` through `:deep()`** rather than adding a `dense`
   prop to `packages/ui` (52px cells versus web's 96px), keeping admin and roster untouched.
6. **The sign-in screen clears the notch with a global `.catalyst-native .auth-layout` rule** — found
   on the simulator, where the header sat under the Dynamic Island. CSS-only, so `AuthLayout` stays
   inside its 240-line shape cap.
7. **The SystemBars style call is fire-and-forget.** iOS ignores the config's `style`, so
   `bootNativeShell` calls `SystemBars.setStyle(Dark)` without awaiting it; a failing or unanswered
   call cannot block boot (S7-BR1/BR2).
8. **Wizard progress and percentages** come from the live flags and completeness data, so they will
   not always match frame 01's static "Step 2 of 5".
9. **Two S2 dependencies were pulled forward** (`@capacitor/core` and the secure-storage plugin)
   because the platform seam and the token store need them before S7.

**Supply-chain note (Q2).** `@aparajita/capacitor-secure-storage` 8.0.1 is community software with a
single maintainer, pinned exactly. It hard-depends on `@capacitor/app` 8.1.2, `@capacitor/keyboard`
8.0.6, `@capacitor/ios` and `@capacitor/android`. Ionic ships no official secure-storage plugin.

---

## §8. Review priority 7 — the board

- `lint:frontend` exit 0 (only the 2 pre-existing `v-html` warnings). `typecheck:frontend` exit 0.
- `test:frontend`: main **1848/1848**, admin 488/488, api-client 221/221, ui 43/43, design-tokens 40
  (+1 todo). Architecture suite 199/199.
- `format:check` flags 4 pre-existing, untouched files (`apps/admin/index.html`,
  `apps/main/index.html`, `docs/reviews/sprint-4-chunk-2-review.md`, `scripts/i18n/verify-locale.mjs`).
- The `http.spec` `ECONNREFUSED` stderr line is pre-existing (one occurrence at the base, one now).
- **iOS:** `xcodebuild` Debug for the simulator succeeds; smoke on the iPhone 17 Pro (iOS 26)
  simulator reaches sign-in on the native path (`html.class = catalyst-native platform-ios`, URL
  `capacitor://localhost/sign-in`, no JS errors, light status-bar content, header clear of the
  island, the create-account pointer in place of sign-up).
- **Android:** `:app:assembleDebug` succeeds under JDK 21; the merged debug manifest carries the
  network security config and the release manifest does not. **Verified build-only:** the x86_64
  emulator never finished booting, so the Android visual check is the walkthrough's.
- **Full Playwright, dev stack down, isolated `catalyst_e2e`, main then admin sequentially:**
  - main **33/33**. One earlier full run had 1 failure, `creator-wizard-happy-path` (the Welcome Back
    Continue click did not navigate within 10s under parallel load); it passed 3/3 alone, and it
    is the same spec CI already retries as a known flake (AH-099 review header).
  - admin **2/2** on a warm run. Cold runs fail one of the two specs by a few seconds (the page
    snapshot already shows the signed-in admin shell). **Pre-existing:** the `d1ab5820` worktree
    fails the same specs the same way (2 red, then 1 red), and `admin-sign-in` is the spec CI
    already retries as a known flake.
  - An earlier 32-red main run was **my harness, not the product**: the agent's shell still exported
    the CSS-diff build's placeholder `VITE_API_BASE_URL`, which Playwright's dev server inherited.
- **CI at the pushed tip (§5.41):** green, all four jobs —
  [run `37561535504`](https://github.com/pedram-kh/Engine/actions/runs/37561535504) at `cf69f97b`
  (the known flake pair retried once each; see the header).

---

## §9. Deploy disposition and the simulator / device walkthrough

**Web deploy:** an `apps/main` SPA rebuild only (the api-client code rides along, dead for web). No
`apps/api` diff, no migration, no PHP-FPM reload, no worker restart. **The app does not deploy.** A
production app build is pointless until AH-099's PENDING deploy lands, because the token door does
not exist on production yet.

### Walkthrough (Pedram's eyes-on instrument for this chunk)

**1. Toolchain.**

```bash
node -v            # 22.x
pnpm -v            # 10.x
xcodebuild -version  # Xcode 26.x (Capacitor 8 needs it)
xcrun simctl list runtimes | grep iOS
/usr/libexec/java_home -V   # needs a JDK 21 — see below
```

- **JDK 21 is required for Android.** The Capacitor 8 template's Gradle 8.14.3 cannot run on JDK 25,
  and both the system JDK and Android Studio's bundled JBR on this Mac are 25. Either
  `brew install openjdk@21`, or in Android Studio set _Settings → Build, Execution, Deployment →
  Build Tools → Gradle → Gradle JDK_ to a 21.
- **Android SDK:** `export ANDROID_HOME=$HOME/Library/Android/sdk` and
  `export PATH=$ANDROID_HOME/platform-tools:$PATH` (so `adb` resolves).

**2. Local API settings (Pedram edits `apps/api/.env`; the agent never does).**

```dotenv
FRONTEND_EXTRA_ORIGINS=capacitor://localhost,https://localhost
# The .env.example default minus its first, bare `localhost` entry.
SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1,127.0.0.1:5173,127.0.0.1:5174
```

Without the second edit, Android's `https://localhost` origin is treated as stateful and every bearer
`POST` returns 419 (the AH-099 trap). Then `php artisan config:clear` and start the stack as usual
(`docker compose up -d`, `php artisan serve` on port 8000).

**3. Build and sync.**

```bash
pnpm --filter @catalyst/main build:native:dev
cd apps/main && pnpm cap sync
```

**4. iOS.** `pnpm cap open ios` → pick an iPhone simulator → Run. **The first launch after a cold
simulator boot can stay black for 25–30s** (WebKit cold start); later launches are quick.

**5. Android.** `pnpm cap open android` → let Gradle sync on JDK 21 → start an emulator or plug in a
device, then:

```bash
adb reverse tcp:8000 tcp:8000
```

→ Run. (`adb reverse` makes `localhost:8000` on the device reach the Mac, so one dev build serves
both platforms. Cleartext to `localhost` / `10.0.2.2` is allowed only in debug builds.)

**6. Sign-in walkthrough** (on each platform):

1. App opens on **sign-in**, dark theme, header clear of the notch, a "create your account at
   platform.catalyst-growth.com" line instead of a sign-up link.
2. Wrong password → inline invalid-credentials message.
3. **Wrong door:** sign in as an agency user → the app's wrong-door message.
4. A creator with 2FA → the code field appears → enter a TOTP → signed in.
5. A creator without 2FA → lands on Welcome Back (onboarding) or the dashboard if approved; bottom
   tabs, More menu with subtitles and a Settings entry, no theme toggle.
6. **Bootstrap:** force-quit and reopen → you stay signed in (token read from the Keychain/Keystore).
7. **Logout** from the avatar menu → back to sign-in; reopen → still signed out.
8. **Dead token:** sign in, then revoke server-side
   (`php artisan tinker` → `\App\Modules\Identity\Models\User::where('email', '…')->first()->tokens()->delete()`),
   tap any tab → back to sign-in with the session-expired notice.
9. Availability tab: compact grid, agenda list, Add opens a bottom sheet.

**Production build (later, after AH-099 deploys):** create `apps/main/.env.native-production` (never
committed, already gitignored) with `VITE_APP_NAME=Catalyst`,
`VITE_API_BASE_URL=https://<production API host>/api/v1`, `VITE_SENTRY_DSN_MAIN=…`, then
`pnpm --filter @catalyst/main build:native && cd apps/main && pnpm cap sync`.

---

## §10. Proposed commit shape (push held)

The house pair:

1. **feat** — `apps/main` (shell, transport wiring, store, sign-in, layouts, availability, theme
   plugin, i18n ×24, Capacitor config, the generated `ios/` and `android/` source projects (76 files,
   no build products), mode file, exclusions), `packages/api-client`, `packages/design-tokens`,
   `.gitignore`, `.prettierignore`, `pnpm-lock.yaml`.
2. **docs** — this review, the AH-100 log entry, the deploy-log entry, the tech-debt entries, the
   Part 2 refresh (including the stale AH-098 "PUSH HELD" line).

Then the CI-at-tip line in §8 is filled in a docs-only provenance commit, as with AH-099.
