# PL-2.1 review: Workspace, USPs and Personas

**Branch:** `pl`. **Date:** 2026-11-19. **Status:** Stage 1 (backend) reviewed and accepted by Pedram. Stage 2 (web) built, committed locally, **not pushed**: it waits for Pedram's walk. Plan: `pl-2-1-plan.md`. Break-reverts: `pl-2-1-break-reverts.md` (Stage 1 and Stage 2 sections).

## Stage 1 (backend): differences recorded as accepted

Accepted by Pedram on review of Stage 1:

1. **`WaveApiTest` route counter exclusion.** `WaveApiTest`'s route counter now excludes the strategy routes (they belong to the Strategy module, whose own tests pin them).
2. **409 `strategy.conflict` on a clash after a retry.** When a unique index (colour, slot or code) still refuses a write after the service has retried, the API answers 409 `strategy.conflict` instead of a 500. The web shows "Someone changed this at the same moment. Reload and try again."
3. **Default persona code suffix.** When the default code of a new persona (market plus slot, e.g. `SK2`) is taken, the server tries `SK2A` to `SK2Z` before it gives up with a conflict. An explicit code that is taken is refused with `persona.code_taken` as the plan said.

Other Stage 1 differences from the plan, recorded earlier in the Stage 1 report: audit actions with their catalogue and erasure decisions went in the same commit as the code that writes them (not a later one); `persona.copy_invalid` covers more cases than the plan listed; `GET personas` takes no `?market` and returns every persona of the wave, grouped by the web.

## Stage 2 (web): what was built

New module `apps/main/src/modules/strategy`; `packages/api-client` types and typed readers; `features.strategy`. No file in `modules/agency` was edited (22 pinned files; the pin test passes). Commits, in order: `ab5bdc83` api-client and API module, `e6e05e4e` 140 strings in 24 locales and the keys spec, `5fddf6a1` routes, entry, stepper, steps, `b5725a9e` specs, break-reverts commit, seeder pair, the Playwright spec, and the browser-pass fixes (`d0406922`).

- **Entry:** a "Strategy & Scripts" button in the wave page header, all agency roles, drawn from a list of zero or one item so that with the flag off there is no node at all (W-OFF).
- **Routes:** `strategy.index` (redirects to USPs by path), `strategy.usps`, `strategy.personas` (`?market=SK`), all with `layout: 'agency'` and the agency-user guards. The page checks both flags on mount, before any request: waves off goes to the brand, strategy off goes to the wave.
- **Header, stepper:** `AgencyPageHeader` with the back button and "Brand · Wave" subtitle; six steps (USPs and Personas are links; Matrix and Concepts "Not available yet"; Creators and Scripts locked); the four are plain elements, `aria-disabled`, no tab stop.
- **Step 1 USPs:** audience title with its dialog, counter "n of 6 USPs" and "6 of 6 USPs · maximum reached" with Add disabled, warning under four, card grid, add and edit dialog with an explicit Save (edit sends only changed fields), drag by the grip and Move earlier / later (both send the full order), delete with the in-use confirmation listing market, persona code, title and the angle text and sending the count it showed; a 409 `usp.in_use` is read through `uspInUseMeta` and the list is refreshed.
- **Step 2 Personas:** market tabs from the wave, counts and an amber dot under two, cards with pronoun-driven labels, add and edit dialog with the four list repeaters, angles with the USP dropdown ("Linked", "Added by hand"), "n of 8 used in [market]", warning under two, copy from another market with a tick list and a live line, delete with the angle count.
- **Wave edit dialog:** `wave.market_has_personas` is read from `error.details[0].meta.markets` through `marketHasPersonasMeta`, like `deletionPendingMeta`.
- **Strings:** 140 keys in 24 locales (`app.strategy.*` and `app.waves.errors.marketHasPersonas`). Parity, placeholders, two-form plurals and "not an English copy" are gated by `core/i18n/strategyKeys.spec.ts`.
- **Seeders:** `Pl21WalkSeeder` and `Pl21WalkCleanupSeeder` (local and testing only, idempotent, never touch a flag), `Pl21WalkSeedersTest` (5 tests).

