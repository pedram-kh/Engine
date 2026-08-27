# Platform Automations: rules table + on-approval auto-connect — REVIEW

- **Status: Closed — approved, pushed.**
- **Date:** 2026-08-27
- **Entry:** AH-090.
- **Built from:** [`admin-automations-plan.md`](admin-automations-plan.md) (S1–S11, D1–D7, C1–C14,
  Q1–Q7) and [`admin-automations-inventory.md`](admin-automations-inventory.md).
- **Commits:** `f31d7c7b` (the service extraction + the D3 guard) · `1c996c77` (table, flag, job,
  hook) · `1a4e0a1f` (CRUD, `?for=select`, SPA) · `f4ac735c` (the Q4 provenance stamp) · `4a3f2c6c` +
  `55663b26` (the docs pass) · this close.
- **Reviewed against:** `WORKING-PROCESS.md`, `PROJECT-WORKFLOW.md` §5 (references cited inline),
  `02-CONVENTIONS.md` §2.3/§2.8/§2.13/§2.16/§3.5, `security/tenancy.md` §4, `feature-flags.md`,
  `tech-debt.md`.

---

## §0. What shipped, in one paragraph

An operator can now write a rule that says _when a creator is approved, connect them to this agency_,
and the platform performs that connection itself — as `system`, with the same guard chain and the same
notifications a human admin's direct-connect produces. Nothing fires until two separate deliberate
acts: a rule exists (the table ships empty) **and** `platform_automations_enabled` is on (default
OFF). The rule's action is re-checked against live state at fire time, not trusted from dispatch time,
and every outcome — fired or skipped, for any of nine reasons — writes one structured log line. The
chunk also removes one pre-existing capability: an admin can no longer connect a creator to a
suspended agency.

---

## §1. Production posture

### The risk line, and why it does not climb

> **⚠️ PROD-DATA RISK: LOW-MEDIUM.** The reason it does not reach MEDIUM is a schema fact, verified at
> plan time and unchanged by the build: **no migration touches an existing table.** The one migration
> is a `CREATE TABLE`. The system-actor connect path needed nothing added to
> `agency_creator_relations` — `invited_by_user_id` is **already nullable** with `nullOnDelete`,
> `NotificationService::notify()` already accepts `?User $actor`, `NotificationResource` already
> renders an actor-less row cleanly, and `AuditLogger` already maps a null actor to
> `actor_type: 'system'`. Had any **one** of those four seams been non-nullable, this chunk would have
> needed an `ALTER` on a live relation table and the risk line would read MEDIUM. It doesn't.

**What the feature writes once deployed and armed:** rows in `agency_creator_relations` — the same
write a human admin performs through Door 2 today, with the same guard chain, fired by a rule instead
of a click. Per fire: one `audit_logs` row (`agency_creator_relation.admin_connected`, `actor_type:
system`), one `notifications` row, one queued `AdminConnectedMail`, and an in-place `last_fired_at` /
`fire_count` bump on the rule. A fire either creates a relation or elevates an existing non-roster one
to `roster` — it never deletes, never blanks a column, never touches a second creator or a second
agency.

**Forward-only, doubly gated.** No retroactive sweep (D7): an approved creator who exists today is
never read by anything in this chunk. At deploy the reachable population is **zero creators**, and it
stays zero until a human both creates a rule and arms the flag.

**`down()` honesty (§5.40).** `dropIfExists('admin_automation_rules')` is a true structural inverse
with a lossy content inverse: a rollback discards every configured rule and its fire trace, and a
redeploy returns an empty table (nothing re-fires — the hook has nothing to read). Relations already
created by fires **survive**, correctly: they are real relationships, not automation state.

**Blast radius of a bug, worst first.** (1) The automation reversing a deliberate human disconnect —
addressed by Q3, below, and it was a semantic decision rather than a coding slip, which is why it was
ratified rather than merely tested. (2) A connect into a suspended or hard-blacklisted agency if the
job trusted dispatch-time state — bounded to one relation per approval per rule, and the exact thing
the re-check break-reverts redden. (3) The hook dispatched inside the approve transaction — fails
**closed**: the worker reads a still-pending creator and logs a skip, so the feature silently doesn't
fire. A legibility bug, and the structured log line is what makes it legible. (4) A mis-scoped rules
query firing a disabled rule — absorbed by the job's own re-check.

