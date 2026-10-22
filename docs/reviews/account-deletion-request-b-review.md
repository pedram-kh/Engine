# AH-104b-del Part B — review (agency side, Security page, public page, the agency notice)

**Status:** Part B built locally, **not pushed**. Part A is pushed (`a8d4054c`, CI green at the tip). This review is the stop Pedram asked for; nothing below is pushed or deployed.
Plan: [`account-deletion-request-plan.md`](account-deletion-request-plan.md). Part A review: [`account-deletion-request-review.md`](account-deletion-request-review.md).
Break-revert logs, verbatim: [`account-deletion-request-b-break-reverts.md`](account-deletion-request-b-break-reverts.md).

**PROD-DATA RISK: LOW at deploy.** No migration, no backfill, no command, no scheduler change, no new env. Part B only reads the two Part A columns and `creators.erased_at`, which are null for every
creator while the flag is OFF and nothing has been erased. The one change that reaches everyone is that agency discovery and messaging queries now filter on those columns
(`Creator::scopeVisibleToAgencies()`); with the columns null the result is unchanged (a spec pins that a live creator is never hidden or flagged). **The risk event is turning the flag ON**, which is
Pedram's step after the walk, not the deploy.

## 1. What was built

| Step  | Commit                 | What                                                                                                                                                                                                                                                                                                   |
| ----- | ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| B1+B2 | `28796d19`             | The visibility scope and the guard (`scopeVisibleToAgencies`, `isUnavailableToAgencies`, `assertAvailableToAgencies` → 422 `creator.unavailable`), the silent fan-outs, `is_erased` on every agency resource (trait `EmitsErasedState`), the static surface manifest. **One commit for both** (see §5) |
| B4+B5 | `6063e768`             | The Security page and delete-account dialog, `features.account_deletion` on `/me`, the route guard, the public `/delete-account` page, the Disable-2FA route fix, strings ×24                                                                                                                          |
| B3    | `0f0f6375`             | "Deleted creator" on every agency screen through one reader (`creatorLabel.ts`, `CreatorIdentityAvatar.vue`), and an architecture spec forbidding `display_name` / `avatar_url` in the agency modules; the dialogs handle `creator.unavailable`                                                        |
| B6    | `92f1bd25`             | `assignment.cancelled`: the agency's in-app notice when an erased creator's assignment is cancelled, its rendering, the route-key contract, strings ×24                                                                                                                                                |
| test  | `44985261`, `e1d344c8` | A live creator is never flagged erased in discovery; four more Security-page cases and a tightened notice assertion (all from break-revert survivors)                                                                                                                                                  |
| test  | `c49ebd71`             | `useShellBack.spec.ts` lists `creator.security` (found by the full web run, §3)                                                                                                                                                                                                                        |
| B7    | the docs commit        | Glossary, runbook "the request side", security/compliance §3.3a, feature flags, tech-debt, deploy log, adhoc log, plan §9 and AH-104c notes, resumption template, this review, the break-revert logs                                                                                                   |

## 2. Per-decision evidence (the rulings, and the spec that holds each)

