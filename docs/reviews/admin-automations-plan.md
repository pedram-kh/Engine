# Platform Automations: rules table + on-approval auto-connect — PLAN (plan-pause)

- **Status:** Plan-pause. **No code written.** Awaiting clearance before sub-step 1.
- **Date:** 2026-08-27
- **Entry:** AH-090 (confirmed free — AH-089 is the latest landed entry, `adhoc-changes-log.md:73`).
- **Author:** Cursor, against the kickoff's locked decisions (D1–D7).
- **HEAD:** `c1a40e91` (`docs(reviews): admin automations inventory`). Working tree clean, nothing held.
- **Orientation re-read at plan time:** `docs/reviews/admin-automations-inventory.md` (this chunk's own
  inventory — **re-verified below, not re-trusted blind**; three of its findings sharpened and one
  corrected), `WORKING-PROCESS.md`, `PROJECT-WORKFLOW.md` §5 references cited inline,
  `docs/02-CONVENTIONS.md` §2.3/§2.8/§2.13/§2.16/§3.5, `docs/tech-debt.md` (the picker-class entry at
  `:2617-2660` and the SPA-bootstrap flake entry), `docs/feature-flags.md`, `docs/security/tenancy.md` §4.

---

## §0. The §5.40 line, re-derived

> **⚠️ PROD-DATA RISK: LOW-MEDIUM** — the kickoff's estimate holds, and the reason it does not climb to
> MEDIUM is a schema fact verified at plan time (C5 below).
>
> **What touches rows that already exist:** **no migration touches an existing table.** The one
> migration is a `CREATE TABLE` (`admin_automation_rules`). The system-actor connect path needs
> nothing added to `agency_creator_relations`: `invited_by_user_id` is **already nullable** with
> `nullOnDelete` (`2026_05_14_100007_create_agency_creator_relations_table.php:103-107`),
> `NotificationService::notify()` already accepts `?User $actor` (`NotificationService.php:41-47`), and
> `AuditLogger` already maps a null actor to `actor_type: 'system'` (`AuditLogger.php:83-89`). Had any
> one of those been non-nullable, this chunk would have needed an `ALTER` on a live relation table and
> the risk line would read MEDIUM. It doesn't.
>
> **What the feature writes to live data, once deployed and armed:** rows in
> `agency_creator_relations` — the same write a human admin performs through Door 2 today, with the
> same guard chain, fired by a rule instead of a click. Plus, per fire: one `audit_logs` row
> (`agency_creator_relation.admin_connected`, `actor_type: system`), one `notifications` row, one
> queued `AdminConnectedMail`, and an in-place `last_fired_at` / `fire_count` bump on the rule row.
> A fire either creates a relation row or elevates an existing non-roster one to `roster` — it never
> deletes, never blanks a column, never touches a second creator or a second agency.
>
> **Forward-only, and the arming is doubly gated.** No retroactive sweep (D7): an approved creator who
> exists today is never read by anything in this chunk. Nothing can fire until an operator both
> (a) creates a rule (the table ships empty) and (b) — if C8 is ratified — turns on
> `platform_automations_enabled`, default OFF. At deploy, the reachable population is **zero
> creators**, and it stays zero until a human deliberately arms it.
>
> **The one deliberate behavior change to an existing path (D3):** the suspended-agency guard is added
> to the connect service, which the human Door-2 path also calls. Today an admin can direct-connect a
> creator to a suspended agency (verified: no suspension check anywhere in
> `AdminCreatorConnectionController::store()` `:107-148` or `directConnect()` `:342-390`). After this
> chunk that returns 422. This **removes** a capability rather than adding one, and the removed
> capability produces a relation into an agency whose users cannot log in — it has no legitimate use.
> Recorded as the chunk's one manual-path change with its own §5.34 case. **Correction to the kickoff's
> wording:** the guard keys on `suspended_at`, not `is_active` — see C2.
>
> **`down()` honesty (§5.40):** `dropIfExists('admin_automation_rules')` is a true structural inverse
> with a lossy content inverse — a rollback discards every configured rule and its fire trace, and a
> redeploy comes back with an empty table (nothing re-fires, because the hook has nothing to read).
> Relations already created by fires **survive** the rollback, correctly: they are real relationships,
> not automation state. Named in the migration's `down()` comment per the AH-054/AH-056 house style.
>
> **Blast radius of a bug, worst first:** (1) **the automation reversing a deliberate human
> disconnect** — an `ended` relation silently re-elevated to `roster` on re-approval (C13; this is the
> single worst outcome in the chunk and it is a semantic decision, not a coding slip, which is why it
> is a ratification question rather than a test); (2) **a connect into a suspended or hard-blacklisted
> agency** if the job trusts dispatch-time state instead of re-checking — bounded to one relation per
> approval per rule, and the exact thing review priority 2's break-revert reddens; (3) **the hook
> dispatched inside the approve transaction** (C7) — fails _closed_, not open: the worker reads a
> still-pending creator and logs a skip, so the feature silently doesn't fire (a legibility bug, and
> the structured log line is what makes it legible); (4) a mis-scoped rules query firing a rule that
> is disabled — absorbed by the job's own re-check.