### The one deliberate behaviour change to an existing path (D3)

An admin direct-connecting a creator to a **suspended** agency now gets `422
connection.agency_suspended`. Before this chunk, nothing anywhere in the connect chain checked
suspension — verified at plan time across `AdminCreatorConnectionController::store()` and
`directConnect()` — so an admin could put a creator on the roster of an agency whose users cannot log
in. This **removes** a capability rather than adding one, and the removed capability has no legitimate
use.

Two things worth stating plainly. First, it is keyed on `suspended_at`, **not** the `is_active` mirror
— `Agency::isSuspended()` carries the explicit ruling that `suspended_at` is the SOT and `is_active` is
kept in lock-step for backward compat, so guarding `is_active` would guard a copy of the truth. This
corrected the kickoff's own wording. Second, **no flag gates this change**: it is live the moment the
code deploys, unlike everything else in the chunk. It has its own §5.34 case in
`AdminCreatorConnectionTest` (the HTTP layer) and a counterpart at service level.

---

## §2. The ratified decisions, and what each produced

| #                           | Ratified                                                                                  | Built                                                                                                                                                    |
| --------------------------- | ----------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Q3** — terminal relations | (A) skip, `skipped:relation_terminal`                                                     | `connect(..., skipTerminalRelations: true)` from the job only. `ended` / `declined` pairs are left alone; the human path keeps elevating them.           |
| **C8** — kill switch        | (A) `platform_automations_enabled`, default OFF, checked at fire time                     | `PlatformAutomationsEnabled` Feature, registered in `AdminFeatureFlagController::FLAGS`, first branch of `handle()`, outcome `skipped:feature_disabled`. |
| **D3** — suspended agency   | (A) fix both callers, keyed on `suspended_at`                                             | The guard lives in the service, so both callers inherit it. HTTP 422, job skip.                                                                          |
| **D6** — guard shape        | (A) conventions rule + endpoint test pair + checklist line; decline the architecture test | `02-CONVENTIONS.md` §3.5, `AdminAgencySelectOptionsTest`'s >25-row pin, the `tenancy.md` §6 checklist line. The tech-debt entry closes pointing here.    |
| **D7** — E2E                | (A) no Playwright leg; the Pest integration test carries it                               | `AdminCreatorApprovalAutomationTest` drives the real approve endpoint → real job → real relation → real audit row. The gap is named in §5.               |
| **C4** — `name` column      | ratified into D1's schema                                                                 | `name` (string 120, required, min 3). The audit reason interpolates it; the rules table's first column reads it.                                         |
| **C3** — the outcome VO     | replaces "verbatim" extraction                                                            | `DirectConnectOutcome` + `DirectConnectResult`. One enum backs both the HTTP codes and the skip reasons, so the two lists cannot drift.                  |
| **C7** — after-commit       | dispatch after `approve()`'s existing emissions                                           | The dispatch sits after the transaction closes and after the mail/in-app emissions, pinned by a break-revert.                                            |
| **Q4** — fire provenance    | ratified: yes                                                                             | `fired_by: 'automation'` + `automation_rule_id: <ulid>` in the audit metadata, present ONLY on an automated fire, pinned from both sides.                |
| **Q5** — the creator's mail | ratified: reuse `AdminConnectedMail` unchanged                                            | The automation's fire calls the same `notify()` the human path calls; no new mailable, no copy change.                                                   |
| **Q6** — duplicate rules    | ratified: no constraint, dialog warns                                                     | The create/edit dialog lists how many other rules already watch the selected event.                                                                      |
| **Q7** — per-rule drill-in  | ratified: no for v1                                                                       | Not built. The rules table's counts + the audit viewer's own action filter cover it.                                                                     |

### Q3 in the words it was ratified in

A rule must never reverse a deliberate admin termination (mandatory-reason, dual-emitted) or a
creator's own refusal of that agency. The manual path keeps elevating those, because **a human
choosing is the consent the elevation encodes** — the asymmetry is the point. The skip logs its reason
like every other outcome.

The test that carries it is not the skip case alone but the **pair**: the same terminal pair that the
job refuses is connected by the human path in the very next assertion. A future refactor that
"unified" the two paths would redden the second half, not the first.

### C8 in the words it was ratified in

