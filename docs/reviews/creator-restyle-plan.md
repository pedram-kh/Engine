# AH-115 plan: creator web adopts the native look

**Status:** DRAFT for plan review. No code written.
**Mode:** A (full loop, alarm rule, break-revert, 24-locale i18n gate, docs move with the build).
**Inventory:** [`creator-restyle-inventory.md`](./creator-restyle-inventory.md) (accepted, rulings Q1 to Q11 in its §13).
**Baseline:** `origin/main` = `9a0be3f3` (inventory commit). Production: API + both SPAs at `6d7a9c28`, native build 4 on TestFlight, AH-118/AH-119 flags ON.
**PROD-DATA RISK:** NONE for the restyle (L0 to L3, L5). NONE for inbox v2 (L4) unless the index read in L4.1 says an additive `relationship_threads(creator_id)` index is needed; then LOW, schema only, its own read and its own deploy step.

Evidence labels: MEASURED (ran it), READ (read the code), INFERRED, NOT PROVED.

## Summary

Creator web gets the native design system by a **runtime-registered creator theme class**, not by widening `.catalyst-native`.

- `creator-dark` (= `nativeDarkTheme`) and `creator-light` (derived) register from the creator, onboarding and auth layouts' `setup()` and unregister on unmount. This mirrors `registerAgencyThemes` / `unregisterAgencyThemes`. (READ)
- `native.css` selectors widen to `:is(.catalyst-native, .v-theme--creator-dark, .v-theme--creator-light)`. Native keeps rendering as before because `.catalyst-native` stays in the list.
- `useNativeLook()` = `isNativePlatform() || inject(CREATOR_LOOK, false)`. Only the creator layouts provide `CREATOR_LOOK`. Behaviour gates (transport, tokens, push, resume) stay on `isNativePlatform`.
- The agency shell is never touched. It is proved byte-identical (DOM + computed styles + theme `<style>` bytes) by a baseline recorded **before any change** (L0).

Each layer ends with its own gate. A failed gate means break-revert of that layer, not a patch on top.

## L0 Guards first

Nothing in L1+ starts until L0 is green and pushed.

**L0.1 Agency baseline walk.** New `--set agency` in `apps/main/tools/walk/walk.mjs`: 24 agency routes, dark and light, two widths (desktop and 390), using the `l3-fixtures.mjs` mock-API fixtures. Records DOM hash, computed-style hash, theme `<style>` hash and thumbnails into `docs/reviews/ah-115-agency-baseline/` (+ README with the Apple-silicon note, as AH-114 did). Recorded on the pristine tree, committed and pushed before L0.2. Compare mode must report 0 diffs on the same tree (two runs back to back) before it is trusted. If it is flaky, fix the walk, not the tolerance.

**L0.2 Agency-frozen hash spec.** `tests/unit/agency-frozen.spec.ts` pins the hashes of `agencyThemes.ts`, `agency.css`, `AgencyLayout.vue` and the agency forks. Header states why (AH-115 must not move them).

**L0.3 Import-graph and class specs.**

- Agency graph never imports the creator theme module (mirror of `creator-no-agency-theme`, other direction).
- Creator code does not import `modules/agency/` (existing spec 2 stays green).
- Agency-rendered tree carries no `creator-*` class and no `v-theme--creator-*` (mounts `AgencyLayout` with the shared components that agency renders).
- Admin closure: spec proves which `packages/ui` files `apps/admin` imports (`CategoryChips`, `CountryDisplay`, `LanguageList`, `SocialAccountList`, `CompletenessBar`, `PortfolioGallery`, `CKpiCard`) and that none of them is edited in L2 except by CSS that is inert outside the creator theme class.
- Creator stylesheet scoping spec: every rule in the creator-scope CSS is rooted at the widened `:is(...)` list or a creator DOM class.

**L0.4 Creator theme registration.** `modules/creators/theme/creatorThemes.ts`: `buildCreatorThemes()`, `registerCreatorThemes()`, `unregisterCreatorThemes()`, `CREATOR_LOOK` injection key. `variables: {}` is set (otherwise `app.use(vuetify)` throws, as the agency module found). Not yet wired to any layout. Unit tests: register adds both themes, unregister removes them, and a double register is idempotent.

**L0.5 Light derivation + parity spec.** `creatorThemes.ts` carries one remap block `.v-theme--creator-light` and a creator-side copy of the agency light palette (creator code cannot import `modules/agency/`). `creator-light-parity.spec.ts` imports both (specs are allowed to) and asserts the creator light colours equal `AGENCY_LIGHT_COLORS` key by key. The derivation is therefore pinned, not drifting.

**L0 gate:** agency walk compare 0 diffs; full unit suite green; lint and typecheck green; CI green at the pushed tip.

## L1 Shell

