# Creator app token auth, backend (chunk 1 of 7) — REVIEW

- **Status: Closed — approved.** Verdict (Pedram, 2026-10-06): D1–D7 and Q1–Q7 verified as
  ruled; the dedicated bearer-only guard, with its rejected-alternatives record (Q1), accepted as
  the mechanism; both hazard polarities proven by BR0 / BR2 with the cookie-parity pin; the
  web-byte-identical claim carried by the untouched 261 / 88 suite plus the BR10b 24-red
  responder-drift proof; the complete revocation table with the use-time backstop; the 15-BR
  record accepted, including its honest evidence notes; the Playwright disposition accepted —
  the 2FA-enrolment failure is pristine-`HEAD`-proven pre-existing, with CI as arbiter.
  Close-condition recorded with the verdict: **if CI reds on the 2FA-enrolment spec, that confirms
  the `BCRYPT_ROUNDS` hypothesis and is fixed as a gate-fix commit (a Playwright web-server env
  override), not waved through; any other red is diagnosed per the standing rule.**
- **Date:** 2026-10-06
- **Entry:** AH-099.
- **Built from:** [`creator-app-inventory.md`](creator-app-inventory.md) §M2 (the read-only
  inventory) and the kickoff's locked decisions D1–D7, with the plan-pause rulings Q1–Q7.
- **Commits:** a **feat + docs pair** (the natural split — code/tests/`.env.example` in one, the
  review/log/deploy-log/tech-debt/resumption in the other). Feat: `e665e9ce`. The docs commit
  is the one that carries this file.
- **CI at the pushed tip (§5.41):** _filled in by the post-push provenance commit, once the run
  completes._
- **Provenance:** closed and pushed 2026-10-06 on Pedram's clearance. The pushed SHAs, the CI run
  URL and the `origin/main = HEAD` confirmation (`git rev-list --left-right --count
origin/main...HEAD` → `0 0`) are recorded in this header by that follow-up commit; a file
  cannot cite its own commit's SHA.
- **Reviewed against:** `docs/02-CONVENTIONS.md`, `docs/05-SECURITY-COMPLIANCE.md`,
  `docs/security/tenancy.md`, `docs/tech-debt.md`.

---

## §0. What shipped, in one paragraph

A creator can now authenticate to the API with a bearer token instead of a cookie session —
the backend half of the Creator mobile app. `POST /api/v1/auth/token` runs the **same**
`AuthService` gate graph as the web login (lockout, user suspension, agency suspension, 2FA
challenge) and differs only at the very end: it mints a per-device Sanctum token
(`creator-app:{ios|android}`) instead of `guard()->login()` + session regenerate. A dedicated
bearer-only guard, `creator_app`, is mounted as `auth:web,creator_app` on the 13
creator-reachable mounts (73 routes). Tokens are revoked at logout, on password reset, and at
the single suspend site, expire at a global 90-day ceiling, and are refused at use time for any
non-Creator or suspended user. **Nothing calls any of it yet** (the client is AH-100), so it
deploys dark. No migration; zero `apps/main` / `apps/admin` / `packages/api-client` change.

---

## §1. Production posture

**⚠️ PROD-DATA RISK: MEDIUM — auth behaviour, not data.** No existing row is touched, no
migration runs, no backfill. The risk is the guard swap on live routes, which is why the chunk's
centre is "the web path did not move".

- **Why it does not climb higher.** A creator's cookie request authenticates under the identical
  `web` guard it uses today (§2, priority 2). The new code is reachable only by a request that
  carries a bearer token or calls `POST /auth/token` — and no client does until AH-100.
- **Why it is not LOW.** The 13 mounts include the creator's most-used surfaces
  (`/me`, `creators/me/*`, messaging, notifications). A mistake in the middleware string would
  401 live creators, so the swap is pinned by a route-inspection trio and by both polarities of
  the hazard (§4).
