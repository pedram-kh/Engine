# PL-1.1 review: brand client files and waves

**Branch:** `pl` (never `main`). **Flag:** `waves_enabled`, default OFF. **Status:** built and committed locally. Pushed tip is `998d0922` (CI run 44017150449, all four jobs green). Everything after it is committed locally and **not pushed** until Pedram has walked (`docs/reviews/pl-1-1-walk.md`).

Related files: `pl-1-1-plan.md` (the approved plan and its corrections C1 to C6), `pl-1-1-break-reverts.md` (every gate, broken and restored), `pl-1-1-walk.md` (the walk script and commands).

## What was built

- **Backend (Stage 1):** flag and `Waves` module, `waves` and `brand_files` tables, wave API (list, create, campaign options, show, edit), brand files API (list, presign init, complete, signed download, delete), policies, audit actions, erasure registry rows, `/me` `features.waves`, admin flag toggle.
- **Web (Stage 2):** api-client types (`waves?: boolean` on the user features, wave and brand-file types); `brandFiles.api.ts`, `waves.api.ts`; Client files and Waves panels on the brand page; `NewWaveDialog` (create and edit); `MarketPicker`; `BrandFileUploadDialog`; `WaveDetailPage` and route `waves.detail`; `wave` status tone; the two-column modifier in `agency.css`.
- **Strings:** 70 new keys in all 24 locales (23 translations plus English), `waveKeys.spec.ts`.
- **Walk seeders:** `Pl11WalkSeeder` and `Pl11WalkCleanupSeeder`, local and testing only; the cleanup does not touch the flag (C5).

## CI history on this chunk