| Ruling                                                                                           | Held by                                                                                                                                                                                                                                                                                                                                                                               |
| ------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| "Hidden" is derived, not stored; one definition                                                  | `CreatorUnavailableToAgenciesTest`: "defines 'visible to agencies' once: the scope and the single-row check never disagree"; "does not read a missing erased_at column as 'not erased'"                                                                                                                                                                                               |
| C4 new agency actions on a pending or erased creator → 422 `creator.unavailable`                 | Same file: "refuses a new connection request with the neutral 422 and writes nothing"; "refuses an invite ... and creates no assignment"; "refuses a talent-pool add ... but lets the agency remove a membership"; "refuses to ACCEPT an application ... but leaves the application as it was"; "starts no NEW conversation ..."                                                      |
| C4 the refusal never says which state (pending or erased)                                        | Same code and same body for both; B1-19 ("refusal code says why") is a break-revert, CAUGHT                                                                                                                                                                                                                                                                                           |
| C4 existing threads and assignments stay writable but silent                                     | "leaves an existing assignment and its thread of a pending creator exactly as they were"; "starts no NEW conversation, but an existing thread stays writable and silent" with the control "tells a normal creator the same message"; "still lets the agency REJECT an application, silently" with the control "tells a normal creator about a rejection"                              |
| Hidden from discovery, the picker, the job-posted fan-out, the digest, the nudge                 | "leaves a creator out of discovery and answers their profile 404 (a cancelled request puts them back)"; "offers the creator in the new-conversation picker only where a thread already exists"; "sends the job-posted fan-out to the normal creator and to nobody scheduled for deletion or erased"; "sends no message digest ..."; "does not nudge a person who asked to delete ..." |
| Cancelling the request puts the creator back, with no trace                                      | Discovery test above (the cancel step is in it)                                                                                                                                                                                                                                                                                                                                       |
| An erased creator: `is_erased: true`, no name, on every agency surface                           | `ErasedCreatorAgencySurfacesTest`: "says is_erased and shows no name on every agency surface that carries the erased creator, and leaves the other creator alone"; "leaves the erased creator out of discovery and out of any new conversation"; the control "does not flag a live, discoverable creator as erased in discovery" (closed B2-9)                                        |
| No agency surface can read a creator without being classified                                    | `AgencyCreatorSurfacesTest` (unit, 5): every agency file that reads creators is in `tests/Support/AgencyCreatorSurfaces.php`; no stale entry; each kind has its marker or a reason; every resource in the manifest emits `is_erased`                                                                                                                                                  |
| The web reads the label from one place                                                           | `creatorLabel.spec.ts` (9), `CreatorIdentityAvatar.spec.ts` (4), arch spec `creator-label-single-reader.spec.ts` (4): `display_name` / `avatar_url` are forbidden in the agency modules                                                                                                                                                                                               |
| The dialogs say "no longer available" instead of an error when the code is `creator.unavailable` | `InviteCreatorsDialog`, `AcceptApplicationDialog`, `AddCreatorsToPoolDialog` specs; 16 B3 break-reverts, all CAUGHT                                                                                                                                                                                                                                                                   |
| Q7 flag off: no entry, route redirects, endpoint 404                                             | `UserFeaturesAccountDeletionTest` (2): `features.account_deletion` false for a creator with the flag off, true when on, false for an agency user and a platform admin; `guards.spec.ts` (`requireAccountDeletionEnabled`); `creator-routes-guard.spec.ts`; `SecurityPage.spec.ts` "404 (the flag went off while the page was open): says it is not available"                         |
| Q2 typed word is a UX gate; control is the re-authentication                                     | `deleteWord.spec.ts` (6: case and diacritics ignored); `SecurityPage.spec.ts` (14): the button stays disabled until the tick, the password and the word are all there; one request in flight; 2FA code asked only when enrolled; 403 wrong password stays signed in; 422 names each reason and the support address; 429 counts down                                                   |
| After a request the person is signed out and told the date                                       | `SecurityPage.spec.ts` "on success: sends the body, signs the user out locally, and goes to sign-in with the date"; `useAuthStore.accountDeletion.spec.ts` (3)                                                                                                                                                                                                                        |
| The public page is static, reachable signed out, names the support address                       | `DeleteAccountInfoPage.spec.ts` (5); `support-address-single-source.spec.ts` (arch): the address lives in one place                                                                                                                                                                                                                                                                   |
| The Disable-2FA page goes to a real route (tech-debt row, now CLOSED)                            | `DisableTotpPage.spec.ts`: "happy path (creator) ... goes to creator.security"; agency goes to `settings`; "never navigates to the old, unregistered `app.settings` route"                                                                                                                                                                                                            |
| `assignment.cancelled` is a live, agency-only, always-on in-app type, no creator name            | `templates.spec.ts`; `PushEligibilityTest` (push-ineligible pin); `NotificationRouteKeysContractTest` (the route-key contract file); `notificationDestination*.spec.ts` (tap opens `campaigns.detail?tab=creators`); `NotificationCenter.spec.ts` (known reason codes rendered through i18n, an unknown reason shown as free text, asserted with an exact string)                     |
| The notice never names the creator                                                               | The data written by `SendAssignmentNotifications::notifyAgencyOfErasedCreator` is `{campaign_name, campaign_ulid, assignment_ulid, reason, is_erased}` and the Part A rider specs (`ErasureCancelOpenAssignmentsTest`) assert one notice per assignment and no PII                                                                                                                    |
| C9 machine draft ×24, human pass en/es before the flag flips                                     | Part B strings in 24 locales in `main` for the Security page, dialog, public page, creator label and notice; `i18n-account-deletion-codes.spec.ts`, `i18n-notifications-parity.spec.ts` green. **The human pass is Pedram's** (§7)                                                                                                                                                    |
| **New ruling:** `live_verified` / `manually_verified` refuse erasure like the payment states     | **Recorded, not built** (built in AH-104c: engine guard plus request-time refusal via C3). In tech-debt ("AH-104b-del Part A" item 3), the AH-104c notes at the end of the plan, `05-SECURITY-COMPLIANCE.md` §3.3a, the runbook §1a and the adhoc log. Until AH-104c the rider **still cancels** those two states; nothing erases before then                                         |

