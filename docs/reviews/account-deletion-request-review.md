# AH-104b-del Part A — review (API, sign-in step, the AH-104a rider)

**Status:** Part A built locally, **not pushed**. Part B (Security page, public page, agency label, `creator.unavailable`) is **not started** and waits for this review.
Plan: [`account-deletion-request-plan.md`](account-deletion-request-plan.md). Break-revert logs, verbatim: [`account-deletion-request-break-reverts.md`](account-deletion-request-break-reverts.md).

**PROD-DATA RISK: LOW.** One additive migration (two nullable columns and an index on `users`), the flag is OFF, nothing runs against production data until Pedram flips it. The rider (A7) changes what
the erasure engine does, and the engine has never run in production; its first real run is AH-104c and is a separate act, behind a dry-run count.

## 1. What was built

| Step | Commit                 | What                                                                                                                                                                                                      |
| ---- | ---------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A1   | `73dec810`, `82ccd12d` | Columns `deletion_requested_at`, `deletion_scheduled_for` (+ index), classified in the erasure registry; flag `account_deletion_enabled` (default off, in the admin allowlist); support address in config |
| A2   | `b5751a35`             | `CredentialRevoker`: one list of everything that signs a person in (sessions, tokens, push tokens), tied by a spec to the erasure registry                                                                |
| A3   | `722c8f2f`, `d31e59ab` | `POST /api/v1/me/account-deletion` on both doors; re-authentication; guards; throttle; one audit row; revoke in one transaction                                                                           |
| A4   | `5cbb6ff1`             | Sign-in gate (`403 auth.deletion_pending`), the `cancel_deletion` restore, bearer refusal                                                                                                                 |
| A5   | `090f988a`             | One static answer for every taken sign-up address, with the way back                                                                                                                                      |
| A6   | `161b7c1c`, `1a05c1bb` | Confirmation mail (queued, localised, no link, same flag)                                                                                                                                                 |
| A8   | `f4b4dae4`, `25d9e6de` | Restore step on the sign-in page (web, agency variant, native sheet), `deletionPendingMeta` and `requestAccountDeletion` in the API client, strings ×24 in main and admin                                 |
| A7   | `1cd31051`, `5dc24cca` | The AH-104a rider: open assignments cancelled by the system at erasure, agencies told once                                                                                                                |
| docs | this commit            | Security/compliance, tenancy, feature flags, tech-debt, deploy log, runbook, this review                                                                                                                  |

## 2. Per-decision evidence (the rulings, and the spec that holds each)

| Ruling                                                                   | Held by                                                                                                                                                                                                                                                                                                                                                       |
| ------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Q1(b) pending answer after password + 2FA, no session; cancel by re-auth | `AccountDeletionSignInTest`: "answers 403 auth.deletion_pending on right credentials, with no session, no token, no login"; "asks for the second factor first, and names the pending state only after a right code"; "restores the account on right credentials with cancel_deletion"; "does not cancel on wrong credentials, a missing code or a wrong code" |
| No state leak before credentials are proven                              | "gives a pending account exactly the ordinary answer to every failure before the credentials are proven"; "answers a pending account at the wrong SPA exactly as an ordinary creator"; "answers an erased account exactly as an unknown email"                                                                                                                |
| Q2 throttles; 3/hour limiter                                             | `AccountDeletionRequestTest`: "allows 3 requests an hour per person, then 429 with Retry-After"; "is mounted without the agency tenancy gate and with both throttles". The typed-word control and its ×24 copy are Part B (Security page); nothing of it is built yet                                                                                         |
| C1 recovery code burned by the pending step, with a hint                 | "spends the recovery code on the pending step and says so, so the client can ask for a fresh one"; web: `SignInPage.spec.ts` "account scheduled for deletion" (recovery-code case shows `code_again`)                                                                                                                                                         |
| C2 no `FailedLoginTracker`/lockout from this door                        | "never suspends, locks or signs out an account however many wrong passwords arrive"; "never feeds the failure counters or locks the account, however often a pending creator signs in"; "applies the sign-in second-factor throttle: locked at 5, enrolment suspended at 10, never a lockout"                                                                 |
| C3 refuse up front on payment/vendor guards                              | "refuses up front while money is held, or a real vendor holds the person, and says only why in a word"; "lets a creator with nothing blocking, and with real data around them, through"                                                                                                                                                                       |
| C4 new actions on a pending creator → 422 `creator.unavailable`          | **Part B. Not built; no evidence yet.**                                                                                                                                                                                                                                                                                                                       |
| C5 cookie-session race accepted                                          | Tech-debt row ([`tech-debt.md`](../tech-debt.md), "AH-104b-del Part A"); bearer side closed by "refuses a bearer token that belongs to a creator scheduled for deletion"                                                                                                                                                                                      |
| C6 repeat request idempotent                                             | "is idempotent: a second request answers with the first date, writes nothing, mails nothing"                                                                                                                                                                                                                                                                  |
| C7 one string for every taken address                                    | `AccountDeletionSignUpTest` ×3: byte-for-byte equality with an ordinary taken address; no placeholders; 24 locales each with their own non-empty copy                                                                                                                                                                                                         |
| C8 global flag, short window, named test creator                         | Deploy-log entry and the checklist in §7; nothing to test                                                                                                                                                                                                                                                                                                     |
| C9 machine draft ×24; human pass en/es is Pedram's                       | Part A strings (the pending answer, the sign-in step, the mail subject) ×24 in main and admin, `i18n-auth-codes` arch spec green. The consequences copy and the public page are Part B; the human pass is on the checklist in §7                                                                                                                              |
| Q4(c) rider: assignments cancelled at erasure, not at request            | `ErasureCancelOpenAssignmentsTest` (12): only non-terminal rows; system actor, no PII in the audit row; agency told once, no mail, no push; whole erasure rolls back if the step fails; other creators untouched. At request time nothing touches assignments (no step in A3 does)                                                                            |
| Q7 flag off at deploy; Q8 mail on the same flag                          | "answers 404 as if the route were absent while the flag is off, and changes nothing"; mail listener checks the flag; `AccountDeletionMailTest`                                                                                                                                                                                                                |

