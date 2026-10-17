# AH-108 — unread indicators: in-app badges (4a) and the home-screen icon badge (4b) — PLAN (plan-pause)

> **Status:** PLAN ONLY. No code until Pedram clears it. Mode A (full loop): an API field, a changed read-state write path, the push payload and a new native plugin.
> **Inventory:** the AH-106 inventory, item 4 (this chat). **Rulings:** Q5, Q6, Q7, Q8, Q9 (2026-10-17), baked in at §1.
> **Stacks on:** AH-106 and AH-107. One production deploy and one TestFlight build 3 for all three (Q9). **Cut line (Q9): if 4b slips, 4a ships without it.**

---

## 0. The live-data risk line

**PROD-DATA RISK: MEDIUM** (the only chunk of the three above LOW, and the reason is one thing: it adds a **write** to `notifications.read_at` on a path that did not write there before).

- **The new write:** opening a thread now also updates `notifications.read_at` for that recipient's message-type rows on that thread. It is a bounded, idempotent `UPDATE ... WHERE recipient_user_id = ? AND subject_type = ? AND subject_id = ? AND type IN (message types) AND read_at IS NULL`, riding the existing `idx_notifications_subject`. It writes no other table, adds no migration, and runs inside the existing mark-read request. Worst realistic failure: it marks too many rows read (over-reach), which hides a bell count it should show. §5.1 pins that with negatives, and §6 break-reverts the type filter.
- **Backlog, the real production question (§9 Q1):** every message notification that exists today is `read_at IS NULL` unless the user clicked it, because until this chunk opening a thread never touched it. After deploy those rows would count as unread forever, so the Messages badge and the icon badge would show phantom numbers on day one. Reconciling them is a **one-shot production data write**, so it is a question for you, not a decision made in this plan.
- **No schema change, no new route.** The unread-count response gains one field; the push payload gains `aps.badge`.
- **Deploy:** **`php artisan queue:restart` is a named step** (`PushMessageFactory` changes; a worker holding the old class map keeps sending badge-less payloads). Either deploy order degrades gracefully: old backend + new SPA shows no message count (the SPA treats absent as 0); new backend + old SPA ignores the new field.
- **Native:** the plugin ships in TestFlight build 3 only (bump `CURRENT_PROJECT_VERSION` 2 → 3, `project.pbxproj:316,340`). Build 2 testers see the in-app badges (web bundle) but no icon badge from the app; pushes carry `aps.badge`, which iOS applies without the plugin.

## 1. Rulings → where each is baked in

| Ruling                                                                                                                                                                                                                                                                             | Baked in at                                                                                                                                        |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Q5** Single source of truth = `notifications.read_at`. Opening a thread (relationship **and** campaign chat) marks that thread's message notifications read. Bell = all unread; Messages tab = unread message-type notifications; icon badge = all unread. Campaign chats count. | §3.1 (write), §3.2 (counts), §4 (client).                                                                                                          |
| **Q6** No sub-flag. Ship and walk on device. Accept stale-until-open.                                                                                                                                                                                                              | No `Feature::` gate anywhere. §0 posture, §7 device acceptance. A throttled or dropped push leaves the badge stale until the app opens (accepted). |
| **Q7** Small in-repo Capacitor plugin for the badge. iOS set/clear, Android no-op. No `@capawesome`.                                                                                                                                                                               | §3.4, §4.4.                                                                                                                                        |
| **Q8** Messages badge on native tab and web nav; 99+ cap on tab and bell.                                                                                                                                                                                                          | §4.2, §4.3.                                                                                                                                        |
| **Q9** AH-108 = 4a + 4b, Mode A; 4a ships alone if 4b slips.                                                                                                                                                                                                                       | §2 stages and cut line.                                                                                                                            |

## 2. Shape and the cut line

| Stage            | Content                                                                                                                                                | Commit                                                     |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------- |
| **0**            | Preflight reads (§2.1) and the **iOS plugin-registration spike** (§3.4). No commit unless a doc changes.                                               | —                                                          |
| **1**            | **4a backend:** read-marking in both message services; the `message_unread_count` field; Pest, test-first.                                             | C1.1 read-marking, C1.2 count field                        |
| **2**            | **4a SPA:** the shared count owner in the creator layout, the Messages badge (tab + web nav), the 99+ cap on bell and tab, the refresh on thread open. | C2.1 count source, C2.2 badges + cap, C2.3 refresh on open |
| **Checkpoint A** | Stop for clearance. **4a is shippable here.**                                                                                                          | —                                                          |
| **3**            | **4b backend:** `PushMessage.badge`, the factory computes it, the sender emits `aps.badge`.                                                            | C3.1                                                       |
| **4**            | **4b native:** the in-repo iOS plugin, the TS wrapper, set on resume and on count change, clear on sign-out, build number bump.                        | C4.1 plugin, C4.2 wiring                                   |
| **Checkpoint B** | Stop for the device walk plan.                                                                                                                         | —                                                          |
| **5**            | Close: AH-108 entry, deploy-log stack, resumption refresh, gates.                                                                                      | docs                                                       |