---

## §1. Read-pass findings that affect the kickoff

Verified at HEAD `c1a40e91`. Findings marked ⭐ change what gets built; ⚠ correct something stated in
the kickoff or the inventory.

### C1 — AH id confirmed: **AH-090** is free.

`adhoc-changes-log.md:73` is `### AH-089 · Graduated floor scoring`, the newest entry. No AH-090 exists.

### C2 — ⚠ D3's guard must key on `suspended_at`, not `is_active`.

The kickoff says "no `is_active` check" and "agency exists and active". `Agency::isSuspended()`
(`Agency.php:130-138`) carries an explicit ruling: _"`suspended_at` is the canonical marker; `is_active`
is kept in lock-step for backward-compat but `suspended_at` is the SOT."_ Guarding on `is_active` would
guard the mirror, not the source. The guard is `$agency->isSuspended()`; the error code stays
`connection.agency_suspended` and the skip reason `skipped:agency_suspended`, as the kickoff specifies.
Same correction applies to the D6 dropdown's "active-only" filter (`whereNull('suspended_at')`) and to
"agency exists", which additionally means non-soft-deleted — free, since `Agency` soft-deletes and the
default scope excludes trashed rows.

### C3 — ⭐ The extraction cannot move Door 2 "verbatim": `directConnect()` returns a `JsonResponse`.

`directConnect()` (`:342-390`) does not return a relation — it returns `$this->relationResponse(...)`
with an HTTP status and a `meta.code`, and its no-op branch (`:344-346`) returns a 200 envelope. A
queued job has no use for a `JsonResponse`, and building one to throw away would be the wrong seam.

**Shape:** the service returns a small result value object (§2.8) — `DirectConnectResult { outcome:
DirectConnectOutcome, relation: ?AgencyCreatorRelation }` — and the two callers _present_ it:

| `DirectConnectOutcome`      | HTTP (controller maps)                | Job (logs)                     |
| --------------------------- | ------------------------------------- | ------------------------------ |
| `Connected` (net-new)       | 201 `connection.direct_connected`     | `connected`                    |
| `Connected` (elevated)      | 200 `connection.direct_connected`     | `connected`                    |
| `AlreadyConnected`          | 200 `connection.already_connected`    | `skipped:already_connected`    |
| `CreatorNotApproved`        | 422 `connection.creator_not_approved` | `skipped:creator_not_approved` |
| `HardBlacklisted`           | 422 `connection.direct_blacklisted`   | `skipped:blacklisted`          |
| `AgencySuspended` (new, D3) | 422 `connection.agency_suspended`     | `skipped:agency_suspended`     |

This is what makes the D5 skip-reason set and the HTTP error codes **one vocabulary** instead of two
hand-maintained lists — the review-priority-2 §5.34 cases and the review-priority-5 log assertions then
enumerate the same enum. The net-new/elevated distinction stays as it is today: a boolean on the result
(`wasNetNew`), because only HTTP cares (201 vs 200).

**Scope note:** only Door 2 extracts. Door 1 (`sendRequest()`, `:284-334`) and `disconnect()`
(`:161-211`) stay inline in the controller, leaving it with two shapes (one delegating, two inline).
Accepted and named — extracting paths no second caller needs would be motion, not progress.

### C4 — ⚠ D1's schema and D2's reason string disagree: there is no `name` column.

D1 lists `event, action, config, enabled, last_fired_at, fire_count, timestamps`. D2's reason string is
`"Auto-connected by rule #{id} ({name})"`. Nothing supplies `{name}`. **Proposal:** add
`name` (`string(120)`, required, admin-supplied) — the reason string needs it, the D6 rules list needs a
human-readable first column, and deriving a label from `event`+`action` would give every v1 rule the
identical name ("Creator approved → connect to agency"), which is useless in an audit reason precisely
when there are several rules. Ratify at Q1.

### C5 — ✅ A system-actor connect needs **zero** changes to existing tables or services.

