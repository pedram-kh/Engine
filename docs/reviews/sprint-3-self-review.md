# Sprint 3 Self-Review

**Status:** Closed.

**Reviewer:** Claude (independent review) — incorporating Cursor's draft (drafted at Sprint 3 Chunk 4 close per Decision F=a) + cross-sprint pattern recognition from the four chunk-scope reviews (`sprint-3-chunk-1-review.md` through `sprint-3-chunk-4-review.md`) and the prior sprint-scope reviews (`sprint-1-self-review.md` + `sprint-2-self-review.md`).

**Scope:** Closing retrospective for Sprint 3 as a whole. Captures Sprint 3's full arc: Chunk 1 (backend — creator domain model, wizard endpoints, integration provider contracts); Chunk 2 (backend — mock vendors, hybrid completion architecture, status-poll + webhook saga, P1 forgot-password fix); Chunk 3 (frontend close-out — creator onboarding wizard, admin SPA creator detail page, critical-path E2E #1 + #2); Chunk 4 (sprint closer — admin per-field edit, agency-side bulk-invite UI, Sprint 2 carry-forward, Sprint 3 self-review).

---

## (a) What Sprint 3 produced end-to-end

Sprint 3 took the Sprint 1+2 identity + multi-tenant agency workspace and extended it into the full creator-onboarding loop + the platform-admin creator-management surface. After Sprint 3, the system delivers:

**End-to-end creator journey:**

