# Admin Automations — read-only inventory

- **Status:** Read-only inventory. No edits, no plan, no code this session.
- **Session orientation:** fresh session, oriented through AH-089's close-out.
  `HEAD` = `origin/main` = **`38ac25d1`** (`docs(reviews): AH-089 close-out — approved, pushed`),
  confirmed via `git rev-parse HEAD` / `git rev-parse origin/main`, tree clean. CI on this tip:
  [run 33051586082](https://github.com/pedram-kh/Engine/actions/runs/33051586082) — green (Frontend,
  Backend, both Playwright E2E suites), with the one carried finding below.
- **PROD-DATA RISK:** NONE — nothing in this document writes anything. The named eventual build
  (a rules table + reused Door-2 connect, fired automatically) is **LOW–MEDIUM**: same write the
  admin performs by hand today, now fired by a rule instead of a click.
- **Rider (carried from the AH-089 close):** §0 below — the `admin-sign-in.spec.ts` inline-TOTP
  redirect flake, diagnosed (mechanism named), not fixed, not shrugged past.

---

## §0 — The rider: `admin-sign-in.spec.ts` flake, bounded diagnosis

### What's actually happening (verified against CI, not assumed)

Pulled job logs for the admin E2E leg of the last 5 full-suite CI runs
(`33051586082`, `33047033227`, `33045135681`, `33035050105`, `33027191768` — spanning
2026‑08‑27 00:32 through 08:03, i.e. every push across the AH‑086b/AH‑087/AH‑088/AH‑089 sessions).
**All five show the identical single flaky test**, always the same assertion:

```
1) [chromium] › playwright/specs/admin-sign-in.spec.ts:69:3 › admin sign-in — happy path › admin with 2FA enrolled signs in via the inline TOTP reveal
   Error: expect(page).toHaveURL(expected) failed
   Expected pattern: /127\.0\.0\.1:5174\/$/
   Received string:  "http://127.0.0.1:5174/sign-in"
   Timeout: 5000ms
   Call log:
     5 × unexpected value "http://127.0.0.1:5174/sign-in"
     - navigated to "http://127.0.0.1:5174/sign-in"
     - navigated to "http://127.0.0.1:5174/"
     2 × unexpected value "http://127.0.0.1:5174/sign-in"
```

Attempt #1 fails this way; Playwright's `retries: 2` (`apps/admin/playwright.config.ts:81`) always
catches it on attempt #2, so the job stays green — this is exactly the CI-annotation-noise-on-a-green-job
pattern, not a hidden business-logic bug.

**Checked whether this is new:** it is not present in the four CI runs immediately before it
(`33022633470`, `32314875962`, `32224237989`, `32120322693` — all "2 passed", zero flaky), and
`git diff --stat 82a9ce98..2de36602 -- apps/admin apps/api/app/Modules/Identity` (the range spanning
the first flaky appearance) is **empty** — no code in the auth/admin surface changed between the
last clean run and the first flaky one. This rules out a regression in this session's own diff; the
trigger is either CI-runner timing variance or a very narrow, always-latent race that the runner's
current speed now exposes on (almost) every push.

### Mechanism, named

The URL trace inside the 5000ms window shows the app land on `/sign-in`, transiently reach `/`
(dashboard), then bounce back to `/sign-in` a second time — all before the assertion's timeout. Reading
the actual guard/store code:

- `apps/admin/src/modules/auth/pages/SignInPage.vue:81-83` — on TOTP success, `store.login()` sets
  `user` in-memory, then `router.push('/')`.
- `apps/admin/src/core/router/guards.ts:80-110` (`requireAuth`) — the dashboard route's guard
  unconditionally re-calls `store.bootstrap()`, which re-hits `GET /admin/me` even though `login()`
  already just resolved the identity. If that second round-trip reads the session as anonymous, its
  `catch` branch (`useAdminAuthStore.ts:180-186`) sets `user = null` and the guard bounces to
  `/sign-in`.
- `apps/admin/src/core/api/index.ts:60,88-99` — any OTHER 401 (not `/admin/me`, not
  `/admin/auth/login`) triggers the global `createUnauthorizedPolicy`, which also clears the user and
  redirects to `/sign-in`. `AdminLayout.vue` mounts `useNavBadges` on dashboard entry — a plausible
  second hit against the same race window.

