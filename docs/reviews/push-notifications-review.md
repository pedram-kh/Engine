# AH-102 — push notifications for the creator app — Checkpoint A review (backend, Stages 0–2)

- **Status:** **CLOSED 2026-10-11 (pushed, CI green at the tip, not deployed, no flag armed).** Checkpoint A (backend) and
  Checkpoint B (the matrix column, the push module, Android, and iOS) are on `origin/main`; Pedram's Android walk (11 of 12
  items) and the real-iPhone spike and walk passed; **`IOS_PUSH_ENABLED` is `true` in code, `push_notifications_enabled` is OFF
  everywhere.** Named open items, none blocking the close: **(1)** the TestFlight production-APNs proof (pending the first
  upload); **(2)** the token-rotation re-POST (R-8, never forced on a device; accepted, self-healing); **(3)** item 10 on iOS (no
  `UNREGISTERED` observed). Close-out: §29. Sections 1-13 are the Checkpoint A review as it stood; later sections are
  additive.
- **Date:** 2026-10-10 (Checkpoint A) · 2026-10-11 (Checkpoint B)
- **Plan:** [`push-notifications-plan.md`](push-notifications-plan.md) (cleared at `d4edffb4`, dispositions at
  `faf96d4d`) · **Inventory:** [`push-notifications-inventory.md`](push-notifications-inventory.md)
- **Local tip:** the docs commit that carries this file sits on top of `a8423726`. `origin/main` is `4d468406`.
  **Pushed at Checkpoint A clearance — see §13.**
- **Prod-data risk:** LOW–MEDIUM (one additive `CREATE TABLE`; the `notify()` hot path restructured behind try/catch,
  an after-commit job and a default-OFF flag). See §8.

## 0. Three lines to read first

> ⚠ **Nothing in this build has ever talked to the real FCM.** The send path, the OAuth exchange and the error taxonomy
> are proven against a fake HTTP layer and a real RS256 signature, not against Google. The first real answer comes from
> `push:test --validate-only` on a server with the credential. The taxonomy is from Google's documentation (§7).
>
> ⚠ **Nothing in this build has run on a device.** Stage 4 is where the app registers a token and where
> auto-init-off, the notification channel and the lock-screen copy are first seen.
>
> ⚠ **`notify()` changed.** The early return is gone; push now runs on both paths. It cannot throw and runs after
> commit, with the flag OFF, but it is the one hot path this chunk touches. §4 has the evidence.

## 1. What was built (12 commits, local)

| Commit | SHA        | What                                                                                            |
| ------ | ---------- | ----------------------------------------------------------------------------------------------- |
| docs   | `faf96d4d` | plan P-rulings recorded                                                                         |
| C0.1   | `1f7d2de0` | drop an inert `RuntimeException` import (Q17, its own first test-only commit)                   |
| C0.2   | `93b7d767` | `.gitignore` guards for service-account JSON and APNs key files                                 |
| C1.1   | `ff0a4112` | type-aware preference default, **behaviour-identical** (refactor only)                          |
| C1.2   | `2f3d62eb` | `push` channel, `PushEligibility`, `campaign.job_posted` OFF-by-default, `type_defaults` in API |
| C1.3   | `37d482d9` | `user_push_tokens` migration, model, factory, `PushTokenRegistry`                               |
| C1.4   | `6ff737d9` | `POST` / `DELETE /me/push-tokens` on bare `auth:creator_app`                                    |
| C2.1   | `238fa85b` | `PushSender` seam and the FCM v1 client (no composer deps)                                      |
| C2.2   | `cdfa13e6` | `lang/*/push.php` ×24 and `PushMessageFactory`                                                  |
| C2.3   | `68ffb21c` | `SendPushNotificationJob`, `PushDeliveryRecorder`                                               |
| C2.4   | `72204f1d` | `PushDispatcher`, `notify()` restructure, `suppressPush`, flag `push_notifications_enabled`     |
| C2.5   | `a8423726` | `php artisan push:test`                                                                         |

## 2. Per-decision evidence

| Ruling | How it is met, and where it is proven                                                                                                                                                                                                                                                                                         |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Q1/Q1b | FCM v1; `FcmPushSender` + `FcmAccessTokenProvider` + `FcmServiceAccount`, `openssl_sign` RS256, `composer.json` untouched. `FcmPushSenderTest` (49) verifies a real signature with a throwaway key.                                                                                                                           |
| Q19    | `PushSender` is the only seam. A scan test (`FcmPushSenderTest`, containment) fails if anything outside `Notifications/Push/Fcm/` names an `Fcm*` class, the FCM host or the OAuth host; the one allowed exception is the provider binding. It is proven non-vacuous.                                                         |
| Q2     | `PushEligibility::TOGGLEABLE` (10) + `ALWAYS_ON` (4); `PushDispatcherTest` runs all 14 through `notify()`, and every other `NotificationType` case through a "never pushes" loop (`live_verified` and `application.rejected` asserted by name).                                                                               |
| Q3     | One resolver, `NotificationChannel::defaultEnabledFor($type)`, `TYPE_DEFAULT_OVERRIDES = {campaign.job_posted: {push: false}}`. `PushPreferenceDefaultsTest` pins both polarities **including the write side** (opting in must store a row). An architecture scan forbids direct `->defaultEnabled()` calls outside the enum. |
| Q4     | `Cache::add('push:throttle:{user}:{threadKey}', 1, 60)`; the same `threadKey` is the collapse key. A scan test pins that the dispatcher never names `DebouncedMessageMailer` / `message_email_debounces` and that the mailer never names push.                                                                                |
| Q5     | `SendAssignmentNotifications::notifyCreatorOfReview` passes `suppressEmail` as `suppressPush`. `PushDispatcherTest` drives the real `/approve` endpoint: a completing approval pushes exactly `assignment.completed_on_approval` while both in-app rows exist.                                                                |
| Q6/Q7  | `PushMessageFactory` reads four name keys only and never forwards the data bag; a sentinel in the `feedback` field never appears in the serialized job (`PushDispatcherTest`) nor in any of 14 types × 24 locales (`PushMessageFactoryTest`).                                                                                 |
| Q11    | FK cascade to `personal_access_tokens`; canaries for model delete, mass delete and the real `POST /auth/logout` (SQLite `PRAGMA foreign_keys` asserted on). `creator.approved`/`rejected` are always-on: a stored `push=false` row cannot suppress them (tested).                                                             |
| Q12    | Registration is not behind the flag; the flag gates sending only (`PushDispatcherTest`, "gates sending only").                                                                                                                                                                                                                |
| Q14    | `.gitignore` patterns for service-account and APNs key files (C0.2). **`google-services.json` and `GoogleService-Info.plist` are not committed yet** — they do not exist until the Firebase project does (§9).                                                                                                                |
| Q15    | Named post-migrate step in the deploy log; cannot be checked locally.                                                                                                                                                                                                                                                         |
| Q17    | C0.1 is a one-line deletion in its own commit; `php -l` warning before, clean after.                                                                                                                                                                                                                                          |
| P-1    | `FCM_SERVICE_ACCOUNT_JSON_B64`, decoded **inside the FCM client**; a missing / non-base64 / non-JSON value is a `config_error` outcome, never a boot crash (8+ cases tested). No file path or permission step exists anywhere.                                                                                                |

## 3. The disciplines, one by one

- **Try/catch on both paths of `notify()`, after-commit job.** `PushDispatcher::dispatch()` wraps its whole body in
  `try/catch (Throwable)` + `report()`. `notify()` calls it once, after the (optional) in-app write, on both the
  "row written" and "in-app muted" paths. `SendPushNotificationJob` calls `$this->afterCommit()` in its constructor.
  Proven by BR-8 / BR-10 / BR-11 below.
- **Suites unedited.** The full backend suite is green with **zero edits to any existing Notifications, Campaigns or
  Messaging test**. The complete list of existing tests touched is in §6.
- **Backend-first order.** The API accepts `push` and returns `type_defaults` now; no SPA code reads either until
  Stage 3. Old clients ignore the extra key (the one fixture that listed the keys exactly was updated).
- **Pre-deploy `count(*) WHERE channel='push'` = 0**, **`queue:restart`** and the **`engine_c_user` GRANT check** are
  named steps in the deploy-log entry. Their results are blank: they are yours to read.
- **Deploy-log disposition before any push:** the PENDING entry exists and stacks on AH-101. **Pushed after clearance (§13).**

## 4. Break-revert record (§5.35)

Each row: mutate the production code → run the spec file → record the failing count → restore → re-green. The
numbering below is the order I ran them in; it does **not** match the plan's BR-1…BR-17 one-for-one (the plan's list
was a floor; several mutations came out of writing the tests). All restored; the working tree was clean after each
(`diff -q` against a backup, and the final `git status` is clean for code).

