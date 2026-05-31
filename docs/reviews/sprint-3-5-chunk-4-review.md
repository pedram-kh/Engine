# Sprint 3.5 — Chunk 4 Review

**Status:** Closed. Spot-check approved with **zero PMC corrections** — the first chunk of the mini-sprint with no review-time fixes, because build-phase discipline (pause-condition-#4 vendor:publish inspection, `git status` after break-revert, the eyes-on loop) caught everything before it reached review. The work is mergeable as-is.

**Reviewer:** Claude (independent review + spot-check pass, 2026-05-31) — incorporating implementation details from Cursor's self-review draft.

**Reviewed against:** `PROJECT-WORKFLOW.md` § 3 (chunk lifecycle) + § 5 standards (5.1 source-inspection tests, 5.3 real-rendering mailable, 5.15 architecture-test allowlist discipline, #34 cross-chunk handoff, #40 break-revert defence-in-depth, #41 Pint CI-authoritative) + § 6 (sub-chunk planning), `docs/reviews/sprint-3-5-chunk-1-review.md` (D7 aurora-utility-only + inheritance contracts 1–4), `docs/reviews/sprint-3-5-chunk-3-review.md` (container/variant tokens + latent-bug discipline), the Sprint 3.5 Chunk 4 kickoff + the plan-approval message (build order B→C→A; per-workstream commit split; MAX_LINES raise; mail teal-700 button + links).

This is the **brand-surfacing chunk** of the Engine C v2 visual-layer refresh — the first chunk that makes the aurora accent _visible_ (registered as a utility in Chunk 1, applied to zero surfaces until now). Three loosely-coupled workstreams with different risk profiles: **A** aurora thin-accent surfacing (aesthetic, eyes-on), **B** semantic-chip warm-`neutral` dependency severance (deterministic, contrast-bound), **C** email mail-theme publish+author+verify (deterministic). Built B→C→A so the deterministic work settled before the eyes-on loop.

---

## Scope — three workstreams, per-workstream commit split

### Workstream B — semantic-chip warm-`neutral` severance (commit `cb0baea`)

`packages/design-tokens/src/vuetify.ts` was the **last runtime consumer** of the warm `neutral` primitive (grep-confirmed: `semantic.ts`/`tokens.spec.ts` import only `brand`/`zinc`; the parity specs' `neutral` matches are `NEUTRAL_SLOTS` constants). Severed it so the warm scale can be deleted in Chunk 5:

- **`on-info` / `on-success`** (both themes): `neutral[0]` → `'#FFFFFF'` literal. **Value unchanged** — white-on-info is ~4.07:1 and white-on-success ~3.13:1, both already in the marginal AA regime, so a naive `neutral[0] → zinc[50]` map would have **worsened** them. Dependency severed, zero visible shift, zero contrast regression.
- **`on-warning`** (both themes): `neutral[900]` (`#121211`) → `zinc[950]` (`#09090B`). The **only** `on-*` value change; ~10.4:1 → ~10.6:1 (imperceptible, nudges up).
- `neutral` removed from the `./tokens` import.
- New source-inspection regression-lock (`vuetify.spec.ts`): asserts `vuetify.ts` does not import `neutral`, `on-info`/`on-success` are pure white (NOT `zinc[50]`), `on-warning` is `zinc[950]`.

### Workstream C — Engine C v2 brand mail theme (commit `fbc0874`)

A publish-then-author, not an edit (nothing was published to tokenize):

1. `php artisan vendor:publish --tag=laravel-mail` → published the standard Laravel 11 markdown mail view set (`html/` button/footer/header/layout/message/panel/subcopy/table + `themes/default.css`, `text/` mirrors). Inspected: **no unexpected files** (pause condition #4 clear on contents).
2. Authored `resources/views/vendor/mail/html/themes/catalyst.css` — a brand rebrand of the stock theme. **LIGHT surface** (email dark-mode is unreliable across clients), mirroring the SPA light mode: zinc-50 page, white panel, zinc text/borders.
3. `config/mail.php`: added the `markdown` block (`theme => 'catalyst'`, `paths => [resource_path('views/vendor/mail')]`). The file previously had **no** `markdown`/`theme` key at all.
4. Blades unchanged (already clean — `trans()` keys + `mail::` components).
5. Real-rendering verification (`tests/Feature/Mail/MailThemeBrandingTest.php`, standing standard 5.3): renders all 4 product mailables via `(string) $mail->render()` and asserts the brand teal-700 (`#0b6f66`) is inlined AND the stock default button colour (`#2d3748`) is gone — proving the catalyst theme is actually applied.

### Workstream A — aurora brand surfacing (commit `d2a2cfe`)

Thin chrome accents (Decision D7; no full-bleed), all consuming `var(--brand-aurora-gradient)`, none in any Vuetify `theme.colors` slot:

- **Auth card (both SPAs)** — 3px aurora top-border on `.auth-layout__card` via a clipped `::before` (v-card's `overflow:hidden` clips it to the rounded top corners). The primary brand moment for unauthenticated users.
- **Onboarding app-bar** — 2px aurora bottom-edge line via `::after` (on a new `onboarding-topbar` class), sitting under the bar so it never fills it or clashes with the teal `mdi-lightning-bolt` wordmark icon.
- **Creator dashboard** — full-width 2px aurora bottom-border on `.creator-dashboard__header` (via `border-image`), reading as a deliberate header rule matching the other two edge-lines.
- `auth-layout-shape.spec.ts` `MAX_LINES` 96 → 115 (both SPAs, mirror-disciplined) with a Chunk-4 code-review note — the cap catches logic creep into the shell, not a 3px decorative accent (no `<script setup>` logic added; the no-function / no-multi-statement-arrow guards still hold).
- New `aurora-surfacing.spec.ts` (both SPAs): each brand surface references the var (accent can't silently regress) and uses the var, not a raw aurora hex.

---

## The plan reinterpretation (the chunk headline)

The original Sprint 3.5 plan named "auth hero strip + dashboard welcome bar." The read pass found **neither exists as named**: auth is a centered card with no hero region, and the agency `/` dashboard is a throwaway placeholder (`DashboardPlaceholderPage`, slated for Sprint 4 deletion). Per the decision-reinterpretation-at-plan-pause-time pattern, "dashboard welcome bar" was reinterpreted to **"thin aurora accents on persistent surfaces that exist today"** — preserving the structural intent (aurora reinforces brand identity) while adapting to verified reality. The brand moment landed on the **creator dashboard** (a real surface) instead of the placeholder. The agency shell + real dashboard get their aurora when Sprint 4 builds them.

---

## Eyes-on outcome (Workstream A)

The aurora treatments are aesthetic judgments verified collaboratively in the dev server, both modes:

- **Auth card top-border + onboarding app-bar line** — approved as built.
- **Creator dashboard accent** — shipped first as a contained 3px × 40px tick under the title; eyes-on, it read as a stray, purposeless line on the sparse dashboard surface. Per Pedram's call it was **unified to a full-width 2px header bottom-border** (Q-coherence resolved live in favour of the full-width edge-line, matching the auth/onboarding accents as one brand language). This is the eyes-on loop overriding the on-paper "keep the tick" choice — exactly its purpose.
- Coherence verdict: the three accents now read as one brand language (shared gradient, 2–3px weight, all full-width edge-lines on their respective surfaces).

---

## Q-answers (locked at plan approval)

| Q    | Decision                                                                                                 |
| ---- | -------------------------------------------------------------------------------------------------------- |
| Q-A1 | 3px clipped `::before` aurora top-border on the auth card, both SPAs.                                    |
| Q-A2 | Creator dashboard accent **included** (full-width header rule after eyes-on unify).                      |
| Q-A3 | **Defer** the D7-allowlist architecture test — 3 placements; eyes-on + `no-hard-coded-colors` enough.    |
| Q-B1 | **Leave + log** the pre-existing `on-info` sub-AA (~4.07:1) — legacy palette decision, out of scope.     |
| Q-B2 | **Sever in Chunk 4, delete the warm-`neutral` primitive in Chunk 5.**                                    |
| Q-C1 | Named **`catalyst.css`** (survives Laravel upgrades that touch `default.css`).                           |
| Q-C2 | Light surface; button + links **teal-700 `#0B6F66`**; headings zinc-900; body zinc-50; borders zinc-200. |

**Mail teal-700 vs SPA primary teal-500 (documented to prevent a future "fix"):** the mail theme is _always_ a light surface — the marginal-contrast regime — where white-on-teal-500 measures only ~2.49:1 (fails AA). teal-700 gives white text ~5.5:1 (AA-normal). The SPA keeps teal-500 because its default surface is **dark** (good contrast) and the light-mode primary is the documented contrast `it.todo`. Both surfaces correctly pick the teal step that hits AA for their background. Inline comment in `catalyst.css` warns against matching the mail button to the SPA primary. (Mail **links** also bumped to teal-700: teal-600 on white is ~4:1, below AA-normal for body-size text.)

---

## Honest deviations & notes

- **The published mail path was swallowed by the repo-root `vendor/` gitignore.** `vendor:publish` writes to `resources/views/vendor/mail`, and the monorepo root `.gitignore` has an unanchored `vendor/` rule (intended for Composer deps) that also matched this path — so the authored `catalyst.css` and the vendor blades would not have been committed. Caught via `git check-ignore` during the publish-output inspection. Fixed with a scoped re-include in `apps/api/.gitignore` (`!resources/views/vendor/` + `!resources/views/vendor/**`). This is the pause-condition-#4 spirit: the publish landed somewhere unexpected (ignored), even though the file _contents_ were the standard set.
- **`config/mail.php` `theme` placement.** The kickoff's shorthand `'theme' => 'catalyst'` resolves, in Laravel 11, to the `markdown.theme` key — which was entirely absent (not just unset). Added the full `markdown` block with the published-views path.
- **No dedicated `on-info` `it.todo`.** The kickoff referenced "the `on-info` `it.todo`"; in fact `vuetify.spec.ts`'s only `it.todo` is light `primary`/`on-primary` (2.49:1). The `on-info` pair is covered by the AA-Large accent-pair assertion (white-on-info ~4.07:1 ≥ 3.0). The stale `on-info` comment in `vuetify.ts` was corrected during the severance docblock rewrite.
- **Whole published vendor mail tree committed.** Both `catalyst.css` and the stock blades + `default.css` are committed (standard practice when publishing mail views): the `markdown.paths` config points component resolution at this directory, and committing the set pins it against framework drift.
- **`MAIL_MAILER` transport irrelevant to verification.** Config default is `log`; `phpunit.xml` overrides to `array`. The render-assert uses `$mail->render()` (no send), so the transport is moot.
- **Stray break-revert edit caught before final state.** During the Workstream-B severance break-revert, the `git checkout` restore of `vuetify.ts` did not take effect, leaving the (uncommitted) `neutral` re-import in the working tree. Caught on the post-Workstream-A `git status`; restored. Neither `cb0baea` nor `d2a2cfe` ever included it.

---

## Acceptance criteria

| #   | Criterion                                                                                                                                                                                   | Status                                                                                                             |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| A   | Aurora applied as thin accents to auth card (both SPAs) + onboarding app-bar + creator dashboard; consumes the var; eyes-on tasteful both modes; parity inv 3 green; NOT on the placeholder | ✅                                                                                                                 |
| B   | `vuetify.ts` no longer imports `neutral`; `on-info`/`on-success` stay pure white; `on-warning` → `zinc[950]`; WCAG re-run clean; severance documented (deletion deferred)                   | ✅                                                                                                                 |
| C   | Vendor mail theme published; `catalyst.css` authored (light surface, brand hex); config points at it; blades unchanged; mailable-renders-brand-hex verification passes                      | ✅                                                                                                                 |
| —   | All existing tests green                                                                                                                                                                    | ✅ (backend 871, main 619, admin 305, design-tokens 25 + 1 todo)                                                   |
| —   | 9 inheritance contracts respected                                                                                                                                                           | ✅ (see below)                                                                                                     |
| —   | Standing standards applied                                                                                                                                                                  | ✅ (5.1 source-inspection, 5.3 real-rendering mailable, 5.15 MAX_LINES allowlist note, #40 break-revert, #41 Pint) |

---

## Inheritance contracts — all respected

1. **Aurora utility-only** — now applied to component CSS via `var(--brand-aurora-gradient)`, still in NO `theme.colors` slot. Parity invariant 3 green. **(Workstream A target.)**
2. **Semantic-chip foregrounds** — warm-`neutral` dependency **severed** (the Workstream B target): `on-info`/`on-success` pure white literals (value-unchanged), `on-warning` → `zinc[950]`.
3. **`<meta theme-color>` + `<html data-theme>`** — untouched (`#09090B` / `dark` in both `index.html`).
4. **matchMedia one-way ratchet** — untouched.
5. **Defaults block is styling SOT** — honored; aurora is scoped-`<style>` `::before`/`::after`/`border-image`, no inline styles, no defaults-block change.
6. **`--catalyst-typography-*` canonical** — untouched.
7. **`--radius-*` scale** — untouched.
8. **`CEmptyState titleTag`** — untouched.
9. **4 container/variant tokens registered** — untouched; parity invariant 7 still pins all 8 values.

**5 stabilization patterns** (per-field 422, `useSubmitErrorKey`, `signedViewUrl`, `postLoginTarget`, `welcomeBackFlag`) — none touched by a brand chunk; the full backend suite (871) + `useSubmitErrorKey.spec` (main) confirm.

---

## Verification results

| Gate                                  | Result                                                                                                |
| ------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `packages/design-tokens` Vitest       | **25 / 25** (+1 todo) — was 22 (+3 severance regression-lock cases)                                   |
| `apps/main` Vitest                    | **619 / 619** (66 files) — +6 `aurora-surfacing` cases (3 surfaces × 2 assertions)                    |
| `apps/admin` Vitest                   | **305 / 305** (32 files) — was 303 (+2 `aurora-surfacing`)                                            |
| `apps/api` Pest                       | **871 / 871** (2804 assertions) — was 867 (+4 mail render-assert)                                     |
| `pnpm typecheck:frontend`             | 0 errors (all 5 workspaces)                                                                           |
| `pnpm lint:frontend`                  | 0 errors (2 pre-existing `v-html` warnings, unrelated)                                                |
| `pnpm --filter @catalyst/main build`  | clean                                                                                                 |
| `pnpm --filter @catalyst/admin build` | clean                                                                                                 |
| `./vendor/bin/pint --test`            | **passed** (authoritative, run with full permissions per #41)                                         |
| **Break-revert — severance**          | re-added `neutral` import → "does not import the warm `neutral` primitive" failed → restored          |
| **Break-revert — aurora-var**         | swapped one surface's var for `rgb(var(--v-theme-primary))` → "references var(...)" failed → restored |

> Backend Pest required `php -d memory_limit=-1 vendor/bin/pest` — `artisan test` re-execs a subprocess that ignores the `-d` flag, and the AWS SDK endpoint table exhausts the default 128M limit. Environment-only; unrelated to the changes.

---

## Files touched

**Design tokens (`packages/design-tokens`):**

- `src/vuetify.ts` — warm-`neutral` severance (`on-info`/`on-success` → `'#FFFFFF'`, `on-warning` → `zinc[950]`, `neutral` import dropped); docblock rewrite.
- `src/vuetify.spec.ts` — severance regression-lock describe block (3 cases) + `node:fs` source read.

**Backend (`apps/api`):**

- `config/mail.php` — `markdown` block (`theme => 'catalyst'`, published-views path).
- `resources/views/vendor/mail/**` — published Laravel mail views + authored `html/themes/catalyst.css` (brand light-surface theme).
- `tests/Feature/Mail/MailThemeBrandingTest.php` — new (4 mailables render-assert brand teal inlined, default gone).
- `.gitignore` — re-include `resources/views/vendor/` (un-swallow from the repo-root `vendor/` rule).

**SPAs:**

- `apps/main/src/modules/auth/layouts/AuthLayout.vue` + `apps/admin/.../AuthLayout.vue` — 3px aurora `::before` top-border on the card.
- `apps/main/src/modules/onboarding/layouts/OnboardingLayout.vue` — `onboarding-topbar` class + 2px aurora `::after` bottom-edge line.
- `apps/main/src/modules/creators/pages/CreatorDashboardPage.vue` — full-width 2px aurora header bottom-border (`border-image`).
- `apps/{main,admin}/tests/unit/architecture/auth-layout-shape.spec.ts` — `MAX_LINES` 96 → 115 + Chunk-4 note.
- `apps/{main,admin}/tests/unit/architecture/aurora-surfacing.spec.ts` — new source-inspection test.

---

## Follow-up items / what was deferred (with triggers)

- **Warm-`neutral` primitive deletion** — severance done this chunk; the TS primitive + dormant `--neutral-*` / `--color-*` CSS removal is **Chunk 5 cleanup** (Q-chunk-4-B2), with the tech-debt-entry closure.
- **Pre-existing `on-info` sub-AA (~4.07:1)** — left + logged (Q-chunk-4-B1); a semantic-palette decision predating the brand pivot, out of severance scope.
- **D7-aurora-location allowlist test** — deferred (Q-chunk-4-A3); revisit if aurora reaches 3+ files needing scatter-control.
- **Agency dashboard + welcome-bar aurora** — Sprint 4 (with the real dashboard).
- **`docs/01-UI-UX.md` v2 prose refresh** — Chunk 5 (unchanged).
- **`tokens.ts` aurora docblock** still names the original "auth hero strip / dashboard welcome bar" targets — refresh alongside the Chunk 5 docs pass.

### Standing-pattern candidates for the Chunk 5 self-review

The spot-check pass flagged two build-phase discipline patterns from this chunk as candidates for promotion to standing standards (`PROJECT-WORKFLOW.md` § 5) during the Chunk 5 mini-sprint self-review:

1. **Inspect `vendor:publish` / generated-file output for gitignore swallows.** The monorepo repo-root `vendor/` rule (Composer deps) matches `resources/views/vendor/` — a generated path can look done locally yet be absent on push. Any artisan publish (or other generator that writes under a broadly-ignored name) should have its output `git check-ignore`-verified before it's assumed committed.
2. **After any break-revert, confirm the restore via `git status` / `git diff` — don't assume `git checkout` took.** The stray-`neutral`-reimport near-miss this chunk shows a break-revert restore needs verification, not assumption; the regression-lock test was a backstop at the commit gate, but `git status` caught it earlier (defense-in-depth as designed).

---

## Cross-chunk note

The publish-side gitignore swallow (repo-root `vendor/` matching `resources/views/vendor/`) is a latent gotcha any future `vendor:publish` will hit; the scoped `apps/api/.gitignore` re-include resolves it for the mail path. No latent bugs in earlier chunks surfaced this round.

---

_Provenance: drafted by Cursor (Sprint 3.5 Chunk 4 build + eyes-on aurora loop with Pedram, 2026-05-31); merged + closed by Claude (independent spot-check pass, zero PMC corrections, 2026-05-31). Per-workstream commit split: `cb0baea` (B) + `fbc0874` (C) + `d2a2cfe` (A) + this docs commit. Closed per `PROJECT-WORKFLOW.md` § 3._
