# Sprint 1 — Chunk 8 Group 1 Review (theme foundations: Vuetify integration + light/dark palettes + `useTheme` composable + architecture tests)

**Status:** Closed. No change-requests; the work is mergeable as-is.

**Reviewer:** Claude (independent review) — incorporating Cursor's self-review draft.

**Reviewed against:** `PROJECT-WORKFLOW.md` § 5 (all standing team standards through chunk 7) + § 7 (spot-checks-before-greenlighting); `02-CONVENTIONS.md` § 1 + § 3 + § 4.3 (coverage thresholds); `07-TESTING.md` § 4 (architecture-test discipline); `20-PHASE-1-SPEC.md` § 5 (Sprint 1 closeout scope); all chunk-6 + chunk-7 review files (standing patterns mirror discipline for shared-shape composables now extends from chunk 7's component pattern); `tech-debt.md` (two new entries added, none triggered by Group 1's mandate); chunk-8 Group 1 kickoff (pre-answered Q1-Q3 + four pause conditions, of which none triggered).

This is the first review group of chunk 8 — the first chunk after Sprint 1's admin-side scope closure. After Group 1 lands, both SPAs have:

- A complete Vuetify-extended theme system with `light` and `dark` palettes, WCAG-AA-validated on critical pairs.
- A `useTheme` composable (path-(b) mirrored per-SPA per the chunk 7.2 D2 standing standard) exposing `currentTheme`, `setTheme(name)`, and `availableThemes`.
- Architecture tests enforcing token-usage discipline with empty allowlists (audit-confirmed zero violations).
- Zero changes to existing pages or layouts (consumer-migration is Group 2's surface — substantially narrowed by the scope-reduction findings below).

---

## Scope

Cursor's draft enumerates the full scope in detail. The merged review preserves Cursor's draft as the durable record by reference rather than restating. Brief orientation:

**Layer 1 (theme definitions):** `packages/design-tokens/src/vuetify.ts` already existed since chunk 3 with both palettes defined under custom keys (`catalystLight` / `catalystDark`). Group 1's surface narrowed to: rename keys to Vuetify-default `light` / `dark`; add missing `on-*` foreground tokens; refine dark theme error from `#EF4444` (3.69:1 — failed AA-normal) to `palette.danger[500] = #DC2626` (4.83:1 — passes); inline-document every dark-palette WCAG measurement.

**Layer 2 (theme manager composable):** `useTheme` mirrored per-SPA at `apps/{main,admin}/src/composables/useTheme.ts` (path-(b) mirror per chunk 7.2 D2). Exposes `currentTheme`, `setTheme(name)`, `availableThemes`. Internally calls Vuetify 3.7+'s preferred `theme.change(name)` API (migrated mid-build after deprecation warning surfaced — D3 below). Zero persistence per the kickoff.

**Layer 3 (Vuetify integration update):** Both SPAs' `plugins/vuetify.ts` updated to use the renamed exports + `light` / `dark` theme keys. Admin's `defaultTheme: 'dark'` preserved from pre-chunk-8 state (D1 below).

**Layer 4 (tests):** `useTheme` unit tests (7 per SPA, 100% coverage). `packages/design-tokens/src/vuetify.spec.ts` (17 passing + 1 `it.todo` for the pre-existing light primary/on-primary 2.49:1 failure, with runtime WCAG computation via `colord/plugins/a11y`). Three architecture tests per SPA (mirrored, all allowlists empty): no-hard-coded-colors, no-inline-color-styles, use-theme-is-SOT.

---

## Acceptance criteria — all met

(All Group 1 acceptance criteria from the kickoff — both SPAs have light + dark themes, `useTheme` composable exists with 100% Vitest coverage, both SPAs' `createVuetify` calls register both themes, architecture tests are in place with empty allowlists, all existing tests remain green, lint/typecheck clean across all three apps + api package, two new tech-debt entries (both surfaced pre-existing concerns rather than introduced by Group 1's mandate), no changes to existing page components — all ✅. Reproduced verbatim in Cursor's draft; merged review preserves the same line-by-line verdicts. Verification gates summarized in §"Verification results" below.)

---

## Plan corrections / honest deviation flagging — three items

**Tenth instance** in chunks 6 + 7 + 8.1 of Cursor flagging where the kickoff carried hidden assumptions that didn't hold. **Ten for ten; the pattern is now load-bearing as a team standard.**

Deviation count for Group 1 is **3**, distributed across three categories — all are paraphrase-vs-actual issues or design pivots, none are tech-debt-flagged carry-forwards or structurally-correct adaptations. Notably: **two of the three deviations are scope-reducing** — the kickoff anticipated more foundational work than was actually needed because chunk 3 had already laid most of the foundations.

### D1 — Admin `defaultTheme: 'dark'` preserved despite kickoff's "light is default" wording (paraphrase-vs-actual, genuinely pre-existing)

**Implicit kickoff assumption:** "light is default" for both SPAs.

**Why it didn't hold:** Admin's `defaultTheme: 'catalystDark'` was set in Sprint 0's scaffolding commit (`3673ea1`, 2026-05-06) — one week before chunk 8 started. Group 1 only renamed the key from `'catalystDark'` to `'dark'`; it did not flip the SPA from light to dark default. The Sprint 0 design rationale for admin = dark was never formally documented; chunk-8.1's review file is the first place this asymmetry is surfaced.

**Alternative taken — accepted:** Preserve the pre-existing asymmetry. Main remains light-default; admin remains dark-default. **Flagged for Group 2's kickoff as an explicit design question** (Option A: preserve dark-default; Option B: prefers-color-scheme symmetric; Option C: preserve asymmetry with explicit cross-SPA layered fallback). The recommendation is Option C absent contradicting UX research, but **this is a design decision, not an implementation decision**, and the Group 2 kickoff will surface it as a Q rather than pre-answer it.

**Why this is correct, not a bug:** The asymmetry is intentional Sprint 0 design; reverting it without UX research would change one-week-old established behavior. Surfacing it pre-emptively lets the Group 2 design choice be deliberate rather than incidental.

### D2 — Theme definitions stay in `packages/design-tokens/`, not `apps/{main,admin}/src/core/theme/` as the kickoff suggested (paraphrase-vs-actual, foundations-already-existed)

**Implicit kickoff assumption:** Theme definitions would live in `apps/main/src/core/theme/index.ts` OR `packages/ui/src/theme/index.ts` (pick based on shared-package availability).

**Why it didn't hold:** `packages/design-tokens/src/vuetify.ts` already existed since chunk 3 as the SOT for both palettes. This is the path-(a) shared-package extract pattern from chunks 6.5-6.7, but applied to design tokens rather than UI components. There's no `packages/ui` (and no need for one); design-tokens is its own focused package.

**Alternative taken — accepted:** Keep design-tokens as SOT. Update existing palette + token exports rather than create a parallel module. The architecture-test scope naturally excludes `packages/design-tokens/src/vuetify.ts` because the walk is rooted at `apps/{main,admin}/src/` — so a future contributor moving theme-definition logic INTO an SPA would (a) violate the SOT decision, AND (b) trip the test on any literal hex they write.

**Why this is correct, not a divergence:** The kickoff didn't know `packages/design-tokens` existed; chunk 8.1's read pass discovered it. The "where do theme definitions live" decision was already made in chunk 3, and Group 1's job is to extend that existing structure, not duplicate it.

### D3 — Migrated to Vuetify 3.7+'s `theme.change(name)` API mid-build after deprecation warning surfaced (paraphrase-vs-actual, framework-driven)

**Implicit kickoff assumption:** `useTheme` would internally call `theme.global.name.value = name` directly (matching the kickoff's structural description).

**Why it didn't hold:** During the build, Vue dev-server emitted a deprecation warning that direct mutation of `theme.global.name.value` is deprecated as of Vuetify 3.7+; the preferred API is `theme.change(name)`. The deprecation warning surfaced at unit-test runtime, not at lint or typecheck time.

**Alternative taken — accepted:** Migrate to `theme.change(name)` in the same chunk. The architecture test still forbids direct mutation of `theme.global.name.value` as regression protection — legacy pattern usage would now be both deprecated AND test-caught.

**Why this is structurally correct:** Following framework-driven deprecations in the same chunk that introduces the consuming code is much cheaper than letting it accumulate as tech debt. The architecture test catching the legacy pattern is the durable enforcement.

### Process record on these three deviations

The "file:line citations to main" discipline upgrade introduced in chunk 7 Group 2 carries forward; here it's "file:line citations to existing chunk-3 state" since most foundations were already there. **Zero paraphrase-vs-actual deviations of the type chunk 7 surfaced** (e.g., "the route table location is X but actually Y") — the three deviations here are all scope-reducing or framework-driven, surfaced during the read pass rather than at implementation time. **The pattern's stable; what changed is what the deviations look like in an application-phase chunk vs. a foundation-establishment chunk.**

---

## Standout design choices (unprompted)

Cursor's draft enumerates several design choices. Four deserve highlighting as broadly applicable patterns:

- **Runtime WCAG contrast computation via `colord/plugins/a11y` rather than hard-coded ratios.** Tests run `colord(fg).contrast(bg)` against live theme objects and compare to WCAG thresholds (4.5 / 3.0). No assertion contains a hard-coded ratio value. **Verified empirically during spot-check 1** — temporarily changing `palette.danger[500]` to `#FF0000` produces test failures with the actual current contrast ratio (`3.99:1 is below WCAG AA-normal`). This is the canonical pattern for any contrast/accessibility test: **compute the metric inside the test, compare against the standard's threshold, never bake in pre-computed values that won't catch regressions.**

- **Architecture test scope via `fs.readdir` recursive walk rooted at SPA `src/`, NOT via explicit glob.** The walk naturally excludes the design-tokens package (out of scope by directory boundary), naturally excludes test files (filter on `.vue` extension), and recursively includes every other `.vue` file. **The "empty allowlist" claim is structurally meaningful because the walk's coverage IS the proof** — there's nothing the walk could miss within the SPA's `src/`. Canonical for architecture tests that need full coverage of a directory tree without explicit enumeration.

- **Negative lookahead `(?!var\()` permits `rgb(var(--v-theme-background))` while catching literal `rgb()`.** A single-line regex addition that distinguishes CSS-variable consumption (allowed; the value comes from Vuetify's theme tokens) from literal hard-codes (forbidden). Canonical for any "no hard-coded colors" pattern where CSS-variable consumption needs to coexist.

- **Same-chunk migration of framework deprecation warnings + architecture test as regression protection.** When the Vuetify 3.7+ deprecation surfaced mid-build, the response was: migrate now, then add an architecture test forbidding the legacy pattern. This converts framework-driven churn into permanent invariants of the codebase. Canonical for any framework-driven deprecation that surfaces during related work.

---

## Decisions documented for future chunks

- **Path-(b) mirror discipline now applies to shared-shape composables, not just components** (D4 in standing standards). Both `useTheme` composables are byte-identical at the source level except for the import paths of their tests' mocks. Future shared-shape composables follow the same path-(b) mirror until a third consumer surfaces or shared-package extraction is otherwise justified (rule-of-three).

- **Empty allowlist is the preferred starting state for architecture tests when the audit confirms compliance** (D5). Chunk 8.1's audit confirmed zero pre-existing hard-coded colors across all `.vue` files; the empty allowlist is enforcement-from-day-one. Deferred allowlists with later migration are weaker — they allow regression in the unmigrated set. **Audit first, then decide; prefer empty when you can.**

- **Same-chunk migration of framework deprecation warnings + architecture test as regression protection** (D6). When framework-driven deprecations surface during related work, migrate in the chunk that surfaces them and add architecture-test enforcement against the legacy pattern. The cost is lower than accumulating tech debt.

- **`packages/design-tokens` is the SOT for shared design tokens across both SPAs.** Established in chunk 3, confirmed in chunk 8.1. Architecture tests' SPA-rooted walks naturally exclude this directory; future contributors who try to duplicate theme-definition logic inside an SPA's `src/` will fail the no-hard-coded-colors test.

- **The asymmetric default theme question is a design decision deferred to Group 2's kickoff.** Main = light-default; admin = dark-default. Group 2 will surface this as an explicit Q with three options (preserve asymmetry, force symmetric system-default, force symmetric coded-default). **Claude's kickoff for Group 2 will include this Q with the Group 1 review's recommendation of Option C (preserve asymmetry) as the suggested default.**

---

## Tech-debt items

**Two new entries added (both surface pre-existing concerns rather than introduced by Group 1's mandate):**

- **"Light theme primary/on-primary fails WCAG AA-normal (2.49:1)"** — pre-existing palette failure since chunk 3. The kickoff explicitly said "don't redesign the light palette in Group 1"; this is recorded as `it.todo` in the contrast spec with the failing-pair documentation inline, and added to tech-debt.md with four resolution options (darken on-primary; lighten primary; introduce a separate light-on-primary token; redesign light palette). Group 2 + sprint cleanup may address; otherwise carried to Sprint 2.

- **"Dormant `tokens.css` CSS-variable system + `@media (prefers-color-scheme: dark)` block"** — surfaced during Group 1's audit. `packages/design-tokens/src/tokens.css` defines `--color-*` CSS variables with a `@media (prefers-color-scheme: dark)` override block. Neither is consumed by any `.vue` file (those consume `var(--v-theme-*)` instead). The dormant system existed before chunk 8; Group 1 didn't activate it. **Flagged so Group 2's `prefers-color-scheme` detection work doesn't accidentally consume the dormant `--color-*` variables instead of `--v-theme-*`.**

**Pre-existing items from chunks 6 + 7 remain open** (Vue 3 attribute fall-through architecture test, idle-timeout unwired on both SPAs, SQLite-vs-Postgres CI, TOTP issuance does not honor `Carbon::setTestNow()`, etc.). None are triggered by Group 1 work.

---

## Verification results

| Gate                                    | Result                                                                         |
| --------------------------------------- | ------------------------------------------------------------------------------ |
| `apps/api` Pint                         | Pass                                                                           |
| `apps/api` PHPStan                      | Pass — 0 errors across 217 files                                               |
| `apps/api` Pest                         | 367 passing (1088 assertions)                                                  |
| `apps/main` typecheck / lint / Vitest   | Pass / Pass / 244 passed (100% coverage on composable)                         |
| `apps/admin` typecheck / lint / Vitest  | Pass / Pass / 190 passed (100% coverage on composable)                         |
| `packages/design-tokens` Vitest         | 17 passed + 1 `it.todo` (light primary/on-primary AA-normal — tech-debt entry) |
| `packages/api-client` Vitest            | 88 passing                                                                     |
| Repo-wide `pnpm -r lint` / `typecheck`  | Clean                                                                          |
| Architecture tests (6 total: 3 per SPA) | All green; all allowlists empty (audit-confirmed zero violations)              |
| Playwright `pnpm test:e2e`              | Not exercised by Group 1; no production-page changes                           |

---

## Spot-checks performed

Three spot-checks, all green. **The strongest spot-check pass since chunk 7 Group 3.**

### Spot-check 1 — Dark theme WCAG AA contrast assertions computed correctly

**Verdict: green, with empirical proof.** Tests use `colord/plugins/a11y` for WCAG-2.1 contrast computation (`colord(fg).contrast(bg)` at `vuetify.spec.ts:98`). Thresholds (`AA_NORMAL = 4.5`, `AA_LARGE = 3.0` at lines 86-87) are the WCAG specification values; **no assertion contains a hard-coded per-pair ratio.** Every assertion runs the live computation against current theme objects.

**Empirical verification:** Cursor went beyond the asked-for source-inspection. Temporarily edited `palette.danger[500]` to `#FF0000`, ran the spec, captured the failure output (`lightTheme error / on-error contrast 3.99:1 is below WCAG AA-normal (4.5:1)`), reverted, re-verified back to 17 passed + 1 todo. **This is runtime proof that the test catches regressions, not just source-inspection inference.** Worth recording as the gold standard for "is the metric actually checked" verification.

### Spot-check 2 — Architecture test scope is correct

**Verdict: green by structural design.** Not a glob — recursive `fs.readdir` walk rooted at `apps/{main,admin}/src/`, filtering for `.vue` extension. Theme definitions are out of scope by directory boundary (live at `packages/design-tokens/`, outside SPA `src/`). Test files are out of scope by extension filter (`.spec.ts` not `.vue`). Every other `.vue` file IS scanned (audit-confirmed 14 in main, 8 in admin). The negative lookahead `(?!var\()` correctly permits `rgb(var(--v-theme-background))` while catching literal hex/rgb/hsl. **The empty allowlist is meaningful — the walk's coverage IS the proof.**

### Spot-check 3 — D1 (admin `defaultTheme: 'dark'`) genuinely pre-existing

**Verdict: green.** Sprint 0 commit `3673ea1` (2026-05-06, one week before chunk 8) is the file's first appearance, AND it already had `defaultTheme: 'catalystDark'`. Group 1 only renamed `'catalystDark'` → `'dark'`; it did not flip from light-default to dark-default. The asymmetry has been the design since the very first scaffolding commit. **Surfaced as a Group 2 kickoff question** — three options (preserve dark-default, force prefers-color-scheme symmetric, preserve asymmetry with layered fallback), with recommendation Option C absent UX research. **This is a design decision, not an implementation decision; Group 2 will decide it explicitly.**

### Diff stat

11 modified files (+306/-25 in `git diff --stat`, including pnpm-lock.yaml for new colord + @vitest/coverage-v8 + vitest devDeps), 13 untracked files (+1,520 LOC). Total chunk-8.1 surface: ~1,800 LOC across 24 files, ~85% of which is tests + review documentation. Production-runtime surface is small: 137 LOC composable + ~80 LOC theme refinement + ~60 LOC plugin updates ≈ 280 LOC. Shape matches expectations: foundations-and-tests group, not consumer-migration group.

---

## Cross-chunk note

None this round. Confirmed:

- Chunk 3's `packages/design-tokens` is the SOT for both palettes; Group 1 extends rather than duplicates.
- Chunk 6-7's standing patterns (path-(b) mirror discipline, architecture-test enforcement, honest deviation flagging with category labels, file:line citations, disciplined self-correction at spot-check time) all carry forward.
- Sprint 0's admin = dark-default scaffolding decision (Sprint 0 commit `3673ea1`) is preserved, not overridden.
- The chunk 7 dual-SPA architecture-test mirror discipline extends naturally to composables (D4 standard) and to design-token specs (the contrast spec at `packages/design-tokens/src/vuetify.spec.ts` is shared, not mirrored — it's testing the shared SOT).

---

## Process record — compressed pattern (tenth instance)

The compressed pattern continues to hold. Group 1 was theme foundations only in one Cursor session, with the kickoff pre-answering Q1-Q3 (theme integration approach, naming convention, light/dark palette strategy) and explicit pause conditions (none triggered). The result: single-round closure with zero change-requests, three honest deviations all surfaced and categorized.

Specific observations:

- **The "audit first, then decide" pattern for architecture tests is valuable.** Group 1 audited existing `.vue` files for hard-coded colors BEFORE deciding the architecture test's allowlist policy. The audit confirmed zero violations, so the allowlist could start empty (enforcement-from-day-one) rather than deferred-with-migration. **This is the cleaner shape.** Recorded as D5 standing standard.

- **Mid-spot-check empirical verification is the strongest disciplined-self-correction yet.** When asked to verify the contrast test catches regressions, Cursor temporarily edited the palette, ran the spec, captured the failure, reverted. This goes beyond source-inspection — it's runtime proof. **Recorded as the canonical "is the metric actually checked" verification pattern.** Mid-spot-check disciplined self-correction continues to be the most reliable indicator of work that's been thought through.

- **Scope-reducing deviations are valuable findings.** Two of Group 1's three deviations narrowed the work rather than expanding it — chunk 3 had already laid foundations the kickoff anticipated needing to build. **This is the right direction for application-phase chunks** vs. the foundation-establishment chunks 6-7 where deviations tended to expand work. Sprint 2+ should expect more scope-reducing deviations as the standards-application phase deepens.

- **Single spot-check round was sufficient.** Three spot-checks, all green, all dispatched cleanly. **Zero change-requests** for the fifth consecutive review group (chunk 7 sub-chunk 7.1 close, Group 1, Group 2, Group 3, plus this Group 1 of chunk 8). The combination of compressed pattern + pre-answered Q1-Q3 + explicit pause conditions + disciplined self-correction + load-bearing spot-check selection is operating at full effectiveness.

---

## What Group 1 closes for chunk 8

- ✅ Both SPAs have light + dark theme definitions with WCAG-AA-validated dark palette on critical pairs.
- ✅ `useTheme` composable mirrored per-SPA with 100% Vitest coverage; uses Vuetify 3.7+'s preferred `theme.change()` API.
- ✅ Both SPAs' Vuetify plugins register both themes with their pre-existing defaults preserved (main = light, admin = dark).
- ✅ Six architecture tests in place (3 per SPA) with empty allowlists, enforcing token-usage discipline from day one.
- ✅ Two tech-debt entries documenting pre-existing concerns surfaced during Group 1's audit (light primary/on-primary AA-normal failure, dormant tokens.css CSS-variable system).
- ✅ Group 2 scope substantially narrowed — consumer pages need no migration; Group 2's real work is preferences + toggle UI + system-default detection + the asymmetric-default-theme design Q.

**Group 2 (next session) covers theme consumers + user preferences + Sprint 1 cleanup, with the asymmetric-default-theme question surfaced as an explicit Q in its kickoff.** Per the scope-reduction analysis, the "migrate consumers" surface is essentially empty (all `.vue` files already use Vuetify props or `var(--v-theme-*)`); the real work is persistence (sessionStorage / cookie / server-stored — design decision), toggle UI (button placement, dropdown vs. binary), system-default detection (`prefers-color-scheme` with the layered fallback per the recommended Option C), and any remaining Sprint 1 cleanup items.

---

_Provenance: drafted by Cursor on Group 1 completion (compressed-pattern process per `PROJECT-WORKFLOW.md` § 3 step 6, modified). Independently reviewed by Claude with three targeted spot-checks (WCAG contrast computation with empirical regression-catching proof; architecture test scope verification; admin dark-default pre-existence verification). Three honest deviations surfaced and categorized (all paraphrase-vs-actual; two scope-reducing, one framework-driven), all resolved with structurally-correct alternatives. The pattern of "every chunk-6 + 7.1 + 7.2-7.3 + 7.4 + 7.5-7.7 + 8.1 group catches at least one hidden assumption" is now ten-for-ten. Status: Closed. No change-requests; Group 1 lands as-is. Closes chunk 8 Group 1 and stages Group 2 (theme consumers + user preferences + Sprint 1 cleanup) as the chunk-8 + Sprint-1 closure work._