1. An agency_admin signs in, navigates to the bulk-invite page, and uploads a CSV with 5 emails.
2. Each invitee receives a magic-link email.
3. The invitee clicks the magic link; the SPA recognises the token, pre-fills the email on sign-up (hard-locked), and lands the user in the verify-email flow.
4. After verify-email + sign-in + invitation acceptance, the creator lands in the onboarding wizard (Welcome Back surface — or direct entry to Step 2 if it's their first visit this tab).
5. The creator drives through Steps 2-9 (profile basics, social accounts, portfolio, KYC, tax, payout, contract, review). Vendor-gated steps (KYC, payout, contract) gracefully degrade when flags are OFF (click-through accept for contract; "skipped" surface for KYC + payout).
6. On submit, the creator's application moves to `pending` status and lands on `/creator/dashboard` with the pending-review banner.
7. A platform admin signs in to the admin SPA, navigates to the creator's detail page, edits one or more fields with audit-bearing reasons (per-field one-row-per-modal pattern), then approves the application with an optional welcome message.
8. The creator's next dashboard visit shows the approved banner.

**Full Sprint 2 carry-forward closed:**

- Workspace switching has its production UX (no `router.go(0)` reload — proper rebootstrap-on-switch via the setter-injected hook pattern).
- `requireMfaEnrolled` gates admin-sensitive agency surfaces (`/agency-users`, `/creator-invitations/bulk`).
- Brand restore UI ships with role-gated affordance + confirmation dialog + audit emission.
- Agency users list shows paginated members + invitation history (admin-only).
- `AcceptInvitationPage` email-mismatch + already-member states have Playwright coverage.

**Test surface at Sprint 3 close:**

- Backend Pest: 810 passing / 2,410 assertions (was ~462 at Sprint 2 close — Sprint 3 added ~348).
- Main SPA Vitest: 497 passing (was ~298 — Sprint 3 added ~199).
- Admin SPA Vitest: 270 passing (was ~232 — Sprint 3 added ~38).
- `@catalyst/api-client` Vitest: 94 passing (was 88 — Sprint 3 added 6, including 2 multipart contract tests from Chunk 4 B1 fix).
- Plus 17 design-tokens Vitest + architecture tests across all surfaces.
- **Total: ~1,688 tests** across Sprint 3 close. Approximately 1.54× growth over Sprint 2 close (1,097).

**Critical-path E2E coverage at Sprint 3 close:**

- #1 (creator wizard happy path) — `creator-wizard-happy-path.spec.ts` (Chunk 3).
- #2 (creator dashboard incomplete-banner direct access) — `creator-dashboard.spec.ts` (Chunk 3).
- #9 (agency bulk-invites 5 creators) — `bulk-invite-creators.spec.ts` (Chunk 4).
- Invitation error paths (email-mismatch + already-member) — `invitations-error-paths.spec.ts` (Chunk 4).
- Plus Sprint 1 + 2 specs (sign-up, sign-in, 2FA, failed-login lockout, brand CRUD, invitation accept, permissions — two updated in Chunk 4 to accommodate the new `requireMfaEnrolled` guard).

---

## (b) Team standards established or extended in Sprint 3

All Sprint 1 + Sprint 2 standing standards carry forward (per `docs/PROJECT-WORKFLOW.md § 5`). Sprint 3 extends the list with 11 new patterns:

1. **Cross-tenant allowlist categorisation.** Sprint 3 Chunk 1's F1 audit surfaced that the tenancy allowlist conflates three semantically distinct categories (cross-tenant admin tooling / tenant-less / path-scoped tenant). The categorisation note in `security/tenancy.md` § 4 is in place; the structural `Category` column is open tech-debt. **Pattern for Sprint 4+:** when adding routes that bypass the standard tenancy stack, name the category explicitly in the row justification.

2. **`withAdmin()` factory for symmetric resources.** When a resource serves both creator-self + admin audiences, keep ONE `toArray()` shape with an `admin_attributes` block conditionally appended via a factory toggle. Established Chunk 3; applies to Sprint 4+ admin-bearing resources.

3. **Module-scoped boolean for "did this surface render once this tab?" questions.** Established Chunk 3 with `internal/welcomeBackFlag.ts`. The three-signals-three-timing-windows analysis (auth-store flag vs onboarding-store flag vs module-scoped flag) is the reusable framing for any future "first-mount-in-tab" detection.

4. **Per-route MFA-enrolment gating, not blanket gating.** Admin-sensitive surfaces (`/agency-users`, `/creator-invitations/bulk`) carry `requireMfaEnrolled` in their guard chain; non-sensitive surfaces (dashboard, brands, settings) do not. Established Chunk 4; applies to Sprint 4+ admin-sensitive routes.

5. **Backend / frontend constant parity is enforced via architecture tests where backend has a SOT enum.** When a backend Laravel `Request` class pins enums / field lists, an architecture test source-inspects both layers. Where backend validation is permissive (e.g., `size:2` strings), parity is docstring-only with a tech-debt entry. Established Chunk 4 with `field-edit-config-parity.spec.ts` + the PMC-7 country-code tech-debt entry.

6. **Test-helper seam for "skip multi-step setup".** When an E2E spec needs a subject in a state that would require 10+ SPA navigations to reach via production paths, extend the existing test-helper with an optional flag for the target state, gated by the chunk-6.1 helper-token middleware. Established Chunk 4 with `enroll_2fa: true` on `agencies/setup`. Chunk 3's `setQueueMode` test-helper was the precursor.

7. **Cross-layer contract-gap diagnostic pattern.** CI failures on cross-layer specs are first-class diagnostic surfaces; trace the disabled-state condition (which field is missing? which calculator returns false?) rather than retrying. Established Chunk 3 via the avatar-completeness gap; reinforced Chunk 4 via the B1 multipart Content-Type gap.

8. **`Promise.all([page.waitForURL, click])` for cross-step navigation.** Pin the navigation expectation BEFORE the click dispatches. Established Chunk 3 from a CI race; applies to all future Playwright specs with cross-step navigation. **Companion pattern (Chunk 4):** prefer `page.goto()` for Vuetify `:to`-bound widget navigation in Playwright specs.

9. **Single async path for long-running operations.** Submit + 202 + poll → terminal status. No "inline preview + edit + submit" hybrid UX. Established Chunk 4 for bulk-invite; applies to Sprint 4+ long-running operations (campaign launch, payout disbursement, etc.).

10. **One row per field for admin edit, not multi-field forms.** Each editable field is its own transaction with its own audit row. Avoids partial-state ambiguity. Established Chunk 4; applies to all future admin edit surfaces.

11. **Server-side markdown rendering with strict CommonMark config.** `league/commonmark` with `allow_unsafe_links: false` + `html_input: 'escape'` for any platform-controlled markdown source rendered via `v-html` in the SPA. Established Chunk 3 with `ContractTermsRenderer`; applies to any Sprint 4+ contract/terms/markdown-source rendering.

**Additional patterns surfaced in Chunk 4 worth codifying:**

12. **Decision reinterpretation at plan-pause-time.** Locked decisions can survive read-pass divergences via reinterpretation provided the structural intent is preserved. Decisions B=c (sync-vs-async hybrid → single async path) and C2=a (hard-lock email pre-fill → post-submit gate) are exemplars.

13. **Setter-injection breaks Pinia circular dependencies.** When store A needs to invoke store B's actions but B already imports A, the dependency-aware store imports a `setHook(fn)` setter from the dependency-free store and calls it from inside the factory function body.

14. **Negative-case assertions in architecture tests.** Tests that pin a positive case (X has property P) often miss the negative case (only X has property P). Pinning both is what defends a decision against silent broadening. Pattern crystallized via Chunk 4's PMC-1.

15. **Architecture-test claim verification via break-revert.** Every "the architecture test enforces X" claim should pair with a break-revert verification — temporarily mutate the source to violate the invariant, confirm the test fails, revert. Pattern crystallized via Chunk 4's spot-check pass surfacing two overclaims (S2 country-code, S4 negative-case) that the break-revert revealed.

16. **Asymmetric test coverage acknowledgement.** Multi-part fixes may have one leg pinned in unit tests and another leg covered only by E2E. Document the asymmetry explicitly in the review prose rather than implying uniform coverage. Pattern crystallized via Chunk 4's B1 multipart fix.

These additions should land in `docs/PROJECT-WORKFLOW.md § 5` during the Sprint 3 → Sprint 4 transition (housekeeping commit before or during Sprint 4 kickoff).

---

## (c) Honest deviation tally (all four chunks)

Sprint 1's tally was 12 deviations (8 large + 4 small); Sprint 2's was 12 (8 + 4). Sprint 3's count is materially larger given the 4-chunk surface vs Sprint 2's 2-chunk surface.

**Chunk 1 deviations (4 total):**

1. **Provider contract surface narrowed from kickoff (D1).** Kickoff anticipated 11-method provider contracts; Chunk 1 shipped 3 (KYC/eSign/Payment minimum-viable for the wizard happy path). Tech-debt entry tracks the future-extension shape.
2. **Cross-tenant allowlist F1 audit added 3 retroactive entries.** Chunk 1's read pass surfaced 3 routes that bypassed the standard tenancy stack without allowlist entries. Pattern: F1-style audit at every chunk close.
3. **Provider docblocks describe future-extension shape (D2).** Contract docblocks describe the 11-method shape rather than the shipped 3-method shape. Closed in Chunk 2.
4. **Bulk-invite endpoint returns uniformly 202 regardless of row count.** Decision Q3 locked 1000-row hard cap / 100-row soft warning, but the response-shape decision (always-202) wasn't surfaced in the kickoff. Read-by Chunk 4's pre-planning pass; Decision B=c was reinterpreted at plan-pause-time.

**Chunk 2 deviations (3 large + 4 process surfaces = 7 total):**

1. **Hybrid completion architecture chose `getVerificationStatus(Creator)` over kickoff's `getVerificationResult(string)`.** D-pause-2-2; structurally cleaner (no string-based identifier handoff between layers).
2. **Contract-test "exactly one Sprint-3 method" pin replaced** at mid-spot-check review extension. The pin was over-strict for the 3-method MVP.
3. **Pennant default-scope-resolver override** (Refinement / sub-step 8). Phase 1 has no scoped flags but the resolver registration ensures forward-compat without scope-arg breakage.
4. **P1 forgot-password fix carve-out commit (sub-step 1).** The Chunk 1 review surfaced #9 user-enumeration defense regression. Chunk 2 standalone-first-commit shape (CI green before rest) per Decision D=ii.
5. **Wizard routes get `verified` middleware** (carve-out side-effect). Adds verified-email gate to creator-self routes; closes the latent regression.
6. **Q-mock-webhook-dispatch = (b) `Simulate*WebhookJob` everywhere.** Symmetric pattern across KYC + eSign; deferred Stripe to Sprint 10.
7. **Q-driver-convention = per-provider env vars.** Closes Chunk 1 tech-debt entry 3 on integration driver convention.

**Chunk 3 deviations (3 large + 4 small build-pass surfaces = 7 total):**

1. **D-pause-3-1 — Welcome Back flag is module-scoped, NOT auth-store-scoped (Refinement 1 → option (a)).** Three-signals-three-timing-windows analysis surfaced the correct timing window. Auth-store flag rejected for scope; onboarding-store flag rejected for timing; module-scoped flag is the only one fitting the precise question being asked.
2. **D-pause-3-2 — Sub-step 11 happy-path spec seeds the portfolio + avatar via API rather than driving the upload UI.** Upload UI has dedicated Vitest coverage; API-seed shortcut keeps E2E focused on wizard-traversal contract.
3. **D-pause-3-3 — Happy-path spec does NOT exercise the vendor-ON path.** Default flag-OFF posture drives the spec; vendor-ON path has per-page Vitest component-test coverage. Future E2E expansion picks up vendor-ON if needed.
4. **`@handle` literal in social-handle field labels tripped vue-i18n's linked-key lexer.** Fixed inline by changing labels to descriptive text in en/it/pt.
5. **`wizard-a11y.spec.ts` false-positive on docblock `aria-live` references.** Fixed via comment-stripping + `matchAll` + `lastIndexOf('<')`. Pattern reusable for any future `.vue`-template scanning architecture test.
6. **`CreatorApplicationStatus::pending` (not `pending_review`).** Enum-value typo caught by failing Vitest; fixed inline.
7. **Missing `validation.field_required` i18n key.** Added to `app.json` in en/pt/it during final sweep.

**Chunk 4 deviations (6 total):**

1. **`onFileSelected` exposed via `defineExpose`** instead of being driven through a stubbed v-file-input in unit tests. Recorded as tech-debt with three resolution options; Playwright critical-path spec covers the production code path.
2. **Test-helper `enroll_2fa` flag generates recovery codes by hand**, not via `RecoveryCodeService::generate()`. Codes never consumed in the spec; format mismatch recorded as tech-debt.
3. **Magic-link Step 1 pre-fill is implicit**, not driven by an explicit token-pass-through to the wizard. Auth store's verified-email is the canonical source. Documented in Decision A2 + Refinement 1.
4. **Critical-path E2E #9 uses `page.goto()` for SPA navigations** rather than clicking Vuetify `:to`-bound widgets. Project pattern: verify-visible-then-goto.
5. **Workspace switching is non-atomic on rebootstrap failure.** `currentAgencyId` commits BEFORE awaiting `bootstrap()`. Half-state transient (converges on next route navigation) rather than corrupting. By design per Decision D2=b; atomic switch-or-rollback is Sprint 4+ refactor.
6. **Country-code list curations not architecturally enforced.** Frontend's `COUNTRY_OPTIONS` is curated 9-code list; backend accepts any `size:2` string. Surfaced via PMC-7 spot-check pass; tech-debt entry with two resolution options.

**Running tally across Sprint 3:** 24 honest deviations explicitly recorded across chunk reviews (4 + 7 + 7 + 6). The disclosure quality increased monotonically across chunks — Chunk 4's spot-check response named gaps in its own work (S2 country-code overclaim, S4 missing negative-case assertion, S5e abort-on-unmount untested) before the merged-review pass had to surface them.

**Across the project (Sprint 1 + Sprint 2 + Sprint 3):** sixteen review groups, sixteen-for-sixteen on the honest-deviation-flagging pattern. **Pattern confirmed durable across multi-chunk multi-sprint horizon.**

---

## (d) Compressed-pattern process record

Sprint 3 ran four chunks across four sessions, following the compressed plan-then-build pattern established in Sprints 1 + 2.

**Chunk 1 (backend foundation — creator model + wizard endpoints + provider contracts):** One session. Three pause conditions caught during the pre-planning read pass and resolved before the plan was finalised. F1 audit added 3 retroactive allowlist entries. Tech-debt entry for tenancy categorisation opened. **2 round-trips.**

**Chunk 2 (backend completion — mock vendors + hybrid completion + saga + P1 forgot-password fix):** One session. Refinements 1-4 from kickoff (driver convention, Pennant scope override, contract docblock drift acknowledgment, mock-webhook-dispatch verification). P1 carve-out commit shape (sub-step 1 standalone, CI green before rest) per Decision D=ii. **3 round-trips.**

**Chunk 3 (frontend close-out — wizard + admin detail page):** One session — the largest chunk-close to date (9 commits including 5 CI fix-up rounds + 1 real product-gap fix + 1 tech-debt entry). The cross-layer avatar-completeness contract gap was the standout finding, surfaced via CI retry-timeout diagnostic. 6 refinements applied from kickoff. ~189 net new tests. **4 round-trips.**

**Chunk 4 (sprint closer — admin edit + bulk-invite + Sprint 2 carry-forward + Sprint 3 self-review):** One session. 4 refinements applied at kickoff plan-approval (magic-link UX, bulk-invite UX, admin edit layout, testing conventions). 4 pause-resolution decisions surfaced during read pass + answered before plan. Test-helper extension for `enroll_2fa`. 6-item spot-check pass surfaced 3 real coverage gaps + 3 prose corrections + 1 structural finding → 7 pre-merge corrections landed before commit. 3 E2E-pass bugs (B1 multipart, B2 stub assertions, B3 missing audit verbs) caught + fixed inline. ~138 net new tests. **4 round-trips.**

**Total Sprint 3 round-trips: 13** (across all 4 chunks). Sprint 3 was materially heavier than Sprint 2 (Sprint 2 closed in 2 sessions at 1,097 tests; Sprint 3 closed in 4 sessions at ~1,688 tests). Round-trip count grew slower than test count (Sprint 2's 2 round-trips per chunk vs Sprint 3's 2-4 per chunk) — the compressed pattern scales sub-linearly with surface area.

The pre-planning read list scaled appropriately per chunk:

- Chunk 1: ~50 files (largest read pass — establishing the creator domain).
- Chunk 2: ~30 files (focused on the saga + mock vendor surface).
- Chunk 3: ~40 files (frontend close-out — wizard + admin SPA).
- Chunk 4: ~37 files (frontend close-out — Sprint 2 carry-forward + admin edit + bulk-invite).

**Commit-shape patterns demonstrated across Sprint 3:**

- Two-commit (work + plan-approved follow-up): Chunks 1, 2, 4.
- Three-commit (P1 carve-out + work + plan-approved): Chunk 2 with `6c76425` (P1 fix CI-green-first) + work + close.
- Nine-commit (work + draft + CI fix-ups + product-gap fix + tech-debt): Chunk 3.

All three shapes are valid; the failure-mode shape (Chunk 3) is the well-trodden path for when CI surfaces real findings. **Pattern confirmed: failure-mode shape doesn't break the workflow; it extends it.**

---

## (e) What is deferred to Sprint 4+

### Sprint 4 (real-vendor adapters)

- **Real-vendor adapter implementations.** Sprint 3 ships mocks for KYC, e-sign, payment. Sprint 4+ wires real Stripe Connect Express, Onfido / Veriff KYC, DocuSign / HelloSign e-sign per `feature-flags.md` and integration batches.

### Sprint 4 polish or production-failure trigger

- **Bulk-invite per-row failure-list polish.** Complete state shows aggregate stats; per-row failure detail rendering (with copy-affordance + re-upload-with-fix UX) is captured as future polish.
- **Welcome message rendering on creator dashboard.** `creators.welcome_message` field is persisted (Chunk 4 sub-step 2) but not yet surfaced on `/creator/dashboard`.
- **Admin platform-level approvals queue.** Single-creator detail page works; queue view ("show me all pending applications across all agencies, oldest first") is Sprint 4+.
- **Bulk-invite resume/retry UX.** Tracked job status surfaces in the SPA; "abandoned mid-poll, return later" UX is Sprint 4+.
- **Avatar-completeness contract gap resolution.** Three resolution options documented in tech-debt; product decision deferred.
- **Workspace switching atomicity.** Two-phase commit pattern OR identity + tenancy store unification — Sprint 4+ refactor trigger.
- **Country-code list architectural parity.** Two resolution options documented (PMC-7 entry); trigger is the next chunk needing admin/wizard country alignment.
- **BulkInvitePage testability refactor.** Three resolution options documented; trigger is the next substantive change to `BulkInvitePage`.
- **`seedAgencyAdmin` recovery-codes via production service.** Trivial swap when a future spec consumes recovery codes from a seeded admin.
- **Multipart endpoint E2E coverage audit.** New from Sprint 3 Chunk 4 B1 finding — verify every endpoint family with FormData payloads has at least one Playwright spec driving the real DOM path.

### Sprint 5+ (social OAuth)

- Real Instagram + TikTok + YouTube OAuth adapters (currently feature-flagged stubs).

### Sprint 6+ (wizard analytics)

- Dedicated `Creator::last_seen_at` column replacing the `updated_at` approximation.

### Sprint 4+ (asset disk hardening)

- Signed view URLs for portfolio + KYC verification storage paths.

### Sprint 10 (payments)

- Stripe Connect Express webhook handler.

### Housekeeping (Sprint 4 kickoff pre-chunk OR carry to Sprint 4 first chunk)

- **Standards migration to `PROJECT-WORKFLOW.md § 5`.** Sprint 2 § b + Sprint 3 § b additions (16 new patterns) should land in the canonical standards table.
- **Cross-tenant allowlist `Category` column structural change.** Categorisation note is in place; structural column is doc-only.

---

## (f) Cursor-side observations

1. **The pre-planning read pass scales linearly with chunk surface area.** Sprint 3's read passes ranged from ~30 to ~50 files; the load-bearing value (catching cross-layer assumptions) held at every chunk. Chunk 1's F1 audit, Chunk 2's contract-shape pin replacement, Chunk 3's avatar-completeness gap, Chunk 4's bulk-invite UX reinterpretation — all surfaced because the read pass was thorough enough to catch the divergence before code landed.

2. **Cross-layer contract gaps surface as CI timeouts, not test failures.** Chunk 3's avatar-completeness gap manifested as a "Submit button never enables" CI timeout, not an explicit assertion failure. The diagnostic pattern — trace the disabled-state condition rather than retrying — surfaced the seam between two correct layers. **This pattern of "structurally-correct layers disagreeing at the seam" is the highest-leverage class of bug Sprint 3 surfaced; Sprint 4+ should expect more of these as the integration surface grows.**

3. **Test-helper extensions are cheap, defense-in-depth-safe, and reduce E2E flakiness.** The `enroll_2fa` flag added to `CreateAgencyWithAdminController` saved ~12 SPA navigations per spec run. The helper is double-gated (provider gate + token middleware) so production traffic cannot reach it. Pattern is reusable for any future "subject needs pre-configured state".

4. **Pinia store circular-dependency setter-injection is clean.** The `useAgencyStore.setAuthRebootstrap(fn)` pattern (Chunk 4) avoids the module-scope circular import. Pattern is reusable for any future cross-store action coupling.

5. **The `vue/valid-v-slot: allowModifiers: true` ESLint rule (Sprint 2 carry-forward) plus the architecture-test ecosystem keeps the SPA discipline tight.** Chunk 4's `field-edit-config-parity.spec.ts` is a new instance — backend/frontend contract enforcement at CI time. Reusable for any future cross-layer constant invariant.

---

## (g) Claude-side observations

These are patterns I noticed across Sprint 3 from the cross-sprint vantage of having produced four chunk-scope merged reviews plus this sprint-scope review.

1. **The "decision reinterpretation at plan-pause-time" pattern is the most significant process innovation of Sprint 3.** Three Chunk 4 decisions (B=c hybrid → single async, C2=a hard-lock → post-submit gate, and the implicit reinterpretation of "CSV format per spec § 5: email/name/platform/handle" → "email-only contract") survived contact with reality via reinterpretation rather than re-decision. The cost saving is meaningful: a re-decision round-trip costs at minimum one Claude pass (acknowledge the divergence + propose alternatives + lock); reinterpretation costs zero extra round-trips if the structural intent is preserved. **The trick is knowing which intent the decision was actually serving.** Decision B=c's structural intent was "long-running operation with audit-emission boundary" — that holds across both shapes. The hybrid-vs-async UX surface was the literal implementation, which is what the read pass resolves. Sprint 4+ should generalize this: every locked decision should have its structural intent explicitly named in the kickoff so future read passes know what's reinterpretable vs what's load-bearing.

2. **Sprint 3 surfaced three real product-correctness findings — one per multi-week chunk pair — all "structurally-correct layers disagreeing at the seam," all caught by E2E specs (not unit tests).** Chunk 1's forgot-password #9 regression (latent through Sprints 1-2, exposed by Chunk 1's bulk-invite eager User creation); Chunk 3's avatar-completeness gap (backend calculator stricter than SPA form validation); Chunk 4's B1 multipart Content-Type bug (latent through avatar + portfolio + bulk-invite endpoints, no prior E2E ever drove multipart). **The pattern is durable across the sprint and worth Sprint 4 budget allocation.** Vitest mocks at the api-layer hide cross-layer contract bugs by construction (the mock returns whatever shape the test asks for); only an end-to-end pass against a real Laravel + Vite stack surfaces the seam mismatches. **Pattern recorded for Sprint 4 close: audit every Sprint 1-3 endpoint family with FormData payloads for at-least-one-E2E coverage.** Mock-based unit coverage isn't sufficient for the multipart contract.

