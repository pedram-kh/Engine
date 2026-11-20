# PL-2.2 review record (Stage 1 backend, Stage 2 web)

## Stage 1 (backend)

Date 2026-11-20. Branch `pl`. Web is Stage 2 and not started.

## Migration rehearsal (G3: the real files through `artisan migrate`)

Database `pl22_rehearsal` (name printed first, dropped afterwards), PostgreSQL, real migration files. Rows: 40 agencies, 20 waves, 120 USPs, 320 personas, 0 angles, 0 cells.

| Migration                                                              | `artisan migrate` time                                                                                        | Locks read from `pg_locks` before commit                                                                                                                                                                  |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| M1 `2026_11_21_100000` unique (wave_id, market, id) on `wave_personas` | 7.48 ms (5.4 ms in `up()`)                                                                                    | `wave_personas`: AccessExclusiveLock (the unique index build) and ShareLock; `lock_timeout` 5 s set for the transaction                                                                                   |
| M2 `2026_11_21_100001` create `wave_matrix_cells`                      | 18.72 ms (20 ms in `up()`; a re-run took 20.63 ms; the first run on an empty database took 2.15 and 12.39 ms) | `agencies`, `waves`, `wave_usps`, `wave_personas`: RowShareLock plus ShareRowExclusiveLock (foreign-key creation); `wave_matrix_cells`: AccessExclusiveLock, ShareLock, ShareRowExclusiveLock (new table) |

The hand-typed lock table in plan section 8 is not the record; this one is. Production is PostgreSQL 17.9, the local test database is PostgreSQL 16; the rehearsal ran on 16.

## Results

- Strategy folder: SQLite 125 passed, 9 skipped (PostgreSQL-only); PostgreSQL 135 passed.
- Full API suite: SQLite 5096 passed, 48 skipped; PostgreSQL 5144 passed; Pint clean; Larastan no errors.
- api-client Vitest 235 passed; main strategy Vitest 35 passed; vue-tsc, ESLint (strategy) clean; Prettier clean on the touched web files.
- Break-reverts: `docs/reviews/pl-2-2-break-reverts.md` (30 rows, all red, all restored).

## Differences from the plan

1. The level CHECK is PostgreSQL-only; SQLite relies on the enum (MS5 skipped on SQLite).
2. USP-in-use meta is `{count, affected}` with `kind` (`angle` or `cell`) and `level` on each item.
3. `PersonaService::copy` returns `{personas, synced_skipped}`; the endpoint adds `meta.synced_angles_skipped`.
4. A minimal api-client and web rename (`confirm_count`, `meta.count`) was made in Stage 1 so the tip stays consistent; no new UI.
5. Audit key decisions and a dynamic-payload entry for `MatrixService` were added to `AuditPayloadKeys` (the guard test demanded them).
6. The new lock test L6 is sequential (two users in turn), not a true race.

## Not read / not run

- G1: closed in Stage 2 (the colours were read; see the fidelity section).
- No browser walk (no UI in Stage 1).
- The rehearsal ran on PostgreSQL 16, not 17.9.

## Stage 1 review: accepted differences (recorded as ruled)

1. The level CHECK is PostgreSQL-only; SQLite relies on the enum.
2. The USP-in-use meta is `{count, affected}` with `kind` and `level` on each item.
3. The copy endpoint reports `meta.synced_angles_skipped`.
4. The minimal web rename (`confirm_count`, `meta.count`) was done in Stage 1 so the tip stayed consistent.
5. The L6 lock test is sequential; L5 is the lock proof.
6. C1 (done): `MatrixService` writes the `WaveMatrixCellSaved` metadata with literal keys; the dynamic-payload entry is gone; every key is decided in `notPersonalKeys()`; W-06 kept; break B31 is red. PL-9 will add AI code to the same file and the guard keeps reading it.

## Stage 2 (web)

### Method

