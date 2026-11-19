# PL-2.1 break-reverts (Stage 1, backend)

**Date:** 2026-11-19. **Branch:** `pl`. Each gate of `pl-2-1-plan.md` §6 was broken on purpose, the named test watched failing, the break restored, the test watched passing. Recorded as they were done. SQLite unless a row says PostgreSQL.

| Gate                   | Break                                                                       | Test that went red                                | Restored and green                      |
| ---------------------- | --------------------------------------------------------------------------- | ------------------------------------------------- | --------------------------------------- |
| K3                     | features.strategy ignores the waves flag                                    | 1 failed, 4 passed (17 assertions)                | 5 passed (25 assertions)                |
| K4                     | drop strategy_enabled from the admin FLAGS map                              | 1 failed, 4 passed (23 assertions)                | 5 passed (25 assertions)                |
| L2                     | remove unique (wave_id, color_key)                                          | 1 failed, 8 passed (40 assertions)                | 9 passed (42 assertions)                |
| L4 (slot)              | remove unique (wave_id, market, slot)                                       | 1 failed, 8 passed (41 assertions)                | 9 passed (42 assertions)                |
| L4 (code)              | remove unique (wave_id, market, code)                                       | 1 failed, 8 passed (41 assertions)                | 9 passed (42 assertions)                |
| T3, U1 (DB)            | composite FK on usp replaced by a plain FK (the USP may be in another wave) | 1 failed, 8 passed (40 assertions)                | 9 passed (42 assertions)                |
| C5                     | USP foreign key changed to cascade                                          | 1 failed, 8 passed (39 assertions)                | 9 passed (42 assertions)                |
| P1 (one angle per USP) | remove unique (persona_id, usp_id)                                          | 1 failed, 8 passed (42 assertions)                | 9 passed (42 assertions)                |
| E1                     | remove the classification of wave_personas.name                             | 1 failed, 1 skipped, 335 passed (1925 assertions) | 1 skipped, 336 passed (1925 assertions) |
