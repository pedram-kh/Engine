# Sprint 8 Chunk 2 — Matching convergence + creator-side (invite / accept / decline / counter) — Review

**Status:** Closed. Spot-check passed (no post-merge corrections). The two-tier gate verifies at distinct severities — blacklist **422 hard-block** (both scopes), availability **409-then-acknowledge soft-warn**, soft-on-either doesn't gate — and the brand-scoped break-revert is pinned with per-brand isolation alongside it (this-brand → 422, different-brand → succeeds): the deferred promise enforced and scoped correctly. The four `creators/me/assignments/*` allowlist rows are in `tenancy.md §4` with the scope-bypass + structural-ownership + fail-closed justification; the `assignment_id` FK (SET NULL) + the `reinvite()` edge are reconciled in the data model. Invite is the correctly-framed hand-audited create-exception; accept/decline/counter go through the machine; the auto-block fires via the first `AssignmentTransitioned` listener; creator-self clones 6.6c (non-owned 404, fail-closed); invite authz is admin+manager+staff (execute, non-member 404). The seven divergences are all sound and plan-flagged.

**Reviewer:** drafted by Cursor (single-chunk build pass); spot-checked + closed. **The spot-check was weighted on the two-tier gate** ([`AssignmentInviteGate`](../../apps/api/app/Modules/Campaigns/Services/AssignmentInviteGate.php) + its wiring in [`CampaignAssignmentController::store()`](../../apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentController.php)) — the load-bearing, net-new piece — and on the **invite create-exception** (the one endpoint that hand-writes its own audit + event rather than going through the machine).

