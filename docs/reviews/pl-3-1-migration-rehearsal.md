# PL-3.1 migration rehearsal (G5)

- Database: throwaway `pl31_rehearsal`, container `catalyst-postgres` (127.0.0.1:5435), PostgreSQL. The name was printed before every
  command. It was created for this run and dropped after. The `catalyst` database was not touched.
- Method: `artisan migrate` ran the whole history, `migrate:rollback --step=3`, then the three PL-3.1 migrations ran one at a time. A
  `TransactionCommitting` listener read `pg_locks` for the migration's own backend just before each commit, so the list is what the
  transaction held at its peak. Script: kept outside the repo (`/tmp/rehearse.php`).
- Each migration begins `SET LOCAL lock_timeout = '5s'`, so a migration that cannot get a lock in 5 seconds fails and rolls back; it
  never queues behind a long transaction and blocks writers behind it.

| Migration                                        | Time (empty tables) | Locks held at commit                                                                                                                                         |
| ------------------------------------------------ | ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `100000_create_wave_creators_table`              | 70.6 ms             | `AccessExclusive` on the new `wave_creators`. `ShareRowExclusive` (FK creation) on `agencies`, `creators`, `users`, `waves`, `wave_markets`, `wave_personas` |
| `100001_create_wave_creator_decisions_table`     | 21.4 ms             | `AccessExclusive` on the new `wave_creator_decisions`. `ShareRowExclusive` on `agencies`, `users`, `waves`, `wave_creators`                                  |
| `100002_extend_wave_strategy_notes_for_creators` | 9.1 ms              | `AccessExclusive` on `wave_strategy_notes` (drop and re-add the step CHECK; the table is PL-2.5's and holds notes only)                                      |

What this means in production:

- `ShareRowExclusive` on `users`, `creators`, `agencies` and `waves` blocks INSERT/UPDATE/DELETE on those tables (reads continue) for the
  length of the transaction. The new tables are empty, so the foreign-key checks scan nothing. The time is milliseconds, but the lock
  is real and it is on live tables. The 5 second `lock_timeout` bounds the wait for it.
- `AccessExclusive` on `wave_strategy_notes` blocks reads and writes of that table while the CHECK is re-added and validated against
  its rows. The table is small (notes of PL-2.5, behind a flag that is not on).
- No existing table is rewritten. No data is written and nothing is backfilled.
- Not measured: timing on production-sized `users`, `creators` and `waves`. The foreign keys are on the NEW tables, so their time does
  not grow with those tables' size, but this was not measured with large tables. Recommend running the three migrations in a quiet
  window, as the plan states.