> **Honest limit:** I recorded the **failing count and the mutation**, not the runner's full raw output. For the
> earlier commits that is all I kept. If you want verbatim output for any row, say which and I will re-run it.

| Where | Mutation                                                                    | Result                                                              |
| ----- | --------------------------------------------------------------------------- | ------------------------------------------------------------------- |
| C0.1  | re-add the `use RuntimeException;` line                                     | compile warning returns                                             |
| C1.1  | call `->defaultEnabled()` directly in a controller                          | architecture scan fails, with the "use `defaultEnabledFor`" message |
| C1.2  | resolver ignores the type                                                   | 5 fail                                                              |
| C1.2  | `setPreference` compares against the channel baseline, not the type default | 4 fail                                                              |
| C1.2  | omit `type_defaults` from the API response                                  | 2 fail                                                              |
| C1.3  | drop the PAT created-at + `sanctum.expiration` filter                       | 1 fail                                                              |
| C1.3  | drop the `expires_at` filter                                                | 1 fail                                                              |
| C1.3  | drop the soft-delete filter                                                 | 1 fail                                                              |
| C1.3  | drop `invalidated_at` filter                                                | 3 fail                                                              |
| C1.3  | upsert does not move a token between users                                  | 2 fail                                                              |
| C1.3  | drop user scoping in the registry                                           | 4 fail                                                              |
| C1.3  | PAT foreign key `RESTRICT` instead of `CASCADE`                             | 4 fail (canaries)                                                   |
| C1.4  | route on `auth:web,creator_app` instead of bare `auth:creator_app`          | 3 fail                                                              |
| C1.4  | drop the throttle                                                           | 2 fail                                                              |
| C1.4  | unscoped `DELETE`                                                           | 1 fail                                                              |
| C1.4  | owner taken from the request body                                           | 1 fail                                                              |
| C2.1  | 404-without-detail → `TokenInvalid`                                         | 1 fail                                                              |
| C2.1  | `INVALID_ARGUMENT` always kills the token                                   | 1 fail                                                              |
| C2.1  | `SENDER_ID_MISMATCH` → `TokenInvalid`                                       | 1 fail                                                              |
| C2.1  | drop the forced refresh on 401                                              | 2 fail                                                              |
| C2.1  | no token scrub in error detail                                              | 1 fail                                                              |
| C2.1  | SHA-1 instead of SHA-256 in the JWT                                         | 1 fail                                                              |
| C2.1  | token URL read from the credential JSON                                     | 31 fail                                                             |
| C2.1  | no access-token caching                                                     | 2 fail                                                              |
| C2.2  | forward the data bag into the message                                       | 4 fail                                                              |
| C2.2  | drop the non-allowlisted-placeholder guard                                  | 1 fail (needed a new test; the guard had none)                      |
| C2.2  | no name sanitising                                                          | 2 fail                                                              |
| C2.2  | `setLocale` instead of an explicit locale                                   | 1 fail                                                              |
| C2.2  | keep the body when a required name is missing                               | 7 fail                                                              |
| C2.3  | drop after-commit on the job                                                | 3 fail                                                              |
| C2.3  | no run-time liveness re-check                                               | 5 fail                                                              |
| C2.3  | retry dead tokens                                                           | 4 fail                                                              |
| C2.3  | `ConfigError` kills the token                                               | 1 fail                                                              |
| C2.3  | log the device token                                                        | 5 fail                                                              |
| C2.4  | re-introduce the early return in `notify()` (BR-10)                         | 2 fail                                                              |
| C2.4  | remove the dispatcher's try/catch (BR-11)                                   | 2 fail                                                              |
| C2.4  | ignore `suppressPush` (BR-12)                                               | 2 fail                                                              |
| C2.4  | remove the throttle (BR-13)                                                 | 5 fail                                                              |
| C2.4  | drop after-commit (notify-level rollback test, BR-8)                        | 1 fail                                                              |
| C2.4  | ignore the flag                                                             | 3 fail                                                              |
| C2.4  | always-on types read the push preference                                    | 4 fail                                                              |
| C2.4  | skip the eligibility check                                                  | 1 fail                                                              |
| C2.4  | drop the empty-token short-circuit                                          | 1 fail                                                              |
| C2.5  | smoke test without `validate_only`                                          | 1 fail                                                              |
| C2.5  | print the token value                                                       | 2 fail                                                              |
| C2.5  | smoke test accepts a config error as success                                | 3 fail                                                              |
| C2.5  | unknown user not rejected                                                   | 1 fail                                                              |
| C2.5  | send to all tokens, not just live ones                                      | 2 fail                                                              |

**One finding from C1.4 worth reading:** swapping the route to `auth:web,creator_app` made 3 specs fail (the
allowlist pin, the swapped-set exclusivity pin, and one more) but **the "cookie session is a 401" test still passed**,
because the controller itself reads `$request->user('creator_app')` and 401s when it is null. That is defence in depth,
not a hole, and it means the **route pins are the primary catch** for a guard swap — which is why the allowlist test
exists.

## 5. Gate board (at the tip `a8423726` plus docs)

| Gate                                     | Result                                                                                                                        |
| ---------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| Backend Pest, full, serial, 2G           | **0 failed.** 3 239 deprecated-but-passing + 5 passed, 16 390 assertions (see the environment note below)                     |
| Pint `--test` (all)                      | pass                                                                                                                          |
| PHPStan / Larastan (`--memory-limit=2G`) | `[OK] No errors` (1 053 files)                                                                                                |
| Vitest, all workspaces                   | design-tokens 42 (+1 todo) · api-client 221 · ui 47 · apps/admin 488 · apps/main 2 207 — **all pass**                         |
| ESLint, all frontend                     | 0 errors; 2 warnings, both pre-existing (`v-html` in `apps/main`)                                                             |
| vue-tsc, all frontend                    | pass                                                                                                                          |
| 24-locale parity                         | backend `LangParityTest` (discovers `push.php` by scanning `lang/`) and the frontend locale specs, both inside the runs above |
| Playwright                               | **Run — see §12**                                                                                                             |

**Environment notes (local only; CI is unaffected):**

1. Local PHP is **8.5.9**; CI is 8.4. Every app-booting test prints `PDO::MYSQL_ATTR_SSL_CA is deprecated` from
   `apps/api/config/database.php:63,83`, which is why Pest labels passing tests "deprecated". That is **not** the cause I
   first attributed it to (the inventory's §6.1 blamed the import fixed in C0.1; the import fix is still right, but
   the "DEPR" noise stays). It is 8.5-only and not caused by this chunk.
2. Local `apps/api/.env` has `SANCTUM_STATEFUL_DOMAINS` without bare `localhost`, so 42 Identity login tests fail
   **locally at a clean HEAD too**. I ran the board with `.env.example`'s value
   (`localhost,localhost:5173,127.0.0.1,127.0.0.1:5173,127.0.0.1:5174`) and it is green. Not mine and not touched.

## 6. Existing tests touched (the §4.4 list, as built)

| File                                                             | Change                                                                                  | Why                   |
| ---------------------------------------------------------------- | --------------------------------------------------------------------------------------- | --------------------- |
| `tests/Feature/Modules/Creators/Sprint3Chunk2InvariantsTest.php` | −1 line (an unused `use RuntimeException;`)                                             | Q17 (C0.1)            |
| `tests/Feature/Modules/Admin/AdminFeatureFlagTest.php`           | +1 assertion (the new flag is listed)                                                   | the flag registry     |
| `tests/Feature/Modules/Identity/CreatorAppGuardSwapTest.php`     | +1 test (the closed bare-`auth:creator_app` allowlist); no existing test changed        | C1.4                  |
| `apps/main/.../NotificationPreferencesPage.spec.ts`              | fixture: `DEFAULTS` gains `push: true`; the response envelope gains `type_defaults: {}` | the API contract grew |

**The plan expected edits in `MeNotificationPreferencesTest` and `NotificationServiceTest` (§4.4). They were not
needed:** those tests assert by path and tolerate new keys. So the consciously-changed contract (a per-type default)
changed **no** existing backend assertion.

New test files: `PushEligibilityTest`, `PushPreferenceDefaultsTest`, `NotificationChannelDefaultArchitectureTest` (updated
scan), `PushTokenRegistryTest` (21), `PushTokenEndpointsTest` (24), `FcmPushSenderTest` (49),
`PushMessageFactoryTest` (31), `SendPushNotificationJobTest` (26), `PushDispatcherTest` (45), `PushTestCommandTest` (22).

## 7. Not verified

