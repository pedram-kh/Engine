# Sprint 3.5 — Chunk 5 Review

**Status:** Closed. (This review also serves as the **Sprint 3.5 close marker** — the design-v2 mini-sprint is done.)

**Reviewer:** drafted by Cursor (implementation: W1 deletion + W4 standards + W2 non-§2 touch-ups + placement of the Claude-authored W2 §2 + W3 self-review). Awaiting Claude's independent spot-check pass.

**Reviewed against:** `PROJECT-WORKFLOW.md` § 3 (chunk lifecycle) + § 5 standards (5.1 source-inspection, 5.8 reasoned removal of dead code, #5.32 decision-reinterpretation, #5.35 break-revert claim verification incl. the restore-via-`git`-status corollary) + § 6 (sub-chunk planning) + § 8 (tech-debt append-don't-delete), the four closed Sprint 3.5 chunk reviews (1–4), `docs/reviews/sprint-3-self-review.md` §b (the 16 patterns W4.1 migrates), the Chunk 5 kickoff + the plan-approval/text-handoff message (the two confirmations + the two Claude-authored texts).

This is the **mini-sprint wrap-up**: no new feature work, no aesthetic judgment. Four loosely-coupled workstreams — delete the dead warm-neutral layer (W1), rewrite the stale design-system doc for v2 (W2), place the Sprint 3.5 self-review (W3), consolidate the standing-standards backlog (W4). Authorship was split: Cursor authored W1/W4/W2-touch-ups; Claude drafted W2 §2 + W3, Cursor placed them and wired the surrounding mechanical edits.

---

## Scope — four workstreams

### W1 — Warm-neutral primitive + dormant CSS deletion

The cleanup Chunk 4 teed up (it severed the last runtime consumer). Surgical, not wholesale:

- **`tokens.ts`** — deleted the warm `neutral` const + the `NeutralTokens` type alias. Rewrote the `zinc` docblock to drop the false "warm `neutral` preserved as an importable primitive / `brand.cream`+`brand.ink` reference its tonal world" lineage prose (corrected: `brand.cream`/`brand.ink` are standalone logo-derived hex literals that never referenced the `neutral` scale). Corrected the aurora docblock's "auth hero strip / dashboard welcome bar" targets → the as-shipped surfaces (auth card both SPAs / onboarding app-bar / creator dashboard header).
- **`tokens.css`** — deleted the `--neutral-*` block + both `:root[data-theme='light'|'dark']` `--color-*` blocks + the chunk-8.2 `@media`-removal note (which only documented the now-deleted block). Rewrote the file header comment. **Kept:** `--brand-*` (incl. `--brand-aurora-*`, the live 4-surface consumer; `--brand-cream`/`--brand-ink`), `--radius-*`, `--space-*`, `--font-*`/`--brand-font-primary`, `--catalyst-typography-*`. File NOT deleted; both SPAs' `main.ts` imports untouched.
- **`packages/design-tokens/README.md`** — a **third stale `--color-*` consumption-guidance landmine** found during the deletion audit (it documented `--color-*` as the consumption path and described `tokens.css` as exporting `--color-*`). Corrected to the Vuetify-theme-layer path + the surviving token set, matching §2.7.

### W2 — `01-UI-UX.md` v2 rewrite (section-surgical) + the landmine reconciliation

- **§2 Color system** — replaced wholesale with the Claude-authored §2.1–§2.7 text (zinc neutral foundation; teal co-brand preserved; aurora utility-only + D7 + the three surfaced locations; single-value semantics; the four container/variant tokens; binary dark-default theme model; and §2.7 "How to consume color" — the correct Vuetify-theme-layer path).
- **§1** — light touch: the v1 "warm dark surfaces / cream foreground" framing reconciled to zinc + aurora co-brand; the "Warm, not corporate" bullet rephrased.
- **§3 Typography** — corrected the self-host path (`public/fonts/` → the shared `packages/ui/assets/fonts/`), the typeface description ("variable font" → as-shipped static weights 400/500/600/700 normal+italic), and the `font-feature-settings` claim (verified against `inter.css`: **not currently applied** — documented honestly as an optional future refinement rather than a shipped fact).
- **§§4–10, 13** — token-name touch-ups only: the scattered `--color-*` references in the component-pattern prose (§5 tables/cards/sidebar, §8 empty-state) corrected to the `--v-theme-*` equivalents (borders → `outline`/`outline-variant`; surfaces → `surface`; secondary text → `on-surface-variant`; selected-row tint → the registered `primary-container` token; focus → `primary`). §10's "system fallback" theme line corrected to binary dark-default.
- **§11 "Workspace home"** — preserved verbatim (forward-looking layout spec, no color literals; the Sprint-4 dashboard reference).
- **§14 Quick-reference card** — the v1 brand-hex line + the "system-default" theme implication replaced with the v2 quick-ref (zinc, teal co-brand, aurora utility-only, the consumption path, static Inter, dark-default binary themes).

### W2.3 — the consumption-guidance landmine (the acceptance-bar item)

The deletion turned every "use `var(--color-*)`" instruction into stale guidance pointing at deleted vars. Reconciled across **three** locations, all pointed at exactly what §2.7 says (Vuetify `color=` props + `rgb(var(--v-theme-*))`; aurora via `var(--brand-aurora-gradient)`; never `--color-*`/`--neutral-*`):

1. **`01-UI-UX.md` §2.7** — the canonical statement (Claude text).
2. **`01-UI-UX.md` §12** Dos/Don'ts — the "Do" color bullet rewritten to match §2.7.
3. **`02-CONVENTIONS.md` §3.8** — the SFC `<style>` example (`border: 1px solid var(--color-border-subtle)` / `border-color: var(--color-action-danger)`) → `rgb(var(--v-theme-outline-variant))` / `rgb(var(--v-theme-error))` + `border-radius: var(--radius-lg)`. Per Confirmation 1: folded into W2, not logged as tech-debt (two-line fix; logging known-stale guidance would be debt for no reason).

A post-edit grep confirms the only remaining `--color-*` mentions in `01-UI-UX.md` are the §2.7/§12 forbidding-references; `02-CONVENTIONS.md` has zero `var(--color-*)`/`var(--neutral-*)`. The README is the same class, fixed under W1. **Landmine closed: all consumption-guidance locations agree.**

### W3 — Sprint 3.5 self-review

Placed the Claude-authored self-review at `docs/reviews/sprint-3-5-self-review.md`. Cross-references confirmed resolve: the four closed chunk reviews exist; the two harness tech-debt entries (`packages/ui` no harness; harness-renders-stock-theme) exist and are re-affirmed open by W4.3; the §b standing-pattern candidates map to the new §5.21–5.36; the separate wizard-fix commit `5cef61f` matches the Chunk 3 record; §3's latent-bug #4 (stale `--color-*` guidance in two docs) matches the landmine reconciled in W2.3.

### W4 — Standing-standards consolidation

- **W4.1** — appended Sprint 3 self-review §b's **16 patterns → `PROJECT-WORKFLOW.md` §5.21–§5.36**, reformatted to the §5 convention (bold title + "**Established:**" line + body). Dedup pass (pause-condition #4): all 16 are net-new relative to #5.1–#5.20 — no true duplicates. #5.25 (backend/frontend constant parity) cross-references §5.1; #5.34 (negative-case assertions) + #5.35 (break-revert claim verification) cross-reference §5.17. Sprint 2 §b was **not** re-migrated (confirmed already present as #5.11–#5.17, its 7 patterns; #5.18–#5.20 are Sprint 1 chunk-7.1/8 origin — CI-authoritative Pint, user-enumeration for preview/status endpoints, read-prior-review-before-merge).
- **W4.2** — corrected + closed the "Standards migration backlog" tech-debt entry. Its claim that Sprint 2 §b was un-migrated was **stale** (Sprint 2 §b is #5.11–#5.17, its 7 patterns; #5.18–#5.20 originated from the Sprint 1 chunk-7.1/8 reviews, not Sprint 2 §b). The legacy parallel numbering was verified to map cleanly before collapsing: **#34→§5.11**, **#40→§5.17** (its "break-revert" connotation captured separately as **§5.35**), **#41→§5.18**, **#42→§5.19** — all four have clean §5.x equivalents; none lacked one. Entry closed.
- **W4.3** — **closed** (strikethrough-Resolved per §8): warm-gray `neutral` deprecation (W1), dormant `--color-*` zinc (W1), `01-UI-UX.md` staleness (W2), and the parent `tokens.css`-dormant entry (W1 — narrowed to "only `--brand-*`/aurora + radius/space/font/typography remain; the parallel CSS-variable-system question is resolved"). With W4.2 that is **5 entries closed**. **Re-affirmed OPEN** (no false closure): `packages/ui` has no test harness, and harness-renders-stock-theme — both noted as infrastructure work Sprint 3.5 deferred, with the eyes-on sweep documented as continuing mitigation.

---

## The deletion-safety audit (W1, re-confirmed before deleting)

Per kickoff pause-conditions #1/#2, the consumer hunt was re-run, not assumed:

- **`neutral` const / `NeutralTokens` type:** grep `\bneutral\b|NeutralTokens` across `packages/**/*.ts` → **zero runtime imports**. Every hit is the definition itself, a comment/docblock (`vuetify.ts`, `semantic.ts`), or a describe-string/regex-literal (`tokens.spec.ts`, `vuetify.spec.ts`). Safe to delete.
- **`--color-*` / `--neutral-*`:** repo-wide grep for `var(--color-` / `var(--neutral-` → only `tokens.css` itself + three docs (now all corrected). Zero `.vue`/`.ts`/SPA-source consumers. Safe.
- **Severance test (`vuetify.spec.ts`):** asserts `vuetify.ts` does not import `neutral` (still true post-deletion) + pins the `on-*` literals. Imports only `{ zinc }`. Stays green.
- **`NEUTRAL_SLOTS`:** `['background','surface','on-surface','border-color']` — Vuetify slot names keyed off `lightTheme.colors[slot]`/`darkTheme.colors[slot]`, unrelated to the warm-gray scale. Unaffected.

**Break-revert (W1.3):** mutated dark `background` to equal light's → the `color-system-parity` "split neutrals" case failed (`expected '#FAFAFA' not to be '#FAFAFA'`) → reverted. Confirms the parity test keys off theme slot values, not the deleted primitive, and still bites after the deletion. Per the #5.35 corollary, the restore was verified via `git diff packages/design-tokens/src/vuetify.ts` (empty — clean restore).

---

## Acceptance criteria

| #   | Criterion                                                                                                                                                                                                                                                                                         | Status                                                                                                     |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- |
| W1  | `neutral` const + `NeutralTokens` deleted; `--neutral-*` + `--color-*` CSS deleted; brand/aurora/radius/space/typography + main.ts imports intact; suites + builds green; severance + NEUTRAL_SLOTS unaffected (break-revert confirmed)                                                           | ✅                                                                                                         |
| W2  | §2 rewritten (zinc + teal co-brand + aurora + dark-default + correct consumption path); theme-model framing corrected (§1/§2.6/§3/§10/§14); the `--color-*` landmine reconciled across §2.7 + §12 + 02-CONVENTIONS §3.8 (+ README); agnostic sections touched only for token-names; §11 preserved | ✅                                                                                                         |
| W3  | Self-review placed at `docs/reviews/sprint-3-5-self-review.md`; cross-references resolve                                                                                                                                                                                                          | ✅                                                                                                         |
| W4  | Sprint 3 §b → §5.21–5.36 (16, dedup-checked); standards-backlog entry corrected + closed; 5 entries closed total, 2 re-affirmed open                                                                                                                                                              | ✅                                                                                                         |
| —   | All existing tests green                                                                                                                                                                                                                                                                          | ✅ (backend Pest 871 unchanged — no backend files touched; main 619; admin 305; design-tokens 25 + 1 todo) |
| —   | 9 inheritance contracts intact (W1 removes only dead code)                                                                                                                                                                                                                                        | ✅ (aurora untouched; container tokens untouched; severance lock green; see below)                         |

---

## Inheritance contracts — all 9 intact

W1 removed only dead code; W2/W3/W4 are docs. None of the 9 contracts from Chunks 1–4 changed:

1. **Aurora utility-only** — `--brand-aurora-*` kept in `tokens.css`; parity invariant 3 green; aurora's 4 surfaces still render (builds clean).
2. **Semantic-chip foregrounds severed onto literals** — `vuetify.ts` untouched; severance test green.
3. **`<meta theme-color>` + `<html data-theme>`** — untouched.
4. **matchMedia one-way ratchet** — untouched.
5. **Defaults block is styling SOT** — untouched.
6. **`--catalyst-typography-*` canonical** — kept in `tokens.css`; untouched.
7. **`--radius-*` scale** — kept in `tokens.css`; untouched.
8. **`CEmptyState titleTag`** — untouched.
9. **4 container/variant tokens registered** — `vuetify.ts` untouched; parity invariant 7 still pins all 8 values (break-revert confirmed bites).

5 stabilization patterns — untouched by a docs/deletion chunk (no backend, no store, no form-state changes).

---

## Verification results

| Gate                                    | Result                                                                                                                |
| --------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| `packages/design-tokens` Vitest         | **25 / 25** (+1 todo) — unchanged                                                                                     |
| `apps/main` Vitest                      | **619 / 619** (66 files) — unchanged                                                                                  |
| `apps/admin` Vitest                     | **305 / 305** (32 files) — unchanged                                                                                  |
| `apps/api` Pest                         | **871** — not re-run; **zero backend files touched** this chunk                                                       |
| `pnpm typecheck:frontend`               | 0 errors (all 5 workspaces)                                                                                           |
| `pnpm lint:frontend`                    | 0 errors (2 pre-existing `v-html` warnings, unrelated)                                                                |
| `pnpm --filter @catalyst/main build`    | clean (4.34s) — `tokens.css` deletion bundles fine                                                                    |
| `pnpm --filter @catalyst/admin build`   | clean (3.03s)                                                                                                         |
| **Break-revert — NEUTRAL_SLOTS parity** | dark `background` = light → "split neutrals > background" failed → reverted → restore verified via `git diff` (empty) |

---

## Files touched

**Design tokens (`packages/design-tokens`):**

- `src/tokens.ts` — deleted `neutral` const + `NeutralTokens` type; zinc-docblock lineage prose corrected; aurora-docblock targets corrected to as-shipped surfaces.
- `tokens.css` — deleted `--neutral-*` + both `--color-*` theme blocks + the chunk-8.2 `@media` note; file-header comment rewritten. Brand/aurora/radius/space/font/typography kept.
- `README.md` — `--color-*` consumption-guidance landmine corrected to the Vuetify-theme-layer path (W1 audit dividend).

**Docs:**

- `01-UI-UX.md` — §2 wholesale rewrite (Claude §2.1–2.7) + §1/§3/§§4–10/§12/§14 touch-ups; §11 preserved.
- `02-CONVENTIONS.md` — §3.8 SFC `<style>` example reconciled to `--v-theme-*` (Confirmation 1).
- `PROJECT-WORKFLOW.md` — §5.21–§5.36 appended (Sprint 3 §b's 16 patterns).
- `tech-debt.md` — 5 entries closed (warm-gray, dormant `--color-*`, 01-UI-UX staleness, parent tokens.css-dormant, standards-migration backlog); 2 re-affirmed open with eyes-on mitigation note.
- `reviews/sprint-3-5-self-review.md` — **new** (Claude-authored, placed).
- `reviews/sprint-3-5-chunk-5-review.md` — this file.

---

## Honest deviations & notes

- **A third landmine found during the deletion audit.** Beyond the two the kickoff/Confirmation-1 named (§12, 02-CONVENTIONS §3.8), the `packages/design-tokens/README.md` documented `--color-*` as both the export set and the consumption path. Same class; fixed under W1 (it directly describes the edited file). This is the systematic-deletion-audit dividend — the same phenomenon as the `--v-theme-outline` finding (Chunk 3) and the `vendor:publish` gitignore swallow (Chunk 4): a systematic pass over a surface finds what ad-hoc work missed. The self-review's §3 lists the two-doc consumption-guidance landmine as latent bug #4; the README is a third instance of the same root cause.
- **`font-feature-settings` was never shipped.** The v1 §3 claimed `"cv02","cv03","cv04","cv11"` were enabled; `inter.css` enables no stylistic sets and `--brand-font-primary` is just the family stack. Rather than carry a false claim, §3 now documents it as not-applied + an optional future refinement (literal-implementation-surfaces-spec-errors, applied to the doc itself).
- **Backend Pest not re-run.** Zero backend files were touched (the only backend-adjacent edit is the `02-CONVENTIONS.md` doc). The 871 count carries from Chunk 4 unchanged; flagged explicitly per the asymmetric-coverage-acknowledgement discipline (#5.36) rather than implying a fresh run.
- **Legacy-numbering map verified, not forced (W4.2).** All four legacy numbers (#34/#40/#41/#42) had clean §5.x equivalents; none was orphaned. The "#40 break-revert" connotation used in the chunk reviews is the defense-in-depth standard §5.17, with the break-revert-as-verification technique now its own standard §5.35 — noted in the corrected entry so the two senses don't get conflated again.

---

## Proposed commit shape (for the merge step)

Multi-commit split across the disjoint surfaces (not yet committed — draft stage):

1. `refactor(design-tokens): delete dead warm-neutral primitive + dormant --color-*/--neutral- CSS (Sprint 3.5 Chunk 5 W1)` — `tokens.ts` + `tokens.css` + `README.md`.
2. `docs(workflow): migrate Sprint 3 §b standing standards to §5.21-5.36 + close standards-migration backlog (W4)` — `PROJECT-WORKFLOW.md` + the `tech-debt.md` standards-backlog entry.
3. `docs(ui-ux): rewrite 01-UI-UX §2 for Engine C v2 + reconcile the --color-* consumption landmine (W2)` — `01-UI-UX.md` + `02-CONVENTIONS.md` + the W1/W2 tech-debt closures.
4. `docs(reviews): add Sprint 3.5 self-review + close chunk 5 (W3 + this review)` — `sprint-3-5-self-review.md` + this file.

(Or grouped if the reviewer prefers fewer commits — the surfaces are disjoint either way.)

---

## Cross-chunk note

The deletion audit surfaced the README `--color-*` landmine (a third instance of the stale-consumption-guidance class). No latent _runtime_ bugs surfaced — the warm-neutral severance (Chunk 4) had already removed the last live consumer, so Chunk 5's deletion was pure dead-code removal. Sprint 4 inherits a clean token layer (zinc + aurora + radius/space/font/typography), an authoritative `PROJECT-WORKFLOW.md` §5 through Sprint 3, and a v2-accurate `01-UI-UX.md`.

**Round-trip:** this chunk's exchanges were the plan-approval + the text-handoff (W2 §2 + W3); next is the spot-check. The text handoff was an extra exchange, not a correction round.

---

_Provenance: drafted by Cursor (Sprint 3.5 Chunk 5 build pass, 2026-05-31) — W1 deletion + W4 standards + W2 non-§2 touch-ups authored by Cursor; W2 §2 + W3 self-review authored by Claude and placed by Cursor. Awaiting Claude's independent spot-check pass; merged version to follow per `PROJECT-WORKFLOW.md` § 3 steps 8–9. **This review is the Sprint 3.5 close marker.**_
