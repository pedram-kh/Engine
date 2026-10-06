# Creator Mobile App (Capacitor wrap of the creator surface) — inventory

**Status:** Read-only inventory. No code changed, no plan written, no decisions made.
**PROD-DATA RISK: NONE** — this document reads code and docs only.
**Eventual build (not this doc):** ⚠️ MEDIUM-HIGH expected across the arc — an auth-mode addition on
every creator-reachable route group (M2), a new device-token table + a third delivery leg on live
notifier paths (M4), and an account-erasure path that **mutates and deletes existing creator rows and
S3 objects** (M5 — the one leg that is squarely §5.40 territory and will need its own `⚠️` line).

Orientation read through [`AH-098`](adhoc-changes-log.md#L73) (master agreement section on the creator
profile). `HEAD` = `origin/main` = **`52af5b23`** (`docs(runbooks): AH-098 deployed — deploy-log + Part 2
refresh`), one docs commit past `9b7d6deb`; nothing past AH-098 has landed. **AH-084 has no log entry** —
it is named, not started (`RESUMPTION-TEMPLATE.md:1264-1269`; `tech-debt.md:2445-2452`). Load-bearing
precedents: **AH-083** (the creator mail set + the notifier fork this arc's push leg sits beside —
`creator-mail-set-inventory.md`, `missing-creator-mails-review.md`), **AH-007 / AH-016 / AH-017 / AH-057**
(the creator mobile-web passes — `adhoc-changes-log.md:4221-4275`, `:3886-3897`, `:3875-3884`,
`:2472-2551`), **AH-013** (two-pane messaging + mobile single-pane — `:3927-3952`), **AH-098** (the bounded
scroll agreement panel — `:73-191`), and the architecture docs' own Phase-2 mobile intent
(`00-MASTER-ARCHITECTURE.md:372-377`, `04-API-DESIGN.md:160-164`).

**Method note.** M1's verdicts are from **static reading only** — no device, simulator, or browser run
this session. The repo's only phone-width E2E leg runs the iPhone 13 profile on the **Chromium** engine
(`apps/main/playwright.config.ts:100-113`, because Playwright's WebKit build bus-errors on this macOS 14
host), so **no automated leg anywhere renders the creator surface in WebKit** — which is exactly the engine
an iOS Capacitor shell runs (WKWebView). Treat every "fine" below as "fine by code evidence, unproven in
WKWebView."

---

## The four shaping answers (read this first)

**1. M2 — token-auth cost: MEDIUM-LARGE, and it gates the whole arc.** The codebase has **zero token
auth today**. `User` carries no `HasApiTokens` trait (`apps/api/app/Modules/Identity/Models/User.php:54-62`);
the only Sanctum-token artefact is the stock `personal_access_tokens` migration
(`apps/api/database/migrations/2026_05_07_003823_create_personal_access_tokens_table.php:16`); nothing
calls `createToken`, `currentAccessToken`, `tokenCan`, or `Sanctum::actingAs` anywhere in `app/` or
`tests/`. Every authenticated route mounts **`auth:web` — the session guard — not `auth:sanctum`**:
25 mounts across 9 module route files (e.g. `apps/api/app/Modules/Creators/Routes/api.php:85`,
`Messaging/Routes/api.php:53,106,114`, `Notifications/Routes/api.php:32,46`, `Identity/Routes/api.php:60,69`).
Login is session-only and **throws if no session is bound** (`Identity/Services/AuthService.php:132-151`,
then `login()` + `session()->regenerate()` at `:301-302`). The client reads the CSRF token out of
`document.cookie` (`packages/api-client/src/http.ts:167-172`, `:316-338`), which a bundled
`capacitor://localhost` origin cannot see for an API-domain cookie. So "same Vue code in a native shell"
does not authenticate as-is; it needs (a) a token-issuing login branch on the existing `AuthService`
graph (so lockout / 2FA / suspension / wrong-SPA gates are reused, not forked), (b) the creator-reachable
mounts moved to `auth:sanctum` — with a **guard-widening hazard** named in §M2.4, (c) a bearer mode in
`@catalyst/api-client`'s transport, (d) revocation on logout / password reset / suspension, and (e) an
expiry posture (Sanctum `expiration` is `null` today — `apps/api/config/sanctum.php:65`). Biometric unlock
(protect a stored token) presupposes this — there is no token to protect under cookie auth. The one
alternative that avoids it (Capacitor `server.url` pointing the WebView at the live SPA origin, so cookies
stay first-party) is reported in §M2.5 with its costs; it collides with the cached-shell half of
offline-grace and with App Store "web wrapper" review risk.

**2. M1 — shell-swap count: 1 hard, plus 2 layout-level platform forks.** Of 27 creator-reachable routes,
**one** is structurally desktop-shaped: `creator.availability` (a fixed 7-column month grid with no
alternate surface — `packages/ui/src/components/CMonthGrid.vue:218-242`). Everything else already has a
mobile-web answer from AH-007/013/017/057 and reads **fine** by code evidence (§M1 table). The two
**layout-level** forks are not "broken at 390" but are what the decided `isNativePlatform()` presentational
fork has to touch: both shells switch on **viewport width** (`smAndDown` —
`apps/main/src/modules/creators/layouts/CreatorDashboardLayout.vue:70`; `OnboardingLayout.vue:32,80`), so a
native app on a tablet (or a phone in landscape at `md`) would render the **desktop topbar**, not the
bottom tabs. The native fork wants "bottom tabs because native," not "bottom tabs because narrow." The UI
chunk is therefore small: one surface swap (availability → agenda/list), two layout predicates, a native
theme, and dialog → bottom-sheet polish (§M1.3).

