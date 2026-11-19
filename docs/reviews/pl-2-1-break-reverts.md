# PL-2.1 break-reverts (Stage 1, backend)

**Date:** 2026-11-19. **Branch:** `pl`. Each gate of `pl-2-1-plan.md` §6 was broken on purpose, the named test watched failing, the break restored, the test watched passing. Recorded as they were done. SQLite unless a row says PostgreSQL.

| Gate | Break                                          | Test that went red                 | Restored and green       |
| ---- | ---------------------------------------------- | ---------------------------------- | ------------------------ |
| K3   | features.strategy ignores the waves flag       | 1 failed, 4 passed (17 assertions) | 5 passed (25 assertions) |
| K4   | drop strategy_enabled from the admin FLAGS map | 1 failed, 4 passed (23 assertions) | 5 passed (25 assertions) |
