# PL-3.1 review file

## Stage 1 (backend): accepted by Pedram, 2026-11-23

Pushed tip `b097c62b`; CI run `45081699059`, all four jobs green. Accepted differences from the plan:

1. The Creators step state is computed in the creators payload (per market and per wave); `ugc.creators` stays hand-set until PL-3.3.
   `StrategyStepState::STEPS` is not extended.
2. `persona.in_use_by_creators`: a persona that creators are matched to cannot be deleted (422), because the composite foreign key is RESTRICT.
3. The wave-delete guard and the campaign link lock are active whenever creator rows exist (not only while the flag is on).
4. `features.creators_scripts` is always emitted on `/me` (a `false` key while the flag is off), as `strategy` is.
5. The test helpers of the Creators specs carry the `pl31` prefix (they clashed with other files in a full run).
6. B20: the unforced two-process race is not a gate; the forced test (a held, uncommitted wave lock) is.
7. B37 (every creator writer is inside the wave-locked transaction): its break-revert was not isolated (the broken call was fatal).

## Stage 2 (web): settle-first additions

- **A1** `POST .../waves/{wave}/campaign` (`WaveService::createCampaignForWave`): shares `makeWaveCampaign` and the audit rows with
  `createWithNewCampaign`; one transaction on the row-locked wave; refused with `wave.campaign_already_linked`. Linking an existing campaign
  uses the PL-1.2 options list and wave PATCH. Tests: `CreatorsLinkTest`.
- **A2** the picker rule, stated once in the plan (section 1.5). **G2 finding:** the invitation gate (`AssignmentInviteGate`) refuses
  not-approved, non-discoverable, erased and hard-blacklisted creators but **does not check exclusivity** (`AssignmentInviteGate.php:47` is
  the blacklist predicate). Exclusivity is enforced at apply time (`CreatorJobBoardController.php:268`, `BrandExclusivity::isBlocked`) and in the
  job fan-out (`BrandExclusivity.php:136`, `excludeBlockedCreators`); `JobsBoardVisibility` (`JobsBoardVisibility.php:66-78`) is creator-side and deliberately
  leaves exclusivity out. **Decision taken:** the picker applies the exclusivity rule's own predicate (`excludeBlockedCreators`), so a creator
  blocked from this brand by another brand's exclusivity never appears and is refused, as the walk data requires. The earlier read-only
  `exclusive_elsewhere` hint is removed from the candidates payload. Tests: `CreatorsApiTest` B6, one case per exclusion
  (not approved, erased, deletion-scheduled, hard agency, hard brand, exclusive elsewhere), each with a break-revert (rows 32 to 36).

## Stage 2 (web): frame comparison (WORKING-PROCESS 4.2, ruling 10)

Frames read with `get_design_context`: 13:1953 (creators page, card) and 13:2572 (picker). Built screens opened in a real browser on
`pl31_walk` at 1440 and 390 wide (`apps/main/tools/walk/shots-pl31.mjs`; screenshots in `/tmp/pl31/shots`). The picker, the replace
picker, the remove confirmation and the decision dialog were each opened, closed and reopened quickly.

| Element                                          | Frame value                                    | Built value                                      | Class                                                    |
| ------------------------------------------------ | ---------------------------------------------- | ------------------------------------------------ | -------------------------------------------------------- |
| Creator card width, photo, radius, padding, gap  | 291, 200, 10, 16, 10                           | same                                             | match                                                    |
| Picker modal, card, photo, columns, gap          | 1180 wide r14, 214, 150, 5, 14                 | same                                             | match                                                    |
| Declined card                                    | 60% opacity                                    | 60% opacity                                      | match                                                    |
| Stepper, step 5 line                             | short line                                     | "Creators: 3 · 0 of 2 markets" ran under step 6  | VISUAL, fixed: the line wraps, only while step 5 is live |
| Picker footer count                              | "3 selected" and the names                     | "Selected: 3" and the names                      | FUNCTIONAL (copy), listed                                |
| Picker confirm button                            | "Add 3 creators"                               | "Add creators (3)"                               | FUNCTIONAL (copy), listed                                |
| Picker subtitle                                  | "from your creator platform (531 creators)"    | "From the applicants and invitees of <campaign>" | FUNCTIONAL (the source is the campaign, plan 1.5)        |
| Picker filters                                   | Country, Language, Category, Talent pool chips | Country chips only                               | FUNCTIONAL (the candidates API takes country only)       |
| Picker card tag                                  | "Connected"                                    | "Applied" / "Invited"                            | FUNCTIONAL (the source differs)                          |
| Picker "Already in wave"                         | no market                                      | names the market                                 | FUNCTIONAL (required by the brief)                       |
| Card location                                    | city and language                              | region and country, language                     | FUNCTIONAL (no city column)                              |
| Card photo                                       | portrait                                       | initial on a gradient when there is no photo     | FUNCTIONAL (walk data has no photos)                     |
| Card status                                      | select                                         | read-only pill with a hint                       | FUNCTIONAL (accepted: the status follows the campaign)   |
| Client decision box and history link on the card | not in the frame                               | added                                            | FUNCTIONAL (brief item 6)                                |
| Verified tick, "Example content"                 | shown                                          | not shown                                        | data absent in the walk, built only when the data exists |
| Page header actions (Approval, Preview, Share)   | present                                        | Notes and Add creators only                      | FUNCTIONAL (later sprints)                               |
| Market tab                                       | code chip                                      | the shared market tab (name and count)           | FUNCTIONAL (shared with steps 1 to 4)                    |