**3. M5 — deletion size: LARGE — its own chunk, not a rider.** Creator self-deletion **does not exist in
any form**: no route, no controller method, no job, no UI, no prune command; the admin compliance erasure
queue is an explicit shell (`apps/api/app/Modules/Admin/Http/Controllers/AdminComplianceController.php:19,53-64`,
`meta.shell: true`); the spec'd `/api/v1/me/data-erasure` + `ExecuteDataErasureJob`
(`docs/05-SECURITY-COMPLIANCE.md:53,60-72`) and the `data_erasure_requests` table (`docs/03-DATA-MODEL.md`
§19) were never built (`docs/tech-debt.md:614`, Sprint-14-gated). The data graph is ~50 FK edges, and the
**RESTRICT** edges — `creators.user_id`, `campaign_assignments.creator_id`, `campaign_applications.creator_id`,
`relationship_threads.creator_id`, `messages.sender_user_id`, `relationship_messages.sender_user_id`,
`notifications.recipient_user_id` — mean a hard delete is blocked by design; the shape the docs already
describe (anonymize-in-place, purge S3, retain the agency's commercial record — `05-SECURITY-COMPLIANCE.md:60-72`)
is the only one that fits the schema. The legal-retention tension (what the agency must keep vs. what the
creator may erase) is named in §M5.3 and not resolved here.

**4. M7 — sequencing verdict: tightly coupled; precede-or-merge, not follow.** Push preferences ride the
exact same storage (`user_notification_preferences`, `channel` is `string(16)`, unique on
`(user_id, type, channel)` — migration `2026_06_06_100001_…:51,54,60`), the same enum
(`NotificationChannel.php:21-23`), the same `isChannelEnabled()` read (`NotificationService.php:108-121`),
and the same page loop (`NotificationPreferencesPage.vue:195-205` renders one `v-switch` per channel, stacked).
AH-084 is the chunk that turns that page from "one in-app switch per type" into a real per-type × per-channel
matrix and establishes the "every send-site reads the channel preference" guard. If push **follows** AH-084, it
adds one column to a built matrix and one more call to a built guard; if AH-084 **follows** push, the matrix
and the guard get designed twice — the outcome the brief wants to avoid. Merge vs. precede is Pedram's
kickoff call (§M7.4).

---

## M1 — Mobile-readiness of the creator surface, page by page

### M1.1 The shells

`App.vue` dispatches on `meta.layout` to three creator-relevant shells (`apps/main/src/App.vue:47-68`):

- **`AuthLayout`** — centred card, `max-width: 480` (`AuthLayout.vue:141-150,177`); the hero sign-in variant
  stacks at `@media (max-width: 1100px)` (`:215-230`).
- **`OnboardingLayout`** — `isMobile = smAndDown` (`OnboardingLayout.vue:32,80`): hamburger + right
  `v-navigation-drawer` (`:239-283`), desktop `AnimatedWizardChrome` vs. `AnimatedWizardChromeMobile`
  (`:108-118`, `:287-309`), page-scroll stage on mobile (`:366-372`). AH-007.
- **`CreatorDashboardLayout`** — `isMobile = smAndDown` (`CreatorDashboardLayout.vue:70`): the topbar nav
  hides and a `v-bottom-navigation` takes over (`:287-337`; four primary slots + More, AH-057); content capped
  at `max-width: 960px` unless `meta.wide` (`:58-63`, `:279-281`). Notification preferences live under the user
  menu, not a settings route (`:257-264`).

There is **no drafts route** (draft submit / resubmit / round history live on `creator.assignment.detail`)
and **no invitations route** (an invitation is an assignment in `invited`, answered on the list/detail —
AH-060). The brief's "invitations" and "drafts" map onto those two routes below.

### M1.2 The route table

| Route                                      | Path                                 | Component (route def)                                                | Evidence                                                                                                                                                                                                                                                                                                                                                                                                                                             | Verdict              |
| ------------------------------------------ | ------------------------------------ | -------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------- |
| `auth.sign-in`                             | `/sign-in`                           | `SignInPage.vue` (`auth/routes.ts:74-78`)                            | Hero stacks `<1100px` (`AuthLayout.vue:215-230`). Mounts the Meta Pixel (`SignInPage.vue:107-112`) — see M3                                                                                                                                                                                                                                                                                                                                          | fine                 |
| `auth.sign-up`                             | `/sign-up`                           | `SignUpPage.vue` (`:106-110`)                                        | AuthLayout card, `max-width: 480`                                                                                                                                                                                                                                                                                                                                                                                                                    | fine                 |
| `auth.verify-email.pending`                | `/verify-email/pending`              | `EmailVerificationPendingPage.vue` (`:115-119`)                      | AuthLayout card                                                                                                                                                                                                                                                                                                                                                                                                                                      | fine                 |
| `auth.verify-email.confirm`                | `/auth/verify-email`                 | `EmailVerificationConfirmPage.vue` (`:128-132`)                      | AuthLayout card. Reached from an **emailed link** — see M6 universal links                                                                                                                                                                                                                                                                                                                                                                           | fine                 |
| `auth.accept-creator-invite`               | `/auth/accept-invite`                | `AcceptCreatorInvitePage.vue` (`:140-144`)                           | State cards `max-width` 480/520 (`AcceptCreatorInvitePage.vue:87-141`). Emailed-link entry                                                                                                                                                                                                                                                                                                                                                           | fine                 |
| `auth.forgot-password`                     | `/forgot-password`                   | `ForgotPasswordPage.vue` (`:149-153`)                                | AuthLayout card                                                                                                                                                                                                                                                                                                                                                                                                                                      | fine                 |
| `auth.reset-password`                      | `/auth/reset-password`               | `ResetPasswordPage.vue` (`:160-164`)                                 | AuthLayout card. Emailed-link entry                                                                                                                                                                                                                                                                                                                                                                                                                  | fine                 |
| `auth.2fa.enable` / `.verify` / `.disable` | `/auth/2fa/*`                        | `EnableTotpPage` / `VerifyTotpPage` / `DisableTotpPage` (`:169-189`) | AuthLayout card. Recovery-codes download + copy — see M3                                                                                                                                                                                                                                                                                                                                                                                             | fine                 |
| `onboarding.welcome-back`                  | `/onboarding`                        | `WelcomeBackPage.vue` (`onboarding/routes.ts:22-29`)                 | Mobile chrome via layout; page `max-width: 640` (`WelcomeBackPage.vue:272`)                                                                                                                                                                                                                                                                                                                                                                          | fine                 |
| `onboarding.profile`                       | `/onboarding/profile`                | `Step2ProfileBasicsPage.vue` (`:32-38`)                              | AH-007 wrap + dial-code CSS (`ProfileBasicsForm.vue:734-840`); avatar dropzone — see M3                                                                                                                                                                                                                                                                                                                                                              | fine                 |
| `onboarding.connections`                   | `/onboarding/connections`            | `Step3ConnectionsPage.vue` (`:44-50`)                                | `isMobile = smAndDown` + mobile social cards (`ConnectionsSocialSection.vue:26-30,216-218,389-423`); portfolio dropzone — see M3                                                                                                                                                                                                                                                                                                                     | fine                 |
| `onboarding.kyc`                           | `/onboarding/kyc`                    | `Step5KycPage.vue` (`:53-59`)                                        | Single column `max-width: 720` (`Step5KycPage.vue:155`). Full-page vendor redirect — see M3 §9                                                                                                                                                                                                                                                                                                                                                       | fine (layout)        |
| `onboarding.tax`                           | `/onboarding/tax`                    | `Step6TaxPage.vue` (`:62-68`)                                        | Single column (`Step6TaxPage.vue:282`)                                                                                                                                                                                                                                                                                                                                                                                                               | fine                 |
| `onboarding.payout`                        | `/onboarding/payout`                 | `Step7PayoutPage.vue` (`:71-77`)                                     | Single column (`Step7PayoutPage.vue:149`). Stripe redirect — see M3 §9                                                                                                                                                                                                                                                                                                                                                                               | fine (layout)        |
| `onboarding.contract`                      | `/onboarding/contract`               | `Step8ContractPage.vue` (`:80-86`)                                   | Click-through: the scroll-to-end region is height-bounded (`MasterAgreementTerms.vue:171-174`, `max-height: 360px; overflow-y: auto`), checkbox gated on `reachedEnd` (`ClickThroughAccept.vue:40-47,75-77`). **There is no in-SPA signature pad** — the e-sign path is a vendor redirect (`Step8ContractPage.vue:96-101`). Nested-scroll-inside-page-scroll on a phone is the one thing worth eyes on a device                                      | fine                 |
| `onboarding.review`                        | `/onboarding/review`                 | `Step9ReviewPage.vue` (`:89-95`)                                     | Single column (`Step9ReviewPage.vue:270`)                                                                                                                                                                                                                                                                                                                                                                                                            | fine                 |
| `creator.dashboard`                        | `/creator/dashboard`                 | `CreatorDashboardPage.vue` (`creators/routes.ts:19-27`)              | Column of alerts/lists, `flex-wrap` teaser (`CreatorDashboardPage.vue:284`); no table, no two-pane                                                                                                                                                                                                                                                                                                                                                   | fine                 |
| `creator.profile`                          | `/creator/profile`                   | `CreatorProfilePage.vue` (`:33-39`)                                  | `max-width: 840` (`:312`); account grid `auto-fit minmax(200px)` (`:339-341`); AH-007 social cards reused; AH-098 agreement panel bounded (`CreatorMasterAgreementSection.vue:115` → `MasterAgreementTerms.vue:171-174`)                                                                                                                                                                                                                             | fine                 |
| `creator.availability`                     | `/creator/availability`              | `AvailabilityPage.vue` (`:43-49`)                                    | → `AvailabilityCalendar` → `CMonthGrid`: **fixed 7 columns** with `grid-auto-rows: minmax(96px, 1fr)` (`packages/ui/src/components/CMonthGrid.vue:218-242`) and **no** `useDisplay` / agenda alternative; hover affordances on cells (`:267-268`); block dialog `max-width="560"`, not fullscreen (`AvailabilityBlockDialog.vue:276`); side-by-side `DateTimeField` without wrap (`DateTimeField.vue:112-120`). At 390px each day cell is ~55px wide | **needs-shell-swap** |
| `creator.assignments`                      | `/creator/assignments`               | `CreatorAssignmentsPage.vue` (`:55-60`)                              | AH-017 `@media (max-width: 599px)` stack + equal-width actions (`CreatorAssignmentsPage.vue:330-345`). Invitations are answered here                                                                                                                                                                                                                                                                                                                 | fine                 |
| `creator.assignment.detail`                | `/creator/assignments/:ulid`         | `CreatorAssignmentDetailPage.vue` (`:66-72`)                         | Drafts composer + round history as stacked cards, not tables (`:691-862`, `:990-1035`); the AH-046 resubmit-in-place form (`:921-949`); link dialog `max-width="420"` (`:804-806`); invitation actions flex on mobile (`:1143-1147`); draft media picker — see M3                                                                                                                                                                                    | fine                 |
| `creator.jobs`                             | `/creator/jobs`                      | `CreatorJobsPage.vue` (`:82-88`)                                     | Card list; `min-width: 0` + `@media (max-width: 599px)` name wrap (`CreatorJobsPage.vue:239-267`)                                                                                                                                                                                                                                                                                                                                                    | fine                 |
| `creator.job.detail`                       | `/creator/jobs/:ulid`                | `CreatorJobDetailPage.vue` (`:94-100`)                               | AH-057 framed card (`:473-475`); the application dialog is a short form, `max-width="520"`, not fullscreen (`:413-445`) — usable at 390, a bottom-sheet candidate under the native fork                                                                                                                                                                                                                                                              | fine                 |
| `creator.notifications`                    | `/creator/notifications`             | `NotificationsPage.vue` (`:109-115`)                                 | `v-card max-width="720"` (`NotificationsPage.vue:17`)                                                                                                                                                                                                                                                                                                                                                                                                | fine                 |
| `creator.notifications.preferences`        | `/creator/notifications/preferences` | `NotificationPreferencesPage.vue` (`:122-128`)                       | `v-card max-width="640"` (`:151`); stacked switches (`:195-205`). **Grows with M7** — 3 channels × 15 creator types stacked is the real mobile question here                                                                                                                                                                                                                                                                                         | fine (today)         |
| `creator.messages`                         | `/creator/messages`                  | `CreatorMessagesPage.vue` (`:136-146`, `wide: true`)                 | AH-013: two-pane only at `mdAndUp`, one pane at a time below (`CreatorMessagesPage.vue:26-35`)                                                                                                                                                                                                                                                                                                                                                       | fine                 |
| `creator.messages.thread`                  | `/creator/messages/:agencyUlid`      | `CreatorRelationshipThreadPage.vue` (`creators/routes.ts:148-157`)   | Back chevron on `smAndDown` (`CreatorRelationshipThreadPage.vue:32-33`); thread `100dvh` + `@media (max-width: 959px)` height (`RelationshipThreadView.vue:613-627`); Enter-to-send desktop-only (`:49-54`). The `dvh` sizing assumes the web bottom bar — a native tab bar + safe-area insets change the arithmetic                                                                                                                                 | fine                 |

**Tally:** 27 routes (the three 2FA routes counted once in the table, three in the tally) — **25 fine, 0
needs-CSS, 1 needs-shell-swap** (`creator.availability`), plus `creator.notifications.preferences` flagged as
"fine today, re-check after M7."

### M1.3 What the native presentational fork touches (beyond the table)

- **Two layout predicates** — `CreatorDashboardLayout.vue:70` and `OnboardingLayout.vue:80` key the mobile
  chrome on `smAndDown`. Under the decided fork the native shell wants the bottom tabs regardless of width
  (tablets, landscape). This is the "per-surface shell swap" seam that already exists, needing a second input.
- **Safe-area insets** — no `env(safe-area-inset-*)` use was found in the creator shells; the
  `v-bottom-navigation` (`CreatorDashboardLayout.vue:291`) and the `100dvh` thread (`RelationshipThreadView.vue:613-627`)
  will sit under the iOS home indicator / notch without it.
- **Dialogs → bottom sheets** — the application dialog (`CreatorJobDetailPage.vue:413`), the draft link dialog
  (`CreatorAssignmentDetailPage.vue:804-806`), the availability block dialog (`AvailabilityBlockDialog.vue:276`):
  usable today, native-idiom candidates.
- **Hover-only affordances** — `CMonthGrid.vue:267-268` (and `.availability-more:hover`, `:477-478`) — moot once
  availability is swapped.
- **No platform detection exists anywhere** — no `@capacitor/*` import, no `isNativePlatform` helper; the
  fork is net-new.

---

## M2 — The auth coupling

### M2.1 How the SPA authenticates today

- **Transport:** one singleton (`apps/main/src/core/api/index.ts:115-121`), `baseUrl` from
  `VITE_API_BASE_URL ?? '/api/v1'` (`:116`), behind an architecture test forbidding direct axios
  (`tests/unit/architecture/no-direct-http.spec.ts`, cited at `core/api/index.ts:22-27`).
- **Cookie mode:** `withCredentials: true` on the instance and re-forced on injected instances
  (`packages/api-client/src/http.ts:105-126`).
- **CSRF:** every `POST`/`PATCH`/`DELETE` first `GET`s `/sanctum/csrf-cookie` (`http.ts:136-143`), then reads
  `XSRF-TOKEN` from `document.cookie` and sends `X-XSRF-TOKEN` (`:167-172`, reader `:316-338`). The preflight
  URL is anchored at the API host root when `baseUrl` is absolute (`:292-302`).
- **401 policy:** any 401 outside five exempt paths clears the user and redirects to sign-in with
  `?reason=session_expired` (`core/api/index.ts:54-60`, `:88-100`).
- **Server:** `statefulApi()` (`apps/api/bootstrap/app.php:55`) starts a session + CSRF only for origins in
  `SANCTUM_STATEFUL_DOMAINS` (`config/sanctum.php:36-39`). Session: driver `database` default
  (`config/session.php:21`), **lifetime 120 min idle** default (`:35`), `expire_on_close` false (`:37`), cookie
  `catalyst_main_session` (`:139`), `SameSite=lax` (`:208`), domain from env — `.catalystengine.com` in
  production per `docs/runbooks/local-dev.md:48-52`. Production values for lifetime/driver are env, not in repo.
- **Guards:** `web` and `web_admin` are both `session` drivers (`config/auth.php:42-58`); the login controller
  picks by route name (`Identity/Http/Controllers/LoginController.php:43-55`).

### M2.2 Every place that assumes same-origin cookies

| Surface                                    | Evidence                                                                                                                                                                                                                                                              | Cookie-coupled?                                                                                                                                                                                         |
| ------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Every JSON API call                        | `http.ts:105-126,136-143,167-172`                                                                                                                                                                                                                                     | **Yes** — the whole coupling lives here, in one file                                                                                                                                                    |
| Multipart uploads (avatar, video complete) | `onboarding/api/onboarding.api.ts:142-150,216-226` through the same client                                                                                                                                                                                            | Yes — via the client, nothing extra                                                                                                                                                                     |
| Presigned PUTs to S3                       | `packages/api-client/src/presigned.ts:53-56` — `withCredentials: false`, by design                                                                                                                                                                                    | **No.** But the **S3 bucket CORS** must allow the native origin; bucket CORS is not in the repo (Terraform is a 19-line skeleton, `infra/terraform/production/main.tf:4`) — it is a console-side change |
| Signed attachment / media reads            | All 20 mint sites are S3 `temporaryUrl()` — e.g. `Messaging/Http/Resources/MessageResource.php:84`, `Campaigns/Http/Resources/CampaignDraftResource.php:93`, `Creators/Support/PortfolioItemPresenter.php:106,127`, `Creators/Http/Resources/ContractResource.php:58` | **No** — bearer-in-URL. Only the JSON call that mints them is coupled                                                                                                                                   |
| Polling (bell, inbox, thread)              | `notifications/composables/useNotificationPoll.ts:33`, `messaging/composables/useMessageThread.ts:18`, `CreatorMessagesPage.vue:37`                                                                                                                                   | Inherits the client. **No SSE / WebSocket / EventSource anywhere** — no second auth channel to convert                                                                                                  |
| Impersonation                              | `Identity/Services/ImpersonationService.php:150-152` (session login), `Identity/Http/Middleware/EnforceImpersonation.php:60-63`                                                                                                                                       | Session-only by design; stays web-only. `EnforceImpersonation` passes through when no session is bound (`:60-62`), so token requests are unaffected                                                     |

### M2.3 Token mode — reality check

- **None of it exists.** No `HasApiTokens` (`User.php:54-62`), no `createToken` in app code (the only
  `createToken` hits are `Password::broker()->createToken` — `PasswordResetService.php:72`, tests), no token
  abilities used anywhere, no `Sanctum::actingAs` in 115 test files that use `actingAs` (session guard). The
  test helpers' token is **not user auth** — it is an `X-Test-Helper-Token` header gating `/api/v1/_test/*` in
  local/testing only (`apps/api/config/test_helpers.php:16-20`; Playwright sends it,
  `apps/main/playwright.config.ts:80-82`).
- **The intent is on record**: `04-API-DESIGN.md:160-164` (login returns a token; `Authorization: Bearer`),
  `00-MASTER-ARCHITECTURE.md:376` (both modes "designed in from Phase 1"), `05-SECURITY-COMPLIANCE.md:404` (token
  auth needs no CSRF), `09-ADMIN-PANEL.md:347` (admin "mobile session management"). The design was promised; the
  plumbing was never laid.
- **Expiry / refresh posture:** Sanctum `expiration => null` (`config/sanctum.php:65`) — tokens never expire
  unless set; Sanctum has no refresh-token concept. A long-lived token behind biometric unlock is the usual
  native shape; the alternative is an expiry + silent re-login. Either is a kickoff decision.
- **Abilities:** none in use; the creator app would be the first consumer. Whether the token is scoped (e.g. a
  `creator-app` ability) or a full-account token is open.

### M2.4 Where session auth is hard-assumed server-side

- **Login** — `AuthService::login()` refuses without a bound session (`AuthService.php:132-151`) and finishes
  with `guard($guard)->login()` + `session()->regenerate()` (`:301-302`). A token branch must reuse the
  gate graph above it (lockout `:158-162`, suspension `:193-197`, agency suspension `:217-221`, wrong-SPA
  `:230-234`, 2FA `:242-296`) and differ only at the very end.
- **The wrong-SPA gate** — `SPA_USER_TYPE_ALLOW_LIST` allows `Creator` **and** `AgencyUser` on `web`
  (`AuthService.php:99-102`). A creator-only native app could narrow its token login to `Creator`; open question.
- **Logout** — tolerant of no session (`AuthService.php:320-330`), but would need token revocation added.
- **Password reset** — rotates `remember_token` (`PasswordResetService.php:101-104`); sessions die via
  Sanctum's `AuthenticateSession` (`config/sanctum.php` `middleware.authenticate_session`). **No token equivalent
  exists** — reset must explicitly revoke tokens.
- **Suspension** — checked at login only (`AuthService.php:193-197`); a token issued before suspension keeps
  working unless revoked at the suspend sites.
- **25 `auth:web` mounts** — the creator-reachable ones are at least `Creators/Routes/api.php:85`,
  `Messaging/Routes/api.php:53,106,114`, `Notifications/Routes/api.php:32,46`, `Identity/Routes/api.php:60,69`
  and the `auth/*` 2FA + logout set (`:85,113-140`), `TrackedJobs/Routes/api.php:25`. Eight test assertions in
  five files pin `auth:web` by string (e.g. `tests/Feature/Modules/Creators/Sprint3Chunk2InvariantsTest.php`,
  `CreatorWizardVerifiedGateTest.php`) — they will go red on a swap, by design.
- **⚠ Guard-widening hazard.** Sanctum's own guard list is `['web', 'web_admin']` (`config/sanctum.php:53`).
  Swapping a main-SPA route from `auth:web` to `auth:sanctum` would make Sanctum's guard also accept a
  **`web_admin` session** on that route — a platform-admin session authenticating against creator endpoints.
  Production host separation mitigates it (`local-dev.md:48-55`), but it is a boundary change the
  break-revert discipline would need to pin. Named, not resolved.
- **Stateful-domain interaction.** If production's `SANCTUM_STATEFUL_DOMAINS` ever contains `localhost` (the
  repo default does — `config/sanctum.php:36-39`), requests from `capacitor://localhost` / `https://localhost`
  would be treated as stateful and CSRF-validated, and bearer `POST`s would 419. The production value is env,
  not in repo — a read-back item at kickoff.