## 3. Gates

Each gate run on its own, exit code read, at the final Part A head (`5dc24cca` plus this docs commit; no web file changed after `25d9e6de`).

| Gate                                | Command (from the package directory)   | Exit    | Result                                                 |
| ----------------------------------- | -------------------------------------- | ------- | ------------------------------------------------------ |
| API tests, whole suite, serial      | `vendor/bin/pest` (no path)            | 0       | 3780 deprecated, 5 passed, 0 failed; 19,490 assertions |
| API style                           | `vendor/bin/pint --test`               | 0       | `passed`                                               |
| API static analysis                 | `vendor/bin/phpstan analyse` (no path) | 0       | `[OK] No errors`                                       |
| Main app tests                      | `npx vitest run`                       | 0       | 254 files, 3004 tests passed                           |
| Admin app tests                     | `npx vitest run`                       | 0       | 62 files, 539 tests passed                             |
| API client tests                    | `npx vitest run`                       | 0       | 9 files, 228 tests passed                              |
| Types: main, admin, api-client      | `vue-tsc --noEmit`, `tsc --noEmit`     | 0, 0, 0 | clean                                                  |
| Commit hooks (Prettier/ESLint/Pint) | lint-staged on every commit            | 0       | passed on each Part A commit                           |

**Read this before trusting the Pest line.** Every API test is reported as _deprecated_, not _passed_: PHP in this environment emits `Constant PDO::MYSQL_ATTR_SSL_CA is deprecated` from `config/database.php`
(not touched by Part A), and Pest files a test that raised a deprecation under that heading. There are **no failures**, but the tool's "passed" count is not the number to read here. I did not fix the config
(out of scope); it is a one-line item for the next PHP-version chore. The new Part A specs: schema 11, revoker 8, request 23, sign-in 17, sign-up 3, mail 11, rider 12 (test-function counts; datasets add more).

## 4. Break-reverts

Every step was mutated and the exit code read (non-zero = caught). Distinct mutations: A1 13, A2 12, A3 28, A4 20, A5 4, A6 12, A7 17, A8 15 (web).