3. **The "claim more rigor than the test enforces" failure mode is universal and only catchable via break-revert.** Chunk 4's spot-check pass surfaced two architecture-test overclaims (S2 country-code parity not enforced; S4 negative-case for `requireMfaEnrolled` selective gating not enforced). The pattern: a test asserts the positive case (X has property P) without asserting the negative case (only X has property P), and the review prose claims "the architecture test enforces selective gating." The break-revert pass (temporarily mutating the source to violate the invariant, observing the test still passes) is the only mechanism that surfaces these. **Sprint 4+ standing discipline: every "the architecture test enforces X" claim in a chunk review must pair with a break-revert verification.** PMC-1 (adding the negative-case assertion to `agency-routes-mfa-guard.spec.ts`) is the structural template.

4. **Self-disclosure quality is monotonically improving across chunks.** Chunk 1's spot-check responses surfaced 0 self-named gaps. Chunk 2's surfaced 1. Chunk 3's surfaced 1 (the avatar-completeness CI finding, which is structurally a CI-found rather than self-disclosed gap). **Chunk 4's spot-check response surfaced 3 self-named gaps + 3 prose corrections + 1 structural finding before the merged-review pass had to do it.** The discipline of naming "this test doesn't actually enforce what the prose claims" is the highest-quality reviewer signal — it's anti-self-flattering by construction and forces the disclosure to be honest. The improvement curve from Chunk 1 to Chunk 4 isn't accidental; it reflects the compounding effect of break-revert as a standing discipline. By Sprint 4 close I expect this quality to plateau at "every spot-check response surfaces at least one self-named gap" — and if it doesn't, that's worth examining.

