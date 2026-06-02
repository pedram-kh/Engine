# Sprint 4 — Chunk 4 Review

**Status:** Closed.

**Author:** Cursor (build + self-review draft).

**Scope:** Structured contract-acceptance record — build the spec'd `contracts` table and route the flag-OFF click-through accept through it, so acceptance is **versioned + timestamped + attributed + unified** with the future vendor path. **One chunk, backend-only** (no frontend — version + IP/UA are server-known). Pulls forward the acceptance-record foundation of the e-sign workstream (no vendor).

**Reviewed against:** `03-DATA-MODEL.md §8` (the `contracts` table shape, `:570–602`) + `:204` (the `signed_master_contract_id` FK), `05-SECURITY-COMPLIANCE.md §4` (encryption-at-rest), `20-PHASE-1-SPEC.md` Step 8, `02-CONVENTIONS.md` (modular monolith, ULID, naming), `07-TESTING.md` §5.17 (defense-in-depth) + §5.35 (break-revert), `08-DATABASE-EVOLUTION.md` §7.1–7.2 (migration round-trip), the locked decisions **D-c4-1…D-c4-7**, and the four plan-pause divergences **A–D** approved by the user.

---

## Divergences from the kickoff (all surfaced at plan-pause, all approved)

- **A — Deferred the hard DB-level FK on `creators.signed_master_contract_id`** (D-c4-1 asked to add it). The column is currently **multi-meaning**: three writers stuff incompatible values into it — the new click-through path writes a **real `contracts.id`**, `ProcessEsignWebhookJob:111` writes an **`integration_events.id`**, and `WizardCompletionService:133` writes a **unix timestamp**. A hard FK would violate on the two vendor sentinels — converting them is vendor-envelope work explicitly scoped OUT. We added the Eloquent relation + write a real `contracts.id` on the click-through path, and **deferred the constraint** to the vendor chunk. Logged **loudly** in `tech-debt.md` (names both sentinel writers + the multi-meaning-column note + the ordered resolution).
- **B — `version` stored as integer** (spec `:583` is `integer`; `CURRENT_VERSION = '1.0'` is a string). The integer column holds `1`; the precise `'1.0'` is preserved in `signed_signature_data.version`. The string↔integer mapping lives in **one place** — `ContractTermsRenderer::versionToInteger()` / `currentVersionNumber()` — so a future `'2.0'` bump can't drift.
- **C — `signed_signature_data` is `text` + `encrypted:array`** (spec says `jsonb`; D-c4-5 mandates encrypt-at-rest — the two conflict, an encrypted blob isn't valid jsonb). Follows the established convention (`creator_tax_profiles.address`). Continuity is unaffected: the queryable `version` lives in its own integer column.
- **D — Snapshot `title` + `body_markdown` at accept time** (both NOT NULL in spec). Snapshots the **raw markdown source** (not rendered HTML) via a new `ContractTermsRenderer::source()` method that leaves `render()` untouched. Better evidence than the kickoff specified, and keeps the spec columns honest rather than reshaping them to nullable.

`status = signed` + `signature_provider = 'internal'` for the click-through (the spec enum has no "accepted" value; the master agreement §10 makes the click-through "a binding electronic signature with the same legal effect").

---

## What was built

### Schema (D-c4-1)

`2026_05_17_100000_create_contracts_table.php` — the **full** `03-DATA-MODEL.md §8` shape (not the subset the kickoff named), so the vendor adapter inherits the envelope columns rather than re-migrating:

- `id`, `ulid` (unique), `agency_id` (FK `agencies`, RESTRICT, null), `kind`, `subject_type`/`subject_id` (polymorphic), `template_id` (nullable, **FK-less** — `contract_templates` is unbuilt; documented), `version` (integer), `title`, `body_markdown`, `body_pdf_path`, `signature_provider`, `signature_envelope_id`, `status`, `sent_at`, `signed_at`, `signed_by_creator_id` (FK `creators`, nullOnDelete), `signed_signature_data` (text/encrypted), `expires_at`, `created_by_user_id` (FK `users`, nullOnDelete), timestamps, soft deletes.
- Indexes `idx_contracts_subject (subject_type, subject_id)` + `idx_contracts_status`.
- Runs on Postgres (CI) + SQLite (local) — no driver-specific types. Forward+backward round-trip verified (`migrate:rollback --step=2` → `migrate`, clean).

`Contract` model (`Modules/Creators/Models`, D-c4-6) — ULID, `kind`→`ContractKind` / `status`→`ContractStatus` / `signed_signature_data`→`encrypted:array` casts, `signedByCreator()` + `createdBy()` relations, class constants (`SUBJECT_CREATOR`, `PROVIDER_INTERNAL`, `METHOD_CLICK_THROUGH`). `ContractStatus` enum (spec values `draft|sent|signed|declined|expired|superseded`) + `ContractKind` enum (`master_universal|master_agency|per_campaign`) + `ContractFactory`.

`Creator::masterContract()` — `belongsTo(Contract, 'signed_master_contract_id')`, no DB constraint yet (divergence A; docblock'd).

### Accept path (D-c4-2/3)

`CreatorWizardService::acceptClickThroughContract()` now (within the existing transaction): reads the version **server-side**, snapshots the agreed source, creates the `contracts` row (`kind=master_universal`, `subject=creator`, `version=1`, `status=signed`, `signature_provider=internal`, `signed_at`, `signed_by_creator_id`, `signed_signature_data={method, version:'1.0', ip, user_agent, accepted_at}`, `created_by_user_id`), sets `creators.signed_master_contract_id` to the new row, keeps `click_through_accepted_at` denormalized (D-c4-3), keeps the existing `CreatorWizardClickThroughAccepted` audit. **Idempotent** — the existing guard (`click_through_accepted_at !== null`) short-circuits before any write, so a re-accept creates no duplicate row. The controller passes `$request->ip()` / `$request->userAgent()` (D-c4-5); the client sends nothing new.

### Validation (D-c4-3)

`CompletenessScoreCalculator::stepCompletion()` — contract satisfaction now keys off `signed_master_contract_id !== null` only (the `click_through_accepted_at` clause is removed; the flag-OFF branch is unchanged). The click-through path sets that FK, so the legacy timestamp is no longer load-bearing.

### Backfill (D-c4-4)

`2026_05_17_100001_backfill_click_through_contracts.php` — idempotent (guarded on `signed_master_contract_id IS NULL`), creates a `version=1` / `backfilled:true` row (no fabricated IP/UA) for any creator with a pre-chunk `click_through_accepted_at`. **No-op on current seed/dev data** (only `Sprint1IdentitySeeder` runs; it seeds no acceptance) — the logic is real and exercised by tests against synthetic pre-chunk data. Uses models so `signed_signature_data` is encrypted; `down()` removes only the `backfilled` rows + unlinks their creators.

### Frontend

**None**, as expected. The checkbox UI is unchanged; version + IP/UA are server-known.

---

## Coverage (§5.17 defense-in-depth; §5.35 break-revert, git-restore verified)

`ClickThroughContractRecordTest` (8):

- **version present + correct** (`= currentVersionNumber() = 1`) — _break-revert: drop the version write → fails._
- correct signer / timestamp / status / provider / kind / subject / creator attribution.
- **raw markdown + title snapshot** (`body_markdown` contains `# Engine C`, NOT `<h1>`).
- `signed_signature_data` carries method + ip + user_agent + version (`'1.0'`) + accepted_at.
- **`signed_master_contract_id` set + points at the new row** (`masterContract` relation resolves to it).
- **step-8 keys off the FK** (flag-ON strict path) — _break-revert: FK set + no legacy timestamp → passes; neither → fails._
- idempotent re-accept → no duplicate row.
- **continuity** — one query yields every acceptance, each carrying its version (closes the inventory's point 6).
- `signed_signature_data` (IP/UA) **not** on the creator-facing resource (`click_through_accepted_at` still is).

`ClickThroughContractBackfillTest` (3): backfills correct v1.0 row + links creator; idempotent re-run; no-op when no pre-chunk acceptance. `Sprint4ContractsMigrationTest` (2): every spec column present; `signed_master_contract_id` unchanged. `ContractTermsEndpointTest` (+2): `source()` exposes raw markdown + title + version **and** `render()` output is unchanged — _break-revert: alter the render path → the existing HTML-shape test + this fail_; the version→integer mapping.

**Full results:** `966 passed (3131 assertions)` (full API suite via Pest at `memory_limit=2G`). PHPStan: `No errors` (449 files). Pint: `passed`.

> Note: `php artisan test` (default 128M `memory_limit`) OOMs partway through an **unrelated** pre-existing test (`BulkInviteCsvParserTest` builds a 300k-row CSV); `--parallel` trips a separate pre-existing `use RuntimeException` warning in `Sprint3Chunk2InvariantsTest`. Neither involves this chunk — the targeted `Creators` + `Database` run (363) and the raised-memory full run (966) are both green.

---

## Spot-check anchors

1. **Version persisted** on the `contracts` row (integer `1`; precise `'1.0'` in `signed_signature_data`) — `ClickThroughContractRecordTest` "creates a contracts row…". Break-revert: drop the version write.
2. **`signed_master_contract_id` set + drives step-8** — "sets creators.signed_master_contract_id…" + "step-8 satisfaction keys off…".
3. **`signed_signature_data` carries method/IP/UA + is NOT creator-facing** — "records method + ip…" + "never exposes signed_signature_data…".
4. **Idempotent re-accept** — "is idempotent — a second accept creates no duplicate…".
5. **Backfill correctness / documented no-op** — `ClickThroughContractBackfillTest` (3 cases).
6. **Continuity query** (acceptances retrievable with version from one place) — "makes acceptances retrievable WITH version…".
7. **(Added, per A) Deferred-FK tech-debt entry names both sentinel writers + the multi-meaning-column note** — `docs/tech-debt.md` "⚠️ Deferred `creators.signed_master_contract_id` → `contracts.id` FK".
8. **(Added, per D) `body_markdown` snapshots raw source AND `render()` is unchanged** — `ContractTermsEndpointTest` "source() exposes the RAW markdown… without altering render()". Break-revert: alter the render path → existing render/terms test fails.

---

## Out of scope (logged at close)

- **Read-receipts** (D-c4-7) → `tech-debt.md` "Read-receipts…".
- **No e-sign vendor / no envelope handling** — the `contracts` envelope columns stay null for click-through; the vendor chunk fills them.
- **Deferred FK constraint** (A) + **`click_through_accepted_at` denormalization** (D-c4-3) → both logged in `tech-debt.md`.

## Docs updated

- `docs/services.md` — e-sign row notes the acceptance-record foundation now exists (versioned `contracts` table; the vendor adapter extends it).
- `docs/tech-debt.md` — three entries: the loud deferred-FK (multi-meaning column), read-receipts deferral, `click_through_accepted_at` denormalization.

---

## Commit pair (proposed — not committed until spot-check)

1. **feat(creators): build spec'd `contracts` table + route click-through accept through it** — migration + backfill, `Contract` model + `ContractStatus`/`ContractKind` enums + factory, `Creator::masterContract()`, `ContractTermsRenderer::source()`/version mapping, `acceptClickThroughContract()` rewrite, controller IP/UA, step-8 satisfaction, all tests.
2. **docs(tech-debt,services): log deferred contracts FK, read-receipts, denormalized timestamp** — `services.md` + `tech-debt.md`.