- **Blast radius of a bug, worst first.**
  1. A malformed `auth:web,creator_app` mount 401s a live creator surface → caught by the
     route-inspection pins (BR11a) and the cookie-parity pin (BR2).
  2. The guard re-admits a platform-admin **session** on creator routes (the widening hazard) →
     caught by BR0 and BR2. A guard that accepts a non-creator **token** (BR1) is reachable only
     with a hand-minted token, since the token door itself is creator-only.
  3. A revocation site silently stops revoking → BR5–BR7; zero callers today, so no live
     exposure until AH-100.
- **No flag is needed.** The path has no callers; arming is AH-100's act.
- **Deploys dark.** Zero token callers exist until AH-100, so the deploy changes no live behaviour
  for any user. The deploy obligation and read-back list are in §9 and the `deploy-log.md` PENDING
  entry (PHP-FPM reload; `FRONTEND_EXTRA_ORIGINS`; `personal_access_tokens` migration `Ran` and
  `count(*)` = 0; production `SANCTUM_STATEFUL_DOMAINS` has no bare `localhost`; `AUTH_GUARD` unset
  or `web`).
- **Q6 record — sessions outlive suspension; tokens do not.** Pre-existing: a cookie session
  survives suspension (the check is login-only). After AH-099 a suspended creator's **token** is
  dead (revoked at the single suspend site and refused at use time). Deliberately not changed here;
  tech-debt entry, trigger "when an admin suspend-user surface ships, session revocation ships
  with it" (§3 Q6, §6.4).
- **Impersonation cannot mint tokens.** Minting requires the account's password, which an
  impersonating admin never has (§3).

---

## §2. Review priorities, in the kickoff's own order

### 1. The guard-widening break-revert, both polarities

The hazard: Sanctum's own guard list is `['web', 'web_admin']` (`config/sanctum.php`). Under a
naive `auth:sanctum` swap, any session in that list wins **before the bearer token is even
checked** — so a platform-admin session authenticates against creator endpoints. `GET /me` is the
sharpest case: under a naive swap it returns **200 with the admin's own data**.

**Mechanism: a dedicated bearer-only guard, `creator_app`, mounted as `auth:web,creator_app`.**
`CreatorTokenGuard extends Laravel\Sanctum\Guard` and overrides `__invoke` to run **only the
token half**, reusing Sanctum's `findToken`, `isValidAccessToken` (expiry), provider check and
`last_used_at` tracking. `sanctum.guard` is **untouched** (pinned).

- **Polarity 1 — the hazard is closed.** A `web_admin` session on a swapped route → 401, on
  `/me` and across one route per swapped group; and a `web_admin` session cannot ride along with a
  creator bearer (the request is the creator, never the admin).
- **Polarity 2 — the cookie path is byte-identical.** A creator cookie session on the swapped
  routes resolves under the **same `web` guard** as before, `currentAccessToken() === null`, no
  transient token, default guard unchanged.

Both polarities are proven by break-revert (§4): **BR0** (guard delegates to Sanctum's
`parent::__invoke`) reddens 4 session pins and the 3 identity pins; **BR2** (naive `auth:sanctum`)
reddens the `web_admin` pins **and** the cookie-parity pin.

### 2. The web-untouched proof

The existing Identity auth suite — the most-tested surface in the codebase — is **untouched and
green**: 261 original Identity tests, zero diffs to any of those files (`git show --stat` for the
feat commit lists only five _new_ Identity test files and two deliberate edits under `Creators/`,
§6.1). The web login controller's error envelopes are **byte-identical** after the shared
responder extraction, and that claim is proven by the existing suite's own assertions, not by
new ones: **BR10b** shifts every status literal in the shared `LoginFailureResponder` and **24 of
the existing web-auth tests go red** (so the pin is live, not vacuous).

Full-board corroboration: Pest 2,974 tests, exit 0; the locally-run Playwright sign-in specs
traverse the refactored `AuthService` (§5).

### 3. The D3 revocation set, including suspend-revokes

