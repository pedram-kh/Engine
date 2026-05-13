# Sprint 1 — Chunk 6 Plan Approved (Checkpoint)

**Status:** Closed — all 9 sub-chunks shipped, see review files for details.
**Sub-chunks closed:** 6.1 (commit f8f4940), 6.2–6.4 (commit 2e4bb7f, hash-drift correction cda780e), 6.5–6.7 (commit bbf0ca2), 6.8–6.9 (commit 7341340).
**Approved at:** Start of Sprint 1 Chunk 6 (Main SPA auth UI)

**Final closure:** Sprint 1 sub-chunk 7.1 (commit e04bf75) closes the chunk-6 hotfix saga (throttle-vs-lockout overlap, `useErrorMessage` resolver taxonomy gap, Playwright specs #19 + #20 active) and meets Sprint 1's E2E acceptance criterion for the auth surface — see `sprint-1-chunk-7-1-review.md`.
**Hotfix saga closure:** Commit `b715cfa` (auth-ip neutralisation in specs #19/#20) is the terminal hotfix in the chunk-7.1 post-merge series; CI run [25689460184](https://github.com/pedram-kh/Engine/actions/runs/25689460184) is the durable green-CI proof. See the post-merge addendum in `sprint-1-chunk-7-1-review.md` for the full nine-hotfix lineage and root-cause breakdown.
**Chunk 7 progress:** Group 1 (sub-chunks 7.2 + 7.3 — admin SPA Pinia auth store + admin SPA i18n bundle) closed in commit `d2ec623`. See `sprint-1-chunk-7-2-to-7-3-review.md`. Group 2 (sub-chunk 7.4 — admin SPA router + guards + mandatory-MFA enforcement) closed in commit `5bf881a`. See `sprint-1-chunk-7-4-review.md`; Group 3 (sub-chunks 7.5 + 7.6 + 7.7 — admin auth pages + admin E2E + admin module README + chunk 7 self-review) is the next session.
**Chunk 8 progress:** Group 1 (theme foundations — light + dark Vuetify themes with WCAG-AA dark palette, `useTheme` composable mirrored per-SPA, architecture tests for token-usage discipline) closed in commit `29c0726`. See `sprint-1-chunk-8-group-1-review.md`. Group 2 (theme consumers + user preferences + Sprint 1 cleanup) is the next session.

## Chunk 7 closure

**Status:** Closed — all 7 sub-chunks shipped (7.1 + 7.2-7.3 + 7.4 + 7.5-7.7), see review files for details.
**Final group:** Group 3 (sub-chunks 7.5 + 7.6 + 7.7 — admin auth pages + admin E2E specs + e2e-admin CI extension + admin auth module README + chunk-7 self-review) closed in commit `d457cb1`. See `sprint-1-chunk-7-5-to-7-7-review.md` (merged Group 3 review) and `sprint-1-chunk-7-self-review.md` (chunk-7 closing retrospective).
**Sprint 1 admin-side scope:** Complete. Admin SPA is feature-complete for Sprint 1's acceptance criteria (auth pages, mandatory-MFA enforcement with D7 deep-link preservation, E2E coverage of the critical auth flows, full e2e-admin CI stack mirroring e2e-main).
**Next:** Sprint 1 proceeds to chunk 8 (theme system + preferences).

## Sprint 1 closure

**Status:** Sprint 1 fully closed — all 8 chunks shipped.
**Final chunk:** Chunk 8 closed across two groups — Group 1 (theme foundations) in commit `29c0726`; Group 2 (theme consumers + user preferences + `<ThemeToggle />` + system-default detection + Sprint 1 cleanup) in commit `637cc1b`. Closing artifacts: `sprint-1-chunk-8-group-2-review.md` (Group 2 merged review), `sprint-1-chunk-8-self-review.md` (chunk 8 retrospective), `sprint-1-self-review.md` (Sprint 1 retrospective).
**Sprint 1 acceptance per `20-PHASE-1-SPEC.md` § 5:** All criteria met (creator can sign up; agency admin can sign in; admin can sign in to admin SPA; 2FA works for admin; cross-tenant access tests pass; audit log captures auth events; all tests green across 367 Pest + 286 main Vitest + 232 admin Vitest + 18 design-tokens specs; lint + typecheck clean repo-wide).
**Tech-debt register at close:** 6 entries open with named trigger conditions and owner sprints; 8 entries closed across Sprint 1.
**Next:** Sprint 2 — brands + agency UI + nav shell per `20-PHASE-1-SPEC.md` § 5 Sprint 2.

This is a checkpoint file for chat-session resumption. Sprint 1 Chunk 6 is unusually large (9 sub-chunks) so the approved plan and the Q-and-A answers are recorded here durably.

---

## Plan structure

Cursor produced a 9-sub-chunk decomposition for chunk 6:

- **6.1** — Backend additions (api-side, surgical): `GET /api/v1/me` + test-helpers module
- **6.2** — API client auth surface (`packages/api-client/src/auth.ts`)
- **6.3** — i18n auth namespace + error-code coverage test
- **6.4** — `useAuthStore` (Pinia)
- **6.5** — Router, guards, idle composable, 401 interceptor
- **6.6** — Primary auth pages + AuthLayout + onboarding placeholder + minimal user shell
- **6.7** — 2FA enroll + challenge + recovery codes UI
- **6.8** — Playwright specs #19 + #20 + supporting setup
- **6.9** — Auth module README + lint/typecheck/coverage final + draft self-review

Sub-chunks group for review at:

- **6.1 alone** (backend changes — security-critical, separate review)
- **6.2-6.4** (api-client + i18n + store — the data layer)
- **6.5-6.7** (router + pages + 2FA UI)
- **6.8-6.9** (E2E + docs + final)

---

## Q-and-A: Three questions answered

### Q1 — `GET /api/v1/me` does not exist yet. Should it land in chunk 6.1?

**Answer: Yes.** Add `GET /api/v1/me` in sub-chunk 6.1, mounted on both `auth:web` AND `auth:web_admin` guards so chunk 7 (admin SPA) reuses the same endpoint without controller duplication. Same `UserResource` shape. Apply the `tenancy.set` middleware to the route (no-op for admin / creator users per chunk 3, populates context for agency users).

Reasoning: the login response covers the warm-path case, but `/me` is required for cold-load (hard refresh with existing session cookie), refreshing user state after MFA enable/disable, and the upcoming `PATCH /me/preferences` endpoint in chunk 8.

### Q2 — Test-helpers route group for E2E testing?

**Answer: Yes** to the env-gated + token-gated test-helpers pattern, with three refinements:

1. **Add a third helper:** `POST /api/v1/_test/totp` accepting `{user_id}` and returning `{code}` derived from the user's `two_factor_secret` via `TwoFactorService` (the only path to google2fa internals — preserves the chunk 5 isolation invariant). Goes through the same env+token gating.

2. **Clock middleware:** stores test-now timestamp in Redis (key `test:clock:current`); a middleware registered ONLY when `TEST_HELPERS_TOKEN` env var is set reads the key on every request and calls `Carbon::setTestNow()` if present. Reset endpoint deletes the key. Same belt-and-suspenders gating as the routes themselves.

3. **`TEST_HELPERS_TOKEN` rotation:** hard-coded in `.env.example` is fine for local dev. For CI, the token must be generated per-run as a random value and injected into both the API container's env and the Playwright runner's env. Document this distinction in the test-helpers module README so a future engineer doesn't ship a known token to staging by accident.

Reasoning: this is the standard pattern (Dusk, Telescope test mode use the same approach). It keeps E2E tests hermetic without parsing real outbound mail or sleeping through real time windows.

### Q3 — Where does `AuthLayout.vue` live?

**Answer: `apps/main/src/modules/auth/components/`** (NOT `packages/ui/`). When chunk 7 lands and there's clear duplication with the admin SPA's auth layout, we can extract to `packages/ui/` then. Premature abstraction now costs more than eventual extraction.

---

## One adjustment on priority #4 (recovery codes 5-second gate)

The 5-second timer for the "Continue" button after recovery codes are shown should count down **visibly** (e.g., "Continue in 5... 4... 3...") so users understand it's intentional UX, not a broken button. After the timer elapses, the button enables and the label changes to "Continue". Checkbox requirement still applies. Component test asserts the countdown text appears.

---

## Workflow note

Cursor's chunk 6 is the first chunk built under the established merge-review workflow from the start. Cursor will:

- Build sub-chunk 6.1 to completion
- Pause and bring 6.1 to Claude
- Then build 6.2-6.4 and bring to Claude as a group
- Then 6.5-6.7 as a group
- Then 6.8-6.9 as a final group

At each review checkpoint, Cursor produces a chat completion summary AND a draft review file, per `docs/PROJECT-WORKFLOW.md` § 3.

---

_This checkpoint file exists because Sprint 1 chunk 6 spans multiple chat sessions (the Sprint 0 + Sprint 1 chunks 1-5 chat is being closed and a new chat will pick up at chunk 6.1 review). The new chat needs to know what plan was approved and what the open questions' answers were. Without this checkpoint, the resumption would have to recover the answers from chat history that may no longer be available._

---

## Sprint 2 progress

Sprint 2 Chunk 1 (backend: brands CRUD + agency user invitations + agency settings + test-helper) closed 2026-05-13.
Commit: `b37557a` — `feat(brands): backend — CRUD + invitations + agency settings (sprint 2 chunk 1)`
452 Pest tests (367 Sprint 1 baseline + 85 new). PHPStan level 8 clean. Review: `docs/reviews/sprint-2-chunk-1-review.md`.
Chunk 2 (frontend) is next.

## Sprint 2 closure

**Status:** Sprint 2 fully closed — both chunks shipped and reviewed.
**Chunk 1 commit:** `b37557a` — backend (brands CRUD + invitations + agency settings + test-helper infrastructure).
**Chunk 2 commit:** `85494f7` — frontend close-out (agency layout shell, brand CRUD UI, invitation UI, settings UI, E2E specs, useAgencyStore, requireAgencyAdmin guard, PROJECT-WORKFLOW.md § 5 standards 5.11–5.20).
**Closing artifacts:** `docs/reviews/sprint-2-chunk-2-review.md` (Chunk 2 merged review), `docs/reviews/sprint-2-self-review.md` (Sprint 2 retrospective). Both closed by Claude — no change requests.
**Test counts at close:** 992 tests green (462 Pest + 298 main Vitest + 232 admin Vitest). Lint, typecheck, Pint all clean.
**Next:** Sprint 3 — dashboard, creators, campaigns per `20-PHASE-1-SPEC.md` § 5 Sprint 3.

---

## Sprint 3 progress

Sprint 3 Chunk 1 (creator-domain backend foundation: 8 new tables + tracked_jobs, 9 Eloquent models with Audited/Encrypted casts, CreatorPolicy, CreatorBootstrapService called from SignUpService transaction, 8 wizard step endpoints + GET /me bootstrap, CompletenessScoreCalculator, AvatarUploadService + PortfolioUploadService, 3 provider contracts with Deferred stubs, 4 MinIO disks, reusable TrackedJob infrastructure with GET /api/v1/jobs/{job}, bulk-invite pipeline with CSV parser + queued job + ProspectCreatorInviteMail, InvitationPreviewController with no-email response shape per #42, in-controller authorizeAdmin pattern per D-pause-9, 15+ new audit-action enum cases, tenancy.md allowlist extension, 6 doc fix-ups, 5 tech-debt entries) closed 2026-05-13.
Commit: `2376488` — `feat(creators): backend foundation — wizard/bulk-invite/providers/tracked-jobs (sprint 3 chunk 1)`
597 Pest tests passing (1816 assertions; 462 Sprint 2 baseline + ~135 new). PHPStan level 8 clean. Pint clean (sandbox; CI authoritative per #41). Review: `docs/reviews/sprint-3-chunk-1-review.md` (Status: Ready for review).
Chunk 2 (provider mocks + complete wizard side-effects + accept-invite flow) is next.
