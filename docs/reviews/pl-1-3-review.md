# PL-1.3 review: Wave project tracker

**Branch:** `pl`. **Plan:** `pl-1-3-plan.md` (corrections C1 to C3 in §13). **Status:** Stage 1 (backend) pushed, CI green on `e5074996`. Stage 2 (web, strings, seeders, close-out) built and **walked by Pedram (2026-11-19, local, steps 1 to 16, all passed)**, then two fixes (D1, D2). Not merged, not deployed.

## Stage 1: migration rehearsal (local, synthetic rows only)

Throwaway PostgreSQL database `pl13_rehearsal` in the local container (dropped afterwards), migrated to the tip before PL-1.3, then **3,000 synthetic waves** (10 synthetic agencies, 300 waves each, inserted directly; nothing from production), then the two PL-1.3 migrations:

| Item                                       | Result                                                                                                   |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------------- |
| Rows in `waves` before and after           | 3,000 and 3,000 (no row written)                                                                         |
| Rows with a marker after M2                | 0 (every existing wave keeps a null `steps_provisioned_at`)                                              |
| Rows in `wave_steps` after both migrations | 0 (no backfill)                                                                                          |
| Time `artisan migrate`                     | M1 `create_wave_steps_table` 18.42 ms; M2 `add_steps_provisioned_at_to_waves` 1.88 ms                    |
| `down()`                                   | run on the empty tables (`migrate:rollback --step=2`: 4.74 ms and 3.55 ms); also in `WaveStepSchemaTest` |

**Locks taken** (read from `pg_locks` inside a transaction with the same statements, then rolled back):

- **M2 (`ALTER TABLE waves ADD COLUMN ... NULL`):** `AccessExclusiveLock` on `waves`, held for the catalog update only (0.14 ms here). No table rewrite. `SET LOCAL lock_timeout = '5s'` bounds the wait.
- **M1 (`CREATE TABLE` with three foreign keys):** `AccessExclusiveLock` on the new table only, plus **`ShareRowExclusiveLock` on each referenced table: `waves`, `agencies` and `users`** (taken by adding a foreign key). That lock blocks writes to those three tables for the duration of the migration transaction (3.2 ms here) and does not block reads. **This is a finding against the plan's wording "creates a new, empty table; no existing table is altered":** it is true that no row and no column of an existing table changes, but the foreign keys do take that brief lock on `agencies` and `users`, which are live tables in production. `waves` is not in production yet (PL-1.1 is not deployed). The PL-1.1 `create_waves_table` migration did the same. `lock_timeout` makes the migration give up after 5 s rather than queue behind a long transaction. **Rehearsal of all six PL-1 migrations (2026-11-18)** is tabulated in `production-line-sprints.md`; `create_waves_table` and `create_brand_files_table` had no `lock_timeout` and now have it. **PROD-DATA RISK for existing data stays NONE** (nothing is read, written or backfilled); the lock is stated so nobody is surprised.

## Stage 1: answer to C1 (the audit key for a user)

See `pl-1-3-plan.md` §13. The precedent fits, so nothing stopped: AH-119 A3 writes `from_user` and `to_user` (user ids, never a name); `AuditPayloadKeys` decides them in `notPersonalKeys()` under the id groups ("points at a row that is anonymised in place"); the users row is anonymised in place on erasure and `AuditRowScrub` keeps ids and ulids. The tracker uses the same two keys, carrying the **integer user id** as AH-119 does. The plan's `owner_id` (a ulid) was **not** added. Seven new keys got a decision in the same commit: `stream`, `step_key`, `owner_client`, `status_source`, `starts_on`, `due_on`, `completed_on`.

## Stage 1: what differs from the plan, and why

