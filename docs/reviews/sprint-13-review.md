# Sprint 13 — Admin Panel Core — Review

**Status:** Closed.

**Reviewer:** drafted by Cursor (implementation); awaiting independent review + merge.

**Reviewed against:** the Sprint 13 kickoff + the approved plan (shell-first blocker, the 14 sub-steps in order, Q1–Q4 confirmed, the impersonation focused-review block S9–S11, the coming-soon/shell posture, the standing standards), `docs/09-ADMIN-PANEL.md` (§5 shell + nav, §6 surfaces, §6.8 impersonation, §6.6 payment surfaces deferred), `docs/security/tenancy.md` §4 (the cross-tenant allowlist + the platform_admin bounded bypass), `PROJECT-WORKFLOW.md §5` (5.18 Pint/Larastan CI-authority, 5.35 break-revert), and the closed S11.0 notification subsystem (the drop-in admin consumer).

This sprint ships the admin panel shell and **every NON-payment Phase-1 admin surface**, plus the full impersonation vertical (core + per-request enforcement + dual-audit). The payment-touching surfaces ship as discrete swappable coming-soon blocks (D-13); the GDPR queues ship as `[]`-returning shells (D-11). **Acceptance-bar honesty is recorded below + in `tech-debt.md`.**

---

## ⚠ Impersonation — the focused security block (S9–S11)

Built as **distinct, testable assertions** per the kickoff. Backend evidence lives in [`AdminImpersonationCoreTest`](../../apps/api/tests/Feature/Modules/Admin/AdminImpersonationCoreTest.php) (core start/end/no-escalation-at-start) and [`EnforceImpersonationTest`](../../apps/api/tests/Feature/Modules/Admin/EnforceImpersonationTest.php) (per-request enforcement). **32 backend security tests green** across these two files + `AgencySuspensionLoginTest`.

### 1 · TTL enforced server-side — break-revert proven (§5.35)

The TTL authority is the DB row `admin_impersonation_sessions.expires_at` (Q2), NOT a cookie or a frontend timer. [`EnforceImpersonation`](../../apps/api/app/Modules/Identity/Http/Middleware/EnforceImpersonation.php) runs per request on the `web` session: an expired marker is **refused (401 `admin.impersonation.expired`), the row is ended, and the session is shredded** — not advisory.

- **Assertion:** "REJECTS an expired impersonation, ends the row, and shreds the session (TTL break-revert)" — seeds `expires_at = now()-15m`, hits a benign route → 401, `ended_at` stamped, `admin.impersonation.ended` audited.
- **Break-revert (§5.35):** removing the `isExpired()` branch in the middleware flips this assertion red (the expired session would 200 through) while the live-session pass-through stays green. That is the proof the server-side check — not the frontend countdown — is load-bearing. An advisory TTL the backend doesn't enforce is a false-security FAIL; this is not that.
- **Orphan guard:** an already-`ended_at` marker is likewise refused (401 `admin.impersonation.session_invalid`).

### 2 · The four hard-blocks — refused at the API (403/no-op), NOT UI-hidden

Four distinct assertions, each hitting the action **while impersonating** and proving the middleware refuses it (not that a button is hidden). The patterns live in [`HardBlockedActions`](../../apps/api/app/Core/Impersonation/HardBlockedActions.php) (route-name `fnmatch`), deliberately OUT of the Identity module so the i18n-codes architecture test doesn't harvest `auth.*` route literals as error codes.

| #   | Hard-blocked action | Assertion                                               |
| --- | ------------------- | ------------------------------------------------------- |
| 1   | Password change     | "hard-blocks #1 password change while impersonating"    |
| 2   | Two-factor disable  | "hard-blocks #2 two-factor disable while impersonating" |
| 3   | Contract signing    | "hard-blocks #3 contract signing while impersonating"   |
| 4   | Payment release     | "hard-blocks #4 payment release while impersonating"    |

Counter-assertion: "does NOT block the same hard-block routes when NOT impersonating" — proves the block is conditional on the impersonation context, not a blanket route disable.

### 3 · Dual-audit is queryable (the column, not metadata) — Q3

Audit posture: **actor = the impersonated user; `impersonator_user_id` = the admin** — a first-class nullable column on `audit_logs` (migration [`2026_06_07_130001`](../../apps/api/database/migrations/2026_06_07_130001_add_impersonator_to_audit_logs_table.php)), populated by [`AuditLogger`](../../apps/api/app/Modules/Audit/Services/AuditLogger.php) from the [`ImpersonationContext`](../../apps/api/app/Core/Impersonation/ImpersonationContext.php) singleton (mirrors `TenancyContext`, the pattern `AuditLogger` already reads).

