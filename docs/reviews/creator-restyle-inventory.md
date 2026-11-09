# AH-115 — creator web adopts the native look: INVENTORY

> **Status:** inventory **ACCEPTED by Pedram, 2026-11-09**, rulings Q1 to Q11 in §13.
> **Mode:** A (full loop, in layers). Read-only: this file is the only thing written.
> **Baseline:** `origin/main` = `5aa4c6b4`. Production: API and both SPAs at `6d7a9c28` (2026-11-06), native build 4 on TestFlight, the AH-118 and AH-119 flags ON (reported).
> **Plan:** [`creator-restyle-plan.md`](creator-restyle-plan.md).
> **Goal (Pedram):** the creator web pages (`CreatorDashboardLayout` and every creator route, `OnboardingLayout`, `AuthLayout` on web) render with the design system the native app uses, so creator web and the phone app are one product. The native render stays as it is unless a frame says otherwise. Includes the parked AH-115 items: the Notifications page layout and creator inbox v2 (applies to native too, same code).
> **Constraints that flip from AH-114:** the agency shell stays byte-identical; the deliberate changes to hash-pinned shared files and to the native polarity specs are re-pinned on purpose, each with its reason. Native build 5 and a device walk follow.

**Evidence labels.** `READ` = file and line or a tool result. `MEASURED` = a number a tool returned. `INFERRED` = follows from the above, not run.

**PROD-DATA RISK: NONE** for the restyle (front end only; no API, migration, env or flag). **NONE / LOW** for inbox v2: two read endpoints; LOW only if an additive `creator_id` index migration turns out to be needed (§11).

---

## 0. Findings that changed the brief

1. **Figma file `pakeokB5NLH7hN1zCRLpfs` has no creator frames** (READ, Figma metadata tool). Its pages are Cover, Foundations, Components, Patterns and "Restyled screens"; the last holds the six **agency** desktop frames (Dashboard, Campaigns, Campaign detail, Discover, Messages x2), all 1600 wide. The creator frames are in **`1Dd0NdZ55EXh58uNyvf9Bi`** (ruling Q1: read read-only; done, §0.1).
2. **Web sign-in is the public marketing front door and agency users use it too** (READ, `AuthLayout.vue`, `marketingTail.ts`, `nativeSheet.ts`). `/agency/sign-in` is the same page and layout; 2FA, forgot and reset password and `/accept-invitation` are shared by both roles and the route cannot say whose they are. Rulings Q2 and Q3 settle this (§13).
3. **There is no committed agency baseline** (READ): `ah-114-creator-baseline/baseline.json` is the only DOM, style and theme-sheet hash baseline; `walk-l4.mjs` records rule markers, not hashes. The agency byte-identical proof needs a new baseline recorded before any change.
4. **A stored `light` theme is probably not applied at load on creator web** (READ): `useThemePreference()` is called only in `AgencyLayout.vue:51` and inside `ThemeToggle` (the lazy user menu). AH-114 inventory §1.6.
5. **Native has the same Roboto fallback** (READ): `native.css` sets Inter on `.v-application` only; the `.text-*` utilities and teleported overlays fall back to the system sans. Fixing it inside the creator scope changes the native render (ruling Q8: do it, record it as a native deviation).

### 0.1 The native Figma file (`1Dd0NdZ55EXh58uNyvf9Bi`), read-only (READ)

Two pages: "Creator app – restyled" and "Components". **Every frame is a 390 px phone frame; there is no desktop or tablet frame.** Frames: 01 Welcome back, 01 Splash, 02 Profile basics (empty, filled), 04 and 05 Social and portfolio, 06 / 06b Master agreement, 06c Review and submit, 07 Dashboard, 08 Dashboard with the More menu, 09 Notifications, 10 Campaign invitations, 11 Messages (empty), 12 Availability, 13 Edit profile, 14 Job posts (empty), 02 Sign in, 02b Sign in error, App Icon. Components: Button (Primary, Secondary, Soft, Ghost, Danger; Md 36, Lg 44), Status pill (five tones), Input (empty, filled, focus, error), Checkbox, Choice chip, Logo.

