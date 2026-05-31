# Sprint 3.5 — Self-Review (design-v2 mini-sprint)

**Status:** Sprint 3.5 closed. This self-review synthesizes the five-chunk design-v2 mini-sprint from the independent reviewer's cross-chunk perspective.

**Scope:** Sprint 3.5 was an interstitial mini-sprint between Sprint 3 close and Sprint 4 kickoff, run to land the Engine C v2 brand identity (a partner-agency moodboard: aurora gradient accent, zinc true-neutrals, Inter, dark-mode-primary aesthetic) without derailing the Phase 1 feature roadmap. Five chunks: (1) token foundation + theme, (2) component overrides + token consumption, (3) surface-by-surface visual regression sweep, (4) aurora surfacing + semantic-chip severance + email mail-theme, (5) this wrap-up (dead-code deletion + doc rewrite + this self-review + standards consolidation).

**Path taken:** Path Z — developer-led defensible defaults, not an agency design team or a commissioned designer. The accepted trade-off was stated up front: _functional, not exceptional; defensible-not-optimal defaults; locked choices compound._ This self-review's verdict on that trade-off is in § 5.

---

## 1. What landed

The v2 brand is in code, applied, and documented:

- **Zinc true-neutrals** replaced the v1 warm-gray scale across both SPAs' Vuetify themes (Chunk 1), consumed everywhere via the theme layer (Chunk 2), verified rendering in both modes across every surface (Chunk 3), and the dead warm-gray primitive deleted (Chunk 5).
- **Aurora accent** (the `#CD69FF → #7FC3FF → #00FFF2` gradient) registered as a utility (Chunk 1), held utility-only through Chunks 1-3 (never in `theme.colors`), and surfaced as thin chrome accents on three persistent surfaces — auth card (both SPAs), onboarding app-bar, creator dashboard header — in Chunk 4, all via `var(--brand-aurora-gradient)`.
- **Inter** self-hosted as static weights 400/500/600/700 (normal + italic) in `packages/ui/assets/fonts/` (Chunk 1).
- **Binary dark-default theme** — the tri-state `'system'` preference dropped, `matchMedia(prefers-color-scheme)` machinery removed and ratcheted shut, dark forced as the first-visit default (Chunk 1).
- **Token scales consumed, not just declared**: `--radius-*` (reconciled to the existing dormant scale), `--catalyst-typography-*` (the existing 12-step scale exposed as CSS vars and consumed in shared components), and four container/variant Material tokens (`outline`, `outline-variant`, `primary-container`, `error-container`) registered explicitly (Chunks 2-3).
- **`CEmptyState`** shared component shipped + migrated across 5 call sites, with a `titleTag` prop for document-outline correctness (Chunks 2-3).
- **Brand mail theme** (`catalyst.css`) — a light-surface email theme using brand hex literals (Chunk 4).
- **The design-system doc** (`01-UI-UX.md`) rewritten for v2, and the standing-standards backlog consolidated (Chunk 5).

The co-brand framing held throughout: aurora landed _alongside_ the v1 teal/violet identity (preserved as primary + logo), not replacing it. The brand identity lives in the theme _values_ (zinc, aurora, Inter), not in renamed theme keys — the Vuetify `light`/`dark` keys were kept per chunk 8.1's standard-naming decision (Chunk 1, R1).

---

## 2. The discipline patterns this mini-sprint exercised

Sprint 3.5 was unusually dense in process-discipline events. Four patterns recurred and earned their place.

### 2.1 Decision-reinterpretation-at-plan-pause-time (the dominant pattern)

Every chunk's kickoff was written without a fresh comprehensive codebase scan, so every chunk's read pass surfaced divergences between the kickoff's assumptions and verified reality. The discipline — reinterpret the locked decision to preserve its _structural intent_ while adapting the _mechanism_ to reality — fired repeatedly:

