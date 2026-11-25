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

## 6. Difference table (frames read with `get_design_context` on 2026-11-25: rail 13:3293, head 13:3359, hook and brief fields in 13:3108 at 1600 px; built screen measured in a real browser at 1600 px and 390 px)

| Element                                  | Frame                                                                                                                                                                                                              | Built (measured)                                                                                                                                                                                                                                                                                      | Class                                                                                |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------ |
| "Archived scripts (n)" entry in the rail | **none**: the rail ends with the "Add script (n/3)" row and the 11/16 footnote                                                                                                                                     | outlined button, 13/500, padding 6x12, 1 px border, 14 px icon, 30 px high: identical to the page's "Add hook" button                                                                                                                                                                                 | no frame; matched to the page's own secondary action. Not a visual difference to fix |
| "Archive script" action in the head      | **none**: the head has the SK chip, the title (20/600), "Last edited by Jess · 20 min ago · v4" (12 px, `#6e6e76`), the accent "Regenerate" button (13/500, 6x12, radius 8) and the EN / SK / Side by side control | quiet text button, 13/500, icon 14 px, 32 px high; the Regenerate button's type is 13/500 6x12, so type and padding agree. The frame node's rendered height was not measured (the tool gives padding and type, not the final box), so "32 px against about 31 px" is a computation, not a measurement | no frame; type agrees. Not changed                                                   |
| Hook fields (EN)                         | label column 112 wide, gap 12, text 13/19 `#ededed`, no input chrome, translations `#a0a0a8`                                                                                                                       | text 13 px / 19 px line, `rgb(237,237,237)`, padding 0, border 0, Inter; field starts at x=717 (593 + 112 + 12); one line is as high as the frame's, then the box grows                                                                                                                               | visual: **agrees** (measured). Growth with content is functional (Pedram ruled it)   |
| Angle field (brief)                      | label column 80, text 13/19 `#ededed`                                                                                                                                                                              | 13 px / 19 px, `rgb(237,237,237)`, padding 0, border 0; starts at x=685                                                                                                                                                                                                                               | visual: **agrees** (measured)                                                        |
| Rail cards, "Add script" row, footnote   | cards `#1b1b1d`, border `#2e2e33`, radius 10, padding 10; row 12 px `#6e6e76` padding 5x8; footnote 11/16                                                                                                          | unchanged since PL-3.2a; not re-measured in this pass                                                                                                                                                                                                                                                 | not re-checked, said plainly                                                         |
| Tertiary text colour                     | `#6e6e76`                                                                                                                                                                                                          | `#8a8a92` (platform token)                                                                                                                                                                                                                                                                            | left for the style guide work (ruling)                                               |

**Functional differences, listed for Pedram, left as built:**

1. The frame head shows "Last edited by Jess · 20 min ago · v4"; the built head shows the last-edited text in its own format and has no version number (versions are PL-3.3).
2. The frame head has the accent "Regenerate" button and the EN / SK / Side by side control; neither is built (generation is PL-9, languages and side by side are PL-3.2b).
3. The frame has no archive or restore at all; "Archive script" and "Archived scripts" come from Pedram's walk rulings.

**Phone width (done in this pass):** at 390 px the archived button used to be the last item of the sideways-scrolling rail row, off screen. The rail head, cards and footnote now scroll inside their own row and the button sits **under** that row, at x=24, fully on screen, with no sideways scrolling of the page. Real browser, 390 px and 1600 px: `/tmp/pl32/shots/page-390.png`, `/tmp/pl32/shots/page-1600.png`. A Playwright test now pins it (break-reverted, see the break-revert file).

## 7. Tests run (counts and database)