Per-rule `enabled` is configuration; the flag is the kill switch — **five rules must die with one
flip.** It also matches the house posture for any new automated write path. Checked at fire time
rather than dispatch time, so flipping it off stops work already sitting on the queue.

### The four already-nullable seams

Recorded verbatim in §1 above, as ratified: they are the reason the risk line does not climb, and
naming them is what makes that claim checkable rather than reassuring.

### Q4 — the half of the pin that matters is the absence, not the presence

Asserting that an automated fire carries `fired_by: 'automation'` + `automation_rule_id` proves the
happy path wired the keys through. It does **not** prove a human connect couldn't accidentally carry
them too — a caller passing a stray default would pass that assertion while quietly mislabeling every
manual connection as automated. The pin that actually catches that is the negative, in
`CreatorAgencyDirectConnectServiceTest`: a human-actor connect asserts `metadata` has **neither** key.
Ratified as built, both sides.

### Q5 — reuse `AdminConnectedMail` unchanged, recorded as a live-mail wording decision

The mail's copy frames the connection as admin-performed, and it stays that way: from the creator's
side, an operator's rule **is** the platform acting, the agency name is what matters, and the existing
"contact support if this is unexpected" line already covers the surprise case a creator might feel on
an automated connect they didn't ask for. This was flagged at plan time as a wording question, not a
code question, and it is recorded here as reviewed rather than defaulted into — a future rename of that
mailable's copy should re-read this line before assuming the "admin-performed" framing was an oversight.

### Q6 — prevention over absorption

No uniqueness constraint: idempotency already absorbs two rules overlapping on the same event and
agency, so a constraint would be enforcing something the system doesn't need enforced. What the dialog
adds is cheaper and catches the actual mistake — an operator about to create a rule nobody asked for a
second time sees the count **before** they click, rather than the platform silently absorbing a
duplicate they didn't know existed.

### Q7 — no drill-in v1

The rules table's per-rule `fire_count` + `last_fired_at` plus the audit log's own action filter (`?
action=agency_creator_relation.admin_connected`) already answer "did this rule do anything" and "what
did it do." A dedicated drill-in view would be additional UI surface for a question the existing
surfaces already answer, absent an operator asking for it.

### The build-report ratifications

Two more decisions surfaced by the build itself, not by the plan, are ratified here rather than left as
loose asides in a chat transcript:

- **The `ApiError`-code divergence.** `FeatureFlagsPage.vue` (the clone template) and at least four
  other admin pages feed `error.code` into `t()` on a load failure; no locale defines keys like
  `auth.forbidden`, so that path renders the raw API token at the operator. `AutomationsPage.vue` does
  not copy this — it uses one i18n key it owns for every load failure. **Ratified as the correct choice
  for this chunk, and explicitly NOT retroactive**: fixing the five sibling pages is a named follow-up
  with its own scope and its own test surface, not something this chunk's mandate covers.
- **Both honest limits, restated so they don't get read as gaps that were missed.** (1) The conventions
  rule closing the tech-debt entry governs **new** pickers from here forward; no audit of the
  codebase's existing list endpoints for the same silent-truncation shape was performed or is claimed,
  and a pre-existing instance would still surface only when a user reports missing rows. (2) The SPA
  Vitest specs prove the automations page's wiring against a **mocked** API contract; a wiring break
  between the real form payload and the real CRUD endpoint's expected shape would pass those specs and
  only be caught by the Pest integration test's real HTTP round-trip (or by a future E2E leg, not built
  this chunk per Q/D7). Both limits were named at plan or build time, not discovered at close — this is
  the record making that true after the fact as well.

---

## §3. The re-check set — the heart of the chunk

The job takes IDs only (§2.16) and re-derives everything. In order:

