# Sprint 3.5 — Chunk 1 Review

**Status:** Closed. No change-requests; spot-check approved post-revert (Inter static weights 400/500/600/700, normal + italic). The work is mergeable as-is.

**Reviewer:** Claude (independent review) — design-v2 mini-sprint, Chunk 1 of the Engine C v2 visual-layer refresh.

**Commits (standard two-commit shape):**

- **work commit** (subject: `feat(design-tokens): land Engine C v2 brand layer — aurora accent, zinc neutrals, Inter, binary dark-first theme`).
- **docs commit** (subject: `docs(reviews): close sprint 3.5 chunk 1 + log v2 brand-layer tech-debt`).

**Reviewed against:** `PROJECT-WORKFLOW.md` § 3 (chunk lifecycle) + § 5 standards (#5.x token discipline, #34 architecture tests, mirror-discipline D2, audit-first D5) + § 6 (sub-chunk planning), `02-CONVENTIONS.md` § 1 (modular monolith, shared-package extraction), `01-UI-UX.md` §2-§3 (the v1 design system this chunk evolves — now stale, deferred to Chunk 5), Sprint 1 chunk 8.1 review (Vuetify-standard theme keys), chunk 8.2 review (tri-state preference + SOT architecture test), chunk 7.1 hotfix #3 (leaf-only `data-test`), chunk 7.2 D2 (per-SPA mirror discipline).

This chunk lands the **co-brand** Engine C v2 visual layer in code: the aurora accent (utility-only), the zinc true-neutral surface scale, self-hosted Inter, and a binary dark-first theme toggle. It is deliberately code-first — the `docs/01-UI-UX.md` prose refresh is deferred to Chunk 5 so the docs describe the _settled_ v2 system rather than chasing in-flight chunks.

---

## Scope

Eight sub-steps, each independently green:

1. **Aurora + zinc primitives** (`packages/design-tokens/src/tokens.ts`). Added `brand.aurora` (`start #CD69FF` / `mid #7FC3FF` / `end #00FFF2` / `gradient`) and a new top-level `zinc` 12-stop scale (Tailwind hexes, 50→950). Existing `brand.gradient` (teal→violet) and the warm `neutral` scale preserved. New `tokens.spec.ts` pins the hexes + monotonic-darkening of zinc.
2. **semantic.ts zinc migration + Vuetify themes.** `semanticLight` / `semanticDark` neutral surface/border/text roles migrated from warm `neutral` to `zinc` (D4 dark + D5 light). `action.primary` intentionally stays on `brand.teal` (co-brand, not a primary pivot — D3 reinterpreted). Vuetify `lightTheme` / `darkTheme` continue to consume `semantic.*`; **theme keys kept as `light` / `dark` (R1)**.
3. **Drop `'system'` + binary toggle + dark default + i18n.** `useThemePreference` (both SPAs) rewritten to a binary `light` / `dark` model with a passive-on-read migration of the legacy `'system'` value; `matchMedia(prefers-color-scheme)` machinery removed. `ThemeToggle.vue` (both SPAs) drops the system button. Main SPA `defaultTheme` + `SPA_DEFAULT` flipped to `dark` (admin already dark). `app.theme.toggle.system` i18n key removed from all six locale bundles. `<html data-theme="dark">` + `<meta name="theme-color" content="#09090B">` in both `index.html`. SOT architecture test: matchMedia ratchet kept (now allowlists no file), composable allowlist comment shrunk.
4. **Inter self-hosting** (`packages/ui/assets/fonts/`). Self-hosted **static weights 400/500/600/700, normal + italic** (latin subset) — `inter-{400,500,600,700}.woff2` + `inter-{400,500,600,700}-italic.woff2` (eight files), `inter.css` (eight discrete-weight `@font-face` blocks + `.v-application { font-family: var(--brand-font-primary) }` cascade override), `LICENSE-INTER.txt` (SIL OFL 1.1). Italic ships because the creator-bio renderer (`useBioRenderer.ts`) emits `<em>` / `<strong><em>` from the bio Markdown subset. `@catalyst/ui` `exports` + `files` updated; both SPAs `main.ts` import the shared CSS. (Static weights honor the locked Q-chunk-1-3 = (b); an interim build-pass shipped the variable font and was reverted before commit — see the PMC deviation note below.)
5. **Aurora + typography + font CSS vars** (`packages/design-tokens/tokens.css`). `--brand-aurora-*` + `--brand-aurora-gradient`; `--brand-font-primary` (Inter stack); the existing 12-step type scale exposed as `--catalyst-typography-{step}-{size,weight,line-height}` CSS vars (D6 reinterpreted: expose existing scale, don't re-author).
6. **Color-system parity architecture test** (both SPAs — the two new architecture-test files). `color-system-parity.spec.ts` pins five invariants: single-value semantics (D1/D2), split neutrals (D4/D5), aurora utility-only (D7, not in any Vuetify `theme.colors`), aurora authored (TS primitive + CSS declaration), typography parity (every TS step has CSS vars + `--brand-font-primary` references Inter). References the actual `lightTheme` / `darkTheme` exports (R1).
7. **Verification** (below) — WCAG re-run, `welcomeBackFlag` isolation, passive storage migration, full build spike, break-revert for all five parity invariants.
8. **Docs + tech-debt + this review** — four tech-debt entries logged; this review draft.

---

## Decisions & reinterpretations

The kickoff document assumed a fuller brand _pivot_ and a different codebase shape than verified reality. Eight pre-plan questions were resolved with the user; all were honoured. Locked answers:

- **`brand_pivot_scope` = co_brand.** Aurora accents land _alongside_ the existing teal/violet identity, not replacing it. This narrowed Chunk 1's scope: semantic colours + primary CTA are NOT pivoted.
- **`state_container_shape` = extend_composables.** Kept the existing composable layer (`useTheme` + `useThemePreference`); no Pinia store introduced.
- **`tri_state_disposition` = drop_system.** Binary light/dark; the OS-preference machinery is gone (one-way ratchet kept in the SOT test).
- **`token_namespace_strategy` = vuetify_native.** Neutral/semantic tokens live as Vuetify `theme.colors`; aurora is an authored CSS var only.
- **`admin_layout_scope` = keep_current.** Admin toggle stays in `App.vue` (no `AdminLayout.vue`).
- **`inter_self_hosting_path` = shared_package_ui.** Fonts in `packages/ui/assets/fonts/`.
- **`d1_d2_semantic_scope` = preserve_current** (Q-chunk-1-1). Single-value success/warning/danger/info across both themes, unchanged from chunk 8.1; D1/D2 reinterpreted as "semantic colours that work in both modes" (already satisfied + WCAG-AA-verified). Escape hatch: split per-mode in a follow-up if Chunk 3's regression sweep shows they read poorly on the new zinc dark surface (a two-line Vuetify change — not a one-way door).
- **`d6_type_scale_scope` = expose_current_as_css_vars** (Q-chunk-1-2). The existing 12-step scale is exposed as CSS variables; no new 8-step scale, no consumer migration.

**Decision reinterpretations at plan-pause-time** (Sprint 3 cross-sprint pattern #12) — five total this chunk:

1. D3 sky-600 primary → "preserve the teal primary" (co-brand).
2. D1/D2 split-semantic → "preserve single-value semantics."
3. D6 8-step scale → "expose existing 12-step scale as CSS vars."
4. The kickoff's neutral-pivot framing → "zinc migration of surface/border/text only; brand + semantic-chip foregrounds keep their existing references."
5. **R1 — theme key rename.** The kickoff's literal `engineCDark` / `engineCLight` key names were reinterpreted back to the codebase's existing `dark` / `light` keys per chunk 8.1's deliberate Vuetify-standard-naming decision. **The brand identity lives in the theme values (zinc neutrals, aurora utility, Inter font), not the key names.** Reinterpretation preserves chunk 8.1's structural intent (Vuetify-ecosystem alignment) while adapting the kickoff to verified reality. Fifth decision reinterpretation in the Chunk 1 planning phase. Concretely: `lightTheme` / `darkTheme` exports kept (no rename, no back-compat re-exports); `themes: { light, dark }` kept in both SPAs' plugins; `ThemeName` stays `'light' | 'dark'`; `SPA_DEFAULT` + Vuetify `defaultTheme` stay `'dark'` (not `'engineCDark'`); the parity test references `darkTheme` / `lightTheme`. No tech-debt entry needed for a rename that didn't happen.

---

## Honest deviations & notes

- **`<meta name="theme-color">` is decorative today.** The update from `#0A0A0B` → `#09090B` (zinc-950) is mechanically correct, but neither SPA is PWA-configured (no manifest, no service worker), so the tag only affects installed-PWA browser chrome (iOS standalone status bar, Android task-switcher tint) — none of which applies to the current SPA setup. The tag is correct-for-when-it-matters; it has no visible effect today. `<html data-theme="dark">` is likewise decorative for the live app (nothing reads the dormant `--color-*` vars it would gate) but documents the dark-first intent and is the correct anchor if a CSS-variable consumption path ever lands.
- **Semantic-chip foregrounds still reference warm `neutral`.** `on-info` / `on-success` (`neutral[0]` = white) and `on-warning` (`neutral[900]` = near-black) in `vuetify.ts` were left on the warm primitive under the D1/D2 single-value-preservation reinterpretation — they are foregrounds of the _unchanged_ semantic colours, not part of the neutral-surface migration. Tracked in the warm-gray deprecation tech-debt entry.
- **Passive-on-read storage migration (anti-pattern avoidance).** The legacy `'system'` value is coerced to "unset" on read but storage is NOT rewritten during initialisation — no write side effect in a getter. Unit-tested in both SPAs (assertion that `setItem`/`removeItem` are not called on read). Active cleanup is deliberately deferred (tech-debt entry).
- **matchMedia ratchet is one-way.** The `matchMedia(prefers-color-scheme)` forbidden pattern stays in `use-theme-is-sot.spec.ts` even though no file uses it now — re-introducing OS-preference detection requires a deliberate edit to the test, not a silent component change. The composable's allowlist row shrank (it no longer needs matchMedia; still allowlisted for the storage primitives).
- **Parity-test assertion hardening (two false-greens closed during verification).** The `color-system-parity.spec.ts` artifact landed with two assertions that were initially too weak; both were caught by the break-revert discipline rather than reaching `main`:
  - **Invariant 4 (aurora authored):** originally asserted the bare token name `--brand-aurora-gradient` was present in `tokens.css`. The bare name also appears inside a `var(--brand-aurora-gradient)` doc comment, so the substring check passed even with the _declaration_ deleted (false-green). Tightened to assert the declaration form `--brand-aurora-gradient:` (trailing colon). Now fails when the declaration is removed.
  - **Invariant 3 (aurora utility-only):** originally asserted `expect(AURORA_HEXES).not.toContain(value)` — array equality against the three solid hexes. A spot-check probe revealed this did NOT catch the aurora **gradient string** (`linear-gradient(… #cd69ff …)`) leaking into a Vuetify `theme.colors` slot, because the gradient string equals none of the three hexes. Strengthened to substring containment (`expect(value).not.toContain(hex)` for each aurora hex), which catches both a solid-hex leak AND a gradient-string leak (the gradient embeds the hexes). Verified: the gradient-in-`accent` break now fails; reverted.
  - Both hardenings make the load-bearing parity artifact strictly stronger; they are the one substantive deviation from the literal plan text (the plan specified five invariants — the invariant _count_ is unchanged; only the assertions guarding two of them were tightened).
- **Test-count composition above the coarse sub-estimate.** Net +37 vs my earlier "~25-28 net new" sub-estimate (still within the kickoff's ~35-40 ceiling). The gap is `it.each` fan-out, not scope creep: each parity spec is 24 cases (4 semantic + 4 neutral + 12 typography + scalars), 48 across both SPAs, partially offset by the ~8-per-SPA tri-state/`matchMedia` cases the binary rewrites removed. Five invariants as planned; the per-slot/per-step granularity is what inflates the case count.
- **PMC — Inter font weight strategy drift.** During build, Inter was shipped as variable font (`InterVariable[-Italic].woff2`), deviating from the locked Q-chunk-1-3 = (b) static weights decision. Cursor surfaced the drift post-build as "unintentional convenient-fetch drift, no technical blocker." Resolution: reverted to static weights 400/500/600/700 (normal + italic) before commit. This is the first locked-decision-drift incident in the project's 4-sprint + stabilization history; recording it as "caught and reverted" establishes the precedent that locked decisions are honored even when the deviation is technically defensible. (Re-verified after the revert: both SPAs rebuild clean, all eight static woff2 files emit into both `dist/assets/` manifests, no `InterVariable` reference remains; the full suites + parity break-revert pass are test-neutral — counts unchanged.)
- **Font-family override via CSS cascade, not `defaults.global.style`.** `.v-application { font-family: var(--brand-font-primary) }` lives in `inter.css` next to the `@font-face` rules. The build spike (below) confirmed the cascade applies and the woff2 files resolve + emit — no `defaults.global.style` needed.
- **Five stabilization patterns confirmed independent + preserved.** The `welcomeBackFlag` module-scoped boolean (reset by `useAuthStore.clearUser()` on logout) has zero coupling to theme state — theme preference lives in `localStorage` under `catalyst.*.theme`; the flag is an in-memory boolean. Logout resets the flag and never touches theme storage; the two are orthogonal. Confirmed by inspection (`internal/welcomeBackFlag.ts` references no theme/storage symbols).

---

## Verification log

Sequence run before commit:

| Command                                                                                                      | Result                                                                                                                                                                     |
| ------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `packages/design-tokens` `vitest run`                                                                        | 22 pass \| 1 todo (tokens.spec 5 new + vuetify.spec WCAG re-run)                                                                                                           |
| `apps/main` `vitest run` (full)                                                                              | 563 / 563 pass (61 files)                                                                                                                                                  |
| `apps/admin` `vitest run` (full)                                                                             | 286 / 286 pass (30 files)                                                                                                                                                  |
| `pnpm typecheck:frontend`                                                                                    | 0 errors (all 5 workspaces)                                                                                                                                                |
| `pnpm lint:frontend`                                                                                         | 0 errors (2 pre-existing `v-html` warnings, unrelated)                                                                                                                     |
| `pnpm --filter @catalyst/main build`                                                                         | built; eight static `inter-{400,500,600,700}[-italic].woff2` emitted (hashed) to `dist/assets/`; no `InterVariable` reference                                              |
| `pnpm --filter @catalyst/admin build`                                                                        | built; same eight static files emitted to `dist/assets/`                                                                                                                   |
| **Break-revert — parity invariant 1** (dark `success` diverged)                                              | caught: "single-value semantics > identical success" failed; reverted                                                                                                      |
| **Break-revert — parity invariant 2** (dark `background` = light)                                            | caught: "split neutrals > DIFFERENT background" failed; reverted                                                                                                           |
| **Break-revert — parity invariant 3a** (solid aurora hex into `accent`)                                      | caught: "aurora utility-only > not in theme.colors" failed; reverted                                                                                                       |
| **Break-revert — parity invariant 3b** (aurora **gradient string** into `accent`)                            | spot-check probe — original array-equality assertion did NOT catch it (false-green); assertion strengthened to substring containment; now caught; reverted                 |
| **Break-revert — parity invariant 4** (removed `--brand-aurora-gradient` decl)                               | caught after tightening the assertion to the declaration form (`--brand-aurora-gradient:`); closed a false-green where the bare token name matched a doc comment; reverted |
| **Break-revert — parity invariant 5** (removed `--catalyst-typography-mono-weight`)                          | caught: "typography > mono {size,weight,line-height}" failed; reverted                                                                                                     |
| **Break-revert — font invariant** (dropped `'Inter'` from `--brand-font-primary`, post-static-revert re-run) | caught: "typography > defines --brand-font-primary referencing Inter" failed; reverted (confirms the font revert left the parity test biting; counts unchanged)            |

Test count (delta vs Sprint 3.5 entry state): main 547 → 563 (+16), admin 270 → 286 (+16), design-tokens 17 → 22 (+5) = **+37 net** — within the kickoff's revised G ceiling (~35-40). The net is below the gross new-case count: the two `color-system-parity.spec.ts` files contribute **24 cases each (48 total)** because the five invariants fan out through `it.each` (4 semantic slots + 4 neutral slots + 12 typography steps + the scalar cases), and the binary-theme rewrites of `useThemePreference.spec.ts` + `ThemeToggle.spec.ts` **removed** the tri-state / `matchMedia` cases (~8 per SPA), offsetting the additions. So the driver of "higher than the ~25-28 sub-estimate" is the per-slot/per-step `it.each` granularity of the parity test, NOT more invariants than planned (still exactly five) — see the "Honest deviations" note on test-count composition.

---

## Files touched

**Design tokens (`packages/design-tokens`):**

- `src/tokens.ts` — `brand.aurora` block; new `zinc` scale; `ZincTokens` type.
- `src/semantic.ts` — neutral roles migrated to `zinc`; docblock rewrite (co-brand + D4/D5).
- `src/vuetify.ts` — WCAG docblock re-measured for zinc; R1 theme-key note. (Values unchanged post-break-revert.)
- `tokens.css` — `--brand-aurora-*` + gradient; `--brand-font-primary`; 12-step typography CSS vars.
- `src/tokens.spec.ts` — new file (aurora + zinc value-presence + monotonic darkening).

**Shared UI (`packages/ui`):**

- `assets/fonts/inter-{400,500,600,700}.woff2` + `assets/fonts/inter-{400,500,600,700}-italic.woff2` — eight self-hosted static-weight woff2 files (latin subset, Inter v4). (Variable `InterVariable[-Italic].woff2` removed in the static-weight revert.)
- `assets/fonts/inter.css` — eight discrete-weight `@font-face` blocks + cascade override.
- `assets/fonts/LICENSE-INTER.txt` — SIL OFL 1.1 attribution.
- `package.json` — `exports` (font css + assets) + `files` (`assets`).

**SPAs (both `apps/main` + `apps/admin` unless noted):**

- `src/composables/useThemePreference.ts` — binary model rewrite + passive migration.
- `src/components/ThemeToggle.vue` — system button removed; binary docblock.
- `src/plugins/vuetify.ts` — R1 docblock; main only: `defaultTheme: 'dark'`.
- `src/main.ts` — import `@catalyst/ui/assets/fonts/inter.css`.
- `index.html` — `data-theme="dark"` + `theme-color #09090B`.
- `src/core/i18n/locales/{en,pt,it}/app.json` — `theme.toggle.system` key removed.
- `tests/unit/architecture/use-theme-is-sot.spec.ts` — matchMedia-ratchet docblock + allowlist comment.
- `tests/unit/architecture/color-system-parity.spec.ts` — new file (five invariants).
- `tests/unit/composables/useThemePreference.spec.ts` — rewritten for binary + passive migration.
- `tests/unit/components/ThemeToggle.spec.ts` — rewritten for binary.
- `tests/unit/App.spec.ts` — inline i18n bundle: unused `system` key removed.

**Docs:**

- `docs/tech-debt.md` — four new entries (warm-gray deprecation, dropped `'system'`, dormant `--color-*`, `01-UI-UX.md` staleness).
- `docs/reviews/sprint-3-5-chunk-1-review.md` — this file.

---

## Decisions documented for future chunks

- **Variable Inter is a deliberate cleanup chunk if ever revisited — not a silent build-pass change.** If at some point variable Inter becomes the right choice for the project (smaller payload at many weights, fluid axis use), it is a deliberate cleanup chunk decided at plan-pause-time, not a quiet swap during a build. Q-chunk-1-3's static-weight lock stands until explicitly revisited.

---

## Open follow-ups (deferred, not blocking close)

All four are logged in `docs/tech-debt.md`:

- **Warm-gray `neutral` deprecation** — audit + remove the warm scale once brand-surface + semantic-chip-foreground consumers migrate (Chunk 4 / consolidation).
- **Dropped `'system'` preference** — optional active localStorage cleanup of stale `'system'` rows (low priority; passive-on-read coercion already correct).
- **Dormant `--color-*` CSS vars** — still warm-gray (zero consumers); delete-or-migrate decision bundled with the warm-gray deprecation.
- **`docs/01-UI-UX.md` staleness** — v2 prose refresh scheduled for Chunk 5 per the kickoff.

Round-trip target: 2 per D12 (plan-approval round + this post-build spot-check pass).
