# PL-3.2b break-reverts (Stage 1, backend)

Every gate was broken on purpose, the tests were run and shown **red**, the change was reverted (by the harness, from a saved copy, in a `finally`), and the same tests were shown **green** again. The harness is `/tmp/pl32b/br.py` (outside the repository): it replaces one exact string in the source, runs the named test files, restores the file, runs them again. A mutation whose "old" text is not found exactly once is not run.

Databases: **sqlite `:memory:`** (the default of `phpunit.xml`) for every row except P1, P2 and N1p, which ran on PostgreSQL `pl32b_test` (127.0.0.1:5435, `phpunit.pgsql.xml`). The `catalyst` database was not touched.

| Gate | What was broken                                                                                 | Tests run                                        | Mutated (red)                                    | Restored (green)      |
| ---- | ----------------------------------------------------------------------------------------------- | ------------------------------------------------ | ------------------------------------------------ | --------------------- |
| H1   | `VerifyTestHelperToken`: the production check removed, so `agencies/setup` runs in `production` | `GatingTest`                                     | 3 failed, 13 passed                              | 16 passed             |
| L1a  | the "at most three local languages" check removed                                               | `ScriptLanguagesTest`                            | 1 failed, 5 passed                               | 6 passed              |
| L1b  | `not_in:en` removed from the language route                                                     | `ScriptLanguagesTest`                            | 1 failed, 5 passed                               | 6 passed              |
| L2a  | removing a language also deletes its line texts                                                 | `ScriptLanguagesTest`                            | 1 failed, 5 passed                               | 6 passed              |
| L2b  | a line text in a language outside the market is accepted                                        | `ScriptLanguagesTest`                            | 1 failed, 5 passed                               | 6 passed              |
| N1a  | line cap: 31 allowed (`>=` becomes `>`)                                                         | `ScriptLinesTest`                                | 1 failed, 14 passed                              | 15 passed             |
| N1b  | line number from position + 1 instead of + 2                                                    | `ScriptLinesTest`                                | 6 failed, 9 passed                               | 15 passed             |
| N1c  | a stale line save no longer conflicts                                                           | `ScriptLinesTest`                                | 1 failed, 14 passed                              | 15 passed             |
| B1a  | B-roll cap: 13 allowed                                                                          | `ScriptLinesTest`                                | 1 failed, 14 passed                              | 15 passed             |
| B1b  | B-roll code without the row number (`2_1`, `2_1`, ...)                                          | `ScriptLinesTest`                                | 3 failed, 12 passed                              | 15 passed             |
| B1c  | a save naming a deleted B-roll row is no longer a 409                                           | `ScriptLinesTest`                                | 1 failed, 14 passed                              | 15 passed             |
| R1a  | reorder accepts any list (the complete-set check always passes)                                 | `ScriptLinesTest`                                | 3 failed, 12 passed                              | 15 passed             |
| R1b  | a reorder moves every line revision                                                             | `ScriptLinesTest`                                | 1 failed, 14 passed                              | 15 passed             |
| I1a  | image block cap: 11 allowed                                                                     | `ScriptImagesTest`                               | 1 failed, 14 passed                              | 15 passed             |
| I1b  | script total checked without the asked size at the reservation                                  | `ScriptImagesTest`                               | 1 failed, 14 passed                              | 15 passed             |
| I1c  | script total not checked again at the verify (real size)                                        | `ScriptImagesTest`                               | 1 failed, 14 passed                              | 15 passed             |
| I1d  | GIF no longer accepted                                                                          | `ScriptImagesTest`                               | 2 failed, 13 passed                              | 15 passed             |
| I1e  | one content layer off (the sniff accepts anything)                                              | `ScriptImagesTest`                               | **15 passed (not detected)**                     | 15 passed             |
| I1e2 | BOTH content layers off (the sniff and the stored-type check)                                   | `ScriptImagesTest`                               | 1 failed, 14 passed                              | 15 passed             |
| I1f  | a GIF gets no thumbnail                                                                         | `ScriptImagesTest`                               | 1 failed, 14 passed                              | 15 passed             |
| I2a  | a failed object delete no longer stops the row delete                                           | `ScriptImagesTest`, `ScriptsChildrenTest`        | 2 failed, 20 passed                              | 22 passed             |
| I2b  | the sweeper removes a fresh reservation too                                                     | `ScriptImagesTest`                               | 1 failed, 14 passed                              | 15 passed             |
| A1   | permanent delete forgets the stored objects                                                     | `ScriptsChildrenTest`                            | 3 failed, 4 passed                               | 7 passed              |
| E1a  | the estimate sums all hooks                                                                     | `ScriptsGatesTest` (E1)                          | 2 failed, 1 passed                               | 3 passed              |
| E1b  | the estimate ignores the market-language fallback                                               | `ScriptsGatesTest` (E1)                          | 2 failed, 1 passed                               | 3 passed              |
| T1a  | one image route skips the flag check                                                            | `ScriptsGatesTest` (T1)                          | 1 failed, 3 passed                               | 4 passed              |
| T1b  | permanent delete open to staff: BOTH the policy and the service check removed                   | `ScriptsApiTest`                                 | 2 failed, 18 passed                              | 20 passed             |
| S1a  | the presenter asks one query per line (N+1)                                                     | `ScriptsGatesTest` (S1)                          | 1 failed                                         | 1 passed              |
| S1b  | a service opens its own bare `DB::transaction(`                                                 | `ScriptsSourceTest`                              | 1 failed, 3 passed                               | 4 passed              |
| Z1   | a typed text added to an audit payload                                                          | `ScriptsGatesTest`, the whole `Erasure` folder   | 2 failed, 1 skipped, 345 passed                  | 1 skipped, 347 passed |
| Z2   | a new table left out of the erasure registry                                                    | the whole `Erasure` folder                       | 1 failed, 1 skipped, 337 passed                  | 1 skipped, 338 passed |
| P1   | the images exactly-one-owner CHECK dropped (PostgreSQL)                                         | `ScriptsPostgresTest` (the CHECKs test)          | 1 failed                                         | 1 passed              |
| P2   | the line-texts composite foreign key to the line dropped (PostgreSQL)                           | `ScriptsPostgresTest` (the CHECKs test)          | 1 failed                                         | 1 passed              |
| N1p  | NEITHER the wave lock NOR the script row lock taken (PostgreSQL, parallel children)             | `ScriptsPostgresTest` (the three parallel tests) | 2 failed (line add, image reservation), 1 passed | 3 passed              |
| N1q  | as N1p AND the line row lock off (a line save then has no lock at all)                          | `ScriptsPostgresTest` (the three parallel tests) | 3 failed                                         | 3 passed              |