**Consequences:**

- No frame exists for: assignment detail, job detail, jobs list with data, Security, notification preferences, Profile (view), the KYC, tax and payout onboarding steps, a Messages thread, a Messages list with rows, the web desktop shell, or any auth screen but sign-in. Those render by the native token and component rules (inference), as they do in the app today.
- The desktop layout of creator web is **not designed**. Ruling Q5 fixes it by rule: topbar nav at md and up with native tokens, bottom tabs below.
- The agency desktop file's component specs differ from the native file's (agency Input 40 px, native 46 px). The creator scope uses the **native** numbers.

---

## 1. How the native look is applied today (READ)

| Layer                       | Where                                                                | What it does                                                                                                                                                                                                                                    |
| --------------------------- | -------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Probe                       | `core/platform/isNativePlatform.ts`                                  | Wraps `Capacitor.isNativePlatform()`. About 45 non-spec files read it.                                                                                                                                                                          |
| Theme                       | `plugins/vuetify.ts:44-63`                                           | `dark: isNative ? nativeDark : dark`; the button style becomes `--native-radius-lg`, then `native.css` forces 8 px with `!important` (the inline radius loses to `.rounded-0`). `light` stays the web light theme.                              |
| Root class                  | `core/platform/bootNativeShell.ts:35`                                | Adds `catalyst-native` and `platform-*` to `<html>`, sets `viewport-fit=cover`, dynamic-imports `native.css`.                                                                                                                                   |
| `native.css`                | `packages/design-tokens/native.css`, 41 `.catalyst-native` selectors | The `--native-*` tokens (hard-coded dark), the type scale, and Vuetify component rules. Every rule is rooted at `.catalyst-native`.                                                                                                             |
| Page sheets                 | 7 files, 3,058 lines                                                 | Rooted at DOM modifier classes (`.creator-main--native`, `.onboarding-native__body`, `.auth-layout--native`, `.sign-in-native`, `.sign-up-native`) that exist only because of `isNative` bindings. Pinned by `native-page-css-scoping.spec.ts`. |
| Global rules in shared SFCs | `AuthLayout.vue:243`, `RelationshipThreadView.vue:805`               | `:global(.catalyst-native …)`.                                                                                                                                                                                                                  |

**Most of the gate is DOM structure, not CSS** (bottom sheet versus dialog, strength card versus `CompletenessBar`, agenda calendar versus month grid, native notification rows). Widening `.catalyst-native` alone restyles nothing structural.

**The gate sites split three ways:**

- **Behaviour, stays `isNativePlatform()`:** `core/api/index.ts`, `core/auth/tokenStore.ts`, `installMarker.ts`, `core/platform/appResume.ts`, `core/platform/push/*`, `badge/iconBadge.ts`, `links/startAppLinks.ts`, the `useAuthStore` bearer, wrong-door and lapse logic, `pushChannelAvailable`, the push rows in `NotificationPreferencesPage`, the "I've verified" button and Delete link on `EmailVerificationPendingPage`, `marketingTail.ts` and `nativeSheet.ts` for the agency routes.
- **Look, widen:** `plugins/vuetify.ts`, the `bootNativeShell` CSS load, `CreatorDashboardLayout`, `OnboardingLayout`, `CreatorDashboardPage`, `CreatorAssignmentsPage`, `CreatorAssignmentDetailPage`, `CreatorJobDetailPage`, `SecurityPage`, the availability page, block dialog and native calendar, `DeleteAccountDialog`, `NotificationCenter`, `RelationshipInbox`, `NativeCard`, `WelcomeBackPage`, `SignUpPage`, `AuthLayout`.
- **Mixed (look plus behaviour):** `ChatPanel`, `RelationshipThreadView`, `ImageLightbox` (share sheet), `AvatarUploadDrop`, `PortfolioUploadGrid`, `ConnectionsSocialSection` (tap versus click copy).