## 3. Gates

Each gate run on its own, exit code read, at the final Part B head.

| Gate                           | Command (from the package directory)                                                                                        | Exit    | Result                                                                                                                                   |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------- | ------- | ---------------------------------------------------------------------------------------------------------------------------------------- |
| API tests, whole suite, serial | `SANCTUM_STATEFUL_DOMAINS='localhost,localhost:5173,127.0.0.1,127.0.0.1:5173,127.0.0.1:5174' php vendor/bin/pest` (no path) | 0       | 3812 deprecated, 10 passed, **0 failed**; 19,708 assertions                                                                              |
| API style                      | `php vendor/bin/pint --test`                                                                                                | 0       | `passed`                                                                                                                                 |
| API static analysis            | `php vendor/bin/phpstan analyse --memory-limit=2G` (no path)                                                                | 0       | `[OK] No errors`                                                                                                                         |
| Main app tests                 | `npx vitest run`                                                                                                            | 0       | 263 files, 3161 tests passed                                                                                                             |
| Admin app tests                | `npx vitest run`                                                                                                            | 0       | 62 files, 539 tests passed                                                                                                               |
| API client tests               | `npx vitest run`                                                                                                            | 0       | 9 files, 228 tests passed                                                                                                                |
| Types                          | `vue-tsc --noEmit` (main, admin), `tsc --noEmit` (api-client)                                                               | 0, 0, 0 | clean                                                                                                                                    |
| ESLint (not run by the hooks)  | `npx eslint . --ext .ts,.vue` (main, admin)                                                                                 | 0, 0    | main: 0 errors, 2 warnings (`v-html` in `MasterAgreementTerms.vue` and `ProfileBasicsForm.vue`, neither touched by Part B); admin: clean |
| Commit hooks                   | lint-staged (Prettier, Pint) on every commit                                                                                | 0       | passed on each Part B commit                                                                                                             |

**Two things the gate run taught, both disclosed:**

1. **The first full Pest run had 78 failures, all login-door tests, and they were my mistake, not a regression.** The local `apps/api/.env` has `SANCTUM_STATEFUL_DOMAINS` without plain `localhost`, and
   `tests/TestCase.php` sends `Origin: http://localhost`, so Sanctum does not treat the test request as stateful and the login path throws "Login attempted on a request with no session bound". CI sets
   the value (`.github/workflows/ci.yml`); Part A's run used it and I forgot it for this one. With CI's value the same tree is 0 failed. This is a local quirk (a note is in the resumption template),
   not a code change; I did not touch `.env`.
2. **The first full main web run had one failure the targeted runs had missed:** `useShellBack.spec.ts` lists every creator route that gets the shell's back arrow, and the new `creator.security` route
   appeared in it. The Security page draws no back control of its own, so the arrow is correct and the spec's list was stale; I added the route with a comment. This is why the final gate is the whole
   suite, not the specs I thought were affected.

**Read this before trusting the Pest line.** As in Part A, every API test is reported _deprecated_ (`PDO::MYSQL_ATTR_SSL_CA` under PHP 8.5, from `config/database.php`, not touched). There are no failures.

## 4. Break-reverts

88 distinct mutations across B1 to B6: B1 19, B2 10, B3 16, B4 26 (23 web, 3 API), B5 4, B6 13 (11 web, 2 API). Every mutation applied to the working tree, the named specs run, the **exit code** read
(non-zero = CAUGHT), the file restored byte-for-byte and `git status --porcelain` read empty after each block. Verbatim in
[`account-deletion-request-b-break-reverts.md`](account-deletion-request-b-break-reverts.md).

