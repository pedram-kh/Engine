# PL-2.5 Stage 1: migration rehearsal

Throwaway PostgreSQL database `pl25_rehearse_test` (host 127.0.0.1, port 5435, server `catalyst-postgres`; never the
`catalyst` database). All earlier migrations applied (`migrate:fresh`), the two new ones rolled back, then each `up()` run
in its own transaction with the locks read from `pg_locks` for the session before the commit. The database was dropped
afterwards. The tables were empty (a fresh schema): both migrations are schema only, no data write, no backfill.

| Migration                                                  | `up()` inside the transaction | Commit | `lock_timeout`                 | Locks on existing tables                                                                                                                                                                      |
| ---------------------------------------------------------- | ----------------------------- | ------ | ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `2026_11_22_100000_create_wave_strategy_completions_table` | 18.9 ms                       | 0.5 ms | `5s` inside, back to `0` after | `agencies`, `users`, `wave_markets`, `waves`: `ShareRowExclusiveLock` (the foreign keys), plus the share and row-share locks that go with it. No `AccessExclusiveLock` on any existing table. |
| `2026_11_22_100001_create_wave_strategy_notes_table`       | 18.5 ms                       | 0.4 ms | `5s` inside, back to `0` after | the same four tables, the same modes                                                                                                                                                          |

`ShareRowExclusiveLock` blocks concurrent writes (INSERT, UPDATE, DELETE) on the referenced table but not reads. It is held
for the length of the migration transaction (about 20 ms here). The `SET LOCAL lock_timeout = '5s'` makes the migration
fail fast instead of queueing behind a long writer and blocking every writer behind it. Not measured: the time with a
production-sized `users`, `waves` or `wave_markets` table, because no such copy was used; the lock mode is the same, and the
foreign keys are validated against an empty new table, so the cost does not grow with the size of the referenced tables.
`down()` was run on both engines by `Pl25GatesTest` (B18).
