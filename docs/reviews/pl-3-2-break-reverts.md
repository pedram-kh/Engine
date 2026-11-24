# PL-3.2a — break-reverts (Stage 1, backend)

Each row: one temporary break in the code (or migration), the test that went red, then the revert and the same test green again.
All were run by file with `--filter`, one at a time. SQLite rows ran on `:memory:`; PG rows ran on the throwaway `pl32_test`
(Docker `catalyst-postgres`, port 5435), never on `catalyst`. The harness restored every file from its saved text after
each run; `git status` was clean afterwards.

Test files: **API** = `tests/Feature/Modules/Strategy/Scripts/ScriptsApiTest.php`, **SLOT** = `ScriptsSlotTest.php`,
**SRC** = `ScriptsSourceTest.php`, **PG** = `ScriptsPostgresTest.php` (group `pg`).

## SQLite

| Gate                         | The break                                                                     | Red                         | After revert |
| ---------------------------- | ----------------------------------------------------------------------------- | --------------------------- | ------------ |
| G1 flags                     | the all-three-flags check always passes                                       | API G1                      | green        |
| G2 tenancy                   | script looked up without its wave                                             | API G2                      | green        |
| G2b tenancy                  | slot looked up without its wave                                               | API G2b                     | green        |
| G3 roles                     | archived delete open to staff (policy **and** service check removed)          | API G3                      | green        |
| G4 cap                       | loop allows a 4th number                                                      | API G4, G4b                 | green        |
| G4 lowest number             | `count + 1` instead of lowest free                                            | API G4                      | green        |
| G4b cap counts archived      | archived scripts ignored when choosing a number (a creator in another market) | API G4b                     | green        |
| G9a Remove needs a choice    | check replaced by a default                                                   | SLOT G9a                    | green        |
| G9b delete is admin/manager  | role check removed                                                            | SLOT G9b                    | green        |
| G9d restore on Add           | restore not called                                                            | SLOT G9d                    | green        |
| G9d same market only         | market filter dropped from restore                                            | SLOT G9d                    | green        |
| G9e carry-over on Replace    | always archives                                                               | SLOT G9e                    | green        |
| G9g carry-over over 3        | cap check removed                                                             | SLOT G9g                    | green        |
| G9h renumber                 | renumbering skipped                                                           | SLOT G9h                    | green        |
| G11 limits                   | audio limit 301                                                               | API G11                     | green        |
| G11 control characters       | `StrategyText::clean` removed                                                 | API G11                     | green        |
| G11b language                | English-only check removed                                                    | API G11b                    | green        |
| G12 constant queries         | a texts query per hook                                                        | API G12                     | green        |
| G12 constant queries         | a scripts query per slot on the rail                                          | API G12                     | green        |
| G13 revision (hook)          | stale check removed                                                           | API G13                     | green        |
| G13 revision (brief)         | stale check removed                                                           | API G13                     | green        |
| G13 no-change save           | early return removed (a no-change save bumps)                                 | API G13                     | green        |
| G13b six hooks               | limit 7                                                                       | API G13b                    | green        |
| G13b last hook               | last hook can be deleted                                                      | API G13b                    | green        |
| G14 one choke point          | a bare `DB::transaction(` in the hook writer                                  | SRC G14                     | green        |
| G15 audit has no script text | `angle` value added to the audit payload                                      | API G15                     | green        |
| G15 audit key decided        | a new, undecided key `script_text`                                            | `AuditPayloadKeysGuardTest` | green        |
| G15 erasure registry         | the `wave_scripts` rule removed                                               | `ErasureRegistrySchemaTest` | green        |
| G15 audit catalogue          | an action renamed                                                             | `AuditActionEnumTest`       | green        |
| G15b erased creator          | name shown after erasure                                                      | SLOT G15b                   | green        |
| G16 notes                    | `scripts` removed from the allowed steps                                      | API G16                     | green        |
| G17 market removal           | confirm check removed                                                         | SLOT G17                    | green        |
| G17 market removal           | scripts not deleted with the market                                           | SLOT G17                    | green        |
| G20 brief live               | stored angle ignored                                                          | API G20                     | green        |
| G20 brief live               | priority USP not first                                                        | API G20                     | green        |
| G21 estimate                 | 3 words per second                                                            | API G21                     | green        |
| G22 step state               | never in progress                                                             | API G22                     | green        |

## PostgreSQL (`pl32_test`)

| Gate                 | The break                                                           | Red                         | After revert |
| -------------------- | ------------------------------------------------------------------- | --------------------------- | ------------ |
| G14 lock order       | the wave row lock removed from `ScriptGuard::transaction`           | PG G14 (1 failed, 7 passed) | 8 passed     |
| G4b cap              | CHECK `number BETWEEN 1 AND 3` removed                              | PG G4b                      | 8 passed     |
| G4b unique           | unique `(wave, creator, number)` made a plain index                 | PG G4b                      | 8 passed     |
| G5 archived xor slot | CHECK removed                                                       | PG G5                       | 8 passed     |
| G5 composite FK      | FK narrowed to `(wave, slot)`; market and creator no longer checked | PG G5 and G9                | 8 passed     |