1. **The FCM error taxonomy** (`UNREGISTERED`, `INVALID_ARGUMENT` naming the token, `SENDER_ID_MISMATCH`, …) is from
   Google's docs. A token is killed **only** on an explicit signal, and every ambiguous answer is `rejected` or
   `config_error`, never a token kill — so a wrong guess fails safe (a dead token lingers) rather than unsafe (a live one
   dies). First real evidence: `push:test`.
2. **The credential smoke test's key assumption.** `push:test --validate-only` (no user) sends a `validate_only` request
   for a deliberately fake token and treats "provider says the token is bad" as proof that **authentication succeeded**.
   If FCM answers a fake token with something I classify differently (for example a bare `INVALID_ARGUMENT`), the smoke
   test exits non-zero and prints the code. That is a safe failure, but it may need a one-line adjustment after the
   first real run.
3. **FCM auto-init disabled (consent-first)** has not been exercised on a device (plan R-8). It is a Stage 4 item.
4. ~~**The Secrets Manager path** `catalyst/<env>/api/fcm` is a convention guess.~~ **Resolved 2026-10-12:** the credential is not in Secrets
   Manager. It is the service-account file on Pedram's laptop (`~/secrets/catalyst-production-c28e9-firebase-adminsdk-*.json`), shipped to the
   production env file in one pipe (deploy runbook, step 7).
5. **`lang/*/push.php` ×24 are machine-quality.** Names go after a colon / dash / parenthesis so no inflection is
   needed, and the register matches the house style (informal by default, formal in a few locales as a sentence-level
   choice). **A native speaker has not read them.**
6. **Real queue behaviour.** Tests use `sync` or `Queue::fake()`. The `queue:work` + default-queue assumption (Q13) and an
   atomic `CACHE_STORE` are deploy read-backs, not tested here.
7. **`Feature::active` scope.** The flag is read with no scope (the registry-wide `resolveScopeUsing(fn () => null)`), same as every other flag.

## 8. Production posture

- **Schema:** one `CREATE TABLE` (`user_push_tokens`); nothing else changes. `down()` drops it and says that registered
  tokens are lost (they re-register on the next launch).
- **Behaviour with the flag OFF (the state after deploy):** `notify()` still writes in-app rows exactly as before. The
  only difference is that it now also calls the dispatcher, which returns after the static eligibility check
  (ineligible types: zero queries, tested) or one flag read (eligible types). No token is read, no job is queued, no HTTP
  call is made.
- **`notify()` contract:** same signature plus one optional trailing `bool $suppressPush = false`; same return type. The
  early return is gone, so for a user who muted in-app, `notify()` now runs the push leg (still returning `null`).
- **`NotificationService` now has a constructor dependency** (`PushDispatcher`). Nothing in `app/` or `tests/` does
  `new NotificationService`; the container builds it. The full suite is green with it.
- **Rollback:** flip the flag OFF (instant, no deploy). A code rollback leaves an empty-or-populated `user_push_tokens`
  table that nothing reads.
- **New operator surface:** `push:test` (one user, one fixed message, read-only against our data, never prints a token).

## 9. What was NOT done at first review (items 1 and 2 are now DONE — see §12)

1. **C0.3 — the R2 rider (`creator-detail.spec.ts:64`).** Not started. Playwright needs the dev stack down and **your
   `php artisan serve` (:8000) and Vite (:5173) are running**. I have not stopped them. Say the word and I will, run the
   bounded (≤2 h) diagnosis, and put the outcome in `tech-debt.md`.
2. **Full Playwright** at the checkpoint — same blocker. The preference page is E2E-traversed and the `defaults`
   contract changed, so the run is worth having before a push.
3. **Firebase project / owning Google account (Q9)** — yours to supply. Until it exists there is no
   `google-services.json`, so Stage 4 cannot build, and no real FCM call has been made.
4. **Q16 / AH-105** (privacy labels, Data Safety, privacy policy, Google DPA) — yours.
5. **Pre-deploy reads and the GRANT check** — blank in the deploy-log entry, yours to fill.
6. **The app must ignore unknown `data.type` values.** `push:test` sends `type: "push.test"`. Stage 4's tap handler has
   to treat an unknown type as "open the app", not as an error.

## 10. Decisions I made that you may want to overrule

> **Cleared at Checkpoint A:** `push:test` bypassing the flag (operator-only and single-recipient is the containment), the
> throttle slot claimed before the build, and the admin flag wording all stand.
>
> **Accepted deviation, on the record:** break-revert evidence is per-mutation failing counts, not verbatim runner output
> (accepted at 45 mutations; re-run only if a row looks off at review time).

- **`push:test` is not behind `push_notifications_enabled`.** The flag gates product sends; the command is how the
  credential and the device are proven _before_ arming, and it reaches only the one user the operator names.
- **The throttle slot is claimed before the message is built.** If the factory then returns `null` (no copy for that
  type — not reachable for the 14 eligible types today) the slot is spent for ≤60 s. Accepted; the alternative is a
  second cache round-trip.
- **A failed message build consumes the slot for ≤60 s.** The slot is claimed before the message is built (the right trade
  against a double-build race); if the build then yields nothing, the (recipient, thread) pair stays throttled for up to
  60 s. Not reachable today for the 14 eligible types. **Cleared by Pedram at Checkpoint A.**
- **A throttle slot claimed in a transaction that rolls back stays claimed for ≤60 s** (plan §5.4: accepted; message
  notifications are not emitted inside the state-machine transaction).
- **Admin flag description** ("Do not arm until the FCM credential is set and `push:test --validate-only` has passed")
  is my wording.

## 11. Clearance requested

1. Is Checkpoint A cleared to **push** (12 commits + docs)? If yes, I will cite the CI run URL at the pushed tip
   (§5.41) before calling it done.
2. May I stop the dev stack to run **C0.3 and the full Playwright run** first? (My recommendation: yes, before the push.)
3. Anything in §10 you want changed.

## 12. The two open gates, closed (C0.3 and full Playwright)

