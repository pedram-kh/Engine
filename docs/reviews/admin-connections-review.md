# AH-051 — Admin-initiated agency↔creator connections + contact-gate fix + first termination path — Review

- **Scope:** three linked changes. (a) The AH-005 contact gate TIGHTENS to roster-only
  (agencies with a live `pending_request` lose contact visibility they hold today —
  deliberate). (b) A new `ended` relationship status + the platform's FIRST relation
  termination path (admin disconnect, which deletes pool-membership rows). (c) Admin
  panel doors that mutate live relation state: Door 1 (send-request) and Door 2
  (direct-connect), plus per-relation Disconnect, on the admin Creator-detail page.
- **Loop:** full house loop — I1–I8 read-only inventory → kickoff with locked D1–D11 →
  plan-pause (rulings: no `ended` migration, `is_discoverable` bypass, single `mode`
  POST, one `RelationDisconnected` type, `ended` FE ripple, pool-posture reversal, no D8
  marker column, D10 `runAs`) → build S1–S10 → this review. Ad-hoc log entry: AH-051.
- **Status:** **Closed — approved.** `c6b6cde` (2-SPA cookie fix, Step 0) rides with
  this chunk's push.
- **Verdict:** independent review complete: D1–D11 verified; three break-reverts confirmed
  — inverse-edit restore form accepted as honestly stated; §5.34 sets + the AH-010 agreement
  invariant green; pool-posture reversal and discoverable-bypass reasoning accepted as the
  record; the D-1 no-approved-leg nuance reviewed and accepted; Playwright green (24/24
  effective — the full main suite's two reds were cold-start/load flakes, green on isolated
  re-run, on surfaces AH-051 never touched; `creator-connection-requests` and the admin suite
  passed).
- **Provenance:** drafted by Cursor, reviewed and closed by Claude.

---

## Production posture (§5.40) — PROD-DATA RISK: LOW-MEDIUM (re-derived at final HEAD)

Re-derived on top of `c6b6cde` (branch `main`). This chunk is riskier than a typical
additive field because it (a) tightens a live authorization gate, (b) mutates live
relation state through two new admin doors, and (c) DELETES rows (pool memberships) on
disconnect. Mitigations and honest posture:

- **No migration.** `ended` is a sixth `RelationshipStatus` enum value stored in the
  existing `relationship_status varchar(16)` column, which carries **no DB CHECK
  constraint**. Deploy carries NO migrate step. The PHP enum + the catalogue tripwire
  (`RelationshipStatusEnumTest`) are the documentation. No column is added, so there is
  no `down()` to get wrong.