## What the first pass found (fixed in this commit)

- **G4b was not testing its name.** With archived scripts ignored, the test still passed, because the archived scripts had
  already been restored by the time the fourth was created. I added the real case: archived scripts of the same creator in
  another market hold their numbers, and a fourth is refused with `meta.archived = 2`. The break now goes red.
- **G3 break was too small.** Removing only the service check stayed green because the policy refuses first (two layers,
  both refuse). The break now removes both and goes red.
- **G15 key guard.** `angle` was already a decided key from PL-2.1, so it never tested the guard. A new key (`script_text`)
  does.

## Not red: stated plainly

With the wave lock removed, the PG race tests for G4 (two creates) and G13 (two saves) **stayed green**; only the
lock-order test G14 went red. They are held by the slot row lock (create) and the script row lock plus the unique
constraint (save), so the wave lock is the first of several guards, not the only one for these two races. The wave lock
is still what orders the writers that touch several scripts (archive, restore, carry-over); G14 is the test for that.

## Wave-lock hold of a save (G3 measurement)

Walk-like data on PG (`pl32_test`): 36 slots in 3 markets, 108 scripts, 648 hooks. 25 hook saves (audio text, 20 words),
run twice. Measured from the start of `select ... from waves ... for update` to the last query of the transaction:

|                             | median              | max                 | min               |
| --------------------------- | ------------------- | ------------------- | ----------------- |
| wave lock held (14 queries) | 8.4 ms and 8.5 ms   | 17.1 ms and 12.0 ms | 8.2 ms and 8.1 ms |
| whole request               | 12.5 ms and 12.7 ms | not recorded        | not recorded      |

This is a local measurement on one machine, with one writer and no other traffic; it says how long the lock is held, not
how it behaves under load. It does not include the commit itself.

A text autosave could take only the script-row lock without risking a deadlock, by analysis (not tested, and I did not check
that every multi-script writer locks its scripts in one fixed order): the other writers take the wave lock and then script
locks, never the reverse, so a writer holding only a script lock never waits for the wave lock. It would
then not wait behind a Replace or a market removal for the wave, but it must re-check `archived_at` and the slot after the
script lock (as `lockedScript` already does). I did not build this; at about 8 ms per save the plan's choice (wave lock for all
writers) stands, and I left it for Pedram to decide whether it is worth the second lock path.

## Stage 2 (web): break-reverts

Same rule as above: break the source, run the named spec, see it red, restore the file, run again and see it green. Each run was alone, by spec file (`VITEST_MAX_WORKERS=2`; the api-client spec from `packages/api-client`; the G30 test with Pest on SQLite). `git status` showed no source change left behind after the last run.

**35 breaks. 34 red, 1 stayed green (row 22, explained). Every restore went back to green.**

