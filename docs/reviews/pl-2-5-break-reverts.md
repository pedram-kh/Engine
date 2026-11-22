# PL-2.5 Stage 1 (backend): break-revert record

Method, for every gate: patch the code (or the migration the gate protects), run the targeted tests and require them
**red**, restore the file in a `finally`, re-run the same tests and require them **green**. `git status` after each run showed
no source change besides the tests added on purpose. The driver is one script (`/tmp/br/pl25_br.py`, not committed, same
shape as the PL-2.4 one); the two PostgreSQL rows ran on the throwaway database `pl25_s1_test` (host 127.0.0.1, port 5435,
never the `catalyst` database), the rest on SQLite.

25 mutations: 24 red under the break, 24 green after the revert. One stayed green (B19, below).

| Gate                  | Mutation                                                                               | Tests                                  | Under the break  | After revert |
| --------------------- | -------------------------------------------------------------------------------------- | -------------------------------------- | ---------------- | ------------ |
| B4 row 1              | `decide()`: a system row never follows the real state                                  | `Pl25TrackerRowsTest`                  | red              | green        |
| B4 rows 4, 5, 7       | `decide()`: drop the "real state did not change, leave the hand-set alone" rule        | `Pl25TrackerRowsTest`                  | red              | green        |
| B4 row 6              | `decide()`: take back only when the real step passes (`>`), not when it reaches (`>=`) | `Pl25TrackerRowsTest`                  | red              | green        |
| B4 rows 2, 3          | `beyond()` uses `>=` (equal counts as beyond)                                          | `Pl25TrackerRowsTest`                  | red              | green        |
| B4 row 3              | `applyStatus`: an equal hand-set is treated as beyond                                  | `Pl25TrackerRowsTest`                  | red              | green        |
| B4 row 2              | `applyStatus`: the 409 / `confirm_incomplete` check disabled                           | `Pl25TrackerRowsTest`                  | red              | green        |
| B4 row 8              | tracker open no longer runs the catch-up                                               | `Pl25TrackerRowsTest`                  | red              | green        |
| B10 / row 10          | tracker open runs the catch-up even when strategy is OFF                               | `Pl25TrackerRowsTest` (spy)            | red              | green        |
| B10                   | `real()` answers while strategy is OFF (the `strategy` key leaks into the tracker)     | `StrategyFlagGatesTest`                | red              | green        |
| B9                    | `MatrixService::save` uses a plain `DB::transaction`                                   | `Pl25CompletionsTest`, `Pl25GatesTest` | red              | green        |
| B20 / A1              | every writer in `ConceptLinkService` uses a plain `DB::transaction`                    | `Pl25GatesTest` (architecture test)    | red              | green        |
| B6 / Q1               | an empty step can be marked                                                            | `Pl25CompletionsTest`                  | red              | green        |
| B7 rule E             | emptied marks are not dropped                                                          | `Pl25CompletionsTest`                  | red              | green        |
| B7 rule D / Q5        | market removal keeps the notes (`purgeMarkets` does nothing)                           | `Pl25CompletionsTest`                  | red              | green        |
| B15                   | any role can edit a note                                                               | `Pl25NotesTest`                        | red              | green        |
| B15                   | thread limit of 100 removed                                                            | `Pl25NotesTest`                        | red              | green        |
| B16                   | a presenter outside `Strategy/Notes` references `WaveStrategyNote`                     | `Pl25GatesTest`                        | red              | green        |
| B8                    | the landing walks the steps in reverse                                                 | `Pl25CompletionsTest`                  | red              | green        |
| B5 / K2               | wave step is done when done in any market (instead of every market)                    | `Pl25CompletionsTest`                  | red              | green        |
| B14                   | the wave list reads a strategy table                                                   | `Pl25GatesTest`                        | red              | green        |
| B12                   | the hook runs outside the transaction                                                  | `Pl25CompletionsTest`                  | red              | green        |
| B17                   | a new table leaves the erasure registry                                                | `tests/Feature/Modules/Erasure`        | red              | green        |
| B18                   | the expression unique index is not created                                             | `Pl25GatesTest`                        | red              | green        |
| B13 (PostgreSQL)      | a hand-set on a strategy row no longer takes the wave lock first                       | `Pl25GatesTest`                        | red              | green        |
| B19 / A2 (PostgreSQL) | `StrategyGuard::lockWave` takes no `FOR UPDATE`                                        | `Pl25RaceTest`                         | **stayed green** | green        |

## What the first pass found weak (fixed, then re-run red)

| Gate         | What the first pass showed                                                                                                                                                                                                       |
| ------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| B4 row 3     | Green under the break: the test set `in_progress` on a row already `in_progress`, so nothing was written. The test now sets `done` by hand first, then sets the real state by hand.                                              |
| B4 rows 2, 3 | Same cause. Red after the row 3 test was rewritten.                                                                                                                                                                              |
| B13          | Green under the break: the fixture helper called the provisioner (which takes the wave lock itself) inside the recorded request, so the log always began with a wave lock. `P::row()` now provisions only when rows are missing. |
| B20          | Green under the first mutation (a docblock held the first match, and other calls in the file kept the needle). The mutation now replaces every call in the file.                                                                 |
| B14          | Green under the first mutation (patched a resource the list does not use). Moved to `WaveController`.                                                                                                                            |

## B19 stayed green, and why

The wave lock is not the only thing holding the hand-set back. The decision (left uncommitted on the first connection)
also updates the `wave_steps` row, and the hand-set's own `lockStep` waits on that row lock. With `lockWave` taking no
`FOR UPDATE` the hand-set still waits and the end state is still `done` / `system`. So the race test proves the **outcome**
(it fails if the hand-set does not wait, or ends `done` / `manual`) and is not the gate for the wave lock: B13 is (the query
log has to show the wave `FOR UPDATE` before any write, and the first `FOR UPDATE` of the request must be on `waves`).
The free-running race (two children released by an advisory lock) ended with the decision first and the hand-set
accepted in 6 of 6 runs; the other consistent outcome (hand-set refused 409) was not observed. The forced test
(decision uncommitted, hand-set starts, waits, then sees the committed state) covers the ordering the free run does not reach.
