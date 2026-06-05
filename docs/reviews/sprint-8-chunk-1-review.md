# Sprint 8 Chunk 1 — Campaign & Assignment Foundation + the State Machine — Review

**Status:** Closed. Spot-check passed (no post-merge corrections). The state machine is verified where it matters: `assertSource()` is fail-closed (`invited→contracted` and `declined→*` both throw with error codes, never silent); `commit()` makes each transition atomic (stamp + audit + dispatch in one DB transaction); the vendor-gated states are genuinely unreachable (gated exceptions, no manual path to `payment_released`); the three verb≠state board-key verbs are correct (the board's future vocabulary); and `counter` records `countered_fee_*` leaving `agreed_fee_*` untouched. Audit-by-hand (not the `Audited` trait) correctly keeps `brief`/`notes`/`cancelled_reason` out of snapshots. Create gated admin/manager (staff 403), brand cross-tenancy rejected, money minor-units, enum catalogue/width tests pin the sets, the four deferred tabs are clean empty-states, and `03-DATA-MODEL.md` is reconciled (`countered_fee_*` + both deferred-table callouts).

**Reviewer:** drafted by Cursor (single-chunk build pass); spot-checked + closed. **The spot-check was weighted on the `CampaignAssignmentStateMachine`** — the net-new, load-bearing piece the rest of the chunk hangs off.

**Reviewed against:** the Sprint 8 Chunk 1 kickoff (D-1…D-10) + the plan-pause confirmations (events-AND-audit, board-key verb alignment, deferred draft tables, `countered_fee_*` addition) + [`03-DATA-MODEL.md §7`](../03-DATA-MODEL.md) + `PROJECT-WORKFLOW.md` §5 standards (5.17 coverage, 5.35 break-revert) + [`10-BOARD-AUTOMATION.md`](../10-BOARD-AUTOMATION.md) (the board event-key catalogue) + [`docs/security/tenancy.md`](../security/tenancy.md).

> **The build is foundation, not flow.** It ships the schema, the enums, the complete state machine (built + tested at the **service level** — no HTTP caller this chunk; invite/accept/decline/counter endpoints are Chunk 2), agency campaign CRUD with an admin/manager create gate, and the net-new `apps/main` campaigns module. The vendor-gated tail of the lifecycle is built-but-unreachable by design.

---

## ⚠ The state machine — review this first

[`CampaignAssignmentStateMachine`](../../apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php) is the **single authority** over `campaign_assignments.status` — no controller flips the column directly. Every legal transition does three things atomically (`commit()` wraps them in a DB transaction):

1. **Stamps a timestamp** where a column exists (`responded_at`/`accepted_at`/`submitted_draft_at`/`approved_at`/`posted_at`/`cancelled_at`). `contracted`/`producing`/`revision_requested`/`payment_held` have **no dedicated `*_at` column** — the audit row + the status change are the record (intentional; matches the data model).
2. **Writes an `audit_logs` row** with the **board event-key verb** + `{from, to}` metadata.
3. **Dispatches `AssignmentTransitioned`** (no listener — the board sprint adds it; see tech-debt).

### The graph (fail-closed)

```
invited → {declined, countered, accepted}
accepted → contracted        (flag-gated: contract_signing_enabled)
contracted → producing
revision_requested → producing   (the review loop — second legal source of producing)
producing → draft_submitted
draft_submitted → {revision_requested, approved}
approved → posted → live_verified → payment_held → payment_released   (the vendor-gated tail)
any non-terminal → cancelled (reason required)
```

`assertSource()` is the load-bearing guard: an illegal source throws [`AssignmentTransitionException::illegal()`](../../apps/api/app/Modules/Campaigns/Exceptions/AssignmentTransitionException.php) carrying an `errorCode` — **fail-closed**, never a silent no-op (e.g. `invited → contracted` throws; `declined → *` throws because `declined` is terminal).

### Verb ≠ landing-state (the board's vocabulary, D-9)

Three audit verbs deliberately differ from their status to match the [board event-key catalogue](../10-BOARD-AUTOMATION.md), not the enum:

| Transition method | Lands on status | Board-key audit verb           |
| ----------------- | --------------- | ------------------------------ |
| `approve()`       | `approved`      | `assignment.draft_approved`    |
| `markPosted()`    | `posted`        | `assignment.posted_by_creator` |
| `holdPayment()`\* | `payment_held`  | `assignment.payment_funded`    |

\* gated — see below. All other verbs match their state 1:1.

### Vendor-gated (D-6) — built, guarded, **unreachable**

`verifyLive()` / `holdPayment()` / `releasePayment()` have real source-guards but then throw [`AssignmentTransitionGatedException`](../../apps/api/app/Modules/Campaigns/Exceptions/AssignmentTransitionGatedException.php) (social adapter parked; escrow = Sprint 10). `contract()` throws `contractSigningDisabled()` unless the `contract_signing_enabled` Pennant flag is on (mock exists). **No manual path reaches `live_verified`/`payment_held`/`payment_released`** — the footgun guard the tests pin.

### Counter (D-7)

`counter()` records `countered_fee_minor_units`/`countered_fee_currency` **without overwriting `agreed_fee_*`** (the agency's original offer is preserved). Single-shot — no negotiation loop (`countered` has no outgoing edge but cancel); see tech-debt.

### Cancel (D-9)

`cancel(reason, actor)` from any non-terminal → `cancelled`; **terminal source rejected** (`AssignmentTransitionException::terminal()`), **empty reason rejected** (`reasonRequired()`); `assignment.cancelled` is the one `requiresReason()` audit verb.

---

## Schema (D-1/D-2/D-7)

- **`campaigns`** + **`campaign_assignments`** migrations follow the `brand_creator_blacklists` conventions (`id()` + `ulid()->unique()`, explicit `idx_*`/`unique_*`, `string(n)` enums, `bigInteger` minor-units, `char(3)` currency, `jsonb`, `softDeletes()`).
- **`countered_fee_*` (D-7):** net-new columns on `campaign_assignments`, documented in `03-DATA-MODEL.md §7` as a Sprint-8 addition.
- **`posting_due_at`** is on assignments (kickoff D-2 omitted it; `idx_assignments_dates` needs it).
- **Deferred (D-4):** `campaign_drafts` + `campaign_posted_content` are **not** migrated — they are children of the assignment (no FK from the assignment to them) and unreferenced this chunk. Logged in tech-debt + `03-DATA-MODEL.md`.

## Enums

`CampaignStatus` (5), `CampaignObjective` (5), `AssignmentStatus` (14, with `isTerminal()` = declined/payment_released/cancelled). Catalogue + varchar-width tests pin the case sets (mirror `BlacklistEnumsTest`).

## Audit + events

[`AuditAction`](../../apps/api/app/Modules/Audit/Enums/AuditAction.php) gained `campaign.created`/`campaign.updated` + the 14 `assignment.*` board-key verbs; `assignment.cancelled` → `requiresReason()`. `AuditActionEnumTest` updated. `AssignmentTransitioned` implements `AssignmentEventContract` (`eventKey()` returns the `AuditAction`); dispatched only, no listener.

> **Audit strategy (deliberate):** `Campaign`/`CampaignAssignment` do **NOT** use the `Audited` trait — `brief`, `notes`, and `cancelled_reason` are free-text and must stay out of audit snapshots. Audit rows are written **explicitly** (`CampaignController` for CRUD, the state machine for transitions) with hand-picked safe fields.

## CRUD + authorization (D-8/D-10)

`CampaignController` (index/store/show/update) mirrors `BrandController` + the agency-creator filter pattern (brand/status/date, agency-scoped, `{data,meta}`). `CampaignPolicy`: viewAny/view = any agency member; **create/update = admin + manager (staff 403)**. `CreateCampaignRequest`/`UpdateCampaignRequest` validate the `brief` sub-fields → `jsonb`, `budget_minor_units` as integer minor units, `budget_currency` size:3, objective/status via `Enum`. `brand_id` arrives as a ULID and is resolved to the internal id **with an agency-ownership check** (cross-tenant brand → validation failure). `CampaignAssignmentController` is read-only (the Creators tab).

## Frontend (`apps/main` + `packages/api-client`)

- `packages/api-client/src/types/campaign.ts` (resource, payloads, list params/envelope) exported from `types/index.ts`.
- `modules/campaigns/`: `api/campaigns.api.ts`, `CampaignForm.vue` (major↔minor budget conversion, brief sub-field assembly), `CampaignListPage.vue` (brand/status/date filters, server-paginated table, empty state), `CampaignCreatePage.vue` (`extractFieldErrors` 422 binding), `CampaignDetailPage.vue` — **the app's first `v-tabs`+`v-window` page**: Overview/Creators/Settings live; **Board/Drafts/Payments/Messages are `coming-soon` empty states** (nothing half-built). Settings edit gated admin/manager.
- Routes (`campaigns.list`/`.create`/`.detail`, `layout:'agency'`, guards `requireAuth`+`requireAgencyUser`) + `AgencyLayout` nav item + i18n `app.campaigns.*` / `app.nav.campaigns` in **en/pt/it**.
- **Arch tests grown:** all three `campaigns.*` routes added to the `requireAgencyUser` guard expected set; `CampaignCreatePage.vue` + `CampaignDetailPage.vue` added to the 422 form-error allowlist (MFA allowlist untouched).

## Coverage (§5.17)

- **State machine** (the weighted suite): every legal transition fires the right verb + stamps the right timestamp + dispatches its event; the illegal matrix is rejected fail-closed; cancel from each non-terminal works, terminal cancel + empty reason rejected; the vendor-gated transitions throw and no legal path reaches the gated states; `counter` records `countered_fee` not `agreed_fee`.
- Enum catalogue + width tests; `AuditActionEnumTest` updated.
- CRUD: admin/manager create gate (staff 403); brief sub-fields land in `brief` jsonb; list filters by brand/status/date + agency-scoped; money is minor-units integer; cross-agency access 404s.
- FE: `campaigns.api.spec.ts` (filter threading), `CampaignListPage.spec.ts`, `CampaignDetailPage.spec.ts` (tabs + empty states + role-gated Settings) — 29 FE tests incl. the two arch suites, all green; `vue-tsc` + ESLint clean.

## Deferrals (all logged in `tech-debt.md`)

1. **Board** — events emitted, listener-less (additive board sprint).
2. **Counter** — single-shot, no negotiation loop.
3. **Vendor-gated transitions** — `live_verified`/`payment_*` (social + S10 escrow) + `contracted` (e-sign flag) built but unreachable.
4. **Draft tables** — `campaign_drafts` + `campaign_posted_content` → Sprint 9.

---

## Spot-check anchors

- Illegal-transition matrix is fail-closed (`invited → contracted` throws; `declined → *` throws).
- The vendor-gated states are unreachable — **no** manual path lands on `payment_released` etc.
- Every legal transition logs its **board-key** audit verb (the board's future vocabulary), incl. the three verb≠state cases.
- `counter` records `countered_fee_*`, leaving `agreed_fee_*` untouched.
- Campaign create is gated admin/manager (staff 403); brand cross-tenancy rejected.
- Money is minor-units integer end-to-end (FE major↔minor conversion in `CampaignForm`).
- Enum catalogue/width tests pin the case sets.
- Board/Drafts/Payments/Messages tabs are empty-state "coming soon" — nothing half-built.
