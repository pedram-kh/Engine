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