This is the **same class the codebase already tracks**, not a new one. `docs/tech-debt.md:1306-1323`
("What we accepted in Sprint 3 Chunk 2 sub-step 1 push...") names this exact shape — "a race condition
between Playwright's navigation expectations and the SPA's bootstrap-vs-redirect interleaving" — for
two sibling specs (`2fa-enrollment-and-sign-in.spec.ts`, the admin chunk-7.6 MFA deep-link spec), and
explicitly records the team's decision at `docs/tech-debt.md:1315`: **"increasing their budget OR
wrapping them in spec-side retries would mask real regressions in the redirect chain"** — i.e. a
timeout bump was considered and deliberately rejected for this exact family once before.

### Disposition (named, not a fourth retry-and-shrug)

**Same tracked class, third occurrence, honestly environmental — extend the existing tech-debt entry
rather than open a new one or bump the timeout.** Reasoning:

1. The mechanism (bootstrap-vs-redirect interleaving right after a fresh login/session write) is
   already diagnosed and open in `docs/tech-debt.md`, with an explicit prior ruling against
   timeout-bumping this exact family — bumping `admin-sign-in.spec.ts`'s assertion now would
   contradict that standing decision on its sibling specs for no stated reason.
2. `retries: 2` already contains it — a real regression would need to fail 3 consecutive attempts to
   slip through, and the underlying surface (`AdminCreatorController`... no — here, `LoginController`
   - `TwoFactorLoginTest` + `EnsureMfaForAdmins`) has orthogonal Pest coverage that would independently
     catch a real auth regression.
3. Silence would NOT be legible here if left as-is: the existing tech-debt entry's spec list
   (`docs/tech-debt.md:1315` — "chunk-7.1 + chunk-7.6 specs") does not name `admin-sign-in.spec.ts`.
   That is the one gap: the entry should be extended to add this spec to its tracked list (a docs-only
   change), so the next reader doesn't re-diagnose from zero. **Not done this session** — read-only.

This is named and evidenced, not asserted: five real CI runs, the exact call-log trace, the guard code
that explains the trace, and the pre-existing tracked decision it must be reconciled with rather than
overridden. No edit made.

---

## I1 — The approval transition

- **Where creator approval is written:**
  `apps/api/app/Modules/Creators/Http/Controllers/Admin/AdminCreatorController.php:228-304`
  (`approve()`). Direct-write, no service class, no state machine: one `DB::transaction` at
  lines 265-283 does `forceFill(['application_status' => Approved, 'approved_at', 'approved_by_user_id',
...])->save()` + `Audit::log(AuditAction::CreatorApproved, ...)`. Idempotent — a second approve on an
  already-approved creator 409s at lines 232-240 without re-stamping. Guarded by
  `CreatorPolicy::approve` (route-bound) plus an inline KYC gate at lines 247-255 (`Verified` or
  `NotRequired` only).
- **What fires today, in order (lines 287-299):**
  1. `CreatorApprovedMail` queued in the creator's locale (`dispatchCreatorMail()`, lines 442-452).
  2. An in-app notification via `NotificationService::notify(..., NotificationType::CreatorApproved, ...)`
     (`notifyCreatorOfLifecycle()`, lines 463-477).
  3. The `creator.application_approved` audit row (verb `AuditAction::CreatorApproved`), written inside
     the same transaction as the status flip (so mail/notification only fire after a committed status
     change — both are outside the transaction, after `$creator->refresh()`).
  4. **No Laravel event is dispatched.** Grepped the whole `Creators` module
     (`apps/api/app/Modules/Creators`) for `event(new` / `::dispatch(` / `Event::listen` — zero hits, and
     there is no `Modules/Creators/Events/` directory at all. Approval is a plain controller method, not
     a state-machine transition with listeners (contrast `CampaignAssignmentStateMachine`, which does
     fire `AssignmentTransitioned` to 7 registered consumers).
