# PL-3.2b review (Stage 1, backend), 2026-11-26

Status: Stage 1 built and pushed; Stage 2 (web) not started. Pedram has not walked anything yet.

## 1. Erasure decision and residual risk

Creator erasure keeps scripts and reference images (ruling 5): they are agency work product, not creator data. **Residual risk: a reference image may show the erased creator.** The image stays. Jordan must confirm this, and it is on the privacy items and the must-confirm list in `production-line-sprints.md`.

## 2. Differences from the plan

- Stored objects are deleted inside the guarded transaction; a failure throws and rolls back (the plan said before it).
- The estimate chooses per block: English, else the first local language of the market.
- The walk seed is a separate `Pl32bWalkSeeder` that calls `Pl32WalkSeeder` first.
- Caveat: an upload that lands after a script delete can leave an orphan object; the sweeper covers pending rows, not rows already gone.

## 3. Migrations

Five new migrations (`2026_11_26_100000` to `100004`) plus an in-place edit of `2026_11_24_100002` (drop `wave_scripts.language`). Rehearsal 3 is in `pl-3-2-migration-rehearsal.md`. `pl32_walk` needs `migrate:fresh`.

## 4. Tests

Break-reverts: `pl-3-2b-break-reverts.md` (36 mutations; the layered-defence cases are noted there). Local runs: sqlite `:memory:` for Strategy (444 passed, 39 skipped Postgres-only), Audit (50), Erasure (338), Tenancy (9), TestHelpers (146), Waves (205), Database (35), Console (89); Postgres `pl32b_test` for Strategy (all passed after one test fix: a fixture wrote a field value Postgres rejects) and Database (35). Larastan whole project clean; Pint clean.

## 5. For Stage 2

`strategyErrors.ts` still maps the old language code; Stage 2 updates it.

## 6. Not run, not read

Production scheduler, queue and GD GIF support are not read. No browser walk (Stage 2).

## 7. CI

Tip `f71bd674`, run `45737913339`: Frontend, Backend, E2E admin SPA and E2E main SPA all success. (Run `45737901879` on the earlier plan commit `77d5e73b` was cancelled by this push.)

## 8. Stage 2 progress log