- **Audit actor** — `AuditLogger` reads the default guard (`Audit/Services/AuditLogger.php:116`);
  `auth:sanctum` switches the default guard for the request, so this should hold — verify at kickoff, don't assume.

### M2.5 The CORS / origin surface

- `config/cors.php` — `paths` `api/*` + `sanctum/csrf-cookie` (`:29`); origins from `FRONTEND_MAIN_URL`,
  `FRONTEND_ADMIN_URL`, and **`FRONTEND_EXTRA_ORIGINS`** (`:33-40`) — the existing seam for `capacitor://localhost`
  (iOS) and `https://localhost` (Android); no patterns (`:42`); `supports_credentials: true` (`:55`).
- S3 bucket CORS for presigned PUTs — same two origins, configured outside the repo (above).
- **Cookie mode from a bundled origin is effectively dead**: `SameSite=lax` (`session.php:208`) withholds the
  session cookie on cross-site XHR, the XSRF cookie on the API domain is unreadable from the app origin's
  `document.cookie` (`http.ts:316-338`), and WKWebView's third-party-cookie policy compounds both. Patching it
  (SameSite=None, a native cookie bridge) is possible and fragile.
- **The alternative shape, reported:** Capacitor `server.url` pointing the WebView at the live SPA origin.
  Cookies stay first-party and M2 shrinks to near-zero — but there is no cached shell without a service worker
  (none exists — no manifest, no SW in `apps/main/index.html`), Capacitor documents `server.url` as a dev
  live-reload feature, App Store review treats thin web wrappers harshly, and biometric unlock would protect
  nothing (there's no stored credential). It is the cheapest path and the weakest product.

**M2 size, honestly:** a backend chunk (token login branch, `HasApiTokens`, route-group swap with the hazard
pinned, revocation at logout/reset/suspend, expiry posture, CORS env) plus a client chunk (a bearer mode in
`createHttpClient` that skips the CSRF preflight, a secure-storage token holder, the 401 policy rerouted to the
native lock screen). Medium-large; the gate for everything else in the arc.

---

## M3 — Browser-API dependencies

Absences, verified: no `capture=` attribute, no `FileReader`, no `sessionStorage`/IndexedDB, no `navigator.share`
/ geolocation / `getUserMedia`, no `navigator.onLine`, no service worker / manifest, no SSE / WebSocket, no
`window.open(` call (links are anchors or `location.href`), no KYC/e-sign iframes.

| Category              | Item                                                                           | Evidence                                                                                                                                                                                                        | Creator flow                           | Verdict                                                                                                                                                                                                                     |
| --------------------- | ------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| File input            | Draft media `<input type=file multiple accept="image/*,video/*">`              | `creators/pages/CreatorAssignmentDetailPage.vue:737-744`; upload loop + PUT `:302-330`                                                                                                                          | Draft submit / resubmit                | **works-in-WebView** (the OS picker offers library + camera). Native camera/gallery (`@capacitor/camera`) is the decided UX upgrade — it returns paths/base64 that must be turned back into a `Blob` for the presigned PUT  |
| File input            | Portfolio dropzone + `<input multiple :accept>`; 500 MB caps                   | `onboarding/components/PortfolioUploadGrid.vue:152-164`; `usePortfolioUpload.ts:63-85`                                                                                                                          | Onboarding portfolio (AH-004)          | **works-in-WebView** for the picker; drag-and-drop is dead weight on a phone                                                                                                                                                |
| File input            | Avatar dropzone + input; 5 MB; `createObjectURL` preview                       | `AvatarUploadDrop.vue:112-124`; `useAvatarUpload.ts:42-48,80,109`                                                                                                                                               | Onboarding step 2 / profile            | **works-in-WebView**                                                                                                                                                                                                        |
| File input            | Video poster via `<video>` + canvas                                            | `internal/captureVideoPoster.ts:28-33,55-96`                                                                                                                                                                    | Portfolio video                        | **needs-rethink** — WKWebView codec/seek quirks; already best-effort → `null`, so it fails silently rather than loudly                                                                                                      |
| File input            | Message attachments, no `accept`                                               | `messaging/components/RelationshipThreadView.vue:563-569`; `ChatPanel.vue:376-382`                                                                                                                              | Both thread models                     | **works-in-WebView**                                                                                                                                                                                                        |
| Presigned PUT         | `uploadToPresignedUrl` — axios PUT, `withCredentials: false`, 10-min timeout   | `packages/api-client/src/presigned.ts:31-70`; callers `usePortfolioUpload.ts:330,342`, `CreatorAssignmentDetailPage.vue:322`, `RelationshipThreadView.vue:264`, `ChatPanel.vue:163`                             | All media uploads                      | **works-in-WebView** once S3 CORS allows the origin. **needs-rethink for large video**: iOS suspends the WebView's XHR when the app backgrounds; a 500 MB PUT dies mid-flight unless a native background-upload path exists |
| Download              | Portfolio `<a :download>` on a signed URL; server forces `attachment`          | `packages/ui/src/components/PortfolioGallery.vue:290-294,330-334`; `PortfolioItemPresenter.php:127-130`                                                                                                         | Portfolio preview (AH-015)             | **needs-Capacitor-plugin** — WKWebView ignores `download`; save-to-Files needs `@capacitor/filesystem` (+ share sheet)                                                                                                      |
| Download              | Signed attachments opened `target=_blank`                                      | `creators/components/CreatorAttachmentsBlock.vue:36-38`; `CreatorAssignmentsPage.vue:192-194`; `CreatorAssignmentDetailPage.vue:566-568,649-650`; `ChatPanel.vue:275-279`; `RelationshipThreadView.vue:415-418` | Briefs, offers, contract PDF, messages | **needs-Capacitor-plugin** (`@capacitor/browser`) — `_blank` behaviour in a Capacitor WebView is a navigation-policy question, not a given; an in-app viewer is the consistent answer                                       |
| Download              | Recovery codes as a blob `<a download>`                                        | `auth/components/RecoveryCodesDisplay.vue:78-87`                                                                                                                                                                | 2FA enrolment                          | **needs-Capacitor-plugin** (`@capacitor/filesystem` / share)                                                                                                                                                                |
| Clipboard             | Portfolio copy-URL: `navigator.clipboard` + `execCommand` fallback             | `PortfolioGallery.vue:42-54`                                                                                                                                                                                    | Portfolio (AH-008)                     | **needs-Capacitor-plugin** (`@capacitor/clipboard`) for reliability                                                                                                                                                         |
| Clipboard             | Recovery codes `navigator.clipboard.writeText`, no fallback                    | `RecoveryCodesDisplay.vue:72-75`                                                                                                                                                                                | 2FA                                    | **needs-Capacitor-plugin** (`@capacitor/clipboard`)                                                                                                                                                                         |
| New-tab links         | Rich-brief renderer forces `target="_blank" rel="noopener noreferrer"`         | `composables/useRichBriefRenderer.ts:27,60` (+ DOMPurify hook `:63-66`)                                                                                                                                         | Briefs, descriptions (AH-081)          | **needs-Capacitor-plugin** (`@capacitor/browser`). One seam — the renderer — covers every brief link                                                                                                                        |
| New-tab links         | Bio links, social links, portfolio links, job examples / brand site            | `useBioRenderer.ts:46-48`; `packages/ui/…/SocialAccountList.vue:64-68`; `PortfolioGallery.vue:246-251`; `CreatorJobDetailPage.vue:284-297`                                                                      | Profile, jobs                          | **needs-Capacitor-plugin** (`@capacitor/browser`)                                                                                                                                                                           |
| New-tab links         | `/creator-guide.pdf` (a `public/` asset) `_blank`                              | `auth/components/CreatorGuideCta.vue:25,41-46`                                                                                                                                                                  | Sign-in                                | **needs-Capacitor-plugin** — the asset ships in the bundle; opening a PDF needs a viewer                                                                                                                                    |
| mailto / tel          | Footer `mailto:`; profile `tel:`; WhatsApp shown as text                       | `AuthMarketingFooter.vue:76`; `CreatorProfileContent.vue:397-398,405-410`                                                                                                                                       | Sign-in, profile                       | **works-in-WebView** (OS handoff)                                                                                                                                                                                           |
| Storage               | `localStorage` — locale, theme (`catalyst.main.locale`, `catalyst.main.theme`) | `useLocalePreference.ts:29,45,65,78`; `useThemePreference.ts:66,115,136-138`                                                                                                                                    | All                                    | **works-in-WebView** (iOS may evict WebView storage under pressure; `@capacitor/preferences` optional). **The auth token must not go here** — Keychain/Keystore behind biometrics                                           |
| Storage               | `document.cookie` XSRF read                                                    | `http.ts:167-172,316-338`                                                                                                                                                                                       | Every write                            | **needs-rethink** — M2                                                                                                                                                                                                      |
| navigator / lifecycle | `visibilitychange` pauses the bell poll; `prefers-reduced-motion`              | `useNotificationPoll.ts:111-128`; `OnboardingLayout.vue:100-101`                                                                                                                                                | Bell, wizard chrome                    | **works-in-WebView**. The connectivity banner (offline-grace) has **no existing hook** — needs `@capacitor/network`                                                                                                         |
| Third-party           | Meta Pixel injected on sign-in (`connect.facebook.net`, `_fbp` cookie)         | `auth/internal/metaPixel.ts:55,62,100-123`; mounted `SignInPage.vue:107-112`                                                                                                                                    | Sign-in                                | **needs-rethink** — iOS App Tracking Transparency + App Privacy labels; the native build likely gates it off. Pixel ID is hardcoded by decision (`metaPixel.ts:55`), so it's a code fork, not an env flip                   |
| Vendor redirect       | KYC `window.location.href = hosted_flow_url`                                   | `onboarding/pages/Step5KycPage.vue:75`                                                                                                                                                                          | Onboarding KYC                         | **needs-rethink** — full-page navigation away from the app's origin; the return must land back in the app                                                                                                                   |
| Vendor redirect       | Stripe Connect `location.href = onboarding_url`                                | `Step7PayoutPage.vue:67`; return URL `apps/api/config/integrations.php:112`                                                                                                                                     | Onboarding payout                      | **needs-rethink** — and see cross-chunk note ① below                                                                                                                                                                        |
| Vendor redirect       | E-sign `location.href = signing_url`                                           | `Step8ContractPage.vue:100`; API return endpoints `Creators/Routes/api.php:129-138`                                                                                                                             | Onboarding contract (flag-ON path)     | **needs-rethink**                                                                                                                                                                                                           |
| Router                | `createWebHistory(import.meta.env.BASE_URL)`                                   | `apps/main/src/core/router/index.ts:112-114`                                                                                                                                                                    | Whole SPA                              | **works-in-WebView** (Capacitor's local server falls back to `index.html`) — confirm deep-link paths on device                                                                                                              |
| API base              | `VITE_API_BASE_URL ?? '/api/v1'`                                               | `core/api/index.ts:116`; typed `env.d.ts:4`                                                                                                                                                                     | All                                    | **works** — already injectable; the native build sets an absolute URL (M6)                                                                                                                                                  |

---

## M4 — The notification system's push seams

### M4.1 The channel architecture post-AH-083

- **No Laravel Notifications.** `Notifiable` was deliberately removed (`User.php:64-70`); nothing in `app/` uses
  `Illuminate\Notifications`. The subsystem is custom.
- **`NotificationService::notify()` is in-app only** — it checks `isChannelEnabled(…, InApp)` and writes a
  `notifications` row (`apps/api/app/Modules/Notifications/Services/NotificationService.php:41-60`); its own
  docblock says email ownership was deliberately _not_ folded in (`:17-22`).
- **Mail forks at each call site**, `Mail::to()->queue()` beside the `notify()` call, gated by Pennant flags,
  never by the email preference:

| Call site                                                                              | In-app leg                                 | Mail leg                                             | Gate                                                                                          |
| -------------------------------------------------------------------------------------- | ------------------------------------------ | ---------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| `Campaigns/Listeners/SendAssignmentNotifications.php`                                  | per-helper `notify()` (e.g. invite `:297`) | `queueInviteMail` `:316-324`; review mail `:406-417` | Invite: `missing_creator_mails_enabled`; review: AH-069 `suppressEmail`; others unconditional |
| `Campaigns/Services/CampaignApplicationNotifier.php`                                   | `:110`, `:168`, `:227`                     | private `queue()` `:273-286`                         | `application_notifications_enabled` (mail only)                                               |
| `Campaigns/Services/JobPostedFanOutService.php`                                        | `emitInApp` `:249-262`                     | `queueMail` `:265-273`                               | `job_posted_notifications_enabled` gates **both** (`:119`)                                    |
| `Messaging/Services/SendMessageNotifications.php`                                      | creator leg `:80-86`                       | `DebouncedMessageMailer::maybeSend` `:88-94`         | flag + 30-min debounce inside the mailer                                                      |
| `Messaging/Services/RelationshipMessageNotifications.php`                              | creator leg `:88-94`                       | `maybeSend` `:96-102`                                | same                                                                                          |
| `Campaigns/Http/Controllers/CampaignAssignmentContractController.php::notifyCreator()` | **none**                                   | `ContractAttachedMail` `:269-286`                    | `per_campaign_contract_enabled` on the action                                                 |

### M4.2 What a third channel costs structurally

- **The obvious single seam is `notify()`** — every live in-app emit already passes through it. A push leg
  there reaches all current dual-emit sites at once. Three caveats make it less than free:
  1. **The early return.** `notify()` returns `null` when in-app is off (`NotificationService.php:48-50`). A push
     leg placed after it silently couples push to the in-app preference — a creator who silences in-app would
     lose push too. The push check has to run independently of that short-circuit.
  2. **Seams it misses.** `ContractAttachedMail` has no `NotificationType` at all (the AH-083 inventory's ⑨
     gap), so it can't push without a type; `JobPostedFanOutService` suppresses in-app wholesale when its flag is
     off (`:119`), so push inherits that kill switch whether wanted or not.
  3. **Transaction timing.** `SendAssignmentNotifications` runs inside the state machine's transaction with
     `after_commit => false` (`config/queue.php:48,57`; `tech-debt.md:2454-2470`). A queued push job from inside
     `notify()` inherits the same queue-before-commit exposure the open tech-debt entry names.
- **The per-type gating question** — the `NotificationChannel` enum gains a case (`NotificationChannel.php:21-23`,
  `defaultEnabled()` at `:28`), validation follows automatically (enum-backed), the column fits (`string(16)`).
  Whether push is per-type opt-out (default ON, like email) or opt-in is the M7 matrix decision.
- **Device-token storage — proposed shape, not decided.** Nothing exists (no table, no columns; the data model
  only promises "mobile push notification columns" at P2 — `03-DATA-MODEL.md:1317`). The natural shape: a new
  table, e.g. `push_devices` — `id`, `user_id` (FK, **cascade** — the M5 erase-set), `platform` (`ios`|`android`),
  `token` (unique), `app_version`, `last_seen_at`, timestamps — additive, no backfill. Token rotation (FCM
  rotates), invalid-token pruning on provider errors, and logout-unregisters are the lifecycle obligations.

### M4.3 The creator-recipient types and the push-worthy v1 set

There are **26** `NotificationType` cases (`NotificationType.php:44-157`), **19** live, and **15** that reach a
creator (14 creator-only + `agency_creator_relation.disconnected`, which is `both`) — plus the mail-only
contract-attached event, which makes the brief's "~16." Proposed v1 set = the AH-083/084 nine, mapped:

| #   | Event                    | Type(s)                                                                                   | Emit site                                                                    | Deep-link target                                                                                           | Payload has what the link needs?                                                                                                                                                                                                                                                                                      |
| --- | ------------------------ | ----------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| ①   | Campaign invite          | `assignment.invited` (`:44`)                                                              | `SendAssignmentNotifications.php:297`                                        | `creator.assignment.detail` `{ulid}` (`creators/routes.ts:66-67`)                                          | Yes — `data.assignment_ulid` (`:302-306`)                                                                                                                                                                                                                                                                             |
| ②   | Job posted               | `campaign.job_posted` (`:121`)                                                            | `JobPostedFanOutService.php:251`                                             | `creator.job.detail` `{ulid}` (`:94-95`)                                                                   | Via `subject` only — `data` carries names (`:258-261`); the campaign ULID is the subject's route key (`NotificationResource.php:71-83`). Fan-out volume: capped 50/run                                                                                                                                                |
| ③   | Application accepted     | `campaign_application.accepted` (`:156`)                                                  | `CampaignApplicationNotifier.php:168`                                        | `creator.assignment.detail`                                                                                | Yes — `assignment_ulid` (`:175-179`)                                                                                                                                                                                                                                                                                  |
| ④   | Application rejected     | `campaign_application.rejected` (`:157`)                                                  | `:227`                                                                       | `creator.jobs` (`:82-83`)                                                                                  | No ULID needed — lands on the list (`:231-234`)                                                                                                                                                                                                                                                                       |
| ⑤   | Draft changes requested  | `assignment.revision_requested` (`:50`)                                                   | `SendAssignmentNotifications.php:427`                                        | `creator.assignment.detail`                                                                                | Yes (`:432-438`)                                                                                                                                                                                                                                                                                                      |
| ⑥   | Draft approved           | `assignment.draft_approved` (`:51`)                                                       | `:427`                                                                       | `creator.assignment.detail`                                                                                | Yes. Open: does push mirror AH-069's mail suppression when approval also completes (`:406-417`)?                                                                                                                                                                                                                      |
| ⑦   | Assignment complete      | `assignment.completed_on_approval` (`:60`), `assignment.manually_verified` (`:61`)        | `:158`, `:234`                                                               | `creator.assignment.detail`                                                                                | Yes. The `live_verified` path still has no type (AH-083 inventory ⑦)                                                                                                                                                                                                                                                  |
| ⑧   | New agency message       | `message.received_by_creator` (`:78`); `message.relationship_received_by_creator` (`:86`) | `SendMessageNotifications.php:80`; `RelationshipMessageNotifications.php:88` | assignment detail (campaign chat); `creator.messages.thread` `{agencyUlid}` (`creators/routes.ts:148-151`) | Campaign: yes (`assignment_ulid`). **Relationship: no** — `data` carries `thread_ulid`, not `agency_ulid` (`RelationshipMessageNotifications.php:61-66`), and the route is keyed by agency. The mail builds it from `$agency` (`:96-102`); push can too, server-side. Open: push per message, or the 30-min debounce? |
| ⑨   | Contract action required | **none** — `ContractAttachedMail` is mail-only                                            | `CampaignAssignmentContractController.php:269-286`                           | `creator.assignment.detail`                                                                                | No type exists; needs one before it can push                                                                                                                                                                                                                                                                          |

Outside the nine, worth naming: `creator.approved` (`:70`, → `creator.dashboard`) — arguably the single
highest-value push for a creator who onboarded on the phone.

**The tap handler is net-new navigation code.** The in-app list does not navigate today — "D-7 deferred: row
click marks read IN PLACE — no navigation" (`notifications/components/NotificationCenter.vue:19-20`, `onRowClick`
`:128-142`). There is no `(type, data, subject) → route` resolver to reuse; the push deep-link resolver would be the
first, and the same function could close D-7 for the in-app list.

### M4.4 Provider reality — reported, not decided

- **Nothing installed:** no push/FCM/APNs/firebase package (`apps/api/composer.json:11-25`), no push keys in
  `config/services.php`, no `config/broadcasting.php`.
- **FCM HTTP v1** covers both platforms (APNs behind FCM with an uploaded APNs key).
- **`laravel-notification-channels/fcm`** is built for Laravel's `Notification` + `via()` system — the system this
  codebase deliberately removed (`User.php:64-70`). Adopting it means reintroducing `Notifiable` for one channel,
  which the `User.php` comment says would have to be "an intentional decision."
- **`kreait/laravel-firebase`** (the Firebase Admin SDK wrapper) called from a queued job fits the custom-service
  posture; **raw HTTP v1** via Laravel's `Http` client + a service-account OAuth token is the minimal option.
- **Client side:** `@capacitor/push-notifications` returns the **APNs** device token on iOS, not an FCM token; an
  FCM-everywhere backend needs the Firebase iOS SDK path (e.g. a Firebase-messaging Capacitor plugin) on iOS.
  This shapes the device-token table's `token` semantics.

---

## M5 — The account-deletion gap (Apple-mandatory)

### M5.1 Does self-deletion exist in any form?

**No.** Verified absent: no delete/erase route on `me`, users, or creators; no `destroy` in Identity / Creators /
Admin creator controllers; no `Prunable`/`MassPrunable`/`model:prune`; no erasure job; no SPA string or route. The
closest things are partial deletes (avatar, portfolio item, availability block, social disconnect —
`forceDelete` on the social row), agency-side relationship ending, and admin approve/reject/edit. **Wizard abandon
has no cleanup** — incomplete creators persist; AH-048's nudge emails them, never deletes them. The audit
vocabulary is ready and unused: `user.deleted` (`AuditAction.php:54`, reason-mandatory at `:509`) and
`creator.deleted` (`:88`).

Apple requires in-app account deletion for any app that supports account creation (sign-up is in the creator
surface — `auth.sign-up`); Google Play additionally requires a web deletion path. M6's checklist cross-refs this.

### M5.2 The data graph a creator row anchors

Hard-delete is blocked by design — the RESTRICT edges below are the schema saying "anonymize, don't delete."

| Table                                                         | Edge                                        | onDelete       | Soft-deletes    | Erase or survive (hint)                                                                          | Migration                                                |
| ------------------------------------------------------------- | ------------------------------------------- | -------------- | --------------- | ------------------------------------------------------------------------------------------------ | -------------------------------------------------------- |
| `creators`                                                    | `user_id`                                   | **restrict**   | yes             | Anonymize in place (FKs need the row)                                                            | `2026_05_14_100000_create_creators_table.php:39-42`      |
| `creators`                                                    | `signed_master_contract_id` (FK-less)       | none           | —               | Survive (points at a contract or an `integration_events` sentinel)                               | `:81`                                                    |
| `creator_social_accounts`                                     | `creator_id`                                | cascade        | yes             | Erase + revoke provider tokens (`05-SECURITY:66`)                                                | `2026_05_14_100001_…:31-34`                              |
| `creator_portfolio_items`                                     | `creator_id`                                | cascade        | yes             | Erase + S3                                                                                       | `2026_05_14_100002_…:32-35`                              |
| `creator_availability_blocks`                                 | `creator_id`                                | cascade        | no              | Erase                                                                                            | `2026_05_14_100003_…:34-37`                              |
| `creator_tax_profiles` (encrypted PII)                        | `creator_id`                                | cascade        | no              | Erase/null (`05-SECURITY:65`) — tension with tax retention                                       | `2026_05_14_100004_…:36-39`                              |
| `creator_payout_methods`                                      | `creator_id`                                | cascade        | no              | Erase row; the Stripe Connect account is a vendor-side act                                       | `2026_05_14_100005_…:29-32`                              |
| `creator_kyc_verifications` (encrypted)                       | `creator_id`                                | cascade        | no              | Erase; vendor-side KYC data is a vendor-side act                                                 | `2026_05_14_100006_…:33-36`                              |
| `agency_creator_relations`                                    | `creator_id`                                | cascade        | no              | **Tension** — the agency's roster history                                                        | `2026_05_14_100007_…:68-71`                              |
| `talent_pool_creators`                                        | `creator_id`                                | cascade        | no              | Erase                                                                                            | `2026_06_03_100003_…:45-48`                              |
| `brand_creator_blacklists`                                    | `creator_id`                                | cascade        | yes             | **Tension** — an agency's ban is its record                                                      | `2026_06_04_100000_…:55-58`                              |
| `campaign_assignments`                                        | `creator_id`                                | **restrict**   | yes             | Survive (the commercial anchor; future payments hang here)                                       | `2026_06_05_100001_…:64-67`                              |
| `campaign_applications`                                       | `creator_id`                                | **restrict**   | no              | Survive (agency record)                                                                          | `2026_07_27_110000_…:76-79`                              |
| `campaign_job_notifications`                                  | `creator_id`                                | cascade        | no              | Erase                                                                                            | `2026_07_27_110001_…:58-61`                              |
| `campaign_drafts`                                             | `submitted_by_creator_id`                   | set null       | no              | Survive the record; `media_attachments` JSON + S3 under `creators/{ulid}/drafts/` is the tension | `2026_06_05_110000_…:51-54`                              |
| `contracts`                                                   | `signed_by_creator_id`; polymorphic subject | set null; none | yes             | Survive (signed); `signed_signature_data` is encrypted PII (ip, user agent)                      | `2026_05_17_100000_…:69-70,92-95`                        |
| `relationship_threads`                                        | `creator_id`                                | **restrict**   | no              | Survive the thread                                                                               | `2026_06_29_100000_…:57-60`                              |
| `relationship_messages`                                       | `sender_user_id`                            | **restrict**   | yes (unwritten) | **Tension** — creator-sent bodies vs. the agency's conversation record                           | `2026_06_29_100001_…:57-60`                              |
| `messages` (campaign)                                         | `sender_user_id`                            | **restrict**   | yes (unwritten) | same                                                                                             | `2026_06_06_110001_…:56-59`                              |
| `message_read_receipts`, `relationship_message_read_receipts` | `user_id`                                   | cascade        | no              | Erase                                                                                            | `2026_06_06_110002_…:43-46`; `2026_06_29_100002_…:43-46` |
| `message_email_debounces`                                     | `recipient_user_id`                         | cascade        | no              | Erase                                                                                            | `2026_08_19_100000_…:54-57`                              |
| `notifications`                                               | `recipient_user_id`                         | **restrict**   | no              | Erase the creator's own feed                                                                     | `2026_06_06_100000_…:55-58`                              |
| `user_notification_preferences`                               | `user_id`                                   | cascade        | no              | Erase                                                                                            | `2026_06_06_100001_…:45-48`                              |
| `audit_logs`                                                  | `actor_id` (no FK)                          | none           | immutable       | Survive; identity anonymized via the user row (`05-SECURITY:67`)                                 | `2026_05_07_020000_…:45-52`                              |
| `admin_impersonation_sessions`                                | `impersonated_user_id`                      | cascade        | no              | **Tension** — cascade would delete an audit-adjacent record                                      | `2026_06_07_130000_…:45-46`                              |
| `sessions`, `personal_access_tokens`, `password_reset_tokens` | `user_id` / morph / email                   | none           | no              | Erase (and the future `push_devices`, M4)                                                        | `0001_01_01_000020_…:26`; `2026_05_07_003823_…:18`       |
| payments / escrow / invoices                                  | —                                           | —              | —               | Not built (Sprint 10); spec'd to survive as "Deleted User" for 7 years (`05-SECURITY:69-70`)     | —                                                        |

**S3 to purge** (the `media` disk): avatar `creators/{ulid}/avatar/…` (`AvatarUploadService.php:71`), portfolio
`creators/{ulid}/portfolio/…`, draft media `creators/{ulid}/drafts/…`, creator-uploaded message attachments
under `messages/{thread_ulid}/…` and `relationship-messages/{thread_ulid}/…` (shared prefixes — per-object, not
per-prefix), contract PDFs (`contracts.body_pdf_path`). **Plaintext PII on `creators`**: display name, bio,
phone, WhatsApp, address, date of birth, content companions.

### M5.3 The legal-retention tension — named, not resolved

The agency's records (signed contracts, assignments with fees, applications, the messages it sent, its blacklist
decisions, and — once Sprint 10 lands — payments with a 7-year obligation) collide with the creator's erasure
right on four rows: **messages the creator sent**, **draft media the agency approved**, **the relation/blacklist
history**, and **tax data**. The docs' answer is an admin approval queue "to handle legal exceptions"
(`05-SECURITY-COMPLIANCE.md:53`) — Apple accepts a non-instant process if the user can **initiate** it in-app and is
told the timeline, but the queue itself is a shell today.