**First-run survivors (7), all closed by a spec and rerun CAUGHT:** B2-9 (nothing asserted a live creator is not flagged erased: `44985261`), B4-6, B4-10, B4-18, B4-21 (Security page cases: `e1d344c8`),
B6-3 (a vacuous test of the unknown reason, tightened to an exact string), and B4-1, which was **my typo in the harness** (I gave the wrong spec path and Vitest ignores a filter that matches nothing); rerun
with the right path it was caught, and no spec was added for it. A background run that got killed mid-mutation once left `creatorLabel.ts` mutated; I restored it with `git checkout` and reran the whole
step; the logs are from the clean rerun.

## 5. Deviations, misses and open questions

1. **B1 and B2 are one commit** (`28796d19`). The scope, the guard and the `is_erased` flag share the manifest and the specs; splitting them would have left a commit with a failing manifest. Say if you
   want it split for the history.
2. **`assignment.cancelled` is always on** (`preference: null`, push-ineligible). My choice: it is an agency-side consequence of a person's legal request, not a marketing notice, so it has no preference
   row. You can overrule it (it would then need a row in the preference matrix).
3. **Lifecycle, contract and verification notices to a pending creator are not silenced.** Deliberate (C4: existing work stays live), but it means a pending creator can still receive those. Say if you
   want them held.
4. **The agency 422 title is English;** the SPA localises it through the code (`creator.unavailable`), the same convention as the rest of the API.
5. **No agency-side connection-request UI exists on the web;** that refusal is covered at the API only.
6. **Stored notification `creator_name` is handled by the erasure registry, not B3** (notifications already written before an erasure are scrubbed by the engine; B3 only covers live payloads).
7. **The typed word is lenient (diacritics and case ignored)** and is a usability gate only, as ruled.
8. **`CreatorProfileContent` shows the localised label** for an erased creator rather than a profile.
9. **The 429 handling is a client countdown** from `Retry-After`; there is no server-side lock beyond the limiter.
10. **Translations are a machine draft ×24.** I wrote the notice strings; several locales already carried English placeholder copy for older notification strings, which I left alone (not Part B's).
11. **Local Pest needs the CI Sanctum value** (§3). If you want the local `.env.example` or the test bootstrap to make that unnecessary, that is a separate small chunk; I did not do it unasked.

## 6. Not verified

- **The native device walk W1 to W10** (plan §9). Nothing here was run on a phone or in production; the Security page was verified by specs and the existing arch specs only, not on a real device.
- **Real mail rendering and delivery in two locales (W7)**, and the public page on a phone with the app installed (W8).
- **Postgres:** the new visibility queries and the rider have run on sqlite only (the tech-debt row 1 gap, now wider). The scope is a `whereNull('erased_at')` plus a `whereNotExists` on `users.deletion_requested_at`; I know of no dialect issue, but I did not run it on Postgres.
- **Performance** of the discovery filter at production size; I added no index in Part B: the scope is a `whereNull('creators.erased_at')` plus a correlated `whereNotExists` on `users.deletion_requested_at` (Part A's index on that column is the only help), and I did not measure it.
- **The agency "Deleted creator" screens on a real erased account**: none can exist in production yet; see W11.
- **A browser (Playwright) pass** over the Security page and the agency screens; CI's Playwright jobs run on push.

## 7. Named steps (the build-3 checklist and the walk)

**Named build-3 steps, unchanged from the plan and extended:**

1. The human pass on the **en and es** strings (the consequences copy, the dialog, the public page, the creator label, the notice) before the flag flips (C9).
2. W1 to W10 on a named test creator, flag ON for the window and OFF again after.
3. Then Pedram turns `account_deletion_enabled` ON in the admin (reason required) before build 3 is submitted.
4. Part A's `queue:restart` stays required; Part B adds nothing.

**Proposed additions (not yet ruled):**

- **W11** (an agency sees "Deleted creator"): on a local or seeded database, mark one creator erased and open the roster, a thread, an application, an assignment and a board card as an agency; each shows
  "Deleted creator" with a neutral avatar and no name, email or photo.
- **W12** (the agency bell notice): on the same database, cancel an erased creator's open assignment through the rider; the agency's admin sees one notice per assignment, no creator name, no mail, no
  push; tapping it opens the campaign's creators tab.

**Decision needed from you:** accept W11 and W12 (they cannot be walked on production because nothing is erased there), overrule or confirm items 2 and 3 in §5, and say whether to push Part B.
**I will not push without your trigger.**

## 8. Walk results (Pedram, 2026-10-22, local stack, phone on a dev build)

**AH-104b-del W1 to W12**

| #       | Result   | Note                                                                                                                                                             |
| ------- | -------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| W1      | pass     |                                                                                                                                                                  |
| W2      | pass     |                                                                                                                                                                  |
| W3      | pass     |                                                                                                                                                                  |
| W4      | pass     |                                                                                                                                                                  |
| W5      | pass     | Restore did not re-ask the password: by design. The sign-in form keeps the credentials and re-sends them with `cancel_deletion`; the server verifies them again. |
| W6      | pass     |                                                                                                                                                                  |
| W7      | pass     |                                                                                                                                                                  |
| W8      | pass     | Local page read only; the live URL is checked after the web deploy.                                                                                              |
| W9      | pass     |                                                                                                                                                                  |
| **W10** | **FAIL** | No way to enter a recovery code on the app's sign-in. Gap, fixed in `6aa9af1f`; see below. Re-walk on the new build.                                             |
| W11     | partial  | Roster, thread and board showed "Deleted creator". The assignment surface is the campaign Creators tab (below).                                                  |
| W12     | pass     |                                                                                                                                                                  |

**W10 diagnosis.** Not web-only and not hidden. The server accepted a recovery code at sign-in all along (`TwoFactorChallengeService` tries a TOTP, then the recovery codes). The field on the
sign-in page and in the delete-account dialog was a numeric-keypad field labelled "authenticator code", with no way to say "I have a recovery code". Present since Sprint 1 on every 2FA field in the
main app. Fixed on the two pages the walk touches: a "Use a recovery code instead" switch relabels the field, swaps the keyboard to text and switches it back (`useMfaCodeInput`, 24 locales, machine
draft). Nine break-reverts, all caught. The other 2FA fields are in tech-debt ("Second-factor fields without a recovery-code switch").

**W11 path.** There is no standalone agency assignment page. Assignments appear on the campaign's Creators tab (`/campaigns/:ulid?tab=creators`, all statuses; the walk row is Walk Campaign,
status cancelled) and in the Board card drawer. Re-check: Campaigns, Walk Campaign, Creators tab; the row reads "Deleted creator" and opens a profile dialog without a name.

**UI nit (native "Delete your account?" sheet).** The checkbox glyph sat 13px right of the title's text edge. Fixed in `b7e6d54b`; measured in a browser (title, checkbox glyph and fields
at x): before 40 / 53 / 48, after 48 / 48 / 48. Not yet seen on the phone.