Frames 13:1375 (the matrix) and 13:1842 (agent states, which holds the level menu) were read with `get_design_context` (file `lYy90qzKk029vjoVCPExs2`, after loading the design-to-code guidance), and the frame renders were saved with `download_assets`. The built screens were opened in Chromium at 1600 x 1498 (the frame's width), at 1440, and at 390 x 844, on a throwaway database `pl22_walk` (PostgreSQL, port 5435; the connection and database name were printed before the seeder ran) with the `Pl22WalkSeeder` data, and the computed style of each element was read back. The Cursor browser could not be sized, so the screenshots came from Playwright's Chromium. Theme tokens: `#1b1b1d` surface, `#232326` surface-bright, `#2e2e33` border-color, `#ededed` on-surface, `#a0a0a8` on-surface-variant, `#6e6e76` text-tertiary, `#3ecf9e` primary. **Token note (ruling 11):** the agency theme lifts `text-tertiary` to `#8a8a92`, so every tertiary text below reads (138,138,146) instead of (110,110,118); that is an accepted difference, not repeated per row.

**G1 (colours):** the legend dots and the USP dot colours were read from the frame's SVG assets (fetched; nothing was blocked): Priority `#34c77b`, Support `#4c8dff`, Low `#6e6e76`; USP dots violet `#8b6cff`, green `#34c77b`, blue `#4c8dff`, amber `#f5a524`, teal `#3ecf9e`, pink `#f472b6`. They match the agency brand variables in dark. **Unconfirmed:** none in dark. The light-theme values (a darker step of each hue) were set by hand and not opened in a browser (see Not run).

Screenshots are outside the repo, in `/tmp/pl22/shots/`: `frame-13-1375.png`, `built-1600.png`, `side-by-side-matrix-1600.png`, `built-390.png`, `built-scroll-1440.png`, `frame-menu-13-1939.png`, `built-popover-1440.png`, `built-popover-error-1440.png`, `built-popover-390.png`, `built-popover-error-390.png`, `side-by-side-level-menu.png`, `built-hostile-1440.png`, `built-personas-synced-1440.png` (and the `-390` pairs).

### Fidelity table (frame 13:1375, 1600 wide)

| Element                                                                                                                                                       | Frame value                                                                                                                           | Built value                                                                                                 | Type                            |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- | ------------------------------- |
| Matrix table                                                                                                                                                  | fill #1b1b1d, border 1px #2e2e33, radius 10                                                                                           | (27,27,29), 1px (46,46,51), 10                                                                              | match                           |
| Header row, corner cell                                                                                                                                       | fill #232326, 230 wide, pad 18/20, "USP / PERSONA" 11 / 600, 0.6 tracking, tertiary                                                   | (35,35,38), 230, 18/20, 11 / 600, 0.6px, (138,138,146)                                                      | match (token)                   |
| Persona header cell                                                                                                                                           | pad 16/20, gap 6                                                                                                                      | 16/20, gap 6                                                                                                | match                           |
| Persona code chip                                                                                                                                             | 1px border at 60% of the colour, pad 2/6, radius 4, 11 / 700                                                                          | 1px at 0.6, 2/6, 4, 11 / 700                                                                                | match                           |
| Persona title / name line                                                                                                                                     | 16 / 600 on-surface; 12 / 400 #a0a0a8                                                                                                 | 16 / 600 (237,237,237); 12 / 400 (160,160,168)                                                              | match                           |
| "Lead with:"                                                                                                                                                  | label 12 tertiary; names 12 / 600 #34c77b                                                                                             | 12; 12 / 600 (52,199,123)                                                                                   | match                           |
| USP cell                                                                                                                                                      | pad 18/20, dot 8, gap 8, name 14 / 600                                                                                                | 18/20, gap 8, 14 / 600                                                                                      | match                           |
| Cell                                                                                                                                                          | pad 16/20, gap 10, left border 1px, top border 1px                                                                                    | 16/20, gap 10, 1px, 1px                                                                                     | match                           |
| Level pill                                                                                                                                                    | radius 6, pad 4 8 4 10, gap 6, 11 / 700, 0.6 tracking, chevron 12; Low fill rgba(110,110,118,.25); Priority fill rgba(52,199,123,.15) | radius 6, 4/8/4/10, gap 6, 11 / 700, 0.6px; Low .25; Priority .15                                           | match                           |
| Priority cell tint                                                                                                                                            | rgba(52,199,123,.04)                                                                                                                  | rgba(52,199,123,.04)                                                                                        | match                           |
| Rationale                                                                                                                                                     | 13 / 19, Low muted                                                                                                                    | 13 / 19, tertiary on Low                                                                                    | match (token)                   |
| Message-line box                                                                                                                                              | radius 6, pad 9/12, border 1.5px yellow at 80% (Priority, Support) or 35% (Low), fill 6%, 13 italic                                   | radius 6, 9/12, declared 1.5px (the browser reports 1px at 1x), .35 on Low, .06 fill, 13 italic             | match                           |
| Heading, subtitle                                                                                                                                             | 22 / 600 on-surface; 13 / 400 #a0a0a8                                                                                                 | 22 / 600; 13 / 400                                                                                          | match                           |
| Legend                                                                                                                                                        | right-aligned on its own row under the heading; dot 8, gap 6, label 12 / 600, hint 12 tertiary, gap 20                                | was on the heading row; now its own row                                                                     | VISUAL, fixed                   |
| Footnote                                                                                                                                                      | 12 tertiary, info icon                                                                                                                | 12 (138,138,146), info icon                                                                                 | match (copy differs, see below) |
| Level menu (frame 13:1939)                                                                                                                                    | 3 rows: dot 8, label 13 / 500, hint 12 tertiary; selected row tinted; panel pad 6, radius 8, shadow 0 12 32 .5                        | same rows and tint; panel pad 6, radius 8, same shadow                                                      | match                           |
| Pinned USP column                                                                                                                                             | 230 wide (the frame note: pinned)                                                                                                     | 230 wide, 140 under 720 px; stays put while the table scrolls sideways (RO, 8 personas, 1440)               | match                           |
| Page header: breadcrumb, "Bolt Food — Wave 7", status chip, Preview as client, Share with client                                                              | drawn                                                                                                                                 | the PL-2.1 workspace header ("Strategy & Scripts", "Brand · Wave")                                          | FUNCTIONAL                      |
| Title-row actions Notes (2), Comments (1), Approval                                                                                                           | drawn                                                                                                                                 | not built (no such features)                                                                                | FUNCTIONAL                      |
| Stepper statuses ("Approved", "Client approved", "4 confirmed")                                                                                               | drawn                                                                                                                                 | progress and lock text from the real state ("11 of 24 cells set", "Locked until all concepts are approved") | FUNCTIONAL                      |
| Market row: "Add market", "Script language: English + Slovak"                                                                                                 | drawn                                                                                                                                 | not built; each tab shows its set/total count                                                               | FUNCTIONAL                      |
| Agent bar ("Generated by Catalyst Agent", Show reasoning, Regenerate, Keep my edits) and the "Agent" tag on every cell, "Edited by Jess · kept on regenerate" | drawn                                                                                                                                 | not built (PL-9)                                                                                            | FUNCTIONAL                      |
| "Lead with:" text                                                                                                                                             | abbreviated ("Selection + Speed")                                                                                                     | the full USP titles joined by " + "                                                                         | FUNCTIONAL                      |
| Footnote copy                                                                                                                                                 | agent wording, "2–8", "4–6"                                                                                                           | plain wording for the built rules                                                                           | FUNCTIONAL                      |

Functional differences are left as built and listed for Pedram in the report.

### Screens built with no design

- A cell that is "Not set" (a NOT SET pill, same size as the others).
- The editor's Rationale and Message line fields, Save, Save and next, Cancel and Clear, the character counters, and every error line (the frame draws only the three-row level menu).
- The bottom sheet under 720 px.
- The empty states (no persona in a market, no USP in the wave) and the load-failed state.
- The note on a Priority or Support cell that has a hand-written angle ("kept as written").
- The persona card's synced angle ("Synced from matrix", read-only, link "Change it in the Messaging matrix"), the copy dialog's synced line, and the USP and persona delete lists with cells.
- The three agent-state panels of frame 13:1842 (empty/generate, generating, out of date) are PL-9 and were not built; only its "Cell override" menu is used.

### Real-browser checks (1440 and 390, real walk data)

- The popover opens above the page (inside the viewport once it settles; a phone sheet slides up), and open / cancel / reopen at 0, 60 and 150 ms leaves exactly one editor, with nothing carried over from the previous cell.
- A Priority cell cannot be saved without a message line; a 141-character line shows its reason in red above the buttons and Save stays off.
- A hostile string (`<img src=x onerror=alert(1)>`) in a rationale and a message line shows as plain text on the cell and on the synced angle on the persona card; no element is created, no alert fires.
- The page never scrolls sideways at 390; only the matrix scrolls.

### Differences from the plan

1. The matrix's own colours (level fills, Priority tint, the yellow line, shadows) live in `apps/main/src/modules/strategy/matrixColors.css`, not in `agency.css` (content-pinned and in the agency baseline) and not in a `.vue` file (`no-hard-coded-colors` caught the first version). The long-term home is `@catalyst/design-tokens`; this is for Pedram to decide.
2. The legend sits on its own row (fixed after the first look).
3. `strategy.matrix` was added to the pinned set in `agency-routes-agency-user-guard.spec.ts`.
4. The `Pl22WalkSeeder` writes its cells through `MatrixService`, so the synced angles are exactly what the screen produces.
5. 39 new i18n keys were added to all 24 locales with a spec (`matrixKeys.spec.ts`); the English-copy allowlist holds 15 pairs ("USP / persona" in the column corner, and "angle" in French).

### Production posture

Both flags (`waves_enabled`, `strategy_enabled`) stay off by default; the strategy page checks both before any request and the matrix address leaves for the wave when strategy is off (Playwright). The API is unchanged since Stage 1; there is no migration in Stage 2 and no new environment variable. The new CSS file loads only with the strategy page. The walk seeders run only in `local` and `testing`. Nothing from Stage 2 is pushed.

### Gates run (Stage 2)

- Vitest: the full `apps/main` run had 4 failures; the 2 that were mine (no-hard-coded-colors, the route pin) are fixed (architecture and strategy folders: 1032 passed). The other 2 are `appIconAssets.spec.ts` and fail identically with my changes stashed. Touched set (strategy, waves, brands, i18n, auth, core, architecture): 77 files, 1041 tests passed. api-client: 235 passed.
- Pest (Strategy, Waves, Audit, Erasure, Brands folders): SQLite 776 passed, 13 skipped; PostgreSQL `catalyst_test` 789 passed (5440 assertions). Pint clean; Larastan no errors (after fixing two in the seeder and its test).
- vue-tsc, ESLint (two existing v-html warnings elsewhere), Prettier: clean.
- Playwright (`strategy-matrix`, `strategy-workspace`, serial, e2e database): 10 passed, each test under 15 s.
- Flag-off walk: agency 126 targets identical (0 page errors); creator 146 targets identical (COMPARE OK; the page errors printed are in the baseline). `strategyEntry.spec.ts` still pins the comment nodes. Waves on and strategy off shows PL-1 exactly (wave page has no entry; strategy addresses leave for the wave).
- Break-reverts: 15 web rows W1 to W15, all red, all restored (`pl-2-2-break-reverts.md`).

### Not read / not run

- The light theme of the matrix was not opened in a browser (only its variables were set).
- The 24 locales were checked by key, placeholder and plural-shape specs, not by looking at the screen in each language (long strings in German or Finnish were not seen in the table).
- The full API Pest suite was not re-run in Stage 2 (the five folders above were); the full Playwright suite was not run (the two strategy specs were).
- `appIconAssets.spec.ts` (2 failures, also red without these changes) was not investigated.
- iOS and Android builds; a real phone.
- CI at the Stage 2 tip: nothing is pushed.