| #   | Gate                                              | Break                                    | Spec                                                        | Result                                                                                                                                                                                                     |
| --- | ------------------------------------------------- | ---------------------------------------- | ----------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Autosave waits for the pause                      | send at once (0 ms)                      | `useBlockAutosave.spec`                                     | red                                                                                                                                                                                                        |
| 2   | Save sends the revision it holds                  | send 0                                   | `useBlockAutosave.spec`                                     | red                                                                                                                                                                                                        |
| 3   | A late answer never eats typing                   | always apply the answer to the draft     | `useBlockAutosave.spec`                                     | red                                                                                                                                                                                                        |
| 4   | A failed save stays failed                        | mark it saved                            | `useBlockAutosave.spec`                                     | red                                                                                                                                                                                                        |
| 5   | Retry after 3 s and 10 s                          | no timer                                 | `useBlockAutosave.spec`                                     | red                                                                                                                                                                                                        |
| 6   | A 4xx is refused, not retried                     | treat every 4xx as retryable             | `scriptsLogic.spec`, `useBlockAutosave.spec`                | red                                                                                                                                                                                                        |
| 7   | A 409 keeps the person's text                     | replace the draft with theirs            | `useBlockAutosave.spec`                                     | red                                                                                                                                                                                                        |
| 8   | Keep mine uses their revision                     | resend the old revision                  | `useBlockAutosave.spec`, `ScriptsStep.spec`                 | red                                                                                                                                                                                                        |
| 9   | Leave guard on route change                       | allow the route                          | `ScriptsStep.spec`                                          | red                                                                                                                                                                                                        |
| 10  | Leave guard on tab close (`beforeunload`)         | no `preventDefault` and no `returnValue` | `ScriptsStep.spec`                                          | red (the first try broke only `preventDefault`; jsdom, like a browser, also cancels on `returnValue = ''`, so both lines are the gate)                                                                     |
| 11  | Hook flushes before it collapses                  | skip the flush                           | `ScriptsStep.spec`                                          | red                                                                                                                                                                                                        |
| 12  | Hostile text is plain text                        | `v-html` on the conflict text            | `noVHtml.spec`, `ScriptsStep.spec`                          | red                                                                                                                                                                                                        |
| 13  | Empty hook goes at once, one with text asks       | never ask                                | `ScriptsStep.spec`                                          | red                                                                                                                                                                                                        |
| 14  | Delete script asks first                          | delete without the dialog                | `ScriptsStep.spec`                                          | red                                                                                                                                                                                                        |
| 15  | Delete dialog shows the hook count                | pass 0                                   | `ScriptsStep.spec`                                          | red                                                                                                                                                                                                        |
| 16  | Add script is off at the cap                      | enabled for a full creator               | `ScriptsStep.spec`                                          | red                                                                                                                                                                                                        |
| 17  | No creators: link to step 5                       | link removed                             | `ScriptsStep.spec`                                          | red                                                                                                                                                                                                        |
| 18  | Archived delete only for `canPurge`               | always shown                             | `ScriptsStep.spec`                                          | red                                                                                                                                                                                                        |
| 19  | Archived list is read fresh on each open          | no read on open                          | `ScriptsStep.spec`                                          | red                                                                                                                                                                                                        |
| 20  | Remove defaults to archive                        | default delete                           | `CreatorsStepScripts.spec`                                  | red                                                                                                                                                                                                        |
| 21  | Remove: delete only for admin or manager          | offered to staff                         | `CreatorsStepScripts.spec`                                  | red                                                                                                                                                                                                        |
| 22  | Replace asks only in replace mode                 | ask in any mode                          | `CreatorsStepScripts.spec`                                  | **green** (`scriptCount` is 0 for every add through the page, so the extra `single` check is a second guard that cannot be reached from the page; a direct mount of the dialog would show it; not written) |
| 23  | Replace defaults to carry over                    | default archive                          | `CreatorsStepScripts.spec`                                  | red                                                                                                                                                                                                        |
| 24  | Brief sends only changed fields                   | always send the angle                    | `scriptsLogic.spec`, `ScriptBrief.spec`, `ScriptsStep.spec` | red                                                                                                                                                                                                        |
| 25  | "Use persona angle" sends the reset               | no reset                                 | `ScriptBrief.spec`, `ScriptsStep.spec`                      | red                                                                                                                                                                                                        |
| 26  | Stepper: step 6 live only with the flag           | live without it                          | `StrategyStepper.spec` (incl. the new flag-off test)        | red                                                                                                                                                                                                        |
| 27  | Page: scripts address refused with the flag off   | no redirect                              | `StrategyPage.spec`                                         | red                                                                                                                                                                                                        |
| 28  | New wave: `confirm_scripts_deleted` sent          | not sent                                 | `NewWaveDialog.spec`                                        | red                                                                                                                                                                                                        |
| 29  | api-client `SCRIPT_TEXT_LIMITS` equals the server | angle 301                                | `packages/api-client` `scripts.spec`                        | red                                                                                                                                                                                                        |
| 30  | i18n: key present in every locale                 | key renamed in `de`                      | `scriptsKeys.spec`                                          | red                                                                                                                                                                                                        |
| 31  | i18n: placeholders intact                         | `{max}` renamed in `de`                  | `scriptsKeys.spec`                                          | red                                                                                                                                                                                                        |
| 32  | `color_key` on the brief persona (G30)            | constant                                 | `ScriptsApiTest` G30                                        | red                                                                                                                                                                                                        |
| 33  | `color_key` on the rail and script persona (G30)  | constant                                 | `ScriptsApiTest` G30                                        | red                                                                                                                                                                                                        |
| 34  | Add hook off at six hooks                         | never disabled                           | `ScriptsStep.spec`                                          | red                                                                                                                                                                                                        |
| 35  | The last hook cannot be deleted                   | delete always allowed                    | `ScriptsStep.spec`                                          | red                                                                                                                                                                                                        |

Found while doing this, and fixed with tests before the rows above: nothing set `creators_scripts` in the page-level kit, so the page's flag redirect and its step-6 rendering had no test (now two `StrategyPage` tests); the hook cap and last-hook rule had no test (now one); the stepper had no flag-off test with a scripts overview passed in (now one).

**Not covered by a test, said plainly:** the rail chip colour on the web (only the server side of `color_key` is asserted, G30); "no template ref inside a `v-for`" (a code rule, no test); two Playwright break rows (leave guard, 409), **not run**.
