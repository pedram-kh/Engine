# AH-104c C2 — review (the hold, the erasure queue, the admin read-through, the sheet's left edge)

**Status:** C2 built locally as a feat + docs commit pair, **not pushed**. C1 is cleared and pushed (CI green at the tip, run 40203569216). This review is the stop Pedram asked for: nothing below is pushed or deployed.
Plan: [`account-deletion-admin-plan.md`](account-deletion-admin-plan.md) §4 C2. Break-revert log, verbatim: [`account-deletion-admin-c2-break-reverts.md`](account-deletion-admin-c2-break-reverts.md).

**PROD-DATA RISK: LOW to MEDIUM at deploy.** **No migration** (the hold columns arrived in C1). Three things reach production data. (1) The claim now revokes every session and token, inside its own
transaction; nothing claims until C3 or a shell `--execute`, so deploying C2 revokes nothing. (2) Hold and Release write one user column and one audit row each, on an account an admin chose, behind 2FA. (3) The
queue and the read-through are reads (five admin payloads gain two additive keys; no agency payload changes, and a spec says so). The risk event is still the first real erasure, not this deploy.
Deploy shape: a normal API and SPA deploy; no `queue:restart` needed, no env, no flag, no worker change (deploy-log entry added).

## 1. What was built

| Step | What                                                                                                                                                                                                                                                                                                                       |
| ---- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| C2-0 | The two C1 items you ruled yes on. `Creator::scopeVisibleToAgencies()` and `isUnavailableToAgencies()` read `users.erasure_started_at` like `deletion_requested_at`; `ErasureClaimer` calls `CredentialRevoker::revokeAll` inside the claim's transaction, in `claim()` and `reclaim()`.                                   |
| C2-1 | `ErasureHold` (`hold`, `release`; locked, idempotent, a reason is required; refuses a claimed account with 409, an erased account and a non-creator with 422), `ErasureHoldRefused`, two audit verbs, `ErasureHoldRequest` (10 to 1000, trimmed), `POST /admin/compliance/erasure-queue/{user}/hold` and `/release`.       |
| C2-2 | `ErasureQueue` (`counts`, `page`, `rowFor`) and `GET /admin/compliance/erasure-queue?tab=&cursor=&per_page=`: six tabs, precedence erased > in progress > held > blocked > ready > pending, an opaque cursor, a bounded scan for Ready and Blocked, an Erased tab read from the ledger that carries nothing of the person. |
| C2-3 | The admin page (Compliance, Erasure queue): six tabs with counts, a one-sentence state per row (the stuck states have their own sentences), Hold and Release dialogs (reason 10 to 1000, the "no personal details" line), Load more, a cap notice, error codes mapped; `compliance.json` ×24.                              |
| C2-4 | `AdminDeletionFlags` (`is_erased`, `deletion_pending`) on five admin payloads; the admin reader `core/creators/creatorLabel.ts` and the "Deletion requested" chip, used by seven pages; `creators.json` ×24; an API manifest of every admin surface that shows a creator; a static spec for the single reader.             |
| C2-5 | The delete sheet's checkbox edge in the creator app: measured, fixed, and measured again (§5). **Not yet read on the phone.**                                                                                                                                                                                              |
| C2-6 | This file, the break-revert log, the runbook (§1, new §1b), `05-SECURITY-COMPLIANCE.md` §3.3a, tenancy rows, tech-debt (item 5 closed, C2-4a opened), the adhoc log, the deploy log, the resumption template, the glossary ("hold" and "Erasing" in the interface; never "claim").                                         |

## 2. The C1 review's three questions, and what I did with each ruling

| Ruling                                                              | Done as                                                                                                                                                                                                                                       |
| ------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1. Close the force-claim visibility gap in C2                       | C2-0. `CreatorUnavailableToAgenciesTest` (35): a claimed creator who never asked is hidden from the scope and refused by its single-row twin; the manifest spec's matrix gained the row. **Tech-debt "AH-104b-del Part A" item 5 is closed.** |
| 2. Revoke sessions and tokens at the claim, in the same transaction | C2-0. `ErasureClaimerTest` (31): `claim()` and `reclaim()` revoke every session, API token and push token; a refused claim revokes nothing (the transaction rolls back).                                                                      |
| 3. Accept the code `admin.impersonation.target_unavailable`         | Accepted; no change.                                                                                                                                                                                                                          |

## 3. Behaviour to read before you clear C2