Verified, and this is what holds §0 at LOW-MEDIUM: `invited_by_user_id` nullable
(`agency_creator_relations` migration `:103-107`); `NotificationService::notify(?User $actor = null)`
(`:41-47`); `NotificationResource::actorPayload()` returns `null` cleanly for an actor-less row
(`:50-56`) and the SPA renders body copy from `type` + `data`, not from the actor
(`apps/main/src/modules/notifications/templates.ts`) — so the creator's in-app row reads identically
whether an admin or a rule connected them; `AuditLogger` maps `actor: null` → `actor_type: 'system'`,
`actor_role: 'system'` (`:83-89`). The service takes `?User $actor` and threads null through all three.

### C6 — ⚠ A test trap in the "system actor" assertion: `AuditLogger` falls back to `auth()`.

`AuditLogger::log()` resolves `$actor ?? $this->resolveCurrentActor()` before deciding the type
(`:83-89`). A test that exercises the automation _inside an authenticated admin request_ — e.g. by
letting `dispatchSync` run the job during a `postJson('/admin/creators/{id}/approve')` call — gets an
audit row attributed to **the acting admin**, not `system`. That test would pass its "a relation was
created" assertion while silently proving the wrong provenance. **Test posture, fixed now:** the job is
constructed and `handle()`d directly, outside any authenticated request, exactly as
`AutoRejectPendingApplicationsTest.php:303-352` does; one test asserts `actor_type === 'system'` with no
authenticated user; the approve-endpoint test uses `Queue::fake()` and asserts the _dispatch_, then
runs the job separately. Two tests, two claims, neither borrowing the other's context.

### C7 — ⚠ D4's dispatch must sit **after** the transaction commits — and the code already has the shape.

Every queue connection sets `'after_commit' => false` (`config/queue.php:48,57,68,77`), so a dispatch
inside `DB::transaction()` is visible to a worker **before** the commit. The job would then re-read a
still-`pending` creator and log `skipped:creator_not_approved` — the feature failing closed and
silently. The precedent is explicit at `CampaignController.php:252-259`: _"Plain dispatch AFTER the
save, so the worker can never read a campaign that is not yet listed."_
`AdminCreatorController::approve()` already has the right seam: the transaction closes at `:283`, then
`refresh()` at `:285`, mail at `:287-290`, in-app at `:294-299`. The rules query + dispatch loop goes
**after `:299`**, alongside the other post-commit emissions. Not `dispatchAfterResponse()` (same Q3
reasoning as the jobs-board arc — no work loops in the web process).

### C8 — ⭐ Proposing a kill switch the kickoff does not name: `platform_automations_enabled`, default OFF.

Per-rule `enabled` is a _configuration_ toggle, not a platform kill switch — an operator with five
rules and a misbehaving connect path has to flip five rows, one at a time, in a UI, while it fires.
Every prior first-of-its-kind emission shipped behind one Pennant flag default OFF (AH-056's fan-out,
AH-058's notices, AH-083's two mails), and this is a strictly bigger step: the first automated
**write** into live relationship data. **Proposal:** one flag, checked **in the job** at fire time (not
at dispatch — the AH-058 posture, so a mid-flight flip is honoured), adding the outcome
`skipped:feature_disabled`; registered in `AdminFeatureFlagController::FLAGS` (`:56-93`) — the AH-056
lesson is that a flag missing from that registry is a `tinker`-only switch, i.e. not a switch. Cost:
one Feature class, one FLAGS entry, one job branch, one test. Ratify at Q2.

### C9 — ✅ One hook site is complete coverage, and the TestHelpers deliberately bypass it.

`AdminCreatorController.php:267` is the **only** production write of
`ApplicationStatus::Approved` — every other occurrence in `apps/api/app` is a read-side comparison, a
`TestHelpers/` seeding controller, or `CreatorFactory`. So the inline hook is not "one of the places
approval happens", it is _the_ place. Corollary worth stating: the five TestHelper endpoints that force
`Approved` directly will **not** fire automations, which is right — E2E and dev seeding shouldn't
manufacture surprise relations.

### C10 — `config` stores the agency **ULID**, and a broken rule must look broken.

Door 2 resolves the agency by ULID (`:111`) and the audit metadata already records
`'agency_id' => $agency->ulid` (`:374`), so the ULID is the established wire identity — `config`
matches it. No FK is possible into a JSON column, so a suspended or deleted agency leaves a rule
_broken-but-present_ (the `board_automations` precedent for a deleted target). Enforcement is the job's
re-check; the D6 list must **render** the broken state (agency missing / suspended) rather than
silently showing a rule that can never fire.

