# Sprint 7 — Blacklisting Review

**Status:** Closed. Spot-check passed (no post-merge corrections) — Part B verified where the risk was (B1's calling-agency `whereNotExists` scoping = the privacy invariant; B4's cross-agency reason isolation; B2's typed-422 hard-send gate + orthogonal-flag reverse case; B3's agency-wide-only counts), Part A's two non-mirrored write paths + partial-unique re-blacklist history + restricted no-relation case + default-OFF generic notification confirmed. All five plan-pause calls landed as decided. `03-DATA-MODEL.md §6` reconciled to the as-built schema before merge.

**Reviewer:** drafted by Cursor (single-chunk build pass); spot-checked + closed. **The spot-check was weighted on Part B** (the load-bearing, net-new marketplace integration).

**Reviewed against:** the Sprint-7 kickoff (the seven locked decisions D-1…D-7, Part A A1–A7, Part B B1–B4) + the plan-pause confirmations (schema, uniqueness, no-relation handling, B2 reverse-case, notification default) + `03-DATA-MODEL.md §6` (the `brand_creator_blacklists` spec table) + `PROJECT-WORKFLOW.md` §5 standards (5.17 coverage, 5.32 decision-reinterpretation, 5.35 break-revert verification) + `docs/security/tenancy.md` §3/§4.

> **The build has two distinct halves and is sectioned accordingly.** **Part A** is mechanical wiring on already-shipped relation columns plus one new table — low design risk. **Part B** is the net-new discovery/request/count integration where the cross-agency-isolation design risk sits — **review this separately and first.**

---

## The two write paths (D-2 — no dual-write), confirmed against the code

| Scope                              | Source of truth                                                                                                                                                        | Write                                                                                                   | Un-write                                         |
| ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------- | ------------------------------------------------ |
| **agency-wide** (`scope='agency'`) | the **relation** columns (`agency_creator_relations.is_blacklisted` + `blacklist_scope`/`blacklist_type`/`blacklist_reason`/`blacklisted_at`/`blacklisted_by_user_id`) | set the columns; **requires the relation to exist** (typed 422 `blacklist.relation_required` when none) | clear the columns                                |
| **brand-scoped** (`scope='brand'`) | a **`brand_creator_blacklists` row**, keyed `(brand_id, creator_id)`, **no relation FK**                                                                               | insert a row; **never touches the relation**                                                            | **soft-delete** the row (preserves history, D-3) |

The relation's `blacklist_scope` is therefore **only ever `'agency'`** — a brand-scoped blacklist does NOT flip `blacklist_scope='brand'` on the relation (D-2 verified: `CreatorBlacklistTest` "creates a row and does NOT touch the relation"). The brand→agency link derives through `brands.agency_id`; the blacklist table carries no `agency_id` by design.

---

## ⚠ Part B — the load-bearing integration (REVIEW THIS SEPARATELY)

### B1 — Discovery exclusion (calling-agency-scoped, hard-only)

`AgencyCreatorDiscoveryController::index` gains a `whereNotExists` against `agency_creator_relations` **scoped to the calling agency** + `is_blacklisted=true` + `blacklist_type='hard'`:

```php
private function excludeHardBlacklisted(Builder $query, Agency $agency): void
{
    $query->whereNotExists(function ($sub) use ($agency): void {
        $sub->from('agency_creator_relations')
            ->whereColumn('agency_creator_relations.creator_id', 'creators.id')
            ->where('agency_creator_relations.agency_id', $agency->id)
            ->where('agency_creator_relations.is_blacklisted', true)
            ->where('agency_creator_relations.blacklist_type', BlacklistType::Hard->value);
    });
}
```

Because the pool is global but the predicate is **calling-agency-scoped**, agency A's hard blacklist removes the creator from **A's discovery only — B still sees them** (the same per-agency isolation the 6.6a annotation subquery embodies). **soft does NOT exclude** (D-1). **brand-scoped does NOT affect discovery** — discovery is agency-level (no brand context); brand-scoped exclusion bites at campaign-matching (Sprint 8 — see tech-debt). **This is the load-bearing one — the `agency_id` scoping IS the privacy invariant, not a style choice.**