- Vitest `src/modules/strategy` and `src/core/i18n`, `VITEST_MAX_WORKERS=2`: **34 files, 340 tests passed**. vue-tsc, ESLint and Prettier clean.
- Pest, sqlite in memory: Strategy `Scripts` folder 35 passed (9 Postgres-only skipped), `ConceptSchemaTest` 7 (2 skipped), `Pl32WalkSeedersTest` 4, Audit 50, Erasure 338 (1 skipped), Tenancy 9, `tests/Unit/Modules/Strategy` 4, `tests/Unit/TestHelpers` 5, the test-helper `CreateAgencyWithAdminTest` 5 (one new case). Pint and Larastan clean on the touched helper.
- Pest on real Postgres, `catalyst_test` (127.0.0.1:5435, the designated test database; `catalyst` was not touched): `Scripts` folder **44 passed**, `ConceptSchemaTest` **9 passed**. The matrix and persona schema tests were not run in this pass.
- Playwright, serial, one browser, throwaway **`pl32_e2e`** (walk API stopped): `pl32-scripts` **12 passed** (new: the staff test, the phone-width archived button), `strategy-workspace` **9 passed**. `strategy-completion` and `pl31-creators` were **not re-run** in this pass (they were green in the fix pass; this pass touched neither them nor shared code they use except the Playwright fixture file, which only gained a function).
- `pl32_walk`: walk stack and real-browser checks only. No flag was written outside throwaway databases.

## 8. Flag-off

- **Agency walk baseline, 126 targets: run alone, flags off, all 126 identical.**
- **Creator walk baseline, 146 targets: could not be reproduced on this machine.** The compare reports 638 differences and 32 page errors (the mock API's 503 answers). Control runs on an **unchanged** `7abc9969` and on `main` `09169b5a` fail the same way (664 differences), and clearing the Vite cache did not help. So it is not caused by this pass. Likely cause, **not proven**: the baseline was recorded on macOS 27.0 and this Mac is on 27.2.1 (the walk tool's thumbnails are OS sensitive). Tech-debt row 59.
- `cmp-pl32.mjs` was **not re-run** (it needs a PL-2 worktree plus the walk stack).
- What covers the creator set instead: this pass changed no file outside the strategy module except the Playwright fixture and a test-only API helper, none of which creator pages use; the Scripts components mount only inside the Scripts step, which exists only with the flag on; the strategy Vitest and Playwright specs pass with the flags off outside the scripts specs.

## 9. Not run, not read

- The live check of the old build for the staff-delete finding. The creator walk compare and `cmp-pl32.mjs` (above). `strategy-completion` and `pl31-creators` Playwright, and the matrix and persona schema tests, in this pass.
- The permanent delete as admin through the API on `pl32_walk` (it would have destroyed the only archived script); covered by `ScriptsApiTest` G4d.
- The rail cards, "Add script" row and footnote were not re-measured against the frame.

## 9a. Staff in Playwright (done)

The test-helper endpoint `/api/v1/_test/agencies/setup` takes two optional fields, `agency_ulid` (join an existing agency) and `role`. `seedAgencyStaff` in the Playwright fixtures uses it, so the staff member is a real agency-side user (a user made through sign-up is a creator and sees none of the agency-side features). The test "staff: can archive and restore, sees no Delete for good, and the permanent-delete endpoint answers 403" is in `pl32-scripts`, with a Pest case for the helper change.

## 9b. The two one-off autosave failures

Neither recurred in the way it was first seen. The "waiting for Saved" failure was my test text (146 characters against an overlay limit of 100, then a 112-character overlay in the growth test): test data, fixed. The sign-in timeout (one keep-mine reproduction run) was never reproduced and its cause is unknown. **One new failure** in this close-out: in the first serial run of `pl32-scripts` the archive test ("archive script asks and counts the hooks ...") failed on a `toBeVisible` wait; two reruns of the whole spec (12 of 12) and the other specs passed, and the screenshot of the first failure was overwritten by the reruns, so I have no trace. All three are in tech-debt row 58 as **watched**.

## 10. Screenshots (outside the repository, `/tmp/pl32/shots`)

`grow-1600.png`, `grow-390.png`, `s3-admin-1600-editor.png`, `s3-admin-390-editor.png`, `s3-staff-1600-archived-dialog.png`, `s3-staff-390-archived-dialog.png`, `s3-staff-390-rail.png`, `s3-{admin,staff}-{1600,390}-archive-dialog.png`, `s3-{admin,staff}-{1600,390}-archived-dialog.png`.
