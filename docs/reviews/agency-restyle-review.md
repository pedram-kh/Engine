# AH-114: agency app restyle from Figma (web only) — REVIEW

**Status: CLOSED 2026-11-02 (L0 to L5 built, cleared layer by layer by Pedram, pushed; NOT deployed).** Inventory: [`agency-restyle-inventory.md`](agency-restyle-inventory.md). Plan, with the step-by-step build notes (§3.1) and every ruling: [`agency-restyle-plan.md`](agency-restyle-plan.md). Creator baseline: [`ah-114-creator-baseline/`](ah-114-creator-baseline/README.md). Ad-hoc entry: AH-114 in [`adhoc-changes-log.md`](adhoc-changes-log.md). Deploy entry (PENDING): [`deploy-log.md`](../runbooks/deploy-log.md).

**Evidence labels.** MEASURED = read in a real browser or by a spec in this chunk. INFERRED = reasoned from code or from measurements, not read directly. REPORTED = told to me, not read by me. "Not read" is written as "not read".

---

## 1. What shipped

The agency web app (every route with `meta.layout: 'agency'`, 24 of them) now follows the Figma file `pakeokB5NLH7hN1zCRLpfs`: two runtime-registered Vuetify themes (`agency-dark`, `agency-light`), one scoped stylesheet, a re-mapped type scale, restyled primitives, five agency-only forks of shared components (status pill, inbox, thread view, KPI card, bell), the five designed routes built to their frames, and the other 19 routes brought onto the same rules by inference.

**The creator web, the native app, the onboarding and auth screens and the admin app render byte for byte what they rendered before** (§4). Nothing in the shared surface was edited: the 21 creator-shared components plus `CKpiCard`, `packages/design-tokens`, `packages/ui`, `plugins/vuetify.ts`, `App.vue`, `useTheme.ts`, `useThemePreference.ts` and `apps/admin` are untouched, and specs 3 and 5 hash them.

| Layer | What it did                                                                                          | Commits                                                                                                                     |
| ----- | ---------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| L0    | Guards, creator baseline, agency themes, scoping spec, isolation spec (nothing renders differently)  | `b63b592b` S0.1, `62df615b` S0.2, `0616d8b1` S0.3, `75475aaf` S0.4, `d970fdcd` S0.5                                         |
| L1    | The agency app takes the new colour, type, shape: layout wiring, shell, type map, primitives A and B | `30ee4793` S1.1, `7a3cd6c0` S1.2, `203964aa` S1.3, `a17fa78f` S1.4, `4d31d171` S1.5                                         |
| L2    | Status pill and the four forks                                                                       | `3c57babd` S2.0, `159808a7` S2.1, `b989a35d` S2.2, `a61da285` S2.3, `b89bc9df` S2.4, `c3cbc136` S2.5, `2ab59911` S2.6       |
| L3    | The four designed pages (five routes)                                                                | `f4f3fc03` S3.1, `bf2752b3` S3.2, `829f4814` S3.3, `651aa788` S3.4                                                          |
| L4    | Inference pass over the other 19 routes                                                              | `3d9c20d5` S4.0, `220fe5cc` S4.1, `e9b512f0` S4.2, `6e95cd01` S4.3, `d8dea17e` S4.4, `97124f93` S4.5, `b89eb45e` layer walk |
| L5    | Housekeeping, this review, the logs, the final proof                                                 | the L5 commits (see `git log`)                                                                                              |

---

## 2. Evidence per layer

Pins are `apps/main/tools/walk/pins.mjs --step <S>`: computed-style reads in real Chromium 147, both themes, one pin per measurable claim. "Break-revert" = the guarded thing was broken on purpose, the named check failed for the stated reason, the break was reverted and `git diff` was clean (`WORKING-PROCESS.md` §4). Counts are from the commit messages and the plan's build notes (§3.1).

