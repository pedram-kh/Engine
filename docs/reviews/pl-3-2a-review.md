# PL-3.2a review: Pedram's walk and the fix pass (2026-11-25)

Branch `pl`, on top of `7abc9969`. Commits (local, not pushed): `8ec4f6eb` text boxes, `539cf322` archive backend, `1a506053` archive web, `0549c60f`
Playwright, plus the commit with these records.

## 1. The walk

Steps 1 to 20 passed, except **4** (text boxes did not grow), **9** ("Keep mine" seemed to be remembered) and **15 and 18** (Delete in the editor was
permanent and open to every role, while permanent delete in the archive was admin and manager only).

## 2. What each fix changed

1. **Text boxes grow.** The spoken hook, the visual hook description, the overlay and the brief's angle are one new component, `AutoGrowTextarea`: a plain textarea
   that is re-measured on typing, on a new value (load, conflict reload), when its width changes (narrow screens) and when the fonts have loaded, and when a
   collapsed hook is opened. No inner scroll bar (overflow hidden, height follows the content). Autosave per block, the leave guard and the counters are untouched.
   At 390 px the hook fields were also only 142 px wide (a column layout that did not stretch them); they are now 308 px.
2. **"Keep mine" covers one conflict.** See section 3.
3. **Archive script.** The editor action is "Archive script" for every role (the confirmation counts the hooks and says it can be restored). The script goes to
   "Archived scripts" with its hooks and texts untouched. **Restore** is offered to every role; the archived dialog says why a row cannot be restored (creator not
   in the market; creator already has three). Permanent delete exists only in the archive, for admin and manager. The old `DELETE` of a live script is gone from the API
   (405). Numbers: a live number is unique among a creator's live scripts in a wave (partial unique index); archived scripts hold none and do not count toward
   three; restore keeps the number if free, else the lowest free; "Add script (n/3)" counts live only. Replace with carry-over no longer renumbers.
   Audit: `WaveScriptArchived` is reused (its payload is unchanged, literal keys); no new audit action.
