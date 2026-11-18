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
- Both migrations are additive and were rehearsed on synthetic data (above). The Aurora engine version is still **not confirmed** (Q11: must be confirmed before `pl` merges to `main`; it does not block the build).
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

- **Not run:** Playwright (E2E campaign and board specs). They need the full API and a migrated database and run in CI; Pedram's walk covers the same ground by hand. The other 110 targets of the agency walk. ESLint for `packages/api-client` (no ESLint binary in that package; the app lint does not include it). Two real concurrent requests against PostgreSQL (the lock tests assert the `for update` SQL; they do not race two clients).
- **Not read:** `CampaignListPage.vue` beyond the name cell and the status column; the creator-side walk files; the Pl11 walk spec details.
- **Not verified:** the Aurora engine version (Q11); any deployed storage or environment.
