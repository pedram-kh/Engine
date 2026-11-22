# PL-2.4 Stage 1 (backend): review

Branch `pl`. Stage 1 (backend) was accepted. Stage 2 (web) and addition A1 are below; Pedram walked it (16 steps, all passed).

## Production posture

- Schema-only migrations, each with `SET LOCAL lock_timeout = '5s'`, no data write, no backfill (test MK8 pins both).
- The PL migrations were **edited in place** (frozen-migration rule, Part 1). Production has not run them; Pedram's
  local `catalyst` database was **not touched** (no rollback, no migrate, no read).
- Flags unchanged: markets belong to the wave and follow `waves_enabled`.

## Rebuilding a stale local database

A database that ran the OLD PL files has no `wave_markets` and carries the old `market` column. The freshness test
(`PlSchemaFreshnessTest`) fails with a message that points here. For a throwaway database only:

```
cd apps/api
php artisan migrate:reset --force --path=database/migrations/2026_11_17_100000_create_waves_table.php --path=database/migrations/2026_11_19_100000_create_wave_steps_table.php --path=database/migrations/2026_11_19_100001_add_steps_provisioned_at_to_waves.php --path=database/migrations/2026_11_20_100000_add_usp_audience_title_to_waves.php --path=database/migrations/2026_11_20_100001_create_wave_usps_table.php --path=database/migrations/2026_11_20_100002_create_wave_personas_table.php --path=database/migrations/2026_11_20_100003_create_wave_persona_angles_table.php --path=database/migrations/2026_11_21_100000_add_market_unique_to_wave_personas.php --path=database/migrations/2026_11_21_100001_create_wave_matrix_cells_table.php --path=database/migrations/2026_11_21_200001_create_wave_concepts_table.php --path=database/migrations/2026_11_21_200002_create_wave_concept_usps_table.php --path=database/migrations/2026_11_21_200003_create_wave_concept_images_table.php --path=database/migrations/2026_11_21_200004_create_wave_concept_links_table.php --path=database/migrations/2026_11_21_200005_create_wave_concept_decisions_table.php
php artisan migrate --force
```

(The reset list is the 16 PL files plus the two market files, in this order; run against a database you can lose.)

## Locks (PostgreSQL, throwaway `pl24_locks`, `php artisan migrate`, lock mode read from `pg_locks` per migration)

| Migration                                            | Time             | Lock on an existing table                                      |
| ---------------------------------------------------- | ---------------- | -------------------------------------------------------------- |
| 2026_11_17_100000 create waves                       | 23 ms            | agencies, brands, campaigns, users: SHARE ROW EXCLUSIVE        |
| 2026_11_17_100001 create brand_files                 | 15 ms            | agencies, brands, users: SHARE ROW EXCLUSIVE                   |
| 2026_11_18_100000 add campaign_type                  | 2 ms             | campaigns: ACCESS EXCLUSIVE                                    |
| 2026_11_18_100001 add stage_key                      | 2 ms             | board_columns: ACCESS EXCLUSIVE                                |
| 2026_11_19_100000 create wave_steps                  | 19 ms            | agencies, users: SHARE ROW EXCLUSIVE                           |
| 2026_11_19_100001 add steps_provisioned_at           | 2 ms             | none                                                           |
| 2026_11_20_090000 create wave_markets (new)          | 17 ms            | agencies: SHARE ROW EXCLUSIVE                                  |
| 2026_11_20_090001 create wave_market_countries (new) | 12 ms            | agencies: SHARE ROW EXCLUSIVE                                  |
| 2026_11_20_100000 add usp_audience_title             | 2 ms             | none                                                           |
| 2026_11_20_100001 wave_usps                          | 14 ms            | agencies: SHARE ROW EXCLUSIVE                                  |
| 2026_11_20_100002 wave_personas                      | 16 ms            | agencies: SHARE ROW EXCLUSIVE                                  |
| 2026_11_20_100003 wave_persona_angles                | 15 ms            | agencies: SHARE ROW EXCLUSIVE                                  |
| 2026_11_21_100000 market unique on personas          | 2 ms             | none                                                           |
| 2026_11_21_100001 wave_matrix_cells                  | 18 ms            | agencies: SHARE ROW EXCLUSIVE                                  |
| 2026_11_21_200001..200005 concept tables             | 13 to 22 ms each | agencies (and users on images, decisions): SHARE ROW EXCLUSIVE |