| Re-check                     | Skip outcome                   | Why it cannot be trusted from dispatch time                                       |
| ---------------------------- | ------------------------------ | --------------------------------------------------------------------------------- |
| Feature flag on              | `skipped:feature_disabled`     | An operator flips the kill switch while jobs sit queued. That flip must win.      |
| Rule still exists            | `skipped:rule_missing`         | Deleted between dispatch and run.                                                 |
| Rule still enabled           | `skipped:rule_disabled`        | Toggled off between dispatch and run.                                             |
| Creator still exists         | `skipped:creator_missing`      | Deleted between dispatch and run.                                                 |
| Creator still `Approved`     | `skipped:creator_not_approved` | **Reopened** between dispatch and run — the approval the rule reacted to is gone. |
| Action config well-formed    | `skipped:misconfigured`        | A rule can be edited to an empty target, or written by a newer version.           |
| Agency resolves, not trashed | `skipped:agency_missing`       | The JSON config carries no FK. Nothing prevents the agency being deleted.         |
| Agency not suspended         | `skipped:agency_suspended`     | Suspended after the rule was written. The most likely of these in practice.       |
| Pair not hard-blacklisted    | `skipped:blacklisted`          | Owned by the service, shared with the human path.                                 |
| Pair not `ended`/`declined`  | `skipped:relation_terminal`    | Q3. The automation's own policy, passed by the job and never by the controller.   |
| Pair not already `roster`    | `skipped:already_connected`    | Idempotency: a re-approval must not produce a second relation.                    |

Each has its own case (§5.34). The break-revert anchors are commented in-test: delete an individual
re-check and watch its own case redden — verified, not asserted. The most instructive is the reopened
case, because it is the one where dispatch-time state and fire-time state genuinely disagree.

**One log line per invocation**, success or skip, through a single `log()` call so a new outcome cannot
ship with an HTTP path but no operator-visible trace. The `Log::spy()` shape follows
`AutoRejectPendingApplicationsTest`.

**Fire trace bumps on `Connected` only** — asserted not-bumped on skips. An operator reading
`fire_count: 0` on an enabled rule is reading the truth, which is the point of the column.

### The after-commit seam, pinned

Every queue connection sets `after_commit => false`, so a dispatch inside `DB::transaction()` is
visible to a worker **before** the commit. The job would then re-read a still-`pending` creator and log
`skipped:creator_not_approved`: the feature failing closed and silently. The dispatch therefore sits
after the transaction closes, alongside `approve()`'s existing mail and in-app emissions.

**Pinned as ratified:** a break-revert moving the dispatch inside the transaction **reddens the
fires-after-commit case.** Verified during S6 by doing exactly that and watching it fail.

### The system-actor test trap (C6)

`AuditLogger::log()` resolves `$actor ?? $this->resolveCurrentActor()`. A test that runs the job
_inside_ an authenticated admin request gets an audit row attributed to **the acting admin**, while
still passing its "a relation was created" assertion — proving the wrong provenance. So: the job is
constructed and `handle()`d directly, outside any request, for the `actor_type === 'system'` claim; the
approve-endpoint test uses `Queue::fake()` and asserts the **dispatch**, then runs the job separately.
Two tests, two claims, neither borrowing the other's context.

---

## §4. The admin surface

**Backend.** `AdminAutomationRuleController` — index (unpaginated), store, update, toggle, destroy.
All four writes are reason-gated by four new `AuditAction` cases (`automation_rule.created` /
`.updated` / `.deleted` / `.toggled`), `requiresReason()` **true for all four**, mirroring
`feature_flag.toggled` which requires a reason in both directions. Each write audits a before + after
snapshot, so a retarget is legible from the log alone. Audit-only: a new `AuditAction` case needs no
`NotificationType` counterpart, per the board-automation precedent.

**Hard delete, as ratified.** A soft-deleted rule would need a `whereNull('deleted_at')` filter in the
dispatch query — a new silent-failure surface (miss it and deleted rules keep firing) bought for no
gain, since nothing in the product reads a dead rule. The one real loss is the fire trace, and the
`automation_rule.deleted` before-snapshot captures the whole row, so _"what did that rule do before it
was deleted"_ stays answerable. This is a permitted hard delete because a rule is operator-authored
configuration, not user-generated data.

**The picker projection.** `GET /admin/agencies?for=select` returns every non-suspended agency as
`{id, name}`, unpaginated. Suspended agencies are omitted — offering one would be offering a choice the
job then refuses. The `>25 rows are all present` test is the assertion that actually pins the bug class;
the thin-shape test is the cheaper companion.

**A broken rule renders as broken.** No FK is possible into a JSON column, so an agency can be deleted
or suspended out from under a rule at any time. The resource computes a `broken_reason` and the table
shows a chip. Hiding it would leave an operator watching a rule that silently never acts — the job
already refuses to fire in those states.

**A rule naming an unknown event still renders**, showing the raw token, because a row that goes blank
hides that the rule exists at all.

