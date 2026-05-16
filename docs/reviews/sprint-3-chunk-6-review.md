# Sprint 3 — Chunk 6 Review

**Status:** Closed.

**Reviewer:** Claude (independent review) — single-chunk follow-up triggered by two related real-user failures on the admin SPA login flow.

**Commits:**

- **work commit** (commit subject: `fix(identity): unblock admin login + reject SPA/user-type mismatches with auth.wrong_spa`).
- **docs commit** (commit subject: `docs(reviews): close sprint 3 chunk 6 + correct local-dev XSRF-isolation note`).

**Reviewed against:** `04-API-DESIGN.md` § 4 (auth surface) + § 8 (canonical error envelope), `05-SECURITY-COMPLIANCE.md` § 3 (audit) + § 6 (auth ordering), `PROJECT-WORKFLOW.md` § 5 standards #5.4 (non-fingerprinting codes) + #34 (architecture tests) + #50 (env-isolation), `02-CONVENTIONS.md` § 1 (modular monolith), `07-TESTING.md` § 4 (test discipline), `runbooks/local-dev.md` § 2 (cookie isolation contract — corrected by this chunk), Sprint 3 Chunk 5 review (closely related: that chunk fixed `ValidationException` envelopes; this chunk fixes the auth-side envelope flow), Sprint 1 Chunk 7.2-7.3 review (admin SPA bundle parity).

This chunk closes two genuine bugs that surfaced during local QA of the seeded `super@catalyst-engine.local` account. They were independent root causes that combined to produce one confusing surface error (admin SPA reports a generic _"Something went wrong. Please try again."_ banner with no console signal and no observable backend audit row), so they are landed together. Both bugs predate chunk 4; both were invisible to CI because no test exercised the real-browser CSRF-preflight session-isolation path or the cross-guard user-type combination.

---

## Scope

### The two bugs (root cause)

**Bug A — CSRF preflight session-cookie mismatch (admin SPA login blocker):**

- `apps/api/app/Modules/Identity/Http/Middleware/UseAdminSessionCookie::shouldApply()` only fired on request paths starting with `api/v1/admin/`. The Sanctum CSRF preflight at `GET /sanctum/csrf-cookie` is a top-level route _not_ under the admin prefix, so it always ran under the main session cookie (`catalyst_main_session`).
- Laravel CSRF tokens are stored _in the session itself_, not in a free-standing token store keyed by cookie name. So the `XSRF-TOKEN` cookie that Sanctum issued from a main-session-bound preflight carried the token for the _main_ session.
- The follow-up `POST /api/v1/admin/auth/login` (which _does_ match the admin prefix → flips to `catalyst_admin_session`) then booted a fresh admin session with its own CSRF token, compared the inbound `X-XSRF-TOKEN` header (still carrying the main-session value) against the admin session's stored token, found a mismatch, and 419'd before reaching the controller.
- The SPA's `useErrorMessage` resolver rejects unknown error codes (`http.invalid_response_body` from `ApiError.fromEnvelope` on a non-envelope 419 body), so the user saw the generic `auth.ui.errors.unknown` → _"Something went wrong. Please try again."_ banner with no further detail.
- The previously-believed contract — _"Sanctum issues a single `XSRF-TOKEN` cookie regardless of guard; the cookie is shared between SPAs because they share an origin"_ (`docs/runbooks/local-dev.md` § 2 pre-chunk-6) — was wrong. The cookie _name_ is shared, but the _value_ is bound to the session that minted it. The doc is corrected in this chunk.

**Bug B — Login routes accept any user type on either SPA:**

- `AuthService::login()` ran credential + suspension + MFA checks but never inspected `user.type` against the guard the controller mapped to. So a `platform_admin` (`super@catalyst-engine.local`) could enter their credentials on `apps/main`, authenticate cleanly, attach to the `web` guard, and land on a UI that wasn't designed for them (no agency membership, empty data lists, no theming). Symmetric problem on the admin SPA: a `Creator` or `AgencyUser` would silently succeed on `web_admin` and reach a console they have no permissions for.
- The bug is more visible than dangerous — every downstream policy / `BelongsToAgency` scope / admin-MFA-required middleware would still reject API calls — but the broken-shell UX wastes the user's time and presents a "your data is missing" experience that looks like data loss. Sprint 3 chunk 4 made this latent: pre-chunk-4 the seeded `super@` user didn't exist, so nobody could trigger it.