| Trigger                   | What revokes                                     | Pinned by                                                                                                   |
| ------------------------- | ------------------------------------------------ | ----------------------------------------------------------------------------------------------------------- |
| Token logout              | exactly the presented token (other devices stay) | BR7; over-reach negative for the other device                                                               |
| Completed password reset  | **all** the user's tokens                        | BR5; other-user negative; invalid-token / rejected-password / forgot-password non-triggers                  |
| Suspension (`escalate()`) | **all** the user's tokens                        | BR6; other-user negative; temporary-lock non-trigger; idempotency (§5.6); end-to-end through the token door |
| Use time (every request)  | refuses non-Creator and suspended users          | BR0/BR1 (the backstop for any future suspend site that forgets to revoke)                                   |

**The single suspend site.** `AccountLockoutService::escalate()` is the **only** place a user is
suspended in the codebase (searched in the read pass; every other `suspended` hit is agency or
2FA-enrolment state). The admin suspend-user surface does not exist yet, so there is nothing else
to wire. A future site is covered by the use-time guard until it adds its own revocation.

### 4. 2FA challenged on the token path

An enrolled creator gets the same `401 auth.mfa_required` + `meta.mfa_required` as on web before
any token exists; wrong code → `mfa.invalid_code`; soft rate limit → `423 mfa.rate_limited`;
enrolment suspended → `423 mfa.enrollment_suspended` even before a code is sent; valid TOTP →
token minted and the audit row records `mfa: true`. Every negative also asserts **zero token
rows**. **BR3** (2FA skipped on the token door only) → 5 red.

### 5. The creator-only door

The token door evaluates the gate graph under the allow-list key `creator_app => [Creator]`. An
agency user or platform admin with **valid** credentials gets `403 auth.wrong_spa` with the
`correct_spa_url` for their type (Q7 — reuses the existing envelope, **zero new i18n keys**) and
no token. Recorded as the deliberate boundary: this arc is the creator app. **BR4** (the key
deleted — an unknown guard **fails open**, which is the realistic regression) → 2 red.

### 6. The audit-actor proof

M2.4 said the actor "should hold — verify, don't assume." Verified: under bearer auth,
`AuditLogger` records the creator as the actor, because `Authenticate` calls `shouldUse()` on the
guard that matched, so the default guard — which `AuditLogger` reads — becomes `creator_app`.
Pinned with an **implicit-actor** audit row (`creator.updated`, written by the `Audited` trait,
which passes no actor), plus a control (no auth context → `system`, null id) and **cookie parity**
(the identical request under a cookie records the identical actor). **BR9** → 1 red.

_Evidence note, stated plainly:_ the wizard's own audit event passes an **explicit** actor, so a
test on it would have proven nothing about guard resolution. That is why the pin uses the
implicit-actor row.

### 7. Full board + CI at the tip

See §5. CI at the pushed tip is cited above once the push happens; it closes the chunk.

---

## §3.0 The kickoff's locked decisions (D1–D7), mapped to evidence