- **Re-approval path — exists, but it is NOT "AH-013"** (AH-013 in `adhoc-changes-log.md:3194` is the
  two-pane WhatsApp-style messaging chunk; unrelated). The actual mechanism is **Sprint 4 Chunk 3,
  Cluster 6** (`apps/api/tests/Feature/Modules/Creators/CreatorReopenTest.php:19-24`):
  - `POST /api/v1/creators/me/reopen` → `CreatorWizardService::reopen()`
    (`apps/api/app/Modules/Creators/Services/CreatorWizardService.php:373-393`) — `rejected → incomplete`
    ONLY (409 `creator.reopen.invalid_state` otherwise), **preserves** `rejected_at`/`rejection_reason`,
    clears `submitted_at`, audits `AuditAction::CreatorApplicationReopened`.
  - `POST /api/v1/creators/me/wizard/submit` → `CreatorWizardService::submit()` (same file,
    lines 309-356) — `incomplete → pending`, clears the rejection fields, audits
    `AuditAction::CreatorSubmitted`.
  - So a creator CAN cycle `approved → (no path back)`, but `rejected → incomplete → pending →
approved (again)` is a real, tested loop. The Automations rule (fires on the approval transition)
    will therefore fire again on a second approval of a previously-rejected creator — same code path,
    same idempotency posture, nothing special required.