**Reviewed against:** the Sprint 8 Chunk 2 kickoff (corrections #1–#4 + D-1…D-13) + the plan-pause divergences (#1–#7, flagged below) + [`03-DATA-MODEL.md`](../03-DATA-MODEL.md) + `PROJECT-WORKFLOW.md` §5 standards (5.17 coverage, 5.35 break-revert, 5.15 allowlist-discipline) + [`docs/security/tenancy.md §4`](../security/tenancy.md) + Chunk 1's [`CampaignAssignmentStateMachine`](../../apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php) (the sole status authority) + the Sprint 5 / Sprint 7 deferred promises (availability auto-block, brand-scoped blacklist).

> **Chunk 2 is the convergence.** Chunk 1 shipped the state machine at the service level with no HTTP caller. Chunk 2 gives it its **front door** (the agency invite create-path), its **creator side** (accept/decline/counter on `creators/me/`), and fires five earlier threads at once: pools-style bulk picker, **both** blacklist predicates, the availability auto-block (Sprint 5's deferred hook), the `/me/` self-serve pattern, and Chunk 1's machine + `countered_fee`. Three corrections from the read-pass inventory are folded in; seven divergences from the kickoff are flagged below.

---

## ⚠ The two-tier gate — review this first

The gate is the conceptual core: **two different axes, two different severities.** It runs in [`CampaignAssignmentController::store()`](../../apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentController.php) before any row is created.

```
invite (create)
  │
  ├─ hard blacklist? (agency-wide OR brand-scoped)  ──yes──▶  422 assignment.blacklisted   ← HARD BLOCK
  │
  └─ hard availability conflict?                    ──yes, not acknowledged──▶  409 assignment.availability_conflict + payload   ← SOFT WARN
                                                    ──no, OR acknowledged=true──▶  create status=invited + hand-written audit + event
```

### Tier 1 — blacklist = HARD BLOCK (422). Both scopes compose (D-1)

[`AssignmentInviteGate::isHardBlacklisted(Campaign, creatorId)`](../../apps/api/app/Modules/Campaigns/Services/AssignmentInviteGate.php) returns true if **either** predicate matches:

- **Agency-wide hard** — an `agency_creator_relations` row for `(campaign.agency_id, creator_id)` with `is_blacklisted = true AND blacklist_type = 'hard'` (the `excludeHardBlacklisted` / connection-gate shape).
- **Brand-scoped hard (the deferred promise comes due, Sprint 7)** — a hard `brand_creator_blacklists` row for `(campaign.brand_id, creator_id)`. The table is keyed `(brand_id, creator_id)` with no `agency_id`; the agency→brand derivation is `brands.agency_id`. This excludes from **this brand's** campaign only.

**Soft (either scope) does NOT block.** This is the half that Sprint 7 logged as "recorded-now, enforced-at-S8" — now enforced (tech-debt entry closed).

### Tier 2 — availability = SOFT WARN (409-then-acknowledge, D-2)

[`AssignmentInviteGate::availabilityConflict(Campaign, Creator)`](../../apps/api/app/Modules/Campaigns/Services/AssignmentInviteGate.php) delegates to the Sprint 5 [`AvailabilityConflictService::detect()`](../../apps/api/app/Modules/Creators/Services/Availability/AvailabilityConflictService.php) over the campaign's posting window. A **hard** conflict returns **409 `assignment.availability_conflict`** with the overlapping occurrences as a payload — **not** a block. The agency re-submits with `acknowledged: true` to proceed (a net-new soft-warn protocol). **Soft availability does not warn.**

### The invite is a CREATE, not a machine transition (correction #1)

The machine has no `invite()`. The `invited` row is created **directly** by `store()`, which then **hand-writes its own `assignment.invited` audit row + dispatches `AssignmentTransitioned`** — the deliberate create-exception, hand-audited like CRUD. The "endpoints call the machine, never flip status" rule applies to accept/decline/counter/reinvite; invite is the create-exception. Idempotent on `unique(campaign_id, creator_id)` — inviting twice yields one row and a clean response, not a duplicate.

---

## Backend

### The `reinvite()` machine edge (correction #3 + D-7)

[`CampaignAssignmentStateMachine::reinvite()`](../../apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php) is the guarded `countered → invited` edge added so the machine stays the sole status authority (no raw back-write). It sets a new `agreed_fee_*`, **clears `countered_fee_*` + `responded_at`** (re-opens cleanly), and audits the distinct verb **`assignment.re_invited`** ([`AuditAction::AssignmentReInvited`](../../apps/api/app/Modules/Audit/Enums/AuditAction.php) — divergence #3: a new verb, not a reused `assignment.invited`, so the re-offer is legible in the trail). `assertSource()` is fail-closed: `accepted → invited` throws (tested). The re-invite endpoint is the verb-on-existing shape `POST .../assignments/{assignment}/reinvite` (correction #4 / divergence #4).

### The auto-block listener (Sprint 5's deferred hook fires — D-11)

[`CreateAssignmentAvailabilityBlock`](../../apps/api/app/Modules/Campaigns/Listeners/CreateAssignmentAvailabilityBlock.php) is the **first consumer** of `AssignmentTransitioned` (Chunk 1 emitted it with zero listeners), registered in [`CampaignsServiceProvider`](../../apps/api/app/Modules/Campaigns/CampaignsServiceProvider.php). It acts **only** on `action === AssignmentAccepted` and creates a **`BlockType::Hard` / `Kind::AssignmentAuto`** block linked via `assignment_id`, spanning the campaign's **posting window** (`posting_window_starts_at/ends_at`), falling back to the campaign run dates (`starts_at/ends_at`). **Divergence #7:** if **both** pairs are null the block is **skipped + logged** (the conflict can't be dated).

### The `assignment_id` FK (correction #2 + D-12)

[`2026_06_05_100002_add_assignment_fk_to_creator_availability_blocks_table`](../../apps/api/database/migrations/2026_06_05_100002_add_assignment_fk_to_creator_availability_blocks_table.php) adds the constraint that Sprint 5 couldn't (no `campaign_assignments` table then) and Chunk 1 didn't: `creator_availability_blocks.assignment_id → campaign_assignments.id` **`ON DELETE SET NULL`**. Deleting an assignment nulls the block's link rather than cascade-deleting the block (the column's original documented intent — the block is the creator's calendar fact). Reconciled in `03-DATA-MODEL.md`.

### Authz (D-6) — invite is _executing_ a campaign, broader than _creating_ one

[`CampaignPolicy::invite()`](../../apps/api/app/Modules/Campaigns/Policies/CampaignPolicy.php) = **admin + manager + STAFF** (execute), resolving the deferred staff-execute question: inviting creators IS executing a campaign (distinct from `create`, which is admin/manager). A non-member gets **404** (tenancy invisibility via the `tenancy.agency` middleware, not 403 — the convention that the agency's existence isn't leaked to outsiders).

### FormRequests (D-8)

- [`InviteAssignmentRequest`](../../apps/api/app/Modules/Campaigns/Http/Requests/InviteAssignmentRequest.php) — `creator_id` exists, `agreed_fee_minor_units` positive int, `agreed_fee_currency` ISO-3 matching `campaign.budget_currency` **when set**, `acknowledged` bool.
- [`ReinviteAssignmentRequest`](../../apps/api/app/Modules/Campaigns/Http/Requests/ReinviteAssignmentRequest.php) — fee + currency only (no `creator_id`).
- [`CounterAssignmentRequest`](../../apps/api/app/Modules/Creators/Http/Requests/CounterAssignmentRequest.php) — `countered_fee_*`, currency resolved from the assignment's campaign.

**Divergence #1 (naming):** the columns are `*_minor_units` + `*_currency` (the real Chunk-1 columns), not the kickoff's `agreed_fee_amount`. **Divergence #2 (nullable currency):** `budget_currency` is nullable; the rule matches it only when set, accepts any ISO-3 when null. Both the budget-vs-fee deferral and the null-currency edge are logged to tech-debt.

### Creator-self endpoints (D-9)

[`CreatorAssignmentController`](../../apps/api/app/Modules/Creators/Http/Controllers/CreatorAssignmentController.php) clones the `CreatorConnectionRequestController` pattern exactly: resolves by `$request->user()->creator` + the assignment ULID, **`withoutGlobalScope(BelongsToAgencyScope)`** (the caller is a creator who may carry invitations from many agencies — ambient tenant context must not narrow the set), **404 on a non-owned ULID** (structural ownership), and **fail-closed 422** unless `status === Invited`. `accept`/`decline`/`counter` all go through the machine. A **fourth** route — `GET creators/me/assignments` (list, **divergence #5**) — feeds the dedicated surface. All four routes are allowlisted in `tenancy.md §4` + the routes-file header.

---

## Frontend

- **api-client types** ([`campaign.ts`](../../packages/api-client/src/types/campaign.ts)): invite/reinvite payloads, the 409 conflict payload, creator assignment list/action resources, counter payload.
- **api wrappers:** [`campaigns.api.ts`](../../apps/main/src/modules/campaigns/api/campaigns.api.ts) gains `invite()` + `reinvite()`; new [`creators/assignments.api.ts`](../../apps/main/src/modules/creators/assignments.api.ts) (`list/accept/decline/counter`).
- **Agency invite picker + gate UX** ([`InviteCreatorsDialog.vue`](../../apps/main/src/modules/campaigns/components/InviteCreatorsDialog.vue)) opened from the Creators tab in [`CampaignDetailPage.vue`](../../apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue): multi-select (mirrors `AddCreatorsToPoolDialog`), **hard-blacklisted rows excluded/disabled** (the 422 is never reached for them — defence in depth, not the only guard), and an **availability-warning modal** that re-submits the conflicted creators with `acknowledged: true`. **Divergence #6:** bulk D-5 is an FE submit-loop collecting per-creator 409s, then a single acknowledge re-prompt — no batch/preview endpoint. Gate visibility on a `canInvite` (admin+manager+staff).
- **Creator surface (D-10)** — a **dedicated route** `/creator/assignments` ([`CreatorAssignmentsPage.vue`](../../apps/main/src/modules/creators/pages/CreatorAssignmentsPage.vue)), not a dashboard section (the content is genuinely heavier: fee, campaign, window, counter fee-form). Only `invited` rows are actionable (fail-closed UI mirroring the server). The counter fee-form binds per-field 422 via `extractFieldErrors` (added to the form-error allowlist arch test). Nav item in [`CreatorDashboardLayout.vue`](../../apps/main/src/modules/creators/layouts/CreatorDashboardLayout.vue) + a count teaser on the approved [`CreatorDashboardPage.vue`](../../apps/main/src/modules/creators/pages/CreatorDashboardPage.vue).
- **Auto-block label (D-13):** `kind=assignment_auto` relabelled "From campaign" across `availability.json` (en/pt/it).
- **i18n (en/pt/it):** `app.campaigns.invite.*`, `creator.ui.assignments.*`, `availability.creatorNav.assignments`.

---

## Tests

**Backend (30 passing, 85 assertions):**

- **Gate** ([`CampaignAssignmentInviteTest`](../../apps/api/tests/Feature/Modules/Campaigns/CampaignAssignmentInviteTest.php)): agency-wide hard → 422; **brand-scoped hard for this campaign's brand → 422** (the deferred-promise **break-revert** — drop the brand predicate and the invite wrongly succeeds); brand-scoped for a _different_ brand → succeeds; soft (either) → succeeds; hard availability → 409, re-submit `acknowledged` → succeeds; soft availability → no warn; fee currency/positivity validation; idempotency; authz (admin/manager/staff invite, non-member 404, unauth 401); reinvite legal + illegal-source fail-closed.
- **Auto-block** ([`AssignmentAutoBlockTest`](../../apps/api/tests/Feature/Modules/Campaigns/AssignmentAutoBlockTest.php)): accept creates `Hard`/`AssignmentAuto` linked to `assignment_id` over the posting window; campaign-dates fallback; skip-when-undateable; no block on a non-accept transition; **FK `SET NULL`** on assignment hard-delete.
- **Creator-self** ([`CreatorAssignmentTest`](../../apps/api/tests/Feature/Modules/Creators/CreatorAssignmentTest.php)): list own assignments across agencies (scope bypass); accept/decline/counter via the machine; counter records `countered_fee_*` not `agreed_fee_*`; currency mismatch 422; **non-owned ULID 404**; **fail-closed** unless `Invited`; unauth 401.

**Frontend (full suite green, 817 tests):** `campaigns.api` invite/reinvite + `creators/assignments.api` wrapper specs; `CreatorAssignmentsPage` (rows + status chips, fail-closed action visibility, accept-then-refetch, empty state); `CreatorDashboardLayout` Invitations nav (+ pt/it localization); `CreatorDashboardPage` + `CampaignDetailPage` regressions absorbed (assignments-api mocked, route registered). **Arch-test growth:** the counter fee-form added to the [form-error-pattern allowlist](../../apps/main/tests/unit/architecture/form-error-pattern.spec.ts).

**Static analysis:** Pint clean, PHPStan no errors on `Campaigns` + `Creators`.

---

## Divergences from the kickoff (all flagged at plan time, restated for the record)

1. **Fee columns** are `*_minor_units` + `*_currency` (real Chunk-1 columns), not `agreed_fee_amount`. Naming-only.
2. **`budget_currency` is nullable** — fee currency matches it when set, any ISO-3 when null. Logged to tech-debt.
3. **Re-invite verb** is the distinct `assignment.re_invited`, not a reused `assignment.invited`.
4. **Endpoint shapes:** single invite = create under the campaign; re-invite = verb-on-existing. No `/invitations` alias.
5. **Creator-self LIST** (`GET creators/me/assignments`) added as a fourth route to feed D-10's surface; allowlisted.
6. **Bulk availability** is an FE submit-loop + aggregate acknowledge; no batch/preview endpoint.
7. **Posting-window fallback** to campaign dates; skip + log when both pairs are null.

## Tech-debt touched

- **Closed:** "Brand-scoped blacklist is recorded-now, enforced-at-S8" — now enforced by the gate's hard predicate (break-revert pinned).
- **Logged (new):** assignment fee NOT validated against campaign budget (per-field shape only, D-8); nullable `budget_currency` weakens the currency check (divergence #2).

## Delivery

Two-commit pair (backend / FE), committed after the spot-check passed. Docs reconciled: `tenancy.md §4` (four `creators/me/assignments/*` rows), `tech-debt.md` (one closed + two new), `03-DATA-MODEL.md` (the `assignment_id` FK callout + the `reinvite()` edge in the state-machine diagram).
