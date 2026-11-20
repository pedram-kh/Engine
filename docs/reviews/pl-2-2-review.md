# PL-2.2 Stage 1 (backend) review record

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

- G1: the legend and USP dot colours were not read from the design context (asset fetches were blocked); Stage 2 must read them.
- No browser walk (no UI in Stage 1).
- The rehearsal ran on PostgreSQL 16, not 17.9.