**What the page cannot do: arm the feature.** The kill switch lives on the feature-flags page. A rule
created here does nothing until someone deliberately flips that flag.

### One divergence from the clone template, deliberate

`FeatureFlagsPage.vue` feeds `ApiError.code` into `t()` on load failure. No locale defines
`auth.forbidden` (or any other API code) as a key, so that path renders the raw token at the operator.
The automations page uses **one key it owns** instead. The pre-existing pattern is not fixed here — it
appears on at least five admin pages (alerts, both compliance shells, operations health, feature flags)
and fixing it is a separate change with its own test surface. Named so the divergence reads as a
choice, not an inconsistency.

---

## §5. Coverage, and the gap

**Backend.** `CreatorAgencyDirectConnectServiceTest` (outcome per branch at service level, including
the Q3 asymmetry pair and the Q4 provenance negative) · `AdminCreatorConnectionTest` (green with the
one added D3 behaviour-change case; every other assertion untouched, which is the refactor pin) ·
`RunAutomationRuleJobTest` (one case per outcome, the log shape, the `system` provenance, the fire
trace, break-revert anchors) · `AdminCreatorApprovalAutomationTest` (dispatch assertions, the
after-commit pin, the end-to-end path, the idempotency case, the Q3 non-reversal case, and a case
proving the approval itself is untouched by the hook) · `AdminAutomationRuleTest` (CRUD, authz,
reason enforcement, audit rows, the broken-rule render, unpaginated at 30 rows) ·
`AdminAgencySelectOptionsTest` (the >25-row pin, the thin shape, suspended omitted, authz, and
backward-compat: omitting `?for=select` still paginates at 25).

**Frontend.** `AutomationsPage.spec.ts` — 14 cases: the list and its fire trace, the empty state, the
kill-switch notice, all three broken states, create (including the payload shape and that the new row
comes from the server response), the reason floor on all three writes, edit-with-empty-reason, the
duplicate warning, the toggle sending the intended next state without flipping optimistically, delete
dropping the row, the unknown-event fallback, and the load-failure message.

**The named gap, as ratified.** No test drives the rules UI through a browser. A wiring break between
the SPA form and the CRUD endpoint — a renamed field, a mis-shaped payload — would be caught by the
Vitest specs' **mocked** contract rather than by a real request. The Pest integration test through the
real endpoint and the real job carries the business logic; what is uncovered is the HTTP contract
between the two apps.

**Why no Playwright leg:** `apps/admin/playwright/fixtures/test-helpers.ts` exposes seven helpers —
sign-up, TOTP, clock, throttle, sign-out — and **no creator, agency or rule seeding.** The admin suite
has never touched business data. A first business-logic leg would need, before its first assertion: a
TestHelper endpoint minting an agency, a second minting a creator in approvable state, a rule row, and
a synchronous queue posture. That is a chunk-sized fixture build. **The first business-logic leg for
the admin suite is a deliberate future investment, not this chunk's rider** — and recording the
fixture-less reality is what makes it a decision rather than an omission.

---

## §6. The rider, discharged

The `admin-sign-in.spec.ts` flake is the **already-tracked** bootstrap-vs-redirect interleaving race,
now its **third occurrence**. `docs/tech-debt.md`'s Playwright-flake entry gains it: five CI runs of
evidence, the empty `git diff` across the first flaky appearance (so it is not a regression from the
sessions that surfaced it), and the guard/store trace that explains the `/sign-in → / → /sign-in`
bounce. The heading's spec list now names it, so the next reader finds it without re-diagnosing from
zero.

**No spec edit, no timeout bump, no retry wrapper** — that entry's standing ruling for this leg is that
either would mask real regressions in the redirect chain, and bumping this spec while that ruling
stands on its two siblings would contradict it for no stated reason.

---

## §7. Standards applied

§5.11 (contracts re-verified at HEAD before use) · §5.32 (no new mechanism where one exists: an inline
hook rather than an event system, the existing mail and notification types, the existing audit verb for
the fire — and the outcome enum as the reinterpretation of "verbatim extraction") · §5.34 (one case per
skip reason, one for the D3 behaviour change, the >25-row picker pin) · §5.35 (break-revert anchors on
each re-check, the log line, and the after-commit seam — all commented in-test) · §5.40 (migration risk

- honest lossy `down()`) · §2.3 (thin controller, service owns the logic) · §2.8 (the result VO) ·
  §2.13 (schema-only migration) · §2.16 (IDs-only job payload, re-check in `handle()`) · §3.5 (the picker
  rule this chunk writes).