- **The six tabs.** Pending: a request stands and the grace has not ended. Ready: due, not held, not claimed, no refusal. Blocked: due with a reason (`payment`, `delivered_work`, `vendor`, or
  `not_erasable` for an account with no creator profile). Held. In progress (claimed, not erased; the row carries the ledger state, and `incomplete` has its own sentence). Erased. **An account is in exactly
  one**, the first that applies in that order, so a claimed and held account reads "In progress" (the hold cannot be set once claimed, but the order is pinned anyway).
- **"Ready" is a read.** Nothing in C2 can erase; the Erase button is C3, behind `account_erasure_execute_enabled`.
- **The hold.** Reason 10 to 1000 characters, in the audit row only (Q5), with a "no personal details" line in the dialog. Idempotent. Refused once claimed (409). **A hold survives the creator cancelling**
  (Q4): the creator's cancel touches the two request columns only, and a spec holds, cancels and asks again.
- **The Erased tab has no PII by construction.** Its row keys are `id`, `type` and `attributes{state, ledger_state, erased_at}` and a spec pins the **key set**, so a field added later fails it.
- **The chip is admin only.** `deletion_pending` is never sent to an agency; a request that is later cancelled leaves no trace there. A spec scans the agency modules for the key and for `AdminDeletionFlags`.
- **Admin screens say "Deleted creator".** The reader refuses the placeholder email `erased-<ulid>@erased.invalid` even if a payload leaked it, and an edit form is never pre-filled with a name that is gone.
- **Error codes.** `admin.erasure.hold.claimed` (409) and `admin.erasure.hold.not_available` (422). The plan said `erasure.hold.*`; see deviation 3.

## 4. Gates

Each run on its own, exit code read, at the final C2 state (working tree; nothing committed when these ran). Local PHP is 8.5, which prints PDO deprecation notices (`Constant PDO::MYSQL_ATTR_SSL_CA`): that is
the "deprecated" count in every Pest summary, environmental and the same as in C1.

| Gate                           | Command (from the package directory)                                                                                        | Exit    | Result                                                                                        |
| ------------------------------ | --------------------------------------------------------------------------------------------------------------------------- | ------- | --------------------------------------------------------------------------------------------- |
| API tests, whole suite, serial | `SANCTUM_STATEFUL_DOMAINS='localhost,localhost:5173,127.0.0.1,127.0.0.1:5173,127.0.0.1:5174' php vendor/bin/pest` (no path) | 0       | 4002 deprecated, 17 passed, **0 failed**; 20,501 assertions (the local PDO notice, as before) |
| API style                      | `php vendor/bin/pint --test` (no path)                                                                                      | 0       | `passed`                                                                                      |
| API static analysis            | `php vendor/bin/phpstan analyse --memory-limit=2G` (no path)                                                                | 0       | `[OK] No errors`                                                                              |
| Main app tests                 | `npx vitest run` in `apps/main`                                                                                             | 0       | 264 files, 3173 tests passed                                                                  |
| Admin app tests                | `npx vitest run` in `apps/admin`                                                                                            | 0       | 64 files, 574 tests passed                                                                    |
| API client tests               | `npx vitest run` in `packages/api-client`                                                                                   | 0       | 9 files, 228 tests passed                                                                     |
| Lint                           | `npx eslint . --ext .ts,.vue` in `apps/main` and `apps/admin`                                                               | 0, 0    | clean                                                                                         |
| Types                          | `npx vue-tsc --noEmit` in `apps/main` and `apps/admin`; `npx tsc --noEmit` in `packages/api-client`                         | 0, 0, 0 | clean                                                                                         |
| Format                         | `npx prettier --check src tests` in `apps/main` and `apps/admin`                                                            | 0, 0    | clean                                                                                         |
| i18n parity                    | `node scripts/i18n/verify-locale.mjs <the 23 non-en locales>`                                                               | 0       | 23 PASS (main + admin against `en`)                                                           |

The first full Pest run failed (10 specs; see below), the first PHPStan run reported 20 errors, and the first admin Vitest run failed one spec; the table is the **second** full run of each at the final
state, after the fixes. The frontend gates were run twice for the same reason (once before the API fixes, once after the break-revert re-runs, both green).

The new and changed specs: `ErasureClaimerTest` 31 (+4), `CreatorUnavailableToAgenciesTest` 35 (the claimed-creator cases added), `ErasureHoldTest` 28, `AdminErasureQueueTest` 28, `AdminDeletionFlagsTest` 18,
`AdminCreatorSurfacesTest` 7, `AdminComplianceShellTest` 4 (exports only); admin: `ErasureQueuePage.spec.ts` 15, `creatorLabel.spec.ts` (reader and chip), `creator-label-single-reader.spec.ts` (4), page tests on
six pages plus the detail page; main: `delete-sheet-left-edge.spec.ts` (4).