### M5.4 Does soft-delete + anonymize fit the model conventions?

Partially. `User` and `Creator` both use `SoftDeletes` (`User.php:62`; `Creator.php:86`) with `deleted_at` columns
(users migration `:67`), and `users.email` is unique (`:33`) — so the docs' `deleted-{ulid}@deleted.local` rewrite
(`05-SECURITY:64`) frees the address for re-signup. The catch: **soft-deleting the `User` hides it from every
relation query**, and the agency-side resources that dereference `assignment → creator → user` would see `null`
(only 17 `withTrashed` calls exist in `app/`). Anonymize-in-place (row stays live, PII scrubbed, login blocked)
avoids that blast radius; soft-delete on top is optional. The model choice is a kickoff decision.

**Honest size:** an erasure engine (anonymize + purge + revoke across ~15 erase-set tables and 6 S3 families,
idempotent, dry-runnable, with a guarded command per §5.40), a request/approval surface (table + admin queue
filling the existing shell), an in-app initiation UI, and a Production-posture section that is anything but
`NONE`. **Its own chunk — plausibly two** (engine; request flow + admin queue).

---

## M6 — Build / deploy reality

### M6.1 Where the Capacitor project lands — both shapes

- **Shape A — inside `apps/main`** (`capacitor.config.ts` + `ios/` + `android/` beside `src/`). One package; the
  `@capacitor/*` JS plugins sit next to the Vue code that imports them; `webDir: 'dist'`. Costs: native projects
  inside a Vue app's ESLint/Vitest/`tsconfig` scopes (exclusions needed), `pnpm build` in CI grows a native-mode
  variant, and the agency surface ships in the same bundle (role routing, not two builds — `RESUMPTION-TEMPLATE.md:31-33`).