- **Run 447** (`0029e79e`) was red: `AuditPayloadKeysGuardTest` found four audit payloads it could not read statically (`WaveService` and `BrandFileController`, `before` and `after`). Cause: my miss, the Erasure folder was not run locally.
- **Fix, preferred form:** the four payloads are now literal arrays with literal keys, decided in `AuditPayloadKeys::notPersonalKeys()`: `go_live_on`, `markets`, `file_type`, `mime_type`, `original_filename`, `size_bytes` are new; `name`, `campaign_id` and `status` were already decided. No `dynamicPayloadFiles()` entry was added, so no tech-debt row. Reason: the literal form is checkable by the guard and tighter; the old `toArray()` form also carried internal ids and the storage key. `name` is also a scrub key (user snapshots); that decision stands and is harmless here. Two key-set pin assertions (tests B10) guard the payloads.
- **Run 448** (`998d0922`): Backend, Frontend, E2E admin, E2E main all success.
- **Process change:** the Erasure test folder is now a local gate for any chunk that adds an audit call, a table or a column (`WORKING-PROCESS.md` §4.1 item 6, and the plan's gate list).

## Tests and gates (local, this chunk)

| Gate                                                                                                                             | Result                                                                                                                                                            |
| -------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Pest, `tests/Feature/Modules/Waves`                                                                                              | 77 passed (includes the seeder tests and key-set pins)                                                                                                            |
| Pest, `tests/Feature/Modules/Erasure` (whole folder, includes the audit guard)                                                   | 336 passed, 1 skipped (the skip is pre-existing)                                                                                                                  |
| Vitest, touched set: `tests/unit/architecture`, `src/core/i18n`, `src/modules/brands`, `src/modules/waves`, `src/modules/agency` | 92 files, 1115 tests passed                                                                                                                                       |
| New web specs                                                                                                                    | `MarketPicker`, `BrandWavesPanel`, `NewWaveDialog`, `BrandClientFilesPanel` (with `BrandFileUploadDialog`), `WaveDetailPage`, `BrandDetailPage.waves`, `waveKeys` |
| `BrandDetailPage.spec.ts` (existing, unmodified)                                                                                 | passed inside the set above                                                                                                                                       |
| Playwright `brands.spec.ts` and `permissions.spec.ts`, flag OFF, serial (`--workers=1`)                                          | 4 passed                                                                                                                                                          |
| Pint (touched PHP, `app/Core`, seeders, tests)                                                                                   | passed                                                                                                                                                            |
| Larastan (touched PHP and tests)                                                                                                 | no errors                                                                                                                                                         |
| `vue-tsc --noEmit` (apps/main)                                                                                                   | clean                                                                                                                                                             |
| ESLint (touched web folders)                                                                                                     | clean                                                                                                                                                             |
| Prettier (touched)                                                                                                               | clean (one older untouched doc, `sprint-4-chunk-2-review.md`, already fails the check)                                                                            |

Break-reverts: 47 Stage 1 rows, 4 audit-fix rows, 35 Stage 2 rows, all in `pl-1-1-break-reverts.md`. Every gate went red on its mutation and the tree was clean after each restore. One gate failed its first break-revert (the cleanup seeder deleting the flag row went unnoticed because the test's array store hid the delete); the test was changed to use the database store and the mutation re-run red.

## Walk result (Pedram, 2026-11-17)

**PASSED: all 14 steps, local**, including the first run of upload, signed download and delete against real object storage (local MinIO). The upload flow was therefore exercised for real only on Pedram's machine; the earlier statement that it had not been run against real storage is now closed for MinIO. It has not been run against any deployed storage.

## Review fixes after the walk

- **F1, replayed upload.** Confirming the same `upload_id` twice hit the `storage_key` unique index and returned 500. The row create now catches the unique violation and answers 422 `brand_file.complete_failed`: one row, one audit row, the object left alone. Test: `F1` in `BrandFileApiTest`. Break-revert: red.
- **F2, set-link race.** `WaveService::update` now runs read, decide and write inside one transaction on a row-locked copy of the wave (`lockForUpdate`), and re-applies the link rule to the locked row. A second request holding a stale wave gets 422 `wave.campaign_link_locked` for a different campaign, or a no-op (no audit row) for the same one. The audit `before` is taken from the locked row. Tests: two `F2` tests in `WaveCampaignLinkTest`, using a stale model. **Limit, as with the earlier race test:** the tests run on SQLite, which ignores row locks, so they prove the re-read and the rule, not the lock. Removing `lockForUpdate()` alone leaves them green (recorded in the break-revert file). The lock is not proved by any test; two real concurrent requests against PostgreSQL were **not run**.
- **F3, blank wave name.** Tests added for create and edit with spaces, a tab and newline, and an empty string. It **was already refused** (422, nothing written, no audit row): the request pipeline trims and nulls the value before the `required` and `min:1` rules. No code fix was needed. Break-revert: red (3 tests).

## Flag-off proof (agency walk, brand-page targets)

Command: `node tools/walk/walk.mjs --set agency --only brand --compare ../../docs/reviews/ah-115-agency-baseline/baseline.json`, run alone under WORKING-PROCESS §4.1 (leftovers killed, nothing else running). `--only brand` selects exactly the 16 brand-page targets (brands, brand-create, brand-detail, brand-edit; dark and light; 1280 and 390 wide). The walk uses its own mock API with no `features.waves`, so the flag is OFF by construction.

- **First run: 8 differences, all on `brand-detail`.** `domHash` and `domLen` (+11 characters) on the 4 targets; style hashes and screenshots identical. 11 characters is the length of `<!--v-if-->`. The `v-if` on the new side column leaves a comment node in the DOM when false. The walk caught a real, if invisible, difference that the unit test (which checked for panels, not for comment nodes) did not.
- **Fix:** the side column is now a one-item `v-for` over an empty list when the flag is off, which leaves no node. A DOM check for comment nodes was added to the W1 flag-off spec (red under the old `v-if`).
- **Final run, committed tree: `COMPARE OK: 16 targets identical to baseline.json`**, 0 page errors.
- **Not done:** a recording of the pre-chunk runtime made on this machine (as the creator restyle review did). The comparison is to the committed AH-115 baseline. The first run showed that baseline had not drifted anywhere else on these 16 targets (every other hash matched), so the two are equivalent for these targets. The other 110 targets of the agency walk were **not run** (C2).
- The agency CSS and status-tone edits did not change a single computed style or screenshot of these 16 targets.

## Clean-up (local)

- **`waves_enabled` is ON locally.** I did not flip it. The Pennant table holds a global row with value true. Pedram said it was switched off in step 14; it is not. Switch it off by hand in the admin app, or tell me to.
- I removed one stray `waves_enabled` row of my own (scope `x`, value false, created by a tinker check of the default during the setup of the walk). Local database only.
- `Pl11WalkCleanupSeeder` ran: 0 walk users, 0 walk agencies, 0 waves, 0 file rows remain.
- **Objects uploaded during the walk are still in the local MinIO bucket.** The cleanup seeder removes rows, not objects. They are local and harmless; they can be cleared in the MinIO console (http://localhost:9011).
- The dev stack (`pnpm dev`) is stopped. Docker containers are still up.
- A temporary git worktree used for a pre-chunk recording (`/tmp/wt-base`) was abandoned; its removal was blocked, so it may still exist in `git worktree list`. It is outside the repo.

## Accepted deviations (recorded, as ruled)

1. **Validation in the controller, after the flag check.** The wave and file controllers validate the body inline, after the flag 404 and before the policy where the plan said request classes. The flag check must come first so a flag-off request never reveals validation behaviour.
2. **Ulid lookups in the controller.** Waves and files are found by ulid (plus agency and brand) in the controller, answering 404 for anything outside the tenant, because route-model binding runs before the tenancy context.
3. **`CurrentAgencyRole`** (new, `app/Core/Tenancy/`) reads the role from the tenancy-context agency, not the user's first membership, so a user in two agencies gets the right role.
4. **The race test's limit.** The "second wave for a campaign" race cannot be run against a real concurrent writer in the test database: the competing row rolls back with the request transaction. The test proves the exception mapping (the single path to 422 `wave.campaign_already_linked`) by raising the unique violation after the pre-check point; it does not prove two simultaneous requests. The partial unique index itself is proved by `WaveSchemaTest`.

## Deviations from the plan found in Stage 2

- **Q8 deviations (as ruled):** the existing header pill is kept and no Brand name or Status rows are added to the details card; no Client or Catalyst uploader chip (the uploader's name is shown); "Updated" is a date, not "Today" or "Yesterday".
- **Date field:** the go-live field is a native date input, not the frame's "Nov 7, 2026" text field.
- **Markets in the Waves table** are shown as ISO codes (first three, then "+N"), as the plan's W2 says; the wave page and the picker show the country names (the picker also shows the code badge).
- **Two-column layout** wraps under the details card on a narrow window using `flex-wrap`, not a media query: the agency CSS scoping spec forbids at-rules.
- **`agency-frozen` re-pin.** `agencyStatusTones.ts` (the `wave` kind) and `agency.css` (the two-column modifier and panel classes) are content-hash pinned by `agency-frozen.spec.ts`. Both were edited on purpose, and both hashes are re-pinned in the same commit. With the flag OFF the brand page markup is unchanged (W1 pins it).
- **Dialog and page closes:** the plan listed a `dialog.close` string; it was not needed and is not shipped.
- **Seven `SAME_AS_ENGLISH_BY_DESIGN` pairs** in `waveKeys.spec.ts`: "Brief" in el, hu, it, pl, ro, sv; "Brand" in el and ro (the glossary keeps the loanword); "Type" in fr.

## Screens built without a design (from the design system)

Each is named here, as the standing rule requires: the **upload dialog** (`BrandFileUploadDialog`), the **New wave and Edit wave dialog** (`NewWaveDialog`, including the campaign field in its editable and read-only states and the market picker menu), the **wave page** shell (`WaveDetailPage`), the **delete-file confirmation**, and the **loading, empty and error states** of both panels and of the wave page. The two panels and the brand page layout follow frames 13:2 and 13:4000 as far as the plan describes them.

## Production posture

**PROD-DATA RISK: NONE.**

- The flag `waves_enabled` is OFF by default and nothing in this chunk turns it on. With it OFF: every wave and file endpoint answers 404 `not_found`, `/me` `features.waves` is false, the brand page renders exactly as before (W1 and the unmodified `BrandDetailPage.spec.ts`), and the wave route redirects to the brand.
- The migrations are schema-only: two new tables, `waves` and `brand_files`, empty on deploy. No existing table or column is altered, no backfill, no data change. Migrations were run on the local and test databases only.
- The Campaigns module is not edited (an empty diff of `app/Modules/Campaigns` over the range from `5968f4f8`).
- Erasure: both tables are registered as `NotApplicable` (agency-owned, no creator data); the audit payloads are literal and decided.
- The walk seeders refuse to run outside local and testing (S3) and the cleanup never touches the flag.
- Uploaded client files have **no virus scan** yet (tech-debt row 40, which must be solved before PL-4, when clients upload). In PL-1.1 only agency admins and managers upload.

## What was NOT run, and what was NOT read

- **Real storage:** run once, by Pedram, against local MinIO during the walk (all steps passed). Not run against any deployed storage.
- **Not run:** the full Pest suite, the full Vitest suites (apps/main outside the touched set, apps/admin, packages/api-client), the 126-target agency walk (correction C2), Larastan on the whole codebase, Pint `--all`. CI ran the full suites on `998d0922`, which does not include the web work.
- **Not run:** CI on the unpushed commits.
- **Browser:** the layout was checked by Pedram on the walk. I have not viewed it myself.
- **Not read:** the Figma frames again in Stage 2 (the plan's description of them was used).