| Survived on first run                                                                 | Closed by                                                                                                                                                                                                                                          |
| ------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A1-3 flag not defined in the provider                                                 | `AccountDeletionSchemaTest` "registers the flag, default OFF, and round-trips it" (added in `82ccd12d`) → A1-3b CAUGHT                                                                                                                             |
| A3-3 agency users allowed; A3-5 erased allowed; A3-10 suspended 2FA enrolment ignored | specs added in `d31e59ab` → CAUGHT                                                                                                                                                                                                                 |
| A7-2b terminal re-read guard; A7-8 digest; A7-11 reason guard                         | specs in `5dc24cca` → CAUGHT                                                                                                                                                                                                                       |
| W-11 pending step taken from any error code (web)                                     | `auth.spec.ts` strengthened in `25d9e6de` → CAUGHT                                                                                                                                                                                                 |
| A6-3 mail sent immediately instead of queued                                          | **Equivalent mutant, not closed.** Laravel queues a `ShouldQueue` mailable whichever of `send()`/`queue()` is called. The property is pinned at the class: `AccountDeletionMailTest` asserts `ShouldQueue`, and A6-6 (queueable removed) is CAUGHT |

The harness is not in the repository; the verbatim logs are in the file linked at the top.

## 5. Deviations, misses and open questions

**Misses of mine, found by the wide suites and fixed (no data effect):**

1. **A6 shipped without its translation key.** `auth.deletion_mail.subject` was harvested by `i18n-auth-codes` and failed 24 specs in the main bundle. Added ×24 in both bundles inside the A8 commit and said so in its body.
2. **A6 shipped without an email-preference classification.** `EmailPreferenceGuardArchitectureTest` wants every mail sender to check `isEmailEnabled()` or be allowlisted. The confirmation mail is transactional and security-relevant, so it is allowlisted with that reason (`1a05c1bb`). Say if you want it to honour the preference.
3. **A7 changed existing Erasure specs** (expected ledger counts, the stage list, `SubjectSetTest`, `ErasureGraph`). The engine's `STAGES` is unchanged; `4h` is a label the engine refuses, and a spec pins the refusal.
4. I ran `git stash` by mistake once and popped it straight back; nothing was lost, the pre-existing lint-staged backup entry is untouched.

**Decisions I took that you may want to overrule:**

- **A flag-off request is `404`**, as if the route did not exist (the repo's convention for flagged endpoints, read in A1), not a 422 code.
- **Cancel stays possible after `scheduled_for` and with the flag off**, until the account is erased. The date is when an admin may erase, not when the account is gone. AH-104c must re-check under a lock (tech-debt row 2). **SUPERSEDED 2026-10-22 (Pedram, AH-104c inventory Q3): the claim.** The creator can cancel by signing in until an admin's Erase **claims** the account (`users.erasure_started_at`), not until `erased_at`; from the claim the sign-in answers as an unknown account, and a failed run stays claimed and is retried by an admin, never reopened to the creator. Reason: the run deletes the S3 files before its database transaction, so a lock inside that transaction comes too late. See [`account-deletion-admin-plan.md`](account-deletion-admin-plan.md) C1-2 and C1-3.
- **The confirmation mail is not preference-gated** (item 2 above).

**Open question for you (needs a ruling before AH-104c, not before Part B):** the rider cancels **`live_verified` and `manually_verified`** assignments too: the creator delivered and is owed money that has not been held yet. I recommend
treating those two as an erasure **refusal** (same family as the payment states). Details in the tech-debt row 3. The dry-run prints `campaign_assignments.cancel`, so you can see the count on production before anything runs.

## 6. Not verified

- **No device, no browser, no mail client.** W1–W10 have not been run. Everything above is Pest and Vitest.
- **Postgres.** All API specs run on sqlite `:memory:`. The migration, the index and the `lockForUpdate()` in the rider have not run on Postgres (the same gap as AH-104a).
- **Production.** Nothing deployed. The counts in the plan are from earlier read-backs.
- **The 24 translations** are a machine draft; no human has read them.
- **`SUPPORT_EMAIL`** is read from config with the ruled default; the web/native `VITE_SUPPORT_EMAIL` side belongs to Part B.

## 7. Named steps (the build-3 checklist and the walk)

1. **Pedram's task, before the flag flip:** a human pass on the **public page and the consequences copy, in en and es** (the 24-locale draft stands for the rest).
2. **Walk W1–W10 run first against the local stack** (Xcode build + local API + Mailhog), like AH-108, results recorded in this file.
3. **Pedram turns `account_deletion_enabled` ON** in the admin (reason required) only after the walk passes and W9 and the support address are confirmed, before build 3 goes to App Review.
4. **Production re-run: W2, W4, W5 only**, on a **named test creator**, with the flag ON for a short window and **OFF again after**. Recorded in the deploy log.
5. Deploy needs `queue:restart` (new queued mailable) and the snapshot (one migration).