- **Where a post-approval hook naturally lives — the actual house precedent, checked, not assumed:**
  The user's brief cites "the flip-detector precedent from AH-056." That precedent is real but says
  the **opposite** of "add an event": AH-056's own kickoff finding
  (`docs/reviews/adhoc-changes-log.md:1868-1872`) is _"the flip detector is the **existing** before/after
  audit-snapshot pair in `CampaignController::update()`, not a new campaign event (the single
  write-path grep makes it airtight)."_ The same shape recurs at AH-058
  (`AutoRejectPendingApplicationsJob.php:22-29`: _"Dispatched by `CampaignController::update()` on the
  not-terminal → terminal edge, in the AH-056 flip-detector's own shape: no domain event, no
  scheduler."_). Creator approval has exactly the same property AH-056/AH-058 relied on: **one write
  path** (`AdminCreatorController::approve()`, confirmed no other code path flips
  `application_status` to `Approved` — it's the sole `ApplicationStatus::Approved->value` assignment
  outside enum/test files). The house-consistent hook seam is therefore an **inline call added directly
  in `approve()`'s existing transaction (or immediately after it)** — mirroring the flip-detector
  shape — not a new `CreatorApproved` domain event + listener pair. A new event class would be the
  first of its kind in this module and would diverge from the two most recent precedents for
  "something else must react to a status flip."

## I2 — The Door-2 path's reusability

- **Exact controller/service:**
  `apps/api/app/Modules/Creators/Http/Controllers/Admin/AdminCreatorConnectionController.php`.
  `store()` (lines 107-148) mode-switches; Door 2 is `directConnect()` (lines 342-390) +
  `notifyDirectConnect()` (lines 397-422). **It is not extracted into a service** — the two doors,
  the notification, and the mailer call are all private methods on the controller itself.
- **Guard chain, read off the actual code (`store()`, lines 107-148):**
  1. `agency = Agency::where('ulid', ...)->first()` → 404 `connection.agency_not_found` if absent.
  2. `creator->application_status === Approved` → 422 `connection.creator_not_approved` otherwise
     (line 117-119). **`is_discoverable` is explicitly bypassed** (docblock lines 52-53).
  3. Inside `TenancyContext::runAs($agency->id, ...)`: hard-blacklist check (lines 134-142) — **only**
     `BlacklistType::Hard`; soft is warn-only and not checked here at all.
  4. Existing-relation check happens inside `directConnect()` itself (line 344): already-`Roster` →
     200 no-op `connection.already_connected` (idempotent).
  5. **No agency-suspended / `is_active` check anywhere in this file** — confirmed by grep
     (`suspend|isSuspended|is_active` → zero matches in
     `AdminCreatorConnectionController.php`). Today, a human admin CAN direct-connect a creator to a
     suspended agency via Door 2 with no error. If the automation reuses this path "wholesale," it
     inherits this exact gap — worth flagging now since I5 asks what happens when "the agency is
     suspended since the rule was written": today, nothing stops it.
- **Mandatory-reason mechanism:** `AdminCreateConnectionRequest::rules()`
  (`apps/api/app/Modules/Creators/Http/Requests/Admin/AdminCreateConnectionRequest.php:34-40`) —
  `'reason' => ['required_if:mode,direct', 'nullable', 'string', 'min:10', 'max:2000']` — but that's
  the **HTTP FormRequest layer**, which an automation firing outside HTTP would not go through. The
  layer that actually enforces it regardless of caller is
  `AuditAction::requiresReason()` (`apps/api/app/Modules/Audit/Enums/AuditAction.php:448-498`, case
  `AgencyCreatorRelationAdminConnected` at line 482-483 → `true`) + `AuditLogger::log()`
  (`apps/api/app/Modules/Audit/Services/AuditLogger.php:83-85`) — it **throws**
  `MissingAuditReasonException` if `reason` is null/empty at the service layer. So the automation MUST
  supply a real ≥10-char reason string when it calls into this path (e.g. "Auto-connected by rule
  <id/name>" satisfies it) — this is enforced structurally, not just at the form layer.
- **What it emits:** `NotificationType::RelationAdminConnected` (in-app) + `AdminConnectedMail`
  (queued, locale-aware) to the creator — both in `notifyDirectConnect()` (lines 397-422). Audit verb
  `AgencyCreatorRelationAdminConnected` (`agency_creator_relation.admin_connected`).
  `isSensitiveCredentialAction()` (`AuditAction.php:506-520`) does **not** include this verb — it's
  scoped only to MFA/password material — so no redaction concerns apply here.
- **Extraction cost — the AH-058 precedent doesn't quite apply; it's "already service-shaped?" = NO.**
  The AH-058 shared-service precedent the brief cites is `CampaignApplicationDecisionService` +
  `CampaignApplicationNotifier` — both already-standalone service classes that
  `AutoRejectPendingApplicationsJob` calls into unchanged. `AdminCreatorConnectionController` has NO
  equivalent: `directConnect()` and `notifyDirectConnect()` are `private` methods on the controller,
  not injectable. Extracting them into a shared service (e.g. `CreatorAgencyDirectConnectService`)
  callable from both the controller and a future automation job would cost: (a) moving ~50 lines of
  transaction + audit logic plus the ~25-line notify method out of the controller, (b) the controller
  keeping the HTTP-specific bits (`AdminCreateConnectionRequest` validation, the 404/422 JSON
  envelopes) as a thin wrapper, (c) a new test file exercising the service directly rather than
  only through `AdminCreatorConnectionTest.php`'s HTTP assertions. Not a large lift, but real — this
  is a genuine "not yet built" gap, not a relabeling exercise.

## I3 — The rules storage

- **The board-automations precedent (AH-041/Sprint 12 Chunk 1), schema as it exists today:**
  `apps/api/database/migrations/2026_06_06_120002_create_board_automations_table.php:36-61`.
  Columns: `id`, `ulid`, `board_id` (FK, cascade), `event_key` (string 64 — "the SAME value
  `AssignmentTransitioned::eventKey()` returns"), `action_type` (string 16, default
  `move_to_column`, backed by `BoardAutomationActionType` enum — `MoveToColumn` | `None`),
  `target_column_id` (FK, nullable, `nullOnDelete`), `condition` (jsonb, nullable — "present-but-
  unwritten," an empty/null condition always passes per
  `BoardAutomationService::evaluateCondition()`, lines 97-102), `is_enabled` (bool, default true),
  timestamps, unique on `(board_id, event_key)`. **No seeded rows** — grepped for
  `BoardAutomation::query()->create` / a dedicated seeder: only a factory
  (`BoardAutomationFactory.php`, test-only) exists; nothing seeds production rows.
- **Important shape mismatch to flag:** board automations write **NO audit row on fire** — the
  in-house rule, stated explicitly at `AuditAction.php:383-384` and `:389-390`: _"Automation moves are
  system-driven and record ONLY the movement row (no audit row)"_ / _"automation moves never write
  an audit row."_ This is the OPPOSITE of what the brief specs for the new feature (an audited,
  `auto_advanced`-flagged connect). The `auto_advanced` metadata-key pattern the brief actually wants
  is the CAMPAIGN-ASSIGNMENT precedent instead — `CampaignAssignmentStateMachine.php:242-244` and
  `AdvanceContractlessAcceptedAssignments.php:32-35,72-75` — where the transition DOES write a normal
  audit row, just with `metadata: ['auto_advanced' => true, ...]` and (per
  `AuditLogger.php:87-89`) `actor: null` → `actor_type = 'system'`, `actor_role = 'system'`
  automatically. **That's the correct precedent to build against, not the board-automations
  no-audit rule** — worth being explicit about which of the two existing "automation" postures this
  feature should inherit, since they currently disagree.
- **What a platform-level `admin_automation_rules` table needs, mapped onto the board-automations
  shape:** `id`, `ulid`, `event` (string, enum-backed — v1 seeds exactly one value, e.g.
  `creator.approved`), `action` (string, enum-backed — v1 seeds exactly one value, e.g.
  `connect_to_agency`), `config` (jsonb — v1's only key is `agency_id`), `enabled` (bool, default
  true), timestamps. No `board_id` equivalent — this is platform-scoped, not agency/board-scoped (no
  tenancy column at all, mirroring `FeatureFlagToggled`-style platform config, not
  `AgencyCreatorRelation`-style tenant data).
- **Registry-shape verdict, tested against two hypothetical future rules:**
  - _"On approval → add to talent pool"_ — `event = creator.approved` (same event, second row),
    `action = add_to_talent_pool`, `config = {"pool_id": "..."}`. **Fits the schema with zero
    alteration** — same three columns, different enum values.
  - _"On KYC pass → notify agency"_ — `event = creator.kyc_verified` (a NEW event key — the
    `kyc_status → Verified` transition, currently written at
    `AdminCreatorController::verifyIdentity()` lines 411-417, itself another single-write-path flip
    with no event), `action = notify_agency`, `config = {}` or `{"agency_id": "..."}` if scoped.
    **Also fits with zero schema alteration** — but note it requires a SECOND flip-detector hook (in
    `verifyIdentity()`, not `approve()`), which is a real but separate piece of wiring, not a schema
    gap. **Verdict: the three-column (event/action/config) registry shape genuinely is
    extensible without alteration — the cost of a new rule TYPE is a new hook + a new action
    handler, never a migration.** This matches the board-automations precedent's own claim about
    itself.

## I4 — The admin UI surface

- **Left-menu registry:** `apps/admin/src/core/nav/navItems.ts:55-140` (`NAV_ENTRIES`) — a flat
  declarative array/tree consumed by `AdminLayout.vue:104-163`. Adding "Automations" is a data entry
  (one `NavLeaf`, e.g. under a new top-level key or inside an existing group) — "the same discipline
  the route table follows" per the file's own docblock (lines 1-24).
- **Closest page shape to clone — Feature Flags, with caveats:**
  `apps/admin/src/modules/feature-flags/pages/FeatureFlagsPage.vue`. It IS the row-per-item +
  enabled-toggle + mandatory-reason-dialog shape (`v-list` of rows, `@click.prevent` intercepts the
  switch, opens a dialog gated on `reason.trim().length >= 10`, lines 63-93, 144-182). **But it has no
  per-row config affordance and no create/delete** — flags are a fixed enumerated set
  (`AdminFeatureFlagController::FLAGS`), never admin-created rows. The Automations rules table needs
  BOTH the flag page's toggle+reason-dialog pattern AND a create/edit affordance (closer to a
  CRUD-with-dialog shape, e.g. `AgencyMembersCard.vue` + `MemberMfaActionDialog.vue`'s
  list-with-action-dialog pattern from AH-087, or a dedicated create dialog/page) — no single existing
  admin page is a full match; it's a composite of two precedents.
