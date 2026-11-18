# PL-1.2 review: campaigns for a wave and the wave board

**Branch:** `pl` (never `main`). **Flag:** `waves_enabled`, default OFF. **Status:** Stage 1 (backend) pushed at `a173a9b2`, CI run 44210687986, all four jobs green. Everything after it, including the model-level `campaign_type` guard and all of Stage 2, is committed locally and **not pushed** until Pedram has walked (`docs/reviews/pl-1-2-plan.md` section 10).

Related files: `pl-1-2-plan.md` (plan, rulings Q1 to Q14, corrections C1 to C4), `pl-1-2-break-reverts.md` (every gate, broken and restored), `pl-1-2-inventory.md`.

## What was built

- **Stage 1 (backend):** `campaigns.campaign_type` (`normal` default, `for_wave`), set on create and immutable (controller answers 422 `campaign.type_immutable`; the model also refuses); `board_columns.stage_key`; the wave board (eight data columns plus Applied, two automations, fixed shape, the Q4 acceptance rule); the link rules (only for-a-wave campaigns can be linked, link and unlink from both sides, locked while a card sits in a working column, row locks); one-transaction create of campaign, board and wave from the New wave dialog (P3); the zero-budget guard for for-a-wave campaigns; erasure decisions.
- **Post-review addition:** `campaign_type` joins `brand_id` in the model-level guard (`CampaignTypeChangeException`). An update that changes the type of a saved campaign throws. The controller's 422 stays the user-facing answer.
- **Stage 2 (web):**
  - Campaign side: the type choice on New campaign (flag ON only, create only; "For a wave" hides the posting switch and sends `creator_posts_content: false`), the type pill on the list and the campaign page, `CampaignWaveCard` on Overview (link, change, unlink, the lock reason, admin and manager only), Settings hides the posting switch for a for-a-wave campaign and explains `campaign.wave_budget_required`.
  - Wave side: `NewWaveDialog` offers three choices (no campaign yet, an existing for-a-wave campaign, create one now with a name); edit can set, change or remove the link until it locks; `WaveDetailPage` shows the lock reason.
  - Wave board: detected from `stage_key`; Applied reads "Applied"; each stage is named in the viewer's language; no column controls and no automations button; a slim card face (name and the Invited / Accepted / Declined label, no fee, no due date); the empty `scripts` slot as PL-1.3's seam (no element); a refused move into a working column shows the acceptance message and the card goes back. **`BoardCardDrawer.vue` is unchanged** (Q13).
  - `packages/api-client`: `put` on the HTTP client (with the CSRF preflight), the new types.
- **Strings:** 33 new keys (and two changed values) in all 24 locales, `waveKeys.spec.ts` now checks 103 keys.
- **Walk seeders:** `Pl12WalkSeeder` and `Pl12WalkCleanupSeeder` with a Pest test; the cleanup does not touch the flag.
- **Process:** `WORKING-PROCESS.md` section 4.1 item 7 (PostgreSQL before a push).

## Walk result (Pedram, 2026-11-18, local, steps 1 to 16)

All sixteen steps passed after the D1 and D2 fixes, except one defect in step 10 (D3 below).

### D1. Menus under the New wave dialog

- **Real cause:** a menu left open when the dialog closed stayed mounted across a fast close and reopen. Vuetify gives an overlay a z-index of the top of its stack plus 10, so the reopened dialog restacked at 2420, above the stale menu at 2410. The menu then painted behind the dialog.
- **The first explanation was wrong.** It blamed `position: absolute` against `position: fixed` and tried a z-index only. That did not fix it for Pedram, and a reproduction in his Chrome showed the stacking numbers above. The fix that holds: the dialog card is keyed per open, and menus are closed on open and on close (`73959b10`), with a fallback z-index (`MENU_ABOVE_DIALOG_Z = 2500`).
- **Gate:** `wave-dialog-menus.spec.ts`, run through `playwright.config.ts` (serial). 2 passed. It had never run through the config before this turn: its first run failed on a wrong import (`SEL` does not exist; the selectors are `dt` and `testIds`) and it did not neutralise the auth throttle. Both were fixed. Break-revert: removing the per-open key makes the reopen test fail (expected 0, received 1); restored, 2 passed.

### D2. Type pill and market list

The type pill stretched to the name column, and the market list sat under the dialog. The pill is sized with an inline style (a scoped style block on the campaign list changed the flag-off DOM by 1856 characters, so none is used). The market list opens beside the chip.

