# Sprint 1 — Chunk 1 Review

**Status:** Closed
**Reviewer:** Claude
**Reviewed against:** `00-MASTER-ARCHITECTURE.md`, `02-CONVENTIONS.md`, `03-DATA-MODEL.md`, `05-SECURITY-COMPLIANCE.md`, `07-TESTING.md`, `08-DATABASE-EVOLUTION.md`

## Scope

DB foundation, models, multi-tenancy primitives:

- `users`, `agencies`, `agency_users`, `admin_profiles` migrations
- `User`, `Agency`, `AgencyMembership`, `AdminProfile` models
- `BelongsToAgency` trait + global scope
- Backed enums (`UserType`, `AgencyRole`, `AdminRole`, `ThemePreference`)
- Factories with role-specific traits
- `Sprint1IdentitySeeder` (local + testing only)

## Acceptance criteria — all met

- ✅ `php artisan migrate:fresh --seed` succeeds
- ✅ `php artisan migrate:rollback` reverses cleanly
- ✅ Larastan level 8 passes for new code
- ✅ `BelongsToAgency` global scope filters correctly
- ✅ `agency_id=null` on tenant-scoped model throws on save
- ✅ Cross-agency `find()` returns null
- ✅ Migration tests assert columns and indexes exist
- ✅ Factories produce valid models in isolation

## Issues raised in review

### Note 1 — SQLite in tests, Postgres in dev

**Resolution:** Accepted with mitigation. Entry added to `docs/tech-debt.md` requiring Postgres-CI by Sprint 8 (when jsonb queries, FTS, and gin indexes start landing).

### Note 3 — APP_KEY workaround

**Resolution:** Rejected the workaround, fixed properly. `phpunit.xml` now embeds a deterministic test-only `APP_KEY`. `scripts/setup.sh` reads `apps/api/.env` and only generates a key when empty (never overwrites). README quickstart confirms `./scripts/setup.sh` is canonical and produces a working test suite immediately. Verified from clean state.

### Note 5 — Tenancy fail-closed enforcement

**Resolution:** `EnsureTenancyContext` middleware throws `MissingTenancyContextException` on any HTTP route reached without active context. Registered as `tenancy` alias. Four-scenario test in `tests/Feature/Tenancy/MissingContextTest.php`. `docs/security/tenancy.md` documents: no-op-when-no-context contract, cross-tenant route allowlist with sign-off process, queue-job pattern (`TenancyContext::runAs` vs `withoutGlobalScope`), PR review checklist, populator architecture diagram.

## Spot-checks confirmed

1. ✅ `users` table contains all Phase 1 columns from `03-DATA-MODEL.md` § 2
2. ✅ `User` model encrypts `two_factor_secret` and `two_factor_recovery_codes` via `casts()` method
3. ✅ `Sprint1IdentitySeeder` is gated to local/testing — and _throws_ `RuntimeException` if invoked elsewhere (defensive interpretation; better than asked)
4. ✅ `BelongsToAgency` trait throws a named exception (not silent return)
5. ✅ `AgencyMembership` has explicit `protected $table = 'agency_users';`

## Decisions documented for future chunks

- The `users.type` enum includes `creator`, `agency_user`, `platform_admin`, with `brand_user` reserved for Phase 2.
- `AgencyMembership` is the model name for the `agency_users` table — Eloquent table mapping is explicit.
- `TenancyContext` is a singleton; routes that need cross-tenant access are explicitly registered in an allowlist documented in `docs/security/tenancy.md`.
- Local-only seeders throw on non-local environments rather than silently returning.

## What was deferred (with triggers)

- Postgres `inet` and `jsonb` column types — Sprint 8 (when Postgres-CI lands)
- Brand-side users (`brand_user` role active) — Phase 2

## Commit reference

`8f51b0e` on `main`
