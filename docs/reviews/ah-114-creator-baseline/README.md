# AH-114 creator baseline (S0.2)

The recorded state of the **creator web** (and the onboarding, auth and bare-app shells) before any agency restyle code exists. Every AH-114 layer re-runs the walk and must reproduce this file. A creator hash that moves stops the step.

## What was captured

- **Source:** the working tree at S0.1 (`b431d8d7`), whose runtime code equals `4780b8a6` (the only commits after it are docs and two spec files).
- **Tool:** `apps/main/tools/walk/` (a dev tool, not part of the shipped app, not in the build, not in any test glob). It starts its own Vite dev server on port 5199 and drives headless Chromium through Playwright.
- **Harness:** the **real** `App.vue`, **real** creator, onboarding and auth layouts, **real** route table (guards stripped, page components replaced by one deterministic probe page that renders every Vuetify primitive the agency restyle touches), **real** Vuetify plugin, **real** token CSS and Inter font. Every `/api` and `/sanctum` request answers 503, so there is **no API data**.
- **Targets (142):** every route whose layout is not `agency` (44 creator, 32 onboarding, 52 auth, 4 error, 4 app, each in dark and light at 1280 and 390 wide), plus 6 **crossover** targets (an agency page first, then a client-side push to a creator route, which is the "agency to creator leaves nothing behind" check).
- **Per target:** `domHash` and `domLen` (the `#app` outerHTML with digits normalised), `styleHash` and `elements` (17 computed properties of every element under `#app`), `themeSheetLen` and `themeSheetSha` (the Vuetify theme `<style>`), `agencyClassCount` (elements whose class contains `v-theme--agency`), and a 32 x 20 greyscale thumbnail of the screenshot.
- **Environment:** Chromium 147.0.7727.15, macOS (darwin), `reducedMotion: reduce`, locale `en-US`, timezone `Europe/Madrid`. A different browser build or OS can move computed styles and thumbnails; re-record on the new environment from an unchanged tree before trusting a comparison.

## How the comparison works

`DOM`, `style` and `theme stylesheet` hashes are **exact**. Screenshots are **not** bit-stable in Chromium (about 8 anti-aliased pixels move between runs of the same page), so they are compared as a thumbnail with a tolerance (any cell off by more than 12 levels, or a mean above 1.0, fails). The DOM and computed-style hashes are the real guarantee; the thumbnail is a second look.

## Determinism (the gate for this step)

Two full runs on the same tree: **142 / 142 targets identical** (first run: `COMPARE` of run 2 against run 1, `COMPARE OK`). Before the tolerance was added, 40 of 142 screenshot hashes differed between the same two runs while every DOM, style and theme-sheet hash matched; that is why screenshots are tolerance-compared.

**Break-revert (MEASURED):** with `--brand-font-primary` in `tokens.css` temporarily changed, a subset run (`--only creator.dashboard`) fails with `styleHash` differences on all 6 targets (four direct, two crossover); reverted, `git diff` clean. (A first attempt with `--radius-md` did **not** fail: see the finding below.)

## Run it

```
cd apps/main
node tools/walk/walk.mjs --set creator --out /tmp/run.json --compare ../../docs/reviews/ah-114-creator-baseline/baseline.json
node tools/walk/walk.mjs --set creator --only creator.dashboard --compare <baseline>   # subset
node tools/walk/walk.mjs --set shell --out /tmp/shell.json --shots /tmp/shell-shots     # agency shell screenshots
```

Playwright needs its browsers (`~/Library/Caches/ms-playwright`); inside the Cursor sandbox `PLAYWRIGHT_BROWSERS_PATH` points somewhere empty, so run it unsandboxed or `unset PLAYWRIGHT_BROWSERS_PATH`. A full creator walk takes about four minutes.

## Limits, stated once

- **No API data.** The pages are the probe page, not the real creator pages. This baseline covers the shells, the layout chrome, the route-dependent nav state and every Vuetify primitive; it does **not** cover real page content. Real-data coverage is the plan's other two legs: a local stack walk when available, and the one final check against the **production creator site** (S5.2). Neither is in this file.
- **Page errors are recorded, not hidden.** 32 targets (the onboarding layout) log an unhandled `ApiError ... (HTTP 503)` because the layout calls the API on mount; the text is deterministic and is part of the baseline's `errors` field (informational, not compared).
- **Screenshots are not committed.** Only thumbnails live in `baseline.json` (it would be about 142 PNGs). `--shots <dir>` writes the PNGs for a human to look at.

## Finding made while building it (pre-existing, not changed here)

On the web, `v-btn` computes `border-radius: 0px` (MEASURED in the harness, which uses the app's own Vuetify plugin), not `var(--radius-md)`: the global default sets `rounded: 0` (a `.rounded-0` class with `!important`) and the inline `border-radius: var(--radius-md)` loses to it (`apps/main/src/plugins/vuetify.ts:52-64`). The Figma button radius (8) therefore has to be set with `!important` inside the agency scope (S1.4); redefining `--radius-md` cannot do it. Creator web buttons are therefore square today (INFERRED from the same plugin; not looked at on the production site) and stay square (out of scope, AH-115).
