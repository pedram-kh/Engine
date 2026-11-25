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
