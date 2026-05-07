# Catalyst Engine — Tech Debt Register

A living list of conscious shortcuts and deferred decisions. Each entry names the
area, the cost we are accepting now, the trigger that escalates the work, and the
sprint by which it must be resolved.

The aim is to make every shortcut visible: nothing in this file should surprise
anyone reviewing it later.

---

## Test suite runs against SQLite in-memory by default

- **Where:** [`apps/api/phpunit.xml`](../apps/api/phpunit.xml) — `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`.
- **What we accepted in Sprint 1:** the Pest suite spins up an SQLite in-memory database for every test run. This is fast, hermetic, and requires no docker dependency from CI. Production runs PostgreSQL 16 (per [`docs/00-MASTER-ARCHITECTURE.md`](00-MASTER-ARCHITECTURE.md) §3).
- **Risk:** subtle SQL bugs invisible in SQLite — `jsonb` operators, full-text search, GIN indexes, window-function variants, `INSERT ... ON CONFLICT` quirks, partial indexes, `NULLS FIRST/LAST` ordering. Code that exercises these features can pass tests under SQLite and fail in production.
- **Mitigation today:** Sprint 1 migrations and models stick to driver-agnostic Laravel column types (`json()`, `ipAddress()`, `ulid()`, `char()`) and avoid raw Postgres SQL. The full migration set is also exercised against the live local Postgres on every chunk closure (see chunk-1 verification log).
- **Triggered by:** any sprint that adds Postgres-specific operators (`@>`, `?`, `?|`, `to_tsvector`, `similarity()`), index types (`gin`, `gist`, `brin`), partial indexes, or generated columns.
- **Resolution required by Sprint 8** at the latest. CI must add a Postgres-targeted test job that runs the same suite against a Postgres 16 service container; both jobs become required status checks. Until then, tests that rely on Postgres-specific behaviour must `markTestSkipped()` under SQLite and provide a Postgres-only counterpart. Sprint 8 Postgres-CI also upgrades `audit_logs.ip` `varchar(45)` → `inet` and any `json` → `jsonb` columns non-destructively (expand/migrate/contract per [`docs/08-DATABASE-EVOLUTION.md`](08-DATABASE-EVOLUTION.md)), since audit data will exist by then.
- **Owner:** Sprint that first introduces Postgres-specific SQL.
- **Status:** open.
