# Sprint 3.5 — Chunk 2 Review

**Status:** Closed.

**Reviewer:** drafted by Cursor (implementation); reviewed + spot-checked by Claude (independent pass, 2026-05-31).

**Reviewed against:** `PROJECT-WORKFLOW.md` § 3 (chunk lifecycle) + § 5 standards (5.1 source-inspection tests, 5.15 allowlist discipline, #34 cross-chunk handoff, #40 break-revert defence-in-depth) + § 6 (sub-chunk planning), `02-CONVENTIONS.md` § 1 (shared-package extraction) + § 3 (frontend conventions, § 3.8 token-first styling), `docs/reviews/sprint-3-5-chunk-1-review.md` (the four inheritance contracts), the Sprint 3.5 Chunk 2 kickoff + the plan-approval message locking reinterpretations R1–R5.

This chunk is the **first consumption** of the Engine C v2 token layer Chunk 1 exposed: it reconciles the button-styling source of truth, tokenizes the (previously dormant) radius scale into the Vuetify defaults, wires the shared `@catalyst/ui` components to consume the `--catalyst-typography-*` CSS vars (which had zero consumers), lands a shared `CEmptyState` component, verifies the high-CSS onboarding cluster + `PortfolioGallery` render cleanly under the zinc dark theme, and extends the architecture-test surface with three new invariants.

---

## Scope — eight sub-steps, each independently green

1. **Defaults / CButton reconciliation (D-fork-a).** Removed `CButton`'s inline `style="border-radius:6px;text-transform:none"`. The Vuetify `defaults.VBtn` block is now the single styling SOT for the button radius + text-transform; `CButton` encodes variant semantics only (`primary`/`secondary`/`ghost`/`danger` → Vuetify variant + color). Added a co-located `CButton.spec.ts` (the package had no tests).
2. **Radius tokenization (R1 — consume the existing scale, don't re-author).** The kickoff's `--brand-radius-*` proposal was reinterpreted at plan-pause-time once the read pass found an existing, fully-dormant `radius` TS scale + `--radius-*` CSS vars. Added the one missing declaration (`--radius-none`) for TS↔CSS parity; wired `defaults.VBtn` to `border-radius: var(--radius-md)` in both SPAs; added a 6th parity invariant (radius TS↔CSS). No `--brand-radius-*` namespace introduced; `lg` stays 8px / `xl` stays 12px (codebase wins).
3. **Typography adoption in shared components (D-fork-b, R3/R5).** Five `@catalyst/ui` components (`CompletenessBar`, `CountryDisplay`, `SocialAccountList`, `LanguageList`, `PortfolioGallery`) migrated from hardcoded `font-size` rem literals to `var(--catalyst-typography-body-size)`. (`CategoryChips` dropped out — it has no font-size literal, R5.) One literal kept as an allowlisted one-off (`CountryDisplay` flag glyph, `1.125rem`). New `typography-consumption.spec.ts` (both SPAs) forbids un-allowlisted rem font-size literals in `packages/ui/src` and asserts real consumption.
4. **`CEmptyState` shared component (R4).** New slot+prop component (`title`/`body` props, `icon`/`action` slots — Q-chunk-2-3 slot-only icon, `dataTest` for anchoring). Migrated **5** call sites (3 in `AgencyUsersPage`, 2 in `BrandListPage` — the inventory had missed `brand-empty-filtered`), each preserving its `data-test` anchor. Co-located `CEmptyState.spec.ts`.
5. **Onboarding cluster + PortfolioGallery dark-mode verification (targeted).** Source-level verification that the high-CSS surfaces are fully theme-token-driven and re-theme to zinc. Findings logged for Chunk 3 (below). No Chunk-2 fixes needed.
6. **Form-error pattern pin (Q-chunk-2-4 = main-only allowlist).** New `form-error-pattern.spec.ts` (main SPA) asserting the 8 canonical 422-binding files keep their `extractFieldErrors` import. Intentionally NOT mirrored to admin (admin has zero consumers — a documented deferral; cross-referenced in the spec header).
7. **Status-chip consolidation — deferred (§ 1.7).** Tech-debt entry only; the five purpose-specific chips left as-is.
8. **Verification + tech-debt + this review.** Full suites both SPAs + design-tokens, typecheck, lint, dual builds; break-revert for all three new invariants; two new tech-debt entries.

---

## Reinterpretations locked at plan-pause-time (R1–R5)

Per the Sprint 3 cross-sprint pattern #12 (decision reinterpretation at plan-pause-time) — five this chunk, all adapting the kickoff to verified repo reality while preserving its structural intent:

- **R1 — radius.** Consume the existing dormant `radius`/`--radius-*` scale; don't author a parallel `--brand-radius-*` namespace. Mirrors Chunk 1's D6 ("expose/consume what exists"). `lg:8px` / `xl:12px` kept over the kickoff's literal `lg:12px`.
- **R2 — test location.** `CButton` + `CEmptyState` specs co-located in `apps/main/tests/unit/` (packages/ui has no Vitest harness). Documented in each spec header + a tech-debt entry.
- **R3 — off-scale literals.** The four off-scale literals (`0.9375rem` ×3, `0.95rem` ×1) migrated to `var(--catalyst-typography-body-size)`; the `1.125rem` flag glyph stays as an allowlisted literal. **See the honest-deviation note below on the px value.**
- **R4 — empty-state count.** 5 call sites, not 4 (`brand-empty-filtered` was missed in the inventory).
- **R5 — typography component count.** 5 components, not 6 (`CategoryChips` has no literal).

---

## Honest deviations & notes

- **`--catalyst-typography-body-size` resolves to 14px, not the 16px the R3 annotation predicted.** R3 instructed migrating the off-scale literals (15px / 15.2px) to `var(--catalyst-typography-body-size)`, annotated "(16px) / ~1px text growth." In this codebase the type scale is `body = 14px`, `body-lg = 16px` — so `--catalyst-typography-body-size` is **14px**. I implemented the **verbatim token name** the instruction specified (`body-size` = 14px), which means the dense-list surfaces move 15px → 14px (a ~1px _shrink_), not the ~1px _growth_ the annotation describes. I honored the explicit, actionable token name rather than silently substituting `--catalyst-typography-body-lg-size` based on the annotation, since substituting a different token on inference would be exactly the kind of silent override the project guards against. **Decision for the spot-check:** if 16px / growth was the true intent, the one-line fix is to switch the four declarations to `var(--catalyst-typography-body-lg-size)`. Otherwise all migrated list/label surfaces are now a uniform 14px `body`, which reads consistently. Flagging explicitly so the reviewer can pick.
  - **R3 resolution:** 14px / `body-size` is the kept outcome. The R3 instruction's "16px" annotation was based on a Vuetify/SaaS convention assumption (`body = 16px`) that doesn't match this codebase's 12-step scale (`body = 14px`, `body-lg = 16px`). Reviewer confirmed 14px is the right outcome — uniform `body` reads consistently, and the scale gap at 15px is preserved as a known characteristic of the chosen scale rather than allowlisted around. Chunk 3 (regression sweep) verifies dense list surfaces don't feel cramped at 14px; if they do, migration is to `body-lg` (16px) uniformly, not to scatter allowlisted exceptions.
- **`CEmptyState` title is an `<h3>` (per the approved kickoff template).** On `BrandListPage` the original empty-state title was an `<h2 class="text-h6">` under the page `<h1>`; the new component renders `<h3>`, so that surface now skips a heading level (h1→h3). Minor a11y nicety; logged for Chunk 3 rather than diverging from the approved API this chunk.
- **`var(--radius-md)` in the Vuetify `defaults.VBtn` inline style.** The defaults `style` string is applied inline on each `<v-btn>` root; it resolves the CSS var from `:root` (tokens.css is imported in both `main.ts`). Confirmed by the clean dual production build. No `6px` literal remains in the defaults.
- **No package-level test harness in `packages/ui`.** `CButton` had _no_ existing tests (the kickoff's "existing CButton tests still pass" assumption was inaccurate). Both shared-component specs are co-located in the SPA suite; tracked as tech-debt.

---

## Acceptance criteria

| #   | Criterion                                                                                             | Status                                                                                                            |
| --- | ----------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| 1   | CButton no longer carries inline radius/text-transform style; defaults block covers it                | ✅ inline style removed; `CButton.spec.ts` asserts no inline `border-radius`/`text-transform`                     |
| 2   | Radius tokens landed; Vuetify defaults consume the radius token                                       | ✅ `--radius-none` added for parity; `defaults.VBtn` → `var(--radius-md)` (both SPAs); no `--brand-radius-*` (R1) |
| 3   | Typography adoption: shared components consume `var(--catalyst-typography-*)` instead of rem literals | ✅ 5 components migrated; only the allowlisted flag glyph remains                                                 |
| 4   | `CEmptyState` shipped; call-site migrations preserve `data-test` anchors                              | ✅ new component + spec; 5 migrations; anchors preserved (full suites green)                                      |
| 5   | Onboarding cluster + PortfolioGallery verified                                                        | ✅ source-level token-flow verification; findings logged for Chunk 3                                              |
| 6   | Form-error pattern pinned; allowlist documented                                                       | ✅ `form-error-pattern.spec.ts` (main), 8-file allowlist, admin deferral cross-referenced                         |
| 7   | Architecture tests extended                                                                           | ✅ radius-parity (in parity spec), typography-consumption (new), form-error-pattern (new)                         |
| 8   | All existing tests green                                                                              | ✅ main 598, admin 295, design-tokens 22 (+1 todo)                                                                |
| 9   | Four Chunk 1 inheritance contracts respected                                                          | ✅ see below                                                                                                      |

---

## Inheritance contracts (Chunk 1) — all respected

- **Aurora utility-only** — untouched; parity invariant 3 still passes (no theme.colors edits this chunk).
- **Semantic-chip foregrounds on warm `neutral`** — untouched in `vuetify.ts`.
- **`<meta theme-color>` + `<html data-theme>`** — untouched in both `index.html`.
- **`matchMedia` one-way ratchet** — untouched in `use-theme-is-sot.spec.ts`.

Five stabilization patterns: per-field 422 is now actively _pinned_ (sub-step 6); `useSubmitErrorKey`, `signedViewUrl`, `postLoginTarget`, `welcomeBackFlag` untouched.

---

## Verification results

| Gate                                      | Result                                                                                                               |
| ----------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| `apps/main` Vitest                        | **598 / 598** (65 files) — was 563 at entry (+35)                                                                    |
| `apps/admin` Vitest                       | **295 / 295** (31 files) — was 286 at entry (+9)                                                                     |
| `packages/design-tokens` Vitest           | 22 pass / 1 todo (unchanged — radius parity asserted in the SPA parity specs per Q-chunk-2-1 hybrid)                 |
| `pnpm typecheck:frontend`                 | 0 errors (all 5 workspaces) — caught + fixed a `noUncheckedIndexedAccess` issue in the typography spec before commit |
| `pnpm lint:frontend`                      | 0 errors (2 pre-existing `v-html` warnings, unrelated)                                                               |
| `pnpm --filter @catalyst/main build`      | clean (5.57s)                                                                                                        |
| `pnpm --filter @catalyst/admin build`     | clean (3.70s)                                                                                                        |
| **Break-revert — radius-parity**          | renamed `--radius-md` → `--radius-mdx` → the `--radius-md` case failed → reverted                                    |
| **Break-revert — typography-consumption** | re-introduced `font-size: 0.875rem` in `CompletenessBar` → failed → reverted                                         |
| **Break-revert — form-error-pattern**     | dropped `extractFieldErrors` from `SignUpPage`'s import → its case failed → reverted                                 |

Test-count delta: main +35 (CButton 9 + CEmptyState 9 + radius-parity 7 + typography-consumption 2 + form-error 8), admin +9 (radius-parity 7 + typography-consumption 2). Within the approved ~15–22 net + 3 invariants estimate once the two co-located component specs (18 cases) are counted — those weren't in the original estimate because the package was assumed to already have tests.

---

## Files touched

**Design tokens (`packages/design-tokens`):**

- `tokens.css` — added `--radius-none` + an R1 rationale comment on the radius block.

**Shared UI (`packages/ui`):**

- `src/components/CButton.vue` — removed inline style; D-fork-a docblock.
- `src/components/CEmptyState.vue` — **new** slot+prop empty-state scaffold.
- `src/components/{CompletenessBar,CountryDisplay,SocialAccountList,LanguageList,PortfolioGallery}.vue` — font-size literals → `var(--catalyst-typography-body-size)`; flag-glyph allowlist comment in `CountryDisplay`.
- `src/index.ts` — export `CEmptyState`.

**SPAs:**

- `apps/{main,admin}/src/plugins/vuetify.ts` — `defaults.VBtn` style → `var(--radius-md)`.
- `apps/main/src/modules/agency-users/pages/AgencyUsersPage.vue` — 3 empty states → `CEmptyState`.
- `apps/main/src/modules/brands/pages/BrandListPage.vue` — 2 empty states → `CEmptyState`.
- `apps/{main,admin}/tests/unit/architecture/color-system-parity.spec.ts` — radius-parity invariant (6th).
- `apps/{main,admin}/tests/unit/architecture/typography-consumption.spec.ts` — **new** (mirrored).
- `apps/main/tests/unit/architecture/form-error-pattern.spec.ts` — **new** (main-only).
- `apps/main/tests/unit/components/{CButton,CEmptyState}.spec.ts` — **new** (co-located).

**Docs:**

- `docs/tech-debt.md` — two new entries (status-chip consolidation deferral; packages/ui test harness).
- `docs/reviews/sprint-3-5-chunk-2-review.md` — this file.

---

## Decisions documented for future chunks

- **Defaults block is the styling SOT for Vuetify primitives.** Component wrappers (`CButton`) encode variant semantics only. Any future wrapper follows the same split.
- **`--catalyst-typography-*` is the canonical typography path for shared components + new code.** Per-SPA code may continue using Vuetify utility classes; new shared code consumes the vars (enforced by `typography-consumption.spec.ts`).
- **The radius scale is `--radius-*` (no brand prefix).** `var(--radius-md)` is the control radius; the TS↔CSS parity is pinned.
- **`CEmptyState` is the empty-state scaffold.** New empty states use it; preserve `data-test` anchors on migration.

---

## Follow-up items / what was deferred (with triggers)

- **Onboarding/admin "-container" / "-variant" Material tokens** (e.g. `--v-theme-primary-container`, `--v-theme-outline-variant`, `--v-theme-error-container`) are referenced with CSS fallbacks but not explicitly registered in our `theme.colors`. Vuetify auto-derivation + the fallbacks cover them; **Chunk 3** may want to register explicit values for precise dark-mode control. Not a regression.
- **`CEmptyState` h1→h3 heading skip on `BrandListPage`** — minor a11y polish for **Chunk 3**.
- **Body-size vs body-lg for the migrated list surfaces** — resolved at spot-check in favour of 14px / `body-size` (see the R3 resolution above). Chunk 3's regression sweep re-checks the dense-list surfaces; uniform `body-lg` is the escape hatch if they read cramped.
- **Status-chip consolidation** — tech-debt (triggered by a 6th chip or a library consolidation pass).
- **`packages/ui` test harness** — tech-debt (triggered by the next chunk adding 2+ shared components).

---

## Spot-checks performed

- **R3 (the headline deviation)** — resolved in favour of keep-as-shipped (14px / `body-size`); see the R3 resolution under "Honest deviations." No reversal.
- **`h1→h3` heading skip + the "-container"/"-variant" Material-token observation** — both confirmed as legitimate Chunk-3 follow-ups (not Chunk-2 regressions).
- **Round-trip count** — target hit at 2 (plan approval + this spot-check pass).

## Cross-chunk note

None this round — no latent bugs from earlier chunks surfaced. The Chunk 1 token layer consumed cleanly; the only "surprise" was the two dormant token sets (radius + typography) which Chunk 2 was scoped to begin consuming.

## Discipline observations

- **Literal-implementation discipline surfaced a reviewer-spec error — second instance in Sprint 3.5** (after Chunk 1's Inter drift). The pattern: implementing exactly what's specified means specification errors surface as observable artifacts (here: the R3 "16px" annotation contradicting this codebase's `body = 14px` scale) rather than being silently corrected away. Load-tested twice this mini-sprint (planning-phase R1–R5 reinterpretations + this build-time R3 surfacing). Worth elevating to a Sprint 3.5 § b standing pattern when the self-review consolidates standards.

---

_Provenance: drafted by Cursor (Sprint 3.5 Chunk 2 build pass, 2026-05-31). Awaiting Claude's independent review + spot-check pass; merged version to follow per `PROJECT-WORKFLOW.md` § 3 steps 8–9._