1. **Audit key** `from_user` / `to_user` instead of `owner_id` (C1, above).
2. **Step PATCH lock order.** A plain edit locks the step row only. A PATCH that moves a step to another stream takes the **wave row lock first** and then the step lock (the order every stream operation uses), so a move and a remove-stream cannot deadlock. The plan said "the step row" only.
3. **Archived brand.** Step and stream writes are refused on an archived brand with the wave rule `wave.brand_archived` (the plan did not say; it is the same read-only rule as editing a wave). The tracker GET still provisions on an archived brand, because it writes defaults, not a user edit.
4. **Add step, Add stream, Remove stream on a wave that was never opened** call the provisioner first (Add stream and Add step) so a custom step cannot pre-empt the marker. Remove stream on a wave with no steps is a 404.
5. **Responses.** Add and update return the one step; remove a step is 204; remove stream and add stream return the **full tracker** payload (so the page redraws from one response). The tracker payload has `absent_streams` (the streams with no step, for the Add stream control) and no "No steps" stream entry: a stream with no steps is simply absent (the §1.2 rule).
6. **Progress object on every wave response** (list, show, create, update), not only list and show; the list computes it with one grouped query, the others with one query.
7. **Audit payload shape.** Update and remove write literal `before` and `after` arrays of nine keys, and `metadata` with `from_user` and `to_user`; add writes `to_user`; stream remove and add write `stream` and `row_count`; provisioning writes `row_count`.
8. **Completed date.** `completed_on` is the actor's today (their time zone) when a step becomes done and is cleared when it leaves done, as planned; tested with a user in `Pacific/Auckland`.
9. **Route-table gates.** `WaveApiTest` B1 now expects 11 wave routes (5 + 6), and `WaveTrackerAccessTest` pins the six tracker routes by name.

## Stage 1: not read, not run

Not read: the developer brief; frame 01b parts listed in the plan. Not run: the full Pest suite, the full Vitest suite, Playwright, the walk, CI (until the push). No real two-client race on PostgreSQL was run; the lock tests assert the `for update` SQL, the unique indexes refuse duplicates, and sequential requests are tested.

## Stage 2: what was built

- **Wave page:** health pill in the title, six stream-aware sections: stream cards (one segment per step, status pill, next step, final delivery, owners incl. Client), the steps table with filters All / UGC / Statics / Stills / Mine, the check (optimistic, reverts with an inline row error), edit, add, remove, Remove stream (not on UGC) and Add stream. After every write the tracker is silently reloaded; a 404 shows "This step was removed by someone else" and reloads.
- **Brand page:** the Waves panel draws the bar from the server `progress` object (six segments, `label_kind` step / complete / not started). The client holds no copy of the progress rule.
- **Roles:** staff may check, change owner and dates; their request never carries `name` or `stream` (the server answers 403 to the whole request otherwise). Admin and manager also add, rename, move, remove, Remove stream, Add stream. A strategy step has no Remove and cannot change stream.
- **Strings:** 81 keys under `app.waves.tracker.*` in all 24 locales (default step names stay English in the database). `waveKeys.spec.ts` now checks 184 keys; 51 loanwords (UGC, Status, Client, Statics, Stills, "Mine" in Danish, "UGC video") are listed in `SAME_AS_ENGLISH_BY_DESIGN`.
- **Frozen file:** `agencyStatusTones.ts` gained the `wave_step` kind (up next neutral, in progress info, due today warning, delayed danger, done success). `agency-frozen.spec.ts` is re-pinned to `bd519081814a45fa04d938eef49dc1e186c93d011b1fe9fc27ea9d5a857abaff` in the same commit. **Reason:** the five tracker step words need their tones through the one status registry, as every other status in the app has. `agency.css` is untouched; the new styles are scoped.
- **api-client:** tracker, step, stream and progress types in `packages/api-client/src/types/wave.ts`; six calls in `waves.api.ts`.
- **Seeders:** `Pl13WalkSeeder` and `Pl13WalkCleanupSeeder` (synthetic rows, `*.local` addresses, refuse to run outside local/testing); `Pl13WalkSeedersTest`.

## Stage 2: screens with no design, built from the design system