**The cut line is real and structural:** Stages 3-4 touch nothing that Stages 1-2 depend on. If the plugin spike fails or the iOS walk finds a problem, Stages 3-4 are held, and 4a goes out with AH-106 and AH-107.

### 2.1 Stage 0 reads (confirm, do not assume)

- Whether `Notification` has model events or observers that an `UPDATE` would fire (the mark-read must stay a plain query-builder update, as `NotificationController::readAll` does at `:121`).
- That `PushDispatcher` → `PushMessageFactory::make` is called **after** the in-app row is persisted (`NotificationService.php:68` creates, `:75` dispatches, so the new row is already counted). When the recipient has muted `in_app`, there is no row (`$notification` is null) but the push still goes, so that message is not in the badge (§9 Q3).
- The agency-side thread-open controllers (`AgencyMessageController:164`, the relationship twin) call the same two service methods (confirmed by grep; Stage 0 re-reads the call sites).
- Whether the SPA already calls the `/read` endpoints on thread open for all four thread pages (creator campaign, creator relationship, agency campaign, agency relationship).

## 3. The contract

### 3.1 Mark-read on thread open (backend)

The four thread-open endpoints already funnel into two methods:

- `MessageService::markThreadReadForUser` (`MessageService.php:138`), campaign chat. Notification subject = the **assignment** (`SendMessageNotifications` passes `subject: $assignment`, data carries `assignment_ulid`).
- `RelationshipMessageService::markThreadReadForUser` (`RelationshipMessageService.php:176`), relationship chat. Notification subject = the **thread** (`subject: $thread`, data carries `thread_ulid`).

**Decision D1 (proposed): put the notification write inside those two methods**, not in the controllers. One edit then covers all four sides (creator and agency, campaign and relationship), and no controller can forget it. This also marks **agency** message notifications read on thread open. Q5 says "opening a thread" with no side restriction, so that is the reading; it is stated here so it is not a surprise.

**Matching rule:** `recipient_user_id = viewer`, `subject_type = <model FQCN>` (stored via `getMorphClass()`, `NotificationService.php:68`; no morph map is registered), `subject_id = <assignment id | thread id>`, `type IN` the message types, `read_at IS NULL`. **Never** by `data->>'thread_ulid'` (unindexed JSON). The type filter is the over-reach guard: an assignment is also the subject of `assignment.invited`, `assignment.draft_approved`, etc., and opening the chat must not mark those read.

**Message types** live in one constant (`NotificationType::messageTypes()`, new), the four cases at `NotificationType.php:86-95`. The unread count (§3.2) and the mark-read both read that constant, so they cannot drift.

### 3.2 The count (backend)

`GET /me/notifications/unread-count` (`NotificationController::unreadCount`, `:66`) gains `message_unread_count` beside `unread_count`. One aggregate query (`count(*)` and `count(*) filter (where type in (...))`), not two. The feed endpoint's `meta.unread_count` (`:58`) is unchanged. A change to a response shape: exact-keyset test edited deliberately (§5.3).

### 3.3 The push badge (backend)

- `PushMessage` (`Notifications/Push/PushMessage.php`) gains `public ?int $badge = null` (vendor-neutral; no `aps` name leaks into the DTO).
- `PushMessageFactory::make` (`:80`) sets it to the recipient's **total unread** (Q5: icon badge = all unread), one `COUNT` per push, on the recipient's existing `recipient_user_id` + `read_at IS NULL` path.
- `FcmPushSender::payload` sets `$aps['badge'] = $message->badge` when non-null (`FcmPushSender.php:135`, next to `['sound' => 'default']`).
- **Android: no `notification_count` is sent** (Q7: Android no-op; launcher badges are OEM-dependent). Adding it later is a one-line change in the same method.
- A `null` badge omits the key (a message built without a recipient count never clears the badge by sending 0).

### 3.4 The iOS plugin (native)

