# Sprint 6.6b — Review: Two-sided connection lifecycle (send → accept/decline)

**Status:** Closed. Spot-check passed (no PMC) — every anchor holds. The load-bearing one is the **D-5 `is_connected` fix**: done at the _contract_ level (both resources drop the boolean and emit raw `relationship_status`, the FE derives three states via `deriveConnectionState`, and "View in roster" keys on `roster` specifically) — not a patched boolean, so a `declined` creator cannot render "connected" (break-revert holds). The state machine is fully fail-closed with typed `meta.code` outcomes: accept/decline reject a non-`pending_request` relation (422 `not_pending`), send distinguishes 201 / re-request-flip / no-op, and `declined → pending_request` **flips** the status (not the silent-no-op trap). The roster default-exclude lives only in the no-`?status=` branch, so the chips still filter to pending/declined (both halves asserted). Creator-self ownership is structural (resolved from `$request->user()->creator`; a non-owned ULID → 404); send reuses the discovery gate (no relation against an undiscoverable creator); the enum-catalogue tripwire (D-4) now exists; the mailable is best-effort (a mail failure cannot 500 a succeeded write).

**Reviewer:** drafted by Cursor (build pass); spot-checked + closed.

**Reviewed against:** the Sprint-6.6b kickoff (locked decisions D-1…D-11, the honest-deviation triggers, the spot-check anchors) + the focused 6.6b read-pass inventory (the 21-consumer enum ripple map + the locked state machine) + the Sprint-6.6a review (the `is_connected` boolean this chunk is required to fix) + `PROJECT-WORKFLOW.md` § 5 standards (5.17 defense-in-depth, 5.35 break-revert claim verification, 5.36 asymmetric-coverage acknowledgement) + the precedents the kickoff named (the `SignUpService` `!== Prospect` fail-closed guard, the bulk-invite `already_invited` idempotency precedent, the documented `/me/assignments/{assignment}/accept|decline` shape, `CreatorApprovedMail`, the `AuditActionEnumTest` catalogue pattern, the `DashboardSummaryController` cross-module read).

**The heart of the marketplace.** 6.6b is the **two-sided lifecycle**: the agency **sends** a connection request → the creator **accepts** or **declines**. This chunk adds the two new statuses, the write paths (both directions), the agency send-action UI, and the creator accept/decline **endpoints**. The creator-side **UI** (the requests inbox) is **6.6c** — the endpoints land here. The state machine was a **locked input**, not an open question, and shipped exactly as specified.

---

## What shipped

### D-1 / D-2 — the two new statuses + the fail-closed state machine

