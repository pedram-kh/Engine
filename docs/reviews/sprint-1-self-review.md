# Sprint 1 Self-Review (closing artifact for Sprint 1)

**Status:** Closed.

**Reviewer:** Claude (independent review pass) — incorporating Cursor's self-review draft.

**Scope:** Closing retrospective for Sprint 1 as a whole. New artifact at sprint scope — pattern-matches `sprint-1-chunk-7-self-review.md` (chunk scope) and `sprint-1-chunk-8-self-review.md` (chunk scope) but at the broader sprint level. Captures Sprint 1's full arc: chunks 1-5 (backend identity foundations); chunk 6 (main SPA auth surface); chunk 7 (admin SPA auth surface); chunk 8 (theme system + Sprint 1 cleanup).

---

## (a) What Sprint 1 produced end-to-end

Sprint 1 took the Sprint 0 scaffolding and turned it into a multi-tenant identity platform with two SPAs feature-complete for auth + routing + guards + i18n + theming. The deliverable:

**Backend (chunks 1-5):**

- Multi-tenancy primitives (`agencies`, `agency_users`, `agency_creator_relations`, tenant scoping).
- Identity layer: `users`, `admin_users`, `sessions`, email verification, password reset, sign-up + sign-in flows for creator + agency + admin variants.
- 2FA: TOTP enrollment + verify + disable + recovery codes; mandatory MFA enforcement model for admins (chunk 7.4).
- Account lockout + rate limiting with `RateLimiter` neutralizer service.
- Audit logging on every state-flipping action with transactional consistency.
- User-enumeration defense across the auth surface.
- Single-error-code-for-non-fingerprinting policy on credential flows.

**Main SPA (chunk 6):**

- Vite + Vue 3 + Vuetify + Pinia + Vue Router + i18n + Sanctum.
- Auth pages: sign-up (creator + agency), sign-in, verify-email, forgot-password, reset-password, 2FA enable + verify + disable.
- Auth layout + locale switcher + error message resolver + loading skeletons.
- Pinia store + per-action loading flags + best-effort refresh.
- Router + guards (requireAuth, requireGuest) + 401 interceptor.
- Playwright E2E: 3 specs covering sign-in happy path + 2FA enrollment + critical-path auth flows.

**Admin SPA (chunk 7):**

