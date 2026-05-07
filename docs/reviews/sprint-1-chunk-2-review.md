# Sprint 1 — Chunk 2 Review

**Status:** Closed (with two clarifications addressed)
**Reviewer:** Claude
**Reviewed against:** `02-CONVENTIONS.md`, `03-DATA-MODEL.md`, `04-API-DESIGN.md`, `05-SECURITY-COMPLIANCE.md`, `07-TESTING.md`

## Scope

Audit module foundation:

- `audit_logs` migration (append-only, no `updated_at` / `deleted_at`)
- `AuditLog` model with overridden `update()` / `delete()` / `save()` that throw
- `AuditLogger` service (singleton, DI'd, also resolvable via `Audit` facade)
- `AuditEvent` value object
- `Audited` trait + `AuditObserver` listening to created/updated/deleted/restored
- `AuditAction` enum (auth._ + minimum user._ verbs)
- `RequireActionReason` middleware enforcing `X-Action-Reason`
- `Auditable` interface (PHPStan can resolve trait+interface intersections)
- Applied to `User` and `Agency`

## Acceptance criteria — all met

- ✅ Migration with all `§ 12` indexes; no `updated_at`/`deleted_at`
- ✅ `AuditLog::update()` and `delete()` actively throw `AuditLogImmutableException`
- ✅ `AuditLogger` callable via DI and facade producing identical rows
- ✅ Allowlist on `User`; `password`, `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, `remember_token` are NOT in allowlist
- ✅ `RequireActionReason` middleware emits documented `validation.reason_required` 400 envelope
- ✅ `AuditAction` enum populated with auth-only actions + minimum user.\* verbs
- ✅ 100% line coverage on `app/Modules/Audit/` (verified via `composer test:coverage` with pcov)

## Standout design choices (unprompted)

- **Trait + interface split.** `Auditable` interface introduced because PHPStan cannot resolve trait names as types. Models opt in by `use Audited; implements Auditable;` and provide `auditableAllowlist()`. Trait declares the method abstract — forgetting allowlist fails at class-load, not in production.
- **Per-instance `withAuditReason()`** fluent API for reason-mandatory actions. Cleaner than global state or context stacks.
- **`requiresReason()` enum method** mirroring `05 § 3.3` reason-mandatory action subset (`auth.account_unlocked`, `user.deleted`, `user.suspended`, `user.unsuspended`).
- **Removed empty `registerRoutes()` scaffold** to hit honest 100% coverage on the provider rather than mocking `routesAreCached()`.
- **Password-isolation test** covers both the easy case (only password changed → no row emitted) and the hard case (allowed field changed AND password changed → row emitted but secret-free).

## Clarifications requested before chunk 3

### Clarification 1 — Postgres-CI migration is non-destructive

**Trigger:** Sprint 8
**Action:** Append a sentence to the existing SQLite-in-tests entry in `docs/tech-debt.md`:

> "Sprint 8 Postgres-CI also upgrades `audit_logs.ip` varchar(45) → inet and any json → jsonb columns non-destructively (expand/migrate/contract per `docs/08-DATABASE-EVOLUTION.md`), since audit data will exist by then."

### Clarification 2 — AuditLogger handles non-HTTP contexts gracefully

**Action:** Add test `runs cleanly with no HTTP request and no overrides` to `tests/Feature/Modules/Audit/AuditLoggerTest.php` asserting:

- `actor_id`: null
- `actor_role`: sentinel `'system'` (or document actual sentinel)
- `ip`: null
- `user_agent`: null
- Logger does not crash

This matters because chunk 1's `Sprint1IdentitySeeder` and several chunk 3+ console commands write audit logs without an HTTP context.

## Decisions documented for future chunks

- Audit log is append-only at application layer — sufficient for SOC 2 Type 1.
- `Audited` trait requires explicit `auditableAllowlist()` — this is per-model opt-in, never automatic. Sensitive fields are excluded by virtue of not appearing in the allowlist.
- Reason-mandatory actions enforce via two layers: HTTP `RequireActionReason` middleware checks for the header at the route boundary; service-layer `AuditLogger` enforces the reason for `requiresReason()` actions when called.
- `AuditLogger` is HTTP-aware but not HTTP-coupled. Pulls IP/UA from `request()` when present, actor from active guard, agency from `TenancyContext`. All overridable via named arguments for queue/console contexts.

## What was deferred (with triggers)

- Double-write to S3 audit bucket (`05 § 3.6`) — Phase 2
- Checksum hash chain (`05 § 3.4`) — Phase 2+
- DB-level INSERT/SELECT-only audit role (`05 § 3.4`) — production ops, AWS RDS hardening pass
- `/api/v1/me/audit-history` endpoint — chunk 7 (admin SPA)

## Verification results

| Gate                                              | Result                                                           |
| ------------------------------------------------- | ---------------------------------------------------------------- |
| `composer ci` (pint:test → stan → test)           | green                                                            |
| `php vendor/bin/pest`                             | 79 passed (284 assertions), ~2.0s                                |
| `php vendor/bin/pest tests/Feature/Modules/Audit` | 44 passed (137 assertions), ~3.6s                                |
| `php vendor/bin/pint`                             | clean                                                            |
| `php vendor/bin/phpstan analyse` (level 8)        | no errors                                                        |
| `composer test:coverage`                          | 91.8% global (was 86.7%); 100% on `Modules/Audit/` (12/12 files) |
| `pnpm -w test`                                    | all green (`apps/admin` + `apps/main` Vitest)                    |