- **Agency dropdown data source — checked against the AH-085 lesson, verdict: NOT dropdown-suitable
  as-is.** `AdminAgencyController::index()`
  (`apps/api/app/Modules/Admin/Http/Controllers/AdminAgencyController.php:43-84`) returns a full
  paginated (`per_page`, capped 100, default 25) list of the FULL serialized shape (11 attributes:
  name, slug, country_code, subscription_tier/status, is_active, is_suspended, suspended_at/reason,
  member_count, created_at — `serialize()`, lines 180-199). This is **exactly the AH-085 bug shape**
  (Brands' `paginate(25)` with no `?for=select` thin projection) — there is no
  `?for=select`/`BrandOptionResource`-equivalent on the agencies index today. A dropdown built by
  calling `.list({ per_page: 100 })` naively would silently truncate past 100 agencies, or past 25 if
  the caller doesn't even override `per_page` — the identical class of bug AH-085 fixed for brands,
  not yet fixed here. Needs the same treatment (a thin `{id, name}` projection) before being wired to
  a rule-config dropdown.
- **Create/edit/disable interaction shape:** given the config affordance requirement (picking an
  agency per rule) plus the mandatory-reason precedent this house uses for every consequential admin
  toggle (`feature_flag.toggled`, `agency.suspended`, `user.mfa_*`), the natural shape is a **dialog**
  (mirrors `MemberMfaActionDialog.vue` / the Feature Flags toggle dialog) rather than a full page —
  consistent with every reason-gated admin action in this codebase being a dialog, never a dedicated
  route.

## I5 — Failure + observability

- **Queued-job posture — the real precedent is AH-058, not AH-069** (AH-069 in the log,
  `adhoc-changes-log.md:980-1061`, is the "deliverables not posted by creator" toggle — unrelated to
  in-job re-checking). The actual in-job re-check precedent is
  `AutoRejectPendingApplicationsJob.php` (AH-058, docblock lines 22-70): dispatched by the
  flip-detecting controller on the ID only (never the model — `SendJobPostedNotificationsJob.php`'s
  own documented trap, lines 43-47, re-explained at `AutoRejectPendingApplicationsJob.php:44-53`), and
  **re-checks the live condition inside `handle()`** (`campaign->status->isTerminal()`, line 94) rather
  than trusting the state at dispatch time — "a campaign re-opened between the flip and the worker
  picking this up keeps its pending applications: the reason to reject them no longer exists" (line
  40-42). The direct analogue for Automations: dispatch the rule-fire job with `(creator_id,
rule_id)`, and inside `handle()` re-verify the SAME guard chain live (creator still approved, agency
  still active/not-suspended — once that check exists — pair not hard-blacklisted, relation not
  already roster) rather than trusting the state captured when the approval happened.