Also checked in the real browser: no-campaign wave (Link and Create panel), empty market with the persona warning, step 5 at 390 wide
(the stepper scrolls inside itself), the picker at 390 wide with a selection, the decision dialog at 390 wide.

## Stage 2 (web): flag-off comparison, real browser

`creators_scripts` OFF, `waves` and `strategy` ON, in `pl31_walk` only (restored to ON afterwards). PL-2 (`4b0f0bad`, a temporary
worktree on the same API and data, then removed) against the built tip, five pages as `admin@pl31-walk.local`, 1440 wide
(`tools/walk/cmp-pl31.mjs`): the wave board, the wave's campaign page, the Evergreen campaign page, the strategy workspace, the concepts step.
Normalised HTML of `main` **byte-identical on all five**, full-page screenshots **byte-identical on all five**.
The first pass found one difference: the first stepper CSS fix wrapped the status line of steps 5 and 6 while the flag was off (the
notes panel's `max-height` moved by 28px). The rule is now scoped with `:has()` to a live step 5; the second pass is the identical result above.

## Stage 2 (web): the walk result (Pedram, 2026-11-23 and 24)

Walked locally on `pl31_walk`, steps 1 to 20: **all passed.** Three improvements asked (F1 to F3), rulings recorded, close-out.
Walk-script corrections for the record: **Pia and Tomáš are not seeded in the wave and must be added first** (they are invitees on the
campaign, not wave creators); **"hidden" is the creator Hana Skrytá** (applied, not discoverable, and she does appear).

## Stage 2 (web): the improvements

- **F1, the picker card shows the creator's current status.** The candidate payload already carried the derived status (the same four as
  the wave cards: Shortlisted, Contacted, Confirmed, Declined, from the same rule), so this is a card change only. The pill is the first
  line under the tags; **kept:** the origin (Applied, Invited) as a smaller neutral tag under it (it was green and competed with the
  pill). **A declined creator can still be picked:** `selectable()` (`creatorsLogic.ts`) refuses only a creator already in the wave or an
  erased account, never a declined one; that is unchanged, and a test pins it (W26).
- **F2, a ceiling is not shown as a target.** The picker's line under the list: normally **"n creators in <market>"** (no number of
  places); from **25 of 30** (5 places or fewer) **"Up to 30 creators per market · n places left"**; at 30 **"Maximum of 30 creators
  reached in <market>"** and Add is off. The wave cap of 100 follows the same pattern when it is the nearer ceiling (**95 or more**
  of 100, "Up to 100 creators per wave · n places left"; at 100 "Maximum of 100 creators reached in this wave"). The step's own
  notice at the cap says the same words. The margin is one constant (`limitLogic.ts`, 5). **Concepts:** the line under the Add concept
  slot is "n concepts" until 25, then "Up to 30 concepts per market · n places left", at 30 "Maximum of 30 concepts reached".
  USPs ("n of 6") and personas ("n of 8") are unchanged. **Not changed, for Pedram:** the concepts step subtitle still reads "Up to 30
  concepts per market. Each is built on one persona." (a ceiling stated once, as a description, not under the slot); say if it should go.
- **F3, picker wording to the frame.** The footer "n selected", the button "Add n creators" ("Add creators" with none ticked), in all 24
  locales. The count lines are singular|plural pairs of two forms (as en, which the locale-parity test requires). **Locales whose
  plural has more than two categories (cs, sk, pl, sl, hr, lt, lv, ro, ga, mt) use a count-neutral wording** ("Pridať tvorcov: {count}")
  because a two-form pair would render a wrong form for some numbers; they are native-quality for a translator's review, like every
  locale of this sprint. New keys: `picker.inMarket`, `picker.upToMarket`, `picker.upToWave`, `picker.maxMarket`, `picker.maxWave`,
  `limit.placesLeft`, `concepts.upTo`; changed: `picker.selected`, `picker.add`, `concepts.tile`, `concepts.tileMax`; removed:
  `picker.roomMarket`, `picker.roomWave`.

