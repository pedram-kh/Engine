# Sprint 2 Chunk 1 Review (backend: brands + agency user invitations + agency settings + test-helper)

**Status:** Closed. No change-requests; the work is mergeable as-is.

**Reviewer:** Claude (independent review) — incorporating Cursor's self-review draft and the mid-spot-check policy-coverage extension.

**Reviewed against:** `PROJECT-WORKFLOW.md` § 5 (all standing team standards through Sprint 1 close) + § 7 (spot-checks-before-greenlighting); `02-CONVENTIONS.md` § 1 + § 3 + § 4.3; `03-DATA-MODEL.md` (authoritative for brands + agency role matrix + invitation shape); `04-API-DESIGN.md` § 4 + § 7 + § 8; `05-SECURITY-COMPLIANCE.md` § 6 (permission matrix); `06-INTEGRATIONS.md` (mail infrastructure); `07-TESTING.md` § 4 + § 4.4; `08-DATABASE-EVOLUTION.md` (migration discipline); `security/tenancy.md` (multi-tenancy enforcement); Sprint 1 self-review (workflow patterns + standing standards baseline); all chunk-6 + chunk-7 + chunk-8 review files (test-helper gating pattern from chunks 6.1 + 7.6; transactional audit from chunks 3 + 7; real-rendering mailable from chunk 4); `tech-debt.md` (zero new entries from Chunk 1).

This is Chunk 1 of Sprint 2 — the first chunk of the post-Sprint-1 application-phase work. After Chunk 1 lands, the backend has:

- `brands` table with Phase 1 columns + reserved Phase 2 columns + a structural `status` enum addition (D1).
- Brand CRUD endpoints (`/api/v1/agencies/{agency}/brands`) with full multi-tenancy enforcement + three-role permission gating (`agency_admin` + `agency_manager` can create/edit/archive; all three roles can view).
- `agency_user_invitations` table (D2) + invitation creation endpoint + accept endpoint + mailable (3 locales) + token model (single-use-with-retry-on-failure per Q1).
- `AgencyInvitationService` with transactional `invite()` and `accept()` actions, both emitting audit log entries on every state flip — including the `expired_on_attempt` audit action that logs when an expired token is used (a security-relevant audit decision worth highlighting).
- Agency settings persistence (currency + language already-existing per D3) + GET/PATCH endpoint.
- Test-helper endpoint `POST /api/v1/_test/agencies/{agency}/invitations` for Chunk 2's accept-flow Playwright spec.
- Eight new `AuditAction` enum values covering brand + invitation + settings state changes.
- One new architecture-pinning test (`BrandPolicyTest`) added mid-spot-check to give the policy layer independent coverage.

---

## Scope

Cursor's draft enumerates the full scope in detail. The merged review preserves Cursor's draft as the durable record by reference rather than restating. Brief orientation:

**Sub-step 0 (route-scoping middleware):** `SetTenancyFromAgencyRoute` middleware — Sprint 2 equivalent of Sprint 1's `SetTenancyContext` for `/agencies/{agency}/*` routes. Resolves the `{agency}` binding, verifies the user has an accepted membership, returns 404 on mismatch, sets `TenancyContext`. Registered as `tenancy.agency` alias.

**Layer 1 (brands table):** Migration + `BrandStatus` enum + `Brand` model (`BelongsToAgency` + `HasUlid` + `SoftDeletes`) + `BrandFactory` with `archived()` and `forAgency()` states.

**Layer 2 (brand CRUD endpoints):** `BrandPolicy` + `BrandController` (index/store/show/update/destroy-as-archive) + `CreateBrandRequest` + `UpdateBrandRequest` + `BrandResource` (ULID-only per Q3, never integer id). Routes under `['auth:web', 'tenancy.agency', 'tenancy']` middleware stack. Belt-and-suspenders `assertBelongsToAgency()` in controller (D4 — closes `SubstituteBindings` ordering gap). 27 Pest feature tests + 17 Pest unit tests (added mid-spot-check) = 44 brand-layer tests.