- **Assertion:** "writes dual-audit (actor = impersonated, impersonator = admin) and is queryable by impersonator" — an impersonated action writes both fields AND the test **queries by `impersonator_user_id`** (the incident-review query "every action impersonator Y performed" a JSON metadata field can't serve first-class).

### 4 · No-escalation — three ways

- **Two-cookie isolation:** "an impersonated web session cannot reach /admin/\* (two-cookie isolation)" — the impersonated `web` session can't touch the admin (`web_admin`) surface.
- **Can't mutate the admin's credentials:** "the impersonated session cannot mutate credentials (2FA/password hard-blocked)".
- **Can't nest / outlive:** "start refuses a second active session (no nesting)" — [`ImpersonationService::start`](../../apps/api/app/Modules/Identity/Services/ImpersonationService.php) refuses if a live (unexpired, unended) session already exists; it also refuses self + platform_admin targets (checked self-before-admin so the error is specific). TTL (§1) bounds the lifetime.

### 5 · Tightened admin session timeout (S11)

[`useIdleTimeout`](../../apps/admin/src/modules/auth/composables/useIdleTimeout.ts) (wired in [`AdminLayout`](../../apps/admin/src/core/layouts/AdminLayout.vue)) enforces a 30-min idle (reset on activity) + 8-h absolute cap (never reset). Both trigger logout → sign-in. ⚠ This is a CLIENT-side reduced-window control; the authoritative server-side bound stays `config('session.lifetime')` (a server-side absolute cap is recorded in `tech-debt.md` — and is distinct from the impersonation TTL, which IS server-authoritative).

---

## Suspend genuinely blocks login at the auth layer (the general-pass check) — Q1

`is_active` has never been read before, so [`AgencySuspensionLoginTest`](../../apps/api/tests/Feature/Modules/Identity/AgencySuspensionLoginTest.php) IS the behaviour. Enforcement lands in `AuthService::login` (reusing the security-ordered login graph, not a second middleware) right after the existing locked/suspended checks.

- **No session:** "blocks a suspended agency user at login with no session" — 423 `auth.account_locked.suspended`, `assertGuest('web')`, a `LoginFailed{reason: agency_suspended}` event.
- **⚠ Multi-agency, deliberate (Q1):** block on the **PRIMARY (acting) agency** only.
  - "blocks when the PRIMARY agency is suspended even if a second agency is healthy" → 423.
  - "does NOT lock out a user whose primary agency is healthy but a secondary agency is suspended" → 200 + authenticated. A user with a second healthy agency is not locked out by an unrelated suspension.
- **Structural no-op:** "does not block a user with no agency membership" — a creator (no acting agency) logs in normally; the gate is scoped to agency users.

---

## The 14 sub-steps — as built

| #   | Sub-step                                                                   | Notes / evidence                                                                                                                                                                                                              |
| --- | -------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| S1  | Shell + env banner + full route table                                      | `AdminLayout` owns `<v-app>`; declarative `NAV_ENTRIES`; env banner from `VITE_DEPLOY_ENV` (Q4 — a build-time display cue, not an auth control). The `meta.layout: 'admin'` migration was surfaced as a divergence + applied. |
| S2  | Admin module backend scaffold + `AuditAction` enum-add (+`requiresReason`) | Net-new admin verbs added to `AuditActionEnumTest` (the tripwire); `NotificationTypeEnumTest` untouched.                                                                                                                      |
| S3  | Agency mgmt + AuthService suspend enforcement                              | `AdminAgencyManagementTest` + `AgencySuspensionLoginTest` (above). Transactional audit on each flip.                                                                                                                          |
| S4  | Creator KYC queue + detail gaps                                            | Assignments/audit history; the payment section is a discrete swappable block (`CreatorPaymentSection`, coming-soon).                                                                                                          |
| S5  | Audit-log viewer                                                           | Cursor-paginated, indexed filters, cross-agency (`AdminAuditLogViewerTest`).                                                                                                                                                  |
| S6  | Feature-flag toggle UI + API                                               | `Feature::activate/deactivate` over DB-backed Pennant + `feature_flag.toggled` audit (reason required). Per-tenant overrides → tech-debt.                                                                                     |
| S7  | Dashboard non-payment stats + audit feed                                   | Payment/dispute cards are stable null placeholders (D-13).                                                                                                                                                                    |
| S8  | Operations Horizon embed + health probe                                    | `viewHorizon` gate + `UseAdminSessionCookie` on `/horizon`; `AdminHealthController` (DB+cache liveness). `AdminHorizonGateTest`.                                                                                              |
| S9  | Impersonation core                                                         | `admin_impersonation_sessions` (migration `2026_06_07_130000`) + start/claim/end + dual-session + one-time token.                                                                                                             |
| S10 | Impersonation enforcement + dual audit                                     | The security block above. `EnforceImpersonation` global on the `api` group.                                                                                                                                                   |
| S11 | Admin session-timeout tightening                                           | `useIdleTimeout` (30-idle / 8-absolute).                                                                                                                                                                                      |
| S12 | GDPR compliance shells                                                     | `AdminComplianceController` → `data: []` + `meta.shell: true` (200, not 404); SPA renders shell-state. `AdminComplianceShellTest`.                                                                                            |
| S13 | Notification admin consumer shell + coming-soon set                        | `AdminAlertsController` (the admin's own operational alerts, user-level-above-tenancy); payment-event alerts held back under `meta.payment_alerts`. `AdminAlertsTest`.                                                        |
| S14 | Docs + this review (uncommitted)                                           | `tenancy.md §4` allowlist (+16 admin rows), `tech-debt.md` (the coming-soon/shell seams + acceptance-bar honesty), this file.                                                                                                 |

---

## ⚠ Acceptance-bar honesty

This sprint meets the acceptance bar for **every NON-payment admin task**. The payment-investigation / dispute / refund surfaces (`09-ADMIN-PANEL §6.6`) are coming-soon this sprint and have nothing to operate on without S10 (escrow). **Full Sprint-13 acceptance lands when S10 lights the payment panels** — at which point the coming-soon blocks (payments nav, dashboard payment/dispute cards, creator-detail payment section, payment-event alerts) close by swap, not rebuild. Recorded in `tech-debt.md`.

---

## Verification

- **Backend:** the Sprint-13 backend suites green — incl. `tests/Feature/Modules/{Admin,Notifications}` (122) and the impersonation + suspend security files (32). The TTL break-revert performed + reverted clean. **Pint clean + Larastan clean** (§5.18) on the touched files.
- **Admin SPA:** full suite green (**382 tests**, 48 files); `vue-tsc` + ESLint clean.
- **Main SPA:** the impersonation claim page + banner + best-effort hydrate green within the full suite.
- **i18n:** new `compliance` + `alerts` namespaces deep-merged into all three locales (en/pt/it); the architecture i18n-codes + no-hard-coded-colors tests stay green (hard-block route literals moved to `Core\Impersonation\HardBlockedActions`; the main-SPA banner uses design tokens).

## Files (high level)

**Backend new:** `AdminComplianceController`, `AdminAlertsController`, `Core/Impersonation/{ImpersonationContext,HardBlockedActions}`, `Identity/Http/Middleware/EnforceImpersonation`, `Identity/Services/ImpersonationService` + controllers/requests/model/exception, migrations `2026_06_07_130000`/`130001`, and the Admin/Identity feature tests cited above.
**Backend edited:** `Admin/Routes/api.php`, `Identity/Routes/api.php`, `bootstrap/app.php`, `AuditLogger`, `AuditLog`, `AuditAction`, `NotificationType` (+`paymentAlerts()`/`isPaymentAlert()` partition), `AuthService` (suspend).
**Admin SPA:** the `dashboard/agencies/creators/payments/audit/alerts/support/operations/compliance/feature-flags` modules + `core/{layouts,nav,pages,composables}` + the i18n bundle.
**Main SPA:** the `impersonation` module (claim page, banner, store, api, routes) + `App.vue` hydrate.
**Docs (uncommitted):** `docs/security/tenancy.md`, `docs/tech-debt.md`, this review.

> **Note for the reviewer:** the docs + this review file are intentionally **uncommitted** — they route to chat for the independent review + merge.

---

## Independent review — verdict (appended to Cursor's draft)

**Status:** Closed. Spot-check passed (no PMC). Sprint 13 — Admin Panel Core complete (single chunk; impersonation given a focused security pass within the review). Acceptance: every NON-payment Phase-1 admin task; full acceptance lands when S10 lights the coming-soon payment panels (by swap, not rebuild).

**Reviewed against:** the Sprint 13 kickoff (D-1…D-13 + Q1–Q4) + the approved plan (shell-first, 14 sub-steps, the S9–S11 impersonation block), `09-ADMIN-PANEL.md`, `PROJECT-WORKFLOW.md §5` (5.35 break-revert).

### ⚠ Impersonation — focused security block (verified at test-body level)

| Anchor                                          | Evidence                                                                                                                                                                                                                                                                                                                        |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| TTL server-authoritative, break-revert proven   | `isExpired()` reads the DB row's `expires_at`; middleware terminates + tears down + 401 `admin.impersonation.expired`. Break-revert: removing the `isExpired()` branch → expired session 200s through (live stays green); restored → 32 green, empty diff. Enforced at the backend, not advisory.                               |
| Four hard-blocks refuse at the API, not UI-hide | password-change / 2FA-disable / contract-sign / payment-release each 403 `action_blocked` while impersonating; counter-assertion proves the routes 200 when NOT impersonating; `HardBlockedActions` `fnmatch` globs cover all four (exact literals + `*.contract.*`/`*.payout.release` arming the coming-soon payment surface). |
| Dual-audit queryable by impersonator            | `actor_id = impersonated`, `impersonator_user_id = admin` (first-class column); test runs `where('impersonator_user_id', admin)->get()` — the column filter, not a single-row read.                                                                                                                                             |
| No-escalation, three ways                       | Two-cookie isolation (impersonated `web` → `/admin/*` 401); no-nesting refused at `ImpersonationService::start` on a LIVE session (`ended_at IS NULL AND expires_at > now()` — the complement of the middleware reject); self-before-admin ordering; TTL bounds lifetime.                                                       |

### General pass — load-bearing anchor

| Anchor                                           | Evidence                                                                                                                                                                                                                                                                                                                                                       |
| ------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Suspend blocks at the auth layer, primary-scoped | `AuthService::login` → 423 `auth.account_locked.suspended` + `assertGuest('web')` + `agency_suspended` event. `primaryAgencyIsSuspended` reads only the first accepted membership (`orderBy('id')`) → case (c) primary-healthy/secondary-suspended **200 authenticated** (the Q1 deliberate scope; a naive "any membership" would over-lock-out and fail (c)). |

### Decisions confirmed (built as approved)

Shell-first (`AdminLayout` + the `meta.layout: 'admin'` migration, surfaced as a divergence); D-2 env banner from `VITE_DEPLOY_ENV` (Q4, build-time display cue); D-3 suspend at `login()` (Q1, primary-scoped); D-4 KYC queue + creator-detail gaps (payment section a discrete swappable block); D-5 audit viewer (indexed/cursor); D-6 flag toggle over Pennant `activate/deactivate` + `feature_flag.toggled` (reason required); D-7 dashboard non-payment stats + placeholder payment cards; D-8 Horizon embed behind the `viewHorizon` gate (custom ops views → tech-debt); D-9 impersonation (above); D-10 admin session-timeout tightening (client-side this sprint — see divergence); D-11 GDPR shells return `[]` + `meta.shell: true`; D-12 notification admin consumer (payment alerts held under `meta.payment_alerts`); D-13 the coming-soon payment set as discrete swappable blocks. Q2 DB `admin_impersonation_sessions` row as TTL authority; Q3 `impersonator_user_id` column.

### Divergences accepted

- **Admin absolute-session cap is client-side this sprint** (`useIdleTimeout` 30-idle/8-absolute); the server-side bound stays `session.lifetime`. Accepted **because the impersonation TTL — the actual sensitive boundary — IS server-authoritative** (verified above). The admin idle/absolute logout is a reduced-window UX control, not the auth boundary. Server-side absolute cap logged to tech-debt (low-risk defense-in-depth hardening).
- **Horizon embedded, not custom views** — the gated embed satisfies "Horizon-integrated" for P1.
- **S3 object-lock audit redundancy** NOT built (write-path hardening) → tech-debt.
- **Acceptance-bar honesty:** non-payment tasks this sprint; payment admin tasks placeheld, complete at S10.

### Verification

Backend: Admin + Notifications suites (122) + impersonation/suspend security files (32, 85 assertions) green; the TTL break-revert performed + reverted clean (empty diff post-restore). Admin SPA: 382 tests / 48 files green; vue-tsc + ESLint clean. Pint + Larastan clean. 16 new admin rows in `tenancy.md §4` (the cross-tenant allowlist + bounded-bypass rationale).

---

_Provenance: drafted by Cursor (Sprint 13 build, 14 sub-steps); verdict appended + spot-checked by Claude — focused security pass on impersonation (TTL break-revert / four API hard-blocks + counter + glob / queryable dual-audit / three-way no-escalation incl. service-level no-nesting all verified at test-body level) + general pass on suspend (primary-scoped multi-agency). Client-side session-cap divergence accepted (impersonation TTL is server-authoritative). No PMC._
