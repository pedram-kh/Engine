# PL-2.5 "Connecting it up" — review

Branch `pl`. Stage 1 (API) was reviewed and accepted. This file records what was accepted, then Stage 2 (web).
**Nothing of Stage 2 is pushed.** Pedram walks first.

## 1. Accepted differences from the plan (Stage 1, recorded as Pedram ruled)

1. `StrategyTrackerSync::transaction` is the one choke point for every write to the four strategy rows.
2. Tracker rows carry the `strategy` block when strategy is ON (and only then).
3. `completions_removed` and `notes_removed` are always present in `wave.updated`.
4. The catch-up on open runs only when strategy is ON.
5. The mark endpoint answers with `meta.warnings`.
6. Removing a market that has notes answers 422 `wave.market_has_notes`; the save goes again with `confirm_notes_deleted`.
7. B19 is not a gate for the wave lock (the row lock also holds); B13 is the gate. See `pl-2-5-break-reverts.md`.
8. Housekeeping H1, H2 and H4 were confirmed by the reviewer; H3 is tech-debt row 52.

## 2. Loose ends

| #   | Item                                               | Result                                                                                                                                                                                                                                                                                                                                                           |
| --- | -------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| L1  | B14 query counts measured, maxima set above them   | Measured on the Pl25 walk data (wave 1, SQLite): strategy summary **34**, tracker **23**, wave list **11**. `Pl25GatesTest` now allows **40** and **28** (were guessed 60 and 45). The wave-list rule (one grouped `wave_steps` query, no strategy table) is unchanged.                                                                                          |
| L2  | The two new tables in every earlier cleanup seeder | Done in the seven earlier `Pl*WalkCleanupSeeder` files; a test (`Pl25WalkSeedersTest`) checks that each deletes notes and completions before `wave_markets`.                                                                                                                                                                                                     |
| L3  | Wave and strategy Playwright specs run locally     | Run serial, `--project=chromium --workers=1`: `strategy-completion` (4, new), `strategy-matrix-manual` (2), `strategy-matrix` (3), `strategy-workspace` (7), `pl23-concepts` (6), `wave-dialog-menus` (2), `wave-tracker` (2): **all green**. Longest single test 15.3 s (`strategy-workspace` at 390, PL-2.1, unchanged); the four new tests take 2.9 to 5.8 s. |

## 3. What Stage 2 built

1. **Stepper with real states**: green circle with a 14 px check (done), amber with the number (in progress), empty circle (not started). Status lines "Complete" and "n of m markets", coloured as the frame (green `#34c77b`, amber `#f5a524`) at 11.5 px. `data-state` on each step.
2. **Mark as complete / Reopen** on USPs, Personas (per market) and the Messaging matrix (per market), in a bar above the step. Warnings (fewer than 4 USPs, fewer than 2 personas, cells not set) are listed in a confirm and never block; an empty step opens a refusal with no confirm button; Concepts shows its automatic state and no button.
3. **Tracker on the wave page**: a hand-set beyond the real step opens a confirm; the row shows "Set by hand · the step says In progress (1 of 2 markets)"; a hand-set Done row stays in the open list so the marker stays in sight; a row the system took back loses the marker; strategy OFF is exactly PL-1.
4. **Brand page bar and wave page progress** read the same rows (checked on the walk: the Brand page and the wave page agree for all four waves).
5. **"Strategy & Scripts"** goes to `strategy.index`, which asks the server for the landing (first step not done, first market not done, Concepts when all done). A direct address still opens that step. With a flag off nothing under `/strategy` is requested.
6. **Notes**: amber 300 px panel on USPs, Personas and Concepts; on the matrix a "Notes (n)" button opens a 360 px side sheet. Plain text only, per market, 2000 characters, edit and delete by the server's `can_edit` and `can_delete`; a former member shows as "Former member".
7. **Market removal** shows an amber alert naming the markets and the notes to be deleted; the button becomes "Delete notes and save".
8. **Strings** in all 24 locales (49 keys; `pl25Keys.spec`), and the `Pl25WalkSeeder` / `Pl25WalkCleanupSeeder` pair.

## 4. Found and fixed during Stage 2

- **Audience-title answer had no `steps` block (Stage 1 gap).** `PATCH .../audience-title` answered with the old summary shape; the page replaced its summary with it and crashed on `steps.usps`. Found by the existing `strategy-workspace` Playwright spec. The controller now returns the same block as the summary (Pest B21), and `StrategyPage.onSummary` keeps the blocks if an answer ever lacks them.
- **The matrix side sheet did not close on Escape** (a temporary drawer closes on its scrim only). Found by the new Playwright test's quick reopen. Now closes on Escape unless a dialog is open above it (`NotesSheet.spec`, and a Playwright break-revert).
- **Hard-coded colours** in the notes panel and sheet (`rgba(...)`) turned the architecture gate red; they are now `color-mix` of theme tokens (same rendered colours).
- **Status colours of the stepper** were grey and 12 px; now the frame's green and amber at 11.5 px.
- **A hand-set Done row vanished into the collapsed "completed" line**, hiding its marker; `groupSteps` now keeps it open.
- **Existing Playwright specs adjusted** (not skipped): the drawer helper selects `[data-test="agency-sidebar"]` because the matrix page now has a second navigation drawer (the sheet); `pl23-concepts` expects "Complete" for a market with every concept approved (the status line is now the real state).
- **Plural-safe progress**: "1 of 1 markets" became "1 of 1 market" in the tracker confirm and marker, in all 24 locales.

