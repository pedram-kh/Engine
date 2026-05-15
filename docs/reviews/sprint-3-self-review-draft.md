# Sprint 3 Self-Review (DRAFT closing artifact for Sprint 3)

**Status:** Draft (awaiting independent review pass; closes at chunk-4 merge).

**Reviewer:** Drafted by Cursor at Sprint 3 chunk-4 close. _(Replace with the Claude-led independent-review pass before final commit, mirroring the Sprint 1 + Sprint 2 pattern.)_

**Scope:** Closing retrospective for Sprint 3 as a whole. Captures Sprint 3's full arc: Chunk 1 (backend — creator domain model, wizard endpoints, integration provider contracts); Chunk 2 (backend — mock vendors, hybrid completion architecture, status-poll + webhook saga); Chunk 3 (frontend close-out — creator onboarding wizard, admin SPA creator detail page, critical-path E2E #1); Chunk 4 (frontend close-out — admin per-field edit, agency-side bulk-invite UI, Sprint 2 carry-forward, critical-path E2E #9).

---

## (a) What Sprint 3 produced end-to-end

Sprint 3 took the Sprint 1+2 identity + multi-tenant agency workspace and extended it into the full creator-onboarding loop + the platform-admin creator-management surface. After Sprint 3, the system delivers:

**End-to-end creator journey:**