## 2. The cheapest way to use the theme without the gates

**Chosen (rulings Q5, Q9): a creator-shell theme class with runtime registration, plus a shell-scoped look probe.**

- Register `creator-dark` and `creator-light` Vuetify themes at runtime from the creator, onboarding and auth layouts' `setup()`, and unregister on unmount (the mirror of `registerAgencyThemes` / `unregisterAgencyThemes`). The agency session then never has them: its theme `<style>` bytes stay identical (ruling Q9).
- `creator-dark` is `nativeDarkTheme`. Vuetify puts the theme class on the root and on every teleported overlay, which is how agency.css reaches overlays.
- Widen the `native.css` selectors to `:is(.catalyst-native, .v-theme--creator-dark, .v-theme--creator-light)` and move the canvas rule to `:root:has(.v-application.v-theme--creator-*)`.
- Replace the look gates with `useNativeLook()` = `isNativePlatform() || inject(CREATOR_LOOK, false)`. The creator layouts provide `CREATOR_LOOK`; the agency layout never does, so shared components keep their current DOM in the agency.
- Native keeps the `<html>` class path and the `nativeDarkTheme` swap, so its render is untouched except for the recorded Roboto deviation.

**Rejected:** widening `.catalyst-native` onto `<html>` on web (restyles the agency under the same `<html>`; depends on a route-time toggle never leaking across creator, agency and impersonation switches); dropping the `isNative` ternary in `buildVuetifyOptions` (agency render identical, but the inert base `dark` and `light` sections of the theme `<style>` change, so the agency is not byte-identical at source level).

## 3. Light mode (ruling Q4)

- Native is dark-only: `native.css` hard-codes dark hex under `color-scheme: dark`; the page sheets read only `--native-*` and contain no literals (pinned).
- **Derive light.** One remap block under `.v-theme--creator-light` covers every sheet. The values exist in `agencyThemes.ts` (`AGENCY_LIGHT_COLORS` and its AA-lifted text colours). Creator code must not import `modules/agency/` (spec 2), so the creator module duplicates them and a parity spec asserts equality with the agency values.
- **Fix the stored-preference load** in the creator layouts (`useThemePreference()` in setup). Consequence, stated: a creator-web user who stored `light` and today sees dark at load will see light.
- **Native stays dark**, with no toggle.
- Open tech-debt: the light logo asset.

## 4. Per-route gap, web today versus native render (INFERRED from gate counts, sheet coverage and the frames; nothing was rendered)

Gap: **L** different structure, **M** partly different, **S** paint and tokens only.