- **Shape:** `CatalystBadge` with `set({ count })`. `count: 0` clears. Implementation: `UIApplication.shared.applicationIconBadgeNumber = count` (the iOS deployment target is 15.0, `project.pbxproj:249`, and `UNUserNotificationCenter.setBadgeCount` is iOS 16+, so the 16+ API is behind `#available`).
- **Permission:** the badge only shows if notification authorization includes `.badge`; the existing push permission request already includes it. A user who declined notifications gets no badge; the plugin does not prompt.
- **Registration is the unknown, hence the Stage 0 spike.** The storyboard uses the stock `CAPBridgeViewController` (`Main.storyboard:14`). A local plugin needs a small subclass that registers it in `capacitorDidLoad()` and the storyboard's `customClass` pointed at it. That is a project-file change that cannot be checked in CI. **Spike:** build to the simulator and call `set` from the web console before any SPA wiring. If it cannot be made to work in a reasonable time, that is the signal to cut 4b.
- **TS wrapper:** `registerPlugin('CatalystBadge')` with a web/Android no-op fallback; callers guard on `Capacitor.getPlatform() === 'ios'` so Android never calls an unimplemented plugin.

## 4. The client behaviour spec

### 4.1 One count owner (the "no module singleton" question)

`useNotificationPoll` is composable-scoped by design and its docblock forbids a module singleton. The bell owns one today; a Messages tab needs the **same** numbers, not a second 45 s poll against the same endpoint.

**Decision D2 (proposed): provide/inject, not a store.** `CreatorDashboardLayout` creates the one poll (with `messageUnreadCount` added to the handle), `provide`s it, and the bell and the nav items `inject` it. The bell falls back to creating its own poll when nothing is provided, so the **agency** layout keeps working unchanged. This honours the docblock (state in a composable scope, no singleton) and adds no Pinia store.

### 4.2 Messages badge, tab and web nav

The creator layout builds one nav model (`CreatorDashboardLayout.vue:123`, `MOBILE_PRIMARY_KEYS` at `:150`) used by both the native bottom bar and the web nav, so one change renders both. A `v-badge` on the Messages item, `model-value = messageUnreadCount > 0`, content from the capper. Accessible name carries the count (a new `creator.*` aria string; see §5.4).

### 4.3 The 99+ cap (Q8)

One pure helper `formatBadgeCount(n) → "1".."99" | "99+"`, used by the bell (`NotificationBell.vue:73`) and the Messages item. The icon badge is **not** capped by us (the OS shows the number it is given).

### 4.4 Icon badge wiring (4b)

A composable in the app shell, active on native iOS only: `setBadge(unreadCount)` on app resume, on every poll update, and `setBadge(0)` on sign-out (before the token is dropped). The source is the **bell total** (Q5), the same number `useNotificationPoll` already holds, so there is no extra request.

### 4.5 Refresh on thread open

After a thread's `/read` call resolves, the page triggers the existing `NOTIFICATIONS_REFRESH_EVENT` (`notificationsRefresh.ts`) so the bell and tab drop immediately instead of waiting up to 45 s. No new endpoint.

## 5. The test spine

### 5.1 Backend (Pest)

- **Marks exactly its thread:** opening campaign thread A marks that recipient's `message.received_by_*` rows with subject = assignment A; **not** another assignment's rows, **not** another recipient's rows, **not** rows already read (their `read_at` unchanged).
- **Over-reach negatives (the important ones):** an `assignment.draft_approved` / `assignment.invited` row on the **same assignment** stays unread; a relationship notification is untouched by a campaign thread open and vice versa.
- Same four-way matrix for the relationship thread and for the agency viewer.
- **Idempotent:** a second open writes nothing (matched row count 0).
- **Count:** `message_unread_count` counts message types only; `unread_count` is unchanged and still counts all; both drop after an open.
- **Push:** `PushMessage.badge` equals the recipient's total unread **including** the just-created row; `aps.badge` present in the FCM payload when set and **absent** when null; the Android block is unchanged (no `notification_count`).
- Existing exact-keyset specs edited deliberately (§5.3).

### 5.2 SPA (Vitest)

- `formatBadgeCount` boundaries: 0 hidden, 1, 99, 100 → "99+".
- The shared poll: bell and Messages item show the same numbers from one poll (one request, not two); the bell falls back standalone on the agency layout.
- Messages item badge on the native bottom bar **and** the web nav; hidden at 0; the accessible name includes the count.
- Refresh on thread open fires the event after `/read` resolves.
- Badge composable: sets on resume and on count change, sets 0 on sign-out, **does nothing off native iOS** (web and Android never call the plugin).

### 5.3 Conscious edits to existing tests (the only ones allowed)

The unread-count exact-keyset spec; the `PushMessage` / FCM payload specs (a new optional field); any bell spec that asserts the badge content as a raw number.

