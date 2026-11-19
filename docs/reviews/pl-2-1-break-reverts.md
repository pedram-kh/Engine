# PL-2.1 break-reverts (Stage 1, backend)

**Date:** 2026-11-19. **Branch:** `pl`. Each gate of `pl-2-1-plan.md` §6 was broken on purpose, the named test watched failing, the break restored, the test watched passing. Recorded as they were done. SQLite unless a row says PostgreSQL.

| Gate                   | Break                                                                                          | Test that went red                                | Restored and green                      |
| ---------------------- | ---------------------------------------------------------------------------------------------- | ------------------------------------------------- | --------------------------------------- |
| K3                     | features.strategy ignores the waves flag                                                       | 1 failed, 4 passed (17 assertions)                | 5 passed (25 assertions)                |
| K4                     | drop strategy_enabled from the admin FLAGS map                                                 | 1 failed, 4 passed (23 assertions)                | 5 passed (25 assertions)                |
| L2                     | remove unique (wave_id, color_key)                                                             | 1 failed, 8 passed (40 assertions)                | 9 passed (42 assertions)                |
| L4 (slot)              | remove unique (wave_id, market, slot)                                                          | 1 failed, 8 passed (41 assertions)                | 9 passed (42 assertions)                |
| L4 (code)              | remove unique (wave_id, market, code)                                                          | 1 failed, 8 passed (41 assertions)                | 9 passed (42 assertions)                |
| T3, U1 (DB)            | composite FK on usp replaced by a plain FK (the USP may be in another wave)                    | 1 failed, 8 passed (40 assertions)                | 9 passed (42 assertions)                |
| C5                     | USP foreign key changed to cascade                                                             | 1 failed, 8 passed (39 assertions)                | 9 passed (42 assertions)                |
| P1 (one angle per USP) | remove unique (persona_id, usp_id)                                                             | 1 failed, 8 passed (42 assertions)                | 9 passed (42 assertions)                |
| E1                     | remove the classification of wave_personas.name                                                | 1 failed, 1 skipped, 335 passed (1925 assertions) | 1 skipped, 336 passed (1925 assertions) |
| O2                     | Reorder accepts a list that is not the wave's USPs                                             | 1 failed, 1 skipped, 21 passed (206 assertions)   | 1 skipped, 22 passed (215 assertions)   |
| O1                     | Reorder no-op skip removed (second audit row on same order)                                    | 1 failed, 1 skipped, 21 passed (215 assertions)   | 1 skipped, 22 passed (215 assertions)   |
| C1                     | In-use USP deleted without the confirmation count                                              | 2 failed, 1 skipped, 20 passed (202 assertions)   | 1 skipped, 22 passed (215 assertions)   |
| C3                     | Stale confirm count accepted                                                                   | 1 failed, 1 skipped, 21 passed (208 assertions)   | 1 skipped, 22 passed (215 assertions)   |
| R3                     | Staff may delete an in-use USP                                                                 | 1 failed, 1 skipped, 21 passed (210 assertions)   | 1 skipped, 22 passed (215 assertions)   |
| R3b                    | Policy deleteInUse opened to staff                                                             | 1 failed, 1 skipped, 21 passed (210 assertions)   | 1 skipped, 22 passed (215 assertions)   |
| X1                     | USP title limit off by one                                                                     | 1 failed, 1 skipped, 21 passed (209 assertions)   | 1 skipped, 22 passed (215 assertions)   |
| L1                     | USP cap 6: count guard AND colour-exhaustion guard both removed (7th USP created)              | 1 failed, 1 skipped, 21 passed (208 assertions)   | 1 skipped, 22 passed (215 assertions)   |
| X3                     | NUL in the middle of a text not stripped                                                       | 1 failed, 1 skipped, 21 passed (213 assertions)   | 1 skipped, 22 passed (215 assertions)   |
| T1                     | Wave looked up by ulid only (agency and brand ignored)                                         | 1 failed, 1 skipped, 21 passed (212 assertions)   | 1 skipped, 22 passed (215 assertions)   |
| T3                     | USP looked up by ulid only (not through the wave): another wave's or agency's card is editable | 1 failed, 1 skipped, 21 passed (210 assertions)   | 1 skipped, 22 passed (215 assertions)   |
| A1                     | Audit row of USP delete carries the card title                                                 | 1 failed, 1 skipped, 21 passed (215 assertions)   | 1 skipped, 22 passed (215 assertions)   |
| C2                     | In-use delete does not remove the angles                                                       | 2 failed, 1 skipped, 20 passed (208 assertions)   | 1 skipped, 22 passed (215 assertions)   |
| L5                     | USP delete does not lock the wave row (for update SQL assertion, PostgreSQL)                   | 1 failed, 22 passed (225 assertions)              | 23 passed (225 assertions)              |
| L5                     | lockWave without lockForUpdate (PostgreSQL SQL assertion, all USP writes)                      | 1 failed, 22 passed (217 assertions)              | 23 passed (225 assertions)              |
| L5                     | lockForUpdate removed: second connection acquires the wave row (two-connection probe)          | 1 failed, 1 passed (4 assertions)                 | 2 passed (17 assertions)                |
