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
