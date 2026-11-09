# AH-115 agency baseline (L0.1)

The recorded state of the **agency shell and its 24 routes** before any AH-115 code exists. Every AH-115 layer re-runs the walk and must reproduce this file **identically**. An agency hash that moves stops the step (break-revert, no patch on top).

## What was captured

- **Source:** `origin/main` runtime code at `5677b378` (docs-only commits after `6d7a9c28`); the only change in the tree is the walk tooling itself (`--set agency`, `agency-routes.mjs`, `freeze-clock.mjs`).
- **Tool:** `apps/main/tools/walk/walk.mjs --set agency`. Own Vite dev server on port 5199, headless Chromium through Playwright.
- **Harness:** the real `App.vue`, real `AgencyLayout`, real route table (guards stripped), real Vuetify plugin, real token CSS and Inter. The 24 `meta.layout: 'agency'` routes keep their **real page component** and are fed by the `l3-fixtures.mjs` mock API (anything unfixtured answers 503). Every run has 0 page errors.
- **Targets (102):** 24 routes x dark and light x 1280 and 390 wide (96), plus 6 **crossover** targets (a creator route first, then a client-side push to `dashboard`, `campaigns`, `messages`; dark and light). The crossover is the check that a creator theme registered by a creator layout leaves nothing behind in the agency render.
- **Per target:** `domHash`, `domLen`, `styleHash`, `elements` (17 computed properties of every element under `#app`), `themeSheetLen`, `themeSheetSha` (the Vuetify theme `<style>` bytes), `agencyClassCount`, and a 32 x 20 thumbnail (tolerance compare, as in AH-114).
- **Clock frozen** to `2026-11-09T11:00:00Z` on both sides (node fixtures through `freeze-clock.mjs`, the page through `context.clock.setFixedTime`). Without it the messages thread differed between two runs of the same tree (a bubble time changes width with its digits: MEASURED, 4 of 8 messages targets); with it, identical.
- **Environment:** Chromium 147.0.7727.15, macOS (darwin) Apple silicon, `reducedMotion: reduce`, locale `en-US`, timezone `Europe/Madrid`. A different browser build or OS can move computed styles; re-record from an unchanged tree on a new environment before trusting a comparison.

## Determinism (the gate for this step)

- Two full runs on the same tree: **102 / 102 targets identical** (`COMPARE OK`).
- **Break-revert (MEASURED):** with `--brand-font-primary` in `tokens.css` temporarily changed, `--only dashboard` fails on all 4 dashboard targets (8 differences: `styleHash` plus the screenshot on each); reverted, `git diff` clean.

## Run it

```
cd apps/main
unset PLAYWRIGHT_BROWSERS_PATH   # or run unsandboxed
node tools/walk/walk.mjs --set agency --out /tmp/agency.json --compare ../../docs/reviews/ah-115-agency-baseline/baseline.json
node tools/walk/walk.mjs --set agency --only dashboard --compare <baseline>   # subset
```

A full run takes about one minute.

## Limits, stated once

- Mock-API data, not production data. It covers the real pages in the real shell with deterministic fixtures, not live content.
- Apple silicon only. CI does not run this walk; the local compare is the gate.
- Screenshots are not committed (thumbnails only).