### D3. Invited column labels (step 10)

**Defect.** Pia and Eva had accepted, but their cards read "Contracted". Accepting moves the assignment on to contracted, and `invite_state` only recognised `accepted`.

**What accepting an application does.** It creates an `invited` assignment with an offer. The creator still answers it. There is a waiting state: the card is in Invited, assignment status `invited`, until the creator accepts.

**Where the origin is stored.** Nowhere. It is derived: an accepted `campaign_applications` row for the same campaign and creator means the creator came from an application. `WaveInviteOrigin` reads those rows once per board load (and once per card move). Nothing was added to the database.

**Origin is derived, not stored (record, 2026-11-18).** The application-or-invite origin of a card is computed from accepted application rows and never written. The one case that would be misread, an applicant later invited separately, has no path today. It is on the PL-3 open items, where the creator picker reads the same data.

**Is it reliable for existing rows?** Yes for rows made through the application flow, because the accepted application row is the record of it. One case is misread: a creator who applied, was accepted, and was later also invited by the agency in a separate step on the same campaign would show as application. No second invite path exists for a creator who already has an assignment, so no such row is expected; it is stated, not proven on production data (nothing was run there).

**Labels in the Invited column** (every status that `countsAsSelected()` counts as accepted):

| Origin                | Status             | Label                |
| --------------------- | ------------------ | -------------------- |
| invited by the agency | invited, no answer | Invited              |
| invited by the agency | accepted or later  | Accepted invite      |
| invited by the agency | declined           | Declined             |
| application           | invited, no answer | **Offer sent**       |
| application           | accepted or later  | Application accepted |
| application           | declined           | Declined             |

**FOR PEDRAM: "Offer sent" is my proposal.** It is the waiting state of an accepted applicant (the agency accepted the application, the creator has not answered the offer). Change the word if you prefer another; it is one key, `app.campaigns.board.inviteLabel.offerSent`, in 24 locales.

**Working columns (Selected onward)** keep the assignment status label (Contracted, Filming and so on); no origin is sent there. The Accepted rule (Q4) is unchanged: both accepted labels may move to Selected. Test D3c pins both.

**Browser check on the walk data** (real Chrome, 127.0.0.1:5173): Daniel Kovac = Declined, Eva Marin = Accepted invite, Pia Horvat in Filming = Contracted (unchanged). I accepted Maya's application in the UI and her card read Offer sent; after her accept (a request from her browser session, not a click) it read Application accepted. Plain "Invited" with an invite origin was not on that board; unit and API tests cover it.

**Tests:** D3a (every `AssignmentStatus`), D3b (origin matrix, move response), D3c (working columns); Vitest: six label cases and the working-column case. Break-reverts are in `pl-1-2-break-reverts.md`.

### Q15 (ruled, Pedram, 2026-11-18)

The creator's "Submit your draft" form still appears on a for_wave campaign. Left as it is in PL-1 (Q9). PL-6 replaces it with the footage hand-in. Added to the PL-6 open items: `waves_enabled` must not be turned on in production for real creators before that is decided.

### Test-helper routes are not in production

`SetFeatureFlagController` is reached through `apps/api/app/TestHelpers/Routes/api.php` lines 83 and 85. `TestHelpersServiceProvider::boot()` (lines 53 to 63) returns before registering routes unless `gateOpen()` (lines 76 to 85) is true, and `gateOpen()` needs the environment to be `local` or `testing` and a non-empty token. The provider is registered in `bootstrap/providers.php` line 51. Real boots: `APP_ENV=production` lists 301 routes, none containing `_test`; `APP_ENV=local` lists 322, with `api/v1/_test/feature-flag/{name}` for POST and DELETE. The test "has no \_test routes at all when the app boots in production" in `SetFeatureFlagTest.php` spawns those boots. Break-revert: adding `production` to `gateOpen()` turned it red.

### Local gates after the walk (2026-11-18)

- Pest on SQLite: Waves 104 passed 1 skipped; Boards 87; Brands 93; Campaigns 816 passed 4 skipped; Erasure 336 passed 1 skipped; TestHelpers 141. All exit 0. (One process for all folders ran out of the 128 MB default memory, so folders ran one at a time with `memory_limit=1G`.)
- Pest on PostgreSQL (`phpunit.pgsql.xml`, local throwaway database): Waves 105; Boards 87; Brands 93; Campaigns 820; Erasure 337; TestHelpers 141. All passed.
- Vitest (boards, waves, brands, campaigns, i18n): 50 files, 632 tests passed. vue-tsc `apps/main` and `apps/admin`: clean (one type error in my own spec was found and fixed). ESLint `apps/main` src and playwright: 0 errors, 2 existing warnings. Prettier on changed spec files: clean. Pint: passed. Larastan: no errors. Locale verifier: 24 of 24 pass.
- Flag-off walk: campaign 12 targets and brand 16 targets identical to baseline.
- **Not run:** the full Playwright suite (only `wave-dialog-menus.spec.ts`); CI is the arbiter for the rest.