### C11 — Rule CRUD needs its own audit verbs, reason-gated, and they carry no NotificationType ripple.

Four audit-only cases — `automation_rule.created` / `.updated` / `.deleted` / `.toggled` — with
`requiresReason()` **true for all four**, mirroring `feature_flag.toggled`, which requires a reason in
_both_ directions (`AdminFeatureFlagController.php:129-144`). A rule that auto-connects creators to an
agency sits in exactly the class of platform-behaviour change that precedent covers. Audit-only: the
board verbs already establish that a new `AuditAction` case needs no `NotificationType` counterpart and
triggers no `NotificationTypeEnumTest` ripple. (`AgencyCreatorRelationAdminConnected` — the verb the
_fire_ writes — already exists at `AuditAction.php:193` and already `requiresReason()` at `:482`;
nothing changes there.)

### C12 — ⚠ Correction to the inventory's I3: the audit posture diverges from `board_automations`, deliberately.

The inventory noted board automation moves write **no** audit row (`AuditAction.php:383`), and flagged
the divergence. Settling it here: this chunk's fires **do** write an audit row, because the subject is a
cross-tenant relationship with commercial consequence, not a card position inside one agency's board.
The board precedent governs the _table shape_; it does not govern the audit posture. Recorded so the
next reader doesn't "fix" the inconsistency.

### C13 — ⭐ The one semantic hole in D5's re-check set: a rule can reverse a human's deliberate disconnect.

D5 re-checks "not already roster". But `directConnect()` elevates `pending_request` / `declined` /
`ended` / `prospect` / `external` → `roster` (`:360-367`) — correct for a human choosing it. For the
automation, `ended` is the dangerous one: an admin used Door 6 to terminate the relationship (with a
mandatory reason and a dual-emit notification), and a later re-approval would silently reconnect the
pair. `declined` is the same shape from the creator's side — the creator refused this agency.