| D   | Decision                                                                                            | Where it is proven                                                                                                                                  |
| --- | --------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| D1  | Token login on the existing gate graph, never a fork; 2FA holds; creator-only door                  | §2.4 (BR3), §2.5 (BR4); Q4 (`POST /auth/token`); S2 — `evaluateGates()` is the one gate graph both doors call                                       |
| D2  | The guard swap, with the widening hazard pinned (priority #1)                                       | §2.1; Q1 (mechanism and rejected alternatives); BR0, BR2 (both polarities); route-inspection trio BR11a–c; §6.1 (the 1-not-8 pin correction)        |
| D3  | The complete revocation set: logout, password reset, suspend sites                                  | §2.3 (table); BR5, BR6, BR7; the single suspend site; Q2 use-time backstop (BR0/BR1)                                                                |
| D4  | Expiry posture, per-device token names, no ability scoping v1                                       | Q3 (global 90 days, retroactive lever; BR8); tokens named `creator-app:{ios\|android}`, abilities `['*']`; deferrals in the tech-debt entry (§8)    |
| D5  | Web SPA byte-identical — the existing auth suite passes untouched                                   | §2.2 — 88/88 existing web-auth tests green, 261 original Identity tests undiffed, BR10b (24 red on responder drift); cookie-parity pin (BR2)        |
| D6  | Environment seams named, not assumed: CORS, stateful-domain read-back, audit actor                  | CORS: `FRONTEND_EXTRA_ORIGINS` values in `.env.example` + deploy entry; stateful domains: §6.3 (the Android-only 419 trap); audit actor: §2.6 (BR9) |
| D7  | Scope fences: no client / Capacitor / push / device table / refresh; agency + admin keep `auth:web` | `apps/main` / `apps/admin` / `packages` diff empty (§5); deferrals in §8; BR11b, BR11c and the stay-behind list (§6.2)                              |

---

## §3. The plan-pause rulings (Q1–Q7), as built

**Q1 — dedicated guard, rejected alternatives (verbatim).**

- **(A) Narrow `sanctum.guard` to `['web']` and mount `auth:sanctum`.** One line, and it works.
  But cookie requests would then run **under the `sanctum` guard** (the default driver changes
  and a transient token is attached), which makes the old path internally non-identical — the
  exact drift this chunk exists to refuse. It also edits global config that
  `AuthenticateSession` reads.
- **(C) `auth:sanctum` plus an assert middleware.** This leaves the hazard in place and patches
  around it, rather than removing it.

**Why `web` comes first** in `auth:web,creator_app`: `Authenticate` tries guards in order and
calls `shouldUse()` on the first match. A creator's cookie request is therefore authenticated by
exactly the `web` guard it uses today. `web_admin` is never in the chain on these routes.

**Q2 — the guard refuses non-Creator and suspended users at USE time.** Defence in depth: it
structurally covers any future suspend site that forgets D3's revocation. **The deliberate
asymmetry this creates: the token path is stricter than the session path.** Correct for the new
surface; the session half is the Q6 tech-debt entry.

**Q3 — global 90-day expiry.** `sanctum.expiration = env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 129600)`.
A fixed ceiling from creation (Sanctum has no sliding expiry and no refresh). **Global rather than
per-token** because Sanctum evaluates it at use time against `created_at`, so lowering the env
value is an operator lever that **retroactively** expires older tokens; a per-token stamp cannot.
Pinned by a time-travel 401 test (**BR8**) and a "lower the config → older tokens expire" test.
Pruning deferred (no rows until AH-100).

**Q4 — a separate endpoint, `POST /auth/token`.** Keeps `LoginController` (and its choice of
guard by route name) untouched and the response shapes honestly different. The error-envelope
mapping moved into a shared `LoginFailureResponder`, arms moved verbatim. **The web login's error
envelopes are byte-identical post-extraction; the existing suite's assertions are the proof**
(§2.2, BR10b).

**Q5 — the four 2FA routes swap** (`enable`, `confirm`, `disable`, `recovery-codes`):
creator-reachable, DB-backed, no session state, and the app will need enrolment management from
the phone.

**Q6 — tech-debt, not fixed here.** Killing sessions at `escalate()` would change live web
behaviour inside the chunk whose centre is "web byte-identical". The entry records: sessions
outlive suspension today (login-only check); tokens post-AH-099 do not; and the trigger — _when
an admin suspend-user surface ships, session revocation ships with it_.

**Q7 — wrong-door response reuses `403 auth.wrong_spa` + `correct_spa_url`.** Zero new i18n keys.

**Impersonation cannot mint tokens — a stated security property.** Minting requires the account's
**password**, which an impersonating admin never has. The password requirement is the mechanism;
the token door never sees an impersonated session's credentials. (Impersonation status/end stay
session-only and are on the stay-behind list below.)

**`last_used_at` stays — and costs a write per bearer request.** Sanctum's guard stamps it; the
future device list needs it. A polling client therefore causes one `UPDATE
personal_access_tokens` per request. Named, accepted, and in `tech-debt.md`.

---

## §4. Break-revert record

Every mutation is asserted to have applied (a no-op mutation would read as a false "green"),
the scoped tests are run, the file is restored from backup and `cmp`-verified byte-identical, and
the working tree is checksummed afterwards. **Control first** (unmutated): the five new files
**61/61** green; the nine existing web-auth files **88/88** green.

