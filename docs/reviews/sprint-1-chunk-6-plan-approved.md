# Sprint 1 — Chunk 6 Plan Approved (Checkpoint)

**Status:** Closed — all 9 sub-chunks shipped, see review files for details.
**Sub-chunks closed:** 6.1 (commit f8f4940), 6.2–6.4 (commit 2e4bb7f, hash-drift correction cda780e), 6.5–6.7 (commit bbf0ca2), 6.8–6.9 (commit 7341340).
**Approved at:** Start of Sprint 1 Chunk 6 (Main SPA auth UI)

**Final closure:** Sprint 1 sub-chunk 7.1 (commit e04bf75) closes the chunk-6 hotfix saga (throttle-vs-lockout overlap, `useErrorMessage` resolver taxonomy gap, Playwright specs #19 + #20 active) and meets Sprint 1's E2E acceptance criterion for the auth surface — see `sprint-1-chunk-7-1-review.md`.
**Hotfix saga closure:** Commit `b715cfa` (auth-ip neutralisation in specs #19/#20) is the terminal hotfix in the chunk-7.1 post-merge series; CI run [25689460184](https://github.com/pedram-kh/Engine/actions/runs/25689460184) is the durable green-CI proof. See the post-merge addendum in `sprint-1-chunk-7-1-review.md` for the full nine-hotfix lineage and root-cause breakdown.
**Chunk 7 progress:** Group 1 (sub-chunks 7.2 + 7.3 — admin SPA Pinia auth store + admin SPA i18n bundle) closed in commit `d2ec623`. See `sprint-1-chunk-7-2-to-7-3-review.md`; Group 2 (sub-chunk 7.4 — admin router + guards + mandatory-MFA flow) is the next session.

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