## Stage 2 (web): rulings (Pedram, 2026-11-24)

- The picker keeps **Country as its only filter**; Language, Category and Talent pool are not built. FUNCTIONAL, accepted.
- The summary row stays as built.
- **Exclusivity:** the picker applies the exclusivity check itself, because the invitation gate does not check exclusivity. Accepted.
  Tech-debt row 54 (`docs/tech-debt.md`): `AssignmentInviteGate.php`, the blacklist predicate at line 47, has no exclusivity axis;
  exclusivity is enforced at apply (`CreatorJobBoardController.php:268`) and in the job fan-out (`BrandExclusivity.php:136`). **Whether
  that is intended is not stated anywhere in the code**; recorded as open.
- **The board shows every applicant and invitee; the wave shows only creators added to it; blacklisted and exclusive creators appear
  on the board and never in the picker.** Accepted.

## Stage 2 (web): verification of F1 to F3

Real browser on the walk stack, 1440 and 390 wide: the picker on Creators Wave 1 (the pills Confirmed, Declined, Shortlisted with the
small origin tags; Pia, who accepted, reads Confirmed), "2 selected" and "Add 2 creators", and a throwaway wave (**Cap Wave
(throwaway)**, seeded in `pl31_walk` only, its own campaign, 55 extra creators) with a market of **25** ("Up to 30 creators per market ·
5 places left") and a market of **30** (the step's notice "Maximum of 30 creators reached in Nordics", Add disabled). Screenshots:
`/tmp/pl31/shots-f` (`tools/walk/shots-pl31-f.mjs`). 9 break-reverts (W20 to W28 in `pl-3-1-break-reverts.md`).

**Two failures found only by running the architecture folder, fixed:** `tests/unit/architecture/agency-routes-agency-user-guard.spec.ts`
did not list `strategy.creators`, and `no-hard-coded-colors.spec.ts` found two hex values in comments of `CreatorPickerDialog.vue` and
`WaveCreatorCard.vue`. Both were Stage 2 defects that the earlier local runs (touched folders only) did not reach; they would have been
red on CI. The local-run list now includes `tests/unit/architecture`.

Local results, this pass: Vitest `src/modules/strategy` + `src/core/i18n` + `tests/unit/architecture` (102 files, 1225 tests) passed;
Pest, the Creators files (not the PostgreSQL file) and the Concepts files, SQLite `:memory:` (135 passed, 9 skipped); Pest Audit,
Erasure, Tenancy (403 passed, 1 skipped); the creators Playwright spec, serial, on `catalyst_e2e` (4 passed); walk compare, with the
comment-node check: **agency 126 and creator 146 targets identical**; `vue-tsc` and ESLint clean; Prettier applied.
**Not run locally:** Pint and Larastan (no PHP file changed in this pass); the PostgreSQL Pest file; the full Pest, Vitest and
Playwright suites (CI); the admin app and `packages/api-client` Vitest (untouched).

**Flag-off, real browser.** `creators_scripts` OFF, `waves` and `strategy` ON, in `pl31_walk` only (restored to ON after), PL-2
(`4b0f0bad`, a temporary worktree, removed) against this build, normalised `main` HTML and full-page screenshots, five pages: the board,
the wave's campaign page, the Evergreen campaign page, the strategy workspace (USPs) and the concepts step. **Board, both campaign
pages and the strategy workspace: HTML and pixels identical. The concepts step differs in exactly one text node:** PL-2 "2 of 30
concepts", now "2 concepts" (F2). Nothing else. (A first pair of captures that looked identical was invalid: the page arguments had not
reached the script and every page was blank; it was found by looking at the images, and redone.)

## Stage 2 (web): what was not run

The full suites, locally (CI runs them at the push). Pint and Larastan this pass (no PHP changed). The Pest PostgreSQL leg (by file
only). A concepts market filled to 25 and 30 in the real browser (the tile's three states are tested in Vitest, rows W24 and W25, and
the flag-off page was compared at 2 concepts). A native speaker's check of the 24 locales. Nothing was run on `main`; nothing deployed.

## CI red at 17b8b185 and its fix (2026-11-24)

- Run 45292155710: `E2E — main SPA` failed on `strategy-completion.spec.ts:392` (notes expanded view: focus did not return to
  `notes-expand`); three attempts, same result. The CI trace shows the sheet gone at once and `document.activeElement` on the
  body for the full 5 s (not a timing problem).