- **The gate tightening is a behaviour change on live data (deliberate, Pedram-confirmed).**
  Agencies currently holding `pending_request` / `declined` / `prospect` relations LOSE
  contact visibility on deploy. This aligns code with the shipped UI promise ("shared
  only with agencies you are connected to"). **Pre-deploy visibility:** the read-only
  `relations:audit-contact-exposure` command reports the exact blast radius (see the D-1
  count section) so the number is seen before deploy. It performs **zero writes**.
- **Disconnect deletes rows — snapshot-first stays.** `TalentPoolMembership` rows for the
  disconnected pair are deleted inside the same transaction as the status flip + audit.
  The over-reach seam (scope-by-this-agency's-pools) is break-revert-proven below. A DB
  snapshot before deploy remains the standing rule because this is the first path that
  deletes relation-adjacent rows.
- **No backfill.** Every existing relation keeps its current status; `ended` is only ever
  reached forward, via admin disconnect from `roster`.
- **Campaign assignments are deliberately untouched by disconnect** — in-flight commercial
  work survives the relationship ending (proven inert below).

## Decision evidence (D1–D11)

- **D-1 — contact gate → roster-only.** `CreatorPolicy::canSeeContactDetails` now requires
  THIS agency to hold a non-blacklisted `roster` relation, sourced from the shared
  `AgencyCreatorRelation::scopePermitsMessaging()` primitive (so contact + messaging can't
  drift on what "connected" means). `pending_request` / `declined` / `prospect` / `ended`
  / `external` all fail. `ContactDetailsWithholdingTest` §7 adds a positive `roster` case
  - a parameterized negative matrix; `CreatorPolicyTest` adds a unit matrix. Break-revert
    executed (below). Contact does NOT add messaging's `approved` leg — a rostered relation
    is the consent event; approval state is orthogonal here.
- **D-2 — accept re-gates (fail-closed).** `CreatorConnectionRequestController::transition`
  accept now additionally requires the creator's application be `Approved` (422
  `connection.creator_not_approved`) AND the relation not HARD-blacklisted (422
  `connection.blacklisted`); soft blacklist is warn-only (never blocks). Decline is never
  re-gated. Both re-gates apply only when the target is `roster`. Break-revert on the
  blacklist leg executed (below).
- **D-3 — `ended` enum value.** Sixth `RelationshipStatus` case: severed-after-roster,
  re-requestable (like `declined`), never messageable, never contact-visible, excluded
  from `DEFAULT_EXCLUDED_STATUSES`. Consumers swept for exhaustiveness: agency store
  collision (re-request from `ended`), roster list exclusion + explicit filter,
  `isEnded()` helper, factory `ended()` state, `MessageableContactsAgreementTest` matrix
  (stays false — the AH-010 invariant), api-client union + `deriveConnectionState`.
- **D-4 — Door 1 (admin send-request).** `POST /admin/creators/{creator}/connections`
  `mode=request` mirrors the agency store path: same collision matrix (re-request from
  declined/ended, no-op on other statuses), hard-blacklist 422 (mode-distinct
  `connection.request_blacklisted`), `approved` gate, `is_discoverable` bypass; creates
  `pending_request`, rides the EXISTING `ConnectionRequestMail`; records the
  `admin_requested` verb + admin `invited_by_user_id`.
- **D-5 — Door 2 (admin direct-connect).** Same POST, `mode=direct`: MANDATORY reason
  (min 10, the consent paper-trail), targets `roster` immediately; idempotent no-op if
  already rostered; elevates pending_request/declined/ended/prospect/external → roster;
  hard-blacklist 422 (mode-distinct `connection.direct_blacklisted`). Dual-emit: in-app
  `RelationAdminConnected` + `AdminConnectedMail` to the creator, naming the agency, with
  a "contact support if unexpected" line.
- **D-6 — admin disconnect (first termination path).** `POST
/admin/creators/{creator}/connections/{agency}/disconnect`: `roster → ended` ONLY (any
  other status → 422 `connection.not_disconnectable`). MANDATORY reason. In ONE
  `DB::transaction`: status flip + the pair's pool-membership rows deleted (scoped to THIS
  agency's pools) + the reason-required `disconnected` audit. Messaging closes
  automatically (the roster-only gate — asserted, not torn down). Campaign assignments
  deliberately untouched. Both parties notified (dual-emit). §5.34 set + over-reach
  break-revert below.
- **D-7 — verbs + notifications.** Three new `AuditAction` verbs
  (`agency_creator_relation.admin_requested` / `.admin_connected` / `.disconnected`;
  `requiresReason()` true for the latter two) + two `NotificationType` cases
  (`RelationAdminConnected`, `RelationDisconnected` — one direction-agnostic disconnect
  type per the ruling). Both catalogue tripwires updated. Two new mailables
  (`AdminConnectedMail`, `RelationDisconnectedMail`), queued + localized.
- **D-8 — provenance, no marker column.** Admin-initiated rows are distinguishable via the
  distinct audit verbs (the primary record) + `invited_by_user_id` stamped with the acting
  admin on Door 1/2. No schema beyond the enum (ruling adopted).
- **D-9 — admin UI.** Creator-detail gains an "Agency connections" section: a cross-agency
  relation list, a "Connect to agency" action → `ConnectToAgencyDialog` (agency search
  picker + door radio + conditional reason), and a per-row "Disconnect" (roster-only) →
  `DisconnectRelationDialog` (reason). Component specs + a page-level D-9 integration
  block. api-client methods `connections` / `connect` / `disconnect`.
- **D-10 — tenancy (`runAs`, §5.1).** Every admin write to the agency-scoped relation runs
  inside `TenancyContext::runAs($agency->id, …)` so the `BelongsToAgency` scope + auto-fill
  apply as if the agency acted. All three new routes added to `docs/security/tenancy.md §4`
  with scope/authorization notes.
- **D-11 — out of scope, recorded.** Creator-side + agency-side disconnect are deferred
  (tech-debt); `external` stays unreachable (untouched); agency-notified-on-accept/decline
  stays deferred. See tech-debt entry.

## Break-reverts (§5.35) — executed verbatim

Each: introduce the break → run the pinning test(s) → observe red → revert → re-run green.
The whole chunk is uncommitted, so `git status` shows the file as `M` (vs HEAD) throughout;
"restored" is proven by the revert being the exact inverse edit + the re-run going green.

**(1) D-1 contact gate.** Swapped `->permitsMessaging()` back to the pre-D-1
`->where('is_blacklisted', false)` (any non-blacklisted status) in
`CreatorPolicy::canSeeContactDetails` → ran `ContactDetailsWithholdingTest` +
`CreatorPolicyTest` → **9 failed** (every non-roster matrix cell: `pending_request`,
`declined`, `prospect`, `ended` now leak contact — "Failed asserting that true is false").
Reverted the exact line; re-ran → **51 passed**.

**(2) D-2 blacklist re-gate.** Neutered the hard-blacklist accept guard (`if (false && …)`)
in `CreatorConnectionRequestController::transition` → ran `CreatorConnectionRequestTest` →
**1 failed** ("BLOCKS accepting when the relation is HARD-blacklisted" now returns 200, not
422). Reverted; re-ran → **14 passed** (soft-blacklist-allows + decline-never-re-gated stay
green alongside).

**(3) D-6 pool-scope (over-reach).** Dropped the `->whereIn('talent_pool_id', $poolIds)`
scope from the disconnect teardown so it deletes the creator's memberships across ALL
agencies → ran `AdminCreatorDisconnectTest` → **1 failed** ("over-reach break-revert seam:
only THIS agency's pool memberships are deleted" — agency B's membership deleted too,
"Failed asserting that false is true"). Reverted; re-ran → **12 passed**.

## §5.34 negative / invariant sets (all green)

- **Withholding matrix incl. `ended`** — `ContactDetailsWithholdingTest` §7: roster ✓ shows,
  {pending_request, declined, prospect, ended} ✗ withheld by omission.
- **MessageableContactsAgreementTest** — `ended` joins both the agency and creator matrices;
  stays FALSE everywhere (the AH-010 invariant: set-valued finder ≡ single-pair gate).
- **Assignments-survive** — `AdminCreatorDisconnectTest`: a `CampaignAssignment` still exists
  post-disconnect.
- **Pools-emptied** — the pair's memberships are gone post-disconnect.
- **Messaging-gate-false post-disconnect** — `canMessageRelationship` returns true pre-,
  false post-disconnect.
- **Second-disconnect 422** — a disconnect on an already-`ended` relation → 422
  `connection.not_disconnectable`.
- **Per-state collision matrix, both doors** — Door 1: net-new pending, re-request from
  declined/ended, no-op elsewhere, hard-blacklist 422; Door 2: net-new roster, elevate from
  every non-roster status, idempotent no-op if already roster, hard-blacklist 422. All
  pinned in `AdminCreatorConnectionTest`.

## Pool-posture reversal — the two postures are coherent TOGETHER (do not "fix" one against the other)

The platform now holds two DELIBERATELY OPPOSITE postures on pool membership, and they are
coherent side by side:

- **Blacklist = warn, don't remove.** When an agency blacklists a creator, pool memberships
  are RETAINED (a warning overlay is shown). Pool membership is the agency's own curation;
  a blacklist is a caution flag on top of it, not an erasure of the agency's work.
- **Disconnect = remove.** When the relationship ENDS (admin disconnect), the pair's pool
  memberships are DELETED. The relationship no longer exists, so continued pool presence
  would leak a severed relation into a curation surface.

The distinction is "is there still a relationship?" — blacklist keeps the relationship (a
flagged one); disconnect ends it. A future reviewer must not unify these: making blacklist
remove memberships would destroy agency curation on a warning; making disconnect retain them
would leak a dead relationship. Stated here so neither is "corrected" against the other.

## `is_discoverable` bypass for admin — reasoning (adopted verbatim, ruling)

Admin-mediated doors bypass the creator's `is_discoverable` flag; `approved` binds both
doors. Reasoning: `is_discoverable` is a **browsing-visibility preference** (whether the
creator appears in an agency's cold-outreach discovery surface), NOT an **eligibility gate**.
These doors are admin-mediated arrangements (Door 2 records an offline agreement Door 1
re-drives the agency's own request) — not cold outreach — so the browsing preference does
not apply. `approved`, by contrast, is a genuine eligibility gate (the creator's application
must be accepted before any agency relationship forms) and therefore binds both doors.

## D-1 pre-deploy count command — output shape

`php artisan relations:audit-contact-exposure` (READ-ONLY, zero writes). Output shape:

```
AH-051 D-1 contact-exposure audit (READ-ONLY, no writes).

Per-status breakdown of relations losing contact visibility:
  external         N
  prospect         N
  pending_request  N
  declined         N
  ended            N

  of which have contact data populated: N

N relation(s) across M agencies currently expose contact.
```

The closing line is the review sentence: **"N pending_request relations across M agencies
currently expose contact"** (the command generalizes it across the whole affected set).
`AuditContactExposureCommandTest` pins the per-status breakdown, the total, the distinct-
agency count, the contact-data-populated subcount, and asserts strict read-only (no DB
mutation). On the empty local DB the run reports all zeros — the SHAPE is confirmed; the
production number is read off the pre-deploy run.

## Event::fake / notification splits

`AdminCreatorConnectionTest` + `AdminCreatorDisconnectTest` pin, for each notification:

- **dispatched leg** — Door 2 queues `AdminConnectedMail` + in-app `RelationAdminConnected`;
  disconnect queues `RelationDisconnectedMail` ×2 (creator + agency member) + in-app
  `RelationDisconnected` to both.
- **no-side-effect leg** — Door 1 queues `ConnectionRequestMail` and does NOT queue
  `AdminConnectedMail`; an idempotent no-op door 2 (already roster) emits no second
  notification/mail.
- **positive leg** — in-app `Notification` rows asserted by type + recipient.

## §5.3 mailables — real-render + queued-locale + parity

`AdminRelationMailTest` renders both new mailables (`AdminConnectedMail`,
`RelationDisconnectedMail`) with real content assertions, pins localized subjects, and
loops all 24 UI locales asserting a clean real render with placeholders resolved (the §5.3
real-render + queued-locale proof). Backend `creators.php` gained `admin_connected` +
`disconnected` groups across all 24 locales; the flaky-10 carry real MT baselines. Locale
parity (backend `LangParityTest` + both SPA i18n-locale-parity architecture specs) green.

## Gate table (final HEAD)

| Gate                                                     | Scope                          | Result                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| -------------------------------------------------------- | ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Pest (full, serial, 2G)                                  | apps/api                       | **1975 passed**, 1 skipped (6968 assertions), 159s                                                                                                                                                                                                                                                                                                                                                                                                                            |
| Pint `--test` (all)                                      | apps/api                       | **passed**                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| PHPStan (1G)                                             | apps/api                       | **No errors**                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| Vitest (full)                                            | apps/admin                     | **443 passed** (1 load-induced timeout flake in `SignInPage` during the concurrent run; **green in isolation** at 697ms — unrelated to this chunk's surfaces)                                                                                                                                                                                                                                                                                                                 |
| Vitest (full)                                            | apps/main                      | **1196 passed** (130 files) — incl. i18n-locale-parity; discover subset 23                                                                                                                                                                                                                                                                                                                                                                                                    |
| Vitest + tsc                                             | packages/api-client            | **204 passed** (incl. new `agency.spec.ts`); typecheck clean                                                                                                                                                                                                                                                                                                                                                                                                                  |
| vue-tsc                                                  | apps/admin                     | clean                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| ESLint                                                   | apps/admin                     | **0 errors**                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| ESLint                                                   | apps/main                      | **0 errors** (2 pre-existing `vue/no-v-html` warnings — `ClickThroughAccept`, `ProfileBasicsForm` — both predate this chunk)                                                                                                                                                                                                                                                                                                                                                  |
| Locale parity                                            | admin ×24 + main ×24 + backend | green (keyset + placeholder + plural)                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| Playwright `creator-connection-requests` (+ full per §4) | apps/main + apps/admin         | **GREEN — 24/24 effective.** Full main suite (22 specs, isolated `catalyst_e2e`, `migrate:fresh`): **20 passed first-run**; `creator-connection-requests` (the D-2 accept path) **passed first-run**; the 2 reds (`2fa-enrollment-and-sign-in`, `brands`) were **cold-start/load flakes** — both green on isolated re-run, both on surfaces AH-051 never touched (2FA enrollment, brand CRUD), failure signature a "waiting for locator" timeout. Admin suite **2/2 passed**. |

## Touched files

Backend: `RelationshipStatus` enum (+ test), `AgencyCreatorController` DEFAULT_EXCLUDED,
`AgencyConnectionRequestController` collision, `AgencyCreatorRelationFactory` (`ended()`),
`AgencyCreatorRelation` (`isEnded()`), `CreatorPolicy::canSeeContactDetails`,
`CreatorConnectionRequestController` re-gates, `AuditAction` (+3 verbs) + test,
`NotificationType` (+2) + test, `AuditContactExposure` command (+ test), two mailables +
two blade views, `AdminCreatorConnectionController` (+ `AdminCreateConnectionRequest`,
`AdminDisconnectRequest`), Creators routes, `lang/**/creators.php` ×24, tenancy.md §4;
tests: `ContactDetailsWithholdingTest`, `CreatorPolicyTest`, `CreatorConnectionRequestTest`,
`AgencyConnectionRequestTest`, `AgencyCreatorRosterTest`, `MessageableContactsAgreementTest`,
`AdminRelationMailTest`, `AdminCreatorConnectionTest`, `AdminCreatorDisconnectTest`,
`AuditContactExposureCommandTest`, enum tests. Frontend admin: `CreatorDetailPage.vue`
(+ D-9 section & spec block), `ConnectToAgencyDialog.vue` + spec,
`DisconnectRelationDialog.vue` + spec, `creators.api.ts`, `creators.json` ×24. Frontend
main: `DiscoverPage.vue`, `DiscoverProfilePage.vue` (+specs), `app.json` ×24 (`connection.ended`

- `roster.status.ended`). Packages: `api-client` `agency.ts` (`ended` union + derive) +
  `agency.spec.ts`. Docs: this file, ad-hoc log (AH-051), tech-debt, resumption template.

---

## Post-close addendum (eyes-on fixes, 2026-07-26)

The original review above is unchanged. This section records six defects Pedram found by
driving the shipped feature by hand **after** the chunk closed, and the fixes that answer
them. Nothing here reopens a D-1…D-11 decision; every fix is either a presentation seam the
suite never asserted, a copy judgment, or one genuine gap in D-3's status sweep.

**Provenance:** found by Pedram in eyes-on, fixed by Cursor.

**Held set:** `4af63b2`, `046d26c`, `530d7d8`, `bdc957b`, `dd65868`, `d381a77` — all atop the
pushed docs commit `30116da`, all held with the AH-051 push call.

### The six fixes

**1 · `4af63b2` — admin connect dialog (three defects in one commit).** _Bug:_ connecting an
unapproved creator produced a correct refusal rendered as the raw string
`connection.creator_not_approved`, and afterwards the agency picker showed a ULID instead of
the agency name. _Root cause:_ three independent frontend seams — the catch handler passed
`error.code` into `t()` as though a 422 code were an i18n key; Vuetify resolves a selection's
displayed title from `items`, and the parent reset `agencies` to `[]` on an empty query,
orphaning the selected ULID; and the dialog had no upfront `approved` gate, so the admin could
walk into a guaranteed rejection. The suite missed all three because the admin specs asserted
_that the API was called with the right payload_, never _what the user sees when it refuses_.
_Pin:_ `maps a connect 422 code to a localized message (NOT the raw code)` + the disconnect
twin, `keeps rendering the selected agency NAME after the search results reset`, and the
unapproved-warning / approved-no-warning pair at both dialog and page level.

**2 · `046d26c` — roster status chip.** _Bug:_ the "All" chip showed two creators while the
Pending-requests chip revealed a third, reading as missing data. _Root cause:_ not a data
defect. D-6 deliberately excludes `pending_request` + `declined` from the default index and
D-3 added `ended` to that set; the word "All" promised a total the backend never returns. The
backend exclusion **was** correctly pinned and the FE spec asserted the chip list existed —
nobody asserted the label was honest about the query behind it. _Pin:_ the spec case
_labels the default chip "Active" (not "All") and offers an `ended` chip_.

**3–4 · `530d7d8` + `bdc957b` — admin-connected mail body.** _Bug:_ reading the real email,
the body over-explained: it disclosed the outside-agreement rationale to the creator and then
narrated the mechanism. _Root cause:_ a copy judgment, not a defect — the D-7 mailable shipped
with prose that only reads wrong once seen in an inbox. _Pin:_ none needed, and deliberately
so — `AdminRelationMailTest` already pins the structural invariants (agency name, recipient
name, support line, placeholder resolution across all 24 locales) and does **not** pin prose
verbatim. Pinning marketing sentences would be brittle without protecting anything.

**5 · `dd65868` — canonical 403 envelope, closed threads, notification registry.** _Bug:_ after
a disconnect, opening the chat produced a 403 the SPA rendered as "Unrecognized error
response", and the disconnect notification read "You have a new notification." _Root cause:_
two independent holes. The SPA's `ApiError.fromEnvelope` expects the canonical JSON:API
envelope, but Laravel's default handler returns `{"message": …}` for `HttpException` — so
**every** 403 platform-wide (all 82 `authorize()` call sites) hit the generic fallback. A
`ValidationExceptionRenderer` with tests existed; nothing equivalent for 403, and no test
anywhere asserted a 403 **body** — only status codes were ever asserted. Separately, the two
new AH-051 `NotificationType` cases were added to the backend enum (pinned by
`NotificationTypeEnumTest`) but never registered in the frontend `LIVE_TYPES`, and no parity
spec existed between the backend enum and the FE registry. Fixing the first exposed a third
asymmetry: reading a relationship thread was never gated the way sending was, so a severed
relation presented a live composer on a dead thread. _Pin:_ `ForbiddenExceptionRendererTest`
(3 cases including the `ApiError.fromEnvelope` contract), a canonical-envelope case in
`RelationshipMessageApiTest`, `templates.spec.ts` pinning the registry against the backend
enum, and the `RelationshipThreadView` closed-state block.
**The 403-envelope half of this commit is carried as its own entry, [AH-052](adhoc-changes-log.md)** —
its blast radius is the whole platform's error contract, not this chunk, and a future reader
debugging error shapes will search for "403 envelope", not "admin connections". The
closed-composer and notification-registry halves stay here.

**6 · `d381a77` — re-pooling an ended relation.** _Bug:_ a creator disconnected by super-admin
sat correctly in the roster's Ended section, yet the detail page still offered Add to pool and
it worked. _Root cause:_ the genuine gap of the six. D-6 deletes the pair's pool memberships,
but nothing stopped a re-add — the guard only checked that a relation **row exists**, and an
`ended` relation keeps its row. That guard had been hand-copied into four controllers, each
documented as "any status qualifies", which was true until D-3 introduced `ended`. D-3 asked
for a sweep of every status consumer; pool membership was missed. The suite missed it because
the pin was on the **deletion** (pools-emptied on disconnect) and nobody asked the inverse
question: can it come back? _Pin:_ 9 backend cases + 4 frontend cases, including a dataset
proving `roster`/`external`/`prospect`/`pending_request`/`declined` all stay poolable (no
over-block) and one proving a re-connected creator becomes poolable again.

### Risk answers — did any fix touch a gated surface?

Verified mechanically over `30116da..HEAD`: **all three break-revert subjects show zero
diffs.** `CreatorPolicy.php` (the D-1 contact gate), `CreatorConnectionRequestController.php`
(the D-2 re-gates) and the D-6 disconnect controllers were not touched by any of the six
commits. The pre-close break-reverts therefore still stand as evidence and did not need
re-execution; their pinning tests were re-run regardless and are green.

Per fix, explicitly:

| Fix       | D-1 gate | D-2 re-gates | D-6 txn / pool-scope            | §5.34 pins    | Notification path   | i18n keyset         |
| --------- | -------- | ------------ | ------------------------------- | ------------- | ------------------- | ------------------- |
| `4af63b2` | no       | no           | no                              | no            | no                  | **yes** — admin ×24 |
| `046d26c` | no       | no           | no (presentational only)        | no            | no                  | **yes** — main ×24  |
| `530d7d8` | no       | no           | no                              | no            | **yes** — mail body | no (values only)    |
| `bdc957b` | no       | no           | no                              | no            | **yes** — mail body | no (values only)    |
| `dd65868` | no       | no           | no                              | AH-010 (read) | **yes** — registry  | **yes** — main ×24  |
| `d381a77` | consumer | no           | adjacent (txn itself untouched) | re-run green  | no                  | no                  |

Notes on the three non-obvious cells. `046d26c` is presentational only — `DEFAULT_EXCLUDED_STATUSES`
is untouched, and the new chip drives `?status=ended`, already pinned by _"filters BY ended —
the chip returns exactly those (AH-051 D-3)"_. `dd65868` touches the AH-010 messaging agreement
on the **read** path only: `can_send` is computed _from_ the existing policy rather than
reimplementing it, so it cannot drift from the gate. `d381a77` touches
`AgencyCreatorDetailController`, a D-1 consumer, as a guard refactor only — contact gating is
unchanged, and both the pools-emptied and assignments-survive §5.34 sets were re-run green.

The mail-copy commits changed placeholder counts (the body went from two `:agency`
occurrences to one), applied identically across all 24 locales; `LangParityTest`'s placeholder
check is green.

### Break-reverts on the new guards (fresh, executed at `d381a77`)

Three, each failing exactly the tests that claim to protect it, each restored clean:

- **Pool add `ended` guard** — `if ($relation->isEnded())` → `if (false)`: 2 failed, 1 passed
  (the re-connected-creator case correctly still passes, since it asserts the _allow_ leg).
- **Availability `ended` guard** — same inversion: 2 failed.
- **Frontend `canAddToPool`** — drop the status clause, leaving the role check: 2 failed.

### Accept-as-untestable

Two, recorded so no future reviewer reads them as missing coverage:

1. **The "All" chip class of defect.** The corrected label is pinned, but _a label that
   misdescribes the query behind it_ is not reachable by assertion — the backend was right, the
   chip list was right, and only a human reading the word "All" could see the lie. No test
   shape would have caught this.
2. **Mail prose.** Deliberately unpinned, as above: the structural invariants are pinned and
   the sentences are not, because pinning copy verbatim is brittle without protecting anything.

### Operational finding (carried to the resumption template)

While verifying the mail-copy change, the new body did not appear until the dev stack was
restarted: the **long-running queue worker caches translations in memory**. This is not
specific to AH-051 — it applies to every mail-copy change the platform ever ships. The worker
must be restarted on deploy or it will keep sending the old body from cache.

### Gate board at `d381a77` (full re-run, post-fixes)

| Gate                                       | Result                                                                                               |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------------- |
| Pest (full, serial, 2G)                    | **2001 passed**, 1 skipped (7080 assertions), 86.9s                                                  |
| Vitest — apps/main                         | **1217 passed** (131 files)                                                                          |
| Vitest — apps/admin                        | **449 passed** (53 files)                                                                            |
| Vitest — packages/api-client               | **204 passed** (9 files)                                                                             |
| vue-tsc — main / admin / api-client        | clean                                                                                                |
| ESLint                                     | admin **0 errors**; main **0 errors** (2 pre-existing `vue/no-v-html` warnings, both predate AH-051) |
| Pint `--test` (all)                        | **passed**                                                                                           |
| PHPStan                                    | **No errors**                                                                                        |
| Locale parity                              | green (`LangParityTest` keyset + `:named` placeholders ×24)                                          |
| Playwright (full, isolated `catalyst_e2e`) | **GREEN — 24/24, all first-run, zero flakes.** main **22/22** (3.0m), admin **2/2** (27s)            |

All sixteen pinned suites pass by name, including contact withholding, the AH-010 messageable-
contacts agreement, the relationship-status catalogue, both connection-request suites, admin
connect + disconnect, and the mail suite. The single skip is the pre-existing `[postgres-only]`
full-text-search case in `AgencyCreatorRosterTest`, unrelated to this work.

The Playwright re-run improves on the AH-051 close, which was 24/24 _effective_ (20 first-run
plus two cold-start flakes green on isolated re-run); this run is 24/24 **first-run** with no
retries. All five specs traversing changed surfaces passed:
`roster-search-and-affordances` (the chip rename), both `creator-detail` cases (the pool-button
gate), all three `talent-pools` cases, both `permissions` cases (403 handling), and
`creator-connection-requests` (the D-2 accept path). The two `talent-pools` add-a-creator specs
are the useful negative evidence for `d381a77`: they add **live** relations to pools and still
pass, so the new `ended` guard did not over-block.

Run hygiene: dev stack down, `DB_DATABASE` overridden to `catalyst_e2e` with
`reuseExistingServer: false` on the API (the post-incident contract), `migrate:fresh` confirmed in
the log, then the stack restarted and health-checked (API `/up` 200, both SPAs 200, queue worker
up). The developer database `catalyst` was verified untouched afterwards (12 users / 7 creators /
2 agencies / 7 relations).