- **`RelationshipStatus` grows two cases** (`apps/api/app/Modules/Creators/Enums/RelationshipStatus.php`): `PendingRequest = 'pending_request'` (agency-initiated discovery request, creator not yet accepted — **no** magic-link token/expiry, the distinguishing feature vs `prospect`) and `Declined = 'declined'` (terminal "creator declined", the row **retained** to occupy the `unique_agency_creator` pair so the agency can re-request). The docblock documents the non-overlapping meanings of all five cases.
- **`isPendingRequest()` model helper** on `AgencyCreatorRelation` (mirrors `isProspect()`) — the fail-closed predicate the accept/decline guard reads.
- **The full transition table, every edge fail-closed-guarded** (mirroring `SignUpService`'s `!== Prospect`):
  - `(none) → pending_request` — agency sends (W1, net-new).
  - `declined → pending_request` — agency **re-requests** (explicit re-engagement, D-4 — the status flips, NOT a silent no-op).
  - `pending_request → roster` — creator accepts (W2).
  - `pending_request → declined` — creator declines (W2; row retained).
  - `prospect → roster` — magic-link signup accept (existing, **untouched**).
  - Accept/decline reject unless the relation is **exactly** `pending_request` (a `roster`/`declined`/`prospect`/`external` row → 422 `connection.not_pending`). Send rejects `pending_request` (already asked) and `roster`/`prospect`/`external` (a real relation exists) as **idempotent no-ops surfacing the existing state**, never a duplicate row or a second mail.

### D-3 / D-4 — the enum-add ripple (the 21-consumer map, worked end-to-end) + the catalogue tripwire

The inventory's full consumer list was worked; the deliberate-change consumers (a missed one ships a bug):

- **The enum + docblock** (above).
- **The FE type union** — `RosterRelationshipStatus` (`packages/api-client/src/types/agency.ts`) grew `'pending_request' | 'declined'`. This is the load-bearing FE consumer (`DiscoveryRelationshipStatus` aliases it); without it the FE silently mistypes the two new values.
- **i18n status labels** in **en / pt / it** — `app.roster.status.pending_request` + `.declined` (every chip does `t('app.roster.status.${status}')`; a missing key renders the raw string).
- **Factory states** — `pendingRequest()` + `declined()` on `AgencyCreatorRelationFactory` (no token/expiry for `pending_request`).
- **The status-asserting tests** — `AgencyCreatorRosterTest` + `AgencyCreatorDiscoveryTest` updated.
- **D-4 — `RelationshipStatusEnumTest` added** (`tests/Feature/Modules/Creators/`). There was **no** enum-catalogue test before (unlike `AuditActionEnumTest`), so the enum had no tripwire. The new catalogue pins the exact 5-case set + the backing string values, so this add — and every future one — is a deliberate, test-gated change.

### D-5 — ⚠ the `is_connected` contract fix (the load-bearing one)

6.6a computed `is_connected = (relationship_status !== null)` — correct when every status meant "a real relationship," but `pending_request` and `declined` **break it**: a declined creator would render "connected" with a "View in roster" button. The fix:

- **Both discovery resources drop the `is_connected` boolean** (`CreatorDiscoveryResource` + `CreatorPublicProfileResource`) and emit the raw `relationship_status` only (which they already carried).
- **The FE derives three states** via `deriveConnectionState(status)` (`agency.ts`): `roster → 'connected'`, `pending_request → 'pending'`, `declined → 'declined'`, everything else → `'none'`. **The "View in roster" affordance keys on `roster` specifically, NOT "has any relation"** — so a `declined` creator can never render "connected"/"view in roster" (the break-revert: revert to the boolean → declined shows connected → fail).

### D-6 — roster default-exclude, but filterable

`AgencyCreatorController::applyStatusFilter` (`apps/api/.../Agencies/Http/Controllers/`) is now a **default-when-unfiltered, explicit-when-filtered** rule that satisfies both halves:

- **No `?status=`** → the default real-relationship set: `whereNotIn(pending_request, declined)` (the roster is "my working relationships," not a request inbox).
- **`?status=pending_request`** (or `declined`) → returns **exactly** that status (the "show my pending requests" / "who declined me" chips).

The exclusion is **NOT** an unconditional `whereNotIn` — that branch only runs when no explicit status was requested, so the chip still works. An unknown value → `whereRaw('1 = 0')` (empty page; the SPA only sends valid chips). The two chips were added to the FE `statusFilterItems` (`CreatorRosterPage.vue`) + the `app.roster.filters.status.*` i18n keys (en/pt/it).

### D-7 — agency send-request: a new `AgencyConnectionRequestController`

A new controller (NOT an action on the read-only discovery controller — send is a stateful write with its own policy/audit/mailable), in the `agencies/{agency}/...` stack:

- **Route:** `POST agencies/{agency}/creators/discover/{creator}/connection-request` (`agencies.creators.discover.connection-request`), in the house `auth:web → tenancy.agency → tenancy` stack (a non-member 404s before the action runs).
- **Authz: admin/manager** via a new `sendRequest` ability on `AgencyCreatorRelationPolicy` (staff → 403).
- **The same fail-closed discoverable gate as the discovery reads** — `approved + is_discoverable` (+ the implicit SoftDeletes scope); a non-discoverable / non-approved creator 404s, so a relation can never be opened against a creator the agency could not have discovered.
- **Creates the relation in `pending_request`** with `invited_by_user_id` + `invitation_sent_at` + `notification_sent_at`, **NO** magic-link token/expiry. Net-new → 201 `connection.requested`; `declined → pending_request` re-request → 200 `connection.re_requested` (the status flips); `pending_request` → 200 `connection.already_requested` (no-op); `roster`/`prospect`/`external` → 200 `connection.already_connected` (no-op). The transition is wrapped in a `DB::transaction`.
- **Audit:** the create / status-transition is captured by the `Audited` trait's auto `agency_creator_relation.created` / `.updated` rows (`relationship_status` is on the allowlist), so the lifecycle is auditable without a dedicated verb.

### D-8 — creator accept/decline + list: the `creators/me/*` pattern

A new `CreatorConnectionRequestController` (Creators module), mirroring the documented `/me/assignments/{assignment}/accept|decline` shape:

- `GET creators/me/connection-requests` — the creator's own `pending_request` relations (6.6c's UI needs it; the endpoint is here), with `agency` eager-loaded (id/ulid/name), newest-first.
- `POST creators/me/connection-requests/{relation}/accept` → `roster`; `POST …/{relation}/decline` → `declined`.
- **Ownership is structural** — every relation is resolved from `$request->user()->creator` by `creator_id`, **never** a path agency id, so one creator can never accept another's request (a non-owned ULID is simply 404). **Fail-closed (D-2):** accept/decline reject unless the relation is exactly `pending_request` (422 `connection.not_pending`).
- **Cross-module read** (honest-deviation note, surfaced — see below): these live in Creators but read/write the Agencies `AgencyCreatorRelation`, the same shape as `DashboardSummaryController`. The `BelongsToAgencyScope` is deliberately dropped (the one justified HTTP bypass, mirroring the discovery controller) — the caller is a creator who may have requests from many agencies; an ambient tenant context would otherwise hide all but one agency's requests. The **`docs/security/tenancy.md` § 4 allowlist** gained all three routes with this rationale.