The brief had no frames for these. Each is made of existing parts (`agency-card`, `AgencyStatusPill`, `v-dialog` and `v-menu` with the PL-1.2 dialog pattern, `v-chip`, `v-btn`, `v-alert`, `v-snackbar`):

1. Stream card row (the design shows the card; its segments, owner chips and Delayed border were derived).
2. The steps table, its filter chips and the "N completed steps" fold line.
3. Add / Edit step dialog.
4. Remove step and Remove stream confirmation dialogs.
5. Row menu, group menu and the footer Add step / Add stream menu.
6. The narrow (under 768 px) stacked row.
7. The "step was removed by someone else" snackbar and the inline row error.
8. The health pill in the wave title.

## Stage 2: C2 report (real browser, real data, what was looked at)

Setup: throwaway PostgreSQL database `pl13_walk` (dropped afterwards) seeded by `Pl13WalkSeeder`, API on 127.0.0.1:8000, Vite on 127.0.0.1:5173, flag turned on in that database only through the test helper. Chromium through Playwright, signed in as the seeded admin. Screenshots were viewed, not just measured.

| Looked at                                                                                                                          | 1440 x 900                       | 390 x 844                        |
| ---------------------------------------------------------------------------------------------------------------------------------- | -------------------------------- | -------------------------------- |
| Brand page with four waves (bars: Draft Wave 8 with Personas in progress, Wave 6 not started, Wave 5 Complete, Wave 4 not started) | looked, correct                  | looked, correct                  |
| Wave 8: header, health pill "At risk, 1 stream delayed", details card, three stream cards, 24-step table                           | looked                           | looked at four scroll positions  |
| Row menu (Edit)                                                                                                                    | opened, looked                   | opened, looked                   |
| Edit step dialog, Owner menu open above the dialog (Unassigned, Client, four active members; the former member is absent)          | opened, looked                   | opened, looked                   |
| Stream group menu, Remove stream confirmation (names the stream and its 5 steps), Cancel                                           | opened, looked                   | opened, looked                   |
| Add step dialog                                                                                                                    | opened, looked                   | opened                           |
| Quick close and reopen (0, 60 and 150 ms) of the edit dialog and the confirmation                                                  | in `wave-tracker.spec.ts`, green | in `wave-tracker.spec.ts`, green |
| Sideways scroll                                                                                                                    | none (measured 0)                | none (measured 0)                |

**Found and fixed during the pass:** (1) the folded line read "USPs to USPs (Nov 16 to Nov 16)" for a single completed step; it now shows the name once and the date once. (2) At 390 the page overflowed by 29 px (details card columns and the header); scoped narrow rules fixed it. (3) At 390 the frozen layout opens the 280 px agency drawer, which pushed the page 196 px sideways; this is the existing shell, not the tracker, and was left alone. The Playwright spec closes the drawer first. (4) The filter chip row at 390 scrolls sideways inside itself ("Mine" sits at the edge); left as is.

**Not looked at in a browser:** manager, staff and designer sign-ins (covered by `WaveStepsPanel.spec.ts` W4 and the API tests, not by eye), Wave 6 first open of the tracker, Agency B isolation (covered by `WaveTrackerAccessTest` TN1; not by eye), the other 23 locales on screen, light theme. Pedram's walk covers these (commands below).

## Stage 2: C3 result (flag off, frozen file)

`node tools/walk/walk.mjs --set agency --compare ../../docs/reviews/ah-115-agency-baseline/baseline.json` on the working tree: **COMPARE OK, 126 targets identical, 0 page errors.** That includes the 12 campaign and 16 brand targets. The walk's mock API has no `features.waves`, so the flag is off by construction, and its DOM hash is of `outerHTML`, so a stray `<!--v-if-->` would have shown. The new brand-row bar sits inside the existing wave rows, which exist only with the flag on. The wave page is not a walk route.

## Stage 2: differences from the plan