**Layer 3 (invitation system):** `agency_user_invitations` migration (D2) + `AgencyUserInvitation` model + factory + `AgencyInvitationService` (`invite()` + `accept()` both transactional with audit per chunk 7 standard) + `InvitationController` (store `agency_admin`-only via explicit membership check; **accept endpoint under `auth:web` only — explicitly NOT under `tenancy.agency` middleware because the accepting user is not yet a member of the agency they're accepting into**) + `InviteAgencyUserMail` (ShouldQueue, 3 locales, tagged) + Blade template + lang files. 17 functional tests + 4 real-rendering mailable tests = 21 invitation-layer tests.

**Layer 4 (agency settings):** Per D3, `default_currency` + `default_language` already existed on the agencies table; no new migration. `AgencySettingsController` (GET all roles, PATCH `agency_admin`-only) + `UpdateAgencySettingsRequest` (currency 3-char ISO, language in en/pt/it) + `AgencySettingsResource`. 12 Pest tests.

**Layer 5 (test-helper provisioning for Chunk 2):** `CreateAgencyInvitationController` (`POST /api/v1/_test/agencies/{agency}/invitations`) seeds a pending invitation with a known token and returns the unhashed token. Mirrors `CreateAdminUserController` from chunk 7.6 verbatim. 8 Pest tests covering every branch.

**`AuditAction` enum extension:** Eight new values:

- `brand.created`, `brand.updated`, `brand.archived`, `brand.restored` — brand state flips
- `invitation.created`, `invitation.accepted` — invitation success path
- **`invitation.expired_on_attempt`** — emitted when an expired token is used (failure-path audit; security-relevant)
- `agency_settings.updated` — settings change

Existing `AuditActionEnumTest` updated to cover the new values.

---

## Design Q answers — verified

The kickoff surfaced three design Qs as explicit questions to answer in the plan response. All three answers are defensible with reasoning, and the implementations match the answers.

### Q1 — Invitation token model: single-use-with-retry-on-failure

**Cursor's answer:** Track `accepted_at` only; multiple attempts before acceptance are fine. Once `accepted_at` is stamped → 409 on subsequent attempts. Token never stored unhashed.

**Reasoning:** Security cost vs UX cost trade-off. A strict single-use model means network failures during accept invalidate the token, forcing the user to request a new invitation. The retry-on-failure model preserves user experience without weakening security — the token is still single-use in the success-path sense (one acceptance stamps `accepted_at`); the failure path just doesn't burn the token. Token-hash storage means the unhashed value is never recoverable from the database.

**Implementation matches answer:** The `accept()` action is transactional with an `accepted_at IS NULL` check before stamping; the column is the single source of truth for token consumption. **Expired-token attempts emit the `invitation.expired_on_attempt` audit action** — meaning the failure path is auditable, which makes the "retry on failure" semantics observable in the audit log without changing the security posture.

### Q2 — Existing-user invitation flow: Option B (dedicated accept page)

**Cursor's answer:** Magic-link goes to a dedicated SPA accept page. The page displays "you're being invited to <Agency> as <Role>" with explicit confirmation. Accept endpoint requires `auth:web`; the accepting user's email must match the invitation email.

**Reasoning:** Option A (seamless accept when signed-in matching) trades explicit consent for one fewer click. The cost of the trade is that a logged-in user can be quietly added to an agency by clicking a link, which is a social-engineering vector. Option B preserves explicit consent at one click of cost.

**Implementation matches answer:** The accept endpoint's response shape is structured around the SPA fetching invitation details (agency name, role, expiry status) before user confirmation; the accept call is a separate POST. **The endpoint is NOT under `tenancy.agency` middleware** — a deliberate architectural choice because the accepting user isn't a member yet of the target agency. Membership-checking happens at accept time via the email-match check, not via middleware that would 404 the user before they could even see the invitation.

### Q3 — Brand identifier shape: ULID

**Cursor's answer:** `/api/v1/agencies/{agency}/brands/{brand}` where both `{agency}` and `{brand}` are ULIDs. `HasUlid::getRouteKeyName()` returns `'ulid'`.

**Reasoning:** Consistency with the existing agency convention. ULIDs are time-sortable and don't expose row counts to API consumers; both are properties worth preserving for any model in the API surface.

**Implementation matches answer:** Verified via D4 spot-check below — `SubstituteBindings` resolves `{brand}` against the `ulid` column, not `id`. The `BrandResource` never serializes the integer `id`, only the `ulid`.

---

## Acceptance criteria — all met

(All Chunk 1 acceptance criteria from the kickoff — endpoints work per spec; 100% Pest coverage on new endpoints + branches; multi-tenancy enforced; permission gating per agency role correct; audit logging on all state-flipping operations; all existing tests remain green; Pint + PHPStan clean; migration applies + rolls back; mailable renders in all three locales; test-helper endpoint provisioned for Chunk 2 — all ✅. Reproduced verbatim in Cursor's draft.)

---

## Plan corrections / honest deviation flagging — four items

**Twelfth instance** in Sprint 1 + 2 of Cursor flagging where the kickoff or spec carried hidden assumptions that didn't hold. **Twelve for twelve; the pattern remains permanent.**

Three deviations are structural additions/observations to the data model (the data-model spec genuinely omitted them); one is an architectural note about Laravel middleware ordering.

### D1 — `brands.status` column (structurally-correct minimal extension)

**Spec assumption:** `docs/03-DATA-MODEL.md` § brands table specifies columns: `id`, `ulid`, `agency_id`, `name`, `slug`, `description`, `industry`, `website_url`, `logo_path`, `default_currency`, `default_language`, `brand_safety_rules`, `exclusivity_window_days` (P2), `client_portal_enabled` (P2), `created_at`, `updated_at`, `deleted_at`. **No `status` column.**

**Why it didn't hold:** The kickoff explicitly pre-answered "brand archive: status field, not soft delete." `archived` was specified as a status value; the column to hold it was not in the spec.

**Alternative taken — accepted:** Added `status` as `varchar(16)` with default `'active'`, values `'active'` / `'archived'`. Mirrors the `campaigns` table's operational-state pattern from `03-DATA-MODEL.md` § 417: `status | varchar(16) | 'draft', 'active', 'paused', 'completed', 'cancelled'`. Coexists with `deleted_at` — the spec's pattern for operational-state entities pairs both.

**Why this is structurally correct, not tech debt:** The spec specifies the pattern for operational-state columns elsewhere; Cursor mirrored it. The kickoff's pre-answer "status field, not soft delete" is implemented as "status field PLUS deleted_at for soft delete" — `archived` is operational; `deleted` (via `deleted_at`) is destructive.

### D2 — `agency_user_invitations` table (structurally-correct minimal extension)

**Spec assumption:** `docs/03-DATA-MODEL.md` § agency_users table has columns `user_id | bigint FK | users.id, RESTRICT` — non-null FK to users. The spec models invitations via `invited_at`, `invited_by_user_id`, `accepted_at` on this same table — which assumes the user account already exists at invitation time.

**Why it didn't hold:** The spec is silent on pre-acceptance state when the invitee doesn't yet have a user account. The kickoff's pre-answered invitation flow (magic-link via email tokenized URL) explicitly supports new-user accept — meaning the invitation must exist before any user account exists.

**Alternative taken — accepted:** New `agency_user_invitations` table with columns `id`, `agency_id`, `email`, `role`, `token_hash`, `expires_at`, `accepted_at`, `accepted_by_user_id` (nullable), `invited_by_user_id`, `created_at`, `updated_at`. At accept time, resolve `user_id` (existing user or newly created), create the `agency_users` row, stamp `accepted_at` and `accepted_by_user_id` on the invitation.

**Why this is structurally correct, not tech debt:** The spec's `agency_users.user_id NOT NULL` constraint _structurally_ prevents storing pre-acceptance state on `agency_users`. The three alternatives:

- (a) `status='pending'` row on `agency_users` — blocked by NOT NULL constraint; would require a data-model change of equal scope (making `user_id` nullable).
- (b) Extend an existing invitations table — no such table exists in the spec.
- (c) Separate `agency_user_invitations` table — minimally-correct.

The spec's `brand_users` P2 design (line 162 — `invited_at`, `accepted_at` on the pivot) is the canonical pattern for "invitation row that becomes a membership row at accept time" _when_ the user already exists. For the not-yet-existing-user case, a separate invitation table is the only structurally-correct shape.

### D3 — Agency settings columns already exist (structurally-correct no-op)

**Spec assumption:** `agencies` table needs `default_currency` + `default_language` columns added.

**Why it didn't hold:** Both columns already exist on the agencies table from earlier Sprint 1 work.

**Alternative taken — accepted:** Skip the migration. Layer 4 ships only the controller + request + resource + tests.

**Why this is correct, not a divergence:** The kickoff's "add columns" instruction was paraphrase of intent; the actual state was "columns already exist; just add the endpoint."

### D4 — Cross-tenant brand check is explicit (architectural note)

**Architectural observation:** Laravel's `SubstituteBindings` middleware is part of the `api` middleware group and runs BEFORE the project's `tenancy.agency` middleware. At `SubstituteBindings` time, `TenancyContext::hasAgency()` returns false (context not yet set), so `BelongsToAgencyScope` is a no-op and route-model binding resolves `{brand}` against the global Brand table without scoping.

**Resolution:** Explicit `assertBelongsToAgency()` check in `BrandController` methods (belt-and-suspenders). The global scope provides defense-in-depth for direct model access (`Brand::find()`), but route-model binding requires the controller-level check.

**Why this is structurally correct:** The middleware ordering is framework-level (not project-controllable without violating Laravel conventions). The controller-level check is the right fix; the cost is one assertion per controller method. The `BrandCrudTest::test_cross_tenant_brand_show_returns_404` test pins the failure mode (verified empirically — see spot-check 3).

**Process record on D4:** Worth documenting for future Sprint 2+ tenant-scoped resource controllers. The pattern is: route-model binding + controller-level `assertBelongsToAgency()` check + tested cross-tenant scenario. Future controllers (campaigns, drafts, etc.) follow the same shape.

---

## Standout design choices (unprompted)

Four deserve highlighting:

- **`BrandPolicy` independent unit-test coverage added mid-spot-check.** This is the strongest disciplined-self-correction since chunk 7 Group 3's chained-flow test. Cursor's break of `BrandPolicy::view()` produced zero failures — the `tenancy.agency` middleware blocked non-members with 404 before the policy was ever evaluated. The policy was defense-in-depth at the HTTP layer but had no independent test coverage. **Cursor surfaced this gap, added `BrandPolicyTest` with 17 tests, re-verified the break catches the policy violation independently, reverted, ran the suite back to green.** Recorded as the canonical pattern: when defense-in-depth layers exist, each layer needs independent test coverage. Without it, regression in one layer is masked by another layer until both regress simultaneously.

- **`invitation.expired_on_attempt` audit action emits on the failure path.** The accept endpoint emits an audit event when an expired token is used, not just when acceptance succeeds. This means the audit log captures both successful onboarding AND attempted-but-failed invitation use — a security-relevant detail for forensics on suspected social-engineering attempts. Worth recording as a pattern: **audit failure paths on security-relevant actions, not just success paths.**

- **Six-step trace documenting `SubstituteBindings` ordering issue.** Cursor's D4 explanation traces exactly what happens for `GET /agencies/{agency-ABC}/brands/{brand-XYZ}` when brand-XYZ belongs to a different agency. The trace is so clear it doubles as documentation for future Sprint 2+ tenant-scoped controllers. **Worth incorporating into `docs/security/tenancy.md` as a worked example.**

- **Test-helper endpoint mirrors `CreateAdminUserController` shape verbatim.** Same gating (`VerifyTestHelperToken` + env gate + provider), same 404-on-closed-gate behavior, same Pest coverage breadth. The chunk 6.1 + chunk 7.6 pattern is now baseline for any new test-helper endpoint.

---

## Decisions documented for future chunks

- **Operational-state columns use `varchar(16)` per the spec's `campaigns.status` pattern.** Established by D1. Future entity tables with operational state (campaigns, drafts, payments, etc.) follow the same shape.

- **Pre-acceptance state for not-yet-existing users lives in a separate `*_invitations` table.** Established by D2. Future invitation-style flows (brand-side users, system-role admins, etc.) follow the same shape unless the spec explicitly provides an alternative.

- **Tenant-scoped resource controllers carry an explicit `assertBelongsToAgency()` check in addition to the global scope.** Established by D4. The `SubstituteBindings` ordering issue is framework-level and structural; the check is the right fix.

- **Defense-in-depth layers require independent test coverage.** Established by the mid-spot-check `BrandPolicyTest` extension. Future security-relevant layers (policies, scopes, middleware, validation) get independent unit tests, not just integrated feature tests.

- **Audit failure paths on security-relevant actions, not just success paths.** Established by `invitation.expired_on_attempt`. Future audit-emitting flows on security-relevant surfaces (token use, permission checks, credential verification) emit on both success and failure paths.

- **Accept-style endpoints that operate on pre-membership state run under `auth:web` only, NOT under `tenancy.agency` middleware.** Established by `InvitationController::accept`. Future similar flows (brand-client invitations in Phase 2, agency-merge confirmations, etc.) follow the same shape.

- **Test-helper endpoints mirror `CreateAdminUserController` shape verbatim.** Established by `CreateAgencyInvitationController`. Future test-helper endpoints follow the same shape (gating, response format, branch coverage).

- **Brand identifier convention is ULID in URLs + ULID-only serialization in resources.** Established by Q3. Future Phase 1 entities follow the same shape (the existing agency convention).

- **Invitation flow uses single-use-with-retry-on-failure semantics.** Established by Q1. Future invitation-style flows (creator bulk-invite, brand-client invitations in Phase 2, etc.) follow the same shape.

- **Magic-link flows route to dedicated accept pages with explicit confirmation.** Established by Q2. Future magic-link flows (password reset confirmation, account merge confirmation, etc.) follow the same shape — never silent-on-click acceptance.

---

## Tech-debt items

**No new entries added.** All four deviations are structurally-correct adaptations to spec gaps or framework-level ordering concerns; none warrant tech-debt entries.

**Pre-existing items from Sprint 1 remain open** (light primary/on-primary AA-normal failure, broader `tokens.css` `--color-*` system, idle-timeout unwired, Vue 3 attribute fall-through architecture test, SQLite-vs-Postgres CI, TOTP issuance vs `Carbon::setTestNow()`, account-locked `{minutes}` interpolation gap, Laravel exception handler JSON shape for unauth `/api/v1/*`, test-clock × cookie expiry). None are triggered by Chunk 1.

---

## Verification results

| Gate                                   | Result                                                                                                                                                |
| -------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| `apps/api` Pint                        | Pass                                                                                                                                                  |
| `apps/api` PHPStan (level 8)           | Pass — 0 errors                                                                                                                                       |
| `apps/api` Pest                        | **452 tests passing** (was 367 at Sprint 1 close; +85 new across Chunk 1 including the mid-spot-check `BrandPolicyTest` extension); 1,226+ assertions |
| `apps/main` typecheck / lint / Vitest  | Pass / Pass / 286 passing (unchanged from Sprint 1 close)                                                                                             |
| `apps/admin` typecheck / lint / Vitest | Pass / Pass / 232 passing (unchanged)                                                                                                                 |
| `packages/design-tokens` Vitest        | 17 + 1 `it.todo` (unchanged)                                                                                                                          |
| `packages/api-client` Vitest           | 88 passing (unchanged)                                                                                                                                |
| Repo-wide `pnpm -r lint` / `typecheck` | Clean                                                                                                                                                 |
| Architecture tests                     | All Sprint 1 tests green; no new architecture tests in Chunk 1                                                                                        |
| Migration                              | Applies + rolls back cleanly (verified)                                                                                                               |
| Mailable                               | Renders in en/pt/it (4 real-rendering tests per chunk 4 standard)                                                                                     |
| Playwright `pnpm test:e2e`             | Not exercised (no E2E in Chunk 1 — Chunk 2's surface)                                                                                                 |

---

## Spot-checks performed

Three spot-checks, all green. **Mid-spot-check disciplined self-correction surfaced + closed a coverage gap before commit.**

### Spot-check 1 — D1 + D2 deviation justifications

**Verdict: green.** Both deviations verified against the data-model spec:

- **D1:** Spec genuinely omits `brands.status`. The `campaigns` table's status column (line 417: `status | varchar(16) | 'draft', 'active', 'paused', 'completed', 'cancelled'`) is the canonical pattern for operational-state entities. `brands.status` matches the shape (varchar(16) with default 'active'; coexists with `deleted_at`). Structurally consistent.

- **D2:** Spec's `agency_users.user_id` is NOT NULL FK — structurally blocks pre-acceptance state on the membership table. The three alternatives (pending row on `agency_users`, extending non-existent invitations table, separate `agency_user_invitations` table) leave only the separate table as structurally feasible. Mirrors the `brand_users` P2 invitation-pivot pattern from the spec.

### Spot-check 2 — Permission gating + multi-tenancy empirical verification

**Verdict: green, with mid-spot-check coverage gap closure.** This is the strongest disciplined-self-correction since chunk 7 Group 3.

**Initial break:** Breaking `BrandPolicy::view()` to return `true` unconditionally produced **zero test failures** — the `tenancy.agency` middleware blocked non-members with 404 before the policy was ever evaluated. The policy was defense-in-depth at the HTTP layer but had no independent test coverage.

**Cursor's response:** Surfaced the gap, added `tests/Unit/Modules/Brands/BrandPolicyTest.php` with 17 tests covering every policy method × role combination. Re-ran the same break: `view returns false for a user with no membership` now fails as expected. Reverted; ran the full suite back to green.

**`assertBelongsToAgency()` break:** Temporarily commenting it out produced "Expected 404 but received 200" on `cross-tenant brand show returns 404` — exact data-leak failure mode caught.

**Both layers independently verified:**

- Policy gate → caught by `BrandPolicyTest.php:71` ("view returns false for non-member")
- Cross-tenant gate → caught by `BrandCrudTest.php:249` ("cross-tenant brand show returns 404")

**Process record:** The defense-in-depth coverage pattern is now canonical. **Recorded as a team standard:** when two layers both enforce an invariant, each needs independent test coverage. Without it, regression in one layer is masked by another layer until both regress simultaneously — a silent-failure mode that's much harder to debug than two independent failures.

### Spot-check 3 — D4 middleware ordering belt-and-suspenders

**Verdict: green; the ordering concern is real, the fix is correct.**

`SubstituteBindings` is entry 3 of Laravel's `api` middleware group (verified at framework source line 497). The project's `['auth:web', 'tenancy.agency', 'tenancy']` middleware runs AFTER `api`. The six-step trace:

1. `SubstituteBindings` resolves `{brand-XYZ}` via `Brand::where('ulid', 'XYZ')->first()`. `BelongsToAgencyScope` checks `TenancyContext::hasAgency()` → false (context not yet set). Scope is no-op. Cross-agency brand DEF is found.
2. `auth:web` authenticates.
3. `tenancy.agency` resolves `{agency-ABC}`, verifies membership, sets context to ABC.
4. `tenancy` (`EnsureTenancyContext`) confirms context.
5. `BrandController::show()` receives `Agency(ABC)` and `Brand(agency_id=DEF, ulid=XYZ)`.
6. `assertBelongsToAgency()` checks `DEF !== ABC` → `abort(404)`.

Without step 6: returns 200 with Brand DEF's data — confirmed empirically in spot-check 2. The cross-tenant pinning test at `BrandCrudTest.php:242` is the durable proof.

**`assertBelongsToAgency()` is load-bearing, not dead defensive code.**

### Diff stat

Per Cursor's verification: 452 tests passing (367 Sprint 1 baseline + 85 new). The mid-spot-check `BrandPolicyTest` addition is included in the 85. All other gates green.

---

## Cross-chunk note

None this round. Confirmed:

- Sprint 1's standing standards (PROJECT-WORKFLOW.md § 5 + chunk-6/7/8 additions) all apply.
- Sprint 1's test-helper pattern (chunks 6.1 + 7.6) mirrored verbatim in `CreateAgencyInvitationController`.
- Sprint 1's transactional audit standard (chunk 7) applied to all state-flipping invitation + brand + settings operations.
- Sprint 1's real-rendering mailable standard (chunk 4) applied to `InviteAgencyUserMail` with 4 real-rendering tests (one per locale + one happy-path).
- The chunk-7.1 hotfix saga conventions are baseline; no new E2E specs in Chunk 1, so most conventions don't apply directly here (those land in Chunk 2's E2E specs).

---

## Process record — compressed pattern (twelfth instance)

The compressed pattern continues to hold. Chunk 1 was backend-only in one Cursor session with three design Qs answered with reasoning + the four honest deviations all categorized correctly + the mid-spot-check coverage gap surfaced and closed.

Specific observations:

- **The mid-spot-check coverage gap surfacing is the most valuable single moment in this review.** Without spot-check 2's empirical break-revert, the `BrandPolicy` would have shipped with no independent test coverage. The break-revert pattern (chunk 8 baseline) caught a real issue, and Cursor's response was structurally correct — surface the gap, close it, verify the closure, document it. **This is the canonical example of "defense-in-depth coverage" as a team standard.**

- **The "answer design Qs in plan response with reasoning" pattern from chunk 8 Group 2 carried forward cleanly.** Three Qs answered; three answers durably recorded in the review file; three implementations match the answers. No round-trip needed on any Q.

- **The data-model spec vs reality boundary surfaced two structural gaps (D1 + D2) that the spec doesn't cover but Chunk 1 needed.** Both are minimally-correct extensions per the spec's adjacent patterns. **Worth flagging for future Sprint 2+ work:** when the spec leaves a gap, the right move is to extend per the spec's nearest pattern, not invent freely.

- **Zero change-requests on the seventh consecutive review group.** The workflow stability from Sprint 1 carries forward into Sprint 2.

---

## What Chunk 1 closes for Sprint 2

- ✅ Brands data model + CRUD endpoints with permission gating + multi-tenancy enforcement.
- ✅ Agency user invitation system (magic-link via tokenized email) with mailable in 3 locales.
- ✅ Agency settings endpoint (currency + language).
- ✅ Test-helper endpoint provisioned for Chunk 2's accept-flow Playwright spec.
- ✅ Eight new `AuditAction` enum values covering brand + invitation + settings state changes including the failure-path `invitation.expired_on_attempt` audit action.
- ✅ `BrandPolicyTest` independent unit-test coverage (added mid-spot-check).
- ✅ Foundation for Chunk 2 (frontend) to consume — all backend endpoints work; permissions enforce; multi-tenancy enforces; audit logs flow.

**Chunk 2 (next session) covers frontend** — agency layout shell + brand CRUD UI + invitation accept page + agency settings UI + E2E specs + Sprint 2 closing artifacts. The largest single Cursor session in the project per the two-group decision; Chunk 2's kickoff will need extra structure.

---

_Provenance: drafted by Cursor on Chunk 1 completion (compressed-pattern process per `PROJECT-WORKFLOW.md` § 3 step 6, modified). Independently reviewed by Claude with three targeted spot-checks (D1 + D2 data-model deviation justifications; permission gating + multi-tenancy empirical verification with mid-spot-check coverage gap closure; D4 middleware ordering trace verification). Four honest deviations surfaced and categorized (3 structurally-correct minimal extensions + 1 architectural note), all resolved with structurally-correct alternatives. One mid-spot-check coverage gap surfaced (`BrandPolicy` had no independent test coverage) and closed before commit (added `BrandPolicyTest` with 17 tests). The pattern of "every group catches at least one hidden assumption" is now twelve-for-twelve. Status: Closed. No change-requests; Chunk 1 lands as-is._
