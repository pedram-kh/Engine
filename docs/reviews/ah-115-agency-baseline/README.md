# AH-115 agency baseline (L0.1)

The recorded state of the **agency shell and its 24 routes** before any AH-115 code exists. Every AH-115 layer re-runs the walk and must reproduce this file **identically**. An agency hash that moves stops the step (break-revert, no patch on top).

## What was captured

- **Source:** `origin/main` runtime code at `5677b378` (docs-only commits after `6d7a9c28`); the only change in the tree is the walk tooling itself (`--set agency`, `agency-routes.mjs`, `freeze-clock.mjs`).
- **Tool:** `apps/main/tools/walk/walk.mjs --set agency`. Own Vite dev server on port 5199, headless Chromium through Playwright.
- **Harness:** the real `App.vue`, real `AgencyLayout`, real route table (guards stripped), real Vuetify plugin, real token CSS and Inter. The 24 `meta.layout: 'agency'` routes keep their **real page component** and are fed by the `l3-fixtures.mjs` mock API (anything unfixtured answers 503). Every run has 0 page errors.
- **Targets (126):** 24 routes x dark and light x 1280 and 390 wide (96), plus 6 **crossover** targets (a creator route first, then a client-side push to `dashboard`, `campaigns`, `messages`; dark and light). The crossover is the check that a creator theme registered by a creator layout leaves nothing behind in the agency render. The last 24 targets are the **auth screens the restyle must not change** (rulings Q3 and 1): `/agency/sign-in` and the four screens an agency user can reach (2FA verify and enable, forgot and reset password, accept invitation), each in dark and light at both widths, on the real `AuthLayout` with a stub page. They were added after L0.5 from the same unchanged runtime (the original 102 reproduced identically on that run: 0 of 102 differing) and before any L1 code.
- **Per target:** `domHash`, `domLen`, `styleHash`, `elements` (17 computed properties of every element under `#app`), `themeSheetLen`, `themeSheetSha` (the Vuetify theme `<style>` bytes), `agencyClassCount`, and a 32 x 20 thumbnail (tolerance compare, as in AH-114).
- **Clock frozen** to `2026-11-09T11:00:00Z` on both sides (node fixtures through `freeze-clock.mjs`, the page through `context.clock.setFixedTime`). Without it the messages thread differed between two runs of the same tree (a bubble time changes width with its digits: MEASURED, 4 of 8 messages targets); with it, identical.
- **Environment:** Chromium 147.0.7727.15, macOS (darwin) Apple silicon, `reducedMotion: reduce`, locale `en-US`, timezone `Europe/Madrid`. A different browser build or OS can move computed styles; re-record from an unchanged tree on a new environment before trusting a comparison.

## Known non-deterministic source: indeterminate progress bars (found at the end of L1)

At the end of L1 the 12 auth targets at 1280 (6 screens, dark and light) stopped matching the recorded baseline, with the **same difference on the tree that recorded it** (`adb31fd8`, no creator code), so the runtime was not the cause. The per-element computed-style dumps (before and after neutralising animations) show the only elements that move are the `.v-progress-linear__indeterminate` bars: Vuetify animates their own `width` with a keyframe, so the value is a snapshot of whatever frame the capture lands on (MEASURED: 14 of 341 elements, one property, on both 1280 and 390). Nothing else differed.

- **Mask:** `walk.mjs` replaces that one property on those elements with `~` in the style hash. Every other property and every other element still counts.
- **Re-record:** the baseline was re-recorded with the mask on the **`adb31fd8` tree** (unchanged runtime), twice (second run `COMPARE OK`, 126 / 126), then compared at the L1 tip: 126 / 126 identical. 104 of the 126 `styleHash` values moved from the first recording because the bars exist on most pages; the DOM hashes, theme sheet hashes and every other recorded field are unchanged.
- **Effect on the AH-114 creator baseline:** its `styleHash` values will differ wherever a creator page has such a bar; that baseline is re-recorded deliberately in L5.

## Determinism (the gate for this step)

- Two full runs on the same tree: **126 / 126 targets identical** (`COMPARE OK`).
- **Break-revert (MEASURED):** with `--brand-font-primary` in `tokens.css` temporarily changed, `--only dashboard` fails on all 4 dashboard targets (8 differences: `styleHash` plus the screenshot on each); reverted, `git diff` clean.

## Re-recorded on 2026-11-09 (L5): the second non-deterministic source was a font race

At L5 the L0 recording stopped matching on **12 of the 126 targets** (the six auth screens at 1280, dark and light, `styleHash` only), with the same failure on `75766ded` (the pushed L3 tip, 126 / 126 when it was pushed) and on the tip, so it was not this chunk. The per-element dump of two runs of one unchanged tree found the difference: the locale select's `v-input__control`, `v-field` and `v-field__overlay` measure **67.34 px in one run and 67.67 px in another** (the same elements, 44 of 341 changed, only the targets that show the select). The select is auto-width and its options are written in every script, so its width depends on which **Inter subset files (Latin-extended, Cyrillic, Greek) have arrived** when the capture lands. `document.fonts.ready` can resolve before those faces are even requested. The creator walk showed the same bimodal state on the onboarding targets at 390 (which also show the select).

- **Fix (walk tool only):** `open()` now requests every weight (400 to 700) with a sample string covering those scripts, then awaits `fonts.ready`, before the capture. The runtime is untouched.
- **Re-record:** the L0 file recorded the incomplete-font state, so it cannot match a deterministic harness. `baseline.json` is now recorded with the fixed harness from the **pre-chunk runtime** (`src` and `packages` at `9a0be3f3`, before any AH-115 code): 126 targets, 0 page errors. The L0 recording stays as `baseline-recorded-l0.json` for reference only.
- **Proof, MEASURED:** the tip compares **126 / 126 identical** against that pre-chunk recording, twice in a row (`COMPARE OK`). Before the harness fix, the tip also compared 126 / 126 against a pre-chunk recording made in one sitting (same font state on both sides), and against the L0 file with the font state of the day it was recorded.
- The creator baseline had the same race and was recorded with the fixed harness (its README).

## Re-recorded on 2026-11-10: text-metric sizes masked (the font race is no longer fought)

After the L5 harness fix the race came back (the agency auth screens at 1280 flipped to the other font state against the committed file, 12 targets, `styleHash` only), so preloading fonts cannot be the gate. **`walk.mjs` now masks `width` and `height` (the measured box) of the locale switcher (`[data-test$="locale-switcher"]`) and of every element inside it** in the style hash, the same way the progress-bar `width` is masked. Colours, fonts, paddings, display and every other property of those elements, and every other element, still count. The preload stays (it narrows the race for the thumbnails). If a diff ever shows another element sized by text metrics, add it to the mask with its measured values here; none showed one.

- **Re-record:** from the pre-chunk runtime (`src` and `packages` at `9a0be3f3`) with the masked harness: 126 targets, 0 page errors.
- **Proof, MEASURED:** the tip compares 126 / 126 identical to the new `baseline.json` three times in a row, and the recording itself compared 126 / 126 three times in a row; also 126 / 126 with the font preload switched off (no weights requested).

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