| #     | Mutation                                                            | Red | What went red                                                                            |
| ----- | ------------------------------------------------------------------- | --- | ---------------------------------------------------------------------------------------- |
| BR0   | guard delegates to Sanctum's `parent::__invoke` (widening re-opens) | 7   | 4 `web_admin`-session pins + suspended / agency-token / admin-token pins                 |
| BR1   | guard's identity check removed (`type`/`isSuspended` → `false`)     | 3   | suspended creator, agency-user token, platform-admin token                               |
| BR2   | naive `auth:sanctum` on the 7 Identity mounts                       | 6   | route-set, no-sanctum, `/me` `web_admin` 401, group sweep, ride-along, **cookie-parity** |
| BR3   | 2FA skipped on the token door only                                  | 5   | mfa_required, invalid_code, rate_limited, enrolment_suspended, valid-TOTP                |
| BR4   | allow-list key deleted (unknown guard fails open)                   | 2   | agency user and platform admin `403 wrong_spa`                                           |
| BR5   | password reset stops revoking                                       | 2   | revokes-ALL, other-user over-reach                                                       |
| BR6   | `escalate()` stops revoking                                         | 4   | revokes-ALL, over-reach, end-to-end via token door, idempotency                          |
| BR7   | token logout stops deleting its token                               | 2   | revokes exactly the token, other-device over-reach                                       |
| BR8   | `sanctum.expiration` → `null`                                       | 1   | 90-day time-travel 401                                                                   |
| BR9   | `AuditLogger` reads the `web` guard instead of the default guard    | 1   | bearer implicit-actor row                                                                |
| BR10  | shared responder: first `423` → `422`                               | 1   | a 2FA rate-limit arm in the **existing** web suite                                       |
| BR10b | shared responder: **every** status literal +1 (8 literals)          | 24  | the existing web login / 2FA / lockout suite — the byte-identical proof is live          |
| BR11a | route-inspection trio 1 — one swapped mount loses `creator_app`     | 2   | swapped-set = intended-set; bearer-per-group                                             |
| BR11b | trio 2 — an agency-tenancy mount gains `creator_app`                | 3   | swapped-set; no-agency-carries-creator_app; agency-keeps-plain-`auth:web`                |
| BR11c | trio 3 — bare `auth:sanctum` on a stay-behind route                 | 2   | agency-keeps-plain-`auth:web`; no-`auth:sanctum`                                         |

_Evidence notes, stated plainly:_

- **BR1 also reddened the three use-time pins** (suspended / agency / admin token), not only the
  agency-token pin the plan predicted. That is because Sanctum's own token half has no identity
  checks at all — so with our check removed there is nothing left, which is the point of Q2.
- **BR10 was a one-arm mutation** (the first `423` of four). It was widened to BR10b rather than
  relying on one red test to carry a claim about the whole responder.
- **A sed incident during the build** (macOS `sed -i` without a suffix) had no effect on existing
  tests: `git status` shows only the two deliberate `Creators/` edits plus the five new files.
- The BR run was **re-done this session** at close, because the earlier outputs were not preserved;
  these are fresh, not carried over.

---

## §5. Gate board

| Gate                                         | Result                                                                                |
| -------------------------------------------- | ------------------------------------------------------------------------------------- |
| Pest, full backend (serial)                  | **2,974 tests, exit 0** (322s). 61 of them are new.                                   |
| New files re-run after final test edits      | **61/61** green (the full run began before the PHPStan-driven test-helper type edits) |
| Existing web-auth files re-run               | **88/88** green                                                                       |
| PHPStan (full project, level 8)              | **No errors**                                                                         |
| Pint `--test`                                | **passed**                                                                            |
| `apps/main` / `apps/admin` / `packages` diff | **empty** (`git diff --stat` — D5/D7 fence)                                           |
| Migration files in the diff                  | **none**; `create_personal_access_tokens_table` shows **Ran**                         |
| Playwright, main SPA (local)                 | **31 passed, 2 failed** — see below                                                   |
| Playwright, admin SPA (local)                | **1 passed, 1 failed** — see below                                                    |
| CI at the pushed tip                         | _pending the push_                                                                    |