**`erase-gone.sh` on local Postgres (local evidence only, not specs).** Migrations applied; the dry-run printed one `campaign_assignments.cancel`; the execute run completed (ledger row 1,
`state: completed`; creator 13 `erased_at` set, `display_name` NULL). This is the first run of the engine on Postgres. The seeded creator has one assignment and no media or objects, so it
does not cover the S3 purge, volume or the production role.

**AH-103d W1 to W6.** W1, W2, W4, W5 (no `POST /auth/logout` in the API log since the launch), W6 pass. **W3 is not walkable on a dev build**: iOS needs the network to verify the first launch
after a reinstall. Re-run W3 on TestFlight build 3. AH-103d stays "walk pending" for W3 only.

**Gates for the two fixes (`6aa9af1f`, `b7e6d54b`).** `apps/main` vitest 263 files, 3166 tests, 0 failed; `vue-tsc --noEmit` exit 0; ESLint exit 0 (two existing `v-html` warnings). Admin,
api-client and the API are untouched. Not pushed.

### 8a. Re-check of W10 and the Security entry (2026-10-22, build with the recovery switch)

- **Switch works; the recovery code signs in.** Confirmed by Pedram on the phone.
- **Spent code stayed in the field on the pending screen**, so Restore sent it again and failed ("two-factor code is invalid"). Fixed: when the restore step appears, the code field is emptied,
  rebuilt (new `key`) and focused, for a recovery code and for an authenticator code. Cause on the device is not proven: the page already cleared its model for a recovery code and the jsdom spec
  passed, so the fix also remounts the field. **Focus on a real iPhone is not verified**: iOS may refuse to raise the keyboard from code that did not start with a tap. Spec: field empty,
  focus called on the new element; five break-reverts, all caught.
- **No Security entry after restoring with an authenticator code: the flag.** `account_deletion_enabled` was OFF locally (turned off after the browser measuring, before this re-check), and the
  entry is `features.account_deletion` on the user, which the server reads from the flag on every response. The restore response carries it on both doors (new feature test, with a break-revert).
  Not a refresh bug. Re-check with the flag ON and a fresh sign-in.