## 5. Fidelity (ruling 10; measured in a real browser at 1600, 1440 and 390 on `pl25_walk`)

The table is filled in `pl-2-5-plan.md` section 4.5. Summary:

- **Closed, with measured values**: USP cards **290** (was 302), persona cards **448** (was 408), concept cards **444** (was 620), at 1600 and at 1440. The notes column is **300** wide; the card's fill, border, radius 10, padding 18, gap 10, title 15 / 600, Internal chip and note text 13 / 19 all match the frame.
- **VISUAL differences fixed**: stepper status size and colour (above); the notes colours (tokens, same values).
- Main column is 932 (1600) and 772 (1440) against the frame's 912: the app shell's content area is 20 px wider than the frame's. Not this stage; the cards are capped, so they match.
- Frames read with `get_design_context`: 13:428, 13:828, 13:1375, 13:4412 (saved frame files).
- Dialogs, menus, the notes panel and the side sheet were opened, closed and quickly reopened at three speeds (0, 60, 150 ms) in `strategy-completion.spec.ts`: the warning confirm, the refusal, the note delete confirm, the tracker confirm, and the matrix sheet. The completion bar, the panel and the sheet were also checked at 390 (no sideways scroll with the sidebar closed).

## 6. FUNCTIONAL differences for Pedram (not built; ruled or out of this stage)

1. **Comments**: the frame's Comments card, "Comments (n)" and the approval title-row actions, "Client review" statuses, "Preview as client", "Share with client" (PL-5).
2. **Steps 5 and 6** stay unlocked, plain elements (no lock, "Not available yet"); ruling C6.
3. **Matrix notes** open in a side sheet because the frame (13:1375) has no notes column; the sheet itself is an assumption (Q3).
4. **Not-started stepper step** has no frame; an empty outlined circle is assumed.
5. **Stepper status line**: while a per-market step is in progress, the line reads "n of m markets" instead of the earlier counts ("3 personas · 1 of 2 markets covered", Concepts "n of m approved"). The counts line still shows for a step that is not started. Say if you want both.
6. **Agency shell at 390**: the permanent 280 px sidebar is open on load and leaves the content 62 px wide until the menu button is pressed. This is the existing shell (every agency page), not this stage.

## 7. Flag-off check

- Walk compare, with the comment-node check: agency **126 targets identical**, 0 page errors; creator **146 targets identical** (the 32 onboarding `HTTP 503` rejects are in the baseline too).
- Waves ON, strategy OFF: the wave page and the tracker are exactly PL-1 (payload without the `strategy` key, no chip, no marker, no entry button, no hand-set confirm); `strategyEntry.spec` (the actions DOM and the comment nodes), the PL-1 tracker and steps-panel specs, and the `wave-tracker` Playwright spec pass.

## 8. Gates

| Gate                                                                          | Result                                                                                                                                                                   |
| ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Vitest, whole `apps/main`                                                     | 5489 passed, 2 failed: only the **two appIconAssets failures (tech-debt row 43)**. (A hard-coded-colour gate failure of mine was found by the first full run and fixed.) |
| `packages/api-client` Vitest                                                  | 236 passed.                                                                                                                                                              |
| Pest, whole suite, **SQLite** (`:memory:`)                                    | 5302 passed, 58 skipped.                                                                                                                                                 |
| Pest, whole suite, **PostgreSQL** (`catalyst_test`, port 5435, printed first) | 5360 passed, 0 failed.                                                                                                                                                   |
| Playwright wave and strategy specs                                            | green, see L3.                                                                                                                                                           |
| `vue-tsc`, ESLint (0 errors; 14 warnings, none in this stage), Pint, Larastan | clean.                                                                                                                                                                   |
| Break-reverts (web)                                                           | 16 of 16 red then green; one weak gate strengthened (L3). `pl-2-5-break-reverts.md`.                                                                                     |

## 9. The walk

Stack on the throwaway database `pl25_walk` (both flags ON there; no flag written anywhere else):

- API `http://127.0.0.1:8005`, main app `http://127.0.0.1:5195`, admin app `http://127.0.0.1:5196` (its own port, against the same API).
- Password for every login: `password-12chars`.
- Agency A admin `admin@pl25-walk.local`, manager `manager@pl25-walk.local`, staff `staff@pl25-walk.local`; Agency B admin `admin@pl25-walk-b.local`; platform admin (admin app) `platform-admin@pl25-walk.local` (the first sign-in forces TOTP enrolment).
- By clicking: Brands → **Bolt Food** → a wave → **Strategy & Scripts**.
  - **Connect Wave 1**: USPs done, Personas done for Slovakia only (lands on Personas, Nordics), matrix and Concepts in progress, notes on USPs and the matrix.
  - **Connect Wave 2**: all four steps done (lands on Concepts).
  - **Connect Wave 3**: nothing started (lands on USPs).
  - **Connect Wave 4**: a Personas row set by hand beyond the real step, so the marker and the confirm show on the wave page.
  - Agency B (**Glovo**, Glovo Wave 1) holds a note Agency A must not see.