1. The empty-stream group line is unreachable: a stream with no steps is absent from the tracker (Remove stream deletes all of its steps), so the group never renders empty. The empty line exists only for Mine.
2. Stream-card segments are one per step (as the plan said), so a five-step stream draws five, not a fixed six.
3. The "N completed" line shows the name once when first and last are the same step, and the date once when both dates match.
4. Narrow layout fixes (above) were not in the plan.
5. The unit gate for the optimistic check (W5) is a component spec, not a browser test.

## Stage 2: gates, break-reverts and results

Every web gate W1 to W8, the per-open dialog key (E2E) and the i18n gate (W7) were broken and reverted; rows are in `pl-1-3-break-reverts.md`. All went red when broken and green when reverted.

Run locally on this tree:

- Vitest: waves, brands, architecture and i18n folders, 90 files, 1,100 tests, green. `vue-tsc --noEmit` clean. ESLint clean on the touched web files and the new Playwright spec.
- Playwright `wave-tracker.spec.ts` at 1440 and 390, serial: 2 passed.
- Pint (passed) and Larastan (no errors).
- Pest on SQLite **and** PostgreSQL: Waves 166, Audit 50, Erasure 337, Tenancy 9 (all passed on both); Brands 93 (SQLite). Running the folders in one Pest command at PHP's default 128M stops with an out-of-memory fatal in `ErasureClaimGuardTest` (not a duplicate declaration; corrected in the 2026-11-19 section below); each folder alone is green.
- Agency walk: 126 / 126 (C3).

## Stage 2: not read, not run

Not run: the full Pest and full Vitest suites, CI for Stage 2 (nothing is pushed), the other 23 locales or the light theme in a browser, staging, any production data. Not read: the developer brief.

## Stage 2: how to walk it locally

```
cd apps/api
php artisan migrate
php artisan db:seed --class='Database\Seeders\Pl13WalkSeeder'      # prints the ulids and logins
php artisan serve --host=127.0.0.1 --port=8000
cd ../main && pnpm dev --host 127.0.0.1                          # http://127.0.0.1:5173
```

Turn `waves_enabled` on by hand in the admin app (the seeder does not). Password for every login: `password-12chars`.

| Login                      | Role                                                 |
| -------------------------- | ---------------------------------------------------- |
| `admin@pl13-walk.local`    | agency admin (Agency A)                              |
| `manager@pl13-walk.local`  | manager                                              |
| `staff@pl13-walk.local`    | staff                                                |
| `designer@pl13-walk.local` | staff, owns Personas on Wave 8                       |
| `former@pl13-walk.local`   | removed member (owns a UGC step)                     |
| `admin@pl13-walk-b.local`  | admin of Agency B (isolation: must not see Agency A) |

Brand Bolt Food: Wave 4 (Statics removed, 19 steps), Wave 5 (strategy steps done, reads Complete), Wave 6 (never opened: first open creates the 24 steps), Wave 8 (mixed: done, in progress, 3 days delayed, due today, former and Client owners). Create Wave 7 in the walk. Agency B has brand Glovo with "Glovo Wave 1".

Clean up afterwards:

```
php artisan db:seed --class='Database\Seeders\Pl13WalkCleanupSeeder'
```

## Walk result and the two fixes (2026-11-19)

Pedram walked steps 1 to 16 locally: all passed. Two small fixes came out of it.