| Route (web)                                                                                            | Native owner and frame                                                                            | Web today                                 | Gap                                  |
| ------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------- | ----------------------------------------- | ------------------------------------ |
| `/creator/dashboard`                                                                                   | `CreatorDashboardPage`, `creatorDashboardNative.css`, frame 07, 08                                | `CompletenessBar`, a "ships soon" banner  | L                                    |
| `/creator/profile`                                                                                     | `CreatorProfilePage`, `creatorProfileFormNative.css`, frame 13                                    | web form; `NativeCard` renders no wrapper | M                                    |
| `/creator/availability`                                                                                | `AvailabilityCalendarNative`, frame 12                                                            | month grid and a dialog                   | L                                    |
| `/creator/assignments`                                                                                 | `CreatorAssignmentsPage`, frame 10 (5 gates)                                                      | one-line rows                             | L                                    |
| `/creator/assignments/:ulid`                                                                           | status card, bottom sheet (no frame)                                                              | dialog                                    | M                                    |
| `/creator/jobs`                                                                                        | `.creator-jobs` rules, frame 14                                                                   | sheet rules only                          | S-M                                  |
| `/creator/jobs/:ulid`                                                                                  | `.job-detail`, bottom sheet (no frame)                                                            | dialog                                    | M                                    |
| `/creator/notifications`                                                                               | `NotificationCenter` native rows, "caught up" footer, frame 09                                    | `v-card` 720 px                           | M                                    |
| `/creator/notifications/preferences`                                                                   | push rows (behaviour-gated)                                                                       | web                                       | S                                    |
| `/creator/security`                                                                                    | `.security-page--native`, block button (no frame)                                                 | web                                       | S                                    |
| `/creator/messages`, `/creator/messages/:agencyUlid`                                                   | `rel-inbox`, `chat-message`, thread height, frame 11                                              | two-pane at md and up                     | M, plus inbox v2                     |
| Onboarding x8 (`welcome-back`, `profile`, `connections`, `kyc`, `tax`, `payout`, `contract`, `review`) | `onboardingNative.css`, native header, account menu, frames 01 to 06c (none for kyc, tax, payout) | animated chrome, mobile drawer            | L                                    |
| AuthLayout x13 plus the bootstrap error page                                                           | sheet versus hero and tail, frames 02, 02b                                                        | marketing landing                         | L, ruled: tokens and components only |
| Bare `app` shell                                                                                       | none                                                                                              | base theme                                | not in scope (Q10)                   |

The shell is a gap too: native has bottom tabs at every width, web desktop has a topbar nav (ruling Q5).

## 5. Creator inbox v2

**Today (READ):**

- The creator inbox is `GET creators/me/relationship-threads` (`CreatorRelationshipMessageController::inbox`): agency rows only, no pagination, no filter.
- Assignment chats live inside `CreatorAssignmentDetailPage` through `ChatPanel`; there is no creator list of them.
- Agency endpoints: `GET agencies/{agency}/message-inbox` (`MessageInboxQuery`, an SQL union scoped to one `agency_id`, cursor on `(last_at, type, id)`) and `GET agencies/{agency}/message-search` (`MessageSearch`, same scoping).
- `features.messages_v2_inbox` is agency-only on `/me` (`UserResource`: `isAgencySide`); `features.messages_v2_edit_search` is for agency users and creators. Edit and delete already work for creators.

**Shape (rulings Q6, Q7):**

- `GET creators/me/message-inbox`, scoped by creator: the relationship leg on `relationship_threads.creator_id`, the assignment leg joined through `campaign_assignments.creator_id`; the same cursor, the same human-message surfacing rule (system lines never surface a thread), viewer-scoped unread. Per row: agency name and logo, the tag, preview and preview kind, `last_message_at`, `unread_count`.
- **Tag (Q7):** `Brand · Campaign` for assignment threads, `Direct` for relationship threads.
- `GET creators/me/message-search?q=&cursor=`: body-only match, `is_own`, the same Postgres GIN indexes (`2026_11_06_110000`, `110001`), scoped to the creator's threads across all agencies (a small set).
- A **creator variant** that shares the SQL pieces; the agency endpoints are not reused or changed.
- No facets, no Mine or All, no owner, no brand popover on the creator side.
- **Web:** the creator list rows come from `RelationshipInbox` (creator-only at runtime) with tag and search props. **Q6: assignment rows deep link** to `creator.assignment.detail?focus=messages` (the AH-103 link already exists); a thread route with a transport adapter is a later step.
- **Native impact:** same code, so build 5 gets it. Build 4 never calls the new endpoint and the old one is unchanged, so build 4 is safe. Order: API, web, build 5, flag.

## 6. The Notifications page

`NotificationsPage.vue` is one `v-card` (720 px) with no header. Native draws rows through `NotificationCenter`'s `isNative` branch with the "caught up" footer (frame 09). The page file and `NotificationCenter` are agency-rendered (via `AgencyNotificationBell`), so the creator layout takes a creator fork (`CreatorNotificationsPage`) or an inject-gated change; the agency keeps the pinned file untouched. Tech-debt row 6 closes with this.