### D-9 — email the creator on send-request only

A queued `ConnectionRequestMail` (`apps/api/app/Modules/Agencies/Mail/`, + the `mail.agencies.connection-request` markdown view, + `creators.connection_request.*` lang keys in en/pt/it). It mirrors `CreatorApprovedMail` — `ShouldQueue`, `Mail::locale()` to the creator's `preferred_language` (defaulting to `en`), the catalyst markdown theme. Queued on net-new send **and** on `declined → pending_request` re-request; **never** on a no-op. The send guards defensively against a missing creator user/email so a mail failure never 500s a write that already succeeded. **Agency-notified-on-accept/decline is DEFERRED** (logged in `tech-debt.md` — the agency learns the outcome pull-style via the discovery annotation's status flip; a real notification subsystem stays deferred).

### Frontend (agency send-action only; creator UI is 6.6c) — D-10 / D-11

- **D-10 — the status-driven "Send connection request" button** on the `DiscoverProfilePage` header (admin/manager-gated, copying the `canEdit` role pattern): not-connected → "Send request" → the W1 endpoint via `discovery.api.ts`'s new `sendConnectionRequest`; `pending` → "Request pending" (disabled/info); `roster` → "View in roster" (the existing link, unchanged); `declined` → "Declined" + an explicit **"Request again"** action (D-4's re-request — deliberate, not hidden). Loading + snackbar (success/already-state/error) states are wired off the response `meta.code`.
- **D-11 — the three annotation states** ("Connected" / "Request pending" / "Declined" / "Not connected") render on both the discovery card grid (`DiscoverPage.vue`) and the profile, replacing the boolean — all derived through `deriveConnectionState`. The roster filter chips (D-6) gained the two states.
- **api-client + i18n:** `RosterRelationshipStatus` union grown, `is_connected` removed from `DiscoveryCreatorListItem` + `CreatorPublicProfile`, new `DiscoveryConnectionState` type + `deriveConnectionState` helper + `ConnectionRequestResponse`; `app.discover.connection.*` keys (states, button labels, toast messages) in en/pt/it.