- **Shape B — `apps/mobile`** (a new workspace package holding `capacitor.config.ts` + native projects, `webDir`
  pointing at a native-mode `apps/main` build). Costs: the plugin **JS** dependencies still have to be importable
  from `apps/main` (that's where `isNativePlatform()` and push/biometric calls live), so the split is
  "native projects here, plugin JS there" — two packages to keep in version lockstep. `pnpm-workspace.yaml`'s
  `apps/*`-by-name list needs the entry.
- Either way, the bundle contains the agency surface too, unless a creator-only entry is carved — not trivial,
  since the router is one tree (`core/router/index.ts`).

### M6.2 Env / config per environment

`VITE_API_BASE_URL` is already the injection point (`core/api/index.ts:116`; `env.d.ts:4`;
`apps/main/.env.example` sets `/api/v1`). A native build needs an **absolute** API URL per environment (Vite
`--mode` files), plus `VITE_SENTRY_DSN_MAIN` (`env.d.ts:6`), plus whatever replaces the hardcoded Meta Pixel
(`metaPixel.ts:55`). The CSRF preflight already anchors at the host root for absolute URLs (`http.ts:292-302`),
though token mode skips it. Backend env adds: `FRONTEND_EXTRA_ORIGINS` (`cors.php:38`), the
`SANCTUM_STATEFUL_DOMAINS` read-back (M2.4), FCM credentials.

### M6.3 What CI can and can't do

CI is `ubuntu-latest` only — backend, frontend, two Playwright legs (`.github/workflows/ci.yml:20,111,168`).
**No macOS runner → no iOS build, no iOS test in CI**; iOS builds, signing, and TestFlight uploads are local Xcode
on Pedram's Mac. Android **can** build on Ubuntu (JDK + Gradle) if wanted. And the gap from the method note
stands: nothing automated renders the creator surface in **WebKit** (`playwright.config.ts:100-113`), so WKWebView
regressions are eyes-on only.

### M6.4 OTA vs. store

- **Native changes always need a store release** — plugins, permissions, icons/splash, entitlements (push), the
  Capacitor version itself.
- **JS/asset changes** can ship over the air through a live-update channel: hosted services (Capgo, Ionic's
  Appflow) or self-hosted (`@capgo/capacitor-updater` + an S3 bucket). Apple's guidelines permit downloaded
  interpreted code that doesn't change the app's primary purpose — verify the current wording at kickoff.
  Costs: a subscription or a small hosting/signing pipeline, plus a channel/rollback discipline.
- **Manual (store-only)**: free, slow — a review cycle per UI fix.
- **New constraint either way — version skew.** Today an SPA deploy reaches every browser on reload (the
  deploy-log tracks it as "the bundle reached browsers" — `docs/runbooks/deploy-log.md:403`). Store builds
  linger for months, so the API contract gains a **backward-compatibility window** and the app needs a
  minimum-version gate. Nothing in the deploy discipline models that yet.

### M6.5 Store-presence checklist

- Apple Developer Program + Google Play Console accounts; **bundle / application IDs** (e.g. a
  `.creator`-suffixed reverse-DNS id).
- **Privacy-policy URL** — exists externally: `https://www.catalyst-growth.com/legal/privacy`
  (`apps/main/src/modules/auth/components/footerLinks.ts:30,75`). App Store "App Privacy" labels and Play "Data
  safety" must declare Sentry, the Meta Pixel (if kept), and media uploads.
- **Account deletion** — in-app initiation (Apple) + a web deletion URL (Google) → **M5 is a store blocker**.
- **Sign in with Apple** — not triggered as far as the code shows: there's no third-party _login_; the social
  OAuth flags are account-_connect_ for Instagram / TikTok / YouTube (`docs/feature-flags.md:40-42`).
- **Review demo account** — reviewers need an approved creator login with data to see past onboarding.
- **Universal / app links** — emailed links (verify-email, reset-password, invite accept, every notification mail's
  CTA) point at web routes today; opening them in the app needs `apple-app-site-association` + `assetlinks.json`
  under `apps/main/public/.well-known/` (does not exist) and a native route handler. This is the same resolver M4's
  push tap needs.
- Icon / splash assets (none exist beyond `public/favicon.svg`); push entitlement + an APNs key uploaded to Firebase.

---

## M7 — The AH-084 interaction

### M7.1 AH-084's planned shape (as recorded)

AH-084 has no kickoff yet. What exists is the deferral trail: AH-083's D5 (`missing-creator-mails-review.md:331`,
`missing-creator-mails-plan.md:391,553`) and the tech-debt entry's resolution — "read `notification_preferences`
for the `email` channel inside the emission path (one shared guard, since every mailable needs it), expose the
email toggles on the preferences page" — estimated "1–2 days, most of it deciding the default and the
transactional-mail exemption list" (`docs/tech-debt.md:2430-2433`). The AH-083 inventory already confirmed no
migration is needed and absent-means-ON holds for free (`creator-mail-set-inventory.md:20-35`).