## Weaker gates, accepted (Pedram, 2026-11-18)

Recorded in plain words, as the review asked:

1. **The Erasure column decisions are not a gate.** Removing the Erasure decisions for `campaign_type` and `stage_key` did not turn any test red (three attempts). The entries stay, but nothing proves them. The real Erasure gate here is the audit-key guard, which has its own break-revert (`E1-audit-keys`).
2. **L2 (one transaction for campaign, board and wave) is red in two of three cases.** The third case, a failure at the campaign step, writes nothing with or without the transaction, so it cannot go red. The two cases that can go red do.
3. **The campaign-create check in `WaveController::store` is defence in depth.** Admin and manager hold both abilities and staff are refused earlier by the wave policy, so no role can reach that line without the permission. It has no break-revert.

## Corrections from the plan review

- **C1, migration rehearsal.** Synthetic data only. 200,000 generated `campaigns` rows and 200,000 generated `board_columns` rows in a throwaway local PostgreSQL database that was dropped afterwards. No production data was copied, exported or sampled. `add_campaign_type_to_campaigns`: 4.15 ms, no table rewrite. `add_stage_key_to_board_columns`: 2.26 ms, no table rewrite. Lock: `ACCESS EXCLUSIVE` on the one table for the duration of the `ALTER`, capped by a 5 s `lock_timeout`. The migrations write no data.
- **C3, can a creator be invited to a draft campaign?** Yes; nothing was loosened. A creator can apply only to a campaign that is listed and is draft, active or paused. The walk campaign is therefore active, listed, budget above 0, with the listing-floor fields (see the seeder).
- **C4, the card drawer.** `BoardCardDrawer.vue` was read in full before any board change. Q13 ruled it stays as it is, and it is unchanged.

## Tests and gates (local, this chunk)

| Gate                                                                                                                | Result                                                                                                                                                                                                                    |
| ------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Pest, `Campaigns`, `Waves`, `Boards`, `Erasure` folders, SQLite                                                     | 1340 passed, 6 skipped (PostgreSQL-only tests and earlier skips; not itemised here)                                                                                                                                       |
| Same four folders, PostgreSQL (throwaway database, dropped)                                                         | 1346 passed                                                                                                                                                                                                               |
| Larastan (whole project)                                                                                            | no errors                                                                                                                                                                                                                 |
| Pint (whole project)                                                                                                | passed                                                                                                                                                                                                                    |
| Vitest, touched set: `tests/unit/architecture`, `src/core/i18n`, `agency`, `brands`, `boards`, `campaigns`, `waves` | 131 files, 1662 tests passed                                                                                                                                                                                              |
| Vitest, `tests/unit/architecture` alone                                                                             | 74 files, 970 tests passed (no frozen file was touched, so no re-pin)                                                                                                                                                     |
| `packages/api-client` Vitest                                                                                        | 9 files, 231 tests passed                                                                                                                                                                                                 |
| `vue-tsc --noEmit` (apps/main, apps/admin)                                                                          | clean                                                                                                                                                                                                                     |
| ESLint (apps/main `src`)                                                                                            | 0 errors, 2 warnings (both pre-existing `v-html` warnings in files this chunk did not touch)                                                                                                                              |
| Prettier (apps/main `src`, `packages/api-client/src`)                                                               | clean                                                                                                                                                                                                                     |
| New and changed specs                                                                                               | `CampaignFormWaveType`, `CampaignWaveCard`, `WaveBoard`, `NewWaveDialog` (3 existing tests updated, 3 added), `WaveDetailPage`, `CampaignDetailPage` (4 added), `waveKeys` (33 keys), `http` (PUT), `Pl12WalkSeedersTest` |

Break-reverts: see `pl-1-2-break-reverts.md`. 20 Stage 2 rows, each red on its break and green after the restore.

## Flag-off proof

The walk uses its own mock API with no `features.waves`, so the flag is OFF by construction. Its DOM hash is of `outerHTML`, so a stray `<!--v-if-->` comment node changes it.