---

## Honest-deviation triggers (status)

None fired into a deviation; the two flagged-for-surfacing items resolved cleanly:

1. **The 21-consumer enum-add (D-3) was transparent or explicitly handled everywhere.** No consumer hard-coded the status set in a `match` without a default that the inventory missed; the deliberate-change consumers (FE union, i18n, factory, tests) were all worked, and the new catalogue test (D-4) is the standing tripwire.
2. **The default-exclude-but-filterable roster logic (D-6) holds both halves** without an unconditional exclusion — the `whereNotIn` lives in the no-`?status=` branch only, so the pending/declined chips filter to exactly those rows (asserted both ways).
3. **The `creators/me/*` placement of accept/decline (D-8) hit no tenancy snag** reading the Agencies model — the cross-module read is the documented `DashboardSummaryController` shape, and the `BelongsToAgencyScope` bypass is the same justified one the discovery controller uses (now allowlisted in `tenancy.md`).
4. **No creator-side requests UI was built** (6.6c) — only the endpoints.

---

## Coverage (§5.17; break-revert §5.35)

| Area                                            | Coverage                                                                                                                                                                                                                                                                                                                                                                                                                  |
| ----------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **The `is_connected` fix (D-5 — load-bearing)** | A `declined` creator does **NOT** render "connected"/"view in roster"; `pending_request` renders "pending"; `roster` renders "connected". Asserted on both the card grid (`DiscoverPage.spec.ts`) and the profile (`DiscoverProfilePage.spec.ts`) via the derived three-state annotation. **Break-revert:** revert to the boolean → declined shows connected → fail.                                                      |
| **State machine (D-1/D-2)**                     | Each transition works — send → `pending_request` (201), accept → `roster`, decline → `declined`, re-request `declined` → `pending_request`. Each guard is fail-closed — accept/decline a non-`pending_request` relation → 422 `connection.not_pending`; send to a `roster`/`prospect`/`external`/`pending_request` relation → no-op surfacing the existing state, NOT a duplicate or transition. Break-revert each guard. |
| **Re-request after decline (D-4)**              | `declined → pending_request` works as an **explicit** re-request — the persisted status is asserted to have flipped (break-revert: it must NOT be silently swallowed as a no-op). The re-request also queues the mail.                                                                                                                                                                                                    |
| **Roster default-exclude + filterable (D-6)**   | The default index (no `?status=`) excludes `pending_request`/`declined`; `?status=pending_request` returns **exactly** those (break-revert: an unconditional `whereNotIn` would break the chip — asserted that filtering BY `pending_request` returns them).                                                                                                                                                              |
| **Enum ripple (D-3/D-4)**                       | `RelationshipStatusEnumTest` pins the 5-case set + values; the FE union + i18n keys cover both new values (no raw-string render — the chip/annotation specs assert the localized label).                                                                                                                                                                                                                                  |
| **Authz (D-7)**                                 | admin/manager can send; **staff → 403**; non-member → 404 (tenancy invisibility); unauthenticated → 401.                                                                                                                                                                                                                                                                                                                  |
| **Idempotency (D-7)**                           | re-send to a `pending_request` relation → 200 `connection.already_requested`, no duplicate row, **no second mail**; to a `roster` relation → 200 `connection.already_connected`, no mail.                                                                                                                                                                                                                                 |
| **Email (D-9)**                                 | send queues `ConnectionRequestMail` to the creator in their locale (asserted `hasTo` + `locale === 'pt'` + `agencyName`); a no-op queues nothing.                                                                                                                                                                                                                                                                         |
| **Accept/decline endpoints (D-8)**              | creator-self-scoped — resolved from `$request->user()->creator`; a creator cannot accept another creator's request (non-owned ULID → 404); `GET …/connection-requests` returns the creator's OWN pending requests only (a roster/other-creator row is excluded), newest-first.                                                                                                                                            |
| **FE — send button + annotations**              | `DiscoverProfilePage.spec.ts` — the status-driven button across `none` (admin: "Send request" → calls the API), `pending` ("Request pending", disabled), `roster` ("View in roster" link), `declined` ("Declined" + "Request again"); staff sees no send affordance. `DiscoverPage.spec.ts` — the three annotation states per card. `discovery.api.spec.ts` — `sendConnectionRequest` targets the W1 URL.                 |