## What the table does not show, said plainly

- **Layers that cover each other.** Four gates have two independent layers, so breaking ONE layer does not turn a test red, and that is by design: (1) the content check of an uploaded image is the header sniff **and** the stored-type check (I1e: one off, green; I1e2: both off, red); (2) the permanent-delete role is the policy **and** the service (breaking only the policy left the 3.2a test green; T1b breaks both); (3) the wave lock **and** the script row lock: breaking only the wave lock left all three parallel tests green (first run of N1p), breaking both turned two red; (4) the two-saves-of-one-line race stayed green with the wave and script locks off (N1p), because a line save also locks the line row itself (`ScriptLineService` line lookup, `FOR UPDATE`); with that third lock off too (N1q) all three parallel tests turned red.
- **Role check on the line, B-roll and image routes.** `Gate::authorize('manageScripts')` is "any agency role", which is the same as being a member of the agency, and a non-member is already a 404 before the policy is reached (the same shape as the PL-3.2a routes). Removing the gate from one route therefore changes nothing a test can see. There is no agency user without a role to test it with. I did not claim a break-revert for it.
- **P1 on Postgres only:** the CHECKs and composite keys do not exist on sqlite, so those two ran on `pl32b_test`.
- The H1 break-revert covers the production check of the middleware. The provider's own "do not register the routes in production" layer is untouched and was not mutated here (it was the PL-0 gate).