### What this chunk lands

**Backend — admin CSRF preflight (1 file widened, 1 test file extended):**

- `UseAdminSessionCookie::shouldApply()` now matches **two** request shapes: (a) the existing path-prefix rule on `api/v1/admin/*` and (b) a new origin-detection rule on `sanctum/csrf-cookie` requests. The origin rule reads `Origin` first (modern browsers populate this on all cross-origin fetches + most same-origin POSTs) and falls back to `Referer` when `Origin` is absent. The expected value is `config('app.frontend_admin_url')`, which already drives CORS and Sanctum stateful-domain config — single source of truth, no new env knob. Trailing-slash differences between header and config value are normalised away.
- Fail-closed posture: if `config('app.frontend_admin_url')` is empty (mis-configured env), the gate never widens. Today's main-session-only behaviour is the safe default, and the doc + tests pin it.
- 6 new cases added to `TwoSpaCookieIsolationTest`: admin-origin Origin matches → apply, main-origin Origin → don't apply, no Origin and no Referer → don't apply, Referer-only fallback → apply, trailing-slash tolerance → apply, only the csrf-cookie path gets the widening (admin-origin Origin on `/api/v1/health` still doesn't fire).

**Backend — WrongSpa gate (5 files touched, 1 new test file):**

- `LoginResultStatus::WrongSpa` new enum case (`apps/api/app/Modules/Identity/Services/LoginResultStatus.php`).
- `LoginResult::wrongSpa(User $user)` new factory (`apps/api/app/Modules/Identity/Services/LoginResult.php`).
- `AuthService::login()` gains a new step **6** between the suspension check (existing step 4 / 5) and the MFA gate. The check consults a private `const SPA_USER_TYPE_ALLOW_LIST` mapping guards to user types: `web` accepts `Creator + AgencyUser`; `web_admin` accepts `PlatformAdmin`; `BrandUser` is reserved for Phase 2 and intentionally absent everywhere (no SPA serves it today, so a brand-user attempting either side falls through to WrongSpa with no special branch). Unknown guards (anything outside `web` / `web_admin` — e.g. future API-token auth) **fail open**: the check is specifically about the two-SPA topology and doesn't apply to token flows.
- Gate ordering is security-relevant: it sits **after** credential + suspension verification on purpose. A 403 wrong-SPA response on a wrong-password or suspended-account probe would let an unauthenticated attacker enumerate "this email belongs to a platform admin" by walking the wrong SPA. Two precedence tests (one for invalid_credentials, one for account_locked.suspended) pin the order.
- `LoginController` maps `WrongSpa` to a 403 envelope with `code: 'auth.wrong_spa'`, `title` from `trans('auth.login.wrong_spa')`, and `meta.correct_spa_url` carrying the _other_ SPA's URL (resolved via `config('app.frontend_main_url')` / `config('app.frontend_admin_url')`). The SPA can use the meta URL to render a one-click "go to the right login page" link in a future UX-polish pass; the current bundle entry is plain text only.
- No `failed-login` counter increment on this branch — credentials were correct, just on the wrong SPA. A `LoginFailed` event _is_ dispatched (with `reason: 'wrong_spa'`) so the audit trail records the attempt for observability; this mirrors how `mfa_required` emits LoginFailed without locking the account.
- No session attachment, no rehash, no MFA challenge on the WrongSpa branch — the wrong-side flow leaves zero side effects on the user's account row, including `last_login_at` (pinned by test).

**Backend — translations (3 files):**

- New `auth.login.wrong_spa` key in `apps/api/lang/{en,pt,it}/auth.php` carrying the user-facing message: _"This account is not registered for this site. Please sign in on the correct site."_ (plus translated equivalents). The same neutral message intentionally applies regardless of which SPA the user landed on, because the `meta.correct_spa_url` carries the navigation; the title is a fallback for tooling that surfaces only `errors[].title`.

**Frontend — i18n bundle parity (12 files):**

- `auth.wrong_spa` (top-level, used by the SPA's `useErrorMessage` resolver since `error.code` is `auth.wrong_spa`) and `auth.login.wrong_spa` (nested, mirrors the backend `trans()` call so the i18n-auth-codes architecture test resolves) added to **all six locale bundles**:
  - `apps/main/src/core/i18n/locales/{en,pt,it}/auth.json` — agency-SPA copy points users to the admin console.
  - `apps/admin/src/core/i18n/locales/{en,pt,it}/auth.json` — admin-SPA copy points users to the agency console.
- The per-SPA copies are not generic mirrors: each side names the _other_ SPA explicitly ("This account is registered for the admin console" vs. "This account is not a platform admin. Please sign in on the agency console instead") so the user doesn't have to interpret the `meta.correct_spa_url` to understand what to do.
- The architecture test at `apps/{main,admin}/tests/unit/architecture/i18n-auth-codes.spec.ts` walks every `auth.*` literal in `apps/api/app/Modules/Identity/**/*.php` and asserts each is resolvable in all three locales of each SPA. Both new keys ride the existing test surface — no test changes required, drift is caught automatically.

**Tests — `WrongSpaGateTest` (8 new feature cases):**

- platform*admin → main SPA → 403 `auth.wrong_spa` with correct `meta.correct_spa_url`, no session attached, no `UserLoggedIn` event, `LoginFailed` event \_is* dispatched with `reason === 'wrong_spa'`.
- Same rejection branch — no `last_login_at` stamp.
- Symmetric: agency-user → admin SPA → 403 `auth.wrong_spa` with the main-SPA URL in meta.
- Control: agency-user → main SPA succeeds (existing happy path doesn't regress).
- Control: platform_admin → admin SPA succeeds (existing happy path doesn't regress).
- Precedence: wrong password against a platform_admin email returns `auth.invalid_credentials` (401), not `auth.wrong_spa`.
- Precedence: unknown email on the main SPA returns `auth.invalid_credentials` (401), not `auth.wrong_spa`.
- Precedence: suspended platform_admin on main SPA returns `auth.account_locked.suspended` (423), not `auth.wrong_spa`.

**Docs — `runbooks/local-dev.md` § 2 correction:**

- The pre-chunk-6 doc claimed _"Sanctum issues a single `XSRF-TOKEN` cookie regardless of guard. In local dev that cookie is shared between the two SPAs because they share an origin — that's tolerable because CSRF tokens are not authenticators."_ — that comment was written under the assumption that the same `XSRF-TOKEN` value would validate against any session. That's not how Laravel CSRF works: tokens are stored _in_ the session, so a per-guard session split means a per-guard CSRF token split. The corrected doc explains the cookie name vs. value distinction and documents the new origin-based widening as Rule #2 in the middleware's match table.
- A new bullet in § 5 ("Debugging auth issues locally") item #3 (419 debugging) covers the admin-specific failure mode: "if `Origin: http://127.0.0.1:5174` doesn't match `FRONTEND_ADMIN_URL`, the preflight lands on the main session and the admin login POST 419s every time."

---

## Why this matters beyond the immediate bug

- **Bug A** would block _every_ admin-side state-changing action, not just login: any `POST` / `PATCH` / `DELETE` from the admin SPA fetched the CSRF preflight first and would have hit the same 419 if the user hadn't been logged in already. The chunk-5 brand creation flow would have been broken for any admin-SPA equivalent. The fix is at the cookie middleware layer, so every admin-SPA write surface benefits without per-route touchup.
- **Bug B** would manifest visibly anywhere an authenticated session crossed an authorization boundary — the user reported "I don't see my brands anymore after re-login" because the platform_admin session was attached but had no agency membership for the brand list to query against, presenting as data loss. The gate closes the door at credential time so the broken-shell UX never materialises, and the `meta.correct_spa_url` lets the SPA herd the user back to the right side.
- Both fixes are **non-overlapping but mutually reinforcing**: Bug A blocked correct admin logins; Bug B let incorrect main logins through. With both landed, the two-SPA boundary holds at both axes (transport + authentication).

---

## Provenance trail

1. **Local QA after Sprint 3 chunk 5 close.** Pedram logs out of `admin@catalyst.local` (AgencyAdmin on agency SPA) and logs back in with `super@catalyst-engine.local` / `password-12chars`. The login succeeds on the agency SPA (`apps/main`, port 5173) and lands on the dashboard — but no brands appear, no agency context, just an empty shell with the seeded super-admin's name in the corner.
2. **First diagnosis — wrong SPA.** The user is a platform_admin trying to use the agency SPA. We point them at `http://127.0.0.1:5174` (admin SPA, `web_admin` guard) and confirm `super@` / `password-12chars` are the seeded credentials.
3. **Bug A surfaces.** The user attempts admin-SPA login at port 5174 with the right creds and gets _"Something went wrong. Please try again."_ on the SignIn page. No console errors. No network 4xx/5xx in DevTools (the request shows as 419 on a closer look, but the SPA's resolver squashes it to the unknown-error fallback).
4. **API log inspection.** `terminals/296162.txt` (the `pnpm dev` concurrently log) shows the pattern: 4 of 5 attempts hit `/api/v1/admin/auth/login` and return in **< 1ms** — middleware rejection, not controller. One attempt at 15:59:52 takes 500ms (controller-reached) but still ends in an error response the SPA can't decode. The sub-millisecond responses immediately point at CSRF: the only thing fast enough to reject before the controller is `VerifyCsrfToken` (or routing, but `Allow: POST` is honoured on the admin login route).
5. **Cookie isolation review.** `UseAdminSessionCookie::shouldApply` only matches `api/v1/admin/*`. The Sanctum preflight `/sanctum/csrf-cookie` doesn't carry that prefix → main session → main-session-bound CSRF token → admin-session login POST sees a mismatch. The runbook says XSRF-TOKEN sharing is tolerable; the runbook is wrong.
6. **This chunk.** Both bugs framed as "one chunk, two distinct fixes that share the underlying topology question." Plan + verify + commit + doc.

---

## Honest deviations from the kickoff plan

- **Two bugs in one chunk.** Conventional chunk discipline would split this into two PRs. They're landed together because (a) the user is blocked on Bug A, (b) Bug B was the trigger for the user discovering Bug A (couldn't log into the admin SPA → no admin context → no way to reach the part of the UI that surfaces Bug B as a UX wart), and (c) the two fixes are in the same modular slice (`apps/api/app/Modules/Identity`) so review-overhead-per-fix is dominated by the shared context. The honest deviation is flagged here; future "this fix uncovered _another_ fix" stacks should pause and ask.
- **Origin-detection rule reads config, not env.** I deliberately did not introduce a new env var like `FRONTEND_ADMIN_ORIGINS=*`. The middleware reads `config('app.frontend_admin_url')` which is _already_ wired into CORS (`config/cors.php`) and Sanctum stateful (`config/sanctum.php`) — adding a new env var would have meant 3 places to keep in sync, and there's no real-world case for an admin SPA running on >1 origin. If staging ever needs that, the right shape is a config-level array, not the middleware adding a new env knob.
- **No SPA-side rendering of `meta.correct_spa_url`.** The bundle entry today is plain text; the SPA does not (yet) read the meta to render a clickable link. Adding a `<a href="{correct_spa_url}">` rendering layer would have meant either parametrising the i18n template (which `useErrorMessage` does support via `error.details[0].meta` → bag forwarding) _or_ side-channelling around vue-i18n with raw DOM. Both are bigger than chunk 6's "unblock the user" goal. Filed as a tech-debt follow-up.
- **No new architecture test for the `SPA_USER_TYPE_ALLOW_LIST` constant.** The allow-list is a private `const` in `AuthService` rather than a public enum, deliberately — exposing it would invite consumers outside the auth path to consult it (auth-gating is one decision, made in one place). The 8-case feature test pins the contract from the outside. If someone widens the allow-list incorrectly, the precedence + control-case tests catch it.
- **No `BrandUser` case in tests.** `UserType::BrandUser` is reserved for Phase 2 and never assigned today, so I didn't seed a fake brand_user just to assert it's rejected. The allow-list comment names the case explicitly so a future Sprint that introduces BrandUser also touches the gate (and adds the case to the per-SPA list as appropriate). The `guardAcceptsUserType` helper short-circuits "not in any allow-list" to `false`, so the default posture is correct even without an explicit test today.

---

## Verification log

Sequence run before commit:

| Command                                                          | Result                                                            |
| ---------------------------------------------------------------- | ----------------------------------------------------------------- |
| `php vendor/bin/pest --filter "TwoSpaCookieIsolation"`           | 10 / 10 pass (4 pre-existing + 6 new CSRF-preflight cases)        |
| `php vendor/bin/pest --filter "WrongSpaGate"`                    | 8 / 8 pass (new file)                                             |
| `php vendor/bin/pest --filter "Identity"`                        | 249 / 249 pass (full Identity feature suite)                      |
| `php -d memory_limit=512M vendor/bin/pest` (full backend suite)  | 829 / 829 pass                                                    |
| `php vendor/bin/phpstan analyse --memory-limit=2G --no-progress` | 0 errors                                                          |
| `php vendor/bin/pint --test`                                     | 0 issues                                                          |
| `pnpm --filter @catalyst/api-client test`                        | 100 / 100 pass                                                    |
| `pnpm --filter @catalyst/main test`                              | 497 / 497 pass                                                    |
| `pnpm --filter @catalyst/main typecheck`                         | 0 errors                                                          |
| `pnpm --filter @catalyst/main lint`                              | 0 errors (2 pre-existing v-html warnings unrelated to this chunk) |
| `pnpm --filter @catalyst/admin test`                             | 270 / 270 pass                                                    |
| `pnpm --filter @catalyst/admin typecheck`                        | 0 errors                                                          |
| `pnpm --filter @catalyst/admin lint`                             | 0 errors                                                          |

---

## Files touched

**Backend (production):**

- `apps/api/app/Modules/Identity/Http/Middleware/UseAdminSessionCookie.php` — `shouldApply()` widened with origin-detection rule; new `originIsAdminSpa()` + `normalise()` helpers; class-level + method-level docblocks updated.
- `apps/api/app/Modules/Identity/Services/LoginResultStatus.php` — new `WrongSpa` enum case.
- `apps/api/app/Modules/Identity/Services/LoginResult.php` — new `wrongSpa()` factory; class docblock extended.
- `apps/api/app/Modules/Identity/Services/AuthService.php` — new `SPA_USER_TYPE_ALLOW_LIST` const; new `guardAcceptsUserType()` private static helper; new step-6 gate in `login()`; class docblock extended with the new step.
- `apps/api/app/Modules/Identity/Http/Controllers/LoginController.php` — new `WrongSpa` branch in `respond()`; new `correctSpaUrlForUser()` private static helper.
- `apps/api/lang/en/auth.php` + `pt/auth.php` + `it/auth.php` — new `login.wrong_spa` key.

**Backend (tests):**

- `apps/api/tests/Feature/Modules/Identity/TwoSpaCookieIsolationTest.php` — 6 new cases for CSRF-preflight admin-origin detection.
- `apps/api/tests/Feature/Modules/Identity/WrongSpaGateTest.php` — new file, 8 cases (rejection symmetry, precedence, control cases, side-effect pinning).

**Frontend (production):**

- `apps/main/src/core/i18n/locales/{en,pt,it}/auth.json` — `auth.wrong_spa` + `auth.login.wrong_spa` entries.
- `apps/admin/src/core/i18n/locales/{en,pt,it}/auth.json` — same two keys with admin-side copy.

**Docs:**

- `docs/runbooks/local-dev.md` — § 2 cookie-isolation contract corrected; § 5 (auth debugging) bullet added for admin-origin preflight rule.
- `docs/reviews/sprint-3-chunk-6-review.md` — this file.

---

## Open follow-ups (deferred, not blocking close)

- **Render `meta.correct_spa_url` as a clickable link in the SignIn pages.** Today the bundle entry is plain text; the meta is wired through the SPA's `useErrorMessage` resolver (the bag forwarding from `error.details[0].meta` already runs) but neither SignInPage interpolates it. Polish, not correctness.
- **Generalise the origin-detection rule for multi-origin admin deployments.** Not needed today (one admin URL per env), but if a future staging tier runs the admin SPA on >1 origin, the middleware should consult an array config key. The current code reads a single string; adding the array is ~10 lines.
- **Backend lockout-counter behaviour on `wrong_spa`.** Currently we dispatch `LoginFailed` for observability but do NOT increment `FailedLoginTracker`. The rationale: credentials were correct, so this isn't a "failed login" in the password-guessing sense — it's a routing error. If we ever observe a `wrong_spa` rate that looks like enumeration probing (it shouldn't — the precedence tests close that vector), we can revisit. Documented inline in `AuthService` for the next reader.