The times are on an EMPTY database, so they say nothing about a large `agencies` or `users` table; the lock MODE is the
fact that carries over. SHARE ROW EXCLUSIVE blocks writes to that table (reads go on) for the length of the migration
transaction; with the 5 s timeout a busy table makes the migration give up rather than queue writes behind it. None of the
new files takes a lock stronger than that on an existing table; the two ACCESS EXCLUSIVE rows are PL-1 migrations that
this change did not touch. Not measured: duration on a production-sized table.

## Differences from the plan

1. Name key is an expression index on `lower(name)` (plan §1.1 and G2), as the plan's correction list says.
2. A market's wire id is its ulid; the web keeps short codes in its screens and a bridge (`marketIds.ts`) translates at
   the strategy API boundary. This is the minimal change that keeps the tip consistent; Stage 2 replaces it.
3. Nested transaction (savepoint) around the market replace, because PostgreSQL aborts the outer transaction on a key
   violation that is later mapped to a 422.
4. `markets_renamed` is always present in the `wave.updated` payload after-block (empty list when nothing was renamed).
5. Wave-delete RESTRICT on `wave_markets` was first only protected by the country rows; test MK6b now pins it alone.
6. Playwright is run serially (`--workers=1`) for these specs: with `--workers=2` on the one shared `catalyst_e2e`
   database two specs raced (5 failures that did not reproduce serially). 22 of 22 pass serially. Worth a look before
   Stage 2 adds more specs.

## Gates run

- Pest SQLite full: 5235 passed, 56 skipped, 0 failed (29906 assertions).
- Pest PostgreSQL full (throwaway `pl24_test`, `DB_*` unset afterwards): 5291 passed, 0 failed (30191 assertions).
  The Audit, Erasure and Tenancy folders are inside both full runs.
- Pint: pass. Larastan: no errors (no ignore or cast added).
- ESLint and vue-tsc on `apps/main`: clean. Vitest full on `apps/main`: 5427 passed, 2 failed, both in
  `tests/unit/native/appIconAssets.spec.ts` (pixel comparison of an icon and a splash; not touched by this change).
- Playwright (chromium, serial): wave-dialog-menus, wave-tracker, strategy-workspace, strategy-matrix,
  strategy-matrix-manual, pl23-concepts: 22 of 22.
- Break-reverts: `docs/reviews/pl-2-4-break-reverts.md`.

## Not read / not run

- The mobile Playwright project and the other Playwright specs (they do not create waves or touch markets) were not run.
- No browser walk (Stage 2). No new UI.
- Lock duration on production-sized tables: not run.
- `apps/api` Pest with `--parallel`: not run (serial only, WORKING-PROCESS §4.1).

# Stage 2 (web)

## Stage 1 differences accepted at review

- The name key is an expression index on `lower(name)`.
- The market replace runs inside a savepoint.
- `markets_renamed` is always present in the `wave.updated` payload.
- Playwright runs serially, only because the e2e database is shared.

## Fidelity: frame 13:4000 (New wave) against the build, 1600 wide and 390 wide

Measured in a real browser on the walk data. Screenshots are in `/tmp/pl24/shots/`.

| Element                                                  | Frame                                            | Built                                                   | Kind                                        |
| -------------------------------------------------------- | ------------------------------------------------ | ------------------------------------------------------- | ------------------------------------------- |
| Modal width                                              | 640                                              | 640                                                     | match                                       |
| Title                                                    | 20/600                                           | 20/600                                                  | VISUAL, fixed                               |
| Brand line                                               | 13, muted                                        | 13, rgb(160,160,168)                                    | VISUAL, fixed                               |
| Close X                                                  | 22px                                             | 22px, 40px target                                       | VISUAL, fixed                               |
| Name and go-live                                         | side by side, gap 14                             | same, one column below 560px                            | VISUAL, fixed                               |
| Markets label                                            | 12/500                                           | 12/500                                                  | VISUAL, fixed                               |
| Market chip                                              | radius 8, 6/10, 13/500, green 10% and 50% border | same                                                    | VISUAL, fixed                               |
| Country code badge                                       | 10/700, radius 4, 1/5                            | same                                                    | VISUAL, fixed                               |
| Add chip                                                 | dashed, radius 8, green text                     | same                                                    | VISUAL, fixed                               |
| Footer bar                                               | `#1b1b1d`, top border, 16/24, gap 10             | rgb(27,27,29), same                                     | VISUAL, fixed                               |
| Card fill                                                | `#151516`                                        | `#232326` (surface-bright token)                        | TOKEN, ruling 11                            |
| Card radius and shadow                                   | 14, `0 24 64 .6`                                 | 10 (`--radius-xl`), overlay token                       | TOKEN, ruling 11                            |
| Cancel border                                            | `#2e2e33`                                        | rgb(58,58,64) outline token; height 40 against about 39 | TOKEN                                       |
| Field style                                              | label above, 12/500, box about 39                | Vuetify outlined, floating label, height 48             | VISUAL, app-wide, not fixed, needs a ruling |
| "Start from: copy previous wave / start fresh"           | present                                          | absent; PL-1 campaign radios kept                       | FUNCTIONAL (PL-3 and later)                 |
| Market builder card (name, code, hint, countries)        | not in frame                                     | built                                                   | FUNCTIONAL, left as built                   |
| Tabs show names, truncate at 180px, info button, tooltip | frame bar style                                  | same bar style plus the additions                       | FUNCTIONAL                                  |

