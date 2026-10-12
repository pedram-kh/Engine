# AH-103 — deep links: a notification opens the screen it is about — review

**Status:** Complete. Device walk accepted (Samsung and iPhone), finding 2 fixed and re-verified on a device, **pushed 2026-10-12**
(`origin/main` `cb79abfa..47d2efc5`, 20 commits), CI **green on all four jobs**
([run 38289855211](https://github.com/pedram-kh/Engine/actions/runs/38289855211)). **Not deployed. No flag armed by this chunk.**
**Date:** 2026-10-12. **Plan:** [`deep-links-plan.md`](deep-links-plan.md). **Inventory:** [`deep-links-inventory.md`](deep-links-inventory.md).
**Prod-data risk:** LOW. No migration, no new route, no preference-contract change. One additive field (`campaign_ulid`) on the in-app bag
at all three emit sites (`campaign.job_posted`, the agency leg of `message.received_by_agency`, `campaign_application.submitted`) and a
per-type route-key allowlist on the push payload.

---

## 0. Three lines to read first

1. **What it is.** A tap on a push, or on a row in the in-app notification centre, runs through one resolver
   (`parsePushTapData` → `resolveNotificationDestination`) and lands on the screen the notification is about: the assignment, the
   chat, the thread, the job, the invitation, the dashboard, or the agency campaign on the right tab. Anything the resolver cannot
   place falls back to the notifications list. It never produces a dead tap.
2. **The one thing that can hurt a user** is a push payload leaking free text to a lock screen. It cannot: the route keys are an
   ULID-only, per-type allowlist (`PushMessageFactory`), shared with the SPA through one fixture
   (`packages/api-client/contracts/notification-route-keys.json`), and four sentinel tests pin the shape (plan §3.2).
3. **The walk found a real defect in AH-102 code, not in this chunk's** (finding 2, §5): after a server-side revoke and a re-login the
   device never re-registered its push token, so it received no pushes. It was fixed here (`47d2efc5`) because the user bounded it
   into this chunk, with a break-revert and a re-run of the failing walk step on a device.

---

## 1. What was built (20 commits on top of `cb79abfa`)

| Commit                             | What                                                                                                                                                                                                                |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `8378e4e5`, `7d8c9166`, `e2fa3c97` | Inventory, inventory addendum (device spike), plan. Read-only; plan-pause                                                                                                                                           |
| `85c9f49e` (C1.1)                  | The shared route-key contract fixture and the backend parity test                                                                                                                                                   |
| `128d75a2` (C1.2)                  | Emit-site `campaign_ulid` at all three emit sites (`campaign.job_posted`, the agency leg of `message.received_by_agency`, `campaign_application.submitted`), with the real-emitter contract test in the same commit |
| `d4258820` (C1.3)                  | The per-type `ROUTE_KEYS_BY_TYPE` allowlist in `PushMessageFactory`, the ULID-shape filter, the sentinels                                                                                                           |
| `577ee2e3` (C2.1)                  | A required `destination` on every live notification type, plus registry parity and route-table specs                                                                                                                |
| `520b929d` (C2.2)                  | The pure push-tap parser and destination resolver                                                                                                                                                                   |
| `a2ec5e8a` (C3.1)                  | The tap seam carries `notification.data`                                                                                                                                                                            |
| `c471a083` (C3.2)                  | The push tap handler uses the resolver; marks the row read only after navigation settles with a live session                                                                                                        |
| `2a1c08ac` (C3.3)                  | `NotificationCenter` row click goes through the same resolver; the "D-7 deferred" comment is gone                                                                                                                   |
| `2a524a41` (C4.1)                  | `redirect=` through the native session-lapse redirects (a revoked-token tap returns to its target after sign-in)                                                                                                    |
| `aed227c9` (C4.2)                  | `?tab=` opens the named tab on the agency campaign page, plus the one web E2E spec                                                                                                                                  |
| `2c90b98c` (C4.3)                  | The not-found assignment links back to notifications; one key, all 24 locales (AH-102 locale process)                                                                                                               |
| `4366fc83` (C4.4, **GO**)          | `?focus=messages` scrolls the chat into view; the go/no-go rule (≤ ~15 lines + one spec) was met                                                                                                                    |
| `0700f7b8`                         | Larastan level-8 fixes in the two AH-103 backend tests                                                                                                                                                              |
| `771d2287`, `df2fff9c`             | `ro`/`mt` notification-centre titles and the `ro` "Setări notificări" strings, walk-visible, fixed                                                                                                                  |
| `30a3e0fb`                         | `tech-debt.md` addendum on the unreviewed-MT row                                                                                                                                                                    |
| `47d2efc5`                         | **Finding 2:** re-register the push token on every sign-in (§5)                                                                                                                                                     |

Net: 70 files, +4699 / −122 (including docs).

**Rulings carried (all recorded in the plan):** Option B route keys (additive, ULID-only, `v` stays `1`); P-Q2 (the signed-out
thread tap degrades to the list); P-Q3 (`v` absent → 1; greater than known → still read the keys); P-Q5 (a per-type map); P-Q6 (both
camera observations to tech-debt); P-Q7 (one web E2E spec); P-Q8 (the AH-102 locale process, named in the commit); P-Q9 (the device
walk was Checkpoint A's acceptance). **Out of scope, named follow-ups:** universal/app links → **AH-103b**; biometric-lock interplay
→ **AH-103c**.

---

## 2. Gate board

| Gate                  | Result                                                                                                                                       |
| --------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| Pint                  | clean                                                                                                                                        |
| Larastan level 8      | clean (after `0700f7b8`)                                                                                                                     |
| Pest (full)           | green. Locally needs `SANCTUM_STATEFUL_DOMAINS="localhost,localhost:5173,127.0.0.1"` and no `--parallel` (local `.env` quirk, CI unaffected) |
| `vue-tsc`             | clean, re-run after the finding-2 fix                                                                                                        |
| eslint                | 0 errors                                                                                                                                     |
| Vitest, `apps/main`   | **2646 passed in 239 files at the pushed tip** (CI). Local run at Checkpoint A: 2632                                                         |
| Playwright, main SPA  | **35 passed** (CI), includes `notification-deep-link.spec.ts`                                                                                |
| Playwright, admin SPA | 2 passed (CI)                                                                                                                                |

**CI tally (the only run for this push):** [38289855211](https://github.com/pedram-kh/Engine/actions/runs/38289855211), tip `47d2efc5`:
Backend (Pint + Larastan + Pest) success · Frontend (lint + typecheck + unit) success · E2E main SPA success · E2E admin SPA success.
Run time 18:17 → 18:34 UTC. Previous `main` tip `cb79abfa` was also green. No reruns and no flaky tests were reported.

**The Playwright deep-link spec's first CI run passed** (it had no run before this push; Checkpoint A accepted that as a known caveat).
The spec is listed locally (`--list`: 35 tests, one is `notification-deep-link.spec.ts:67`) and CI reports 35 passed.

---

## 3. Break-revert record

Each change was proved able to fail: mutate the production code, expect a specific failure, restore. Counts are failing tests per
mutation as recorded when each commit was made; the mutation list lives in the commit sessions, not reconstructed here.

| Commit                     | Mutations and failing counts                                                                              |
| -------------------------- | --------------------------------------------------------------------------------------------------------- |
| C1.2                       | 6 mutations                                                                                               |
| C1.3                       | 7 / 1 / 18 / 1                                                                                            |
| C2.1                       | a `tsc` error, then 3, 11, 3, 2. The fixture-row-delete mutation was **re-measured** as 2 Vitest + 4 Pest |
| C3.2                       | 2 / 1 / 2 / 2 / 6                                                                                         |
| C3.3                       | 6 / 1 / 1                                                                                                 |
| C4.1                       | 1 / 3 / 2                                                                                                 |
| C4.2                       | 2 / 1 / 1                                                                                                 |
| C4.3                       | 2 / 2                                                                                                     |
| C4.4                       | 3 / 1 / 1                                                                                                 |
| **Finding 2 (`47d2efc5`)** | see below                                                                                                 |

**Finding 2 break-reverts (all restored byte-for-byte, 89/89 in `src/core/platform/push` afterwards):**

| #    | Mutation                                                                                                      | Failing         |
| ---- | ------------------------------------------------------------------------------------------------------------- | --------------- |
| BR-1 | `registerAfterSignIn()` delegates to the throttled `syncIfGranted()` (the old trust-`registeredAt` behaviour) | 1 (the W8 case) |
| BR-2 | the session watcher calls `syncIfGranted()` (the old `main.ts` wiring)                                        | 4               |
| BR-3 | no session watcher at all                                                                                     | 4               |
| BR-4 | the watcher also fires on sign-out (id → null)                                                                | 2               |

---

## 4. The device walk (Checkpoint A's acceptance) — W1–W11 on real emits

**Setup:** the AH-102 walk setup; flag armed locally; queue worker running; Samsung SM-A515F over `adb reverse`, iPhone over LAN;
creator `pedram+32@defraged.com` (user 14), an agency admin (user 5). Pushes came from real emits (the real
`AssignmentTransitioned` listener and the real agency HTTP routes), not `push:test`.

| W   | Samsung | iPhone  | Evidence                                                                                                                                                                                |
| --- | ------- | ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| W1  | pass ×4 | pass ×3 | Foreground (row), background and killed all land on the assignment. Rows 58–60 (Samsung) and 77–79 (iPhone) were read at the tap                                                        |
| W2  | pass    | pass    | Assignment, chat visible without scrolling (rows 61, 80)                                                                                                                                |
| W3  | pass    | pass    | The right thread by lookup (rows 62, 81)                                                                                                                                                |
| W4  | pass    | pass    | Job detail for the push (63, 82) and for the old row with no `campaign_ulid` (64, 83)                                                                                                   |
| W5  | pass    | pass    | Dashboard (65, 84), messages inbox (66, 85), notifications list (67, 86)                                                                                                                |
| W6  | pass    | pass    | In-app muted: no row created; the push alone opened the assignment                                                                                                                      |
| W7  | pass    | pass    | A route type reaches the target after sign-in; a thread type reaches the list (the accepted P-Q2 degradation)                                                                           |
| W8  | pass    | pass    | Revoked token, warm tap, sign-in, then the target. iPhone strict-order re-run: revoked 17:28:04Z, row 91 delivered and unread, new PAT 17:29:12Z, assignment loaded right after sign-in |
| W9  | pass    | pass    | Not-found state with a working link back to the notifications list (rows 71, 92)                                                                                                        |
| W10 | n/a     | n/a     | Agency web: the Drafts and Applications tabs open from the bell (rows 74 and 76 read); shown in two screenshots                                                                         |
| W11 | pass    | pass    | The `bare` push opens the list (rows 72, 93)                                                                                                                                            |

An early iPhone W8 attempt was ambiguous (the tap raced the revoke, row 89 was read at 17:21:40Z, before the re-login). The strict
re-run above is the one to cite.

### 4.1 W8 re-run after the finding-2 fix (Samsung, build of `47d2efc5`)

The same sequence, in order, with the new build. This is the evidence that the fix works on a device.

| Step                           | Evidence                                                                                                                               |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------- |
| Cold launch on the new build   | Row 9 `last_seen_at` moved 16:39:57 → **18:10:43** (the old code would have skipped: `registeredAt` was fresh)                         |
| Push 95 sent, app backgrounded | `push.delivery … outcome: delivered … http 200` to token 9 at 18:12:06; the card was in the shade                                      |
| Revoke                         | PAT 26 deleted, **row 9 deleted** at 18:12 (only the iPhone's row 13 remained)                                                         |
| Tap while signed out, sign in  | Landed on the invitation detail ("test-app-2"). **New row 14 for PAT 33, created 18:14:26**, the same second as the sign-in            |
| Push 96 sent                   | `push.delivery … platform: android … token_id 14 … delivered … 200` at 18:15:50                                                        |
| Tap on push 96                 | Landed on the invitation detail; `read_at` 18:16:19                                                                                    |
| Notifications 94, 95           | Still unread: 94 was sent while the app was in the foreground (no card); 95 was tapped signed-out. That is finding 1, not a regression |

### 4.2 Walk observations

- **Job-detail banner.** "This job is under way" on the job detail is that page's own state: the fixture creator already has an
  assignment on that campaign, so the walk could not show the apply state.
- **Not-found screen, minor UX.** It shows "← Back to invitations" at the top and "Back to notifications" in the alert: two links to two
  different lists. Accepted as is; revisit if the not-found screen ever gets a design pass.
- **Walk-emit caveats.** `job_posted`, `approved`, `connected` and `disconnected` used `notify` with the exact bag the emitter builds,
  not a full fan-out or admin action. W9 used a valid-shaped ULID that matches nothing. The listener and the agency HTTP routes were real.
- **Camera (inventory §9.5).** Android's picker offers no camera entry; iOS "Take Photo" exits the app (**cause undiagnosed, no crash
  log**). Both to tech-debt, unscheduled (P-Q6).

### 4.3 Walk state, for whoever resumes

- **Dev DB cleaned (2026-10-12).** Deleted notifications **55–96** (42 rows, users 14, 5, 7, 1), the walk's messages (assignment
  messages 4–7 including system message 6; relationship messages 6–10), their read receipts, and restored the two threads'
  `last_message_at`. Kept: notifications ≤ 54 (including `creator.approved` 52–54 from the evening before the walk) and relationship
  messages 4–5. The **audit log is append-only and still holds the walk's rows** (28 entries dated 2026-10-12). `user_push_tokens`
  rows 13 (iPhone) and 14 (Samsung) are live registrations and were left.
- **`pedram+2@defraged.com`** (an agency admin on the local dev DB): its password was reset for the walk to
  `Walk-AH103-e5103882`. The old hash was not saved. Local dev only.
- The installed iPhone build only works on LAN IP `192.168.1.136`; the Mac's IP changed during the session.
- Mute and `job_posted` push preferences were reset to defaults; the scratch emit scripts were deleted.

---

## 5. Findings

### Finding 1 — a stale unread row after a signed-out tap (recorded, not fixed)

The tap handler marks a row read only when there is a live session at the tap (and not on `auth.sign-in` or `error.auth-bootstrap`).
After sign-in the redirect carries the user to the target, but the row stays unread, so the bell shows an unread row for a screen
already viewed. Seen in W7/W8 (rows 68–70, 87–89, 91; and 95 in §4.1). **The Q6 semantics stand;** it is a deliberate consequence of
"mark read only after a settled landing with a session". Tech-debt row added.

### Finding 2 — no push re-registration after a server-side revoke and re-login (FIXED here, `47d2efc5`)

- **Root cause (AH-102 code).** A server-side revoke (or lapse) deletes the `user_push_tokens` row, which is tied to the personal
  access token by a foreign key, without the device knowing. On the next login `syncIfGranted` looked at the locally stored
  `registeredAt`, found it fresh (inside the 24 h `UPKEEP_INTERVAL_MS`), and skipped. The signed-in device then had no server row and
  received no pushes. Only a manual sign-out (`logoutCleanup` wipes the local record) recovered it. Reproduced on both platforms.
- **Fix.** `registerAfterSignIn()` in `pushRegistration.ts` POSTs whenever permission is granted and never consults `registeredAt`.
  `startPush` takes a `userId` getter and calls it when the id goes null → id or changes; a sign-out does not trigger it.
  `main.ts` passes the auth store's user id and the old `watch(…push.sync())` block is gone. The server upsert is idempotent.
- **A consequence to know about.** A cold start with a stored login now does **one extra idempotent POST per launch** (the watcher sees
  null → id as the session bootstraps). Confirmed on the device (§4.1, row 9 `last_seen_at`). The 24 h throttle still governs the
  visibility upkeep.
- **Verification.** 13 new Vitest specs (6 for `registerAfterSignIn`, 7 for the `startPush` watcher), the four break-reverts in §3, and
  the W8 re-run on a device (§4.1). The fix stayed inside the bound the user set (one commit, specs, a break-revert, one device
  re-run), so it was not moved to tech-debt.
- **Not yet covered, now a planned check.** It was device-verified on the Samsung only; the iPhone has the same code path. The iPhone is
  to be re-verified on the **first TestFlight build after the deploy**: sign in, then confirm a new `user_push_tokens` row exists for
  that device and a push is delivered. It is listed next to the production-APNs proof in the TestFlight checklist
  ([`push-notifications-review.md`](push-notifications-review.md), named open items, row 4).

---

## 6. Deviations and accepted caveats

- **Stale `?tab=` (no write-back).** `?tab=` opens the named tab on arrival, but the page does not write the tab back to the URL when
  the user switches tabs, so the URL can go stale relative to what is on screen. Accepted; tech-debt row.
- **The P-Q2 degradation.** A signed-out tap on a thread-type push (the thread lookup needs a session) lands on the notifications
  list after sign-in, not on the thread. Accepted; tech-debt row.
- **The Playwright spec had no CI run before the push.** Accepted at Checkpoint A; the run passed (§2).
- **`ro`/`mt` strings.** Two walk-visible titles and the "Nutification settings" lines were fixed. The rest of the corrupted `ro`/`mt`
  strings stay under the unreviewed-MT tech-debt row, with the greppable signature recorded (`Nutif…`, half-translated strings like
  "Setări saved"), so a future locale pass starts with a sweep across all 24 locale folders. `ro/app.json:1217` and `:1222` were left
  alone on purpose.

---

## 7. Not verified

- **Universal links and Android App Links** (AH-103b) and **biometric-lock interplay** (AH-103c): out of scope by ruling.
- **Production APNs.** iOS was verified on a debug install (sandbox APNs). The TestFlight pass (AH-102's open item) covers production.
- **The iPhone after the finding-2 fix.** A **planned check** on the first TestFlight build after the deploy (sign in, confirm a new
  `user_push_tokens` row, confirm a push is delivered), alongside the production-APNs proof; see §5.
- **The first CI run after the `ubuntu-latest` → Ubuntu 26 migration (2026-10-19)** has not happened; this run is still on the old label.
- **Break-revert mutation lists** for C1.2–C4.4 are recorded as counts only in this document.
- **Pest total test count** was not captured for this review; the job's result (success) is.

---

## 8. Push and CI

Pushed once: `origin/main` `cb79abfa..47d2efc5` (20 commits), CI run
[38289855211](https://github.com/pedram-kh/Engine/actions/runs/38289855211) green on all four jobs. Not deployed; deploy timing is
Pedram's call. Deploy entry: `AH-103 … — PENDING` in [`../runbooks/deploy-log.md`](../runbooks/deploy-log.md).

_Provenance: written by Cursor at AH-103 close (2026-10-12) from the commit history, the CI run, the walk records in the chat, and the
dev-DB reads recorded in §4.3._