**Playwright, honestly.**

1. **The first two local runs were invalid and are not counted.** Run 1 had no
   `TEST_HELPERS_TOKEN` (the global-setup refuses to start — CI generates one per run; a throwaway
   `openssl rand -hex 32` was used). Run 2 launched with all 33 tests failing with
   `browserType.launch: Executable doesn't exist` — the shell had inherited a
   `PLAYWRIGHT_BROWSERS_PATH` pointing into Cursor's sandbox cache. **No test body executed**, so
   neither run says anything about this change. The counts above are from the run with that
   variable unset. All runs hit the isolated `catalyst_e2e` database (the dev DB was never touched).
2. **Two real failures remain, and neither is caused by this change.**
   - **`2fa-enrollment-and-sign-in` (main) and `admin-mandatory-mfa-enrollment` (admin)** fail at
     the same step: `expect([data-test="recovery-codes-display"]).toBeVisible()` after the TOTP
     **confirm** (5s budget). The page snapshot at failure shows the recovery codes **are rendered**
     with the countdown at "4 second(s)" — enrolment worked, the panel simply appeared after the
     budget. **The identical spec fails 3/3 on a pristine `HEAD` with all of this chunk's changes
     stashed** (working tree backed up, stashed, restored, and checksum-verified identical
     afterwards). A consistent explanation, **not proven**: enrolment-confirm hashes ten recovery
     codes, and ten bcrypt cost-12 hashes take **3.3s on this machine** (`BCRYPT_ROUNDS` is not
     overridden anywhere). The admin spec that exercises **TOTP sign-in** — the path this chunk
     refactored — passed. Not fixed here: it is outside this chunk's fences and CI is the arbiter.
   - **`brands` happy path** failed once in the full run (30s test timeout, a spec that touches
     login only for sign-in) and **passed 2 of 3** in an isolated repeat — timing, not logic.