- **Logging — the "silence must be legible" precedent, correctly located:** the brief cites
  "AH-083 F1"; the actual mechanism lives in the AH-058 Jobs Board area, not AH-083.
  `CampaignApplicationNotifier.php:294-327` (`logEmission()`) and
  `SendJobPostedNotificationsJob.php:72-81` both log a **structured `Log::info` line on every fire,
  success or no-op alike**, naming the outcome (`enabled`, `notified`, `remaining` /
  `mail_queued`, `mail_suppressed_by_flag`) — the explicit reasoning at
  `CampaignApplicationNotifier.php:294-297`: a prior chunk's correctly-silent flag-off path had
  "NOTHING anywhere said so," fixed by adding this line. Automations should mirror this exactly: one
  structured log line per fire attempt, whether it connected, no-opped (already connected), or skipped
  (creator not approved / agency suspended / blacklisted), naming the reason — cheap, and this
  codebase already treats its absence as a shipped bug once.
- **Audit posture for automated actor, already solved:** `AuditLogger.php:87-89` — passing `actor:
null` auto-resolves to `actor_type = 'system'`, `actor_role = 'system'`. `AutoRejectPendingApplicationsJob`
  already relies on exactly this ("The audit rows land with `actor_type = 'system'`, which is the
  truth: no human pressed reject" — docblock line 53). No new mechanism needed for "the audit context
  marked automated."
- **A visible "last fired / fire count" on the rule row — what's cheap:** nothing in the board-
  automations table tracks this today (`board_automations` has no `last_fired_at` / count column;
  `BoardCardMovement` rows are the only trace, and they're per-card, not per-automation). The cheapest
  equivalent for the admin rules table would be two additive nullable columns
  (`last_fired_at`, `fire_count`) updated in the same job that does the connect — genuinely cheap (one
  extra `forceFill` in an existing transaction), but **does not exist as a precedent to point to**;
  it would be new, if built.

## I6 — Ripple

- **Tenancy §4:** `docs/security/tenancy.md:155-157` already documents the exact AH-051 routes
  (`GET/POST /api/v1/admin/creators/{creator}/connections`, `POST .../disconnect`) in the
  route/method/justification table shape a new automations-rules route set would need to join
  (same table, additive rows) — allowlisted precedent already exists for this admin-tenancy-bypass
  category.
- **Approval-path tests that pin current behaviour:** no dedicated `AdminCreatorApprovalTest.php` —
  approve/reject mail + status-transition behaviour is pinned in
  `apps/api/tests/Feature/Modules/Creators/Admin/CreatorLifecycleMailTest.php` (Sprint 4 Chunk 3
  Cluster 7); the Door-2 connect path is pinned in
  `apps/api/tests/Feature/Modules/Creators/Admin/AdminCreatorConnectionTest.php`; the reopen loop in
  `CreatorReopenTest.php`. Any inline hook added to `approve()` would need to run alongside these
  without perturbing their existing assertions (none of them mock/fake an automations consumer today,
  so a naive hook that throws would need to be defensive, matching the `dispatchCreatorMail()` /
  `notifyCreatorOfLifecycle()` no-op-on-missing-user pattern already used in the same controller).
- **LIVE_TYPES/notification impact — confirmed none expected.** The existing
  `RelationAdminConnected` notification + `AdminConnectedMail` already fire unconditionally inside
  `notifyDirectConnect()` regardless of caller (HTTP admin vs. a future automation job calling the
  same code path) — no new `NotificationType` case, no `LIVE_TYPES` registry change needed, confirmed
  by reading the method: it has no actor-type branching.
- **i18n scope:** menu (1 new nav-leaf label × 24 locale files, matching the `navItems.ts` /
  `app.nav.*` key convention) + page (list/empty/create-dialog copy, likely 10-20 keys, mirroring
  Feature Flags' `admin.featureFlags.*` namespace size) + rule config (agency-picker label/placeholder,
  a handful of keys) — all ×24, consistent with every other admin-surface chunk in the log (AH-087,
  AH-088 each touched exactly 24 locale files per new namespace).
- **E2E exposure — the admin suite's shape is much smaller than the brief implies.**
  `apps/admin/playwright/specs/` contains exactly **two** spec files:
  `admin-sign-in.spec.ts` and `admin-mandatory-mfa-enrollment.spec.ts` — both auth-only. There is
  **no existing E2E coverage of any admin business surface** (agencies, creators, feature flags,
  audit log, none of it). A new Automations E2E leg would be the **first** admin business-logic
  Playwright spec, not an addition to an established per-module E2E pattern — worth knowing before
  scoping test effort, since there's no sibling admin CRUD spec to mirror.
- **api-client — confirmed stays local, per the actual AH-087-era finding:**
  `docs/reviews/admin-agencies-2fa-inventory.md:484-498` (I4.4) — confirmed
  `packages/api-client/src/types/` has no `admin.ts`; every existing `Admin*` type
  (`AdminAgency`, `AdminAgencyAttributes`, etc.) lives locally in
  `apps/admin/src/modules/*/api/*.api.ts`. Stated explicitly as "the same per-app-local pattern... not
  itself centralized either... not an oversight." A new `admin-automations.api.ts` with locally-declared
  types is the confirmed, precedented choice.

---

## The shaping answers

- **I1's hook seam:** an inline call added directly inside `AdminCreatorController::approve()`'s
  existing transaction (or immediately after commit) — mirroring the AH-056/AH-058 flip-detector
  shape this codebase has used twice already for "something else must react to a status flip," not a
  new domain event. Creator approval has the same single-write-path property those precedents relied
  on.
- **I2's extract-vs-already-service reality:** **not already service-shaped.** `directConnect()` +
  `notifyDirectConnect()` are private controller methods, not an injectable service — real (if modest)
  extraction work is needed before both the HTTP controller and a future automation job can share one
  code path. The mandatory-reason and audit-verb machinery are already reusable as-is (enforced at the
  `AuditLogger` layer, not just the FormRequest), but the connect logic itself is not.
- **I3's registry-shape verdict:** the three-column (event/action/config) shape, modeled directly on
  `board_automations`, holds against both hypothetical future rules with zero migration — the cost of
  a new rule type is a new event-hook + action-handler pair, never a schema change. One live tension
  to resolve before building: the board-automations precedent explicitly writes NO audit row on
  automated fire, while the brief's own audit design (the `auto_advanced` pattern) explicitly wants
  one — these are two different existing "automation" postures in this codebase that currently
  disagree, and the campaign-assignment `auto_advanced` posture (audited, `actor_type=system`) is the
  one that matches what's being asked for.