## Stage 2 (web) and the archived counts

Same method: break the code, show the specs red, restore from git, show them green. Databases: Vitest has no database; the API rows ran on sqlite `:memory:`; the archived-counts test also passed whole on PostgreSQL `pl32b_test` (102 tests of `Scripts/`, dropped afterwards). The `catalyst` database was not touched.

| Gate | What was broken                                                               | Tests run                                    | Mutated (red)                                                      | Restored (green) |
| ---- | ----------------------------------------------------------------------------- | -------------------------------------------- | ------------------------------------------------------------------ | ---------------- |
| AC1  | archived list: `lines` counted from the hooks                                 | `ScriptsChildrenTest`                        | 2 failed, 6 ok                                                     | 8 passed         |
| AC2  | archived list: images counted without the READY filter (a reservation counts) | `ScriptsChildrenTest`                        | 1 failed, 7 ok                                                     | 8 passed         |
| AC3  | archived list: every script gets the sum of all images                        | `ScriptsChildrenTest`                        | 1 failed, 7 ok                                                     | 8 passed         |
| U1   | reorder: every answer confirmed (moves not folded)                            | `useReorder.spec`                            | 1 failed, 2 ok                                                     | 3 passed         |
| U2   | reorder: a refusal swallowed                                                  | `useReorder.spec`                            | 1 failed, 2 ok                                                     | 3 passed         |
| U3   | lines: Add never off at 30 of 30                                              | `ScriptLinesSection.spec`                    | 1 failed, 6 ok                                                     | 7 passed         |
| U4   | lines: limit text from 20 instead of 25                                       | `ScriptLinesSection.spec`                    | 1 failed, 6 ok                                                     | 7 passed         |
| U5   | languages dialog: no cap on local languages                                   | `ScriptLanguagesDialog.spec`                 | 1 failed, 4 ok                                                     | 5 passed         |
| U6   | images: a wrong type accepted                                                 | `ScriptImageField.spec`, `scriptsLogic.spec` | 1 failed, 12 ok                                                    | 13 passed        |
| U7   | images: the 300 MB of the script not checked                                  | `ScriptImageField.spec`                      | 1 failed, 5 ok                                                     | 6 passed         |
| U8   | images: the block-full check removed                                          | `ScriptImageField.spec`                      | **6 passed (not detected)**, then a test was added: 1 failed, 6 ok | 7 passed         |
| U9   | language toggle drawn for an English-only market                              | `ScriptLanguageToggle.spec`                  | 1 failed, 3 ok                                                     | 4 passed         |
| U10  | CTA chip on every line                                                        | `ScriptLineCard.spec`                        | 1 failed, 5 ok                                                     | 6 passed         |
| U11  | a line deleted elsewhere no longer shows the box                              | `ScriptLineCard.spec`                        | 1 failed, 5 ok                                                     | 6 passed         |
| U12  | reorder sends only one id instead of the whole list                           | `ScriptLinesSection.spec`                    | 1 failed, 6 ok                                                     | 7 passed         |

Not mutated here (guarded by the browser spec or an earlier gate): English not removable (no Remove button exists for it; the dialog spec asserts that), the no-`v-html` rule (`noVHtml.spec`, PL-3.2a), the colour-literal gate (it was red on a hex literal in a comment and is green after the fix).

## Walk fixes (2026-11-27)

Each row: the code was broken on purpose, the named tests were red, then the code was restored and the tests were green. `B*` backend (Pest, sqlite), `V*` Vitest, `PW*` Playwright (`pl32b_e2e`).