4. **"Archived scripts" is a button**: outlined, archive icon, label and count, the same as "Add hook" (13 px / 500, padding 6 x 12, radius 8, 1 px border `#2e2e33`, 30 px high; measured on both).
5. **Estimated length.** Before and after: the server's estimate counts the spoken (audio) words of the **longest hook only**, 2.5 words per second, rounded up; visual
   descriptions, overlays and the brief are not counted, the hooks are never summed. This was already how it was built; the docblock was wrong (it said the browser
   computes it; the browser shows the server's number, renewed by each save of a hook, so it follows the text once saved). Docblock fixed, a unit test added
   (`ScriptEstimateTest`, 4 tests, break-reverted).

## 3. FIX 2: the mechanism, in plain words

Reproduced in a real browser with two tabs on the same script, in both tab orders (the tab that saves first, and the tab that chooses "Keep mine", swapped), and as a
Playwright test. **Nothing remembers "Keep mine".** The choice takes the revision from the conflict answer once, resends the text once, and the tab then holds the
revision the server gave back. Every later save carries that revision. When the other tab saves in between, the first tab's next save is a **409 again** and the
notice shows again. **The text added in the other tab survived every time** (checked by reload in a third view).
The one quiet case: a stale tab whose text becomes **exactly equal** to the text the server already holds. That is no real change, so the server writes nothing, shows no
conflict, and the tab adopts the current revision. That is harmless (nothing is overwritten) and is the most likely thing Pedram saw. I could not reproduce a save without a revision check.
Rules in code and tests: no force or overwrite mode exists; a block save without a current revision is refused (G13c); the Playwright sequence asserts the second conflict.
One intermittent sign-in timeout happened in one reproduction run and did not recur; its cause is unknown.

## 4. The staff-delete finding

**The path was the editor's permanent "Delete script", which every agency role could use** (`manageScripts`). The archive's permanent delete was already admin and manager only
(`deleteScriptsInUse`; the button shown only when `canDecide`). That is established from the code of the build Pedram walked; I did **not** run the old build to confirm it
live (optional check, not run). Staff could therefore destroy a live script but not an archived one. Now: staff archive and restore; staff get **403** on the permanent
delete (API and in the browser no button); admin and manager delete from the archive; another agency gets 404 (`ScriptsApiTest` G4d). Checked on `pl32_walk` as
`staff@pl32-walk.local`: Archive script and Restore shown, no "Delete for good", `DELETE .../scripts/archived/{id}` answers 403; as admin the button is shown.

## 5. Migration

Edited in place (`100002_create_wave_scripts_table`): schema-only, `SET LOCAL lock_timeout = '5s'`. Rehearsed through `artisan migrate` on the throwaway `pl32_rehearsal`.
Locks: see "Rehearsal 2" in `pl-3-2-migration-rehearsal.md` (same set as before; `ShareRowExclusive` on `agencies`, `creators`, `users`, `wave_creators`, `wave_markets`, `waves`; 26.7 ms with empty tables).
`StrategySchemaTest` L9 updated. `pl32_walk` rebuilt (`migrate:fresh`) and reseeded: Lucia 2 scripts, Martin 3, Katarina none, Tomas 1, one archived script (Jana), Astrid in Nordics with 1.

## 6. Difference table (frame 13:3108, rows added)

| Element                                  | Frame                                                                                                  | Built                                                                                        | Class                                                |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| "Archived scripts (n)" entry in the rail | **none** (rail 13:3293 ends with the footnote; read as metadata only)                                  | outlined button with icon, same metrics as the page's "Add hook" button (measured identical) | no frame; matched to the page's own secondary action |
| "Archive script" in the editor head      | head 13:3359 was **not re-read** in this pass; the slot is the old Delete slot, same text-button style | text button with an archive icon, same slot and style as before                              | not checked against the frame                        |
| Hook fields growing with content         | frame field heights not re-read in this pass (Pedram ruled the growth)                                 | grow to the content; first line at the same size and position (13 px / 19 px)                | functional (ruled by Pedram)                         |
| Tertiary text colour                     | `#6e6e76`                                                                                              | `#8a8a92` (platform token)                                                                   | left for the style guide work                        |

At phone width the rail is one sideways-scrolling row (old behaviour); the archived button is the last item of that row, so on 390 px it sits off screen to the right until the row is scrolled. **Reported, not changed.**

## 7. Tests run (counts and database)

- Vitest `src/modules/strategy` and `src/core/i18n`, `VITEST_MAX_WORKERS=2`: **34 files, 340 tests passed**. vue-tsc clean, ESLint and Prettier clean (by lint-staged at commit).
- Pest, sqlite in memory: Strategy `Scripts` folder 35 passed (9 Postgres-only skipped), `StrategySchemaTest` 10, `Pl32WalkSeedersTest` 4, Audit 50, Erasure 338 (1 skipped), Tenancy 9, `ScriptEstimateTest` 4.
- Pest on real Postgres, throwaway **`pl32_fix_test`** (127.0.0.1:5435): `Scripts` folder and `StrategySchemaTest`: **54 passed** (including the 9 that sqlite skips).
- Playwright, serial, one browser, throwaway **`pl32_e2e`** (walk API stopped for it): `pl32-scripts` **10 passed**, `strategy-workspace` 9, `strategy-completion` 7, `pl31-creators` 6.
- `pl32_walk`: used by the walk stack and the real-browser checks only. The `catalyst` database was not touched; no flag was written outside throwaway databases.

## 8. Flag-off

Not re-captured with the pixel comparison (`cmp-pl32.mjs`, the 126 and 146 target walk compare): **not run** this pass. What ran: `StrategyPage.spec`, the stepper spec and the strategy Playwright specs
(flags off outside the scripts specs) pass; the changed components only mount inside the Scripts step, which exists only with the flag on.

## 9. Not run, not read

- The live check of the old build for the staff-delete finding. The pixel flag-off comparison. The editor head frame 13:3359. Playwright for the staff role (no staff seeding helper): the staff checks were done with a measuring script on `pl32_walk` (screenshots below) and in Pest.
- The permanent delete as admin through the API on `pl32_walk` (it would have destroyed the only archived script); covered by `ScriptsApiTest` G4d.

## 10. Screenshots (outside the repository, `/tmp/pl32/shots`)

`grow-1600.png`, `grow-390.png`, `s3-admin-1600-editor.png`, `s3-admin-390-editor.png`, `s3-staff-1600-archived-dialog.png`, `s3-staff-390-archived-dialog.png`, `s3-staff-390-rail.png`, `s3-{admin,staff}-{1600,390}-archive-dialog.png`, `s3-{admin,staff}-{1600,390}-archived-dialog.png`.