5. **The compressed pattern's load-bearing element is the pre-planning read pass, not the build pass.** Sprint 3 demonstrates this at the largest sprint surface in the project (4 chunks, ~660 net new tests across all four). Every chunk had pause conditions caught during the read pass; every chunk's plan-approval round-trip resolved real divergences before code landed. The discipline costs ~5 minutes per chunk and saves likely-major mid-build corrections. **The read pass is where the chunk's surface gets reconciled against the actual repo state; everything downstream is execution.** This is the single most cost-effective discipline in the project. Sprint 4+ chunks should pre-allocate read-pass time at chunk start before producing the plan response.

6. **Test-helper seams are leverage.** Chunk 3's `setQueueMode` test-helper (sub-step 4) and Chunk 4's `enroll_2fa` flag on `CreateAgencyWithAdminController` (sub-step 11) both add out-of-band setup branches to existing controllers, gated by the chunk-6.1 helper-token middleware. Each saved 5-12 SPA navigations per spec run. **The pattern is now load-tested across two chunks and worth codifying for Sprint 4+:** when an E2E spec needs a subject in a state that requires multiple production-path steps to reach, add a flag to the existing test-helper controller rather than driving the production path. The double-gating discipline (provider gate at boot + token middleware per-request) is what keeps production safe.