| Gate | What was broken                                                           | Tests run                                       | Mutated (red)   | Restored (green)       |
| ---- | ------------------------------------------------------------------------- | ----------------------------------------------- | --------------- | ---------------------- |
| B1   | the hook-deleted 404 gets another code                                    | `ScriptLinesTest`                               | 1 failed, 17 ok | 18 passed              |
| B2   | a line save to a deleted id falls back to the first line                  | `ScriptLinesTest`                               | 1 failed, 17 ok | 18 passed              |
| B2b  | a hook save to a deleted id falls back to the first hook                  | `ScriptLinesTest`                               | 1 failed, 17 ok | 18 passed              |
| B3   | the line's own shot type and visual are never written                     | `ScriptLinesTest`                               | 1 failed, 17 ok | 18 passed              |
| B4   | the shot type is not validated                                            | `ScriptLinesTest`                               | 1 failed, 17 ok | 18 passed              |
| B4b  | the visual description limit removed                                      | `ScriptLinesTest`                               | 1 failed, 17 ok | 18 passed              |
| B5   | duplicate drops the two line fields                                       | `ScriptLinesTest`                               | 1 failed, 17 ok | 18 passed              |
| B6   | the setting is never written                                              | `ScriptLinesTest`                               | 1 failed, 17 ok | 18 passed              |
| B7   | the setting limit removed                                                 | `ScriptLinesTest`                               | 1 failed, 17 ok | 18 passed              |
| B8   | B-roll limit 41 instead of 40                                             | Scripts folder, `Pl32WalkSeedersTest`           | 3 failed        | 101 passed, 15 skipped |
| B9   | the picker counts live instead of archived scripts                        | `ScriptsSlotTest`                               | 1 failed, 12 ok | 13 passed              |
| B10  | restore brings back the highest numbers first                             | `ScriptsSlotTest`                               | 2 failed, 11 ok | 13 passed              |
| B11  | restore ignores the limit of three                                        | `ScriptsSlotTest`                               | 2 failed, 11 ok | 13 passed              |
| B12  | restore wipes the script's setting                                        | `ScriptsChildrenTest`                           | 1 failed, 8 ok  | 9 passed               |
| B13  | the audit row of a brief save carries the setting text                    | `ScriptLinesTest`, Audit folder                 | 1 failed, 67 ok | 68 passed              |
| B14  | the audit row of a line save carries the visual text                      | `ScriptLinesTest`                               | 1 failed, 17 ok | 18 passed              |
| V1   | the payload drops the line shot type                                      | `scriptsLogic.spec`, `ScriptLineCard.spec`      | 2 failed, 18 ok | 20 passed              |
| V2   | the payload drops the setting                                             | `scriptsLogic.spec`, `ScriptBrief.spec`         | 2 failed, 17 ok | 19 passed              |
| V3   | a deleted hook (404) is read as "the whole script is gone"                | `scriptsLogic.spec`                             | 1 failed, 10 ok | 11 passed              |
| V4   | the returning count ignores the carried scripts                           | `scriptsLogic.spec`, `CreatorsStepScripts.spec` | 2 failed, 21 ok | 23 passed              |
| V5   | the picker never shows the archived-scripts note                          | `CreatorsStepScripts.spec`                      | 2 failed, 10 ok | 12 passed              |
| V6   | a saved line that is deleted elsewhere vanishes (ghost only when unsaved) | `ScriptLinesSection.spec`                       | 1 failed, 8 ok  | 9 passed               |
| V7   | the collapsed "Visual:" summary is hidden                                 | `ScriptLineCard.spec`                           | 1 failed, 8 ok  | 9 passed               |
| V8   | the setting counter is always shown                                       | `ScriptBrief.spec`                              | 1 failed, 7 ok  | 8 passed               |
| V9   | the setting limit differs from the server's (parity)                      | `packages/api-client` `scripts.spec`            | 1 failed, 2 ok  | 3 passed               |
| PW1  | a saved line deleted elsewhere vanishes after a refresh                   | `pl32b-scripts` "saved, then deleted elsewhere" | 1 failed        | passed                 |
| PW2  | a saved hook deleted elsewhere vanishes after a refresh                   | `pl32b-scripts` "reads the script again"        | 1 failed        | passed                 |
| PW3  | the hook "deleted by someone else" box is not drawn                       | `pl32b-scripts` "Hook B deleted in another tab" | 1 failed        | passed                 |
| PW4  | a save to a deleted line id lands on the first line                       | `pl32b-scripts` "I keep typing"                 | 1 failed        | passed                 |
| PW5  | the line revision check is off (the moved-up line loses its conflict)     | `pl32b-scripts` "moves up and takes the number" | 1 failed        | passed                 |
| PW6  | the hook revision check is off                                            | `pl32b-scripts` "Hook C that becomes B"         | 1 failed        | passed                 |
| PW7  | the B-roll "row is gone" check is off                                     | `pl32b-scripts` "a row deleted in another tab"  | 1 failed        | passed                 |