### M7.2 Where push slots in — storage

Identical to email. `user_notification_preferences` is sparse, `(user_id, type, channel)` unique, `channel` is
`string(16)` (migration `2026_06_06_100001_…:51,54,60`); `setPreference()` deletes on return-to-default
(`NotificationService.php:79-100`). Push adds one enum case (`NotificationChannel.php:21-23` + `defaultEnabled()`
`:28`) and one union member (`packages/api-client/src/types/notification.ts:166`). **No migration for either
channel.** Open, push-only: preferences are per-**user**, not per-**device** — a creator with a phone and a tablet
gets one switch.

### M7.3 Where push slots in — the page

The page loops one `v-switch` per `(type, channel)` pair, stacked under each type
(`NotificationPreferencesPage.vue:195-205`), in a 640px card (`:151`), driven by each type's `channels` array in
`LIVE_TYPES` (`apps/main/src/modules/notifications/templates.ts:92`, e.g. `:105-109`). Today that's one switch per
type (two for campaign messaging — `:168-172`). With email it becomes two; with push, three — **up to 45 stacked
switches for 15 creator types.** That's the matrix layout (rows = types, columns = channels) that both AH-084 and
this arc need, and on a 390px screen it's the M1 re-check. Three couplings ride along:

- **The "no dead control" rule** (`templates.ts:60-64`, restated at `:225-230`) — a toggle may appear only when a
  consumer delivers it. For push, that raises a question email doesn't: a web user with **no registered device**
  seeing a push switch is a dead control by this rule's own definition.
