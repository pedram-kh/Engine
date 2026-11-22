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

## Walk improvements (N1 to N4), each gate red then green

| Gate                                              | Break                                            | Caught by                                                                                                            | Broken | Restored |
| ------------------------------------------------- | ------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------- | ------ | -------- |
| W1 a note just added scrolls into view            | the scroll after an add is removed               | `NotesThread.spec`                                                                                                   | red    | green    |
| W2 the list scrolls inside the panel              | `overflow-y: visible` on the list                | Playwright "30 notes scroll"                                                                                         | red    | green    |
| W3 the panel stays in view while the page scrolls | `position: static`                               | Playwright "30 notes scroll" (first pass stayed green: it only checked "not below the bar"; now also "not above it") | red    | green    |
| W4 never taller than the window, composer in view | the measured max-height is not applied           | Playwright "30 notes scroll"                                                                                         | red    | green    |
| W5 at 390 the panel is static and capped          | sticky and no cap at 390                         | Playwright "at 390"                                                                                                  | red    | green    |
| X1 one composer at a time                         | the thread is drawn while the large view is open | `NotesPanel.spec`                                                                                                    | red    | green    |
| X2 the draft is kept between panel and large view | the sheet no longer shares the draft             | Playwright "expanded view"                                                                                           | red    | green    |
| X3 focus returns to the expand control            | the focus call is removed                        | Playwright "expanded view"                                                                                           | red    | green    |
| H1 "Set by hand" has its own line under the name  | the line goes back into the status cell          | `WaveStepsPanel.spec`                                                                                                | red    | green    |
| P1 the stepper line carries the markets           | the joined line shows only the count             | `StrategyStepper.spec`                                                                                               | red    | green    |

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

# Stage 2 (web) break-reverts

Each row: the source file is mutated, the named test is run and must go red, the file is restored in a `finally`, the
test is run again and must go green. Run by `/tmp/pl25/breaks.py` (kept outside the repo); the results below are its
output. Vitest tests run under `apps/main`, Playwright under `--project=chromium --workers=1`, Pest on SQLite.

| Gate                                                                                      | Break                                       | Test                                           | Broken | Restored |
| ----------------------------------------------------------------------------------------- | ------------------------------------------- | ---------------------------------------------- | ------ | -------- |
| N1 a note is drawn as text, never HTML                                                    | `{{ body }}` becomes `v-html`               | `NotesThread.spec`, `noVHtml.spec`             | red    | green    |
| N2 a late answer for the market you left does not paint into the one you are on           | drop the sequence check on the success path | `NotesThread.spec`                             | red    | green    |
| C1 warnings are shown in a confirm and never block                                        | the warnings branch never opens             | `StepCompletionBar.spec`                       | red    | green    |
| C2 an empty step is refused, with no confirm button                                       | the empty branch never opens                | `StepCompletionBar.spec`                       | red    | green    |
| C3 Concepts has no Mark / Reopen button (automatic)                                       | Concepts counts as markable                 | `StepCompletionBar.spec`                       | red    | green    |
| S1 the stepper shows the real state                                                       | every step reads "not started"              | `StrategyStepper.spec`                         | red    | green    |
| T1 a hand-set beyond the real step does nothing until confirmed (Cancel changes nothing)  | the confirm result is ignored               | `WaveTrackerSection.spec`                      | red    | green    |
| T2 the "set by hand" marker with what the step says                                       | marker never rendered                       | `WaveStepsPanel.spec`                          | red    | green    |
| T3 a hand-set Done row stays in the open list (its marker stays in sight)                 | the row is folded into the completed line   | `trackerFormat.spec`                           | red    | green    |
| M1 removing a market with notes sends the count the person saw                            | `confirm_notes_deleted` is not sent         | `NewWaveDialog.spec`                           | red    | green    |
| E1 the matrix side sheet closes on Escape                                                 | the Escape handler returns at once          | `NotesSheet.spec`                              | red    | green    |
| E2 same, in a real browser                                                                | same                                        | Playwright `strategy-completion` "side sheet"  | red    | green    |
| L1 the landing carries the market                                                         | the market query is dropped                 | `completionLogic.spec`                         | red    | green    |
| L2 "Strategy & Scripts" opens the server's landing                                        | always replace to USPs                      | Playwright `strategy-completion` "USPs: mark"  | red    | green    |
| L3 with the flag off nothing under `/strategy` is asked of the server                     | the entry page skips its flag check         | Playwright `strategy-workspace` "strategy off" | red    | green    |
| A1 the audience-title answer carries steps, landing and note counts (found in this stage) | the controller leaves the steps block out   | Pest `Pl25GatesTest` B21                       | red    | green    |

## What the first pass found weak (fixed, then re-run red)

| Gate | What the first pass showed                                                                                                                                                                                                                                                                                                                                                          |
| ---- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| L3   | Stayed green: the workspace page redirects on a flag that is off by itself, so the entry page's own check changed nothing anyone could see. The Playwright test now records every request to `/api/.../waves/{wave}/strategy...` made while the flag is off and expects none (the entry page's check exists so that nothing is requested first). Red after the fix, green restored. |

## Not separately gated

`StrategyPage.onSummary` keeps `steps`, `landing` and `note_counts` when an answer arrives without them. It is a guard
behind the server fix (A1) and has no test of its own; A1 is the gate that matters.