## Screens built with no design (each named)

None of these has a frame. Each is built from Vuetify components and the agency theme classes; none is visually invented beyond that.

1. Add USP and Edit USP dialog (title, explainer, creative angle, copy seed, colour note, Save and Cancel).
2. Delete USP confirmation, plain and with the affected list (and its "list changed, confirm again" state).
3. Audience title dialog.
4. Add persona and Edit persona dialog (code, title, name, pronoun, summary, core job, the four list repeaters, need).
5. Copy from another market dialog (source select, tick list, live line, over-the-maximum state).
6. Delete persona confirmation (with the angle count) and Remove angle confirmation.
7. Angle add and edit inline row, and the "Add USPs in step 1 first" hint.
8. The stepper's Not available yet and Locked steps.
9. The page shell, and the loading, empty, error and "removed by someone else" states of both steps.
10. The warning lines and counters (below four USPs, below two personas, maximum reached).
11. The wave page entry button.

## Differences from the frames and from the plan

1. **Persona code format.** The plan's walk step 8 edits the code to "SK-A". The server accepts only 2 to 8 letters or digits (`[A-Z0-9]{2,8}`), so "SK-A" is refused. The walk uses **"SKA"**.
2. **Seed content.** The plan said Wave 7 holds the frame's six USP texts and Zuzana and Peter. The frame's text was not read for this build, so the seeder uses invented, plausible USP texts ("Delivered in 30 minutes", "No hidden fees", and so on) and SK personas Zuzana and Marek. Counts and colours are as the plan said. Wave 10's SK personas are Jana, Peter and Alex.
3. **Dialogs on a narrow screen.** The plan said every dialog goes fullscreen below 600 px. The browser pass showed that the small ones (the two confirmations, the audience title, copy, add and edit USP) became a nearly empty full screen. Only the **persona dialog** (long) is fullscreen on a phone; the others are centred cards. Titles wrap instead of being cut with an ellipsis.
4. **Stepper width.** From 1100 px the six steps share the bar's width and the status line wraps, so no step is clipped (at 1440 with the side menu the last step was cut off). Under 1100 px the bar keeps its natural width and scrolls inside itself, as planned.
5. **Audience bar.** The theme's `agency-card` is a column; the audience bar is a row that wraps (audience left, counter and Add right). Found in the browser, fixed with a selector that beats the theme's.
6. **Wave edit message.** The plan's walk step 15 says "Remove the personas of SK first (2)". The built message lists every market with its count: "Remove the personas of these markets first: SK (2), RO (1)."
7. **USP card number.** Shown as "01" to "06" (as the frame's chip), not "1".
8. **Route redirect by path.** `strategy.index` redirects with a path, not a route name: the agency route-pin architecture test reads `routes.ts` with a regular expression and would take a second `name:` key for a second route.
9. **Copy dialog and delete use the lists already loaded.** The persona list of the wave is loaded once and grouped by market in the browser; switching tabs never refetches, and every save reloads it.
10. **The audience title limit** comes from the server with the summary (the column is 80 characters); the web holds no copy of any limit.

## Production posture

- **Nothing is deployed and nothing is pushed.** `pl` is local; main is untouched.
- **Both flags are off by default and the build never turned either on.** With `waves_enabled` off, or on with `strategy_enabled` off, the wave page is exactly as PL-1 left it: no button, no node where it would be (3 empty comments in the actions area, the same 3 PL-1 had, measured on the PL-1 commit `246713f5`), and the strategy address leaves for the wave or the brand before any request. Every strategy endpoint answers 404 until both are on.
- **Stage 1 migrations are schema-only** (four: one nullable column and three new empty tables), rehearsed on a throwaway PostgreSQL database (`pl-2-1-migration-rehearsal.md`). Stage 2 adds no migration.
- **The seeders refuse to run outside local and testing** and never touch a flag. The walk accounts live under `@pl21-walk.local` and `@pl21-walk-b.local` and the cleanup removes exactly those.
- **User text is only ever drawn through Vue interpolation.** An architecture spec fails if any file under `modules/strategy` contains `v-html`, `innerHTML` or another markup sink; a hostile string typed into a USP, an explainer, a persona title and name, an angle and the audience title shows as text, in a real browser, with no element created and no alert.
- **Local dev database:** `waves_enabled` is **on** (Pedram turned it on for the PL-1.3 walk; read-only query, not changed) and `strategy_enabled` has no row, which is **off**. The Stage 1 migrations are **not yet applied** there: the walk starts with `php artisan migrate`.

## Checks run

| Check                                                                                                                                                                 | Result                                                                                                                    |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Vitest, touched set: `modules/strategy`, `waves`, `brands`, `core/i18n`, `tests/unit/architecture` (`VITEST_MAX_WORKERS=4`)                                           | green (counts not recorded here; re-run to get them)                                                                      |
| New Vitest specs: StrategyPage (5), UspsStep (12), PersonasStep (13), strategyEntry (4), strategyKeys (7), no-raw-html (2), NewWaveDialog (2 added), errors (4 added) | green                                                                                                                     |
| `packages/api-client` Vitest                                                                                                                                          | green for `errors.spec.ts` (+4 reader tests); whole-package count not recorded here                                       |
| Agency route pin (`agency-routes-agency-user-guard`) and agency frozen-file pin                                                                                       | green                                                                                                                     |
| `vue-tsc --noEmit`                                                                                                                                                    | clean                                                                                                                     |
| ESLint (strategy, waves, auth, i18n, architecture specs, the Playwright spec)                                                                                         | clean                                                                                                                     |
| Prettier check on strategy, waves and the Playwright spec                                                                                                             | clean                                                                                                                     |
| Pint (`--test`), Larastan                                                                                                                                             | passed                                                                                                                    |
| Pest: Strategy, Waves, Audit, Erasure, Tenancy folders, SQLite                                                                                                        | green on SQLite (counts not recorded here)                                                                                |
| Pest: the same five folders, PostgreSQL (throwaway database)                                                                                                          | green on PostgreSQL (counts not recorded here)                                                                            |
| Playwright `strategy-workspace.spec.ts`, serial, Chromium: 1440, 390, and strategy-off                                                                                | 3 passed                                                                                                                  |
| Playwright `wave-tracker.spec.ts` and `brands.spec.ts` (the wave page I touched)                                                                                      | 4 passed                                                                                                                  |
| Flag-off walk, agency set: `node tools/walk/walk.mjs --set agency --compare ../../docs/reviews/ah-115-agency-baseline/baseline.json`                                  | 126 targets identical                                                                                                     |
| Flag-off walk, creator set: `... --set creator --compare ../../docs/reviews/ah-115-creator-baseline/baseline.json`                                                    | COMPARE OK, 146 targets identical (the 32 page errors are recorded in the baseline itself)                                |
| Waves on, strategy off: the wave page                                                                                                                                 | `strategyEntry.spec.ts` (no button, same comment nodes as PL-1, same actions markup) and the Playwright strategy-off test |
| Break-reverts: 33 web gates, each red when broken and green when restored                                                                                             | `pl-2-1-break-reverts.md`, Stage 2 section                                                                                |

## Real-browser pass (WORKING-PROCESS §4.2)

Setup: a throwaway PostgreSQL database `pl21_walk` seeded by `Pl21WalkSeeder`, API on 127.0.0.1:8000, Vite on 127.0.0.1:5173, both flags turned on **in that database only** through the test helper, Chromium through Playwright, signed in as the seeded admin. Screenshots were looked at, not only measured. The database was dropped afterwards, as was the throwaway test database used for the PostgreSQL Pest run.

| Looked at                                                                                                                                            | 1440 x 900                           | 390 x 844                            |
| ---------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------ | ------------------------------------ |
| Wave page with the entry button                                                                                                                      | looked                               | looked                               |
| Wave 7 USPs (six cards, audience title, "6 of 6 USPs · maximum reached", Add disabled)                                                               | looked                               | looked                               |
| Wave 9 USPs (warning under four), Wave 6 (empty state)                                                                                               | measured and in the Playwright spec  | measured and in the Playwright spec  |
| Wave 6 Add USP dialog with the empty-title error; the same dialog with the hostile string typed                                                      | looked                               | looked                               |
| USP card menu (Edit, Move earlier disabled on the first, Move later, Delete)                                                                         | looked                               | opened (spec)                        |
| Delete USP in use (Wave 9): the affected list and "Delete and remove 1 angle"                                                                        | looked                               | looked (title was cut: fixed)        |
| Personas, Wave 9 RO (eight, maximum) and SK (one, warning, amber dot on the tab)                                                                     | looked                               | looked                               |
| Edit persona dialog (Wave 7)                                                                                                                         | looked                               | looked                               |
| Angle USP list ("Linked" and "+"), copy dialog with the live line (Wave 7 CZ)                                                                        | looked                               | opened (spec)                        |
| Wave 8 with hostile text in a USP, a persona (title, name, summary) and an angle                                                                     | looked: plain text, 0 `img` elements | looked: plain text, 0 `img` elements |
| Quick open, close and reopen (0, 60, 150 ms) of: Add USP dialog, USP card menu, delete confirmation, Add persona dialog, angle USP list, copy dialog | in the Playwright spec, green        | in the Playwright spec, green        |
| Sideways page scroll on each step                                                                                                                    | none (measured 0)                    | none (measured 0)                    |
| `alert` dialogs fired by the hostile strings                                                                                                         | none                                 | none                                 |

**Found and fixed during the pass:** (1) the audience bar was a centred column (the theme card's column won over the module's row); (2) the last step of the stepper was clipped at 1440; (3) the confirmation dialogs were fullscreen at 390 with a lot of empty space and their titles ended in an ellipsis; (4) a Playwright step reloaded the page before the reorder request finished (a test race, not a product bug; the spec now waits for the response).

**Not looked at in a browser:**

- Dragging a card by its grip with a real mouse or finger (the order is saved by Move earlier / Move later, which was exercised; the drag itself goes through `vuedraggable`'s `@change`, covered only by the shared code path and not by a pointer test).
- Confirming a delete of a USP (plain or in use), and the Remove angle and Delete persona confirmations: the dialogs of the first were opened and read, the delete calls are covered by Vitest; Remove angle and Delete persona were not opened in a browser.
- Editing an existing angle, the 409 "list changed" path of the delete dialog, and the "removed by someone else" states: Vitest only.
- Walk steps 13 and 14 (two tabs, same card by two people): not run in a browser; the server side is covered by the Stage 1 tests.
- `manager@` and `staff@` and Agency B sign-ins on screen (covered by Vitest and the Stage 1 API tests; the staff disabled Delete and its reason were opened only in Vitest).
- The other 23 locales on screen, light theme, a real phone (only a 390 px window).

## Not read and not run

- **Not read:** the Figma frames for this chunk (the seed texts are invented; see difference 2); the brief documents beyond the plan.
- **Not run:** the full Vitest suite; the full Pest suite (only the Strategy, Waves, Audit, Erasure and Tenancy folders, on both databases); CI (nothing is pushed); ESLint on `packages/api-client` (that folder is outside the app's ESLint config; it is covered by `vue-tsc` and its own Vitest); the other Playwright specs besides `strategy-workspace`, `wave-tracker` and `brands`; a production-size or concurrent PostgreSQL race run .