3. Of the 31 main-SPA specs that passed, the relevant ones are sign-in, the failed-login
   lockout/escalation spec (#20 — it traverses `escalate()`, one of the edited revocation sites),
   the invitation specs and the creator-wizard happy path. In the admin SPA the 2FA **sign-in**
   spec passed.

---

## §6. Findings the read pass produced

### 6.1 The 1-not-8 pin correction

The kickoff said "the 8 tests pinning `auth:web` by string go red by design." The read pass
found **one** real pin: `Sprint3Chunk2InvariantsTest.php` (~`:202`), now
`toContain('auth:web,creator_app')` + `not->toContain('auth:web')`. The "8" had been counting
`auth:web_admin`, a test-only route, and comments. A second file,
`CreatorWizardVerifiedGateTest.php`, had its **docblock only** updated. Both are the only edits
to existing tests in the commit.

### 6.2 The 13 mounts / 73 routes

Verified from `route:list` at close: **73 routes** carry `auth:web,creator_app`
(`creators/*` 60, `me/*` 8, `auth/*` 5); **zero** mount `auth:sanctum`.

| Module        | Mounts | What                                           |
| ------------- | ------ | ---------------------------------------------- |
| Identity      | 7      | `GET`/`PATCH /me`, logout, the four 2FA routes |
| Creators      | 1      | `creators/me/*`                                |
| Messaging     | 3      | the creator-reachable messaging groups         |
| Notifications | 2      | notifications + preferences                    |

**Stay-behind list (still `auth:web` only, pinned) and why:**

- `GET /auth/impersonation/status`, `POST /auth/impersonation/end` — session-only by design
  (`EnforceImpersonation`); an impersonation hand-off is a cookie construct.
- `GET /jobs/{job}` — the bulk-invite polling route; agency-side only, no creator caller exists.
- `POST /agencies/{agency}/invitations/accept` — agency invitation accept; an agency surface.
- `POST /agencies/{agency}/creators/invitations/bulk` — bulk creator invitation; an agency surface.
- **Every `tenancy.agency` group** (checked: more than 50 routes) keeps plain `auth:web`.

### 6.3 The Android-only 419 trap (deploy read-back wording)

The 419 trap (M2.4) is **Android-only**, and the read-back should say so. The Capacitor shell's
origins are `capacitor://localhost` (iOS) and `https://localhost` (Android). Sanctum strips only
`http://` / `https://` before matching `SANCTUM_STATEFUL_DOMAINS`. So a **bare `localhost`** entry
in production's `SANCTUM_STATEFUL_DOMAINS` would make Android's `https://localhost` a **stateful**
origin — Sanctum would then demand a CSRF token the bearer app never has, and every bearer POST
would 419. iOS's `capacitor://localhost` is never stripped, so it never matches. The repo's
`.env.example` default does contain `localhost` (correct for local dev), which is exactly why the
read-back is needed. The tests use `capacitor://localhost` for token-door requests because the
`TestCase` default `http://localhost` origin is itself stateful.

### 6.4 The Q2 asymmetry (restated)

After AH-099 the token path is **stricter** than the session path: a suspended creator's token is
dead (revoked at the suspend site, and refused at use time), but an already-open **cookie session**
survives suspension. Pre-existing, deliberately not changed here (Q6).

---

## §7. Sub-step record

- **S1 — guard and config.** `HasApiTokens` on `User`; `CreatorTokenGuard`; `creator_app` guard +
  provider registration; `sanctum.expiration`. No route used it yet; tested through a test-only
  route.
- **S2 — token login.** `evaluateGates()` extracted from `login()` (body moved verbatim; `login()`
  keeps its exact sequence); `issueToken()`; `GatesPassed`; `LoginResult::success(user, token)`;
  shared `LoginFailureResponder`; `POST /auth/token` with the same `auth-ip` and
  `auth-login-email` throttles; `TokenLoginController` + `TokenLoginRequest`.
- **S3 — the guard swap.** The 13 mounts → `auth:web,creator_app`; the one real pin updated; the
  hazard pins (both polarities), the route-inspection trio, and the bearer-per-group sweep.
- **S4 — revocation.** Token logout; password reset; `escalate()`; each with its §5.34 cases
  (trigger, over-reach negative, non-trigger negative).
- **S5 — audit-actor proof.** Implicit-actor row under bearer, a control, and cookie parity.
- **S6 — docs.** This review, the AH-099 entry, the deploy-log PENDING entry, tech-debt entries, the
  Part 2 refresh, and `.env.example` (`FRONTEND_EXTRA_ORIGINS`, the stateful-domain warning,
  `SANCTUM_TOKEN_EXPIRATION_MINUTES`).

Each step ended green on scoped Pest, Pint and PHPStan.

---

## §8. Tech-debt entries

Both in [`../tech-debt.md`](../tech-debt.md):

1. **Sessions outlive suspension; tokens do not (Q6)** — with the trigger "when an admin
   suspend-user surface ships, session revocation ships with it".
2. **Token lifecycle deferrals** — pruning, no device table (tokens accumulate per login), no
   refresh tokens, no ability scoping, `last_used_at` write per request.

Also worth recording from this session, not filed as debt: the local Playwright 2FA-enrolment
timing (§5) — if CI is green at the tip it is a local-hardware artefact; if CI reds on it, it is
real and needs a `BCRYPT_ROUNDS` override in the Playwright web-server env.

---

## §9. Deploy obligation

See the `deploy-log.md` PENDING entry. In short:

- **No migration** (verified: the stock table's migration is `Ran`; no migration file in the diff).
- **PHP-FPM reload.** No worker restart. No SPA rebuild (zero SPA diff). **Deploys dark.**
- **Env:** `FRONTEND_EXTRA_ORIGINS` gains `capacitor://localhost,https://localhost`;
  `SANCTUM_TOKEN_EXPIRATION_MINUTES` optional (defaults to 129600).
- **Read-backs:** `personal_access_tokens` migration `Ran`; `count(*)` = 0; production
  `SANCTUM_STATEFUL_DOMAINS` has **no bare `localhost`** (the Android 419 trap, §6.3);
  `AUTH_GUARD` unset or `web`.