**L1.1 Wire the theme.** `CreatorDashboardLayout`, `OnboardingLayout` and `AuthLayout` call `registerCreatorThemes()` in `setup()`, unregister in `onBeforeUnmount`, provide `CREATOR_LOOK`, and bind `<v-app :theme>`. `AuthLayout` provides it only on creator-reachable route names (see §8).

**L1.2 `useNativeLook()`.** New composable. Look-only gates (listed per file in inventory §1) switch from `isNativePlatform()` to `useNativeLook()`. Behaviour gates are not touched.

**L1.3 Widen native.css.** Selectors widen to the `:is(...)` list. Canvas rule moves to `:root:has(.v-application.v-theme--creator-dark, .v-application.v-theme--creator-light)`. `native.css` and its pins are re-pinned deliberately.

**L1.4 Theme preference load.** Creator layouts call `useThemePreference()` so a stored `catalyst.main.theme` applies at load. Consequence stated: web creators who stored light now see light. Native stays dark-only.

**L1.5 Layout shape.** Topbar nav at md and up (native tokens), bottom tabs below, `MOBILE_PRIMARY_KEYS` unchanged. `ThemeToggle` shows on web, hidden on native as today. The `creator-nav-*` and `creator-bottom-nav-*` test ids are kept.

**L1.6 Roboto fix.** In the creator scope, `.text-*` utilities and teleported overlays get Inter (same technique as `agency.css`). This also changes native: **recorded deviation**, listed in the build 5 device walk.

**L1 gate:** agency walk 0 diffs; the creator shell renders under the new theme in the mock-API walk (eyeball + thumbnails); native polarity specs listed in §3 edited by name; unit, lint, typecheck green.

## L2 Shared components

Per inventory §7. Rule: a file rendered by the agency or admin is never edited in a way that changes its agency render. Options are an inject gate on `useNativeLook()` (default false, so flag-off equals today) or a creator fork.

| Treatment                                       | Files                                                                                                                                                    |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Edit in place, re-pin (creator-only at runtime) | `RelationshipInbox`, `NotificationBell`, `PushStateRow`                                                                                                  |
| Inject gate, default off                        | `ChatPanel`, `RelationshipThreadView`, `ContactPicker`, `ReportFallback`, `RichBrief`, `CEmptyState`, `CMonthGrid`, `ImpersonationBanner`, `ThemeToggle` |
| Fork                                            | `NotificationsPage`, `NotificationCenter` (see L4.4)                                                                                                     |
| CSS-only inert outside the creator class        | admin-imported `packages/ui` components                                                                                                                  |

Each gated component gets a **flag-off pin**: mounted without `CREATOR_LOOK`, its DOM equals the pre-change snapshot. The `shared-components-frozen.spec.ts` hashes are updated by name, one commit message line per file giving the reason.

**L2 gate:** agency walk 0 diffs; flag-off pins green; admin build and admin unit suite green; native snapshots unchanged.

## L3 Creator routes by gap size

Order per inventory §4. Each route: restyle, edit its spec, walk it, commit. One route family per commit.

- **L (first):** dashboard, availability, assignments (list), onboarding (wizard steps).
- **M:** jobs board, job detail, assignment detail, profile, connections.
- **S:** security, notification preferences, welcome back, email verification pending.
- **Auth screens: tokens + components only** (Q2), hero and tail kept. `/agency/sign-in` byte-identical (Q3).

Web-voiced replacements for native-voiced strings (see §5).

**L3 gate per family:** agency walk 0 diffs; the family's Vitest and its Playwright spec green; creator walk thumbnails reviewed (the creator baseline is expected to move, it is not compared until L5).

## L4 Inbox v2

**L4.1 Index read (own commit, before the migration).** `EXPLAIN` on the creator relationship leg against a seeded set: `relationship_threads` only has the unique `(agency_id, creator_id)` index, so `creator_id` alone has no leading index. Decide from the plan output whether to add `idx_relationship_threads_creator`. If added: additive, nullable-free, `CREATE INDEX` only, its own migration and own PROD-DATA RISK line (LOW). The assignment leg uses `idx_assignments_creator_status` (READ).

**L4.1 result (no migration in this chunk).** NOT PROVED by `EXPLAIN`: the dev stack has no Postgres, so the plan was not read against a seeded set. What is READ: the relationship leg filters `relationship_threads.creator_id = ?`, which has no leading index (the unique `(agency_id, creator_id)` does not serve it), so it is a scan of `relationship_threads`; the previous creator inbox (`GET creators/me/relationship-threads`) runs the same filter today, so the new endpoint adds no new scan shape. The assignment leg is `campaign_assignments.creator_id`, served by `idx_assignments_creator_status`. Decision left open on purpose: add `idx_relationship_threads_creator (creator_id)` only if a prod row count and an `EXPLAIN` say so (additive `CREATE INDEX`, LOW, its own migration and deploy step). Nothing ships without it being asked for.