- `node tools/walk/walk.mjs --set agency --only campaign --compare ../../docs/reviews/ah-115-agency-baseline/baseline.json`: **COMPARE OK: 12 targets identical to baseline.json**, 0 page errors (campaigns, campaign-create, campaign-detail; dark and light; 1280 and 390 wide).
- Same command with `--only brand`: **16 targets identical** (the PL-1.1 proof, repeated on the final tree).
- **The check is real.** With the type choice written as a `v-if` the same command reports the four `campaign-create` targets different, `domLen` +11 (`<!--v-if-->`). Restored.
- **Comment-node rule used throughout:** every new control that is hidden with the flag OFF (type choice, posting switch, type pills, Wave card, scripts slot) draws through a zero-or-one-item list, never a `v-if`. No new HTML comment was added to a template (a template comment shows up in the dev DOM); notes live in script blocks.
- **Normal board:** the board is not a walk route (it is a tab). It is covered by `WaveBoard.spec.ts` (stored names, Applications title, controls, fee and due date kept, and the comment-node count equal to the pre-chunk count of 55, measured on `a173a9b2`).
- The agency CSS and status-tone files are not edited (`v-chip` and `AgencyStatusPill` with a one-off tone are used for the new pills).

## Screens built without a design

No design frame exists for these; each was built from the design system and the existing components, and is named here: the type choice on the campaign form (radio group), the type pill, `CampaignWaveCard`, the three-choice campaign section of `NewWaveDialog`, the lock text on the wave page, and the wave board (existing board components with the slim card and stage names). Q10: no screenshot reached the build; no screenshot was used.

## Production posture

- Flag default OFF; with it OFF the campaign and board DOM equals the recorded baseline (above) and the waves routes answer 404.
- Both migrations are additive and were rehearsed on synthetic data (above). The production engine is **confirmed** (Q11, 2026-11-18): PostgreSQL 17.9 on aarch64 (Aurora). The fast default for `ADD COLUMN … DEFAULT <constant>` applies, so migration M1 does not rewrite `campaigns`. Source: the colleague's read-back, relayed by Pedram; not read by Cursor.
- New campaigns made for a wave are normal rows of the normal tables. After the flag goes OFF they keep working and the link stays in the data (Q7).
- Nothing was run against any deployed environment. Migrations were run on local and throwaway test databases only.

## Deviations from the plan

1. **`put` added to the shared HTTP client.** The campaign-side link route is `PUT`, and the client had no `put`. Added with the CSRF preflight (a break-revert row) rather than changing the route.
2. **Fifth walk creator.** The plan names four creators. `omar@pl12-walk.local` is a fifth, the invited creator on Bolt Evergreen, so the old board's card does not belong to a creator the walk uses.
3. **The wave-side edit "No campaign" item.** Edit now lists the wave's own campaign (the server's list excludes campaigns that already have a wave) so an unchanged link is not read as a change.
4. **Scripts slot** is drawn through a one-item list, so it exists only on a wave board card; it renders no element when empty.
5. **One source comment reworded** (`CampaignDetailPage.vue`): an existing spec scans that file for the capitalised retired word and my comment tripped it.

## Pre-existing issues noticed

- The existing `BoardCard`, `BoardColumn` and `CampaignDetailPage` templates already carry HTML comments, which appear in the dev DOM. They are the same on both sides of every comparison, so they do not matter to the flag-off proof, but they are why the pinned counts are large.
- I did not audit the 24 locale files beyond the keys this chunk touched, so no locale defect elsewhere is claimed or ruled out. The only existing strings I changed are `app.waves.errors.campaignLinkLocked` and `app.waves.dialog.campaignLocked` (the lock now reads "Selected or further").

## Not read and not run

- **Not run:** the Playwright specs other than `wave-dialog-menus.spec.ts` (they run in CI; Pedram's walk covers the same ground by hand). The other 110 targets of the agency walk. ESLint for `packages/api-client` (no ESLint binary in that package; the app lint does not include it). Two real concurrent requests against PostgreSQL (the lock tests assert the `for update` SQL; they do not race two clients).
- **Not read:** `CampaignListPage.vue` beyond the name cell and the status column; the creator-side walk files; the Pl11 walk spec details.
- **Not read by Cursor:** the Aurora engine version (Q11) is a read-back relayed by Pedram, not read by Cursor. **Not verified:** any deployed storage or environment.
