# PL-3.2a review file

Chunk: PL-3.2a, Script editor (structure, rail, brief, hooks in English, notes, the archive and carry-over choice on Remove and Replace, autosave).
Branch `pl`. Stage 1 (backend) was accepted by Pedram. This file covers Stage 2 (web) up to the hand-over for the walk. **Nothing is pushed.**
Date of the real-browser pass: 2026-11-24. Design source: Figma file `lYy90qzKk029vjoVCPExs2`, frame **13:3108** (08 · Step 6, Script editor, 1600 x 3027).

## 1. Fidelity table against frame 13:3108 (the parts built)

Method. Frame values come from `get_design_context` on the sub-nodes 13:3293 (Scripts list), 13:3359 (editor head), 13:3379 (workflow bar),
13:3405 (brief), 13:3450 (Hooks header) and 13:3457 (Hook A). Built values are computed styles read in a real Chromium at **1600 x 1000** on
`pl32_walk` (the dev tool `apps/main/tools/walk/shots-pl32.mjs` and a measuring script in `/tmp`, not committed). "Match" means the computed value
equals the frame value to the pixel.

**VISUAL differences found and fixed in this pass (6):**

| Element                      | Frame                                                                                                                     | Built before                                                    | Built now                                                                                                                                              | Class         |
| ---------------------------- | ------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------- |
| Editor slot chip             | 11 / 700, padding 2 x 6, radius 4                                                                                         | 12 / 700, padding 3 x 8, radius 6                               | 11 / 700, 2 x 6, radius 4                                                                                                                              | VISUAL, fixed |
| Editor title                 | 20 / 600, "Name — Script 1" (em dash), chip gap 8                                                                         | 18 / 600, "·" separator, gap 12                                 | 20 / 600, em dash in all 24 locales, gap 8                                                                                                             | VISUAL, fixed |
| Sub line spacing             | 4 under the title                                                                                                         | 2                                                               | 4                                                                                                                                                      | VISUAL, fixed |
| Workflow bar and pill        | box padding 10; pill padding 5 x 6, 12                                                                                    | box 10 x 14; pill 3 x 12                                        | box 10; pill 5 x 6                                                                                                                                     | VISUAL, fixed |
| "Hooks" header with Add hook | heading 17 / 600, top padding 6; button 13 / 500, padding 6 x 12, radius 8, 30 high, border #2e2e33; at the top right     | no heading; the button sat under the last hook, 14 px, 40 high  | heading 17 / 600; button 13 / 500, 6 x 12, radius 8, 30 high, border #2e2e33 at the top right (new key `app.strategy.scripts.hooks.title`, 24 locales) | VISUAL, fixed |
| Shot dropdown                | 210 wide, #232326, radius 8, padding 6; items 12, padding 10 x 6, radius 5; selected tint rgba(62,207,158,.1) and #3ecf9e | 210 wide, shadow right; items 40 high, 16 px, radius 0, no tint | items 27 high, 12 px, 10 x 6, radius 5; selected tint and colour as the frame; box radius 8, padding 6, #232326                                        | VISUAL, fixed |