**L4.2 API.** Behind new flag `messages_v2_creator_inbox_enabled` (default OFF, `Features/` pattern with `NAME` + `default()` closure). Exposed as creator `features.*` on `/me` via `UserResource`. Endpoints 404 while off. `messages_v2_inbox_enabled` is not reused.

- `GET creators/me/message-inbox`: relationship leg on `relationship_threads.creator_id`, assignment leg joined via `campaign_assignments.creator_id`, cursor `(last_at, type, id)`, same human-message surfacing rule as `MessageInboxQuery`, viewer-scoped unread, tag `Brand · Campaign` or `Direct`. No facets, owner or brand popover.
- `GET creators/me/message-search?q=&cursor=`: body-only match, `is_own`, scoped to the creator's threads, reuses the GIN indexes.
- Pest tests: flag off 404, scoping (never another creator's thread), cursor stability, unread, search scope, N+1 guard.

**L4.3 Web.** `RelationshipInbox` gains tag and search props. Rows carry the tag (Q7). Assignment rows deep link to `creator.assignment.detail?focus=messages` (Q6). `CreatorMessagesPage` reads the v2 endpoints when `features.messages_v2_creator_inbox_enabled`, else the current relationship-threads list. The flag-off path equals today.

**L4.4 Notifications fork.** `CreatorNotificationsPage` (and the native row branch of `NotificationCenter` reused through the creator look) with a page header, replacing the 720px bare card for creators. The agency keeps `NotificationsPage` unchanged. Closes tech-debt row 6.

**L4 gate:** Pest green; flag OFF = current behaviour proved by spec; agency walk 0 diffs; Playwright new specs green (§6).

## L5 Close-out

**L5.1 Creator re-baseline.** Deliberate re-record of `docs/reviews/ah-114-creator-baseline/baseline.json` (142 targets) into a new `ah-115-creator-baseline/`, with a README listing each reason it moved. Agency baseline compared one last time: 0 diffs. Native polarity unchanged apart from the Roboto deviation and inbox v2.

**L5.2 Docs.** `docs/feature-flags.md` row for the new flag, tech-debt row 6 closed, deploy log entry, README of baselines.

**L5.3 Build 5 device-walk list.**

1. Build 5 installs over build 4, session survives.
2. Dark look unchanged on dashboard, availability, assignments, onboarding, auth.
3. Inter now renders on `.text-*` and overlays (Roboto fix, recorded deviation).
4. Inbox with flag OFF equals build 4. With flag ON: one list, Direct and Brand · Campaign tags, search, deep link to assignment chat.
5. Notifications page layout.
6. Push, resume, deep links, icon badge (behaviour gates untouched, regression check).
7. Safe area and keyboard on the chat thread.

## §3 Spec edits by name

- **Native polarity (the web side flips):** `tests/unit/plugins/vuetify.spec.ts`, `tests/unit/composables/useMobileShell.spec.ts`, `native-css-scoping`, `native-page-css-scoping`, `native-button-radius`, `native-field-text-size`, `native-tab-bar`, `native-detail-pages`, `native-invitation-actions`, `rel-thread-native-height`, `creator-profile-native-coverage`, `auth-layout-shape`.
- **Web cases in `*.native.spec.ts`:** `AuthLayout`, `SignInPage`, `SignUpPage`, `EmailVerificationPendingPage`, `OnboardingLayout`, `OnboardingLayout.accountMenu`, `WelcomeBackPage`, `DropzonePrompts`, `ConnectionsSocialSection`, `ConnectionsPortfolioSection`, `AvatarUploadDrop`, `CreatorDashboardPage`, `CreatorAssignmentsPage`, `NotificationCenter`, `RelationshipInbox`, plus `RelationshipThreadView` and `ChatPanel` pins.
- **Others:** `AvailabilityCalendarNative`, `NativeCard`, `CreatorDashboardLayout` (lines 500, 517, 971), `SecurityPage`, `CreatorAssignmentDetailPage`, `CreatorJobDetailPage`, `ThemeToggle.spec` if the toggle is hidden.
- **Hash pins re-pinned on purpose:** `shared-components-frozen.spec.ts` (22), `shared-tokens-frozen.spec.ts` (`native.css`, `plugins/vuetify.ts` only if touched, `App.vue`, `useTheme.ts`, `useThemePreference.ts` only if touched). Each pin line carries its reason.
- **Untouched:** `useAuthStore.*`, `transport-mode`, `tokenStore`, `installMarker`, `appResume`, push specs, `agency-theme-scoping`, `agency-theme-isolation`, `agency-color-system-parity`.
- **New:** agency-frozen, creator-scoping, agency-never-imports-creator-theme, agency-tree-no-creator-class, creator-light-parity, flag-off pins, inbox v2 specs.

## §5 i18n keys for approval

All in 24 locales, real MT in the flaky ten (bg, el, et, fi, ga, hu, lt, lv, mt, ro). Reused as is: `app.messaging.inbox.tagDirect`, `tagCampaign`, `loadMore`.

| Key                                             | EN                                                            |
| ----------------------------------------------- | ------------------------------------------------------------- |
| `app.messaging.creatorInbox.title`              | Messages                                                      |
| `app.messaging.creatorInbox.searchPlaceholder`  | Search your messages                                          |
| `app.messaging.creatorInbox.searchClear`        | Clear search                                                  |
| `app.messaging.creatorInbox.searchEmpty`        | No messages match "{query}"                                   |
| `app.messaging.creatorInbox.searchError`        | Search is unavailable right now. Try again.                   |
| `app.messaging.creatorInbox.resultYou`          | You                                                           |
| `app.messaging.creatorInbox.tagBrandCampaign`   | {brand} · {campaign}                                          |
| `app.messaging.creatorInbox.openAssignmentChat` | Open campaign chat                                            |
| `app.messaging.creatorInbox.empty`              | No conversations yet                                          |
| `app.messaging.creatorInbox.emptyHint`          | Messages from agencies and campaigns appear here.             |
| `app.notifications.page.title`                  | Notifications                                                 |
| `app.notifications.page.caughtUp`               | You are all caught up                                         |
| `app.creators.shell.nav.more`                   | reuse existing if present, else More                          |
| `creator.upload.avatarPromptWeb`                | Drop a photo here or choose a file                            |
| `creator.upload.portfolioPromptWeb`             | Drop files here or choose files                               |
| `creator.upload.subtitleWeb`                    | wording to confirm with the existing `subtitle_native` string |

Native-voiced strings ("Tap to choose a photo/files", `avatar_prompt_native`, `portfolio_prompt_native`, `subtitle_native`) stay for native. Web gets the `*Web` variants above. Exact existing key paths are confirmed in L3 before any key is added; keys that already exist are reused, not duplicated. The final list is frozen at approval.

## §6 Playwright set

- **Existing, run and fix selectors:** `creator-shell-mobile`, `creator-dashboard`, `creator-jobs-board`, `creator-wizard-happy-path`, `creator-signup-consent`, `creator-messages-badge`, `creator-campaign-status`, `creator-connection-requests`, `notification-deep-link`, `2fa-enrollment-and-sign-in`, `failed-login-lockout-and-reset`, `jobs-board-full-lifecycle`, `hand-off-at-approval-lifecycle`, `invitations*`, `smoke`, `permissions`.
- **New:** `creator-inbox-v2` (one list, tags, search, deep link to assignment chat, flag off fallback), `creator-notifications-page`.
- **Traps:** `TEST_HELPERS_TOKEN` exported; dev stack down on 8000/8001/5174; `catalyst_e2e` created and migrated by hand; `unset PLAYWRIGHT_BROWSERS_PATH` or run unsandboxed; `workers: 1`; output into `playwright-report/`, never `/tmp`.

## §7 Deploy entry

1. **API** (L4.1 index migration if any, L4.2 endpoints, flag OFF). Verify flag-off 404 and no behaviour change.
2. **Web, both SPAs.** Admin ships unchanged in behaviour (closure proof from L0.3). Rollback: redeploy the previous `dist`.
3. **Native build 5.** Bump `CURRENT_PROJECT_VERSION` 4 to 5, archive, upload, device walk (L5.3).
4. **Flag** `messages_v2_creator_inbox_enabled` ON only after the walk. Build 4 is safe with the flag on or off (it ignores the new endpoints).

## §8 Points for review

1. **Auth scoping vs Q2/Q3.** Sign-in is the marketing landing shared with agency users, and `/agency/sign-in` is the same page. Proposal: the creator look applies by route name on creator-reachable routes only (`auth.sign-in`, `auth.sign-up`, `auth.verify-email.pending`, creator onboarding). Role-agnostic screens an agency user can reach (2FA, forgot, reset, `/accept-invitation`) stay unchanged unless you rule otherwise. **Question: restyle those four too, accepting that agency users see them changed?** Default if unanswered: leave them unchanged.
2. **Light users.** L1.4 means web creators who stored light now see the derived light theme. Confirm.
3. **Roboto fix** changes native (Q8, recorded).
4. **Index** decision waits on the L4.1 read.
5. **i18n** final list approval, including the three `*Web` wording strings.
6. **Agency baseline** is recorded on Apple silicon, like the creator one. CI does not compare it. Local compare is the gate.