The field style difference is app-wide (every form uses the same theme). Ruled on after the walk: kept, see "Rulings after the walk".

## Interaction checks

- Dialog open, close and quick reopen, every menu and the countries list: `wave-dialog-menus` (2 of 2).
- Countries list: hover with a mouse, tap in touch emulation, and Tab then Enter all open it. Escape closes it and focus returns to the info button.
- Hostile market name: renders as text (0 `img` elements), truncates, full name in the tooltip and the list. No `v-html` (`noVHtml.spec.ts`).
- No sideways scroll at 1600 or 390.

## Flag-off

- Agency target 126 and creator target 146: identical to the baseline, comment nodes included, 0 page errors.
- Waves ON, strategy OFF, differs from PL-1 only here:
  - The New wave dialog: header with close X and restyled title and brand line; name and go-live side by side; a Markets label; market builder cards instead of the chip picker; restyled chips; footer bar; outlined Cancel.
  - The wave page chips and the Brand waves table show market names and an info button.
- Nothing else differs. The `wave-tracker` and strategy-off Playwright specs pass.

## Gates run (Stage 2)

- Vitest `apps/main`: 5450 passed, 2 failed. Both are in `tests/unit/native/appIconAssets.spec.ts`, which failed the same way at Stage 1 and is untouched.
- Vitest `api-client`: 236 passed.
- Pest SQLite: 5239 passed, 56 skipped. Pest PostgreSQL (`pl24_test`): 5295 passed. Both ran before the last seeder refactor; after it only the seeder test (4 of 4), Pint and Larastan were rerun.
- Playwright, chromium, serial: 22 of 23 in the full run; the one failure was `wave-dialog-menus`, fixed and rerun 2 of 2. Longest test 14.1 s.
- vue-tsc clean. ESLint 0 errors, 2 old `v-html` warnings in untouched files. Pint pass. Larastan 0 errors.
- Break-reverts: 49 web gates, each red then green, in `pl-2-4-break-reverts.md`.

## Not run

- Pest full suites after the final seeder refactor.
- Firefox and WebKit Playwright projects.
- Real-device touch (touch emulation only).

# Walk result and addition A1 (Pedram, 2026-11-22)

## Walk result

Pedram walked it locally on `pl24_walk`, steps 1 to 16: **all passed**. The one addition he asked for is A1.

## Rulings after the walk

- **Dialog field style.** The platform's outlined fields with floating labels are kept. The frame's label-above style is
  not applied to this dialog alone. It stays a deliberate VISUAL difference from frame 13:4000 (see the fidelity table).
  Tech-debt row 51 points at the style guide Jordan is supplying.
- **Accepted as built:** the market builder, the tabs by name with the countries list, and the New wave dialog's changed
  look with strategy OFF.

## A1: persona codes can follow a short-code change (option B)

This amends Q6 (an edited short code left existing persona codes alone). That stays the default.

- **What the user sees.** In the wave edit dialog, once an existing market's short code is changed to a valid one, a tick
  box appears under it, unticked: "Also update the codes of this market's personas", with a note. It is offered only when
  strategy is on and only on edit (not on create, not for a market that is not saved yet, not if the code is put back).
- **What the server does.** With the box ticked, personas of THAT market whose code is exactly the old short code followed
  by their slot number (UKI1, UKI2) are renamed to the new code with the same number. A code typed by hand, or any code
  that is not exactly old code + slot (for example `UKI3A`), is never changed. A rename whose new code is already used in
  that market is skipped. Unticked, nothing changes, as before.