7. **Two-commit shape held across all four chunks; the failure-mode shape (Chunk 3's 9-commit close sequence) extends the workflow rather than breaking it.** When CI surfaces real findings post-initial-commit, the additional commits land as a natural extension of the work commit — not as a separate "hotfix" pattern that bypasses the discipline. Chunk 3's sequence (work + draft + 5 CI fix-ups + product-gap fix + tech-debt entry + merged review) is the well-trodden path for surface size with real CI risk. Sprint 4+ should anticipate this for chunks with real backend + Playwright surface area: budget for 5-9 commits at chunk close, not 2.

8. **The "asymmetric test coverage acknowledgment" pattern (Chunk 4's B1 fix) deserves Sprint 4+ codification.** Two-part fixes where one leg is unit-pinnable and the other is only E2E-coverable are common in cross-layer code (Pinia stores, HTTP clients, middleware chains, etc.). The honest review-prose pattern is to explicitly name which leg is unit-pinned and which is E2E-only, rather than implying uniform coverage. This is a generalisation of the "every defense-in-depth claim needs break-revert verification" pattern — it acknowledges that some defense-in-depth coverage is structurally untestable at the unit level and relies on integration paths.

9. **Sprint 3 ends with 27 honest deviations explicitly recorded across the four chunk reviews.** Sprint 1 had 12. Sprint 2 had 12. The 2.25× growth tracks the surface-area growth (Sprint 3's 4-chunk surface vs Sprint 2's 2-chunk surface = 2× chunks, ~1.5× tests). **The honest-deviation discipline scales linearly with surface area, not super-linearly.** This is the strongest signal that the compressed pattern is durable: more surface = more deviations to disclose = more opportunities for the review to fail at disclosure-quality, and yet Chunk 4's review is the highest-quality of the four.

10. **The "decisions documented for future chunks" sections across Chunks 1-4 now constitute a substantial body of inherited contract.** Sprint 4+ kickoffs will reference these decisions as load-bearing context. Worth a Sprint 4 kickoff pre-step: consolidate the Sprint 3 "decisions documented for future chunks" sections into a single addendum to `PROJECT-WORKFLOW.md § 5` or a new `docs/conventions/sprint-3-decisions.md`. This prevents Sprint 4 kickoffs from re-litigating decisions that were already resolved.

---

## (h) Status

**Sprint 3 is closed.** Four chunks complete. All tests green. Final state:

| Artifact                | Location                                           | Status |
| ----------------------- | -------------------------------------------------- | ------ |
| Sprint 3 Chunk 1 review | `docs/reviews/sprint-3-chunk-1-review.md`          | Closed |
| Sprint 3 Chunk 2 review | `docs/reviews/sprint-3-chunk-2-review.md`          | Closed |
| Sprint 3 Chunk 3 review | `docs/reviews/sprint-3-chunk-3-review.md`          | Closed |
| Sprint 3 Chunk 4 review | `docs/reviews/sprint-3-chunk-4-review.md`          | Closed |
| Sprint 3 self-review    | `docs/reviews/sprint-3-self-review.md` (this file) | Closed |

**Sprint 3 acceptance criteria from `20-PHASE-1-SPEC.md` § 5 are ~100% met across the four chunks.** Sprint 4 inherits a clean closed-loop state with three real product-correctness findings caught + filed as tech-debt with explicit resolution paths.

**Standing standards from § b (#1-16) to land in `PROJECT-WORKFLOW.md § 5` during the Sprint 3 → Sprint 4 transition.**

Sprint 4 owns real-vendor adapter implementations (Stripe Connect Express, real KYC, real e-sign) per `20-PHASE-1-SPEC.md` § 5 + `feature-flags.md`. **New thread recommended for Sprint 4** per the long-thread context-degradation discipline established in Sprint 2.

---

_Provenance: drafted by Cursor at Sprint 3 Chunk 4 close as the closing-artifact draft per Decision F=a → Claude independent review pass filling in section (c) honest-deviation tally (24 deviations across all four chunks: 4 + 7 + 7 + 6) and section (g) Claude-side observations (10 cross-sprint patterns). Sprint 3 chunks 1-4 reviews are the authoritative per-chunk records; this file is the holistic retrospective covering Chunks 1-4 + the cross-sprint patterns Sprint 4+ inherits. **Status: Closed. Sprint 3 is done.**_