- Cause (product regression from Stage 2, commit `df9f0eda`): `StrategyPage.vue` wrapped the completion bar and the step grid
  in `v-for="n in onCreators ? [] : [1]"` to leave the flag-off DOM unchanged. A template ref inside a `v-for` is an array, so
  `panelRef.value?.focusExpand()` and `notesOpenBtn.value?.$el.focus()` found nothing: focus was lost for the notes panel's
  expanded view and for the matrix "Notes (n)" sheet. Bisected locally: `ccec02f5` passes, `df9f0eda` fails, `b097c62b` 3/3 pass,
  `17b8b185` 3/3 fail.
- Fix: both wrappers are `v-if="!onCreators"` (no extra node is rendered when true, so the flag-off DOM is the same). The Creators
  step keeps its `v-for` (it holds no ref).
- Tests: Vitest W4 in `StrategyPage.spec.ts` (breaks when the grid goes back to `v-for`); the matrix Playwright test now asserts
  `notes-open` is focused after Escape (breaks when the bar goes back to `v-for`). Narrow-width Playwright run: not run.

## Pedram's re-check, the closing checks and the clean-up (2026-11-24)

**Re-check.** Pedram re-checked F1 (current status pill on picker cards), F2 (ceilings shown only near the limit) and F3 (picker
footer wording): **all passed.** Accepted as built.

**Red CI.** Run 45292155710 at `17b8b185`: `E2E — main SPA` failed (notes focus), the other three jobs passed. Cause and fix are in the
section above (refs inside a `v-for` on `StrategyPage.vue`, `df9f0eda`); the fix `8aad8c3b` ran green on all four jobs (run 45300621016).

**Flag-off after the focus fix (real browser).** `creators_scripts` OFF, `waves` and `strategy` ON, `pl31_walk` only. PL-2 (`4b0f0bad`,
a temporary worktree) against this build, normalised `main` HTML (comment nodes included, counts compared too) and full-page
screenshots at 1440, as `admin@pl31-walk.local`: the board, the wave's campaign page, the Evergreen campaign page, USPs, personas,
matrix and concepts. Comment-node counts equal on all seven (216, 103, 95, 84, 98, 52, 101). **HTML identical on six; the concepts page
differs in exactly one text node: PL-2 "2 of 30 concepts", now "2 concepts" (F2).** Pixels identical on board, both campaign pages and
USPs. Personas and matrix pixels differ between two runs of PL-2 itself (an unstable animation or timing, not the build); their HTML
is identical. Concepts pixels differ only through that one text. (Invalid passes found and discarded: PL-2 served from a worktree
lost its icon font until the Vite allow list was widened for that temporary copy; two runs passed their arguments through an unsplit
zsh variable and captured a blank page.) Walk compares, each run alone: agency **126 targets identical** to the baseline; creator
**146 targets identical** (the 32 page errors are the baseline's own 503 answers from the mock API).

**Notes on the Creators step.** Pedram's finding was right: the "Notes (n)" button on the Creators step did not get focus back when
the sheet was closed with its close control (Escape did). `CreatorsStep.vue` now returns focus to the button after any close, with a
template ref outside any `v-for` (a close caused by switching market does not take focus). New Playwright test in
`pl31-creators.spec.ts` at 1440 and 390: open, Escape, button focused; open, close control, button focused; it failed before the fix at
1440 (close control) and passes three times in a row after it. **Refs in `v-for`, search of the strategy and waves modules:** no other
case. The refs are `NotesPanel` (`root`, `expandBtn`), `NotesThread` `listEl`, `ConceptReferences` `picker`, `WaveCreatorCard`
`codeInput`, the two dialogs' `card`, and the two on `StrategyPage` / one on `CreatorsStep` named above; none has a `v-for` ancestor
(the `v-for` elements in those files are siblings or children). The waves module declares none.

**Narrow width.** The `mobile` Playwright project only matches `creator-shell-mobile.spec.ts`, so it cannot run these specs; both
specs set their own viewport. `strategy-completion` and `pl31-creators` ran whole in the `chromium` project (13 tests, all passed),
which includes the 390-wide legs of the creators spec and the new notes-focus test at 390. **Not run:** the notes expanded view and
the matrix sheet at 390 (the expanded view's 720px width assertion is a desktop one), and a break-revert of the Creators focus fix at 390.

**Clean-up.** `Pl31WalkCleanupSeeder` ran on `pl31_walk` (named first); afterwards the database held 0 agencies, 0 wave creators and 0
walk users, so the throwaway Cap Wave data went with it. `pl31_walk` was then dropped; it was the only throwaway database of this
chunk (`catalyst`, `catalyst_e2e`, `catalyst_rehearsal`, `catalyst_test` were not touched). The servers on 8000, 5173 and 5174 are
stopped, the temporary PL-2 worktree and the CI artifact copies are removed, no process of mine is left, no `DB_*` variable is
exported. `/tmp/pl31` is left until Pedram says.