**Found by the gates, fixed:**

- **My C2-0 change broke the erasure digest, and no spec I ran while building saw it.** The dry-run digest hashed the row counts of `sessions`, `personal_access_tokens` and `user_push_tokens`; `execute()`
  recomputes it **after** the claim, and the claim now revokes all three, so for any account with a live credential the two digests could never match. The first full Pest run failed **ten specs**
  (`ErasureCommandTest`, `ErasureMirrorTest`, `ErasureDryRunTest`). Fixed in `DryRunReport::digest()`: the credential tables (`CredentialRevoker::TABLES`) are still **printed** but no longer **hashed**
  (a person signing in or a token refreshing is not "the data changed", and the claim has already ended every one). It matters for the shell's `--force-not-requested` and for C3's click-then-job; in normal
  mode a request has already revoked everything, so production would not have seen it until C3. A digest printed before this deploy is not valid after it (no erasure has ever run in production, so none is
  outstanding). Detail in the break-revert log.
- **A wall-clock flake in `AdminErasureQueueTest`** (the grace-boundary spec compared against a real `now()`); time is now frozen for the file.
- **PHPStan reported 20 errors in my C2 code and specs** (level 8 covers `tests/`): a docblock parsed wrongly, a Carbon method its stubs do not know, `collect()` over a `mixed` JSON body, `test()` in helpers,
  non-exhaustive `match`es, `strpos()` returning `false`. Fixed without ignores, casts or `@var`.
- `vite-vuetify-prebundle.spec.ts` failed: C2-3's `ErasureQueuePage.vue` uses `VTabs` and `VTab`, which were not in the dev pre-bundle list of `apps/admin/vite.config.ts` (a cold dev start would have reloaded the page
  on first visit). Added `VTabs`. This was **my miss in C2-3**, found only when the full admin suite ran.
- The `mountSupportPage` test harness did not load `creators.json`, so `admin.creators.erased` showed as a raw key in the user-search spec. Production merges every namespace; the harness now loads it too.
- Prettier had not been run over the appended spec tests (a template put an unquoted route name in three specs; `vue-tsc` caught it).