| Step | Specs and pins (MEASURED)                                                                                                         | Break-reverts caught                                                                                                                                                                                          |
| ---- | --------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| S0.1 | Spec 3 `shared-tokens-frozen`, spec 5 `shared-components-frozen`                                                                  | 3: one character in `tokens.css` (names the file); one comment in `RelationshipInbox.vue` (names the file); `surface-bright` renamed in `vuetify.ts` (four literal themes and the file hash fail)             |
| S0.2 | Baseline: 142 targets; two runs on one tree 142 / 142 identical                                                                   | 1: `--brand-font-primary` changed fails all 6 `creator.dashboard` targets on `styleHash`. A first attempt with `--radius-md` did not fail and exposed that `v-btn` is `rounded-0` on the web (README finding) |
| S0.3 | Spec 4 `agency-color-system-parity`, `agencyThemesShape` (158 tests)                                                              | 3: drop `variables` (4 shape specs, the spike's `TypeError`); revert the info lift (4 parity specs, 3.96 and 4.37); a different light success fill (fill-equality)                                            |
| S0.4 | Spec 1 `agency-theme-scoping`                                                                                                     | 8 / 8: a bare `html` rule; an unprefixed `.text-h6`; a `, body` in a list; an `@media` block; canvas `#0b0b0c` changed to `#0b0b0d`                                                                           |
| S0.5 | Spec 2 `agency-theme-isolation`, spec 7 `creator-no-agency-theme`                                                                 | 6: a type-only import, a real import, an `agency.css` side-effect import (each names the leaking file); `theme="agency-*"` on the creator, onboarding and auth `v-app` (each fails its two cases)             |
| S1.1 | `measure.mjs` A to F on the dev server and a production build; `AgencyLayout.theme` spec; spec 7 extended; creator walk 142 / 142 | 4: remove `useThemePreference()`, remove the `:theme` binding, a class on `<html>`, drop the unmount hook                                                                                                     |
| S1.2 | `measure.mjs` G, shell DOM-pin spec                                                                                               | 9 (shell pins)                                                                                                                                                                                                |
| S1.3 | 28 pins, 180 checks                                                                                                               | 4                                                                                                                                                                                                             |
| S1.4 | 41 pins, 222 checks                                                                                                               | 22                                                                                                                                                                                                            |
| S1.5 | 31 pins; all pins 92, 538 checks                                                                                                  | 22                                                                                                                                                                                                            |
| S2.0 | 4 pins; all pins 96, 554 checks                                                                                                   | 3                                                                                                                                                                                                             |
| S2.1 | 18 pins, 216 checks; spec 6 `agency-status-tone` (32 tests: ten unions, tones, 24-locale labels)                                  | 5 (12 / 4 / 2 / 24 / 12 checks)                                                                                                                                                                               |
| S2.2 | 24 pins, 200 checks; 18 specs                                                                                                     | 5                                                                                                                                                                                                             |
| S2.3 | 20 pins, 184 checks; 22 specs                                                                                                     | 5                                                                                                                                                                                                             |
| S2.4 | 15 pins, 78 checks; 9 specs                                                                                                       | 4                                                                                                                                                                                                             |
| S2.5 | 12 pins, 60 checks; 11 specs; full pin set 185 pins, 1,292 checks                                                                 | 4                                                                                                                                                                                                             |
| S2.6 | Spec 4 gains the `error-strong` contrast test; 186 pins                                                                           | 2: `error-strong` set back to `#f0506e` fails both themes; inbox stamp back to day-and-month fails both themes                                                                                                |
| S3.1 | 37 pins, 224 checks, 1600 and 1280; Campaign list spec +11                                                                        | 4: row height, page gap, status chip regression, Sm label size                                                                                                                                                |
| S3.2 | 43 pins, 248 checks; Campaign detail spec +24, header spec +2                                                                     | 5: tab gap, card radius, back-button radius, selected-tab colour, settings card                                                                                                                               |
| S3.3 | 40 pins, 226 checks; Discover spec +13, `AgencyFilterPill` spec 7, `AgencyCreatorCard` spec 14                                    | 5: column count, card radius, pill label size, applied-pill border, cover initial opacity                                                                                                                     |
| S3.4 | 22 pins, 106 checks; two page specs                                                                                               | 5: full-bleed hook, list-pane width, sidebar fill, inbox `labels`, pane height                                                                                                                                |
| S4.0 | 3 pins                                                                                                                            | not recorded                                                                                                                                                                                                  |
| S4.1 | 47 pins, 208 checks                                                                                                               | 4: `active` on the roster inputs, `agency-table` on pools, `agency-seg` on brands, the membership pill on team                                                                                                |
| S4.2 | 33 pins, 186 checks                                                                                                               | 4: brand pill, agency card on the roster detail, pool member inset, Discover frame class                                                                                                                      |
| S4.3 | 28 pins, 190 checks                                                                                                               | 4: frame class, back button, form card width, Settings header                                                                                                                                                 |
| S4.4 | 10 pins, 42 checks                                                                                                                | 4: roster link, strip gap, page frame, activity card                                                                                                                                                          |
| S4.5 | 4 pins, 26 checks; `agencyNoStatusChips.spec`, `draftRoundTone.spec`                                                              | 2: the agency `.v-card` border rule; a chip put back in `PoolListPage` (the guard spec names it)                                                                                                              |
| S4.L | `walk-l4.mjs`: 24 routes x 2 themes x 2 widths = 96 stops, 0 problems                                                             | not applicable (a walk, not a pin)                                                                                                                                                                            |
| L5   | `app.brands.fields.createdAt` x 24, `verify-locale` 24 / 24                                                                       | 1: a misspelt key fails `i18n-used-keys-resolve`, naming the file; restored                                                                                                                                   |

**The one plan error the specs caught.** Spec 4 found at S0.3 that three light status texts were 4.42 to 4.49 on the canvas tint (the inventory had measured on white only); each was darkened one step (plan §2.1b). The first S4.5 gate caught a second one: I had put `roundStateTone` in `campaigns/draftRounds.ts`, which the creator tree imports, and spec 2 failed naming the file; the function now lives in `modules/agency/status/`.

---

## 3. Figma deviations (every place the app is not the frame, and why)

### 3.1 Colours

| What                                | Figma                    | Shipped                                 | Why (MEASURED, plan §2.1 and §2.1b, S2.6)                                                                  |
| ----------------------------------- | ------------------------ | --------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| Dark tertiary text                  | `#6e6e76`                | `#8a8a92`                               | 3.89 / 3.40 / 3.10 on canvas / surface / raised, to 5.74 / 5.02 / 4.58 (ruling 11, AA-normal)              |
| Dark info pill text                 | `#4c8dff`                | `#70a4ff`                               | 5.21 / 4.37 / 3.96, to 6.70 / 5.63 / 5.09                                                                  |
| Dark danger pill text               | `#f0506e`                | `#f3738b`                               | 4.92 / 4.17 / 3.78, to 6.18 / 5.24 / 4.75                                                                  |
| Dark AI pill text                   | `#8b6cff`                | `#a289ff`                               | 4.57 / 3.87 / 3.50, to 6.07 / 5.15 / 4.66                                                                  |
| Light success, warning, danger text | (light is ours, see 3.2) | `#157648`, `#975900`, `#ba2946`         | 4.43, 4.49, 4.42 on the canvas tint, to 4.61, 4.64, 4.60 (found by spec 4)                                 |
| Notification count pill fill        | `#f0506e` (node 36:182)  | `#d6304f` (`error-strong`, both themes) | White on it is 4.78:1 (Figma fill: 3.46:1). Only this pill; the `error` slot and danger text are unchanged |
| `text-inverse`                      | not in Foundations       | `#ffffff` in both themes                | Native has it; first consumer is the switch thumb. Designer note below                                     |

All status **fills** stay at the Figma values, one value in both themes (spec 4 invariant); only text colours move. The four lifted dark rows are the three failing pill texts plus tertiary text. Success, warning and neutral pill text already pass and are unchanged.

### 3.2 The light theme is ours

Foundations is dark only; ruling 3 asked for both. Light is derived from the same tokens (inventory §2.2): canvas `#f4f4f5` (also `surface-bright`), surface `#ffffff`, sidebar `#fafafa`, top bar `#ffffff`, tertiary text `#6b6b74`, raised equals canvas. The accent slot takes the dark value so every fill is one value. Every pair is asserted in spec 4. The light sidebar shows the off-white wordmark through `filter: invert(1) hue-rotate(180deg)` (S1.2), a stopgap until a light asset exists.

### 3.3 Frames over Patterns

The Patterns page says sidebar **300**, top bar **68**, right panel **300**. All the frames measure **280 / 65 / 360** (inventory §5.1 R1, MEASURED on the frames). Ruling 4: the frames win. Shipped: drawer 280 (S1.2), top bar 65 with an inside 1px separator (S1.2), campaign-detail right panel 360 (S3.2), roster-detail rail 360 (S4.2). Content padding is `space/8` = 32.

### 3.4 Shape and type

- **`radius/xl` is 10 in Figma and 12 in the web repo.** `--radius-xl` is redefined to 10 and `--radius-2xl` to 12 inside the agency theme-class scope only (S0.4). The `.agency-card` class is 12 by the card frames; Vuetify `v-card` is 10.
- **Roboto fallback.** Roboto is not installed anywhere, so the `.text-*` utilities and every overlay fell back to the system sans on the agency side (the spike's measurement). Fixed inside the agency scope (S0.4, S1.3). The creator side keeps its fallback, as ruled; creator-side font work is AH-115.
- **Buttons.** `v-btn` computes `border-radius: 0` on the web (`plugins/vuetify.ts` sets `rounded: 0`), so the Figma radius 8 is set with `!important` inside the agency scope (S1.4). Creator buttons stay square.
- **Labels.** Small non-icon buttons use the Sm label size 13 (Figma), not 12 (S3.1 correction).

### 3.5 Behaviour and content

- The top-bar search is omitted (nothing behind it, ruling 15).
- Status is a pill (dot and word on a 15 % tint) everywhere it was a chip; 17 assignment tones were accepted at L2. One posted-content status colour moves from info blue to the table's warning amber in the view-post drawer (accepted at L4).
- "Wave" is "Campaign". No Figma sample copy was used. A vocabulary grep (`Wave`, `USP`, `persona`, `script`, `client share link`) ran in every step and on every string this chunk added.
- The dashboard KPI strip uses the four Figma icon-tile tones (`ai`, `success`, `info`, `warning`).
- The board palette (`modules/boards`) is not mapped onto the tones (ruling 7).

### 3.6 Omissions: shown by Figma, not built, because nothing backs them (ruling 15, no placeholders)

| Page              | Not built                                                                                                                                                                                                         |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Inbox and thread  | The Online pill, the campaign tag and "View campaign" (a relationship thread has no campaign), the more menu, the report link and fallback, the native branches; the per-row brand tag unless a label is supplied |
| Campaigns list    | Per-status counts in the segments, the row dots menu, the rows-per-page selector                                                                                                                                  |
| Campaign detail   | "Share brief", "Created by", Market, the Edit button, "Creators confirmed", "Budget used", "Next steps", the Campaign name row; the right panel draws only with at least two real rows                            |
| Discover          | Per-card "N videos" and the heart, the verified badge, the Gender, Age and "Has portfolio" filters, "across N markets", the grid and list toggle, "Saved searches", "Add to talent pool"                          |
| Messages header   | The brand filter and the "wave" filter                                                                                                                                                                            |
| Lists and details | Page subtitles on the lists (no i18n key), a status on pool rows (the API has none)                                                                                                                               |
| Whole app         | Reference link, Agent bar, Market tab, Stepper step, Priority tag, USP chip, Comment (they belong to another product)                                                                                             |

### 3.7 Left as they were, on purpose

The 31 em-dash lines in `en/app.json` and the Title Case strings (ruling 6: new strings only follow the writing rules); the literal font sizes inside the Figma-built L2 and L3 components (they are the Figma values); the notification pages and the five notification dialogs (shared, restyled through the scope only); `CategoryChips` and `BlacklistBadge` (shared, tags not statuses).

---

## 4. Final proof run (S5.2)

All MEASURED on 2026-11-02 on the L5 tip, Chromium 147, macOS.

1. **Full board.** `apps/main` typecheck, lint, vitest; `apps/admin` vitest; `packages/*` vitest; prettier on every touched file; `verify-locale` 24 / 24. Final tip (`30d8df28` plus this note): `apps/main` 300 test files, 3,966 tests, 0 failures; typecheck 0, lint 0; `apps/admin` 64 files, 602 tests; `packages/*` design-tokens 42 (+1 todo), api-client 228, ui 47; prettier clean; `verify-locale` 24 / 24 PASS. Two gate runs during L5 timed out one or two 5 s specs under parallel load (`CreatorProfilePage.spec.ts`, `DiscoverPage.spec.ts`); both pass alone (36 / 36 together), and a full `apps/main` run with the fork pool capped at 4 was 300 / 300 files, 3,966 / 3,966 tests. It is the same family as the known `Step2ProfileBasicsPage.spec.ts` flake (tech-debt row).
2. **Creator walk against the S0.2 baseline, every page.** `COMPARE OK: 142 targets identical to baseline.json` (DOM hash, computed-style hash, theme stylesheet hash, thumbnails; 44 creator, 32 onboarding, 52 auth, 4 error, 4 app, 6 crossover targets). The `ApiError ... (HTTP 503)` lines printed by the onboarding targets are in the baseline and are expected.
3. **Production build, the spike's five items** (`measure.mjs --build`, a `vite build` of the harness served by `vite preview`):
   - **A, creator page:** theme stylesheet 18,863 characters, hash `15e8eb12f4410a49`, no agency rules, 0 agency classes: the baseline's numbers.
   - **B, agency first paint:** every frame from navigation carries `v-theme--agency-dark` and already has the agency rules; no frame on the global theme class.
   - **C, stored light:** `v-theme--agency-light` at load, `html` and root `rgb(244, 244, 245)`.
   - **D, teleported menu:** `v-theme--agency-dark`, Inter, overlay shadow variable present.
   - **E, dark:** `html` `rgb(11, 11, 12)`; `text-h6` and `text-body-2` compute Inter.
   - **F, agency to creator in one session:** 0 agency classes, theme stylesheet 18,863 characters and equal to a direct creator load, `html` background transparent again.
4. **Shared paths, `git diff --stat 4780b8a6..HEAD`.** Empty for the 22 spec-5 files, `packages/design-tokens`, `packages/ui`, `packages/api-client`, `apps/admin`, `plugins/vuetify.ts`, `App.vue`, `useTheme.ts` and `useThemePreference.ts` (both apps). Three existing specs were edited by name and only by addition (`CampaignListPage.spec.ts` 154 added, 1 changed line; `CampaignDetailPage.spec.ts` 221 added, 0 changed; `DiscoverPage.spec.ts` 197 added, 1 changed line; the changed line in the two is the `mountList` / `mountDiscover` helper taking a `total`).
5. **Creator stylesheet delta, stated as a number.** Production builds of `apps/main` at `4780b8a6` (before AH-114) and at the L5 tip: the shared `index-*.css` goes from **773,680 to 802,340 bytes (+28,660; gzip 106,613 to 111,090, +4,477)**. The whole CSS set goes 908,355 to 947,769 bytes raw (60 to 64 files). The additions are inert on creator pages (spec 1: every `agency.css` selector starts with the agency theme classes), and the creator's own Vuetify theme sheet is unchanged (item 3, A). Lazy-loading `agency.css` would take it out of the creator bundle: tech-debt row, with AH-115.
6. **L4 layer walk.** `walk-l4.mjs`: 24 routes, dark and light, 1600 and 1280, 96 stops, 0 problems (no page overflow, no console error, no error alert, no leftover status chip, always themed).

### 4.1 The one real-data check: the live production site

`tools/walk/prod-compare.mjs` reads the live production site and a production build of this tree the same way. Per public route x theme x viewport it loads the page on `https://platform.catalyst-growth.com` and on `vite preview` of the build, answers every API request in the browser with one fixed 503 on both sides (so no API call leaves the machine and both pages see the same data), and compares the DOM hash, the computed-style hash and the theme stylesheet. Read-only: GETs of public pages and assets, nothing submitted, no sign-in.

- **Routes:** `/sign-in`, `/sign-up`, `/forgot-password`, `/delete-account` and an unknown path (the 404), dark and light, 1280 and 390: **20 stops**.
- **Production against the L5 tip: 16 of 20 stops identical** (DOM, computed style, theme stylesheet, no agency class, `html` and `body` classes). **The 4 that differ are the sign-in page** (all four theme and width combinations). The computed-style hash and the theme stylesheet are equal on them; the only difference in the DOM is one Vue scoped-style id: `CreatorGuideCta.vue` renders `data-v-b5682d48` on production and `data-v-f90c534f` in the build. Its scoped CSS rules are the same 17 rules on both sides, and nothing in the file changed since 2026-07-31.
- **The same difference is there without AH-114.** A production build of `4780b8a6` (the tree before any AH-114 commit) shows the identical id `f90c534f`, and the same 4 stops differ against production. **The L5 tip against the pre-AH-114 build: 0 of 20 stops differ** on any hash. So AH-114 changes nothing on these pages; the id mismatch is between how production was built and how I built it. Cause not read (INFERRED: a build-environment difference, since the scoped id hashes the file path and source in a production build; I did not inspect the Netlify build).
- **Limit, stated once: not checked.** This is the public part of the creator site only. Pages behind a sign-in (dashboard, campaigns, messages, profile, the walkable creator pages) were **not** compared against production: no credentials were used. Their guarantee is the stub-harness walk (item 2), spec 2 (no creator file reaches agency code) and spec 5 (the shared components are hash-pinned). A signed-in comparison with a test creator account is the one remaining real-data check; it needs a session from Pedram.

---

## 5. Designer note

For whoever owns the Figma file:

1. **`text/inverse` is not in Foundations.** Native has it (`#ffffff`); the agency themes now define it (`#ffffff`, both themes). Please add it.
2. **The light theme is ours.** Foundations is dark only; the light values in 3.2 were derived and contrast-checked. Please review or replace them with designed values.
3. **Patterns says 300 / 68 / 300; every frame measures 280 / 65 / 360.** The app follows the frames. Please correct the Patterns page.
4. **`radius/xl` is 10 in Figma and 12 in the web repo**; the agency scope uses 10 for cards and controls and keeps 12 for `agency-card`.
5. **Four dark colours fail AA-normal as drawn** (tertiary text, and the info, danger and AI pill text): see 3.1 for the lifted values. The notification count pill (`#f0506e`, white text) is 3.46:1: shipped as `#d6304f`.
6. **A light logo asset is needed.** The light sidebar inverts the dark wordmark with a filter.
7. **Elements drawn that the product cannot supply** are listed in 3.6. If any should exist, they need an API field first.
8. **The Patterns page names parts of another product** ("the workspace stepper", "Creators and Scripts stay Locked") and Components has seven components the product does not have (3.6, last row).

---

## 6. Rulings that closed the chunk (2026-11-02)

- Disabled roster Followers and Engagement filters stay (pinned by `CreatorRosterPage.spec.ts`, D-4); follow-up "feed or remove" logged.
- Dashboard "Active campaigns" and "Payments due" show a dash (the API returns `null`; pinned by `DashboardPage.spec.ts`, D-c1-4); follow-up "feed or remove" logged.
- Notification page layout goes to AH-115 (the pages are shared).
- The brand detail and list "Created" label is one i18n key, `app.brands.fields.createdAt`, 24 locales, `verify-locale` 24 / 24 (the flaky-ten locales carry real translations, no English fallback; the translations are single words from the model, not reviewed by a native speaker).
- The posted-content pending colour change and the roster table's horizontal scroll at 1280 are accepted.

**Status: CLOSED pending deploy and Pedram's walk** (close-out accepted at `1bc398e8`, 2026-11-02). Rulings on the open items:

- The signed-in creator comparison is skipped. Pedram's browser walk on the local stack covers it (creator sign-in, old render).
- The 23 non-English `createdAt` translations are accepted as machine-consistent.
- The sign-in `data-v-` id difference is accepted as pre-existing (the tech-debt row is kept).
- The `deploy-log.md` entry stays `PENDING` until the colleague publishes the main Netlify site.

## 7. Open items and where they are logged

All are rows in [`tech-debt.md`](../tech-debt.md), section "AH-114 (agency restyle) — open items": lazy-load `agency.css`; the `AgencyKpiCard` skeleton offset; the flaky `Step2ProfileBasicsPage` spec (and the `CreatorProfilePage` timeout); the roster Followers and Engagement filters; the dashboard KPI nulls; the Notifications layout (AH-115); the light logo asset; plus the AuthLayout screens, the creator-side Roboto fallback, the existing em-dash and Title Case strings, the data the designs show with no source, the future chunks (campaign status counts, the Discover age filter), the signed-in real-data check, and the build id mismatch on the sign-in page.

## 8. Deploy

Web build of `apps/main` only. API: none. No migration, no flag, no env change. Rollback: redeploy the previous `dist`. The entry is `PENDING` in [`deploy-log.md`](../runbooks/deploy-log.md). Nothing was deployed by this chunk; the creator native build 3 is a bundled binary and is not reached by a web deploy.