**Lean: the automation skips terminal states** (`ended`, `declined`) with `skipped:relation_terminal`,
while the human path keeps elevating them exactly as today. The asymmetry is the point: an automation
should never undo a decision a human made on purpose. This is a caller-supplied policy on the service
call (the job passes it; the controller doesn't), not a change to Door 2's matrix. Ratify at Q3 — this
is the single most consequential open question in the chunk.

### C14 — i18n is two namespaces, not one, and the parity test enforces all 24.

Admin SPA i18n is per-namespace JSON with eager `en` imports plus a glob for the other 23
(`apps/admin/src/core/i18n/index.ts:5-15`, `:77`), and `i18n-locale-parity.spec.ts` enforces key parity
across all 24 locale dirs (verified: 24 dirs, `bg…sv`). So the surface is a new `automations.json` ×24

- one `en` import + registration, **plus** `app.json` ×24 for the nav label. Namespace files contribute
  an `admin.*` subtree (`index.ts:51-52`) — the new keys land under `admin.automations.*`.

---

## §2. Cross-chunk contracts consumed, verified at HEAD (§5.11)

| Contract                                                                                                     | Verified at                                                                                                | Consumed how                                                            |
| ------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| `TenancyContext::runAs(?int, Closure)` restores the previous context and is safe with no ambient tenant      | `TenancyContext.php:52-62`                                                                                 | the service wraps its relation read+write, callable from a worker       |
| `AuditLogger`: null actor → `system`; `requiresReason` throws without one                                    | `AuditLogger.php:83-89`                                                                                    | the fire's audit row; the reason string is structural, not cosmetic     |
| `AgencyCreatorRelationAdminConnected` exists and requires a reason                                           | `AuditAction.php:193`, `:482`                                                                              | unchanged; the automation supplies the reason                           |
| `agency_creator_relations.invited_by_user_id` nullable                                                       | migration `:103-107`                                                                                       | the fire leaves it null (no admin chose it)                             |
| `NotificationService::notify(?User $actor)` + null-safe resource                                             | `NotificationService.php:41-47`, `NotificationResource.php:50-56`                                          | the creator's in-app row, system-actored                                |
| `?for=select` thin projection: same route, unpaginated, thin resource                                        | `BrandController.php:33-73` + `BrandSelectOptionsTest.php`                                                 | the agencies dropdown copies both the endpoint shape and the test shape |
| `Log::spy()` + `shouldHaveReceived('info')->withArgs(...)` with a §5.35 break-revert anchor comment          | `AutoRejectPendingApplicationsTest.php:303-352`                                                            | every outcome's structured line                                         |
| In-`handle()` re-check of live state                                                                         | `AutoRejectPendingApplicationsJob`                                                                         | the D5 re-check set                                                     |
| Post-commit plain dispatch, never in-transaction, never after-response                                       | `CampaignController.php:252-259`                                                                           | the D4 hook site                                                        |
| `AdminFeatureFlagController::FLAGS` is the allowlist a flag must join to be operable                         | `AdminFeatureFlagController.php:56-93`                                                                     | C8's kill switch                                                        |
| Admin routes: `auth:web_admin` + `EnsureMfaForAdmins`, tenant-less by category, allowlisted in tenancy.md §4 | `Modules/Admin/Routes/api.php:49-52`, `:89-96`                                                             | the rules CRUD routes mount in the same group                           |
| Migrations live centrally, not per-module                                                                    | `Modules/Admin/Database/Migrations/` is empty; `board_automations` lives in `apps/api/database/migrations` | the new migration's location                                            |
| Admin SPA module = 4 files (`api/`, `pages/*.vue` + `.spec.ts`, `routes.ts`)                                 | `apps/admin/src/modules/feature-flags/`                                                                    | the clone template per I4                                               |

---

## §3. Sub-step plan

Eleven sub-steps in five phases. Each is independently committable; each names its own test.

### Phase A — the seam, then the guard (S1–S2)

**S1 — Extract `CreatorAgencyDirectConnectService`. No behaviour change.**
`Modules/Creators/Services/CreatorAgencyDirectConnectService.php` + `DirectConnectOutcome` enum +
`DirectConnectResult` VO. `directConnect()` + `notifyDirectConnect()` bodies move in; the guard chain
moves with them verbatim (approved-creator, hard-blacklist inside `runAs`, already-roster no-op, the
structural reason). Signature: `connect(Creator, Agency, ?User $actor, string $reason, bool
$skipTerminalRelations = false): DirectConnectResult`. The controller keeps its FormRequest, its
`assertAdmin`, its ULID lookup, and becomes an outcome→envelope mapper.
**Pin:** `AdminCreatorConnectionTest` passes **untouched** — not one assertion edited. Plus new
`CreatorAgencyDirectConnectServiceTest` (outcome per branch, at service level).

**S2 — D3: the suspended-agency guard, both callers.**
`isSuspended()` check in the service (C2), before the relation read. HTTP: 422
`connection.agency_suspended`. This is the one sub-step that _adds_ a case to
`AdminCreatorConnectionTest` (an admin connecting into a suspended agency now gets 422) — the §5.34
behaviour-change case, plus the service-level counterpart.

### Phase B — storage and arming (S3–S4)

**S3 — the table, model, enums, factory.** Schema-only `CREATE TABLE admin_automation_rules`:
`id`, `ulid`, `name` (C4, pending Q1), `event` (string 64), `action` (string 64), `config` (jsonb),
`enabled` (bool, default true), `last_fired_at` (nullable ts), `fire_count` (unsigned int, default 0),
timestamps. Index on `(event, enabled)` — the dispatch-time query's shape. No uniqueness constraint
(D1's recorded choice). `AutomationEvent` / `AutomationAction` string enums, one case each in v1.
Model in `Modules/Admin/Models/`, factory in `Modules/Admin/Database/Factories/`. `@migration-risk`
docblock + the honest lossy `down()` (§0).

**S4 — the flag (pending Q2/C8).** `PlatformAutomationsEnabled` Feature class, default OFF, registered
in `AdminFeatureFlagController::FLAGS`, with a test asserting both that it defaults OFF and that it
appears in the admin flags index.

### Phase C — the firing loop (S5–S6)

**S5 — `RunAutomationRuleJob`.** `Modules/Admin/Jobs/`, constructor takes `(int $creatorId, int
$ruleId)` — IDs only. `handle()` re-checks, in order: flag on (C8) → rule exists and `enabled` →
creator exists and still `Approved` → agency resolves from `config` ULID, non-trashed, not suspended
→ then delegates to the service (which owns blacklist / already-roster / terminal-state). Exactly one
`Log::info('platform-automations: rule fired', [...])` line per invocation, success or skip, with the
outcome named. On `Connected`: `last_fired_at` + `fire_count` bump. Tests: one per outcome, the
`Log::spy` shape with break-revert anchors, and `actor_type === 'system'` (C6).

**S6 — the hook in `approve()`.** After `:299`, post-commit (C7): query enabled rules for
`creator.approved`, dispatch one job per rule. Tests: `Queue::fake()` dispatch assertions (zero rules →
zero dispatches; three rules → three jobs; disabled rule → not dispatched); the end-to-end
approve→job→relation integration test; the D7 idempotency test (approve → reopen → re-approve = one
relation, second fire logs `skipped:already_connected`); and the **review-priority-2 break-reverts**:
delete each re-check, watch the reopened / suspended / disabled cases redden.

### Phase D — the admin surface (S7–S9)

**S7 — rules CRUD backend.** `AdminAutomationRuleController` (index/store/update/destroy/toggle) in
`Modules/Admin/Http/Controllers/`, four FormRequests, a resource, routes in the existing admin group,
four reason-gated audit verbs (C11). Config validated against the action's schema (v1: `agency_id`
must resolve to a live agency). Tests: CRUD + authz (non-platform-admin → 403) + reason enforcement +
audit rows.

**S8 — the agencies `?for=select` thin projection.** `AdminAgencyController::index()` gains the
`for=select` branch returning an unpaginated `AgencyOptionResource` collection (id/ulid + name,
non-suspended only, ordered by name) — the `BrandController.php:33-73` shape, docblock included. Test
pair mirroring `BrandSelectOptionsTest`: the thin shape, and the ">25 agencies are all present"
assertion that is the actual bug-class pin.

**S9 — the SPA module.** `apps/admin/src/modules/automations/` — 4-file clone of `feature-flags/`:
`api/automations.api.ts` (local types, D7), `pages/AutomationsPage.vue` (+ `.spec.ts`), `routes.ts`,
nav entry in `core/nav/navItems.ts`. Rows: name, event, action, target agency, enabled toggle,
last-fired, fire count; broken-agency state rendered (C10). Create/edit dialog: name, event select,
action select, agency autocomplete (fed by S8), enabled toggle. Delete confirm with mandatory reason.
i18n: `automations.json` ×24 + `app.json` ×24 (C14).

### Phase E — docs and the board (S10–S11)

**S10 — the docs pass.** AH-090 entry in `adhoc-changes-log.md`; `admin-automations-review.md`;
`docs/security/tenancy.md` §4 rows for the five new admin routes + a note on the agencies-index
`for=select` mode; `docs/02-CONVENTIONS.md` §3.5 picker rule (§5, proposal 1); `docs/tech-debt.md` —
close the picker-class entry (`:2617-2660`) with a pointer to the new rule, **and extend the
SPA-bootstrap flake entry with `admin-sign-in.spec.ts`** (§6, the rider); `docs/feature-flags.md` entry
for the new flag; deploy obligations (migrate; **queue-worker restart** for the new job class; flag
stays OFF; the table ships empty).

**S11 — full board + CI at tip.** Backend Pest + Pint + PHPStan; both SPA suites + typecheck + lint +
the architecture tests (i18n parity ×24 will fail loudly if a locale file is missed); the admin
Playwright suite (unchanged — see §5 proposal 3); flaky-10 on the new backend tests and the new SPA
specs.

---

## §4. Review priorities → where each is discharged

| #   | Priority                                                                   | Discharged in                                                                                               |
| --- | -------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| 1   | Refactor pin: `AdminCreatorConnectionTest` green untouched + service tests | S1 (untouched); S2 adds the one behaviour-change case                                                       |
| 2   | D5 re-check set, §5.34 per skip reason, break-revert the in-job re-check   | S5 (per-outcome tests) + S6 (the break-revert anchors: reopened → still fires but skips; suspended → skips) |
| 3   | D3's both-caller guard + its behaviour-change case                         | S2                                                                                                          |
| 4   | Idempotency: approve → reopen → re-approve = one relation                  | S6                                                                                                          |
| 5   | One structured log line per outcome (`Log::spy` shape)                     | S5                                                                                                          |
| 6   | Fire-trace updates                                                         | S5 (bump on `Connected` only; asserted not-bumped on skips)                                                 |
| 7   | Conventions rule + `?for=select` landed                                    | S8 + S10                                                                                                    |
| 8   | i18n ×24 admin + flaky-10                                                  | S9 + S11                                                                                                    |
| 9   | Full board + CI at tip                                                     | S11                                                                                                         |

---

## §5. The three proposals the kickoff asked for

### Proposal 1 — D6's guard shape: **conventions rule + endpoint test pair; decline the architecture test.**

The tech-debt entry's own trigger text (`:2647-2654`) offers two graduation options: (a) a
`02-CONVENTIONS.md` entry naming the two allowed shapes and the threshold that picks between them, or
(b) a lint/architecture-test guard flagging a `<select>` bound to a `.list()` call without an
unpaginated variant or a `q` param.

**I priced (b) and recommend declining it, on evidence.** The only mechanically greppable signature of
this bug class is the literal `per_page: 100` at a call site. I grepped every non-spec source file in
`apps/main` and `apps/admin`: **zero occurrences.** The two live "give me everything" consumers —
`InviteCreatorsDialog.vue:119` and `AddCreatorsToPoolDialog.vue:117` — pass `ROSTER_PER_PAGE`, a named
constant, and they are the _legitimate_ AH-066 search-shaped consumers (paired with `?q=`). So a
literal-regex guard would (i) be green by construction today, (ii) be bypassed by the very idiom the
codebase now uses everywhere, and (iii) false-positive on the two correct call sites the moment either
inlines its constant. Detecting the real thing — "this `:items` binding is fed by a paginated endpoint"
— requires tracing a ref's provenance through a composable, which is not honestly cheap and is not
what the existing source-inspection architecture tests do.

**Recommended, and cheap:**

1. **`docs/02-CONVENTIONS.md` §3.5 entry** — the decision procedure, with the threshold mined from
   AH-066/AH-085 rather than re-derived: _bounded scope (operator-created, one at a time through a
   form — brands, agencies, pools) → unpaginated `?for=select` thin projection; unbounded scope
   (user-generated, can run to hundreds — creator rosters, discovery) → server-side `?q=` search. A
   paginated index is never consumed as a select._ Cross-referenced from §2.15 on the backend side.
2. **The endpoint test pair** (S8) — the enforcement that actually bites, at the layer where the bug
   lives: the ">25 rows all present" assertion, mirroring `BrandSelectOptionsTest`.
3. **A review-checklist line** in the conventions entry: "new list endpoint — will a select ever read
   this? If yes, which of the two shapes?"
4. The tech-debt entry closes with a pointer to the rule (the trigger has now fired three times:
   AH-066, AH-085, this chunk's agency dropdown).

If you want (b) anyway as a belt-and-braces tripwire, I'll add it in S9 as ~20 lines with its
limitation stated in its own docblock — it is cheap, just close to worthless.

### Proposal 2 — D7 deletion: **hard delete, reason-gated, config snapshotted in the audit row.**

Recommend hard delete, as the kickoff leans. Reasoning beyond "the audit rows are the history": a
soft-deleted rule would need a `whereNull('deleted_at')` filter in the dispatch query, which is a new
silent-failure surface (miss it and deleted rules keep firing) bought for no gain — nothing in the
product reads a dead rule. The one real loss is the fire trace (`last_fired_at`, `fire_count`), and the
mitigation is already in the pattern: `automation_rule.deleted` captures the full row — config, trace
and all — in the audit `before` snapshot, so "what did that rule do before it was deleted" stays
answerable. Relations created by past fires are untouched (D7's no-disable-cascade rule, applied to
deletion as well: **deletion stops future fires and never unwinds past ones**).

### Proposal 3 — D7 E2E: **no Playwright leg this chunk. The gap is named, not hidden.**

The honest price. `apps/admin/playwright/fixtures/test-helpers.ts` exposes exactly seven helpers:
`signUpAdminUser`, `mintTotpFromSecret`, `setClock`, `resetClock`, `neutralizeThrottle`,
`restoreThrottle`, `signOutViaApi` (`:115-304`). There is **no creator seeding, no agency seeding, no
rule seeding** — the admin suite has never touched business data. A first business-logic leg would
therefore need, before its first assertion: a TestHelper endpoint minting an agency, a second minting a
creator in approvable state (`pending` + KYC cleared), a rule row, and a synchronous queue posture so
the fired job completes inside the test. That is a chunk-sized fixture build, and what it would prove —
approval fires a rule and a relation appears — is proven end-to-end by S6's Pest test through the real
HTTP approve endpoint with the real job.

**What I'd ship instead:** S6's integration test (real endpoint → real job → real relation → real audit
row) plus S9's Vitest specs for the list page and dialog. **The named gap:** no test drives the rules
UI through a browser, so a wiring break between the SPA form and the CRUD endpoint (a renamed field, a
mis-shaped payload) would be caught by the Vitest specs' mocked contract rather than by a real request.
If you want the first admin business-logic leg to be this chunk, it's a real decision with a real
price, and I'd rather you make it explicitly than have me smuggle a fixture layer in under "E2E leg."

---

## §6. The rider's disposition, folded into this chunk's docs pass

Per the inventory's §0 (`admin-automations-inventory.md:77-99`), the `admin-sign-in.spec.ts` flake is
the **already-documented** navigation-vs-bootstrap interleaving race, not a new mechanism: `login()`
sets the user, the router navigates, `requireAuth` immediately re-fetches `/admin/me` via
`store.bootstrap()`, and a 401 on that second call clears the user and bounces back to `/sign-in` —
which is exactly the `/sign-in → / → /sign-in` bounce in the CI logs.

**Disposition (unchanged, now with its exact target):** extend
`docs/tech-debt.md:1303` — _"Residual Playwright-retry flakiness on chunk-7.1 + chunk-7.6 + chunk-3
wizard specs"_ (body `:1306-1323`) — adding `admin-sign-in.spec.ts` to the entry's tracked spec list as
the **third occurrence** of this class, with the CI evidence and the guard/store trace from the
inventory's §0, and restating the standing ruling at `:1315` that a timeout bump or spec-side retry
would mask real regressions in the redirect chain. The heading's spec list grows to name it, so the
next reader finds it without re-diagnosing from zero. No spec edit, no timeout bump, no production
change. Lands in **S10**, so the rider closes inside this chunk's docs commit rather than trailing as
a loose end.

---

## §7. Open questions

Each carries my lean; none blocks starting S1 except Q1 and Q3.

**Q1 — `name` column: ratify?** (C4) _Lean: yes, `string(120)`, required, min 3._ Without it D2's reason
string has no `{name}` to interpolate and the D6 list has no first column worth reading.

**Q2 — the `platform_automations_enabled` flag: ratify?** (C8) _Lean: yes._ One flag, checked in the
job, registered in `FLAGS`. It is the difference between "turn the feature off" and "edit five rows
while it fires."

**Q3 — ⭐ does an automation reverse a human disconnect?** (C13) _Lean: no — the automation skips
`ended` and `declined` with `skipped:relation_terminal`; the human Door-2 path keeps elevating them._
This is the chunk's most consequential semantic choice and the one I'd least like to guess on.

**Q4 — audit metadata for a fired connect: add `automation_rule_id` + `fired_by: 'automation'`?**
_Lean: yes._ `actor_type: system` says "not a human"; it does not say _which rule_. Without the rule id
the audit log can't answer "what did rule #3 ever do", and the reason string is prose, not a filter.

**Q5 — the creator's mail on an automated connect.** The fire reuses `AdminConnectedMail`, whose copy
frames the connection as admin-performed. _Lean: reuse unchanged, no new mail_ — from the creator's
side an operator's rule _is_ the platform acting, the agency name is what matters, and the existing
"contact support if this is unexpected" line already covers the surprise case. Flagging it because it
is a live-mail wording question, not a code question.

**Q6 — multiple rules for the same event _and_ the same agency.** D1 says no uniqueness constraint and
idempotency absorbs the overlap — agreed. _Lean: no constraint, and the create dialog lists existing
rules for the selected event_ (cheap, and it stops the duplicate being created blind rather than
absorbing it after the fact).

**Q7 — does the rules list need a per-rule fire drill-in?** _Lean: no for v1_ — the counts plus the
audit viewer's existing action filter cover it; a drill-in is a follow-up if operators ask.

---

## §8. Standards this chunk applies

§5.11 (contracts re-verified at HEAD, §2) · §5.32 (no new mechanism where one exists: inline hook, not
a new event system; existing mail and notification types; existing audit verb for the fire) · §5.34
(one case per skip reason, one for the D3 behaviour change) · §5.35 (break-revert anchors on the
re-check set and the log line, commented in-test) · §5.40 (migration risk + honest `down()`) · §2.3
(thin controller, service owns the logic) · §2.8 (the outcome VO) · §2.13 (schema-only migration) ·
§2.16 (IDs-only job payload, re-check in `handle()`) · §3.5 (the new picker rule this chunk writes).

---

## §9. What this chunk deliberately does not build

- **No retroactive sweep and no backfill command** (D7). Existing approved creators are never read.
  Named as the follow-up, not built.
- **No disable- or delete-cascade.** Stopping a rule stops future fires only; past relations stand.
- **No second event and no second action.** The table proves zero-migration extensibility against the
  inventory's two hypotheticals; only `creator.approved × connect_to_agency` ships.
- **No Door 1 extraction, no `disconnect()` extraction** (C3) — no second caller needs them.
- **No admin Playwright leg** (§5, proposal 3) — gap named.
- **No shared `packages/api-client` types** (D7) — admin types stay local, per the
  `admin-agencies-2fa-inventory.md` precedent.
- **No per-tenant flag scope** — the existing platform-level Pennant posture, unchanged.
