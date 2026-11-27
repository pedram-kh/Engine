# PL-3.2a migration rehearsal (G3)

- Database: throwaway `pl32_rehearsal`, container `catalyst-postgres` (127.0.0.1:5435), PostgreSQL. The name was printed before
  each command. It was created for this run. The `catalyst` database was not touched.
- Method: `artisan migrate` (called from a small runner kept outside the repo, `/tmp/rehearse32.php`) ran the whole history before
  2026-11-24, then each PL-3.2a migration on its own. A `TransactionCommitting` listener read `pg_locks` for the migration's own
  backend just before each commit (relations in `public`, modes ShareRowExclusive, Share, Exclusive, AccessExclusive), so the list is
  what the transaction held at its peak. Then `migrate:rollback --step=5` ran all five `down()` methods, and M1 ran forward again.
- Every migration begins `SET LOCAL lock_timeout = '5s'`: a migration that cannot get a lock in 5 seconds fails and rolls back; it
  never queues behind a long transaction and blocks writers behind it.

| Migration                                            | Time (empty tables) | Locks held at commit                                                                                                                                                        |
| ---------------------------------------------------- | ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `100000_add_creator_key_to_wave_creators` (M1)       | 8.8 ms              | `AccessExclusive` and `Share` (the index build) on **`wave_creators`** (PL-3.1's table, no production database has it before the first merge of `pl`)                       |
| `100001_extend_wave_strategy_notes_for_scripts` (M2) | 8.8 ms              | `AccessExclusive` on **`wave_strategy_notes`** (drop and re-add the step CHECK; PL-2.5's table)                                                                             |
| `100002_create_wave_scripts_table` (M3)              | 28.5 ms             | `AccessExclusive` and `Share` on the new `wave_scripts`. `ShareRowExclusive` (FK creation) on `agencies`, `users`, **`creators`**, `waves`, `wave_markets`, `wave_creators` |
| `100003_create_wave_script_hooks_table` (M4)         | 20.7 ms             | `AccessExclusive` and `Share` on the new `wave_script_hooks`. `ShareRowExclusive` on `agencies`, `waves`, `wave_scripts`                                                    |
| `100004_create_wave_script_hook_texts_table` (M5)    | 20.0 ms             | `AccessExclusive` and `Share` on the new `wave_script_hook_texts`. `ShareRowExclusive` on `agencies`, `waves`, `wave_scripts`, `wave_script_hooks`                          |

All five `down()` methods ran clean and in reverse order.

What this means in production:

- Only **M3** touches busy tables with a real lock: `ShareRowExclusive` on `users`, `creators`, `agencies` and `waves` blocks INSERT, UPDATE
  and DELETE on those tables (reads continue) for the length of the transaction, 28.5 ms here because the new table is empty and its
  foreign-key checks scan nothing. The 5 second `lock_timeout` bounds the wait. Same shape as PL-3.1's `wave_creators` migration.
- M1 and M2 take `AccessExclusive` on PL tables that no production database has before `pl` merges. Both are empty or hold notes only.
- M4 and M5 touch only PL tables and `agencies` (`ShareRowExclusive`).
- No existing table is rewritten. No data is written and nothing is backfilled.
- Not measured: timing on production-sized `users`, `creators` and `waves`. The foreign keys are on NEW tables, so their time does not
  grow with those tables' size, but it was not measured with large tables. Run the five migrations in a quiet window, together with
  PL-3.1's.

## Rehearsal 2 (2026-11-25): migration M3 edited in place for the live-only number rule

- Database: throwaway `pl32_rehearsal` (created for this run, dropped after), container `catalyst-postgres`, PostgreSQL. The `catalyst` database was not touched. The name was printed before the run.
- Why: after Pedram's walk, M3 (`100002_create_wave_scripts_table`) changed in place (nothing but local and CI has run it): the full unique key on `(wave_id, creator_id, number)` is gone and a **partial unique index** `unique_wave_scripts_live_wave_creator_number ... WHERE archived_at IS NULL` replaces it. Still schema-only, still `SET LOCAL lock_timeout = '5s'`.
- Method: a runner outside the repository called `artisan migrate` (`--path` per migration file): the 119 earlier migrations first, then each of the five on its own, a `TransactionCommitting` listener reading `pg_locks` for the migration's own backend (modes ShareRowExclusive, Share, Exclusive, AccessExclusive, relations in `public`). Then `migrate:rollback --step=5` and M1 forward again.

| Migration             | Time (empty tables) | Locks held at commit                                                                                                                                                        |
| --------------------- | ------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `100000` (M1)         | 8.7 ms              | `AccessExclusive`, `Share` on `wave_creators`                                                                                                                               |
| `100001` (M2)         | 8.4 ms              | `AccessExclusive` on `wave_strategy_notes`                                                                                                                                  |
| `100002` (M3, edited) | 26.7 ms             | `AccessExclusive`, `Share`, `ShareRowExclusive` on the new `wave_scripts`; `ShareRowExclusive` on `agencies`, `creators`, `users`, `wave_creators`, `wave_markets`, `waves` |
| `100003` (M4)         | 18.1 ms             | new `wave_script_hooks` (`AccessExclusive`, `Share`, `ShareRowExclusive`); `ShareRowExclusive` on `agencies`, `wave_scripts`, `waves`                                       |
| `100004` (M5)         | 17.4 ms             | new `wave_script_hook_texts` (`AccessExclusive`, `Share`, `ShareRowExclusive`); `ShareRowExclusive` on `agencies`, `wave_script_hooks`, `wave_scripts`, `waves`             |

- The lock set is the same as in the first rehearsal; M3 took 28.5 ms before and 26.7 ms now (noise), and the partial index adds no lock on an existing table (it is built on the new, empty `wave_scripts`). `pg_indexes` shows the index with its `WHERE (archived_at IS NULL)`.
- All five `down()` methods ran clean in reverse order; the tables were gone afterwards and M1 ran forward again.
- Not measured: production-sized `users`, `creators` and `waves` (same caveat as before).

## Rehearsal 3 (2026-11-26): PL-3.2b, five new migrations and M3 edited in place

- Database: throwaway `pl32b_rehearsal` (created for this run, **dropped after**), container `catalyst-postgres` (127.0.0.1:5435), PostgreSQL. The `catalyst` database was not touched. The name was printed before every command and by the runner itself, which refuses any other name. The tables were **empty** (no synthetic rows): the foreign keys are on new tables, so their check time does not depend on the size of the old ones.
- Method: a runner kept outside the repository (`/tmp/pl32b/rehearse.php`) called `artisan migrate` (`--path` per file): the 124 earlier migrations first (this includes the PL-3.2a ones, and M3 as it is now), then each new one on its own, a `TransactionCommitting` listener reading `pg_locks` for the migration's own backend (relations in `public`; modes ShareRowExclusive, Share, Exclusive, AccessExclusive). Then `migrate:rollback --step=5` ran the five `down()` methods, a `lock_timeout` failure was provoked, and the five ran forward again.
- **M3 edited in place, and why:** ruling 9 ("no unused column") removes `wave_scripts.language`. Only local databases and CI have ever run `2026_11_24_100002_create_wave_scripts_table`, so the migration was edited instead of adding a drop-column migration. The edit is the removal of the column and a note in the docblock; nothing else in M3 changed. `StrategySchemaTest` was updated in the same commit. **Any database that already ran the old M3 (for example `pl32_walk`) needs `migrate:fresh`**, because Laravel will not run an edited migration twice. Production does not have M3 yet.

| Migration                                    | Time (empty tables) | Locks held at commit                                                                                                                      |
| -------------------------------------------- | ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `100000_create_wave_market_script_languages` | 17.3 ms             | new table: `AccessExclusive`, `Share`, `ShareRowExclusive`; `ShareRowExclusive` on `agencies`, `waves`, `wave_markets`                    |
| `100001_create_wave_script_lines`            | 16.9 ms             | new table (same three); `ShareRowExclusive` on `agencies`, `waves`, `wave_scripts`                                                        |
| `100002_create_wave_script_line_texts`       | 19.8 ms             | new table (same three); `ShareRowExclusive` on `agencies`, `waves`, `wave_scripts`, `wave_script_lines`                                   |
| `100003_create_wave_script_broll`            | 21.8 ms             | new table (same three); `ShareRowExclusive` on `agencies`, `waves`, `wave_scripts`, `wave_script_lines`                                   |
| `100004_create_wave_script_images`           | 28.9 ms             | new table (same three); `ShareRowExclusive` on `agencies`, `waves`, **`users`**, `wave_scripts`, `wave_script_hooks`, `wave_script_lines` |

- Existing tables are only ever **referenced**: `ShareRowExclusive` (blocks INSERT, UPDATE and DELETE on that table for the length of the migration; reads continue) on `agencies`, `waves`, `wave_markets` and, for the images' `uploaded_by_user_id`, on **`users`** (a production table, the busiest of them). Each migration is a few tens of milliseconds on empty tables. No existing table is altered or rewritten; no data is written or backfilled.
- All five `down()` methods ran clean in reverse order: the five tables were gone and `wave_scripts` has no `language` column afterwards.
- **`lock_timeout`:** with another session holding `ACCESS EXCLUSIVE` on `wave_scripts`, the lines migration stopped after **5.0 s** with SQLSTATE 55P03 (`canceling statement due to lock timeout`), the table was not created and no `migrations` row was written. Nothing queued behind the lock.
- Forward again after the rollback: all five tables present.
- Not measured: timing on production-sized `users`, `agencies`, `waves`. Run the new migrations in a quiet window together with the PL-3.1 and PL-3.2a ones.

## Rehearsal 4 (2026-11-27): the two walk columns edited in place

- Why: after Pedram's walk, `2026_11_26_100001_create_wave_script_lines_table` gained `shot_type` (`varchar(24)`, nullable, with the Postgres CHECK `chk_wave_script_lines_shot_type` of the ten types or NULL) and `visual_description` (`text`, nullable), and `2026_11_24_100002_create_wave_scripts_table` gained `setting` (`text`, nullable). Nothing but local and CI has run these (PROD-DATA RISK none, ruled). Both still begin with `SET LOCAL lock_timeout = '5s'`; both are still create-table migrations, so no existing table is altered.
- Method: a runner outside the repository booted the app on the throwaway database `pl32b_rehearse`, ran `migrate` on an empty database (all migrations, 933 ms in total) with a `TransactionCommitting` listener reading `pg_locks` for the migration's own backend. The database was dropped afterwards.
- Locks held at commit: `2026_11_24_100002` took `ShareRowExclusive` on `agencies`, `creators`, `users`, `wave_creators`, `wave_markets`, `waves` and on the new `wave_scripts` (plus `AccessExclusive` and `Share` on the new table); `2026_11_26_100001` took `ShareRowExclusive` on `agencies`, `waves`, `wave_scripts` and the new `wave_script_lines` (plus `AccessExclusive` and `Share` on the new table). The same lock set as Rehearsal 3: the new columns add none.
- Read back from the catalog: `setting text null`, `shot_type character varying(24) null`, `visual_description text null`, and the CHECK with the ten types. The Postgres test P2 (`ScriptsPostgresTest`) refuses `'bogus'` and `'Wide'` and accepts the ten and NULL.
- Not measured: timing on production-sized tables (unchanged from Rehearsal 3).

## Close-out (2026-11-27): no migration changed

The B-roll limit went away without touching a migration: `wave_script_broll` never had a count CHECK (only `chk_wave_script_broll_shot_type`), the limit lived in `StrategyLimits` and the services. Rehearsal 4 above still describes the migrations as they are. The Postgres folder run on `pl32b_test` (below in the report) migrated the whole set again on a fresh database.