**Test runs (local):**

- **Backend (sprint-specific):** `RelationshipStatusEnumTest` + `CreatorConnectionRequestTest` + `AgencyConnectionRequestTest` + `AgencyCreatorRosterTest` + `AgencyCreatorDiscoveryTest` — **79 passed (233 assertions)**, 1 pre-existing skip. The broader `tests/Feature/Modules/Agencies` suite green (**183 passed, 1 skip**) — confirms the enum-add + roster-filter change left the rest of the module unchanged. PHPStan: **clean**. Pint: **clean**.
- **Frontend:** full Vitest suite **753 passed (86 files)** — incl. the updated discover specs + the api-client send-request spec. `vue-tsc --noEmit`: clean. ESLint on the discover + roster modules: clean.

> **Note (environment, not a failure):** running the _entire_ `tests/Feature/Modules/Creators` suite in one process OOMs at the very end inside `intervention/image`'s GD decoder — PHP's default `memory_limit=128M` is exhausted by the accumulated image-processing tests (the `-d memory_limit` flag does not propagate through `artisan test`'s subprocess). This is the long-standing big-suite memory ceiling already logged in `tech-debt.md` ("Full API test suite needs `memory_limit=2G`"), unrelated to this chunk — every sprint-relevant test passes when run directly via `vendor/bin/pest`.

---

## Spot-check anchors

- **The `is_connected` three-state fix (D-5 — load-bearing, break-revert):** a `declined` creator is NOT "connected"; `pending_request` shows "pending", `roster` shows "connected". ✓
- **Each state-machine transition + its fail-closed guard (break-revert each):** send→pending, accept→roster, decline→declined; accept a non-pending relation → 422; send to a roster relation → no-op surfacing state. ✓
- **Re-request `declined → pending_request` is explicit, NOT a silent no-op (break-revert: the status flips).** ✓
- **The roster default excludes pending/declined BUT the chips filter to them (break-revert both halves).** ✓
- **The enum-catalogue test pins the 5-case set.** ✓
- **Send-request gated admin/manager (staff 403).** ✓
- **Accept/decline are creator-self-scoped** (resolved from `$request->user()->creator`). ✓
- **`ConnectionRequestMail` queues to the creator's locale.** ✓
- **The tenancy allowlist entry was added** for the `creators/me/connection-requests/*` routes. ✓

---

## Out of scope (logged at close)

- **The creator-side requests UI** (the inbox where the creator sees + accepts/declines) → **6.6c** (the endpoints landed here).
- **Agency-notified-on-accept/decline** + a real notification subsystem → **deferred** (logged in `tech-debt.md`; the agency learns the outcome pull-style via the discovery annotation's status flip).
- The `is_discoverable` self-serve opt-out write path → still future (the column is future-proofing only, per 6.6a).

---

## Files touched

**Backend**

- `apps/api/app/Modules/Creators/Enums/RelationshipStatus.php` — `PendingRequest` + `Declined` + docblock (D-1).
- `apps/api/app/Modules/Agencies/Models/AgencyCreatorRelation.php` — `isPendingRequest()` + `agency()` relation.
- `apps/api/app/Modules/Agencies/Database/Factories/AgencyCreatorRelationFactory.php` — `pendingRequest()` + `declined()` states (D-3).
- `apps/api/app/Modules/Agencies/Policies/AgencyCreatorRelationPolicy.php` — `sendRequest` ability (D-7).
- `apps/api/app/Modules/Agencies/Http/Controllers/AgencyConnectionRequestController.php` — new (the W1 send path + state machine + idempotency + mail) (D-7/D-9).
- `apps/api/app/Modules/Agencies/Mail/ConnectionRequestMail.php` — new (queued, localized) (D-9).
- `apps/api/resources/views/mail/agencies/connection-request.blade.php` — new (markdown view) (D-9).
- `apps/api/lang/{en,pt,it}/creators.php` — `connection_request.*` mail keys (D-9).
- `apps/api/app/Modules/Creators/Http/Controllers/CreatorConnectionRequestController.php` — new (list/accept/decline, creator-self-scoped) (D-8).
- `apps/api/app/Modules/Creators/Routes/api.php` — `creators/me/connection-requests` group + comment block (D-8).
- `apps/api/app/Modules/Agencies/Routes/api.php` — the `connection-request` POST route (D-7).
- `apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php` — default-exclude-but-filterable status logic (D-6).
- `apps/api/app/Modules/Agencies/Http/Resources/CreatorDiscoveryResource.php` — dropped `is_connected`, emits `relationship_status` (D-5).
- `apps/api/app/Modules/Agencies/Http/Resources/CreatorPublicProfileResource.php` — same (D-5).
- `apps/api/tests/Feature/Modules/Creators/RelationshipStatusEnumTest.php` — new catalogue (D-4).
- `apps/api/tests/Feature/Modules/Agencies/AgencyConnectionRequestTest.php` — new (D-7/D-9).
- `apps/api/tests/Feature/Modules/Creators/CreatorConnectionRequestTest.php` — new (D-8).
- `apps/api/tests/Feature/Modules/Agencies/AgencyCreatorRosterTest.php` — D-6 default-exclude + filter coverage.
- `apps/api/tests/Feature/Modules/Agencies/AgencyCreatorDiscoveryTest.php` — `is_connected` removal + `relationship_status` annotation coverage (D-5).

**Frontend**

- `packages/api-client/src/types/agency.ts` — union grown, `is_connected` removed, `DiscoveryConnectionState` + `deriveConnectionState` + `ConnectionRequestResponse` (D-3/D-5).
- `apps/main/src/modules/discover/api/discovery.api.ts` — `sendConnectionRequest` (D-10).
- `apps/main/src/modules/discover/pages/DiscoverProfilePage.vue` — the status-driven send button + 3 annotation states (D-10/D-11).
- `apps/main/src/modules/discover/pages/DiscoverPage.vue` — the 3 annotation states on the card grid (D-11).
- `apps/main/src/modules/roster/pages/CreatorRosterPage.vue` — the pending/declined filter chips (D-6).
- `apps/main/src/core/i18n/locales/{en,pt,it}/app.json` — `app.discover.connection.*` + `app.roster.{status,filters.status}.*` keys (D-3/D-6/D-10/D-11).
- `apps/main/src/modules/discover/pages/DiscoverProfilePage.spec.ts` — send button states + annotation states.
- `apps/main/src/modules/discover/pages/DiscoverPage.spec.ts` — annotation states.
- `apps/main/src/modules/discover/api/discovery.api.spec.ts` — `sendConnectionRequest` URL.

**Docs**

- `docs/security/tenancy.md` — § 4 allowlist: the three `creators/me/connection-requests/*` routes (D-8).
- `docs/tech-debt.md` — the deferred agency-notified-on-response + the still-deferred notification subsystem (D-9).
- `docs/reviews/sprint-6-6b-review.md` — this review.