**Match (measured, nothing to fix):** rail title 15 / 600; rail creator card (#1b1b1d, 1 px #2e2e33, radius 10, padding 10, gap 4); persona chip in the rail
(10 / 700, 1 x 5, radius 4, border at 70 %); creator name 13 / 600; script row (7 x 8, radius 6, 13; selected row border 50 % and fill 10 % of the brand
green); status 11 and dot 7; Add script 12 with 5 x 8; footnote 11 / 16; brief card (fill 5 %, border 30 %, radius 10, padding 16, gap 12, label column
80); USP pills (3 x 9, radius 999); duration chip (4 x 10, radius 6, 13 / 500); hook card (#1b1b1d, radius 10, padding 16, gap 12, 668 wide); hook title
15 / 600; label column 112 with top padding 2; shot chip (3 x 8, radius 6, 12 / 500); shot dropdown width 210 and shadow 0 12 32 rgba(0,0,0,.55).

**VISUAL, not changed, for Pedram's ruling (1):** the sub line, the rail count, the brief labels and other tertiary text use the platform theme token
`text-tertiary` (rgb 138, 138, 146), not the frame's #6e6e76 (110, 110, 118). The token is used by every agency page; changing it is a platform decision,
not a PL-3.2a one. The built text is lighter than the frame.

**FUNCTIONAL differences (by ruling or by what the chunk builds):**

1. Persona code in chips: the frame says "SK1"; the page shows the persona's real code ("SK-C1"). Data, not design.
2. Rail count: the frame says "5" beside four scripts (a frame slip); the page shows the real count.
3. Statuses in the rail and the workflow bar: the frame shows Client review, Approved, Internal review; the page shows only "Draft", one read-only pill (Q9). PL-3.3.
4. Sub line: "Last edited by Jess · 20 min ago · v4" in the frame; "Last edited {when}" with no name and no version (PL-3.3).
5. "Regenerate", "Generate first script" and "Auto-translated · review": not built (E1).
6. Language toggle (EN, SK, Side by side) and the EN / local columns of a hook: not built (PL-3.2b); hooks are English only.
7. Brief title: the frame says "Brief — pulled from persona + messaging matrix"; the page says "Brief" (24 locales, wording only, not changed).
8. Brief angle: editable, with a count, a source line and a reset (the frame shows plain text); duration options 15, 20, 30, 45, 60 and the estimate "Estimated length: about n s" (the frame: "~20 seconds" and "Estimated from lines: ~19s"); the estimate counts audio hooks only until lines exist (E4, E14).
9. Hook title: the frame has a drag grip; the page has a collapse chevron (reorder is PL-3.2b, E7).
10. "opt." in the frame, "optional" on the page (wording); the Add hook button also shows the count ("Add hook (3/6)") and the limit.
11. Footnote: "Up to 3 scripts per creator by default — add more if needed." in the frame; "Up to {max} scripts per creator" on the page (Q11).
12. Additions with no frame: the save chip ("All changes saved", "Changes not saved: n"), "Delete script", per-hook save status with Retry, the conflict box, the Archived scripts link.
13. Title pill "Scripts in client review", "Preview as client" and "Share with client", the comments card ("1 client comment"): not built (E2, PL-3.3, PL-5).
14. B-roll codes (`{line}_{n}`, Q2) differ from the frame (line minus one): not on this page yet (lines are PL-3.2b). **Note for the design side.**

## 2. Screens built with no frame

Narrow layout (rail as a row, Notes as a side sheet); the empty market, no-creators, no-persona, loading, error and limit states; the delete script
and delete hook confirms; the Archived scripts dialog; the leave dialog (Stay, Try saving again, Leave and lose changes); the conflict box (Keep mine, Use theirs, Copy my
text); the Remove and Replace choices on step 5; the hook menu (Duplicate, Delete). These use the platform dialogs and menus and were opened and
reviewed in the browser; they were **not** measured against a frame because there is none.

## 3. What the page shows in place of the lines section

**Nothing.** The editor ends after the hooks. There is no placeholder text, no disabled "Add line" button and no empty heading (`ScriptEditor.vue`
header comment: nothing is drawn for Lines, B-roll and images, which are PL-3.2b). The only trace is the footnote and the language label, which stay
as in PL-3.1.

## 4. Real browser, on `pl32_walk` (a throwaway database, named and printed before each command)

**Dialogs and menus, opened, closed, and quickly reopened** (open, close, then open-close-open with no wait; then no overlay left):
at **1600 x 1000**: hook menu, shot menu, duration menu, delete script, archived scripts, delete hook, Remove (step 5), Replace picker: **8 of 8**.
At **390 x 844**: the same eight plus the Notes sheet: **9 of 9**. (The three confirm dialogs and the picker are persistent: they close with Cancel, not
Escape; that is the platform behaviour and the check used Cancel.)

**Autosave, six checks, all passed** (run 3 times in full; the first run in a fresh page and every later one passed, see "What went wrong" below):

1. type and wait: "Saved" shown, text there after a reload;
2. type and leave at once (click Creators): the guard saves first, text there on return;
3. type with the API **truly stopped** (the server process killed): "Could not save", the chip is stuck, the text stays, leaving opens the dialog (Stay), the API comes back and Retry saves;
4. two tabs on the same hook: the second tab gets the 409 with their version shown and its own text kept in the field; Keep mine writes it;
5. two tabs on different hooks: both kept, no conflict;
6. the hostile string (`<img src=x onerror=...> {{ 7*7 }} <script>...</script>`): shown literally in the field, nothing injected, no browser dialog.

**What went wrong during the pass (kept for the record):** two of the four full autosave runs failed once each: a sign-in that did not reach the layout in
30 s, and the check 5 save wait of 8 s (the status text did not read "Saved" in time). Neither reproduced in the six runs after it (five isolated runs of
check 5 and two full runs); the cause is not proven. The likeliest cause is a cold Vite transform right after the locale files were edited. Playwright
`pl32-scripts` (7 tests, each under 9 s) passed after the last code change.

**Phone width, found and not mine:** at 390 wide **every** agency page opens with the sidebar drawer pushing the page (`AgencyLayout.vue`:
`const drawer = ref(true)`, "main" gets 280 px left padding and the page scrolls sideways) until the person taps the menu button. The walk tool and the
overlay check close it first. It is the same on `/brands` and `/campaigns`, so it is older than this chunk; it is **not** in `tech-debt.md`.

## 5. Flag-off

Each run alone, the stack stopped.

- **Walk compare, agency set: 126 targets identical** to `ah-115-agency-baseline`; **creator set: 146 targets identical** to `ah-115-creator-baseline` (the 503 answers in the log are the mock API's own).
- **Real browser, `creators_scripts` OFF, `waves` and `strategy` ON, in `pl32_walk` only**, PL-2 (`4b0f0bad`, a temporary worktree, now removed) against this build, as `admin@pl32-walk.local` at 1440: the wave board, the campaign page, USPs, personas, matrix and concepts. **HTML identical and pixels identical on all six** (comment-node counts equal: 224, 103, 78, 104, 50, 64). The PL-2 server was shown to be PL-2 (its `StrategyStepper.vue` has no `creatorsOn`; this build's has five).
- Caveat: the PL-2 copy resolved `@catalyst/api-client` from the current workspace (a symlinked `node_modules`), so the client library was this build's.
- The step 6 address with the flag off leaves for the wave (`StrategyPage.spec`, break-reverted).

## 6. Accepted Stage 1 differences (for the record)

1. `brief_revision` is a separate token, not the script revision.
2. The duration estimate is computed on the server.
3. Replace defaults to `carry_over`.
4. The overview payload (`/strategy/scripts` without a script) carries the rail, the counts and the limits in one read.
5. The `language` column exists and is unused: English only until PL-3.2b.
6. One `wave_script.deleted` audit row per archived script removed together with a market.
7. Hook required fields are **not enforced on a draft**. **Record for PL-3.3:** they are checked at send.
8. `color_key` deviation: the persona chip colour is the server's `color_key` for the persona's slot (the PL-2.1 palette), not a per-script colour. Asserted by test G30 on the brief persona and on the rail persona. The frame draws SK1 and SK3 purple and SK2 and SK4 green; the built chips for the first two creators match (purple, green).
9. Tab-close limit: closing the tab asks through the browser's own native prompt only (`beforeunload`); the page cannot save at that moment and the prompt text is the browser's.
10. The CI and composer memory limit was raised to **1G** (see 7).

## 7. The two tech-debt rows (also in `docs/tech-debt.md`, rows 55 and 56)

- **Pest memory growth.** Per-folder peaks (RSS): Scripts about **119 MB**, Strategy about **215 MB**, Creators about **566 MB**. The Creators folder alone is close to the old limit, which is why the limit is now 1G in CI and in composer. A full run in one process is still not safe to assume.
- **The wave lock on a save.** Every script write takes the wave's row lock. Measured on a seeded wave of 36 slots, 108 scripts and 648 hooks: **median about 8.4 ms, maximum 17.1 ms, 14 queries.** Caveat: one writer, a local machine, commit excluded. Under many editors on one wave the lock serialises them; not measured.

## 8. What was run in this pass

- Vitest by folder, `VITEST_MAX_WORKERS=2`: `src/modules/strategy` + `src/core/i18n` **33 files, 335 tests passed** (after the last code change); `src/modules/waves` earlier in the stage, 9 files, 106 tests.
- Playwright, serial, each alone, on `pl32_e2e` (since dropped): `pl32-scripts` 7 passed (rerun after the fidelity changes), `strategy-workspace` 9, `strategy-completion` 7, `pl31-creators` 6 (those three before the fidelity pass).
- Break-reverts: **38 runs, 37 red, 1 green at first and then fixed** (`pl-3-2-break-reverts.md`), including two in a real browser.
- Walk compares and the flag-off browser compare (section 5); dialogs and autosave in the browser (section 4).
- `vue-tsc`, ESLint and Prettier clean on the web app after the last change.

## 9. What was not read or run

- **Figma, not read:** the Notes and Comments column (13:3952), the market bar (13:3269), the stepper (13:3227), the collapsed hook variants (13:3555 and 13:3622) and every lines section (PL-3.2b). The whole-frame call returned only a sparse outline; the sub-nodes above were read, with the screenshot switched off to save room (the numbers are from the generated code). The Notes panel is the PL-3.1 component and was not re-measured.
- **Not measured numerically:** the hook menu and the duration menu (no frame menu for them), the narrow layout, the dialogs.
- **Not run:** the full Pest, Vitest and Playwright suites (CI); Pint (no PHP changed in this pass); the PostgreSQL Pest file; the admin app and `packages/api-client` full Vitest; the light theme; a phone or a touch profile (the 390 check is a desktop browser at that width); the 22 other locales read by a person; a screen reader; the Figma frame screenshot side by side (numbers only).

## 10. Screenshots (outside the repository)

`/tmp/pl32/shots/`: `scripts-1600.png`, `scripts-390.png` (the page), `ov-1600-*.png` and `ov-390-*.png` (every menu and dialog open),
`autosave-1-saved.png`, `autosave-3-api-stopped.png`, `autosave-3-leave-dialog.png`, `autosave-4-conflict.png`, `autosave-6-hostile.png`.
Flag-off captures: `/tmp/pl32/cmp/cur-*.png` and `pl2-*.png` with their HTML. (`/tmp` is cleared on a restart; copy what is needed.)