**Setup.** Dev stack stopped with Pedram's permission (`php artisan serve` :8000, Vite :5173). The admin dev Vite on :5174
(running since Friday) also had to stop: the admin Playwright config reuses it and it proxies to :8000, so both admin
specs failed deterministically against it (3 of 3 attempts each) until it was stopped. Isolated E2E DB `catalyst_e2e`,
`TEST_HELPERS_TOKEN` set, `--retries=2` (CI's value).

### 12.1 C0.3 — the R2 rider: outcome (a), a fix with break-revert (`8c7d3318`)

- **Reproduction.** `creator-detail.spec.ts:64 --repeat-each=30 --retries=0`: **1 of 30 failed — and it was the first
  iteration** (cold Vite), 29 of 29 warm passed. Repeated with a wiped `node_modules/.vite`: failed the first iteration
  again. **3 of 3 cold-cache runs on the original config failed** (the repeat-30 run, the wiped-cache run, and the
  break-revert run below); 0 of 29 warm.
- **Cause (not the plan's hydration hypothesis).** The trace shows `TypeError: isObject is not a function` from a
  `.vite/deps/chunk-…?v=…` file and a second navigation to `/roster`; Vite's own log shows
  `new dependencies optimized: …` then `optimized dependencies changed. reloading`. Vuetify components that live only in
  lazy route chunks are discovered mid-run; each discovery re-bundles and reloads the page, which drops the click's
  navigation. `apps/main/vite.config.ts` already documents exactly this mechanism and pre-declares 30 components; these were
  the ones it missed.
- **Fix.** 14 more modules in `optimizeDeps.include`: `VSystemBar`, `VBottomNavigation`, `VBadge`, `VPagination`,
  `VTooltip`, `VImg`, `VRadio`, `VRadioGroup`, `VSwitch`, `transitions`, `VTabs`, `VWindow`, `VSheet`, `VAutocomplete`.
  Dev-server only; production builds are unaffected.
- **Break-revert.** Original config, cold cache: first iteration **fails**, 3 reload groups in the Vite log. Fixed config,
  cold cache: 3 of 3 pass, **0** reloads. Original again: fails. Fixed restored (`diff -q` clean): 2 of 2 pass, 0 reloads.
- **Time spent:** about 1 h of the 2 h budget.

### 12.2 Full Playwright, main SPA (34 tests, cold Vite cache, `--retries=2`)

| Run                                         | Result                                             | Flaky (passed on retry)                                                                                                                    |
| ------------------------------------------- | -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| 1 — with the first 10 modules only          | 32 passed, 2 flaky, exit 0                         | `2fa-enrollment-and-sign-in.spec.ts:93` (30 s timeout, passed on retry); `creator-wizard-happy-path.spec.ts:91` (URL stayed `/onboarding`) |
| 2 — with all 14 modules (the committed fix) | 33 passed, **1 flaky**, exit 0, **0 Vite reloads** | `2fa-enrollment-and-sign-in.spec.ts:93` — failed attempts 1 and 2, passed on retry 2                                                       |

- `creator-detail.spec.ts:64` passed first time in both full runs. `creator-wizard-happy-path.spec.ts:91` (a known flake)
  flaked in run 1 at the exact moment Vite logged `VAutocomplete` as newly optimized, and not in run 2: same cause, same fix.
- **`2fa-enrollment-and-sign-in.spec.ts:93` is NOT one of the known three, and it is recorded, not waved through.** It is the
  first test of the run. In isolation it takes ~26 s of a 30 s timeout: 4 of 4 warm passes (26.2, 25.9, 26.0, 25.1 s), 1 of 1
  cold pass with the original Vite config (26.2 s) and 1 of 1 with the fixed one (27.2 s). So it is marginal on this machine
  whatever the Vite config, and a loaded cold start tips it over. It never reaches `notify()`, so AH-102 cannot cause it.
  Parked in `tech-debt.md` (item 7). If CI shows it, it is a timeout bump, not a push finding.

### 12.3 Full Playwright, admin SPA (2 tests, `--retries=2`)

1 passed, **1 flaky**: `admin-sign-in.spec.ts:69` (a known one; failed attempt 1, passed on retry 1). Not diagnosed
(recorded only, per plan). The admin Vite config has the same lazy-component pattern; noted in `tech-debt.md`.

### 12.4 The known three, accounted for

| Known flake                            | This run                                             |
| -------------------------------------- | ---------------------------------------------------- |
| `creator-detail.spec.ts:64`            | passed first time (both full runs); root cause fixed |
| `creator-wizard-happy-path.spec.ts:91` | flaked once (run 1), 0 times after the fix (run 2)   |
| `admin-sign-in.spec.ts:69`             | flaked once, passed on retry; not diagnosed          |

Plus one new: `2fa-enrollment-and-sign-in.spec.ts:93` (above).

### 12.5 Incidental evidence

The `user_push_tokens` migration ran under real Postgres (`migrate:fresh` against `catalyst_e2e`, all migrations including
`2026_10_10_100000_create_user_push_tokens_table`, no errors). Every other migration test so far had been SQLite.

## 13. Push

Pushed to `origin/main` on 2026-10-11: `4d468406..6e827e40` (fast-forward, 17 commits: inventory, plan, Stages 0–2,
the docs pair, C0.3, and the gates docs commit).

- **CI at the pushed tip `6e827e40`:** [run 38135748678](https://github.com/pedram-kh/Engine/actions/runs/38135748678) —
  **success**, all four jobs: Backend (Pint + Larastan + Pest), Frontend (lint + typecheck + unit), E2E main
  (34 passed, 0 flaky), E2E admin (1 passed, **1 flaky**).
- **CI flake, recorded not waved through:** `admin-sign-in.spec.ts:69` (admin 2FA inline-TOTP sign-in) failed once and
  passed on retry — the same spec that flaked locally (§12.3); it is on the known-flake list, undiagnosed, and unrelated
  to push. The main suite had no flakes in CI, including `2fa-enrollment-and-sign-in.spec.ts:93` (tech-debt item 7).
- A docs-only provenance commit (this section) follows the tip; it changes no code.

---

# Checkpoint B — the matrix column, the app's push module, Android (Stages 3–4)

> ⚠ **Nothing in Checkpoint B has run on a device, and no real FCM call has ever been made.** Every behaviour below is
> proven by Vitest (fakes at the Capacitor and HTTP seams), a Gradle build, a merged-manifest read and an image
> inspection. The first real answers come from your E3 walk ([`push-notifications-device-walk.md`](push-notifications-device-walk.md)).
>
> ✅ **`google-services.json` is in** (commit `9119b9c4`, Firebase project "Catalyst Production"); the release APK carries the
> project's `google_app_id`. Still nothing has run against real FCM.
>
> ⚠ **R-8 (FCM auto-init off) is unverified.** The manifest keeps it off so no token exists before consent. If E3 item 12
> fails, the fallback is default auto-init (§19).

## 14. What Checkpoint B built (6 local commits + the docs commit)

| Commit | SHA        | What                                                                                                              |
| ------ | ---------- | ----------------------------------------------------------------------------------------------------------------- |
| docs   | `1b16a6f6` | Checkpoint A provenance (§13). Stayed local, rides with this push (your ruling)                                   |
| C3.1   | `f61c0ec1` | push column in the preference matrix, native only; `pushChannelAvailable()`; `type_defaults` composed in the page |
| C3.2   | `20942da6` | push label and state strings ×24, with an architecture spec                                                       |
| C4.1   | `a377cf97` | registration, soft-ask sheet, state row, tap and foreground handling, logout cleanup, boot wiring                 |
| C4.2   | `6d07b727` | Android FCM wiring: plugin, manifest meta-data, derived notification icon, config spec                            |
| test   | `55b4ef21` | pins the soft-ask sheet as `persistent` (a break-revert survived; §20.6)                                          |
| docs   | (this)     | this review, the device-walk scaffold, the docs pair, tech-debt, resumption                                       |

**Placement notes (where something is not in the commit the plan named):** the state-row **strings** are in C3.2 but the
**component** that uses them is in C4.1 (the strings are inert until then, and the parity spec needs them in all 24
locales at once); the `@capacitor-firebase/messaging` **dependency** is in C4.1, not C4.2, because C4.1 is the first code
that imports it and `pnpm install` must precede its typecheck and build.

## 15. Stage 3 — the matrix column (C3.1, C3.2)

- **Registry:** the nine toggleable types and `message.received_by_creator` list `push`; `creator.approved` /
  `creator.rejected` carry it as an always-on channel. `NATIVE_ONLY_CHANNELS = ['push']` is stripped by
  `preferenceGroupsForRole()` by default, so **web and every pre-existing spec are unchanged**; the page passes
  `includeNativeOnly: pushChannelAvailable()` (P-8: native AND (Android OR `IOS_PUSH_ENABLED`, a constant `false`)).
- **Defaults on the wire:** the page resolves `row?.is_enabled ?? type_defaults[type]?.[channel] ?? defaults[channel]`,
  so `campaign.job_posted` shows Push OFF until the creator opts in, and saving writes the composed value (a row is
  stored, as in Checkpoint A's write-side pin).
- **Parity with the server, from source:** `templates.spec.ts` reads `PushEligibility.php` and `NotificationType.php` and
  fails if the client registry and the server policy drift (14 types, 10 toggleable, 4 always-on).
- **Strings:** 24 locales. The ten that often leak English (bg, el, et, fi, ga, hu, lt, lv, mt, ro) are asserted not to
  equal English (only the "Push" column header is exempt, and asserted to be "Push"). Same machine-quality caveat as
  `push.php` (§7.5): not native-read.
- **Break-reverts (per-mutation failing counts; each reverted, diff clean, suite re-green):**
  BR-14a registry stops stripping native-only → **11** failed (4 new, 6 in the UNEDITED page spec, 1 web-polarity);
  BR-14b page always passes `includeNativeOnly: true` → **7** (6 in the unedited page spec); BR-15 page ignores
  `type_defaults` → **3**; BR-14c delete a type from `PushEligibility::TOGGLEABLE` → **2** (the parity spec and the
  ruled-count pin); BR-18a ro `softAsk.title` set to English → **1**; BR-18b lv drops a key → **2**.
  (BR-14b's first attempt matched nothing, because prettier had split the line, and showed 0 failing; it is recorded as
  a non-run and re-done: 7.) BR numbers are per commit, so "BR-16" in C2.5 and in C4.1 are different mutations.

## 16. Stage 4 — the app's push module and Android (C4.1, C4.2)

### 16.1 C4.1 behaviour (all in `apps/main`)

| Rule                                                                                                                                                 | Where it is pinned                                                            |
| ---------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| Consent first: no token and no OS prompt until the creator accepts OUR sheet                                                                         | `pushRegistration.spec.ts` (accept/decline/denied paths)                      |
| A declined or answered soft-ask is not shown again for 30 days; an OS deny is never re-prompted                                                      | cool-down boundary tests (both sides of 30 d); BR-19, BR-27                   |
| Never registers while signed out                                                                                                                     | BR-20                                                                         |
| Upkeep re-POSTs at most daily; a repeat of the same token is not a rotation; rotation needs granted permission                                       | BR-22, BR-25, BR-26                                                           |
| Logout: forget at the server first, then `deleteToken()` at FCM, local record wiped, bounded at 3 s, never throws, runs BEFORE the bearer is cleared | `useAuthStore.pushLogout.spec.ts` (order pinned); BR-17, BR-21, BR-28, BR-29  |
| iOS builds do nothing while `IOS_PUSH_ENABLED` is `false`                                                                                            | `pushChannelAvailable.spec.ts`; BR-16                                         |
| Foreground push refreshes the bell (a `document` event the poll already listens for); tap routes to `creator.notifications`                          | `startPush.spec.ts`, `useNotificationPoll.refreshEvent.spec.ts`; BR-23, BR-24 |
| Unknown `data.type` values open the app (they are never inspected: tap always goes to the notifications page)                                        | `startPush.spec.ts`                                                           |
| Soft-ask shows once per mount, only on the dashboard, only for pending/approved creators, never at launch or sign-in                                 | `PushSoftAskSheet.spec.ts`, `CreatorDashboardPage.push.spec.ts`               |
| Settings row: "Turn on" only while the permission is `prompt`; no button when denied; re-reads the OS state on `visibilitychange`                    | `PushStateRow.spec.ts`                                                        |

**C4.1 break-reverts (scope: push module + notifications + the auth and dashboard push specs, 204 tests, baseline all
pass; each mutation restored and re-checked):** BR-16 remove the iOS gate → 1 failed · BR-17 cleanup after the token
clear → 1 · BR-19 ignore the cool-down → 1 · BR-20 register while signed out → 1 · BR-21 no logout timeout → 2 ·
BR-22 ignore the daily window → 1 · BR-23 drop the foreground handler → 1 · BR-24 drop the tap handler → 1 · BR-25 no
rotation dedupe → 1 · BR-26 rotation without granted permission → 1 · BR-27 soft-ask ignores the OS state → 4 ·
BR-28 skip `deleteToken()` at logout → 2 · BR-29 keep the local token record at logout → 1.

**Bundle check:** the plugin's web half imports the optional peer `firebase/messaging`. A Vite alias points it at a
stub (`firebaseMessagingStub.ts`); the production build passes and `dist` contains **no Firebase web SDK strings** (a
2 KB stub chunk and a 637-byte plugin chunk).

### 16.2 C4.2 evidence

- **`npx cap sync android` only.** Diff: two generated Gradle files (+1, +3 lines). `ios/` is untouched; a spec fails if
  `Package.swift` ever mentions Firebase (R-7).
- **Builds:** `assembleDebug` and `assembleRelease` both **BUILD SUCCESSFUL** (JDK 21; the system JDK 25 is rejected by
  Gradle 8.14.3 — `Unsupported class file major version 69`).
- **Merged release manifest:** `POST_NOTIFICATIONS`, c2dm `RECEIVE`, `WAKE_LOCK`, `ACCESS_NETWORK_STATE` arrive from the
  plugin and Firebase (no manual permission needed); our four meta-data are present (auto-init `false`, analytics
  `false`, default channel `catalyst_default`, default icon `@drawable/ic_stat_catalyst`); `MessagingService` and
  `FirebaseMessagingService` are registered; the release manifest has **no** cleartext or network-security-config (that
  stays debug-only).
- **The conditional Google-services apply** (`android/app/build.gradle:48-53`) was proven with a throwaway
  `google-services.json` (fake project, deleted at once, never staged): `:app:processDebugGoogleServices` ran and debug
  assembled. Without the file the plugin is not applied.
- **The push icon (your E1 item).** Derived from `catalyst-logo.svg`, the same source as the launcher icon: pure white on
  transparent, 24 dp box with a 22 dp mark, at the five notification densities (24/36/48/72/96 px). The first cut showed
  a grey haze inside the C: the logo's soft inner glow is faint but non-zero alpha (≤ 43) and Android would tint it.
  The script now keeps the shape and drops the glow (alpha ≤ 64 → 0, ≥ 160 → 255). Preview, tinted as Android draws it,
  on a dark and a light shade, plus the real 24 dp size enlarged on the right of each:

  ![push icon preview](push-notifications-assets/push-icon-preview.png)

  Regenerate with `pnpm --filter @catalyst/main assets:push-icon`.

- **Break-reverts (14-test config spec):** BR-30 auto-init `true` → 1 failed · BR-31 analytics on → 1 · BR-32 manifest
  channel ≠ the server's → 1 · BR-33 icon colour/grey (caught by the reproducibility check; the white check guards a
  regenerated icon) → 1 · BR-34 glow not dropped → 1 · BR-35 plugin missing from the Android build → 1 · BR-36 Firebase in
  the iOS `Package.swift` → 1 · BR-37 icon meta-data points elsewhere → 1.
- **`capacitor.config.ts` is unchanged:** `FirebaseMessaging.presentationOptions` is iOS-only (P-5 is for Stage 5);
  Android foreground pushes are handled in JS and show no system banner.

## 17. Existing tests and gates touched in Checkpoint B (the §4.4 list, as built)

| File                                                                                                              | Change                                                                                                                | Why                               |
| ----------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- | --------------------------------- |
| `apps/main/tests/unit/architecture/use-theme-is-sot.spec.ts`                                                      | **+1 allowlist entry** (`composables/usePushPreference.ts`) with a comment. **A conscious edit of an existing gate.** | non-theme persistence (see below) |
| `NotificationPreferencesPage.spec.ts`, `useAuthStore.native.spec.ts`, every pre-existing `templates.spec.ts` case | **unedited**, and passing — they are the proof that web and the existing native logout are unchanged                  | —                                 |
| `templates.spec.ts`                                                                                               | one new `describe` (the push-column registry); no existing case edited                                                | C3.1                              |

**The one gate edit, explained.** The push module keeps local state (the soft-ask cool-down and the upkeep clock) in
WebView `localStorage`, and the theme-SoT architecture test forbids direct `localStorage` calls outside an allowlist. Its
own message says "for non-theme persistence open a tech-debt note + extend this allowlist", which is what I did, the way
`useLocalePreference.ts` is already allowlisted: the persistence moved into one file, `composables/usePushPreference.ts`
(the push module imports it), and **only that file** is allowlisted. Its own spec uses `Storage.prototype` spies so it
does not need a second entry. A `docs/tech-debt.md` note records it, including that the allowlist should be generalised
if it grows again. Say if you would rather have `@capacitor/preferences` instead and no allowlist change.

**Decisions I made that you may want to overrule (Checkpoint B):**

- **No Android settings deep-link** from the denied state: the plugin offers none, so the row is explanatory text with no
  button. A dead button seemed worse. (`tech-debt.md` item 9.)
- **The `firebase/messaging` Vite alias** to a stub (`tech-debt.md` item 10), to keep the Firebase web SDK out of a
  native-only feature's bundle.
- **Soft-ask copy and trigger:** pending or approved creators, once per dashboard mount, then a 30-day cool-down (P-2).
- **A decline and an OS answer both start the cool-down**, and an OS deny is never re-prompted. The state row is the only
  way back.
- **Logout waits at most 3 s** for the cleanup, then proceeds; offline logout leaves the server row to lapse with the
  login (R-4, accepted).

## 18. Flake accounting, updated (your ruling on the C0.3 outcome)

- **R2 rider — closed.** The C0.3 outcome (a fix with a break-revert, `8c7d3318`) closes it.
  **`creator-wizard-happy-path.spec.ts:91` is covered by the same root cause** (cold Vite dependency cache re-optimising
  and force-reloading mid-test): it flaked once in the first full run and **0 times after the fix** (§12.4).
- **`admin-sign-in.spec.ts:69` stays on the watch-list as the one remaining undiagnosed flake.** It failed once and
  passed on retry locally (§12.3) and in CI at the Checkpoint A tip (§13). `tech-debt.md` item 13.
- **Local Vitest, one load flake:** `CreatorDashboardLayout.spec.ts` "renders the Invitations nav item" timed out at 5 s
  in one full run while Pest and Gradle shared the machine; it passes in the grouped run and in isolation, and the file
  is untouched by this chunk.

## 19. Checkpoint B gate board (at the test tip `55b4ef21` plus the docs)

| Gate                                        | Result                                                                                                                                                                                     |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Backend Pest, full, serial, 2G              | **0 failed.** 3 239 deprecated-but-passing + 5 passed, 16 390 assertions (identical to Checkpoint A: no backend code changed in B; the PHP 8.5 `PDO::MYSQL_ATTR_SSL_CA` noise is as in §5) |
| Pint `--test`                               | pass                                                                                                                                                                                       |
| PHPStan / Larastan (`--memory-limit=2G`)    | `[OK] No errors`                                                                                                                                                                           |
| Vitest, all workspaces                      | design-tokens 42 (+1 todo) · api-client 221 · ui 47 · apps/admin 488 · apps/main **2 319** (2 318 at the C4.1 full run, +1 for the sheet's persistence test) — **all pass**                |
| ESLint, all frontend                        | 0 errors; 2 warnings, both pre-existing (`v-html` in `apps/main`)                                                                                                                          |
| vue-tsc / tsc, all frontend                 | pass                                                                                                                                                                                       |
| 24-locale parity                            | `i18n-push-strings.spec.ts` (new), `i18n-notifications-parity` and backend `LangParityTest`, inside the runs above                                                                         |
| Production build (`apps/main`)              | pass; no Firebase web SDK in `dist` (§16.1)                                                                                                                                                |
| Android `assembleDebug` / `assembleRelease` | both **BUILD SUCCESSFUL** (JDK 21); merged manifest read (§16.2)                                                                                                                           |
| Playwright (main, admin)                    | **Run 2026-10-11 at `8521aadc` — all 36 tests pass, but not in one clean run on this host; see §23.** (Not run at the original B tip: the dev stack was up.)                               |

**Two environment notes.**

1. **Vitest with `--coverage` is flaky on this machine, and CI does not run it** (`pnpm test:frontend` is plain
   `vitest run`; the auth module's 100% line gate lives only in `test:coverage`). Two full coverage runs each timed out
   1–3 _different, unrelated_ specs at the 5 s default (`CreatorProfilePage`, `BrandListPage`, `CreatorDashboardLayout`);
   every one passes in isolation and in the plain run. I did not raise a timeout. The `useAuthStore.ts` change in B is six
   lines (the cleanup call), exercised by `useAuthStore.pushLogout.spec.ts`.
2. **Gradle needs JDK 21.** The system default (25) is rejected (`Unsupported class file major version 69`).

## 20. Not verified (Checkpoint B), and what is not tested

1. **Everything on a device** (E3), including the lock-screen copy in a non-English locale, the channel as the OS shows it,
   tap-from-killed, foreground arrival, and the throttle and collapse in practice.
2. **R-8: FCM auto-init off.** That an explicit `getToken()` mints a token, and that rotation fires `tokenReceived`, with
   `firebase_messaging_auto_init_enabled=false`. The fallback is in the device-walk doc.
3. **`main.ts` wiring has no spec.** `startPush(...)` with `onTap → router.push({name:'creator.notifications'})`,
   `onForeground → requestNotificationsRefresh`, and the `watch` on the auth user id are the boot file's glue; the pieces
   they call are all specced (`startPush.spec.ts`, `useNotificationPoll.refreshEvent.spec.ts`), the glue is not. E3 items
   4 and 5 are its test.
4. **Real FCM**, as in §0 and §7. `google-services.json` and the service-account credential do not exist yet.
5. **The `catalyst_default` channel's visible name** is the i18n string at the time of registration; it follows the
   locale only the next time registration re-asserts it (at most daily).
6. **Break-revert evidence for B is per-mutation failing counts**, in the same accepted form as A. Totals: C3 6
   mutations, C4.1 13 + 7 (`startPush`, the sheet, the dashboard trigger, the state row), C4.2 8. The 7 added late are
   BR-38 `startPush` ignores availability → 1 · BR-39 foreground sync while hidden → 1 · BR-40 `stop()` leaves the
   listener → 1 · BR-41 sheet ignores eligibility → 1 · BR-42 sheet not `persistent` → **0 at first (the mutation
   survived: nothing pinned it)**, then a spec pinning the prop was added and the mutation fails it → 1 · BR-43 the
   dashboard asks every status → 2 · BR-44 "Turn on" shown when denied → 2.

## 21. Clearance requested

1. **Eyes-on E1, E2, E3** (the device-walk doc has the scaffold). For E1, the push icon preview is in §16.2.
2. **May I stop your `php artisan serve` (:8000) again** to run the full Playwright suites on the isolated stack before
   the push? (I will tell you when it is back to yours; it restarts as you started it.)
3. **Put the FCM credential in your local `apps/api/.env`** (`FCM_PROJECT_ID=catalyst-production-c28e9`,
   `FCM_SERVICE_ACCOUNT_JSON_B64`) for E3. The Firebase project, `google-services.json` and the iOS prerequisites are done
   (§22); the service-account key stays with you until deploy.
4. **The one gate edit** (§17) and the decisions listed there.
5. **The push itself:** `1b16a6f6` + C3.1 + C3.2 + C4.1 + C4.2 + the test, `google-services.json`, iOS wiring and docs commits, as one push, after the above.
   Nothing is pushed.

## 22. Stage 5 (iOS) — wired, not enabled (added 2026-10-11)

Q19 is now yes (Apple account active, Team `LTW9262ZXP`, APNs key uploaded to Firebase, Push on the App ID,
`GoogleService-Info.plist` supplied). Two commits, both local:

- **`9119b9c4`** `google-services.json` committed (Q14). Evidence: `processDebugGoogleServices` and
  `processReleaseGoogleServices` run; the release APK holds `google_app_id` `1:908297825153:android:4dd8…`, `project_id`
  `catalyst-production-c28e9`, `gcm_defaultSenderId` `908297825153` (read with `aapt2`). The config spec pins the package
  name to the app id, the one production project, and that no service-account key can be pasted into it (BR-45..47: 1
  failing each).
- **`0d51aed9`** the plan's 5.2 wiring: `cap sync ios`; the SwiftPM symlink option; `App.entitlements`
  (`aps-environment=development`; Xcode's automatic signing swaps in `production` for TestFlight and App Store archives);
  `DEVELOPMENT_TEAM=LTW9262ZXP` on both configurations; the plugin's three AppDelegate forwards; FCM auto-init off;
  foreground `presentationOptions: []` (P-5); `GoogleService-Info.plist` as a bundle resource.
  **`IOS_PUSH_ENABLED` stays `false`**, so no iOS user sees a Push control before the iPhone spike (the plan flips it in
  5.2; I held it back because the spike is what decides go or no-go, and Checkpoint B must not carry an unproven iOS push).

**Evidence (the plan's R-6, partly):** the SwiftPM graph resolves and the app builds for a generic iOS device in Debug and
Release, unsigned (`** BUILD SUCCEEDED **`), under Xcode 26.0. The release binary links Firebase Messaging (192 string
hits, 2 MB) and **not** the GoogleAppMeasurement SDK (only the interop stubs inside Firebase Core and Messaging).
`FirebaseMessagingAutoInitEnabled=false` and `GoogleService-Info.plist` are in the built bundle.

**Two things that went wrong on the way, kept because the next person will meet them:**

1. The first `xcodebuild` died resolving packages (`grpcpp.zip … does not contain a binary artifact`); an immediate retry
   resolved. Transient.
2. **A build that succeeded without Firebase in it.** After the second attempt, the Release binary was 260 KB and
   contained no Firebase symbols, yet reported success; it was a stale build cache from before the package graph changed.
   After deleting the project's DerivedData `Build` folder it rebuilt at 2 MB with Firebase linked. I only caught it by
   inspecting the binary, so this is in the device-walk doc as the first thing to check if push is dead on the phone.

**Spec:** `tests/unit/native/pushIosConfig.spec.ts` (15). Break-reverts, each 1 failing: BR-48 AppDelegate drops the
APNs-token forward · BR-49 auto-init on · BR-50 entitlement dropped from one configuration · BR-51 team removed from one
configuration · BR-52 plist removed from the Resources phase (**survived at first**: my regex also matched the build-file
definition line; tightened to the Resources phase and re-run: 1) · BR-53 plist for another bundle id · BR-54 plist from
another Firebase project · BR-55 foreground banner left at the plugin default · BR-56 SwiftPM symlink option dropped ·
BR-57 plugin missing from the Swift package · BR-58 entitlement key removed. The earlier "iOS has NOT picked the plugin
up" assertion (R-7) was **removed from `pushAndroidConfig.spec.ts`** because the wiring now intentionally exists (a
conscious edit of my own C4.2 spec; `tech-debt.md` item 1 records R-7 as resolved).

**Not done, and cannot be by me:** 5.1 on a real iPhone (go/no-go), the signed archive (needs your Xcode account), and 5.3.
The spike needs a **local, uncommitted** `IOS_PUSH_ENABLED = true` or the phone never shows the soft-ask; the steps are in the
device-walk doc §6. On go, that flip is its own commit; on no-go, plan §5.4 (fallback B) applies and the two Stage 5 commits
are the part to reconsider.

**Closing AH-102 with Stage 5:** possible if the spike goes well. What it adds to Checkpoint B: this section, the flip commit,
and the §4 walk run on iOS twice (debug and TestFlight).

## 23. Playwright at `8521aadc` (run 2026-10-11, on the isolated E2E stack)

Main SPA 34 tests, admin SPA 2 tests, `--retries=0` (the local config), dev stack stopped under the standing approval.
**All 36 pass, but not in a single clean run, and the failures are this host, not AH-102.**

| Run                                         | Result                                                                                                                                                                                                  |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Main, full, as-is                           | 33 pass, 1 fail: `2fa-enrollment-and-sign-in.spec.ts:93`                                                                                                                                                |
| Same spec on `origin/main` (`6e827e40`)     | **Fails identically**, twice. So it predates AH-102.                                                                                                                                                    |
| Cause                                       | `POST /auth/2fa/confirm` took 7.2 s: it bcrypts 10 recovery codes (`TwoFactorService::hashRecoveryCode`, driver pinned to `bcrypt`, default 12 rounds), and the spec waits 5 s for the codes to appear. |
| Main, full, with `BCRYPT_ROUNDS=4` exported | 31 pass, 3 fail, all pure timeouts: `2fa-enrollment` (33 s vs 30 s), `brands.spec:108` (35 s vs 30 s), spec #20 (90 s cap). Host load average was 55–90 (Docker, Spotlight, others).                    |
| The three, `--timeout=120000`               | All pass (32 s, 41 s, 1.4 min).                                                                                                                                                                         |
| Admin, full                                 | `admin-sign-in.spec.ts:69` failed once: the redirect to `/` landed at the very end of its 5 s `toHaveURL`. `--repeat-each=3`: 3/3 pass (9–11 s).                                                        |

**What this says.** Nothing in the AH-102 diff reaches these specs (no auth, 2FA, brands or sign-in code changed on web). The
hosts differ in speed, not behaviour: CI passes them at the default timeouts. I did **not** edit any timeout, spec or
`playwright.config.ts`. `BCRYPT_ROUNDS=4` and `--timeout=120000` were shell-level overrides for this local run only.

**For the record:** `admin-sign-in.spec.ts:69` flaked once more, the same shape as the watch-list entry (slow redirect, then
passes). It stays on the watch-list; this is a second data point that it is load-sensitive, not a diagnosis.
`creator-wizard-happy-path.spec.ts:91` and `creator-detail.spec.ts:64` (the R2 rider) passed in the full run.

**Still owed:** CI at the pushed tip is the only run at default timeouts on a fast host; it is cited after the push.

## 24. The Android walk (E1, E2, E3), run 2026-10-11

**Device:** Samsung SM-A515F, debug build, local API, **real FCM v1** against `catalyst-production-c28e9`. Every result, with
its evidence, is in [`push-notifications-device-walk.md`](push-notifications-device-walk.md) (§2 to §4); the gotchas are its §7.

| Block                                   | Result                                                                                                                                          |
| --------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| E1.1 to E1.5 (matrix, icon)             | **Pass**                                                                                                                                        |
| E2.1 to E2.5 (soft-ask)                 | **Pass**, E2.4 with a wording note (one denial is `prompt-with-rationale`, the button is live; the second denial shows "blocked")               |
| E3 items 1, 2, 3, 5, 6, 7, 8, 9, 10, 11 | **Pass**                                                                                                                                        |
| E3 item 4 (tap)                         | Background **pass**. Killed **FAILED**, found and fixed (`72afb8ae`), re-run from a real kill **pass**                                          |
| E3 item 12 (R-8)                        | **Partly**: no token before consent, explicit `getToken()` works, `pm clear` + re-accept leaves one live row. **Rotation re-POST not verified** |

**What the walk found (none was visible to the tests):**

1. **The cold-start tap was lost** (item 4). The tap that launches a killed app is delivered the moment the listener registers,
   before the router's first navigation, and an immediate `router.push` is overtaken by the dashboard. Fix: wait for
   `router.isReady()`, extracted to `pushTapRouting.ts` with a spec (3 tests; break-reverts BR-59 push immediately: 2 failing,
   BR-60 wrong route: 1, BR-61 no catch: unhandled rejection fails the run). The old inline handler in `main.ts` had no spec.
2. **My walk doc was wrong in two places:** `push:test` never invalidates a token (by design), so item 10 needs a real emit;
   and `push:test` is English-only, so the localisation check needs a real emit as well.
3. **Environment traps, all on the dev machine, none in the product:** a pending migration, a bare `localhost` in
   `SANCTUM_STATEFUL_DOMAINS` (419 for the Android origin), a stale exported `SANCTUM_STATEFUL_DOMAINS` overriding `.env` for a
   server I started, and `am force-stop` silently blocking FCM. A line-merge in `.env` (an unsaved edit) once broke CORS for
   the app. The debug build also logs the stored bearer token to the Android console.
4. **FCM behaviour observed:** after an uninstall, the first sends are still accepted; `UNREGISTERED` arrives on a later one
   and the real path invalidates once, with no retry and no failed job.
5. **First real localised copy:** Spanish `creator.approved` read "Cuenta aprobada / Tu cuenta de creador fue aprobada. Abre la
   app para empezar", neutral. Still **not** native-reviewed (tech-debt item 5), and only one of the 23 non-English locales
   was seen on a device.

**Dev data the walk changed** (local database only): user 14's preferred language is `es`; user 14 has stored preference rows
(`message.received_by_creator` in-app and push off, `campaign.job_posted` push on); about 15 test notifications for user 14
(the bell shows 20); creator 9 was set to pending and re-approved through the admin SPA (`approved_at` now 2026-10-11 17:37,
by admin 3); the `user_push_tokens` migration was applied; a bare `localhost` was removed from `SANCTUM_STATEFUL_DOMAINS` in
`apps/api/.env` (the original line is in the walk notes).

**Not done:** the iOS spike (5.1) and the signed archive; item 12's rotation re-POST; the 22 other locales on a device;
Playwright at the default timeouts on a fast host (CI at the pushed tip).

## 25. Is the bearer-token logging structurally debug-only? (checked 2026-10-11, before the push)

**Short answer: yes, by the build type, with one override that is now pinned shut. A release build emits nothing.**

**What logs it.** Not our code (no `console.*` in `src/` touches a token). The Capacitor bridge writes plugin results to the
WebView console, and the Android `WebChromeClient` forwards the console to logcat as `Capacitor/Console`. That is how
SecureStorage's `internalGetItem` result, the stored token, reached logcat in the debug build.

**The gate, from the installed `@capacitor/android` source (not a runtime flag of ours):**

- `CapConfig.java`: `loggingBehavior` defaults to `debug`, and `debug` means `loggingEnabled = isDebug`, where
  `isDebug = (applicationInfo.flags & FLAG_DEBUGGABLE) != 0`.
- `Bridge.java` passes that same `config.isLoggingEnabled()` into the injected JS (`cap.isLoggingEnabled`), which is what
  switches the JS-side plugin-result logging (`native-bridge.js`). One value gates both halves.
- `FLAG_DEBUGGABLE` is the APK's `android:debuggable`, fixed at build time by the build type. The app, the server and the
  user cannot change it on an installed build. It is a property of the artifact, not a toggle.
- The one way around it is `loggingBehavior: 'production'` (unconditional) in `capacitor.config.ts`. It is not set.

**Evidence from the artefacts (release built with `pnpm build:native`, `cap sync android`, `assembleRelease`):**

| Check                                                                                    | Debug APK                                    | Release APK                  |
| ---------------------------------------------------------------------------------------- | -------------------------------------------- | ---------------------------- |
| `aapt2 dump xmltree` merged manifest, `android:debuggable`                               | `true`                                       | **absent** (so false)        |
| `networkSecurityConfig` (the cleartext-to-localhost config)                              | present                                      | **absent**                   |
| `assets/capacitor.config.json`, `loggingBehavior` and `android` keys                     | n/a                                          | **both unset**               |
| On the phone: `adb shell run-as com.catalystgrowth.platform`                             | works                                        | **"package not debuggable"** |
| Launch, then logcat for the app's pid, lines tagged `Capacitor/` (plugin calls, console) | present (the walk saw them, incl. the token) | **0**                        |
| Same logcat, lines containing "token", any case                                          | present (the walk saw it)                    | **0**                        |

The release APK was re-signed with the local debug keystore only to install it for this check (aligned and signed under
`/tmp`, deleted afterwards), then replaced by the debug build. In the release launch the only Capacitor-related log lines were
one `Capacitor: Starting BridgeActivity` (emitted before the config loads, no data) and two Android `ConnectivityManager`
stack-trace lines that mention class names.

**What I could NOT show.** A signed-in release session: the release build has no cleartext path to the local API, so it
cannot sign in against it, and a production API was not part of this check. The absence of token output in release therefore
rests on the gate above (plus the empty `Capacitor/` log at launch, where the same plugin calls that logged in debug ran
silently), not on watching a token being stored and not logged.

**Pinned:** `tests/unit/native/androidLoggingDebugOnly.spec.ts` (3 tests) fails if `capacitor.config.ts` sets
`loggingBehavior` or `webContentsDebuggingEnabled`, if the main manifest or the release build type can make the app
debuggable, or if a `@capacitor/android` bump changes the `debug` default or stops deriving it from `FLAG_DEBUGGABLE`.
Break-reverts, each 1 failing: BR-62 `loggingBehavior: 'production'` · BR-63 `android:debuggable="true"` in the main manifest ·
BR-64 `debuggable true` in the release build type. The dependency-default test was checked by running its pattern against the
library source and against an edited copy (matches the original, not the edit), not by editing `node_modules`.

**Still true:** in a debug build the token is in logcat. Debug builds are the developer's own; the rule in the walk doc
(§7.5) is not to paste logcat. Signing out revokes the token.

## 26. Pushed, and CI at the pushed tip (2026-10-11)

**Pushed:** `origin/main` `6e827e40..9dd7f167` (15 commits: `1b16a6f6` … `9dd7f167`). Checkpoint A was already pushed
(`6e827e40`); this is Checkpoint B (app). **Pushed, not deployed.** `push_notifications_enabled` is OFF and
`IOS_PUSH_ENABLED=false`.

**CI at `9dd7f167`:** [run 38163464156](https://github.com/pedram-kh/Engine/actions/runs/38163464156), conclusion **success**,
all four jobs green (Frontend, Backend, E2E admin, E2E main).

- **E2E main SPA (Playwright): 34 passed, 0 flaky, 0 failed.** This is the clean Playwright run that §23 deferred to CI,
  including `2fa-enrollment-and-sign-in.spec.ts:93`, which fails on a loaded laptop (bcrypt cost against a 5 s wait) and
  passes on the runner.
- **E2E admin SPA (Playwright): 1 flaky, then passed on retry** (`admin-sign-in.spec.ts:106`: the URL stayed on `/sign-in`
  on the first attempt). The admin app is untouched by AH-102 (no file under `apps/admin` changed in this range), and the run is
  green on retry. Recorded as seen, not diagnosed; if it recurs it is a separate tech-debt row.

## 27. iPhone spike: `NSAllowsLocalNetworking` in the committed Info.plist (2026-10-11)

A real iPhone cannot reach the Mac's `localhost:8000` (no `adb reverse` equivalent), so the spike reaches the API over Wi-Fi.
Approved by Pedram and kept in the committed plist: `NSAppTransportSecurity` → `NSAllowsLocalNetworking = true`. It only
relaxes ATS for local-network traffic (unqualified hosts, `.local`, IP addresses); it is not `NSAllowsArbitraryLoads` and sets
no `NSExceptionDomains`, and production builds talk to an HTTPS API, so it changes nothing for them.
Pinned in `pushIosConfig.spec.ts` (16 tests); break-reverts, each 1 failing: BR-66 the broad key instead · BR-67 the broad key
added alongside · BR-68 the key removed. For the spike only (not committed): `IOS_PUSH_ENABLED = true`, a gitignored
`.env.native-development.local` with the Mac's LAN URL, and the API bound to `0.0.0.0` (reverted to `127.0.0.1` when the spike ends).

## 28. The iPhone spike and the iOS walk (2026-10-11)

**Go.** S5.1 to S5.5 passed on a real iPhone (iOS 27.0.1, Xcode debug install, sandbox APNs through the one uploaded key): the
app builds and installs, the permission prompt works, an FCM token (`platform=ios`, 142 characters) registers, and a push
arrives through FCM. Plan §5.4 (`ApnsPushSender`, fallback B) is **not needed**. Details: `push-notifications-device-walk.md` §6.

**What the spike found**

- **A bug that only a device could find (fixed, `9d28206c`).** `register()` called `createChannel` first; the plugin answers it on iOS with
  "Not implemented on iOS", and the `catch` ended registration silently before `getToken()`: no token, no POST, no error. Now Android-only,
  specced with the plugin's real rejection (BR-69, BR-70).
- **The sign-in survives a delete and reinstall** (iOS Keychain). Tech-debt 17; decide before the first iOS store build.
- **FCM never reported the deleted app as gone** within 6 test sends and 1 real send: the uninstall item is **not observed on iOS** (the
  logic is server-side and was proven on Android; the reinstall then reused the same token, as Firebase keeps its install identity in the Keychain).
  **Likely cause, unverified:** sandbox-APNs feedback timing (Apple reports an uninstalled app late, a known vendor quirk). Not tested
  further.
- The locked-screen card reads "Catalyst / Notification" (the iPhone's Show Previews default); unlocked, the neutral copy shows.

**Committed (local, not pushed):** `4b4dca71` (the `NSAllowsLocalNetworking` exception, §27), `9d28206c` (the iOS channel fix),
`9ab318dd` (**`IOS_PUSH_ENABLED = true`**, with its spec: BR-71). Full frontend suite: 234 files, 2358 tests green; `vue-tsc` clean.

**Not covered:** the TestFlight run (production APNs, the sandbox/production token split), iOS items 3, 6, 7, 8 (server-side), the other 22 locales
on a device. **Spike closed:** API back on `127.0.0.1`, worker stopped, flag off, language `en`, the LAN env override deleted; user 14's three stale
Android sign-in tokens were revoked.

## 29. Close-out of AH-102 (2026-10-11)

**Final status: closed.** Everything on `origin/main`; CI at the pushed tip `e2501352` is **green on all four jobs** ([run 38173240196](https://github.com/pedram-kh/Engine/actions/runs/38173240196): main SPA Playwright 34 passed, 0 flaky; admin SPA Playwright 1 flaky, passed on retry, the known `admin-sign-in.spec.ts:69`, tech-debt 16). **Pushed, not deployed.** No flag
armed; `push_notifications_enabled` is OFF everywhere and `IOS_PUSH_ENABLED` is `true` in code only. Deploy obligations stay in the
deploy log (Checkpoint A's entry carries the migration, the `queue:restart`, the grant check and the credential read).

**Named open items (carried, not blocking the close)**

| #   | Item                                                                                             | State                                                                                                                                                                                                                                                           | Where it lands                                                |
| --- | ------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| 1   | **TestFlight production-APNs proof** (the sandbox/production token split on a real signed build) | **Pending the first TestFlight upload.** The iPhone spike used the Xcode debug install (sandbox APNs) only.                                                                                                                                                     | Before the first iOS store build; with the server flag arming |
| 2   | **Token-rotation re-POST** (R-8: that a rotation fires `tokenReceived` and re-POSTs once)        | Never forced on a device, on Android or iOS. **Accepted by Pedram 2026-10-11** as self-healing: a rotated token that never re-POSTs goes `UNREGISTERED` on the next send and is invalidated; the daily upkeep registers the new one. Tech-debt item 11.         | Tech-debt                                                     |
| 3   | **Item 10 on iOS** (uninstall, then `UNREGISTERED`, then invalidated)                            | **Not observed on iOS** (6 test sends and 1 real send all `delivered`). Likely cause **unverified**: sandbox-APNs feedback timing, a known vendor quirk. The server logic is platform-independent and proven on Android. **Accepted by Pedram.**                | Re-observe on the TestFlight run                              |
| 4   | **AH-103 finding-2 re-verify on the iPhone** (sign-in re-registers the push token)               | **Planned, first TestFlight build after the AH-103 deploy.** Sign in, then confirm a **new `user_push_tokens` row exists** for that device and a **push is delivered**. Verified on the Samsung only (AH-103 review §4.1, §5); the iPhone shares the code path. | Run with item 1, on the same TestFlight build                 |

Also carried, not new: the other 22 locales unseen on a device, `lang/*/push.php` machine-quality, tech-debt 17 (the iOS Keychain
sign-in survives a reinstall; **accepted as recorded**, decided before the first iOS store build, likely with AH-104's deletion work).

**Token hygiene (recorded at Pedram's request).** During the Android walk the user's dev bearer token was printed to my terminal by an
unfiltered logcat read; it was disclosed at the time and treated as exposed. Revoking it at that session's sign-out left **three older
`creator-app:android` sign-in tokens for user 14** (17:49 to 18:01 UTC, from sessions cleared on the phone without signing out; the
exposed token may have been one of them). **All three were revoked on 2026-10-11** (`personal_access_tokens` for user 14: 3 to 0), local
dev database only. Since then logcat reads are filtered, and `.env` values are never printed. The FCM service-account value was never
committed or echoed.