## 7. Shared files touched and who renders them

The agency and admin columns come from an importer grep (READ), not a closure walk; the admin closure is to be proved in L0.

| Pinned file                                                                                                               | Agency renders it                                         | Admin imports it         | Treatment                                             |
| ------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- | ------------------------ | ----------------------------------------------------- |
| `RelationshipInbox`                                                                                                       | no (the fork mentions it in comments)                     | no                       | edit in place, re-pin                                 |
| `NotificationBell`                                                                                                        | no (`AgencyNotificationBell` is a fork)                   | no                       | edit in place, re-pin                                 |
| `PushStateRow`                                                                                                            | no (push is behaviour-gated, native only)                 | no                       | edit if needed, re-pin                                |
| `ContactPicker`, `ReportFallback`                                                                                         | yes (agency Messages page, `ChatPanel`)                   | no                       | `useNativeLook` default off, or leave and scope       |
| `ChatPanel`, `RelationshipThreadView`                                                                                     | yes (`CampaignMessagesPanel`, board drawer, `ChatDialog`) | no                       | inject-gated with flag-off pins as in AH-119, or fork |
| `NotificationCenter`, `NotificationsPage`, `NotificationPreferencesPage`                                                  | yes                                                       | no                       | creator fork or inject gate                           |
| `ThemeToggle`, `ImpersonationBanner`                                                                                      | yes                                                       | admin has its own copies | do not touch                                          |
| `RichBrief`                                                                                                               | yes                                                       | no                       | do not touch                                          |
| `CEmptyState`, `CMonthGrid`                                                                                               | yes                                                       | no                       | CSS-scope only                                        |
| `CategoryChips`, `CountryDisplay`, `LanguageList`, `SocialAccountList`, `CompletenessBar`, `PortfolioGallery`, `CKpiCard` | yes                                                       | **yes (admin SPA)**      | CSS-scope only; admin untouched                       |

## 8. Specs to edit, by name

- **Hash pins, re-pinned on purpose with a reason each:** `shared-components-frozen.spec.ts` (only the files actually edited, §7); `shared-tokens-frozen.spec.ts` (only `native.css`, `plugins/vuetify.ts`, `bootNativeShell.ts` if hashed, and any `native.ts` change; the `nativeDarkTheme` and `nativePalette` literals do not move, the agency palette derives from them).
- **Native polarity (the web side flips):** `tests/unit/plugins/vuetify.spec.ts`, `tests/unit/composables/useMobileShell.spec.ts`, `native-css-scoping`, `native-page-css-scoping`, `native-button-radius`, `native-field-text-size`, `native-tab-bar`, `native-detail-pages`, `native-invitation-actions`, `rel-thread-native-height`, `creator-profile-native-coverage`, `auth-layout-shape`; the web cases in `AuthLayout.native`, `SignInPage.native`, `SignUpPage.native`, `EmailVerificationPendingPage.native`, `OnboardingLayout.native`, `OnboardingLayout.accountMenu`, `WelcomeBackPage.native`, `DropzonePrompts.native`, `ConnectionsSocialSection.native`, `ConnectionsPortfolioSection.native`, `AvatarUploadDrop.native`, `CreatorDashboardPage.native`, `CreatorAssignmentsPage.native`, `NotificationCenter.native`, `RelationshipInbox.native`, `RelationshipThreadView.native` (pins), `ChatPanel.native` (pins), `AvailabilityCalendarNative`, `NativeCard`, `CreatorDashboardLayout` (lines 500, 517, 971), `SecurityPage`, `CreatorAssignmentDetailPage`, `CreatorJobDetailPage`, and `tests/unit/components/ThemeToggle.spec.ts` if the toggle is hidden on creator web.
- **Behaviour specs stay untouched:** `useAuthStore.*`, `transport-mode`, `tokenStore`, `installMarker`, `appResume`, the push specs.
- **Agency specs stay green and unedited:** `agency-theme-scoping`, `agency-theme-isolation`, `agency-color-system-parity`.
- **New, the AH-114 specs in the other direction:** creator stylesheet scoping, the agency-never-imports-creator-theme graph, an agency-frozen hash spec, an agency-tree-carries-no-creator-class spec, a creator-light parity spec against the agency palette.