Not detected, kept on purpose: the explicit `wave_script_hook.deleted` branch in `classifyFailure` is redundant with the "any other 404 is gone" line below it (the same answer either way), so removing only that branch changes nothing; the unit test pins the code's meaning through `isGone` instead (V3). Not mutated: the editor head wrapping (a layout fix, checked in the browser at 1600 and 390).

## Close-out (2026-11-27): no B-roll limit, the walk seed, the flag-gate test

| Gate | What was broken                                                                                               | Tests run                              | Mutated (red)   | Restored (green) |
| ---- | ------------------------------------------------------------------------------------------------------------- | -------------------------------------- | --------------- | ---------------- |
| C1   | the safety ceiling set back to 40                                                                             | `ScriptLinesTest`, `ScriptsSourceTest` | 3 failed, 20 ok | 23 passed        |
| C2   | the add-row ceiling check removed                                                                             | `ScriptLinesTest`                      | 1 failed, 18 ok | 19 passed        |
| C3   | the duplicate-line ceiling check removed                                                                      | `ScriptLinesTest`                      | 1 failed, 18 ok | 19 passed        |
| C4   | the presenter sends a B-roll number again                                                                     | `ScriptLinesTest`                      | 1 failed, 18 ok | 19 passed        |
| C5   | the ceiling message carries a number                                                                          | `ScriptLinesTest`                      | 1 failed, 18 ok | 19 passed        |
| C6   | the seed back to 40 shots                                                                                     | `Pl32WalkSeedersTest`                  | 1 failed, 6 ok  | 7 passed         |
| C7   | Eva removed with "delete" instead of "archive"                                                                | `Pl32WalkSeedersTest`                  | 3 failed, 4 ok  | 7 passed         |
| C8   | Eva not an applicant of the campaign                                                                          | `Pl32WalkSeedersTest`                  | 3 failed, 4 ok  | 7 passed         |
| V10  | web: Add shot off from 40 rows                                                                                | `ScriptLineCard.spec`                  | 1 failed, 9 ok  | 10 passed        |
| V11  | web: a counter drawn again from 35                                                                            | `ScriptLineCard.spec`                  | 1 failed, 9 ok  | 10 passed        |
| V12  | web: the ceiling code not mapped to its message (first mutation was NOT detected, 5 passed; a test was added) | `scriptsLogic.spec`                    | 1 failed, 11 ok | 12 passed        |
| PW8  | browser: Add shot off from 40 rows                                                                            | `pl32b-scripts` "limits"               | 1 failed        | passed           |
| F1   | flag-gate test K1 with the clock not frozen and one tick between the reads (the flake made deterministic)     | `StrategyFlagGatesTest`                | 1 failed, 3 ok  | 4 passed         |
