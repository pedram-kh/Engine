# PL-2.1 migration rehearsal (G3)

Replaces the hand-typed `psql` numbers in `pl-2-1-plan.md` section 8. Run on 2026-11-19 on a **throwaway local PostgreSQL 16 database** (`pl21_rehearsal`, container `catalyst-postgres`, dropped afterwards). **Nothing from production; no production database was touched or read.**

**How.** The database was migrated with `php artisan migrate`, the four PL-2.1 migrations were rolled back (`migrate:rollback --step=4`), **synthetic** rows were created with the model factories, and the four real migration files were then run again through the Artisan `migrate` command (called from a small wrapper outside the repository, `/tmp/rehearse.php`, so the locks of the migration's own transaction could be read). The wrapper listens to every statement of the migration and reads `pg_locks` for its own backend after it, so each relation's lock modes are the ones the migration's transaction actually held.

| Table                 | Rows before the four migrations | Rows after |
| --------------------- | ------------------------------- | ---------- |
| `agencies`            | 25                              | 25         |
| `brands`              | 25                              | 25         |
| `waves`               | 200                             | 200        |
| `wave_usps`           | (table absent)                  | 0          |
| `wave_personas`       | (table absent)                  | 0          |
| `wave_persona_angles` | (table absent)                  | 0          |

Waves with a non-null `usp_audience_title` after the migration: **0** (no backfill, no data written).

The factory could only produce about 25 distinct brand names per process (Faker's unique company names), so the rehearsal is 25 agencies and 200 waves, not the 70 agencies the plan's hand-typed run used. The lock modes do not depend on row counts; the timings are for tables this small.

| Migration (file)                                       | Time as printed by `artisan migrate` | Locks held by its transaction                                                                                                   |
| ------------------------------------------------------ | ------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------- |
| M1 `add_usp_audience_title_to_waves` (nullable column) | 5.73 ms                              | `waves`: **AccessExclusive**                                                                                                    |
| M2 `create_wave_usps_table`                            | 21.43 ms                             | `agencies`, `waves`: **ShareRowExclusive** (foreign keys); `wave_usps` (new): AccessExclusive and the rest                      |
| M3 `create_wave_personas_table`                        | 17.96 ms                             | `agencies`, `waves`: **ShareRowExclusive**; `wave_personas` (new): AccessExclusive and the rest                                 |
| M4 `create_wave_persona_angles_table`                  | 19.68 ms                             | `agencies`, `waves`, `wave_personas`, `wave_usps`: **ShareRowExclusive**; `wave_persona_angles` (new): AccessExclusive and rest |

Total wall time of `artisan migrate` for the four: **95.6 ms** (including the wrapper's own `pg_locks` queries, which also account for the AccessShare and RowShare entries in the raw output; those are the rehearsal's own reads, not the migration's).

**Reading it.** Same picture as the plan: `waves` is locked against writes (and, for M1, reads) for a few milliseconds; `agencies` is locked against writes for the length of M2, M3 and M4; every other lock is on a table the migration creates. `SET LOCAL lock_timeout = '5s'` is in each file, so on a busy table a migration fails fast instead of queueing writers behind it. The rollback (`migrate:rollback --step=4`) took 1.6 ms, 3.9 ms, 4.0 ms and 6.5 ms and is a rollback tool only before the flag is armed (`down()` drops the tables and the column, which is lossy).

**Not measured:** a database of production size or with concurrent traffic (none exists to copy and none was touched). PL-1 is not deployed (`production-line-sprints.md` section 6), so on first deploy these four run on a `waves` table that is created in the same deploy.