- Step 1 done (2026-11-26): databases listed (`pl32_e2e` already absent, nothing to drop); `catalyst_test` is the CI/PG-leg throwaway (docs/07-TESTING.md); production-404 gate is `GatingTest` H1 (16 pass, sqlite); tech-debt rows 60 and 61 present.
- Step 3 (Ruling 10) started: frame read (13:3696, 3803, 3916, 3936, 3379, 3689); VISUAL fixes: collapsed height 48, chevron on the right, image borders from the outline token (#3a3a40). Table in the final report.
- Step 2 (part): new spec pl32b-scripts.spec.ts (13 tests, pl32b_e2e) covers lines, reorder, drag, B-roll, limits, languages, refusals, conflict, stale reorder, ghost, staff, phone. Fixed: dismissing a deleted-line card now removes it. Found: browser uploads need a real object store (the local fallback URL does not exist), so uploads are walked on MinIO.
- Step 2 (browser flows) and Step 4 web part: unit specs for useReorder, ScriptLinesSection, ScriptLanguageToggle, ScriptLanguagesDialog, ScriptLineCard, ScriptImageField; strategy folder 326 pass, architecture + i18n 1020 pass; vue-tsc, ESLint, Prettier clean. Fixed: hex literal in a comment (colour gate).
- Step 4 backend: archived counts test added (two scripts, ready vs reserved image); sqlite 8 pass, Postgres pl32b_test Scripts/ 102 pass (dropped); break-reverts AC1-AC3, U1-U12 in pl-3-2b-break-reverts.md.
- Step 4 e2e on pl32b_e2e (dropped after): pl32b-scripts 12 passed, pl32-scripts 12 passed, pl23-concepts 6 passed, strategy-workspace 9 passed. Flag-off: agency walk 126 targets, 114 identical and 12 auth-desktop styleHash differences that also appear on unchanged a855c728 (tech-debt row 62); creator baseline not reproducible (row 59), not run again.
- Step 5: walk stack up on pl32b_walk (reseeded, flags on in that database only): API 8000, main 5173, admin 5174 answer 200; login and the scripts page smoke-tested read-only; uploads run on MinIO.

## 9. Walk fixes (2026-11-27, after Pedram's walk), local commits only, not pushed

Walk: 26 steps, all passed except step 17; steps 13 and 20 led to rulings (see `production-line-sprints.md`, "PL-3.2b rulings after Pedram's walk").

- **FIX 1 (step 17), the mechanism.** Everything in the editor is addressed by the block's own id and its own revision, never by a number or a place: a save is `PUT .../lines/{ulid}` with that line's `revision` (hooks the same, B-roll rows by their own ulid inside their line's save), and the server looks the line up by `script_id` plus ULID under the script lock. A line number is `position + 2` computed on read; a hook letter the same. So text typed in a tab that still shows a deleted line can never be saved onto the line that moved up: the server answers 404 `wave_script_line.deleted` and writes nothing (a backend test and three browser tests prove this, and break-reverts B2, B2b and PW4 break it on purpose). What was really wrong: (a) a line the person had edited and saved, then deleted elsewhere, vanished silently on the next list refresh (the ghost card was only kept for unsaved text); (b) hooks had no "deleted by someone else" card or Copy at all, and a deleted hook answered a generic 404. The literal sequence of the walk (type, delete in the other tab, keep typing) already showed the box and the conflict in my runs; the notice was missing when a refresh landed before the next keystroke. Fixed: a line or hook the person wrote in stays as a deleted card with their text and Copy; a hook 404 has its own code `wave_script_hook.deleted`; the moved-up line keeps its own revision, so the conflict box appears on it.
- **FIX 2, 3, 4, 5** as in the rulings. Migrations edited in place (`2026_11_26_100001` lines: `shot_type` string(24) with a Postgres CHECK of the ten, `visual_description` text; `2026_11_24_100002` scripts: `setting` text), both still schema-only with `SET LOCAL lock_timeout = '5s'`. Rehearsal 4 is in `pl-3-2-migration-rehearsal.md`.
- **FIX 5, before and after.** Before: Replace and Add already restored in the ruled order (carried first, then lowest number first, as far as three allow, the rest archived: `ScriptsSlotTest` G9g), but nothing told the person; Katarína ended with three scripts with no warning. After: the picker's rows carry `archived_scripts` for the asked market and the dialog says "Archived scripts of X that come back: n" and, when some stay, "Left archived because three live scripts is the limit: n", for each chosen creator, following the carry-over / archive choice.
- **Also fixed:** the editor head (title squeezed to a vertical column with three languages: the head wraps and the title keeps a readable width at 1600).
- **Shot type list check.** The built menu has the ten types of the frame, in the frame's order. Names: the built English labels are now "Wide shot", "Medium shot", "Close-up", "Detail shot", "POV", "Over the shoulder", "Top-down", "Screen recording", "Reaction shot", "Creator to camera" (the four that said "Wide", "Medium", "Detail", "Reaction" before follow the frame). The hook's chip menu uses the same labels.
- **Difference table additions (Ruling 10).**

| Part                                        | Frame                                                | Built                                                                                                              | Class                               |
| ------------------------------------------- | ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | ----------------------------------- |
| Line, open card: "Visual" row               | none for the open row (13:3696 is the collapsed row) | label with an eye icon and "optional", the shot chip, an auto-growing box, built from the hook's "Visual hook" row | NO FRAME, built from the hook row   |
| Line, collapsed: "· Visual: {shot}"         | 13:3936: "· Visual: creator to camera"               | same text, lower case, next to the quote and the shots count                                                       | MATCHES                             |
| Brief: "The overall setting of the video"   | none                                                 | label, auto-growing box, grey example, counter from 400 of 500, under Duration                                     | NO FRAME, built from the Brief rows |
| Shot-type labels                            | "Wide shot", "Close-up", "Detail shot", ...          | the same ten; four labels renamed to match                                                                         | VISUAL, fixed                       |
| Replace / Add notice about archived scripts | none                                                 | a bordered note above the choice                                                                                   | NO FRAME                            |

- Tests (this window): see the final report. Break-reverts: `pl-3-2b-break-reverts.md`, section "Walk fixes".
- Found, not fixed: at a window about 1100 px wide the three columns (rail, editor, notes) leave the editor about 170 px; the replace dialog's question line is cut at 390 px (both existed before and are not in this prompt).