### B2 — Connection-request gate (hard blocks send)

`AgencyConnectionRequestController::store` gains a guard **before** the state machine: a `hard` agency-wide blacklist on the relation **blocks the send** with a typed 422 (`meta.code = connection.blacklisted`). `soft` does NOT block (warn-only). **B2 reverse case (confirmed at plan-pause — orthogonal flag):** blacklisting a creator already in `pending_request`/`roster` sets the blacklist columns **without changing `relationship_status`** — the existing relation persists, flagged; hard then excludes from discovery + future sends.

### B3 — Scope-aware KPI counts (D-3)

`DashboardSummaryController`'s `creators_in_roster` + `pending_creator_applications` replace the flat `is_blacklisted=false` with a scope-aware predicate:

```php
private function agencyWideBlacklisted(): \Closure
{
    return function ($query): void {
        $query->where('is_blacklisted', true)
            ->where('blacklist_scope', BlacklistScope::Agency->value);
    };
}
// …->whereNot($this->agencyWideBlacklisted())
```

Only an **agency-wide** blacklist drops a creator from the count; a **brand-scoped** one (which never touches the relation) does NOT. This closes the Sprint-4 tech-debt entry. The roster row + 2a detail stay **boolean-emit** (display surfaces — a blacklisted creator stays listed, flagged).

### B4 — Cross-agency isolation of `blacklist_reason` (the privacy pin — tested)

`blacklist_reason` is the same data class as `internal_notes` (free-text, per-agency, GDPR-sensitive). It is absent from `CreatorDiscoveryResource` (carries no blacklist facts at all), withheld from `AgencyCreatorDetailResource` (only the **structured** facts ship — flag/scope/type/date, never the reason), and absent from the relation's audit allowlist (redacted by construction). The new write surface preserves all of this. `BlacklistEnforcementTest` pins: agency A hard-blacklists a shared creator with a reason → agency B's discovery + detail show **no blacklist facts and no reason**, and the creator is still discoverable by B.

---

## Part A — make blacklisting possible (mechanical wiring + one new table)