---

## §8. What this chunk deliberately did not build

- **No retroactive sweep, no backfill command.** Existing approved creators are never read. Named as
  the follow-up.
- **No disable- or delete-cascade.** Stopping a rule stops future fires; past relations stand.
- **No second event, no second action.** The schema proves zero-migration extensibility; only
  `creator.approved × connect_to_agency` ships.
- **No Door 1 or `disconnect()` extraction.** No second caller needs them, so extracting would be
  motion, not progress. The controller is left with two shapes — one delegating, two inline — which is
  named rather than tidied.
- **No admin Playwright leg** (§5).
- **No shared `packages/api-client` types.** Admin types stay local, per the admin-agencies precedent:
  shared types earn their place when a second app consumes the same endpoint.
- **No per-rule fire drill-in.** The counts plus the audit viewer's action filter cover v1.
- **No per-tenant flag scope.** The existing platform-level Pennant posture, unchanged.
- **No audit of pre-existing pickers.** The new conventions rule governs new pickers; a pre-existing
  silent truncation elsewhere would still be found only by a user reporting missing rows. Recorded on
  the closed tech-debt entry rather than claimed as done.

---

## §9. Close

**Verdict: approved.** D1–D7 and C1–C14 verified as ruled; Q1–Q7 verified as ratified, all seven now
recorded in §2 with the reasoning each was ratified in, not just the outcome. Specifically:

- The **outcome-enum, one-vocabulary design** (C3) is accepted as the model for any future service that
  needs to serve two callers with two different presentation needs (HTTP envelope vs. structured log)
  off one decision — the alternative, a second hand-maintained list, is exactly the drift this pattern
  forecloses.
- The **after-commit dispatch seam** (C7) and its break-revert are confirmed: the anchor reddened when
  the dispatch was deliberately moved inside the transaction, proving the seam is load-bearing rather
  than a comment describing intent.
- The **D3 both-caller guard**, keyed on `suspended_at`, with its own behaviour-change case in
  `AdminCreatorConnectionTest` — the one change in this chunk that is live without a flag.
- The **C13 terminal-skip asymmetry** — an automation never reverses a deliberate human disconnect or
  refusal, the human path keeps elevating them — recorded with the reasoning it was ratified in: a human
  choosing is the consent the elevation encodes, so the asymmetry is not an inconsistency to later
  "fix."
- The **C12 audit-posture divergence from `board_automations`** — this chunk's fires DO write an audit
  row, because the subject is a cross-tenant relationship with commercial consequence, not a card
  position inside one agency's board — recorded so a future reader doesn't "fix" the difference into a
  false consistency.
- The **Q4 provenance stamp**, pinned from both sides: the automated fire carries `fired_by` +
  `automation_rule_id`, and the human connect is asserted to carry neither. The absence-implies-human
  half is the one that actually catches a caller forgetting to pass the ULID.
- The **third-instance conventions rule** (D6) plus the `?for=select` endpoint pair landed, and the
  `tech-debt.md` picker-class entry closes pointing at both — with the declined architecture-test
  option recorded in writing, not merely dropped.
- The **rider's flake entry**, extended per the inventory's own §0 disposition: `admin-sign-in.spec.ts`
  joins the tracked list as a third occurrence, evidenced rather than asserted, with no spec edit
  because that entry's standing ruling against timeout-bumping this family still holds.
- The **build-report ratifications** — the owned-key divergence from the `ApiError.code` pattern
  (correctly scoped as this chunk's choice, not a retroactive fix to the five sibling pages), and both
  honest limits (no pre-existing-picker audit claimed; the Vitest mocked-contract gap with the
  fixture-less admin suite as its recorded reason) — are ratified as accurate self-assessment, not
  understated risk.

**Provenance:** reviewed and closed by Pedram, 2026-08-27. Built by Cursor against the ratified plan
(`admin-automations-plan.md`) across commits `f31d7c7b`, `1c996c77`, `1a4e0a1f`, `f4ac735c`, `4a3f2c6c`,
`55663b26`.