- **Chunk 1**: six reinterpretations (co-brand scope; useThemeStore → existing composables; drop tri-state; Vuetify-native token naming; admin-keeps-layout; R1 theme-key-rename dropped).
- **Chunk 2**: five (radius reconciliation to the dormant scale; spec colocation; off-scale literal handling; 5-vs-4 call sites; 5-vs-6 components).
- **Chunk 3**: the `--v-theme-outline` 3-vs-4-token expansion.
- **Chunk 4**: the headline one — "auth hero + dashboard welcome bar" reinterpreted to "thin accents on persistent surfaces," because the named targets didn't exist (auth has no hero; the agency dashboard is a Sprint-4 throwaway placeholder).

The Chunk 4 reinterpretation is the clearest case in the project's history of the pattern changing _what gets built_, not just _how_. The welcome bar was deferred to Sprint 4 (to be built with the real dashboard) rather than decorating a page slated for deletion.

**Verdict**: not a failure mode. It scales with the gap between kickoff-time assumptions and verified codebase state. The cost (a read-pass-then-reinterpret cycle per chunk) is far cheaper than the alternative (building on wrong assumptions, then reworking). This pattern should be a permanent expectation, codified in § 5 standards.

### 2.2 Literal-implementation-surfaces-spec-errors

When the implementing agent implements _exactly_ what the kickoff specifies — rather than silently "correcting" it based on inference — specification errors surface as observable artifacts instead of silent corrections. Three instances:

- **Chunk 1**: the kickoff said static Inter weights; an interim build shipped the variable font (an unintentional drift); it was surfaced post-build and reverted to the locked decision.
- **Chunk 2**: the kickoff's R3 annotation said migrate off-scale literals to `body-size "(16px)"`; the implementer used the literal token name `body-size`, which is 14px in this codebase (not 16px). The implementer surfaced the discrepancy rather than substituting `body-lg-size` on inference. The reviewer's annotation was wrong about the scale's structure; the literal implementation made that wrong-ness visible.
- **Chunk 4**: two kickoff inaccuracies (a non-existent `on-info` `it.todo`; a `config/mail.php` `theme` key that was actually absent, resolving to `markdown.theme`) surfaced because the implementer checked rather than assumed.

