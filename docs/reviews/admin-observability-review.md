# AH-102b — admin observability for the creator app — Checkpoint B review

**Status:** Checkpoint B cleared; eyes-on complete. **Pushed 2026-10-12** (`origin/main` `b21b7b2f..176f46fc`), CI **green on all four jobs** ([run 38199714770](https://github.com/pedram-kh/Engine/actions/runs/38199714770)). **NOT deployed**, no flag armed.
**Date:** 2026-10-12. **Plan:** [`admin-observability-plan.md`](admin-observability-plan.md). **Inventory:**
[`admin-observability-inventory.md`](admin-observability-inventory.md).
**Prod-data risk:** LOW (one additive `CREATE TABLE`; the push job's call site and recorder change behind full containment;
read-only admin endpoints; no flag armed).

---

## 0. Three lines to read first

1. **What it is.** Half A: every push send attempt is now recorded in `push_deliveries` (append-only, 90-day retention,
   `push:prune-deliveries` daily). Half B: two read-only admin pages over it and over data that already existed — **Operations →
   Push deliveries** and **Creators → Creator app** — with four new `GET` routes. No new writes outside the recorder.
2. **The one thing that can hurt a user** is a recorder failure failing the push job (retry → duplicate push). It cannot: the
   recorder's three steps are independently contained, a failure is logged by exception class only and never rethrown
   (BRB-1/2, and the job-level single-`send()` assertions). The likeliest production failure — a missing `GRANT` on the new
   table — now costs rows, not pushes, and has a named deploy step and an arming read-back.
3. **A lesson worth keeping (§9).** A real defect (a 500 rendered the raw key `http.unknown_error`, and the table said
   "nothing stored" while the load had failed) got past green specs because the error tests used a **crafted** error, not the
   code the client library actually emits. It was found by the Stage 4 screenshots, not by a test.

---

## 1. What was built (15 commits before this docs update, all local)

| Commit     | Step  | What                                                                                                            |
| ---------- | ----- | --------------------------------------------------------------------------------------------------------------- |
| `65d2b306` | —     | Inventory (docs only)                                                                                           |
| `e4f7bac7` | —     | Plan (docs only)                                                                                                |
| `9bf80e41` | C0.1  | `LiveAppSession::constrain()` extracted from `PushTokenRegistry::liveQuery()`; `PushTokenRegistryTest` unedited |
| `30b5894c` | C0.2  | Admin Vite pre-bundle list completed + guard spec (the `admin-sign-in` flake rider)                             |
| `9197cd0b` | C0.2b | `BCRYPT_ROUNDS=4` in the two E2E API `webServer` env blocks (the `mfa-enrollment:82` fix), test-only            |
| `686d653b` | C1.1  | `push_deliveries` migration + model + factory (additive; 3 indexes; 1 FK)                                       |
| `c67d9505` | C1.2  | `notification_ulid` on `PushDeliveryOutcome`; the job passes it                                                 |
| `03411f16` | C1.3  | Contained recorder: invalidate → insert → log, each in its own `try`/`catch (Throwable)`                        |
| `b64beee8` | C1.4  | `push:prune-deliveries` (+ `->daily()`)                                                                         |
| `4f4659fa` | C2.1  | `GET /admin/push-deliveries` + `/summary`                                                                       |
| `53e32e7a` | C2.2  | `GET /admin/creator-app/users` + `/users/{creator}/sign-ins`                                                    |
| `61627374` | C3.1  | Push deliveries page, nav entry, `operations` i18n ×24                                                          |
| `d95988ea` | C3.2  | Creator app page + sign-ins dialog, nav entry, route, `creators` i18n ×24                                       |
| `eea2e89e` | fix   | Error states: page message instead of a raw code; no false "empty" claims on error (found in Stage 4)           |
| `ff97a781` | C5.1  | Docs: this review and the record updates (the first docs commit)                                                |
| `3f8c97a1` | fix   | Push type filter is a select of the known types, readable labels (found in the eyes-on walk, round 1)           |

`git diff origin/main..HEAD` is **empty** for `PushTokenRegistryTest.php` and `AdminCreatorController.php` (asserted, both
rulings from the plan: Q10, Q11). 109 files changed before this docs commit; 72 of them are locale JSON (3 files × 24).

### Existing files touched (the §4.4 list, as built)

| File                                                                                  | Change                                                                                                                          |
| ------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `Notifications/Push/PushTokenRegistry.php`                                            | `liveQuery()` delegates its three liveness clauses to `LiveAppSession`; SQL byte-identical                                      |
| `Notifications/Jobs/SendPushNotificationJob.php`                                      | one extra argument at the recorder call site; no send/release/fail control-flow change                                          |
| `Notifications/Push/PushDeliveryOutcome.php`                                          | optional trailing `?string $notificationUlid`                                                                                   |
| `Notifications/Push/PushDeliveryRecorder.php`                                         | rewrite (§3.2 of the plan)                                                                                                      |
| `bootstrap/app.php`                                                                   | one `->daily()` schedule line                                                                                                   |
| `Modules/Admin/Routes/api.php`                                                        | four routes                                                                                                                     |
| `tests/.../SendPushNotificationJobTest.php`                                           | **additions only**                                                                                                              |
| `apps/admin/vite.config.ts`, `playwright.config.ts`; `apps/main/playwright.config.ts` | C0.2 / C0.2b                                                                                                                    |
| `apps/admin/src/core/nav/navItems.ts`, `modules/{creators,operations}/routes.ts`      | two nav entries, two routes                                                                                                     |
| `apps/admin/tests/unit/helpers/mountCreatorPage.ts`                                   | the only existing **spec-side** file edited: the test router now includes `operationsRoutes` so the dialog's deep link resolves |
| `locales/*/{app,creators,operations}.json` ×24                                        | new keys only                                                                                                                   |

**No existing spec file was edited** (the diff against `origin/main` shows only new spec files plus the one helper above); the
nav and layout specs pass unchanged.

---

## 2. Gate board

At the code tip `3f8c97a1` (the docs commits change no code). The backend, the main SPA and `api-client` are unchanged since
`eea2e89e`; their rows below were measured there, and `git diff eea2e89e..3f8c97a1` touches only `apps/admin`:

| Gate                                                            | Result                                                                                                                                        |
| --------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend, full Pest serial                                       | **3,370 tests, 0 failed** (16,925 assertions; every test shows "deprecated" from the PHP 8.5 `PDO::MYSQL_ATTR_SSL_CA` notice, as on baseline) |
| Pint `--test` (all)                                             | passed                                                                                                                                        |
| PHPStan (Larastan)                                              | **No errors**; no ignores or baseline entries added                                                                                           |
| Admin SPA, full Vitest                                          | **539 passed** at `3f8c97a1` (534 at `eea2e89e`; 492 at Stage 0)                                                                              |
| Admin `vue-tsc`, ESLint (all), Prettier                         | clean on every changed file (a whole-tree Prettier check also flags 42 build-artefact files, which are not source)                            |
| `verify-locale.mjs` ×23 non-English locales                     | all PASS; `i18n-locale-parity` and `i18n-used-keys-resolve` green inside the Vitest run                                                       |
| **Main SPA** Vitest + `vue-tsc` (untouched; re-run to prove it) | **2,358 passed**; typecheck clean                                                                                                             |
| **`@catalyst/api-client`** Vitest + `tsc` (untouched; re-run)   | **221 passed**; typecheck clean                                                                                                               |
| Admin Playwright (isolated `catalyst_e2e`, dev stack down)      | **2 specs, passed 3 runs out of 3, no retries, no flake** (see §6 for what that does and does not show)                                       |

---

## 3. Break-revert record (§5.35)

Every mutation made the named test(s) fail; the file was restored and the passing run recorded. BRB numbers are the plan's.

### Stage 0–1 (write path)

| BRB    | Step  | Mutation                                                                                | Failed                                                                        |
| ------ | ----- | --------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| 8a/b/c | C0.1  | drop soft-delete / `expires_at` / age clause from `LiveAppSession`                      | 1+1 / 2+1 / 2+1 tests across `LiveAppSessionTest` and `PushTokenRegistryTest` |
| —      | C0.2  | remove `VProgressLinear` from the pre-bundle list; add a typo'd module                  | `vite-vuetify-prebundle.spec.ts` fails both                                   |
| —      | C0.2  | cold Vite cache, fix reverted / warm / cold with fix                                    | 6/6 fail at `admin-sign-in:106` / 3/3 pass / 28/28 pass                       |
| —      | C0.2b | remove the `BCRYPT_ROUNDS` line, outer `BCRYPT_ROUNDS=13` as stressor                   | admin `:82` and main `:93` both FAIL; restored: both pass                     |
| 1      | C1.3  | insert uncontained                                                                      | 5                                                                             |
| 2a     | C1.3  | a failure skips the remaining steps                                                     | 2                                                                             |
| 2b     | C1.3  | insert before invalidate                                                                | the order test                                                                |
| 4      | C1.3  | `detail` uncapped / written for `Delivered`                                             | the cap/null test                                                             |
| 3      | C1.2  | stop passing the ULID at the call site                                                  | the ULID-reaches-recorder test                                                |
| 5      | C1.4  | `<`→`<=`; ignore `--limit`; `--dry-run` still deletes; floor loosened; chunk 5000→10000 | exact-cutoff; limit/oldest-first; 2 dry-run; 2 bad-input; "exactly 2 DELETEs" |
| 6      | C1.4  | also deletes `user_push_tokens`                                                         | other-tables-untouched                                                        |
| 7      | C1.4  | schedule registration removed                                                           | the schedule pin                                                              |

C1.1 (migration): dropping `cascadeOnDelete` fails the FK + cascade tests; adding an FK on `user_push_token_id` fails the FK +
survives-a-gone-device tests.

### Stage 2 (admin read endpoints)

| BRB | Step        | Mutation                                                                    | Failed                                                        |
| --- | ----------- | --------------------------------------------------------------------------- | ------------------------------------------------------------- |
| 9   | C2.1 / C2.2 | platform-admin check removed                                                | 4 / 5 auth tests (C2.2 includes the no-existence-oracle test) |
| 10  | C2.1 / C2.2 | `ip` key added to a row; PAT `id` added to sign-ins                         | attribute-set + privacy scan; sign-in shape + P-7             |
| 11  | C2.2        | expired PAT counted as live                                                 | 3 (expires_at, age, last-seen-over-live-only)                 |
| 11  | C2.2        | audit `guard='creator_app'` filter dropped                                  | the web-login negative                                        |
| 11  | C2.2        | name-prefix filter dropped                                                  | 3 name negatives                                              |
| 12  | C2.1        | preset drops the `retryable AND final` leg; or includes non-final retryable | the failures-preset test, both variants                       |
| 13  | C2.1        | window boundary shifted; `7d` changed to 8 days                             | the boundary tests                                            |

Extra mutations not in the plan, all caught: a push row on a non-live token counted as live; sign-in state forced to `live`;
soft-deleted users listed; nulls ordered first; terminal-only counting dropped; an unknown outcome returning all rows.

**A recorded near-miss:** the first BRB-11 mutation was a silent no-op (a stray backslash in the replace string), and the
suite stayed green. It was caught only because the run showed no failure at all; the corrected mutation failed 3 tests.
**A mutation that does not turn the suite red is a bug in the mutation until proven otherwise.**

### Stage 3 (admin SPA)

| BRB | Step | Mutation                                               | Failed                            |
| --- | ---- | ------------------------------------------------------ | --------------------------------- |
| 14a | C3.1 | Failures chip sends the wrong param                    | 1                                 |
| 14b | C3.1 | "Email · not tracked" label removed                    | 1                                 |
| 14c | C3.1 | config-error tile always prominent                     | 1                                 |
| 14d | C3.1 | `creator_name` sent to the API                         | 1                                 |
| 14e | C3.1 | window switch ignores the window                       | 1                                 |
| 14f | C3.1 | filter change keeps the cursor history                 | 1                                 |
| 15a | C3.2 | search not debounced                                   | 1                                 |
| 15b | C3.2 | `/creators/app` registered after `/creators/:ulid`     | 1 (the source-order spec; see §8) |
| 15c | C3.2 | "Devices" wording in the copy                          | 1                                 |
| 15d | C3.2 | status filter sends no param                           | 4                                 |
| 15e | C3.2 | a filter change does not reset the page                | 1                                 |
| 16a | fix  | raw error key rendered (the old behaviour)             | 3                                 |
| 16b | fix  | false empty claims during an error (the old behaviour) | 2                                 |

### Eyes-on round 1 (the type-filter fix, `3f8c97a1`)

| BRB | Mutation                                                 | Failed                                            |
| --- | -------------------------------------------------------- | ------------------------------------------------- |
| 17a | the select turned back into a free-text field            | 2 (the control is a select; the option click)     |
| 17b | `item-value` changed to the label                        | 1 (picking a labelled option sends the **value**) |
| 17c | one eligible type removed from `PUSH_NOTIFICATION_TYPES` | the backend-parity spec                           |
| 17d | one label deleted from one locale (`de`)                 | the every-locale-has-a-label spec                 |

**The first version of the select spec was too weak, and 17b showed it.** It emitted `update:modelValue` with the type
value straight into the component, which bypasses the select's own item-to-value mapping, so mutation 17b passed all 23
tests. The replacement drives the real control (open the menu, click the option **by its label**) and 17b then fails. This
is the same lesson as §9 item 1 in a different place.

The backend-parity spec also earned its keep immediately: my first list had `campaign.application_accepted`; the stored
value is `campaign_application.accepted`, and the spec failed on that before anything was run in a browser.

---

## 4. Stage 4 — the Postgres walk (no commit)

**Setup.** A scratch database `catalyst_walk` (not the dev database, not `catalyst_e2e`), all 81 existing migrations plus the two
new ones on Postgres 16, then a synthetic population: **20,000 creators, 8,600 personal access tokens (5,600 creator-app,
3,000 web), 4,760 push-token rows, 50,000 audit rows (25,000 creator-app logins, 15,000 web logins, 10,000 other), 100,000
push deliveries** over 120 days. The seed is a throwaway script, not committed.

**Correctness against independent SQL** (not against the endpoint's own logic):

| Filter                                       | Endpoint total         | Independent SQL        |
| -------------------------------------------- | ---------------------- | ---------------------- |
| `app_status=active` / `signed_out` / `never` | 4,000 / 2,000 / 14,000 | 4,000 / 2,000 / 14,000 |
| `platform=ios`                               | 2,000                  | 2,000                  |
| `push=registered` / `dead` / `none`          | 3,000 / 400 / 16,600   | 3,000 / 400 / 16,600   |

The 15,000 web-guard audit rows did **not** leak into "signed out" (`never` matched). Verified on Postgres for the first time:
the JSON `metadata->guard` predicate, the grouped joins, the `CASE` nulls-last ordering and the chunked delete.
All 20,000 creators walked across 200 pages of 100: **no duplicates, no ordering violations**, grouped active → signed-out →
never. A 250-page cursor walk of push deliveries returned 25,000 unique ids in strictly descending order.

**Timings.** Whole-request wall time through `artisan serve` (includes ~60 ms of per-request PHP start-up), three repeats per
request: push list and summary endpoints worst ≈ 0.17 s; creator-app list worst ≈ 0.37 s, plus one cold 0.54 s on the first
request of a new filter; sign-ins ≈ 0.09 s. The slowest single query seen in the walk was **143 ms** (`app_status=never`, page 200) against the P-6 rule's ~500 ms: **not triggered, so no denormalisation row.**

`EXPLAIN (ANALYZE)` was **re-run at the docs commit, after the prune** (so `push_deliveries` held ~75,000 rows, not 100,000;
warm cache; the first-run figures that produced the 143 ms are in the Stage 3/4 report):

| Endpoint (query)                               | Execution time                                  |
| ---------------------------------------------- | ----------------------------------------------- |
| push list default / failures / creator / dates | 0.08 / 0.14 / 0.19 / 0.09 ms (index scans only) |
| push summary 30d: counts / oldest / `count(*)` | 11.4 / 0.06 / 9.5 ms                            |
| creator list default (count / page)            | 13.7 / 78.9 ms                                  |
| `app_status=never` (count / page)              | 52.9 / 86.5 ms                                  |
| `app_status=never`, page 200 (count / page)    | 56.6 / 121.6 ms                                 |
| `push=none` (count / page)                     | 28.4 / 81.2 ms                                  |
| `push=dead` (count / page)                     | 26.0 / 78.5 ms                                  |
| search `creator1234` (count / page)            | 31.9 / 82.6 ms                                  |
| sign-ins for a creator (4 queries)             | each ≤ 0.17 ms (index scans)                    |

The creator-list queries sequentially scan `creators`, `users` and `personal_access_tokens` (20k / 20k / 8.6k rows) and are fast
enough at this size; that is the plan's accepted shape, and the first thing to look at if the page ever feels slow at real scale.

**Prune on the dataset.** 25,135 rows older than 90 days. `--dry-run` reported 25,135 and deleted none; the real run deleted
25,135 in 6 chunks of ≤5,000 and touched no other table (audit, users, PATs, push tokens unchanged); `--days=0` is refused.
A run straight afterwards deleted **1**: the 90-day cutoff slides with the clock and had passed one seeded row two seconds
later (the rolling window, not a bug); three further runs deleted 0.

**Eyes-on.** Screenshots of both pages, **dark and light**: populated, filtered (30-day window, Failures, signed-out), empty,
error, and the sign-ins dialog (20 numbered images in `~/Desktop/ah-102b-screenshots`, not committed). Seed artefacts to ignore: the table is ordered by `id`
while seeded `created_at` is random, so dates look unordered; and a light-theme sidebar logo is nearly invisible (pre-existing,
not this chunk).

**Playwright.** The admin suite has **two specs** (`admin-sign-in:69`, `admin-mandatory-mfa-enrollment:82`); both passed 3 of 3
runs with no retries (warm Vite cache; the cold-cache evidence is C0.2's 28/28). **They do not touch either new page**, so the
plan's R6 mitigation ("endpoints exercised in the full admin Playwright on pgsql") was met by this walk and the screenshots,
not by the E2E suite. A new-page E2E spec is a tech-debt row, not shipped here.

**Walk-environment hazards found** (also in `RESUMPTION-TEMPLATE.md`'s standing traps): an exported
`SANCTUM_STATEFUL_DOMAINS` in the shell is inherited by `artisan serve` and gives a login 500 ("no session bound"); a platform
admin is forced through 2FA enrollment (`mfa_exempted_at` did not bypass it), and the enrollment confirm step visibly sat on
"Verifying…" for several seconds at the default bcrypt cost — consistent with, but not a timing of, the finding in §7.

### 4.1 Eyes-on round 1 (2026-10-12)

1. **The type filter was a free-text input (finding, fixed in `3f8c97a1`).** An admin would have had to type
   `message.received_by_creator` from memory. It is now a select over the 14 push-eligible types (exactly
   `PushEligibility::TOGGLEABLE` + `ALWAYS_ON`, kept in step by a spec that reads the PHP source), readable label shown, the
   stored type value sent. Labels exist in all 24 locales; **the 23 non-English ones are my translations, not native-reviewed
   (Irish and Maltese least certain)**, as for the rest of this chunk. Other free-text inputs on the two new pages: the
   Push page's **Creator ID** (a ULID, normally filled by the deep link from the Creator app page; not a "type") and the
   Creator app page's **search** (a name search). The audit-log page's **action** filter is the same kind of free-text
   type input; it is pre-existing and untouched (tech-debt row). The table's **Type** column still shows the raw dotted
   value; a labelled chip is a follow-up, not done.
2. **The first Stage 4 dataset used notification types that do not exist** (`campaign.invited`, `contract.ready`,
   `message.received`, `payout.paid`). Nothing at the time exercised the type filter against them, so it did not show; with
   the select it would have returned nothing for every option. The scratch database was re-typed in place to the 14 real
   values (about 5,300 rows each) before the re-walk. Production data can only hold real types: the column is written from
   `NotificationType`.
3. **Reviewer's walk ran against a local stack by mistake, which was a useful accident.** On a local database that did not
   yet have the `push_deliveries` table, Push deliveries failed to load, and the page rendered the proper message with "—" tiles
   and no false-empty claim: **the `eea2e89e` fix observed working on a genuinely broken load**, not a crafted one. The local
   database then received the pending migration (`2026_10_12_100000_create_push_deliveries_table`, the only one pending).
4. **Walk stack for the re-walk:** the same scratch database (20,000 creators, 74,864 deliveries after the prune), API on
   `127.0.0.1:8002`, admin dev server on `127.0.0.1:5175` with its own Vite cache, and a different host name and port from
   the everyday stack (`localhost:5174`) so cookies cannot collide. Walk-environment trap added: the API's
   `FRONTEND_ADMIN_URL` must equal the admin origin, or the CSRF preflight lands on the main session and login returns 419.

---

## 5. Deviations from the plan (all accepted at Checkpoint A or B)

1. A sign-in row is `live` or `expired` only; "signed out" has no row (logout/reset/lockout delete the token) and is read from
   the audit trail by the list view.
2. The `push` filter has a third value, `dead`.
3. A device under an expired sign-in reads `push_state: none` (deliverability), keeping its version/registered-at as history.
4. The `{creator}` path parameter is resolved **after** the admin check: a non-admin probe gets 403, never 404 (no existence oracle).
5. The column is `invalidation_reason`; the response key is `push_invalidation_reason`.
6. List ids are the creator ULID; sign-in rows carry no id (P-7).
7. The sign-ins surface is a **dialog**, not a drawer.
8. Route order (BRB-15b): vue-router 4 ranks static segments above dynamic ones whatever the registration order, so the
   mutation can only fail the **source-order** spec; there is no behavioural failure to observe. Both layers are kept.
9. i18n: the 23 non-English locales are my translations, **not native-reviewed** (Irish and Maltese least certain); the gates
   check keys, `{named}` tokens and plural counts, not language quality.

---

## 6. Flake accounting (the C0.2 rider, stated honestly)

**Cause named for `admin-sign-in.spec.ts:106`** (from the CI trace of run `38174364033`): login succeeds (`200`, `/admin/me`
`200`), then about 2 s after the TOTP submit the page re-requests `/sign-in` as a document and `[vite] connecting` appears a
second time; Vite's optimizer hash changes three times. The dashboard route pulls in `VProgressLinear` (via the `@catalyst/ui`
barrel's `CompletenessBar`), which was missing from the admin pre-bundle list, so a **cold** CI dev server discovers it
mid-run and force-reloads; the reloaded guest page does not bootstrap, so the user stays on `/sign-in`. Fixed in `30b5894c`
with a guard spec. Reproduction: cold cache, fix reverted **6/6 fail**; warm **3/3 pass**; cold with fix **28/28 pass** (8 + 8 under 1× CPU load

- 12, all after trace capture was added). The tech-debt hypothesis at `:1364` (a 401 race) was **wrong**: the trace shows no 401.

**What is not settled.** The first six runs after the fix were 4 of 6; the two failures had no trace captured and the machine
was contended. The 28 passes came after trace capture was added, so a rare load-dependent failure of the same kind is not
excluded. Why the flake became visible on 2026-10-06 was not established (`packages/ui` and `apps/admin` have no diff in the
preceding days). A heavier CPU-load experiment was blocked by the harness and dropped. **The CI tally at the pushed tip is the
check** — it is recorded in the post-push docs commit (§10), not asserted here. Before this chunk (from the inventory): `:69`/`:106` flaked in
17 of the 18 `main` runs since 2026-10-06 in which the job ran, identically, and never failed after retries; one run was clean.

**`mfa-enrollment:82` was a different cause** and is fixed (C0.2b): `POST /auth/2fa/confirm` bcrypts ten recovery codes at cost 12
(5,768 ms in the CI trace against the spec's 5 s `expect`; locally cost 12 passes, 13 fails with the identical signature, 4 passes). `BCRYPT_ROUNDS=4` in the two throwaway E2E API servers resolves the E2E half of
**both** the admin `:82` and the main-SPA `2fa-enrollment-and-sign-in.spec.ts:93` rows (same mechanism, both suites). The
production half is a separate finding (§7).

---

## 7. Findings that are not this chunk's to fix (all recorded in `tech-debt.md`)

- **Production 2FA confirm takes ≈ 3.7 s** on a fast laptop (cost 12, ten recovery codes). the code comment says cost 10, but
  the config default is **12**. A real user-facing latency; the Stage 4 walk showed the same several-second wait (not timed).
  **Flagged, not fixed.**
- `AuditLogPage` uses the same `t(error.code)` pattern that rendered a raw key on the new pages (§9), so it very likely has the
  same defect. **Suspected, not reproduced, untouched in this chunk.**
- The Push deliveries footer runs `count(*)` over the whole table (10.5 ms at 100k rows; a sequential scan, so roughly linear —
  an extrapolation, not a measurement). A watch item, not a P-6 trigger.
- Isolated runs of `TalentPoolMembershipTest.php` fail ("undefined function `poolManager()`") because its helpers live in another
  test file; the full suite passes. Pre-existing.

---

## 8. Not verified

- **Production.** Nothing was read from production: not whether the scheduler runs, not `engine_c_user`'s privileges on
  `push_deliveries`. Those are Pedram's read-backs at deploy (plan Appendix A and the deploy-log entry).
- **Production data volume.** The walk is synthetic; real table growth, and therefore the `count(*)` footer and prune chunking
  at scale, are extrapolations.
- **The new pages in Playwright.** No E2E spec covers them (§4).
- **Translations** by native readers (§5.9).
- **iOS/Android clients** are untouched by this chunk; `push:test` still bypasses the recorder by design (it will not write a row).

---

## 9. Lessons recorded

1. **A test that fabricates its failure proves the fabrication, not the product.** The error-state specs rejected with an
   error whose `code` was the page's own i18n key (or a plain `Error`), so they passed while the real client emits
   `http.unknown_error` (no translation) for a non-envelope 500 or a dropped connection. The Stage 4 screenshot of a real
   failed load showed the raw key, plus "No push attempts stored yet" under a failed load. **Rule: an error-state test uses the
   exact error the transport layer produces**, and asserts what the user reads, including the absence of claims the failed load
   cannot back (empty/none/zero).
2. **A mutation that does not fail anything is suspect** (the BRB-11 no-op, and again BRB-17b against the first select spec).
3. **Look at it.** Of everything in this chunk, the defects found after the green board were found by looking: a rendered
   failure on the production database engine (§1 fix), and a filter nobody could use without memorising its values (§4.1).
4. **Test-data realism is part of the test.** The first walk dataset used notification types the product never emits, so a
   whole filter looked fine because nothing exercised it. Seeds should draw from the real enum.

---

## 10. Push and CI

**Pushed tip: `176f46fc`** (the type-filter docs update; `origin/main` `b21b7b2f..176f46fc`, 17 commits, pushed once, after the
reviewer's clearance, Pedram's go and the re-walk). **CI at that tip: [run 38199714770](https://github.com/pedram-kh/Engine/actions/runs/38199714770) — completed, success, all four jobs.**

| Job                                | Result                                      | Time (UTC)          |
| ---------------------------------- | ------------------------------------------- | ------------------- |
| Backend (Pint + Larastan + Pest)   | success                                     | 03:25:59 – 03:31:17 |
| Frontend (lint + typecheck + unit) | success                                     | 03:25:54 – 03:35:54 |
| E2E — admin SPA (Playwright)       | success: **2 passed, 0 flaky, no retries**  | 03:35:56 – 03:37:35 |
| E2E — main SPA (Playwright)        | success: **34 passed, 0 flaky, no retries** | 03:35:56 – 03:43:53 |

The only job annotations are the four "Node.js 20 is deprecated" warnings from GitHub Actions; there is no flaky-test annotation.

**The `admin-sign-in.spec.ts:69` / `:106` tally.** At the pushed tip: **first-attempt pass, 0 retries** (in the run above). Before the
fix it flaked in 17 of the 18 `main` runs since 2026-10-06 in which the job ran (§6), including the base-tip run `38174364033`.
**One clean run is consistent with the C0.2 fix and does not prove it:** §6's "not settled" caveat stands (a rare load-dependent
failure of the same kind is not excluded; the 4-of-6 early runs had no trace). The tally keeps accumulating on `main`; if `:69`
flakes again, re-open the tech-debt row with that run's trace. `admin-mandatory-mfa-enrollment.spec.ts:82` (the bcrypt fix,
C0.2b) also passed first time, as did every main-SPA spec.

**A closing docs commit follows the pushed tip** (this record), as AH-102 did; CI for it is not cited here. `catalyst_walk` was
dropped and the walk stack shut down after CI went green, as agreed.

---

_Provenance: drafted by Cursor at Checkpoint B (2026-10-12); evidence is from local runs and the commit messages cited above._