**Proof by break-revert.** 69 distinct mutations (C2-0 7, C2-1 12, C2-2 16, C2-3 13, C2-4 16, C2-5 5), each with its exit code read and the file restored identical; the log is verbatim in
[`account-deletion-admin-c2-break-reverts.md`](account-deletion-admin-c2-break-reverts.md). **One was not caught, and it is an equivalent mutant:** C2-0 "the revocation runs before the gate in `claim()`"
passes all 31 `ErasureClaimerTest` specs because a refusal throws and the single transaction rolls the revocation back (the same shape as C1's "stamp before the checks"); the scan that keeps the revocation
inside the transaction is C1's. **Four survived or aborted on their first run and were closed** (the hold's reason-required check, the Erased tab's counts, a Prettier-moved target, the "pending once erased" guard),
and **two problems were found by the full gate run rather than by any mutation** (the digest, and a clock flake; §4). The C2-0 results: both C1 rulings are held by specs (§2), and the 7 C2-0 mutations are
caught except the equivalent one.

## 5. C2-5: the sheet's left edge, and what I could and could not measure

**What was claimed before.** The first fix (`b7e6d54b`) was measured in a browser and looked right. You found it was wrong on the phone. I did not trust a browser number again.

**What I did.** I rendered the real `DeleteAccountDialog.vue` as the creator app renders it: the **bottom sheet** (the app's branch, `isNativePlatform()` forced true), the app's own theme and **`native.css`** (the
stylesheet the app loads at boot, which the first measurement could not have had), in **Playwright's WebKit (the engine of the phone's WebView)** at an iPhone 15 Pro viewport (393 css px, 3x). Left edges are
read twice: `getBoundingClientRect()` and, for the drawn box, by **finding the leftmost ink pixel** in the screenshot (the box is a font glyph, so its element's rect is not where it is drawn).

| Left edge, css px (card border included)                         | Before       | After        |
| ---------------------------------------------------------------- | ------------ | ------------ |
| Title text                                                       | 25           | 25           |
| **Checkbox box (ink)**                                           | **26.33**    | **25.0**     |
| Password field outline                                           | 25.0         | 25.0         |
| Typed-word prompt                                                | 25.0         | 25.0         |
| Typed-word field outline                                         | 25.0         | 25.0         |
| _(web `v-dialog`, same page without `native.css`)_ box vs fields | 48.0 vs 48.0 | 48.0 vs 48.0 |

Screenshots: [`before-native.png`](account-deletion-admin-c2-assets/before-native.png), [`after-native.png`](account-deletion-admin-c2-assets/after-native.png). The harness (a throwaway Vite page, a Playwright
script and a pixel reader) is in [`account-deletion-admin-c2-assets/align-harness/`](account-deletion-admin-c2-assets/align-harness/); it is evidence, not part of any build.

**Cause, found by the measurement.** Under `native.css` the compact checkbox's icon is 20px, not 24px (`.v-input--density-compact` and the native body font change it), inside a 28px wrapper, so the slack is 4px,
not the 2px the first fix subtracted. The mdi glyph is drawn 3 of 24 units inside its icon. The first fix did arithmetic on a slack worked out on the web; it was right on the web and 1.3px off in the app.
**The new rules do no arithmetic:** the icon is 24px, its wrapper is exactly 24px from the edge, and the box is pulled back by the glyph's own 3px. Nothing depends on density, font size or the native stylesheet.

**What I could not do, and why.** The device number is **not read**. The phone (Pedram's iPhone 16 Pro) is paired and available, and its WebView is inspectable in a Debug build (Capacitor 8 sets it from `DEBUG`,
so step 1 needs no change), but reading the sheet's numbers needs the sheet **open on the phone**, which needs a signed-in creator, the local API reachable on the LAN, and someone to tap through to
Security, Delete account. I cannot tap the phone, and I did not install a build onto it unasked. So:

- **Measured, in the phone's engine:** the structure and the numbers above, before and after.
- **Not measured:** the phone's own pixels. WebKit-on-a-Mac and the phone's WKWebView share the engine but not the fonts' rasterisation, the display scale handling or iOS's own text-size rules; a sub-pixel
  difference is possible, a 2px one is not what the measurement predicts.
- **The spec** (`delete-sheet-left-edge.spec.ts`) pins only the structure: the three rules and the absence of `calc()` and of `--v-selection-control-size`. jsdom cannot lay anything out, so it proves **no pixel**; it stops
  the arithmetic coming back.
- **Pedram's eyes on the phone are the final check.** If you want the numbers from the device too: rebuild and install with the usual sequence (`build:native:dev`, `cap sync ios`, `xcodebuild`, `devicectl`),
  open the sheet, attach Safari Web Inspector (Develop menu, the phone, Catalyst), and paste this in the console:

```js
const l = (s) => document.querySelector(s)?.getBoundingClientRect().left
console.log({
  titleBox: l('[data-test="delete-account-dialog-title"]'),
  ackIconBox: l('[data-test="delete-account-ack"] .v-selection-control__input'),
  password: l('[data-test="delete-account-password"] .v-field'),
  word: l('[data-test="delete-account-word"] .v-field'),
})
```

The expected reading is `titleBox` 1 (the card border), `ackIconBox` **22** (the icon box starts 3px left of the edge on purpose; the drawn box is then at 25), `password` and `word` 25. If `ackIconBox` is anything other
than 22 on the phone, that is the finding.

## 6. Deviations from the plan, and what I decided

1. **Ready and Blocked come from a bounded scan, not from a query.** The plan described the tabs as queries. The guards (`PaymentGuard`, `DeliveredWorkGuard` and above all `VendorGuard`, which asks an external
   state) cannot be written as cheap SQL, so `ErasureQueue` selects the **first 200 due accounts in user-id order (the oldest accounts first)** and asks each guard (the same `blockersOf` that the claim's refusals use). **Cost:** a few queries per due row, **once per request**
   (memoised for the counts and the page), and **none for Pending, Held, In progress or Erased**, which are plain indexed predicates. **Cap:** if more than 200 are due, the counts carry `due_scan_capped`
   and the Ready and Blocked tabs show a notice ("Only the first 200 accounts that are due are checked at a time"). **Why it is acceptable now:** the queue is a handful of requests; the cap is a ceiling nobody
   reaches this year. **What would make it wrong:** a backlog of more than 200 due accounts (an outage of the operator, not of the platform) would hide the highest-id due accounts from both tabs until the lower ones are handled.
   The query count is pinned constant (2 for 25 rows and for 1 row, after a warm-up request); the cap is pinned by a spec.
2. **Per-row guard cost is bounded by the cap, not by the page size.** Counts need every due row classified, so a request for the first page of Ready also classifies the other due rows. Same ceiling, 200.
3. **The hold's error codes are `admin.erasure.hold.*`, not `erasure.hold.*`.** The admin SPA resolves an error `code` as an `admin.*` locale key (the same reason as C1's impersonation code). Same statuses.
   I found it when `t(code)` did not resolve; renamed in the controller, the specs and the locale files.
4. **The Erased tab is read from the ledger, not from `users`.** An erased user row is a placeholder; the ledger row carries the date and state and nothing of the person. Its cursor descends by ledger id.
5. **`deletion_pending` is `!erased && pending` in the API, not in the SPA.** An erased creator never reads as "leaving", even if the user row and the creator row disagree (a spec sets exactly that).
6. **Not in the plan: `VTabs` in the dev pre-bundle list** (the gate caught it), and the support harness fix (§4).
7. **The impersonation search is flagged from the user row; the other four from the creator row.** It lists users, not creators; both read the same two columns.
8. **Not in the plan: the digest no longer hashes the credential tables** (§4, first item). It is a change to the erasure engine's contract (what the confirmed digest stands for), made because the claim
   now revokes them. Say if you want the other reading: leave the digest alone and revoke at the start of `execute()` instead, which weakens ruling 2 ("at the claim, in the same transaction").

## 7. Not verified, and the new tech-debt

- **The phone** (§5): the numbers and the look of the sheet on the device.
- **Postgres.** The hold's row lock, the queue's range reads and the claim's revocation have run on sqlite only (tech-debt "AH-104b-del Part A" item 4; the same slot as before C3).
- **The Erase flow itself** (C3), the colleague's read-back E (before C4), AH-103d W3 on TestFlight build 3.
- **A real browser walk of the admin page.** The page is covered by 15 component specs with the real Vuetify and i18n, not by a Playwright run against the API. The C2 walk below is the check.
- **NEW, open, for a ruling: tech-debt "AH-104c C2-4a".** An admin can connect a leaving creator to an agency by hand (`AdminCreatorConnectionController`), and an automation rule (`RunAutomationRuleJob`) can do
  it too, because neither asks `isUnavailableToAgencies()`. The admin screens now show the chip, so a person can see it, but nothing refuses. Both are recorded in the C2-4 manifest as `open`, naming the entry, so
  the manifest spec fails the day either is changed without the entry being read. I did **not** fix it: it changes what two admin actions do, which is yours to rule.

## 8. The C2 walk (for Pedram, web admin; the phone items are marked)

1. As a platform admin with 2FA, open Compliance, Erasure queue. The six tabs show counts that add up to the number of creators with a request, a hold, a claim or an erasure.
2. With the flag `account_deletion_enabled` on for a named test creator: ask to delete. The creator appears on **Pending** with the days left; the creator list shows "Deletion requested". Cancel as the creator: the
   chip and the row are gone; an agency never saw either.
3. Hold the pending creator: the dialog refuses a reason of 9 characters, accepts 10, says no personal details. The row moves to **Held**. Cancel as the creator again and ask again: still Held.
4. Release it. Back to Pending.
5. In the database, set the creator's `deletion_scheduled_for` to a past time (a test account only): the row is **Ready**; give the account an assignment in `live_verified`: **Blocked**, reason delivered work.
6. Open the creator detail, the app-usage list, push deliveries and the user search for an erased creator (a test one erased on staging): each says "Deleted creator" with no `erased.invalid` address.
7. **On the phone:** Security, Delete account. The checkbox, the title, the password field and the typed-word field share one left edge; paste the console snippet in §5 if you attach the inspector.

## 9. Questions for Pedram

None block C3. Four for the review, each with my recommendation:

1. **C2-4a: close it in C3?** _Recommend: yes._ `AdminCreatorConnectionController::store` and `RunAutomationRuleJob` ask `isUnavailableToAgencies()` and answer 422 `creator.unavailable` (the job: skip and
   record). It is small, it changes two admin actions, and it must land before the first real request exists, which is now.
2. **Accept deviation 1 (the 200-row scan) as is, or put the due scan behind a nightly materialised list?** _Recommend: as is_, and raise `DUE_SCAN_CAP` if a backlog ever shows the notice.
3. **Accept deviation 3 (`admin.erasure.hold.*`)?** _Recommend: yes._
4. **Accept deviation 8 (the digest no longer hashes the credential tables)?** _Recommend: yes_: it keeps ruling 2 (revoke at the claim) whole, and the credentials are the one thing the claim itself changes.