**Verdict**: this is a load-bearing safety property, not pedantry. It means reviewer errors (and there were several — the reviewer's mental model of the typography scale, the mail config structure, and the aurora targets were all wrong at kickoff time) become correctable artifacts rather than silent compounding mistakes. Codify in § 5.

### 2.3 Break-revert catches false-greens

Every "the test enforces X" claim was paired with a temporary mutate-confirm-fail-revert. This caught real false-greens:

- **Chunk 1**: two false-greens in the color-system-parity test — invariant 3 (aurora utility-only) was array-equality and missed the gradient-string-leak case (tightened to substring containment); invariant 4 (aurora authored) matched a bare token name in a doc comment (tightened to declaration-form). Both surfaced _before_ commit, both made the load-bearing test strictly stronger.
- **Chunks 2-4**: break-revert confirmed teeth on every new invariant (radius-parity, typography-consumption, form-error-pattern, the 4-token container parity, the warm-neutral severance lock, the aurora-var lock).

**Verdict**: the single most reliable defense against a test that asserts a property it doesn't actually enforce. Already a Sprint 3 §b pattern; reaffirmed across the whole mini-sprint.

### 2.4 The eyes-on loop overrides on-paper aesthetic choices

Chunk 4's aurora surfacing was the only chunk with genuine aesthetic judgment, and the eyes-on loop (Cursor flags + fixes; Pedram verifies the render in both modes) did exactly what it exists for: the creator-dashboard accent shipped first as the planned 3px × 40px tick, read as a "stray, purposeless line" in the rendered sparse surface, and was unified to a full-width header rule that made all three accents read as one brand language.

**Verdict**: no source-level review or unit test could have caught "this tick looks purposeless." Aesthetic correctness exists only in the render. The eyes-on loop is the only mechanism in the workflow that reaches it, and Chunk 4 is the one chunk where it mattered. Codify the pattern: aesthetic surfaces require eyes-on; the on-paper choice is a starting point the render can override.

---

## 3. Latent bugs surfaced (the systematic-pass dividend)

Sprint 3.5's systematic passes — the visual sweep especially — surfaced four pre-existing latent issues that ad-hoc work had missed. None were introduced by the mini-sprint; all predated it.

1. **`--v-theme-outline` borders not rendering at all** (Chunk 3). The `outline` Material token was referenced raw (no fallback) in the onboarding dropzones; Vuetify doesn't auto-generate it; an undefined CSS var inside `rgb()` invalidated the entire `border` shorthand, resetting `border-style` to `none`. The dropzone borders weren't rendering wrong — they weren't rendering. Found by the Chunk 3 token-consumer audit; fixed by registering the token.
2. **Wizard-revisit blank-form** (Chunk 3 sweep, fixed separately as `5cef61f`). Revisiting a completed Tax or click-through Contract step showed a blank form, reading as lost progress. Root cause was security-correct (PII never returned to the browser) but the presentation layer didn't handle "complete-but-not-rehydratable." Found by the eyes-on sweep.
3. **`vendor:publish` gitignore swallow** (Chunk 4). The monorepo-root `vendor/` gitignore rule (intended for Composer deps) also matched the published `resources/views/vendor/mail/` path — the authored mail theme would have been silently uncommitted. Found by the pause-condition-#4 publish-output inspection.
4. **Stale `var(--color-*)` consumption guidance in THREE docs** (Chunk 5). `01-UI-UX.md §12`, `02-CONVENTIONS.md §3.8`, AND `packages/design-tokens/README.md` instructed contributors to use the `--color-*` vars that Chunk 5 deletes. Found progressively: §12 named in the kickoff, §3.8 found in the read pass, README found in the deletion audit — three instances of one root cause surfaced by progressively more systematic passes.

**Verdict**: the strongest justification for the verification-heavy chunks (3 and 5) existing at all. The recurring lesson: a systematic pass over a surface finds the bugs that accumulate when that surface isn't systematically checked. The component-test harness gap (§ 4) is why these weren't caught by tests.

---

## 4. The harness gap (the one thing Sprint 3.5 didn't fix)

The component-test harness renders under Vuetify's _stock_ theme — not the Catalyst zinc themes. So no component-level test exercises a rendered surface in the actual v2 theme; the token _values_ are regression-locked (color-system-parity), but nothing renders a component against the real dark theme and asserts on it. This is why Chunks 3 and 4 relied on eyes-on verification rather than automated rendering coverage.

Sprint 3.5 deliberately did _not_ fix this — standing up a themed-mount harness is infrastructure work (a chunk of its own), not docs or brand work. Two tech-debt entries track it (`packages/ui` has no test harness; the harness renders under stock theme). Both remain **open** after Chunk 5; the eyes-on sweep is the documented continuing mitigation.

**Verdict**: the correct deferral. But it's the mini-sprint's largest standing risk — every future visual/theming change relies on eyes-on until the harness registers the themes. The first Sprint 4 chunk that touches rendered surfaces should weigh closing it.

---

## 5. Accepted trade-offs (Path Z, revisited)

Path Z's bet was "developer-led defensible defaults, functional-not-exceptional." Held against the result:

- **Aurora as thin accents** is the clearest expression of the trade-off. The restrained treatment (2-3px edge-lines, never full-bleed) is _defensible and coherent_ — it signals brand without screaming. It is not _exceptional_ — a designer might have done something more distinctive with the gradient. That's exactly the trade Path Z named, and it held: the result is tasteful and won't embarrass, which was the goal.
- **Body = 14px scale gap.** The codebase's 12-step scale has `body` at 14px and `body-lg` at 16px, with a real 15px gap where some dense-list surfaces sat. Chunk 2 chose uniform 14px (honoring "expose the existing scale, don't re-author"); Chunk 3's eyes-on confirmed it reads cleanly. The accepted characteristic: the scale has a documented 15px gap, navigated by choosing the nearest canonical step rather than scattering allowlisted exceptions.
- **Single-value semantic colors** (D1/D2 reinterpreted in Chunk 1). The chunk-8.1 single-value semantic colors were preserved rather than split per-mode. The marginal `on-info` contrast (~4.07:1, below AA-normal) is a pre-existing characteristic left as-is — a semantic-palette decision predating the brand pivot, explicitly out of Sprint 3.5's scope. Logged, not fixed.
- **The mail teal-700 vs SPA teal-500 "correct inconsistency"** (Chunk 4). The mail button uses teal-700 while the SPA primary is teal-500. This _looks_ inconsistent but is correct: the mail theme is a light surface (always the marginal-contrast regime) where teal-500-on-white fails AA, so it picks the light-safe darker teal; the SPA uses teal-500 because its default surface is dark (good contrast). Both correctly choose the AA-passing step for their background. Documented inline to prevent a future "fix."

**Verdict on Path Z**: the trade-off was honestly stated and honestly delivered. The result is functional, coherent, and defensible. The cost — every locked choice compounds, and the kickoffs were reinterpretation-prone because they weren't designer-validated — was real but managed by the reinterpretation discipline. For a solo dev landing a partner-agency brand without a design budget, Path Z was the right call.

---

## 6. Standing-pattern candidates (codified in this chunk's W4)

Sprint 3.5 generated or reaffirmed these patterns, now migrated into `PROJECT-WORKFLOW.md § 5` (with Sprint 3 §b's 16 as #5.21-5.36):

- **Decision-reinterpretation-at-plan-pause-time** (§ 2.1) — the dominant pattern.
- **Literal-implementation-surfaces-spec-errors** (§ 2.2) — the safety property.
- **Break-revert-claim-verification** (§ 2.3) — already a §b pattern, reaffirmed.
- **Eyes-on-overrides-on-paper-aesthetic** (§ 2.4) — new this mini-sprint.
- **Inspect generated/published-file output for gitignore swallows** — the `vendor:publish` gotcha (§ 3 item 3); a monorepo-specific hazard worth a standing note.
- **After any break-revert, verify the restore via `git status`/`git diff`** — the Chunk 4 near-miss (a `git checkout` restore that didn't take, leaving an uncommitted re-import that would have shipped Workstream B's _opposite_). Break-revert restores need verification, not assumption.
- **Cross-SPA parity** — every architecture-test addition mirrored to both SPAs, bit-identical modulo paths (throughout Sprint 3.5).

---

## 7. What carries to Sprint 4

- **The harness gap** (§ 4) — two open tech-debt entries; the first rendered-surface chunk should weigh closing them.
- **The agency dashboard + welcome bar** — deferred from Chunk 4. Sprint 4's agency-dashboard chunk (option b) builds the real dashboard at `/`, and the aurora welcome bar gets built _with_ it, not retrofitted onto a placeholder.
- **The `tokens.css` dormant-layer is now gone** — Sprint 4 inherits a clean token layer (zinc + aurora + radius + space + typography, no dead warm-gray).
- **The reinterpretation discipline** — Sprint 4's kickoffs will be reinterpretation-prone for the same reason Sprint 3.5's were (written ahead of verified state). The read-pass-then-reinterpret cycle is the expected rhythm.

---

## 8. Round-trip + commit discipline

Every chunk hit its 2-round-trip target (plan approval + spot-check), except Chunk 5's text-handoff exchange (W2 §2 + W3 drafted by the reviewer). Commit discipline held: two-commit shape (work + plan-approved docs) on Chunks 1-3; per-workstream splits on Chunks 4-5 where the workstreams touched disjoint surfaces. One PMC correction across the mini-sprint (Chunk 3's `primary-container` dark teal-900 → teal-800, an ineffective-value-that-would-have-been-regression-locked). Chunk 4 was the first chunk with zero spot-check corrections — not because the spot-check was lighter, but because the build-phase discipline (pause conditions, `git status` checks, the eyes-on loop) caught everything before review.

---

_Provenance: Sprint 3.5 self-review, drafted by Claude (independent reviewer, cross-chunk perspective), 2026-05-31. Synthesizes the four closed chunk reviews + the Chunk 5 wrap-up. Sprint 3.5 closed._