**D1. Delayed row wording.** A delayed step showed the red "Delayed" pill and then "Delayed · 3 days", so the word appeared twice. The text beside the pill is now the duration only ("3 days", "1 day") from a new key, `app.waves.tracker.steps.overdue`, in all 24 locales (derived from each locale's own delayed wording by dropping the word before the middle dot, plural forms kept). The stream card keeps "Delayed · 3 days". Gates: `WaveStepsPanel.spec.ts` W3 (the word appears once in the row, no "Delayed ·"); `waveKeys.spec.ts` (186 keys) and the locale-parity plural gate. Break-reverts D1 and D1b.

**D2. Add stream when a default step of that stream is elsewhere.** A default Statics step moved by hand to Stills keeps its key; after Remove stream (Statics), Add stream hit the unique `(wave_id, step_key)` index, returned "nothing written", and the user saw nothing. Now Add stream (under the wave row lock) reads the step keys already on the wave, inserts only the default steps of that stream whose keys are not there, and leaves the moved step where it is. If every default key is already on the wave, the API answers 422 `stream.defaults_exist` and the page shows "Every default step of this stream is already on the wave, in another stream. Move them back, or add a step." (`app.waves.tracker.errors.streamDefaultsExist`, 24 locales). A stream that already has steps is still a quiet no-op (ST2 unchanged). Tests `D2a` (one moved: four inserted, moved step stays, 24 steps, one audit row) and `D2b` (all five moved: 422, nothing written, no audit row) and web test D2 on the message. Break-reverts D2a and D2b on SQLite **and** PostgreSQL; D2c on the web message.

**The Pest fatal: not a duplicate declaration.** Reproduced it with Waves, Audit, Erasure, Tenancy and Brands in one command: `Allowed memory size of 134217728 bytes exhausted` at `ErasureClaimGuardTest.php:28`, where a helper reads every file under `app/` into an array. It is PHP's default 128M, not a function declared twice. The test is from AH-104c (2026-10-22), not PL-1 code, so it is a tech-debt row (`docs/tech-debt.md` item 42). With `php -d memory_limit=1G` the five folders run together: 653 passed, 4 skipped. My earlier note in this file ("a helper function declared twice") was a wrong guess and is withdrawn.

### Real browser, D1 and D2 (local, walk data, 1440 and 390)

- **D1:** Wave 8 delayed row "Internal review": pill "Delayed" then "1 day" at both widths (looked at the 390 screenshot); the Statics stream card still reads "Delayed · 1 day".
- **D2 (1440):** moved "Design v1" to Stills with the edit dialog, Remove stream Statics, Add stream Statics: Statics came back with four steps (Static brief, Internal review, Client feedback round, Final export), "Design v1" stayed in Stills, no error. **D2 message (390):** shown with the server answer simulated (a 422 `stream.defaults_exist` injected in the browser); the real 422 is covered by test D2b. Not done in the browser: the all-five-moved case against the real API.

### Gates for this round (all on this tree, local)

| Gate                                                                                                             | Result                                                   |
| ---------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| Pest, Waves + Brands + Audit + Erasure + Tenancy in one run, SQLite (`php -d memory_limit=1G`)                   | 653 passed, 4 skipped                                    |
| Same folders, PostgreSQL (`phpunit.pgsql.xml`, throwaway `pl13_test`)                                            | 657 passed (**Brands on PostgreSQL: now run, included**) |
| Pint, Larastan                                                                                                   | passed, no errors                                        |
| Vitest: waves, brands, i18n, architecture                                                                        | 90 files, 1,101 tests passed                             |
| `vue-tsc --noEmit`, ESLint (waves, i18n, the Playwright spec)                                                    | clean                                                    |
| `wave-tracker.spec.ts` at 1440 and 390, serial                                                                   | 2 passed                                                 |
| Agency walk (flag off by construction, includes the 12 campaign and 16 brand targets and the comment-node check) | COMPARE OK, 126 / 126 identical                          |
| Full Pest, full Vitest, full creator baseline, CI                                                                | not run (CI after the push; see the PUSH report)         |

### Clean-up

`waves_enabled` is **ON** (global value `true` in the local `catalyst` database) before and after this round; it was ON when the walk environment started and I did not change it. `Pl13WalkCleanupSeeder` run: no `pl13-walk` users and no "PL-1.3 Walk" agency remain. `pnpm dev` stopped (API, main, admin, queue). Temporary scripts removed. Two unrelated processes of another project (ports 8010 and a Vite under `Gullio`) were left alone.