- Path-(b) mirror of main SPA's structure with admin-specific divergences.
- Pinia store + i18n bundles (mandatory-MFA strings + no sign-up/forgot-password copy).
- Router + guards + mandatory-MFA enforcement (`requireAuth.mfaEnrollmentRequired` branch + D7 deep-link preservation).
- Auth pages: sign-in, 2FA enable + verify + disable. No sign-up (admin onboarding is out-of-band).
- Test-helper endpoint `POST /api/v1/_test/users/admin` for E2E seeding (admin onboarding can't be driven through production surface).
- Playwright E2E: 2 specs covering sign-in happy path + mandatory-MFA enrollment journey with D7 deep-link preservation. `e2e-admin` CI job mirrors `e2e-main`'s full backend stack.
- Auth module README.

**Theme system (chunk 8):**

- WCAG-AA-validated light + dark palettes in `packages/design-tokens` (the SOT across both SPAs).
- `useTheme` composable mirrored per-SPA (Vuetify-aligned).
- `useThemePreference` composable mirrored per-SPA (localStorage persistence + matchMedia listener mounted only on 'system' preference).
- `<ThemeToggle />` component mirrored per-SPA, mounted in `AuthLayout.vue` + `App.vue` chrome.
- Asymmetric default theme behavior: main = light default, admin = dark default (Sprint 0 scaffolding), with layered fallback (user pref > SPA default > prefers-color-scheme on 'system' opt-in).
- 8 architecture tests per SPA (no hard-coded colors, no inline color styles, useTheme is SOT, plus 3 SOT-boundary extensions).

**Sprint 1 final test count:** 990 tests across all packages (286 main Vitest + 232 admin Vitest + 367 backend Pest + 17 design-tokens Vitest + 88 api-client Vitest).

---

## (b) Team standards established or sharpened across Sprint 1's eight chunks

Sprint 1 established the core team standards that Sprint 2+ inherit. Per `PROJECT-WORKFLOW.md` § 5 + chunk-7 + chunk-8 retrospective additions:

**Foundational standards (chunks 1-5):**

- Source-inspection regression tests for structural invariants (§ 5.1).
- `Event::fake` test split (§ 5.2).
- Real-rendering mailable test pattern (§ 5.3).
- Single error code for non-fingerprinting (§ 5.4).
- Transactional audit on state-flipping actions (§ 5.5).
- Idempotency on state-flipping actions (§ 5.6).
- Constant-verification-count for credential lookups (§ 5.7).
- Reasoned removal of dead code (§ 5.8).
- User-enumeration defense across the auth surface (§ 5.9).
- The review-files workflow itself (§ 5.10).

**SPA foundations (chunk 6):**

- Per-action loading flags + best-effort refresh pattern in Pinia stores.
- Vuetify + Sanctum + i18n + Vue Router integration with auth-aware guards.
- `useErrorMessage` prefix-allowlist resolver with i18n interpolation.
- Test-helper endpoint pattern gated by env + token + provider (chunk 6.1 charter).
- Architecture tests pinning "recovery codes never assigned to Pinia state" (chunk 6.7).

**Cross-SPA conventions (chunk 7):**

- Path-(b) mirror discipline for shared-shape components and composables (chunk 7.2 D2 + chunk 8.1 D4 extension).
- Mandatory-MFA enforcement model: pure guard composition, not a route flag (chunk 7.4).
- D7 deep-link preservation across MFA-enrollment redirect (chunk 7.4 + 7.5 + 7.6 end-to-end).
- Session-cookie boundary: `catalyst_main_session` + `catalyst_admin_session` (chunk 7.4 + 7.6).
- e2e-admin port offsets `+1` for concurrent CI E2E jobs (chunk 7.6).
- Vite proxy target env-overridable for E2E (chunk 7.6).
- Module README pattern with five-section structure (chunk 7.7).
- "File:line citations to main" kickoff discipline (chunk 7 Group 2 review's discipline upgrade).

**Chunk-7.1 hotfix saga durables (chunk 7.1 + applied baseline):**

- Per-spec `auth-ip` rate-limiter neutralization in `beforeEach` + restore in `afterEach`.
- T0 = `Date.now() + 30 days` baseline for any spec pinning the test clock.
- Shared `defaultHeaders` constant (Accept + X-Requested-With) on every API-driven fixture call.
- No parent `data-test` attribute fall-through to a child component with its own root `data-test`.
- Backend `meta.seconds` envelope shape for retry-after error codes.
- Prefix-allowlist error resolver in `useErrorMessage`.
- Consuming-page reminder comment block at the invocation site for pitfalls invisible at call-site.

**Theme + preferences (chunk 8):**

- Runtime WCAG contrast computation via `colord/plugins/a11y` (no hard-coded ratios).
- Architecture test scope via `fs.readdir` recursive walk (natural exclusions by directory + extension).
- Negative lookahead `(?!var\()` permits CSS-variable consumption while catching literal colors.
- Empty allowlist as preferred starting state when audit confirms compliance (chunk 8.1 D5).
- Same-chunk migration of framework deprecations + architecture test as regression protection (chunk 8.1 D6).
- Module-scoped singleton composables for SPA-level state.
- Lazy reactive-listener attachment when conditional on user opt-in.
- Defensive coding against browser API unavailability for browser-API composables.

**Process patterns sharpened through Sprint 1:**

- Compressed pattern (plan-then-build in one pass; single completion artifact per group; pre-answered Q1-Q3 with explicit pause conditions).
- Aggressive grouping (Option A pattern): mirror work bundled, design work isolated, closing work bundled.
- Honest deviation flagging with category labels: paraphrase-vs-actual, structurally-correct admin adaptation, phase-deferred mirror, tech-debt-flagged carry-forward, structurally-correct minimal extension, structurally-correct test-side adaptation.
- Cursor-solo debugging loop for post-commit CI red (Claude reconnects on scope-boundary or once CI green).
- Empirical regression-catching verification via break-revert pattern (chunk 8 baseline).
- Test-run-first feedback loop (chunk 8 baseline).
- Design Qs in kickoff vs pre-answered Qs (chunk 8 Group 2 introduced; depends on whether decision has genuine user-facing trade-offs).

---

## (c) Running tally of honest-flagging across all chunks

The honest-deviation-flagging pattern is the most reliable workflow output of Sprint 1.

**Eleven review groups (excluding chunk 1-5 individual chunks before the grouping pattern stabilized); every single group surfaced at least one hidden assumption.**

| Group                            | Deviations            | Notes                                                                                               |
| -------------------------------- | --------------------- | --------------------------------------------------------------------------------------------------- |
| Chunk 6.1                        | 1+                    | Test-helper charter establishment surfacing                                                         |
| Chunks 6.2-6.4                   | 1+                    | Main store + best-effort refresh                                                                    |
| Chunks 6.5-6.7                   | 1+                    | Main router + guards + pages                                                                        |
| Chunks 6.8-6.9                   | 1+                    | Main E2E + module README                                                                            |
| Sub-chunk 7.1                    | 9 hotfix-as-deviation | The chunk-7.1 saga; each hotfix a hidden assumption broken by CI                                    |
| Chunk 7 Group 1 (7.2-7.3)        | 6                     | Admin store + i18n; triggered "verify main's actual implementation" discipline upgrade              |
| Chunk 7 Group 2 (7.4)            | 9                     | Admin router + guards + MFA; triggered "file:line citations to main" discipline upgrade             |
| Chunk 7 Group 2 hotfix (4282464) | 1                     | Smoke spec selector drift                                                                           |
| Chunk 7 Group 3 (7.5-7.7)        | 3                     | Admin pages + E2E + README; first group with zero paraphrase-vs-actual after the discipline upgrade |
| Chunk 8 Group 1 (8.1)            | 3                     | Theme foundations; two scope-reducing (foundations existed more than anticipated)                   |
| Chunk 8 Group 2 (8.2)            | 2                     | Theme consumers + preferences; both test-side adaptations                                           |
| **Total**                        | **~36**               | **Eleven-for-eleven on the pattern**                                                                |

The DECREASING trend in deviation count per group (from 9 in chunk 7.4 down to 2-3 in chunk 8) reflects the kickoff-writing discipline improving. The FREQUENCY remains 100% — every group catches at least one hidden assumption. **Sprint 2 should expect this pattern to continue.**

**The two kickoff-writing discipline upgrades that drove the deviation-count reduction:**

1. **"Verify main's actual implementation before specifying admin's mirror"** (introduced after chunk 7 Group 1). Put the responsibility on Cursor to read main's source before accepting the kickoff's paraphrase.

2. **"File:line citations to main in kickoffs"** (introduced after chunk 7 Group 2). Put the responsibility on Claude to write specifications with explicit citations rather than paraphrase that would need verifying.

**Sprint 2 onward defaults to both:** file:line citations from Claude + verification against main's implementation from Cursor.

---

## (d) Compressed-pattern process record across all of Sprint 1

**Round-trip counts:**

- Chunks 1-5 (backend identity): pre-compressed-pattern era; chunk-by-chunk review without grouping.
- Chunk 6: ~15 round-trips across nine sub-chunks; the standards-establishment phase.
- Chunk 7: 6 substantive review rounds across three groups + 1 Cursor-solo hotfix round = 7 total. Estimated 15-18; came in at ~40% of estimate.
- Chunk 8: 2 substantive review rounds across two groups = 2 total. Estimated 5-8; came in at ~25% of estimate.

**Trajectory:** chunk 6 was the highest-cost chunk (standards-establishment); chunks 7 + 8 were progressively more efficient as the standards stabilized. Sprint 2 onward should track closer to chunk 8's cadence than chunk 6's.

**Hotfix tally:** 12 chunk-7 hotfixes (heavily skewed toward 7.1's saga with 9; 1 in Group 2; 2 in Group 3); 4 chunk-6 hotfixes. **Zero hotfixes in chunk 8.** The chunk-7.1 saga lessons paid forward — chunk-7.6 shipped without replaying any of them; chunk 8 had zero hotfix loops.

**Zero change-requests on the last six consecutive review groups** (chunk 7's sub-chunk 7.1 close + Group 1 + Group 2 + Group 3 + chunk 8's Group 1 + Group 2). The workflow stabilized.

---

## (e) What is deferred to Sprint 2+

**Sprint 2 (brands + agency UI + nav shell):**

- Agency layout shell (sidebar, top bar, workspace switcher).
- Brand CRUD endpoints + UI (`/api/v1/agencies/{agency}/brands`).
- Brand detail view in main SPA.
- Per-agency settings page (basic — defaults like currency, language).
- Agency user invitation flow.
- Theme toggle + locale switcher migration to the new user-menu surface (the `<ThemeToggle />` and locale switcher are positioned for this — no refactoring needed).

**Sprint 3+ (creator surface):**

- Creator self-signup wizard (8 steps).
- Creator dashboard + profile completeness.
- Bulk roster invitation (CSV).
- Creator approval workflow (Sprint 4).
- Creator availability calendar (Sprint 5).
- Creator matching (Sprint 6).

**Sprint 8+ (campaign + assignment surface):**

- Campaigns + assignments (Sprint 8 — heart of the platform).
- Drafts + review (Sprint 9).
- Payments via Stripe Connect (Sprint 10).
- Messaging (Sprint 11).
- Boards + automation (Sprint 12).

**Sprint 3+ (admin substantive console):**

- Admin dashboard body content.
- Admin settings page substantive UI.
- Admin sign-out UI button.
- Creator onboarding admin views + brand management admin views.

**Sprint 3+ (multi-tenancy + role-policy hardening):**

- `AdminRole` field expansion from placeholder `'platform_admin'`.
- Audit logging on admin sign-in (hook point exists; emission is Sprint 3+).
- Per-role admin route tables.

**Sprint 8 (Postgres-CI for Pest):**

- Pest unit-test suite currently runs against SQLite in-memory; admin SPA's E2E job is the second instance running against Postgres in CI.

**Open tech-debt entries carried forward to Sprint 2+:**

- Light primary/on-primary AA-normal failure (2.49:1) — chunk 8.1 entry with four resolution paths.
- Broader `tokens.css` `--color-*` system — narrowed chunk 8.2 entry.
- Idle-timeout unwired on both SPAs — chunk 7.4 D6.
- Vue 3 attribute fall-through architecture test — chunk 7.1.
- SQLite-vs-Postgres CI for Pest — pre-chunk-7.
- TOTP issuance does not honor `Carbon::setTestNow()` — pre-chunk-7.
- `auth.account_locked.temporary` `{minutes}` interpolation gap — pre-chunk-7.
- Laravel exception handler JSON shape for unauthenticated `/api/v1/*` — chunk-7.1.
- Test-clock × cookie expiry interaction structural fixes — chunk-7.1.

---

## (f) Cursor-side observations

**Sprint 1 was a foundations sprint.** The deliverable looks like infrastructure rather than features — that's by design. Sprint 2 owns the first visible substantive surface (agency layout + brand CRUD + user invitation flow); the Sprint 1 infrastructure (auth + i18n + theming + routing + guards + multi-tenancy primitives + test infrastructure) is what makes Sprint 2 buildable in 1.5 weeks instead of months.

**The compressed pattern + Option A grouping + the discipline upgrades + Cursor-solo debugging loops compounded.** Chunk 6 was 15 round-trips; chunk 8 was 2. The compounding is the result of multiple small process refinements (compressed pattern from chunk 6; aggressive grouping from chunk 7; file:line citations from chunk 7 Group 2; empirical regression-catching from chunk 8) each multiplying the effectiveness of the others.

**The chunk-7.1 hotfix saga was the highest-cost single event in Sprint 1.** Nine hotfixes for one closure commit. The saga's findings became durable conventions applied from the first commit in chunk-7.6 (no replay) and chunk-8 (no E2E specs added, so most conventions weren't exercised, but the patterns are baseline). **The cost paid forward.** Sprint 2's E2E specs will continue applying the saga conventions from day one.

**The honest-deviation-flagging pattern is the most reliable workflow output.** Eleven-for-eleven across Sprint 1. Sprint 2 will likely produce 1-3 deviations per group depending on the kickoff's accuracy — the pattern doesn't end with Sprint 1.

---

## (g) Claude-side observations

Endorsing Cursor's (f) observations; adding the reviewer-side perspective.

**Sprint 1's most valuable outcome isn't the code — it's the workflow.** The combination of (compressed pattern + Option A grouping + honest deviation flagging with category labels + file:line citations + disciplined self-correction + empirical regression-catching + load-bearing spot-check selection + design Qs in kickoff vs pre-answered Qs + Cursor-solo debugging loops) is now a durable, repeatable process. Sprint 2's velocity will be a function of how well this workflow continues to be applied.

**The discipline upgrades are the highest-leverage process refinements.** Two were introduced in Sprint 1 ("verify main's actual implementation" after chunk 7 Group 1; "file:line citations to main" after chunk 7 Group 2). Each one targeted a specific class of deviation that had been frequent, and the next group validated whether the upgrade worked. **Sprint 2 should expect 0-2 new discipline upgrades to surface naturally as the work changes shape (the standards-application phase produces different deviation patterns than the foundation-establishment phase).**

**The "every group catches at least one hidden assumption" pattern is load-bearing as a team standard.** Eleven-for-eleven. The pattern's reliability is what makes the kickoff-writing discipline upgrades tractable — each upgrade addresses a specific category, and the next group's deviations either validate the upgrade or surface the next category to address. **Sprint 2 onward depends on Cursor continuing to flag honestly rather than pattern-matching to the kickoff blindly.**

**The chunk-7.1 hotfix saga's cost-benefit math is genuinely good in retrospect, though it was hard to see during the saga.** Nine hotfixes for durable conventions that paid forward across every E2E spec since. The lesson worth recording: **process trauma that produces durable conventions is worth the cost; process trauma that produces ad-hoc fixes is not.** The saga produced the former because the team was disciplined about converting each hotfix into a durable convention applicable to future work.

**Sprint 1 reached genuine workflow stability.** Six consecutive review groups closed with zero change-requests. This isn't because the work is uniformly clean — three of those groups had hotfixes after CI ran, two of them had honest deviations that warranted attention, all of them had spot-checks I scrutinized carefully. It's because the workflow surfaces issues at the right place and the right time: pre-commit during spot-check, post-commit during Cursor-solo CI debugging. **Sprint 2 should preserve this combination as-is.**

**Sprint 2 will produce a different shape of work.** Brands + agency UI + nav shell is feature work, not infrastructure work. Cursor's deviations will likely shift from "paraphrase-vs-actual" toward "structurally-correct adaptation" + "scope clarification" + "consumer surface implication" — the deviation categories from infrastructure chunks gave way to product-decision categories. **The honest-deviation-flagging pattern will adapt; the workflow scaffolding stays.**

**One framing for Pedram's decision-making in Sprint 2:** the Sprint 1 workflow is now a durable asset. Treat it as one. Sprint 2 doesn't need to relearn the lessons of chunks 6-7; it inherits them as baseline.

---

## (h) Status

- Sprint 1 fully closed.
- Sprint 2 ready to start: brands + agency UI + nav shell.

The Sprint 1 deliverable: two SPAs feature-complete for auth + routing + guards + i18n + theming; backend identity layer complete; 990 tests across the codebase; the workflow itself as a durable, repeatable asset.

---

_Provenance: drafted by Cursor as the closing artifact for Sprint 1 (Group 2 of chunk 8's compressed-pattern process — three structured drafts per `PROJECT-WORKFLOW.md` § 3 step 6, modified). Claude-side observations added on independent review pass. **Status: Closed. Sprint 1 is done.**_