## 9. Playwright and walks

- The creator walk (142 targets) moves for every creator, onboarding and auth target; it is re-baselined deliberately at close-out.
- E2E-traversed creator surfaces: `creator-shell-mobile`, `creator-dashboard`, `creator-jobs-board`, `creator-wizard-happy-path`, `creator-signup-consent`, `creator-messages-badge`, `creator-campaign-status`, `creator-connection-requests`, `notification-deep-link`; also `2fa-enrollment-and-sign-in`, `failed-login-lockout-and-reset`, `jobs-board-full-lifecycle`, `hand-off-at-approval-lifecycle`, `invitations*`, `smoke`, `permissions`.
- New: creator inbox v2 with search, and a creator Notifications spec. The full suite runs before any push.
- Native build 5 needs a device walk; no walk proves it.

## 10. i18n

Reusable: `app.messaging.inbox.tagDirect`, `tagCampaign`, `loadMore`. Not reusable: the agency search copy ("Search creators or messages"). New keys and the web wording of the native tap-copy strings go to the plan for approval; every new key in all 24 locales with real MT in the flaky ten.

## 11. PROD-DATA RISK

- Restyle: **NONE**.
- Inbox v2: **NONE** for the two read endpoints. **LOW** if an additive index is needed: `relationship_threads` has a unique index on `(agency_id, creator_id)` and no leading `creator_id` index (the existing creator inbox already filters that way; the table is small); the assignment leg uses `idx_assignments_creator_status`. Any index is schema only, additive, and gets its own read (the plan's index step).

## 12. Flags

One new flag, default OFF: `messages_v2_creator_inbox_enabled` (ruled in the plan request). Exposed as a creator `features.*` entry on `/me`; both endpoints answer 404 while it is off; UI gate only. `messages_v2_inbox_enabled` is not reused (already ON, it would switch creators on at web deploy). The restyle has no flag: rollback is redeploying the previous `dist`; native rollback is a new build.

## 13. Rulings (Pedram, 2026-11-09)

| Q   | Ruling                                                                                                                                     |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Q1  | Yes: read `1Dd0NdZ55EXh58uNyvf9Bi` read-only. Done, §0.1.                                                                                  |
| Q2  | Shared auth screens (sign-in, sign-up, 2FA, forgot, reset, invitation accept): **tokens and components only**; keep the hero and the tail. |
| Q3  | `/agency/sign-in` stays **byte-identical**.                                                                                                |
| Q4  | Derive light; fix the stored-preference load in the creator layouts; native stays dark.                                                    |
| Q5  | Topbar nav at md and up with native tokens; bottom tabs below.                                                                             |
| Q6  | Inbox v2 assignment rows deep link first.                                                                                                  |
| Q7  | Tag is `Brand · Campaign`; `Direct` for agency (relationship) threads.                                                                     |
| Q8  | Fix Roboto inside the creator scope; recorded as a native deviation.                                                                       |
| Q9  | Agency byte-identical means DOM, computed styles **and** the Vuetify theme `<style>` bytes; runtime theme registration.                    |
| Q10 | The bare `app` shell is out of scope.                                                                                                      |
| Q11 | Commit this inventory as a docs file.                                                                                                      |

**A tension the plan resolves, not a ruling:** Q2 and Q3 put the shared auth pages under the creator look while `/agency/sign-in` and the agency-side use of the shared 2FA and reset screens must not move. The route cannot say whose a 2FA or reset screen is, so the plan scopes the creator look by **route name** (creator-reachable routes only) and leaves the screens an agency user can reach unchanged unless Pedram rules otherwise (plan §8).