- **The always-on set** — `preference: null` types (`templates.ts:193-208`, relation lifecycle) and the
  tech-debt entry's "transactional-mail exemption list" are the same question per channel.
- **The copy** — `notifications.preferences.description` says "in-app" (`locales/en/notifications.json:44`) and
  `channels` has `in_app` + `digest` only (`:57-60`); both chunks edit the same keys ×24.

### M7.4 The coupling, for the kickoff

- **Precede** — AH-084 ships standalone (its own 1–2-day estimate), builds the matrix + the shared send-site
  guard; push later adds a column and a call. Lowest coupling risk; AH-084 also closes a live consent gap that
  exists regardless of the app.
- **Merge** — one chunk designs the matrix for three channels at once; the arc gets bigger and AH-084's consent fix
  waits on the arc's auth gate (M2).
- **Follow** — push designs the matrix first and AH-084 retrofits email into it, or the matrix gets built twice.
  The brief's "build it ONCE" rules this out in practice.

Sequencing is Pedram's call at kickoff.

---

## Cross-chunk notes (latent issues found during this read)

1. **The vendor-redirect return path looks unfinished, independent of this arc.** All three wizard vendor
   pages build a `useVendorBounce` handle (`Step5KycPage.vue:47`, `Step7PayoutPage.vue:40`, `Step8ContractPage.vue:43`)
   and read its status, but **no production code calls `bounce.start()`** — only the composable's own spec does
   (`useVendorBounce.spec.ts:42-163`). And Stripe Connect's `return_url` defaults to the SPA path
   `/onboarding/payout/return` (`apps/api/config/integrations.php:112`), which is **not a registered route**
   (`onboarding/routes.ts` registers eight paths, none `/return`); the API's own return endpoints are JSON
   (`Creators/Routes/api.php:129-138`). Whether this bites today depends on `kyc_verification_enabled` /
   `creator_payout_method_enabled` / `contract_signing_enabled` (`docs/feature-flags.md:43-45`) being armed in
   production — read them back live per the house pattern before assuming. In the native app it bites for sure.
2. **Relationship-message notifications can't deep-link from their payload alone** — `data` carries `thread_ulid`
   (`RelationshipMessageNotifications.php:61-66`) but the creator route is keyed by `agencyUlid`
   (`creators/routes.ts:148-151`). Harmless today (D-7: the in-app list doesn't navigate); load-bearing for push
   and for D-7's eventual close.
3. **No automated WebKit rendering anywhere** — named in the method note; relevant to any iOS-facing work, not
   only this arc.

---

## What this document does not do

Per the request: no decisions, no plan, no code. The four shaping answers are the load-bearing findings for
kickoff; every section after them is the supporting inventory. Choices named and left open: token vs. remote-URL
shell (M2.5), token expiry and scope (M2.3), the `auth:sanctum` guard-widening mitigation (M2.4), Shape A vs. B
(M6.1), OTA vs. store (M6.4), the push v1 set and per-message vs. debounced push (M4.3), the FCM package (M4.4),
anonymize-only vs. anonymize + soft-delete and the retention list (M5.3–M5.4), AH-084 precede vs. merge (M7.4).
