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