- **A1 — `brand_creator_blacklists` table + model.** Migration per the kickoff A1 schema (see honest-deviation #1): `id`, `ulid`, `brand_id` (FK cascade), `creator_id` (FK cascade), `blacklist_type` (the shared enum), `reason` (text, **NOT NULL**), `blacklisted_at`, `blacklisted_by_user_id` (FK nullOnDelete), `notification_sent_at` (nullable), timestamps, **soft-deletes**, + a **partial unique index** `(brand_id, creator_id) WHERE deleted_at IS NULL` (re-blacklist after un-blacklist inserts a fresh history row — confirmed at plan-pause). `BrandCreatorBlacklist` uses the `Audited` + `SoftDeletes` traits; `reason` is excluded from the audit allowlist.
- **A2/A3 — the two write paths** via the dedicated `CreatorBlacklistController` (`store`/`destroy`), NOT the rating/notes PATCH (D-2 no dual-write).
- **A4 — FormRequests + enums + catalogue tests** (D-6/D-7): `BlacklistCreatorRequest` (mandatory `reason`, `scope`, `type`, `brand_id` required-with/prohibited-without brand scope) + `UnblacklistCreatorRequest`. `BlacklistScope` (`agency`/`brand`) + `BlacklistType` (`hard`/`soft`) are real PHP enums cast on the relation; `blacklist_type` is the **one** enum shared with the brand table. `BlacklistEnumsTest` pins the case sets + the varchar(8) width.
- **A5 — `creator.blacklisted` audit verb** (D-5): logged with actor + scope/type metadata, **reason redacted** (`CreatorBlacklistTest`: the secret reason appears in NO audit column). `BrandCreatorBlacklistCreated`/`Deleted` cases map the trait-emitted rows.
- **A6 — notification** (D-4): `blacklist_notification_policy` on `agencies.settings` (the first real consumer of that jsonb) + `CreatorBlacklistedMail` (queued, locale-aware, defensive on missing email, generic body — no reason leaked) + `notification_sent_at` stamp. **Default OFF** (confirmed at plan-pause).
- **A7 — FE**: `BlacklistCreatorDialog` (reason + scope + type + brand picker when scope=brand), admin/manager-gated (`canEdit`); the un-blacklist action; soft = warning badge, hard = error badge (the exclusion is Part B); the notification-policy toggle on the settings page.

---

## Acceptance criteria

| Part   | Criterion                                                                                                                                                                                | Status |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| **B1** | hard agency-wide blacklist gone from the **blacklisting** agency's discovery; **still present for another agency** (break-revert: un-scope the `whereNotExists` → vanishes for everyone) | ✅     |
| **B1** | soft does NOT exclude; brand-scoped does NOT affect discovery                                                                                                                            | ✅     |
| **B2** | hard blacklist blocks send (typed 422 `connection.blacklisted`); soft does not                                                                                                           | ✅     |
| **B3** | agency-wide blacklist drops the roster count; brand-scoped does NOT (break-revert: the old boolean would drop brand-scoped too)                                                          | ✅     |
| **B4** | A's blacklist + reason invisible to B (discovery + detail); creator still discoverable by B                                                                                              | ✅     |
| **A**  | hard/soft + agency/brand enums (catalogue tests pin the case sets)                                                                                                                       | ✅     |
| **A**  | brand-scoped write = a table row + **no relation touch** (D-2); agency-wide write = relation columns                                                                                     | ✅     |
| **A**  | mandatory reason (422 without); un-blacklist clears / soft-deletes                                                                                                                       | ✅     |
| **A**  | admin/manager gate (staff 403); the `creator.blacklisted` audit logs the event + redacts the reason                                                                                      | ✅     |
| **A**  | notification fires the mailable when policy=on (locale-aware), not when off                                                                                                              | ✅     |
| —      | all existing tests green; pint + phpstan clean                                                                                                                                           | ✅     |

---

## Verification results

| Gate                                       | Result                                                                                                                                                                                                                                               |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `apps/api` Pest — full suite               | **1186 passed, 1 skipped** (`php -d memory_limit=1G vendor/bin/pest`)                                                                                                                                                                                |
| `apps/api` Pest — blacklist scope          | **31 passed** (`CreatorBlacklistTest` + `BlacklistEnforcementTest` + `BlacklistEnumsTest`)                                                                                                                                                           |
| `apps/api` Pint                            | `passed` (touched files clean)                                                                                                                                                                                                                       |
| `apps/api` PHPStan                         | **No errors** (level per `phpstan.neon`)                                                                                                                                                                                                             |
| `apps/main` Vitest — full suite            | **772 passed** (87 files)                                                                                                                                                                                                                            |
| `apps/main` `vue-tsc --noEmit`             | 0 errors                                                                                                                                                                                                                                             |
| `apps/main` ESLint                         | 0 errors (2 pre-existing `v-html` warnings, untouched files)                                                                                                                                                                                         |
| `packages/api-client` `tsc --noEmit`       | 0 errors                                                                                                                                                                                                                                             |
| **Break-revert — B1 discovery un-scoping** | un-scope the `whereNotExists` (drop `agency_id`) → the shared creator wrongly vanishes from agency B too → the cross-agency-visibility assertion in `BlacklistEnforcementTest` fails → revert restores B's visibility (the load-bearing privacy pin) |

---

## Files touched

**Backend — enums + model + migration (Part A):**

- `app/Modules/Agencies/Enums/BlacklistScope.php` — **new** (`agency`/`brand`).
- `app/Modules/Agencies/Enums/BlacklistType.php` — **new** (`hard`/`soft`, the shared enum).
- `database/migrations/2026_06_04_100000_create_brand_creator_blacklists_table.php` — **new** (A1 schema + partial unique index).
- `app/Modules/Agencies/Models/BrandCreatorBlacklist.php` — **new** (Audited + SoftDeletes; `reason` redacted; `auditAction()` mapped).
- `app/Modules/Agencies/Database/Factories/BrandCreatorBlacklistFactory.php` — **new**.
- `app/Modules/Agencies/Models/AgencyCreatorRelation.php` — cast `blacklist_scope`/`blacklist_type` to the new enums.
- `app/Modules/Agencies/Http/Resources/AgencyCreatorDetailResource.php` — `?->value` on the enum-cast attributes (structured facts only; reason withheld).

**Backend — write path + authz + notification (Part A):**

- `app/Modules/Agencies/Http/Controllers/CreatorBlacklistController.php` — **new** (`store`/`destroy`, both scopes, audit, notification).
- `app/Modules/Agencies/Http/Requests/BlacklistCreatorRequest.php` + `UnblacklistCreatorRequest.php` — **new**.
- `app/Modules/Agencies/Policies/AgencyCreatorRelationPolicy.php` — `blacklist()` ability (admin/manager).
- `app/Modules/Agencies/Routes/api.php` — POST/DELETE `creators/{creator}/blacklist` (inside the `auth:web → tenancy.agency → tenancy` stack — no §4 allowlist entry).
- `app/Modules/Audit/Enums/AuditAction.php` — `CreatorBlacklisted` + `BrandCreatorBlacklistCreated`/`Deleted`.
- `app/Modules/Agencies/Mail/CreatorBlacklistedMail.php` + `resources/views/mail/agencies/creator-blacklisted.blade.php` — **new**.
- `lang/{en,pt,it}/creators.php` — the generic `blacklisted` email section.
- `app/Modules/Agencies/Http/Requests/UpdateAgencySettingsRequest.php` + `Controllers/AgencySettingsController.php` + `Resources/AgencySettingsResource.php` — the `blacklist_notification_policy` settings key.

**Backend — Part B integration:**

- `app/Modules/Agencies/Http/Controllers/AgencyCreatorDiscoveryController.php` — `excludeHardBlacklisted` (B1).
- `app/Modules/Agencies/Http/Controllers/AgencyConnectionRequestController.php` — the hard-blacklist send gate (B2).
- `app/Modules/Agencies/Http/Controllers/DashboardSummaryController.php` — `agencyWideBlacklisted` scope-aware predicate (B3).

**Backend — tests:**

- `tests/Feature/Modules/Agencies/CreatorBlacklistTest.php` — **new** (Part A: write paths, mandatory reason, authz, audit redaction, notification).
- `tests/Feature/Modules/Agencies/BlacklistEnforcementTest.php` — **new** (Part B: discovery exclusion + cross-agency isolation, request gate, scope-aware counts).
- `tests/Feature/Modules/Agencies/BlacklistEnumsTest.php` — **new** (catalogue).
- `tests/Feature/Modules/Audit/AuditActionEnumTest.php` — the three new cases.

**Frontend:**

- `packages/api-client/src/types/agency.ts` — `BlacklistScope`/`BlacklistType`, the blacklist payloads + envelope, `blacklist_notification_policy` on the settings types.
- `apps/main/src/modules/roster/api/roster.api.ts` — `blacklist()` (POST) + `unblacklist()` (DELETE-with-body).
- `apps/main/src/modules/roster/components/BlacklistCreatorDialog.vue` — **new**.
- `apps/main/src/modules/roster/pages/CreatorDetailPage.vue` — the blacklist section + type-aware badge + un-blacklist action + dialog mount.
- `apps/main/src/modules/settings/pages/SettingsPage.vue` — the notification-policy toggle.
- `apps/main/src/core/i18n/locales/{en,pt,it}/app.json` — the `roster.blacklist.*` + `settings.fields.blacklistNotificationPolicy*` strings.
- `apps/main/src/modules/roster/api/roster.api.spec.ts` + `pages/CreatorDetailPage.spec.ts` — blacklist coverage.

**Docs:**

- `docs/tech-debt.md` — closed the scope-aware-blacklist-counts entry (B3 built); logged the brand-scoped-matching-effect deferral (Sprint 8).
- `docs/reviews/sprint-7-review.md` — this file.

---

## Honest deviations & notes

1. **The `brand_creator_blacklists` schema follows the kickoff A1, NOT `03-DATA-MODEL.md §6` verbatim — surfaced + approved at plan-pause.** Spec §6 names the columns `block_type` / `created_by_user_id` and has no `ulid`, no soft-deletes, no `blacklisted_at`. The kickoff A1 (chosen) uses `ulid` + soft-deletes (un-blacklist = soft-delete, D-3) + `blacklisted_at` + `reason NOT NULL` + columns named `blacklist_type` / `blacklisted_by_user_id` to match the relation + the shared enum. This is a deliberate, logged divergence — the data-model doc should be reconciled to the as-built schema in a docs pass.
2. **Re-blacklist after un-blacklist relies on a partial unique index** (`WHERE deleted_at IS NULL`), not plain `unique(brand_id, creator_id)` — a plain unique would let the soft-deleted row keep the slot and block re-blacklisting. Chosen at plan-pause for full-history preservation (fresh row per blacklist episode).
3. **Agency-wide blacklist of a no-relation creator is restricted, not auto-created** (typed 422 `blacklist.relation_required`). The FE only exposes the dialog on `CreatorDetailPage` (which 404s without a relation), so in practice a relation always exists; the backend refuses to invent a synthetic `relationship_status`. Brand-scoped needs no relation. Confirmed at plan-pause.
4. **Brand-scoped blacklist is recorded-now, enforced-at-campaign-matching-later (Sprint 8).** The table + write path ship now; the matching effect has no consumer until brand-level campaign matching exists. Logged in `tech-debt.md` with the explicit instruction that Sprint 8's matching MUST consume `brand_creator_blacklists`. (Per the kickoff out-of-scope note — no Sprint-8 matching built to "make brand-scoped do something now.")
5. **`tenancy.md` / `services.md` unchanged — confirmed.** The blacklist writes are agency-path-scoped (`agencies/{agency}/creators/{creator}/blacklist`) inside the tenancy stack, not `creators/me/*` — no cross-tenant §4 allowlist entry needed. No service-boundary change.
6. **A test-only refactor for PHPStan cleanliness.** `CreatorBlacklistTest` accessed properties on nullable `->first()` / `DB::table()->first()` results. Resolved by mirroring the house pattern (`firstOrFail()` for the model fetches; the `AuditLog` model instead of raw `DB::table` for the audit row) — no `assert()` / `@phpstan-ignore` / cast-to-silence. Behaviour unchanged.

---

## Proposed commit shape (for the merge step — not yet committed)

A backend/FE split (or two-commit pair):

1. `feat(agencies): blacklisting — brand_creator_blacklists table + the two write paths + discovery/request/count enforcement (Sprint 7 A+B)` — all backend (migration, enums, model, controller, requests, policy, routes, audit, mail, settings, Part-B controllers) + the three backend test files + the tech-debt closures.
2. `feat(main): blacklist creator dialog + detail/settings wiring + api-client types (Sprint 7 A7)` — `packages/api-client` + `apps/main` (dialog, detail page, settings toggle, i18n, FE specs).

Plus this review under either, or its own `docs(reviews): add Sprint 7 review` commit.

---

_Provenance: drafted by Cursor (Sprint 7 single-chunk build pass, 2026-06-04). Part A = mechanical wiring on shipped columns + one new table; Part B = the net-new discovery/request/count integration (the load-bearing half). Spot-check passed (no post-merge corrections), weighted on Part B; `03-DATA-MODEL.md §6` reconciled to the as-built schema. **Closed** per `PROJECT-WORKFLOW.md` §3._