### 5.4 i18n

Expected: **one** new key for the Messages item's accessible name with a count (`creator.ui.messagesUnreadAria`, plural). Every other string reuses what the bell already uses. 24 locales, MT for the flaky 10, parity gate. A new key is an i18n stop-gate, so it is named here and cleared with the plan.

### 5.5 Playwright

Web: a counterparty message appears as a Messages badge on the next poll, and opening the thread clears it. The device legs (icon badge) cannot be automated and are the §7 walk.

## 6. Break-revert list

| Condition                  | Mutation                                                | Must fail                               |
| -------------------------- | ------------------------------------------------------- | --------------------------------------- |
| Type filter on mark-read   | drop the `whereIn('type', ...)`                         | the same-assignment over-reach negative |
| Subject scoping            | drop the `subject_id` predicate                         | the other-thread negative               |
| Recipient scoping          | drop the `recipient_user_id` predicate                  | the other-recipient negative            |
| Idempotence                | write `read_at` on already-read rows                    | the unchanged-`read_at` assertion       |
| Hook in both services      | remove it from the relationship service only            | the relationship matrix                 |
| Count split                | make `message_unread_count` count all types             | the message-only count spec             |
| Badge includes the new row | compute the badge before the row exists (or off by one) | the push badge spec                     |
| Null badge omits the key   | emit `badge: 0` for null                                | the absent-key spec                     |
| 99+ cap                    | cap at 100                                              | the boundary spec                       |
| One poll                   | give the Messages item its own poll                     | the one-request spec                    |
| Native iOS only            | let web call the plugin                                 | the platform-guard spec                 |
| Sign-out clear             | remove the `setBadge(0)`                                | the sign-out spec                       |

Mutations are applied with Python string replacement, and each is verified to have applied.

## 7. Device acceptance (the chunk's acceptance; build 3, real emits)

1. Counterparty sends in a **relationship** thread and a **campaign** thread: the bell and the Messages tab both count; the home-screen icon shows the total.
2. Open one thread: that thread's contribution drops from the tab, the bell and (on the next set) the icon; the other thread's stays.
3. Background the app, receive a push: the icon updates from `aps.badge` with the app closed.
4. Read everything: the icon clears.
5. Sign out: the icon clears; sign in as someone else: no stale number.
6. Notifications permission **declined**: no icon badge and no crash.
7. A burst of 100+ unread: the tab and bell read "99+".

## 8. Docs that move with the build

AH-108 entry in `docs/reviews/adhoc-changes-log.md`; stack on the AH-106 PENDING entry in `docs/runbooks/deploy-log.md` with the `queue:restart` step and the backlog disposition (§9 Q1); Part 2 refresh of `docs/reviews/RESUMPTION-TEMPLATE.md`; tech-debt rows for (a) the in-app-muted push not counted in the badge, (b) no Android launcher badge, (c) stale-until-open (Q6).

## 9. Plan-review questions

1. **The backlog (production data).** Existing message notifications are unread today and nothing will ever mark them read. Choose: **(A, recommended)** a one-shot artisan command with `--dry-run` that marks a message notification read when its thread has no unread messages for that user (the read receipts already say so), with dry-run and real-run counts recorded in the deploy log, per the one-shot template; **(B)** mark every pre-deploy message notification read once (simpler, hides genuinely unread ones); **(C)** do nothing and accept phantom counts until users click through. A is the only option that is correct; it is also a production write, so it needs your explicit yes.
2. **Agency nav.** The creator layout owns the shared poll (D2) and the native and web nav together. The agency web nav has its own Messages entry. Add the badge there too (the backend already covers the agency side), or creator layout only for this chunk?
3. **Push badge when `in_app` is muted.** A user with `in_app` off gets the push but no in-app row, so that message is not in the badge total. Accept (recommended; one tech-debt row), or count from message threads instead of notifications (breaks the single-source-of-truth ruling)?
4. **D1.** The write lives in the two service methods, so it also covers the agency side of opening a thread. Confirm.
5. **D2.** provide/inject from the layout, not a Pinia store. Confirm.
6. **Android.** No `notification_count` in the FCM payload (Q7). Confirm.

## Not verified (carried and new)

- The plugin-registration approach (§3.4) is from Capacitor's documented local-plugin pattern, not yet exercised in this repo; Stage 0 is the spike. It is the single most likely thing to slip 4b.
- The Stage 0 reads (§2.1), including the model-events question, have not been done.
- Any device walk. The icon badge cannot be verified without a TestFlight or Xcode device build.
- Playwright: Docker is down locally, so the web leg's first run would be in CI.