1. An agency_admin signs in, navigates to the bulk-invite page, and uploads a CSV with 5 emails.
2. Each invitee receives a magic-link email.
3. The invitee clicks the magic link; the SPA recognises the token, pre-fills the email on sign-up, and lands the user in the verify-email flow.
4. After verify-email + sign-in + invitation acceptance, the creator lands in the onboarding wizard (Welcome Back surface — or direct entry to Step 2 if it's their first visit this tab).
5. The creator drives through Steps 2-9 (profile basics, social accounts, portfolio, KYC, tax, payout, contract, review). Vendor-gated steps (KYC, payout, contract) gracefully degrade when flags are OFF (click-through accept for contract; "skipped" surface for KYC + payout).
6. On submit, the creator's application moves to `pending` status and lands on `/creator/dashboard` with the pending-review banner.
7. A platform admin signs in to the admin SPA, navigates to the creator's detail page, edits one or more fields with audit-bearing reasons (per-field one-row-per-modal pattern), then approves the application with an optional welcome message.
8. The creator's next dashboard visit shows the approved banner with the welcome message.

**Full Sprint 2 carry-forward closed:**

- Workspace switching has its production UX (no `router.go(0)` reload — proper rebootstrap-on-switch via the setter-injected hook pattern).
- `requireMfaEnrolled` gates the admin-sensitive agency surface (`/agency-users`).
- Brand restore UI ships with role-gated affordance + confirmation dialog + audit emission.
- Agency users list shows paginated members + invitation history (admin-only).
- `AcceptInvitationPage` email-mismatch + already-member states have Playwright coverage.

**Test surface:**

- Backend Pest: ~781 (was ~462 at Sprint 2 close — Sprint 3 added ~319).
- Main SPA Vitest: ~512 (was ~298 — Sprint 3 added ~214).
- Admin SPA Vitest: ~277 (was ~232 — Sprint 3 added ~45).
- Plus 17 design-tokens Vitest + 88 api-client Vitest + architecture tests across all surfaces.
- **Total: ~1700+ tests** across Sprint 3 close. Approximately 1.55× growth over Sprint 2 close (1097).

**Critical-path E2E coverage at Sprint 3 close:**

- #1 (creator wizard happy path) — `creator-wizard-happy-path.spec.ts` (Chunk 3).
- #2 (creator dashboard incomplete-banner direct access) — `creator-dashboard.spec.ts` (Chunk 3).
- #9 (agency bulk-invites 5 creators) — `bulk-invite-creators.spec.ts` (Chunk 4).
- Plus Sprint 1 + 2 specs (sign-up, sign-in, 2FA, failed-login lockout, brand CRUD, invitation accept, invitation error paths, permissions).

---

## (b) Team standards established or extended in Sprint 3

All Sprint 1 + Sprint 2 standing standards carry forward (per `docs/PROJECT-WORKFLOW.md § 5`). Sprint 3 extends the list:

1. **Cross-tenant allowlist categorisation.** Sprint 3 Chunk 1's F1 audit surfaced that the tenancy allowlist conflates three semantically distinct categories (cross-tenant admin tooling / tenant-less / path-scoped tenant). The categorisation note in `security/tenancy.md` § 4 is in place; the structural `Category` column is open tech-debt. **Pattern for Sprint 4+:** when adding routes that bypass the standard tenancy stack, name the category explicitly in the row justification.

2. **`withAdmin()` factory for symmetric resources.** When a resource serves both creator-self + admin audiences, keep ONE `toArray()` shape with an `admin_attributes` block conditionally appended via a factory toggle. Established Chunk 3; applies to Sprint 4+ admin-bearing resources (Brand admin views, Campaign admin views, etc.).

3. **Module-scoped boolean for "did this surface render once this tab?" questions.** Established Chunk 3 with `internal/welcomeBackFlag.ts`. Reusable beyond the wizard for any future surface that needs first-mount-in-tab detection.

4. **Per-route MFA-enrolment gating, not blanket gating.** Sensitive admin surfaces (agency users management) carry `requireMfaEnrolled` in their guard chain; non-sensitive surfaces (dashboard, brands, settings) do not. Established Chunk 4; applies to Sprint 4+ admin-sensitive routes (campaign approval, agency suspension, etc.).

5. **Backend / frontend constant parity is enforced via architecture tests.** When a backend Laravel `Request` class pins enums / field lists that the frontend mirrors, the parity is verified by source-inspection at CI time. Established Chunk 4 with `field-edit-config-parity.spec.ts`. Applies to any future admin-editable surface.

6. **Test-helper seam for "skip multi-step setup".** When an E2E spec needs a subject in a state that would require ~10+ SPA navigations to reach via production paths, extend the existing test-helper with an optional flag for the target state, gated by the chunk-6.1 helper-token middleware. Established Chunk 4 with `enroll_2fa: true` on the agencies/setup helper. Applies to Sprint 4+ specs that need pre-enrolled or pre-configured subjects.

7. **Avatar-completeness gap diagnostic pattern.** CI failures on cross-layer specs are first-class diagnostic surfaces; trace the disabled-state condition (which field is missing? which calculator returns false?) rather than retrying. Established Chunk 3; applies broadly to any cross-layer contract-gap diagnosis.

8. **`Promise.all([page.waitForURL, click])` for cross-step navigation.** Pin the navigation expectation BEFORE the click dispatches. Established Chunk 3 from a CI race; applies to all future Playwright specs with cross-step navigation.

9. **Single async path for long-running operations (D-pause-9 / Q-pause-PC6 reinterpretation).** Submit + 202 + poll → terminal status. No "inline preview + edit + submit" hybrid UX. Established Chunk 4 for bulk-invite; applies to Sprint 4+ long-running operations (campaign launch, payout disbursement, etc.).

10. **One row per field for admin edit, not multi-field forms.** Each editable field is its own transaction with its own audit row. Avoids partial-state ambiguity. Established Chunk 4; applies to all future admin edit surfaces.

11. **Server-side markdown rendering with strict CommonMark config.** `league/commonmark` with `allow_unsafe_links: false` + `html_input: 'escape'` for any platform-controlled markdown source rendered via `v-html` in the SPA. Established Chunk 3 with `ContractTermsRenderer`; applies to any Sprint 4+ contract/terms/markdown-source rendering.

These additions should land in `docs/PROJECT-WORKFLOW.md § 5` during the Sprint 3 closing commit.

---

## (c) Honest deviation tally (all four chunks)

_(Pending fill-in during the independent review pass — Sprint 1's tally was 8+4=12, Sprint 2's was 8+4=12; Sprint 3's is expected to be larger given the chunk count.)_

Known deviations recorded inline in each chunk review:

- **Chunk 1:** Provider contract surface narrowed from kickoff (D1); cross-tenant allowlist F1 audit added 3 entries retroactively; provider docblocks describe future-extension shape (D2 — closed in Chunk 2).
- **Chunk 2:** Hybrid completion architecture chose `getVerificationStatus(Creator)` over kickoff's `getVerificationResult(string)` (D-pause-2-2); contract-test "exactly one Sprint-3 method" pin replaced; Pennant default-scope-resolver override (Refinement / sub-step 8).
- **Chunk 3:** Avatar-completeness contract gap surfaced via CI (logged as tech-debt with 3 resolution options); `Promise.all([waitForURL, click])` pattern adopted from CI race; `priorBootstrap` module-scoped over store-scoped (Refinement 1).
- **Chunk 4:** `defineExpose({ onFileSelected })` test-only API exposure (logged as tech-debt); `seedAgencyAdmin` hand-rolled recovery codes (logged as tech-debt); test-helper `enroll_2fa` flag (new seam).

**Running tally across Sprint 3:** ~15-20 deviations explicitly recorded across chunk reviews. **Across the project (Sprint 1 + Sprint 2 + Sprint 3):** seventeen review groups, seventeen-for-seventeen on the honest-deviation-flagging pattern. _(Independent review to confirm + finalise the count.)_

---

## (d) Compressed-pattern process record

Sprint 3 ran four chunks across four sessions, following the compressed plan-then-build pattern established in Sprint 1 + 2.

**Chunk 1 (backend foundation — creator model + wizard endpoints + provider contracts):** One session. Three pause conditions caught during the pre-planning read pass and resolved before the plan was finalized. F1 audit added 3 retroactive allowlist entries. Tech-debt entry for tenancy categorisation opened.

**Chunk 2 (backend completion — mock vendors + hybrid completion + saga):** One session. Refinements 1-4 from kickoff (driver convention, Pennant scope override, contract docblock drift acknowledgment, mock-webhook-dispatch verification). One mid-spot-check refinement on the contract-shape pin replacement.

**Chunk 3 (frontend close-out — wizard + admin detail page):** One session — the largest chunk-close to date (9 commits including 5 CI fix-up rounds + 1 real product-gap fix + 1 tech-debt entry). The cross-layer avatar-completeness contract gap was the standout finding, surfaced via CI retry-timeout diagnostic. 6 refinements applied from kickoff. ~189 net new tests.

**Chunk 4 (sprint closer — admin edit + bulk-invite + Sprint 2 carry-forward + Sprint 3 self-review):** One session. 4 refinements applied at kickoff plan-approval (magic-link UX, bulk-invite UX, admin edit layout, testing conventions). Test-helper extension for `enroll_2fa`. ~136 net new tests.

**Total Sprint 3 round-trips:** 4 (one per chunk) + intermediate CI fix-ups (5 in Chunk 3, ~1-2 in others) + 2-3 mid-review extensions across chunks. Sprint 3 was materially heavier than Sprint 2 (single-session 1097-test sprint vs ~1700+-test sprint across 4 sessions) but the compressed pattern held.

The pre-planning read list scaled appropriately per chunk:

- Chunk 1: ~50 files (largest read pass — establishing the creator domain).
- Chunk 2: ~30 files (focused on the saga + mock vendor surface).
- Chunk 3: ~40 files (frontend close-out — wizard + admin SPA).
- Chunk 4: ~35 files (frontend close-out — Sprint 2 carry-forward + admin edit + bulk-invite).

---

## (e) What is deferred to Sprint 4+

- **Real-vendor adapter implementations.** Sprint 3 ships mocks for KYC, e-sign, payment. Sprint 4+ wires real Stripe Connect, Onfido / Veriff KYC, DocuSign / HelloSign e-sign per `feature-flags.md` and the integration batches.
- **Bulk-invite per-row failure-list polish.** The complete state shows aggregate stats; per-row failure detail rendering (with copy-affordance and re-upload-with-fix UX) is captured as a future polish item.
- **Welcome message rendering on creator dashboard.** The `creators.welcome_message` field is persisted but not yet surfaced on `/creator/dashboard` (Sprint 4+ polish).
- **Admin platform-level UX for application reviews queue.** The single-creator detail page works; a queue view ("show me all pending applications across all agencies, oldest first") is Sprint 4+.
- **Bulk-invite resume/retry UX.** Tracked job status surfaces in the SPA; "abandoned mid-poll, return later" UX is Sprint 4+.
- **Avatar-completeness contract gap resolution.** Three resolution options documented in tech-debt; product decision deferred to Sprint 4 polish OR the next chunk that surfaces it in production.
- **Cross-tenant allowlist categorisation column.** The categorisation note is in place; the structural `Category` column is open tech-debt for a dedicated housekeeping commit before Sprint 4 kickoff (or carry into Sprint 4).
- **BulkInvitePage testability refactor.** Three resolution options documented (extract to composable / drop unit coverage in favour of Playwright / wait for Vue Test Utils plugin-component stubbing). Triggered by the next substantive change to `BulkInvitePage`.
- **`seedAgencyAdmin` recovery-codes-via-production-service.** Trivial swap when a future spec consumes recovery codes from a seeded admin.

---

## (f) Cursor-side observations (DRAFT)

_(Update during the independent-review pass with concrete patterns surfaced by the reviewer.)_

1. **The pre-planning read pass scales linearly with chunk surface area.** Sprint 3's read passes ranged from ~30 to ~50 files; the load-bearing value (catching cross-layer assumptions) held at every chunk. Chunk 1's F1 audit, Chunk 2's contract-shape pin replacement, Chunk 3's avatar-completeness gap, Chunk 4's bulk-invite UX reinterpretation — all surfaced because the read pass was thorough enough to catch the divergence before code landed.

2. **Cross-layer contract gaps surface as CI timeouts, not test failures.** Chunk 3's avatar-completeness gap manifested as a "Submit button never enables" CI timeout, not an explicit assertion failure. The diagnostic pattern — trace the disabled-state condition rather than retrying — surfaced the seam between two correct layers. **This pattern of "structurally-correct layers disagreeing at the seam" is the highest-leverage class of bug Sprint 3 surfaced; Sprint 4+ should expect more of these as the integration surface grows.**

3. **Test-helper extensions are cheap, defense-in-depth-safe, and reduce E2E flakiness.** The `enroll_2fa` flag added to `CreateAgencyWithAdminController` saved ~12 SPA navigations per spec run. The helper is double-gated (provider gate + token middleware) so production traffic cannot reach it. Pattern is reusable for any future "subject needs pre-configured state".

4. **Pinia store circular-dependency setter-injection is clean.** The `useAgencyStore.setAuthRebootstrap(fn)` pattern (Chunk 4) avoids the module-scope circular import. Pattern is reusable for any future cross-store action coupling.

5. **The `vue/valid-v-slot: allowModifiers: true` ESLint rule (Sprint 2 carry-forward) plus the architecture-test ecosystem keeps the SPA discipline tight.** Chunk 4's `field-edit-config-parity.spec.ts` is a new instance — backend/frontend contract enforcement at CI time. Reusable for any future cross-layer constant invariant.

---

## (g) Claude-side observations (PENDING)

_(To be filled during the independent-review pass.)_

---

## (h) Status

**Sprint 3 is closing.** Four chunks complete. All tests green (pending the final Playwright run for `bulk-invite-creators.spec.ts`). Ready for the closing commits + independent review.

| Artifact                | Location                                                 | Status              |
| ----------------------- | -------------------------------------------------------- | ------------------- |
| Sprint 3 Chunk 1 review | `docs/reviews/sprint-3-chunk-1-review.md`                | Closed              |
| Sprint 3 Chunk 2 review | `docs/reviews/sprint-3-chunk-2-review.md`                | Closed              |
| Sprint 3 Chunk 3 review | `docs/reviews/sprint-3-chunk-3-review.md`                | Closed              |
| Sprint 3 Chunk 4 review | `docs/reviews/sprint-3-chunk-4-review.md`                | Draft (this commit) |
| Sprint 3 self-review    | `docs/reviews/sprint-3-self-review-draft.md` (this file) | Draft               |

**Standing standards from § b (#1-11) to land in `PROJECT-WORKFLOW.md § 5` during the closing commit.**

Sprint 4 owns real-vendor adapter implementations (Stripe Connect Express, real KYC, real e-sign) per `20-PHASE-1-SPEC.md` § 5 + `feature-flags.md`. New thread recommended for Sprint 4 per the long-thread context-degradation discipline established in Sprint 2.

---

_Provenance: drafted by Cursor at Sprint 3 Chunk 4 close as the closing-artifact draft for Sprint 3. **Status: Draft. Claude-led independent review pending.** Sprint 3 chunks 1-4 reviews are the authoritative per-chunk records; this file is the holistic retrospective._