- **The result.** The edit answers with `meta.persona_codes` = `renamed`, `skipped` (the new code was taken) and
  `kept_custom` (not the standard code). The wave page shows them in a closable message until closed.
- **One transaction under the wave lock.** The rename runs inside `WaveMarketService::replace`, inside `WaveService::update`'s
  transaction on the locked wave row, through a third method on the `WaveMarketGuard` contract (Strategy owns the persona
  table). A refusal anywhere rolls back the market edit and the renames together.
- **Audit.** `wave.updated` carries `persona_codes_renamed` and `persona_codes_skipped` (counts) and `persona_codes_markets`
  (market ulids), always present, registered in `AuditPayloadKeys`. No code and no name is in the payload.
- **Request.** `markets.*.rename_persona_codes` (boolean, edit only; sending it on create is refused).
- **Roles.** Admin and manager only (the wave update gate). Staff get 403. Another agency's wave is 404; nothing changes.
- **Locales.** Three new keys (`renamePersonaCodes`, `renamePersonaCodesHint`, `personaCodesResult`) in all 24 locales,
  pinned by `waveKeys.spec.ts` (now 215 keys).
- **Tests.** Pest `WaveMarketsApiTest` A1-1 to A1-8: standard codes renamed; hand-typed and `SK3A` kept; collision skipped;
  unticked and not sent; ticked with an unchanged code; another market never touched; staff refused and other agency
  not found; audit with ids and counts only; the flag must be boolean and is refused on create. Vitest for the draft
  rules, the builder tick and the dialog payload and result. Playwright `pl24-markets` A1 (5.6 s): tick offered, unticked
  keeps `UKI1`/`UKI2`, ticked renames to `IRL1`/`IRL2`, result shown. Break-reverts A1 to A18 and P5 to P7 are in
  `pl-2-4-break-reverts.md`.
- **Browser, wide and narrow.** `/tmp/pl24/shots/codes-tick-1600.png` and `codes-tick-390.png` (the dialog on Markets Wave 1
  with the code of "UK & Ireland" typed over; not saved). No sideways scroll at either width.
- **Flag-off.** The page's result message is drawn with a `v-for` over zero or one entries, so with nothing to say no node
  (not even a comment) is added. Agency 126 and creator 146 targets identical to the baselines.

## Close-out gates (after the last seeder refactor and A1; these replace the Stage 2 figures above)

- **Pest SQLite, full:** 5247 passed, 56 skipped (29976 assertions).
- **Pest PostgreSQL, full** (throwaway `pl24_test`, dropped and recreated first): 5303 passed (30261 assertions).
- **Vitest `apps/main`:** 5459 passed, 2 failed. Both are `tests/unit/native/appIconAssets.spec.ts` (tech-debt row 43):
  failed locally, known, green on CI. Nothing else failed.
- **Vitest `apps/admin`:** 754 passed. **`packages/api-client`:** 236 passed.
- **Playwright, chromium, serial, 25 tests** (`pl23-concepts`, `pl24-markets`, `strategy-matrix-manual`, `strategy-matrix`,
  `strategy-workspace`, `wave-dialog-menus`, `wave-tracker`): 25 passed on the second full run; every test under 15 s
  (longest 14.1 s). The first full run had one failure, `strategy-matrix` "with strategy off the matrix address leaves for
  the wave" (a redirect timing out at 6.2 s); it passed alone (4.2 s) and in the second full run. Not reproduced, not
  explained: a timing flake of the redirect after the flag read.
- **Pint** pass, **Larastan** no errors, **vue-tsc** (`apps/main`, `apps/admin`) clean, **ESLint** 0 errors (2 old `v-html`
  warnings in untouched files).
- **Flag-off walk:** agency 126 targets identical, 0 page errors; creator 146 targets identical.
- **Pest runs of this chunk's targeted files** (the A1 tests and the break-revert Pest gates) ran on a PostgreSQL
  throwaway database (`pl12_test`) because a database variable from an earlier session was still exported in the shell. It
  is not Pedram's `catalyst` database, and the full SQLite run above was repeated with the variables cleared. The
  Waves and Strategy folders were rerun on SQLite: 442 passed, 20 skipped.

## Not run

- Firefox and WebKit Playwright projects.
- Real-device touch (touch emulation only).
- A rollback test that forces a failure after the persona renames to show the market edit rolls back with them (the
  rename shares the transaction by construction; no test pins it).
- A1 is not offered in the New wave dialog, the Brand page or any other place; only the wave edit.
- Production: nothing read, nothing run. No PL migration has been run there (read-back not seen by Cursor).
