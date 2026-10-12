# AH-103 — deep links for the creator app — change inventory

Read-only inventory. No code, config, migration or native project file was written or changed. **Plan-pause: no build
plan and no code until this document is reviewed and cleared.**

> **This is a snapshot of the tree at `cb79abfa`, not a live claim.** Line numbers are real at that SHA and will drift.
> Where something could not be measured it says so (§8 collects them) rather than asserting it.

**Base SHA:** `cb79abfa` — `HEAD` and `origin/main` agree. Pushed, **not deployed**, no flag armed
(`docs/runbooks/deploy-log.md`: AH-099 → AH-102b all `PENDING`). CI at this tip: green on all four jobs (§6).
**Working tree:** clean at the start.
**Paths:** backend paths are relative to `apps/api/app/Modules/` unless they start with `config/`, `lang/`, `tests/`
(relative to `apps/api/`); SPA paths are relative to `apps/main/src/` unless they start with `apps/`, `packages/` or
`docs/` (repo-root-relative).

**PROD-DATA RISK: NONE** for this inventory (docs only). The expected risk of the eventual build is stated in §7.

**Two pointers in the brief that were slightly off, corrected so the record is straight.** The "deliberate AH-102
boundary" is `push-notifications-inventory.md` **§4.4** ("Minimal tap → navigation, and the AH-103 boundary", `:441-453`)
and, in the plan, **§5.5** (`push-notifications-plan.md:483-486`), not plan §4.4 (that section is the per-type default
mechanism). The content is as the brief describes it.

---

## 0. What the review should know first

1. **A push tap cannot be resolved from the push payload as it stands.** The payload is `{type, v, notification_ulid?}`
   (`Notifications/Push/PushMessageFactory.php:63-67`). It carries no `assignment_ulid`, no `campaign_ulid`, no
   `thread_ulid`. And there is **no single-notification endpoint**: `/me/notifications` has `index`, `unread-count`,
   `read-all` and `{notification}/read` only (`Notifications/Routes/api.php:36-39`). The AH-102 inventory's "AH-103
   needs **no** backend payload migration" (`push-notifications-inventory.md:451-452`) is true for the four always-on types
   (they need no id) and **not** for the other ten push-eligible types. This is the one decision in the chunk that
   reaches the backend (Q1).
2. **The in-app row already carries what a resolver needs for most types** (`data` bag + the `subject` handle,
   `Notifications/Http/Resources/NotificationResource.php:35-43,71-85`), so the in-app row click is buildable with **zero**
   backend change for 16 of 21 types. The five that cannot resolve cleanly are listed in §2.2.
3. **The relationship-thread gap resolves without a backend change.** `thread_ulid` is the row `id` of both relationship
   inboxes, and each inbox row also carries the counterparty ULID (§2.3). So the route can be found from `thread_ulid`
   alone, for old and new rows alike, at the cost of one extra GET.
4. **Most of the emit-site `data` keys a resolver would depend on are not pinned by any real-emit test.** `assignment_ulid`
   is pinned at three sites; **`thread_ulid` and `campaign_ulid` are not pinned anywhere** — the only test hits for
   `thread_ulid` are hand-built fixtures in `PushDispatcherTest.php:94` and `PushMessageFactoryTest.php:48`. This is
   exactly the AH-102b §9 lesson (a test that fabricates its input proves the fabrication). §2.5.
5. **Nothing on a device has ever shown what `notificationActionPerformed` delivers in `notification.data`.** The app's
   listener ignores the event entirely (`core/platform/push/pushClient.ts:99-106`). The walk proved _where the tap
   lands_, never _what the tap carried_. Everything in this inventory that depends on the tap's data is **unverified on
   hardware** (§3.1, Q8). **Resolved by the §9 addendum (2026-10-12): observed on both an iPhone and an Android phone, in
   foreground, background and killed states.**
6. **Signed-out and expired-session taps already preserve the target** through `requireAuth`'s `?redirect=` and
   `SignInPage`, both pinned by specs. **Two native lapse paths drop it** (§3.2).
7. **Universal/app links: nothing exists, and nothing can be device-verified before deploy and a signing key exist.**
   Recommend splitting them to AH-103b (§4).

---

## 1. What exists now

### 1.1 The AH-102 tap wiring (the boundary)

| Step                                          | Where                                                                                                                                                                                                                                                      |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Plugin listener `notificationActionPerformed` | `core/platform/push/pushClient.ts:99-106` — the event is **discarded**: `(event) => listener(undefined)`. `PushClient.onNotificationTapped` is typed `PushSubscription<void>` (`:43`).                                                                     |
| Registration subscribes it                    | `core/platform/push/pushRegistration.ts:249-253` → `handlers.onTap()` (no argument; `PushHandlers.onTap: () => void`, `:77`). Skipped once `stopped`.                                                                                                      |
| Boot wiring                                   | `main.ts:56-61` — `startPush({ onTap: createPushTapHandler(router), onForeground: () => requestNotificationsRefresh() })`, called after `app.mount` (`:40`).                                                                                               |
| The handler                                   | `core/platform/push/pushTapRouting.ts:24-31` — `router.isReady().then(() => router.push({ name: PUSH_TAP_ROUTE_NAME }))`, `PUSH_TAP_ROUTE_NAME = 'creator.notifications'` (`:22`, "Per-type destinations are AH-103"). Errors swallowed.                   |
| Platform gate                                 | `core/platform/push/startPush.ts:36-43` — a no-op unless `pushChannelAvailable()`; `IOS_PUSH_ENABLED = true` (`pushChannelAvailable.ts:26`), so Android and iOS are both live in code. Web: nothing.                                                       |
| Existing specs that pin this                  | `pushTapRouting.spec.ts` (3 tests: opens the list once ready; does **not** push while the first navigation is in flight; never throws), `pushRegistration.spec.ts:401` (routes a foreground push and a tap to the injected handlers), `startPush.spec.ts`. |

**Cold start (the walk's finding, already fixed).** The launch tap is delivered the moment the listener registers, before
the router's first navigation. `isReady()` is what keeps it from being overtaken (`pushTapRouting.ts:1-11`, tech-debt item
13, `72afb8ae`). That fix is the foundation §3.1 builds on.

**Foreground handling.** `notificationReceived` → `handlers.onForeground()` (`pushRegistration.ts:242-247`) →
`requestNotificationsRefresh()` (`modules/notifications/composables/notificationsRefresh.ts:12`), a `document` event the
bell poll already listens for. No banner: `FirebaseMessaging.presentationOptions: []` (`apps/main/capacitor.config.ts`).
Foreground is **not** part of AH-103 (a foreground push is not a tap); it only matters in that a resolver must not run
on it.

### 1.2 The push payload contract

- **Built by** `PushMessageFactory::make()` (`Notifications/Push/PushMessageFactory.php:48-75`): `['type' => <value>, 'v' =>
PAYLOAD_VERSION]` (`:63`, `PAYLOAD_VERSION = 1`, `:43`), plus `notification_ulid` **only when the in-app row was
  written** (`:65-67`). The in-app row is absent when the recipient muted in-app for that type but kept push
  (`NotificationService.php:56-75`; AH-102 Q2 made push independent of in-app on purpose). So **a push with no
  `notification_ulid` is a supported state, not an edge.**
- **The `data` bag is never forwarded** (`PushMessageFactory.php:19-21`, enforced by sentinel tests, AH-102 review §2 row
  Q6/Q7). The allowlist is names only (`NAME_KEYS`, `:38`).
- **On the wire every value is a string**: `FcmPushSender.php:157` (`array_map(static fn ($v): string => (string) $v, ...)`).
  So `v` arrives as `"1"`, not `1`. The app must parse defensively.
- **Eligibility:** 14 types push — `PushEligibility::TOGGLEABLE` (10) and `ALWAYS_ON` (4), `Push/PushEligibility.php:34-60`.
  The other 7 live types (five agency-only types, `assignment.live_verified`, `campaign_application.rejected`) never push.
- **Collapse/throttle keys** for the two message types read `assignment_ulid` / `thread_ulid` out of the data bag
  (`PushMessageFactory.php:86-98`) — the only place the push side already consumes those ids, server-side.

### 1.3 `NotificationCenter.vue` — rows do not navigate

- The header comment: "**D-7 deferred**: row click marks read IN PLACE — no navigation. `subject` is on the wire but
  intentionally unused this chunk." (`modules/notifications/components/NotificationCenter.vue:19-20`).
- `onRowClick` (`:138-152`): read row → returns immediately (no PATCH, no navigation); unread row → optimistic
  `read_at`, `props.poll?.applyMarkRead()` (only the dropdown passes a `poll`), `notificationsApi.markRead(row.id)`, and a
  rollback of the local flip if the PATCH throws. The `<v-list-item>` has `@click="onRowClick(row)"` (`:240`) and **no
  `:to`**, so there is no link semantics either.
- **One component, three mounts** (`:2-12`): the agency dropdown (`NotificationBell`), the agency page
  (`/notifications`) and the creator page (`/creator/notifications`), web and native. So "build row navigation" means
  building it for **agency users on the web too**, which is why §1.4 and §2 map all 21 types, not only the 16 a creator can
  receive.
- The bell dropdown **already closes on any route change** (`NotificationBell.vue:40-55`, AH-101 U1, and its spec
  `NotificationBell.spec.ts:122-158`; the component's own comment already anticipates "a row that deep-links"). A
  navigating row needs no new close logic.
- Pins that a navigating row will change **consciously** (not silently): `NotificationCenter.spec.ts:152` (unread row marks
  read) and **`:171` "clicking an already-read row does nothing (no PATCH)"** — a read row would still navigate, so that
  test's name and (at most) one assertion change; the no-PATCH assertions stand.
- The row shape the resolver would read: `NotificationResource` →
  `attributes.{notification_type, data, read_at, created_at, actor, subject}` where `subject = {type: class_basename, ulid}`
  or `null` (`NotificationResource.php:35-43,71-85`). The list eager-loads `['actor','subject']`
  (`Notifications/Http/Controllers/NotificationController.php:45`).

### 1.4 The route map, per live type

Registry: 21 types in `LIVE_TYPES` (`modules/notifications/templates.ts:151-322`). **16 reach a creator** (15
creator-recipient + `agency_creator_relation.disconnected`, recipient `both`); **5 are agency-only**. `Push?` is
`PushEligibility`. Param source today: **D** = key in the row's `data` bag; **S** = the row's `subject.ulid`.

**Creator-reaching (16) — routes in `modules/creators/routes.ts`, all `requireAuth` only:**

| #   | Type                                                 | Push | Natural destination (route name · params)                                                    | Param source today                                    |
| --- | ---------------------------------------------------- | ---- | -------------------------------------------------------------------------------------------- | ----------------------------------------------------- |
| 1   | `assignment.invited`                                 | yes  | `creator.assignment.detail` (`:66-67`) · `{ulid: assignment_ulid}`                           | D `assignment_ulid` (== S)                            |
| 2   | `assignment.revision_requested`                      | yes  | `creator.assignment.detail` · `{ulid: assignment_ulid}`                                      | D `assignment_ulid` (== S)                            |
| 3   | `assignment.draft_approved`                          | yes  | `creator.assignment.detail` · `{ulid: assignment_ulid}`                                      | D `assignment_ulid` (== S)                            |
| 4   | `assignment.draft_rejected`                          | yes  | `creator.assignment.detail` · `{ulid: assignment_ulid}`                                      | D `assignment_ulid` (== S)                            |
| 5   | `assignment.completed_on_approval`                   | yes  | `creator.assignment.detail` · `{ulid: assignment_ulid}`                                      | D `assignment_ulid` (== S)                            |
| 6   | `assignment.manually_verified`                       | yes  | `creator.assignment.detail` · `{ulid: assignment_ulid}`                                      | D `assignment_ulid` (== S)                            |
| 7   | `assignment.live_verified`                           | no   | `creator.assignment.detail` · `{ulid: assignment_ulid}`                                      | D `assignment_ulid` (== S)                            |
| 8   | `message.received_by_creator`                        | yes  | `creator.assignment.detail` · `{ulid: assignment_ulid}` (the page embeds the chat)           | D `assignment_ulid` (== S)                            |
| 9   | `campaign_application.accepted`                      | yes  | `creator.assignment.detail` · `{ulid: assignment_ulid}` (the invitation it created)          | D `assignment_ulid`                                   |
| 10  | `message.relationship_received_by_creator`           | yes  | `creator.messages.thread` (`:151`) · `{agencyUlid}` — **found by lookup** (§2.3)             | D `thread_ulid` → inbox → `agency.id`                 |
| 11  | `campaign.job_posted`                                | yes  | `creator.job.detail` (`:94-95`) · `{ulid: campaign ulid}`                                    | **S only** (`data` has no campaign ulid)              |
| 12  | `campaign_application.rejected`                      | no   | `creator.jobs` (`:82-83`; the board shows the application status chip)                       | none needed; S is the _application_, not the campaign |
| 13  | `creator.approved`                                   | yes  | `creator.dashboard` (`:20-21`) · none                                                        | none needed                                           |
| 14  | `creator.rejected`                                   | yes  | `creator.dashboard` · none (the dashboard renders the rejection reason)                      | none needed                                           |
| 15  | `agency_creator_relation.admin_connected`            | yes  | `creator.messages` (`:136-137`, the inbox) · none                                            | none needed; S is the relation (no agency ulid)       |
| 16  | `agency_creator_relation.disconnected` (creator leg) | yes  | **no destination** → fallback (the list). There is nothing to open on an ended relationship. | none                                                  |

**Agency-only (5) — routes in `modules/auth/routes.ts`, `requireAuth` + `requireAgencyUser`:**

| #   | Type                                      | Natural destination (route name · params)                                     | Param source today                                                     |
| --- | ----------------------------------------- | ----------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| 17  | `assignment.draft_submitted`              | `campaigns.detail` (`:323`) · `{ulid: campaign_ulid}`, ideally the Drafts tab | D `campaign_ulid`                                                      |
| 18  | `assignment.contracted`                   | `campaigns.detail` · `{ulid: campaign_ulid}`, ideally the Creators tab        | D `campaign_ulid`                                                      |
| 19  | `message.received_by_agency`              | `campaigns.detail` · `{ulid: campaign_ulid}`, Messages tab                    | **gap**: D has `assignment_ulid`, no `campaign_ulid`                   |
| 20  | `message.relationship_received_by_agency` | `messages.thread` (`:251`) · `{creatorUlid}` — **found by lookup** (§2.3)     | D `thread_ulid` → agency inbox → `creator.id`                          |
| 21  | `campaign_application.submitted`          | `campaigns.detail` · `{ulid: campaign_ulid}`, Applications tab                | **gap**: D has `creator_name`, `campaign_name`; S is the _application_ |

(`agency_creator_relation.disconnected` also reaches agency members; its agency leg has the same "nothing to open" answer
as #16.)

**Facts about the destinations that matter to a resolver:**

- `creator.assignment.detail` has its own 404 state (`CreatorAssignmentDetailPage.vue:257,1079-1086`: a "not found" alert,
  distinct from a generic failure with a retry). `creator.job.detail` has one too (`CreatorJobDetailPage.vue:107-112`, an
  info alert, plus a "back to jobs" link at `:178`). The assignment page's 404 state has **no link out**.
- The campaign chat is **inside** `creator.assignment.detail` (`<ChatPanel>` at `CreatorAssignmentDetailPage.vue:622`).
  There is no anchor/hash support today, so #8 lands at the top of a long page.
- `campaigns.detail` picks its tab from a local `ref('overview')` (`modules/campaigns/pages/CampaignDetailPage.vue:237`);
  **no query or hash is read**. Landing on Drafts/Creators/Applications/Messages (#17–19, #21) needs a small SPA addition
  (Q3). Without it all four land on Overview.
- The two thread routes are keyed by the **counterparty** ULID (`creator.messages.thread` `:agencyUlid`; `messages.thread`
  `:creatorUlid`), not by the thread ULID the notification carries.
- `creator.jobs` / `creator.job.detail` are guarded `requireAuth` only; the approved-creator gate is server-side
  (`routes.ts:73-81`), so a tap by a not-yet-approved creator renders an empty board, not an error.

---

## 2. The resolver

### 2.1 What it would be

One registry, one resolver `(type, params) → resolution`, used by both the push tap and the in-app row click. The
precedent already in the tree is `LIVE_TYPES` (`templates.ts:151`): one entry per type carrying `templateKey`,
`recipient`, `preference` ("ONE source of truth … so they cannot drift", `:15-24`). A destination belongs in that same
entry, for the same reason. A type is "resolved" when it yields a route; otherwise the resolution is the **fallback**.

Resolution outcomes the two callers must agree on:

| Outcome            | Push tap                                                                                                                                 | In-app row click                                                                                                                                |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------- |
| A route            | `router.push(route)`                                                                                                                     | mark read + `router.push(route)`                                                                                                                |
| Fallback (no dest) | `router.push({name: 'creator.notifications'})` (today's behaviour — never a dead tap)                                                    | mark read, **stay** (the user is already in the list; navigating to the list from the list is a no-op, and the dropdown has its own "view all") |
| Unknown type       | same as fallback (AH-102 already requires this: `push-notifications-review.md:235,405`: an unknown type is "open the app", not an error) | same as fallback (the row already renders the generic template, `templates.ts:329-332`)                                                         |

Inputs differ by caller, so the registry should take a **normalised** `{type, ids}` rather than a row: the row click builds
`ids` from `data` + `subject`; the push tap builds it from the push payload. That keeps the resolver ignorant of which
surface called it. (Shape only — no design work here.)

### 2.2 Payload gaps, per type: what the emit site puts in `data` vs what the destination needs

Emit sites cite the `notify()` call (`NotificationService.php:56`). "Pinned?" = a real-emit test asserts the key (not a
hand-built fixture).

| #   | Type                                                     | Emit site (`Modules/…`)                                                                | `data` keys today                                                                      | Route needs           | Verdict                                                                                                                                                                                     | Pinned?                                                          |
| --- | -------------------------------------------------------- | -------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- | --------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------- |
| 1   | `assignment.invited`                                     | `Campaigns/Listeners/SendAssignmentNotifications.php:347-357`                          | `campaign_name`, `creator_name`, `assignment_ulid`                                     | `assignment_ulid`     | **OK** (in-app). Push: not in payload (Q1)                                                                                                                                                  | yes — `AssignmentInviteConvergenceTest.php:208,246`              |
| 2–4 | `revision_requested`, `draft_approved`, `draft_rejected` | same file `:494-509` (one `notify`, type chosen at `:515-521`)                         | `campaign_name`, `creator_name`, `outcome`, `feedback`, `assignment_ulid`, `version` † | `assignment_ulid`     | **OK** (in-app). Push: Q1                                                                                                                                                                   | yes — `NotificationProofConsumerTest.php:100,173`                |
| 5   | `assignment.completed_on_approval`                       | same `:161-171`                                                                        | `campaign_name`, `creator_name`, `assignment_ulid`, `version` †                        | `assignment_ulid`     | **OK**. Push: Q1                                                                                                                                                                            | not by key (review test covers the sibling rows)                 |
| 6   | `assignment.manually_verified`                           | same `:235-245`                                                                        | `campaign_name`, `creator_name`, `assignment_ulid`                                     | `assignment_ulid`     | **OK**                                                                                                                                                                                      | no                                                               |
| 7   | `assignment.live_verified`                               | same `:284-294`                                                                        | `campaign_name`, `creator_name`, `assignment_ulid`                                     | `assignment_ulid`     | **OK** (never pushes)                                                                                                                                                                       | no                                                               |
| 8   | `message.received_by_creator`                            | `Messaging/Services/SendMessageNotifications.php:57-65,80-86`                          | `campaign_name`, `sender_name`, `assignment_ulid`                                      | `assignment_ulid`     | **OK**. Push: Q1                                                                                                                                                                            | no (`MessageNotificationTest.php:91-92` pins the two names only) |
| 9   | `campaign_application.accepted`                          | `Campaigns/Services/CampaignApplicationNotifier.php:168-181`                           | `agency_name`, `campaign_name`, `assignment_ulid`                                      | `assignment_ulid`     | **OK**. Push: Q1                                                                                                                                                                            | yes — `CampaignApplicationAcceptTest.php:212`                    |
| 10  | `message.relationship_received_by_creator`               | `Messaging/Services/RelationshipMessageNotifications.php:56-61,88-94`                  | `agency_name`, `creator_name`, `sender_name`, `thread_ulid`                            | `agencyUlid`          | **Resolvable from `thread_ulid` alone** by lookup (§2.3); no emit change required                                                                                                           | **no** — `thread_ulid` is asserted by no real-emit test          |
| 11  | `campaign.job_posted`                                    | `Campaigns/Services/JobPostedFanOutService.php:251-262`                                | `campaign_name`, `agency_name`                                                         | campaign ulid         | **In-app OK via `subject.ulid`**. **Push: no source** — the dispatcher is handed `data`, never the subject (`NotificationService.php:75`). Needs an additive `campaign_ulid` in `data` (Q1) | no                                                               |
| 12  | `campaign_application.rejected`                          | `CampaignApplicationNotifier.php:227-234`                                              | `campaign_name`, `cause`                                                               | none (`creator.jobs`) | **OK** (list destination needs no id). Never pushes                                                                                                                                         | n/a                                                              |
| 13  | `creator.approved`                                       | `Creators/Http/Controllers/Admin/AdminCreatorController.php:299-304,503-517`           | `welcome_message` (opt.)                                                               | none                  | **OK**, push included                                                                                                                                                                       | n/a                                                              |
| 14  | `creator.rejected`                                       | same `:371-376`                                                                        | `rejection_reason`                                                                     | none                  | **OK**, push included                                                                                                                                                                       | n/a                                                              |
| 15  | `agency_creator_relation.admin_connected`                | `Creators/Services/CreatorAgencyDirectConnectService.php:211-217`                      | `agency_name`                                                                          | none (inbox)          | **OK** for `creator.messages`. An exact thread would need an additive `agency_ulid` (Q4)                                                                                                    | n/a                                                              |
| 16  | `agency_creator_relation.disconnected`                   | `Creators/Http/Controllers/Admin/AdminCreatorConnectionController.php:274-278,306-310` | `counterparty_name`                                                                    | none (fallback)       | **OK** — fallback by design                                                                                                                                                                 | n/a                                                              |
| 17  | `assignment.draft_submitted`                             | `SendAssignmentNotifications.php:422-432` via `notifyAgencyMembers` (`:577-603`)       | `creator_name`, `campaign_name`, `campaign_ulid`, `version` †                          | `campaign_ulid`       | **OK** (in-app, web)                                                                                                                                                                        | no                                                               |
| 18  | `assignment.contracted`                                  | same `:559-570`                                                                        | `creator_name`, `campaign_name`, `campaign_ulid`                                       | `campaign_ulid`       | **OK**                                                                                                                                                                                      | no                                                               |
| 19  | `message.received_by_agency`                             | `SendMessageNotifications.php:57-65,108-114`                                           | `campaign_name`, `sender_name`, `assignment_ulid`                                      | `campaign_ulid`       | **GAP**: no campaign ulid; the agency has no assignment-keyed route. `subject` is the assignment, no help. Additive `campaign_ulid` needed (Q2)                                             | no                                                               |
| 20  | `message.relationship_received_by_agency`                | `RelationshipMessageNotifications.php:56-61,73-79`                                     | same bag as #10                                                                        | `creatorUlid`         | **Resolvable from `thread_ulid`** by agency-inbox lookup (§2.3)                                                                                                                             | no                                                               |
| 21  | `campaign_application.submitted`                         | `CampaignApplicationNotifier.php:112-120`                                              | `creator_name`, `campaign_name`                                                        | `campaign_ulid`       | **GAP**: no campaign ulid; `subject` is the application. Additive `campaign_ulid` needed (Q2)                                                                                               | no                                                               |

† `version` is added by `withRound()` only when the machine supplied a round (`SendAssignmentNotifications.php:166,202-208,428,499`); it is
irrelevant to routing. (Note the push test fixtures call this key `round` — `PushMessageFactoryTest.php:42-45` — which the emitter never
writes. A small, harmless instance of §2.5's point: the fixture was typed by hand and drifted.)

**Age of the keys (old rows must still resolve).** `git blame` gives the date of the commit that _last touched_ each key's line, which is
an upper bound on when the key entered the emit site, not an exact introduction date: `assignment_ulid` — review rows `2026-06-06`
(`24aa4de0`), campaign messages `2026-06-06` (`e791653d`), `completed_on_approval` `2026-08-16` (`451388f1`, AH-069), `invited`
`2026-08-19` (`7b86bf90`, AH-083), application-accepted `2026-07-28` (`78c2dd83`, AH-058); `thread_ulid` `2026-06-29` (`2656e5a3`,
AH-010a); `campaign_ulid` on the two agency rows `2026-06-06` (`98297b04`). Two lines read differently: `manually_verified`'s
`assignment_ulid` shows last-touch `2026-10-10` (`34d90a4f`, the notification-matrix commit; the surrounding block carries an
older "S11.0 Chunk 2" comment, so the key is older than that date), and the `live_verified` line shows `2026-06-06` while the enum case
itself arrived in `34d90a4f` (`2026-10-10`) — I did not reconcile the two. So every row a production database can hold for a
creator-reaching type appears to carry the key its destination needs, and "old notifications resolve or fall back" looks met
without a backfill. **I read no production row**: this is the emit-site history, not the contents of the table.

### 2.3 The relationship thread — can `thread_ulid` alone resolve the route? **Yes.**

- The creator route is keyed by the agency ULID (`creator.messages.thread`, `:agencyUlid`; the transport URL is
  `/creators/me/relationship-threads/{agency}/messages`, `messaging/api/relationshipMessaging.api.ts:79-83`). The
  emitter's own comment says the thread ULID "the creator-side route does not accept"
  (`RelationshipMessageNotifications.php:36-39`).
- But **`GET /creators/me/relationship-threads` returns, per thread, `id = thread->ulid` and `attributes.agency.id =
agency->ulid`** (`Messaging/Http/Controllers/CreatorRelationshipMessageController.php:76-87`; type
  `packages/api-client/src/types/messaging.ts:251-265`). The agency inbox mirrors it: `id = thread->ulid`,
  `attributes.creator.id = creator->ulid` (`AgencyRelationshipMessageController.php:73-77`; type `messaging.ts:239-248`).
- The inbox lists **every thread that has at least one message**, regardless of relationship state (`->has('messages')`,
  `CreatorRelationshipMessageController.php:65-69`, with no connection filter). A notification only exists because a
  message was just sent, so its thread is in the inbox; an ended relationship's thread "stays readable"
  (`:30-37`, D6).
- **So:** the resolver for #10 and #20 is `lookup(thread_ulid) → counterparty ulid`, an async step with one GET. If the
  thread is not in the response (it should always be; defence against a race or a removed thread) → fallback.
- **Why no additive `agency_ulid` is needed or recommended:** old rows (all of them) lack it, so the lookup path must exist
  regardless; a second, synchronous path for new rows would be two code paths for one outcome, and new rows would then
  resolve differently from old ones. The cost of the lookup is one request that the thread page itself repeats on mount
  (`CreatorRelationshipThreadPage.vue:56-77` calls `creatorInbox()` for the header). A reviewer who prefers a synchronous
  route can have `agency_ulid` as an additive key with the lookup kept as the fallback; that is Q4's call, not a
  requirement.

### 2.4 The push-payload decision (Q1) — three ways to give a tap its ids

The push tap has `{type, notification_ulid?, v}` and needs, for ten types, an id.

| Option | What                                                                                                                                                                                                                                                              | Backend                                                              | Covers muted-in-app?                                         | Tap latency                                                           | Notes                                                                                                                                                                                                                                                                                                                                                                                                        |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------- | ------------------------------------------------------------ | --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **A**  | New `GET /me/notifications/{ulid}`; tap fetches the row, then runs the _same_ resolver as a row click                                                                                                                                                             | new route (route table, `docs/security/tenancy.md` §4 allowlist row) | **No** — no `notification_ulid` when in-app is muted         | one GET before navigating                                             | Cleanest reuse; a new endpoint                                                                                                                                                                                                                                                                                                                                                                               |
| **C**  | No backend change; tap fetches page 1 of `/me/notifications` and finds the row by ulid                                                                                                                                                                            | none                                                                 | **No** (same reason); also fails if the row is past the page | one GET                                                               | Consistent with the AH-102 "no payload migration" expectation; weakest coverage                                                                                                                                                                                                                                                                                                                              |
| **B**  | Additive, **ULID-only** route keys in the push `data` (`assignment_ulid`, `campaign_ulid`, `thread_ulid`) on a **second allowlist** in `PushMessageFactory`, beside the names allowlist; plus `campaign_ulid` added to the `job_posted` data bag at its emit site | `PushMessageFactory` + one emit-site key                             | **Yes**                                                      | none (navigates immediately; the destination page loads its own data) | Opaque identifiers only — `notification_ulid` already travels the same way. Needs its own sentinel tests so the "never forward the bag" rule (`PushMessageFactory.php:19-21`) stays structural. `v` stays 1: the AH-102 inventory already records that "unknown fields are ignored by old app builds" (`push-notifications-inventory.md:452`), and nothing is deployed, so no old build exists in the field. |

**Recommendation: B.** Muted-in-app-with-push-on is a state AH-102 deliberately supports (§1.2), so A and C leave a
designed configuration without a deep link, and both put a network round trip between the tap and the screen. B is
backend-only-additive behind a flag that is OFF and a build that is not deployed (live-data risk §7). It does reverse the
"no backend payload change" expectation recorded at `push-notifications-inventory.md:451-452`, which is why it is a question
(Q1), not an assumption.

### 2.5 Where the emit-site keys are (not) pinned — and the guard this implies

Real-emit assertions exist for `assignment_ulid` at `AssignmentInviteConvergenceTest.php:208,246`,
`NotificationProofConsumerTest.php:100,173` and `CampaignApplicationAcceptTest.php:212`. There are **none** for
`thread_ulid`, `campaign_ulid` (as a notification key), or `assignment_ulid` on the message and `manually_verified` rows.
The push-side tests build their `data` by hand (`PushMessageFactoryTest.php:41-48`). Applying the AH-102b §9 rule —
_an error-state test uses the exact error the transport produces_ — to this chunk: the resolver's contract tests must feed
it the **actual `data` the emit site produces**, not a literal the test author typed.

### 2.6 Mark-as-read on tap

**Today.**

- _List row:_ an unread row is marked read on click, optimistically, with a rollback on PATCH failure, and the bell badge is
  reconciled only when a `poll` handle was passed (dropdown) — the page relies on the bell's 45 s poll
  (`NotificationCenter.vue:11-16,138-152`). A read row does nothing.
- _Push tap:_ marks **nothing**. The tap lands on the list with the row still unread.
- _Endpoint:_ `PATCH /me/notifications/{notification}/read`, idempotent (`Notification::markRead()` is a no-op when already
  read; the controller 404s for another user's or an unknown ulid, `NotificationController.php:84-97`).

**Proposed behaviour (for review, not built).**

- _Row click:_ navigate **and** mark read; navigation must not wait on the PATCH and a failed PATCH must not block it (the
  existing rollback of the local flip stays). A read row navigates without a PATCH.
- _Push tap:_ if `notification_ulid` is present and a session exists → fire-and-forget `markRead(ulid)` plus
  `requestNotificationsRefresh()` so the bell count is right. No ulid (muted in-app) → nothing to mark. A 404 (row pruned)
  is swallowed. **Signed-out tap:** nothing is marked (no session to PATCH with); the row stays unread and the user sees
  it in the list. That loss is small and I recommend accepting it rather than persisting an intent across sign-in.
- Mark-read must run **after** the navigation guard lets the user through, not before, so a tap that ends at sign-in does
  not fire a PATCH that will 401 and trip the native 401 policy (`core/api/index.ts:191-209`).

### 2.7 Parity guard proposal — so the resolver cannot drift from `LIVE_TYPES`

Precedent in the tree: `templates.spec.ts:55-130` reads `Enums/NotificationType.php` from source and asserts every backend
type is live or on an explicit deferred allowlist; `templates.spec.ts` also reads `PushEligibility.php` (AH-102 §15).

1. **Put the destination in `LIVE_TYPES` and make it a required field of `LiveNotificationType`** (`templates.ts:78`).
   `LIVE_TYPES` is a `Partial<Record<NotificationType, …>>`, so a type-level `Record` exhaustiveness is not available; but a
   _required property on the entry_ means `vue-tsc` fails any new live type that omits its destination — the same
   one-source-of-truth property `recipient` and `preference` already have. A separate resolver map would be a second list
   to drift.
2. **Runtime spec over `liveNotificationTypes()`:** every live type has a destination (a route, or the explicit fallback);
   an unknown type resolves to the fallback and never throws; the fallback route name exists in the router.
3. **Route-table spec:** every route name a destination references exists in the real route table, every required `:param`
   is supplied by the declared `ids`, and the route's shell matches the type's `recipient` (a creator type never points at a
   `requireAgencyUser` route and vice versa — `agency-routes-agency-user-guard.spec.ts` already pins the agency set).
4. **Emit-site contract (backend, test-only):** for each type, drive the real emitter and assert the `data` bag contains
   the keys the SPA registry declares it needs (§2.5). Shared source of truth: one fixture of `{type → required keys}` read
   by both the Pest test and the Vitest spec, so a key renamed on either side fails a build. This is the half the current
   tests lack.
5. **Push side:** the same `{type → required keys}` fixture drives a `PushMessageFactoryTest` case — every push-eligible type
   with a route key yields that key in the payload (if Q1 = B), and **no** key outside the two allowlists ever appears
   (sentinels, as AH-102).
6. **Break-revert (§5.35):** delete one live type's destination → the registry spec fails; rename a route → the route-table
   spec fails; drop `thread_ulid` from an emitter → the contract test fails; add a free-text key to the push allowlist →
   the sentinel test fails. Record the failing counts as AH-102/102b did.

### 2.8 Touch points the resolver crosses (named so none is a surprise at plan time)

- **The tap seam is `void`-typed end to end** (`pushClient.ts:43,99-106` → `pushRegistration.ts:77,249-253` →
  `pushTapRouting.ts:24`). The tap's payload has to travel through all three. Their specs change consciously:
  `pushTapRouting.spec.ts`, `pushRegistration.spec.ts:401`, `startPush.spec.ts`.
- **`notification.data` is typed `unknown`** (`@capacitor-firebase/messaging` `definitions.d.ts`, `Notification.data`). The
  parser must treat it as hostile: a string-valued map, `v` as `"1"`, a missing or non-string id → fallback, never a throw.
- **`NotificationCenter.spec.ts:152,171` and `NotificationCenter.native.spec.ts`** change as in §1.3.
- **Agency row clicks** need the agency shell's inbox lookup for #20 (`relationshipMessagingApi.agencyInbox(agencyId)`,
  `relationshipMessaging.api.ts:85`) and the current agency id from `useAgencyStore`.

---

## 3. The hard paths

### 3.1 Cold start (tap from killed)

- **Where it is handled today:** `pushTapRouting.ts:24-31`. `router.isReady()` resolves after the first navigation
  _including its guard redirects_ (a creator hitting `/` is bounced `app.dashboard` → `requireAgencyUser` →
  `onboarding.welcome-back` → `requireOnboardingAccess` → `creator.dashboard`; `core/router/guards.ts`, `modules/auth/routes.ts:206`).
  The tap's `push` runs after that settles. Proven on a real Android kill by the walk (`push-notifications-device-walk.md:74,128`: killed,
  swiped in the app switcher, lands on Notifications after the fix).
- **What the resolver changes:** only the argument to `router.push`. The `isReady()` gate stays as is and is the reason a
  cold-start deep link works at all. The new risk is not routing, it is **the data**: whether the launch tap's
  `notification.data` is populated in the killed state on Android (Intent extras) and on iOS (`userInfo`). The plugin's
  type allows it; **nobody has looked** (§0.5). The Android cold-start path is also where the walk found the last real bug.
- **A destination that needs an async step (#10, #20) on cold start** runs its inbox lookup _after_ `isReady()` — i.e. after
  `requireAuth`'s bootstrap — so the session exists when the lookup fires. If the lookup fails (offline, 401) → fallback to
  the list, never a blank screen.
- **Offline cold start with a stored token:** `requireAuth` → bootstrap error → `error.auth-bootstrap?attempted=<fullPath>`
  (`guards.ts:62-66`), and `AuthBootstrapErrorPage.vue:36-37` pushes `attempted` on retry — so a tap's target also survives
  an offline cold start, provided the tap's own `push` is what produced the `attempted` value. (Code-read; not exercised.)
- **Biometric interplay** is out of scope here (§5). Noted so it is not forgotten: `tokenStore.ts:7-8` says the AH-103
  biometric gate wraps `read()`; a cold-start tap would then wait on a prompt inside `bootstrap()`, which `isReady()`
  already waits on.

### 3.2 Signed-out or expired-session tap

**The brief's premise holds, with two exceptions.** Evidence by path:

| Path                                                        | What happens                                                                                                                                                                                                                                                                                                     | Target preserved?                                        | Evidence                                                                                                                                                  |
| ----------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Signed out** (no token), tap → `router.push(target)`      | `requireAuth` → `user === null` → `{name:'auth.sign-in', query:{redirect: to.fullPath}}` (`guards.ts:76-80`) → `SignInPage.postLoginTarget` honours `?redirect` unless it is `/` (`SignInPage.vue:186-190`)                                                                                                      | **Yes**                                                  | `tests/unit/core/router/index.spec.ts:85-93` (guard emits `redirect`); `SignInPage.spec.ts:205,220` (honours it, "session-expired flow, both user_types") |
| **Cold start, token dead server-side**                      | First nav: `/me` 401 is exempt from the global policy and the store clears the token (`guards.ts`, `core/api/index.ts:40-125`) → initial nav goes to sign-in with the _initial_ path; then the tap's `push(target)` runs after `isReady()`, `requireAuth` sees `user === null` again → `sign-in?redirect=target` | **Yes** (the second navigation overwrites the first)     | code-read; the ordering rests on `isReady()` — **not exercised**                                                                                          |
| **Warm tap, token revoked server-side while the app slept** | `push(target)` passes `requireAuth` (the store still holds a user), the destination page's first API call 401s → `createNativeUnauthorizedPolicy` pushes `{name:'auth.sign-in', query:{reason:'session_expired'}}` — **no `redirect`** (`core/api/index.ts:204-207`)                                             | **No** — lands on the dashboard after sign-in            | code-read                                                                                                                                                 |
| **Resume with a lapsed session** (`appStateChange`)         | `refreshSessionState` → `onSessionLapsed()` → `main.ts:44-48` pushes sign-in with `reason` only                                                                                                                                                                                                                  | **No** (and unrelated to a tap, but it is the same hole) | `core/platform/appResume.ts:51-71`                                                                                                                        |
| **Offline cold start**                                      | see §3.1                                                                                                                                                                                                                                                                                                         | **Yes** via `attempted`                                  | code-read                                                                                                                                                 |
| **Different account signed in**                             | the destination 404s for a row that is not theirs (fail-closed server-side) → the page's own not-found state                                                                                                                                                                                                     | n/a — fails closed, no leak                              | `CreatorAssignmentDetailPage.vue:257`                                                                                                                     |

**Scope needed:** the two "No" rows share one cause — the native lapse redirects do not carry the current route. Adding
`redirect: <current fullPath>` to the two pushes (`core/api/index.ts:204-207`, `main.ts:45-47`) is small and SPA-only, and the
sign-in page already knows how to use it. It edits the policy specs consciously. The **web** policy (`index.ts:103`) is not
involved. I recommend including it (Q5). It is not required for the signed-out and cold-start cases, which already work.

`redirect` is passed to `router.push(string)`, which resolves a path inside the app; it cannot navigate off-origin. (A
value that matches no route renders vue-router's no-match; that is pre-existing and not made worse by this chunk.)

### 3.3 A tap on a notification whose subject is gone

Two layers, and they should both exist:

1. **At resolve time, for in-app rows only.** The list eager-loads `subject` through the default Eloquent scopes
   (`NotificationController.php:45`); `Campaign`, `CampaignAssignment` and `Creator` are `SoftDeletes`. A soft-deleted or
   removed subject makes `subjectPayload()` return `{type, ulid: null}` while `subject_type` is set
   (`NotificationResource.php:71-85`). So a row whose subject is gone is **detectable client-side** as
   `subject !== null && subject.ulid === null`. That can short-circuit to the fallback before navigating. _Read from the
   code; I found no test of this resource branch and did not run it_ — treat it as a mechanism to verify, not a guarantee.
   A push tap has no row, so this layer does not exist for it.
2. **At the destination, for everything.** The destination's own 404 handling is the backstop and already exists for the two
   routes a creator is most likely to hit: assignment detail (not-found alert, **no link out**,
   `CreatorAssignmentDetailPage.vue:1079-1086`) and job detail (info alert + "back to jobs", `CreatorJobDetailPage.vue:107-112,178`).
   `creator.messages.thread` is not a gone-subject case: the thread outlives the relationship (read-only, D6).

**Fallback behaviour recommended:** layer 1 → the list (push) / mark-read-only (row). Layer 2 → leave the destination's own
not-found states alone, with one small SPA addition worth a ruling: give the assignment page's not-found alert a link back
to notifications (Q3), since a tap that lands on a dead-end alert is the closest this chunk comes to the "dead tap" the brief
forbids. Ended relationships: #16 has no destination by design, so there is no dead end to reach.

---

## 4. Universal / app links (https links opening the app) — scoped honestly

**What exists:** nothing. Evidence: no `com.apple.developer.associated-domains` in `ios/App/App/App.entitlements` (the only
key is `aps-environment`); no `https` VIEW intent-filter in `android/app/src/main/AndroidManifest.xml` (the launcher
filter only); no `appUrlOpen` use anywhere in `src` (`@capacitor/app` 8.1.2 is installed and used for `appStateChange` only,
`core/platform/appResume.ts:26`); no `public/.well-known/` (`apps/main/public/` holds `creator-guide.pdf` and
`favicon.svg`). **What helps:** iOS `SceneDelegate.swift` already forwards `continue userActivity` and `openURLContexts` to
Capacitor's `SceneDelegateProxy`, and `launchMode="singleTask"` is set on the Android activity.

### 4.1 iOS — `apple-app-site-association`

- **File:** `https://<production host>/.well-known/apple-app-site-association` — no file extension, HTTPS, **no redirect**,
  reachable without auth, `Content-Type: application/json`. Content, in the modern `components` form:
  `{"applinks":{"details":[{"appIDs":["LTW9262ZXP.com.catalystgrowth.platform"],"components":[{"/":"/creator/*"}]}]}}`.
  App ID = Team ID `LTW9262ZXP` + bundle id `com.catalystgrowth.platform` (both read from `project.pbxproj`:
  `DEVELOPMENT_TEAM`, `PRODUCT_BUNDLE_IDENTIFIER`).
- **App side:** the `com.apple.developer.associated-domains` entitlement with `applinks:<production host>` in
  `App.entitlements`; the **Associated Domains capability enabled on the App ID** in the Apple Developer account
  (Pedram's account; push was enabled the same way). Plus a JS `appUrlOpen` listener that maps the URL to a route.
- **From memory, not verified here (vendor docs):** Apple fetches the file through its own CDN at install/update, so a
  change to the file is not seen instantly; iOS 16+ has a developer mode (`applinks:<host>?mode=developer`) for
  unpublished changes.

### 4.2 Android — `assetlinks.json`

- **File:** `https://<production host>/.well-known/assetlinks.json`, `application/json`:
  `[{"relation":["delegate_permission/common.handle_all_urls"],"target":{"namespace":"android_app","package_name":"com.catalystgrowth.platform","sha256_cert_fingerprints":["<SHA-256 of the signing certificate>"]}}]`.
- **The fingerprint does not exist yet.** `android/app/build.gradle` has **no `signingConfigs`**; the release build type has
  only `minifyEnabled false` and the ProGuard file; `versionCode 1`, `versionName "1.0"`. No release keystore is in the
  tree. If the app ships through Play App Signing (the usual route), the fingerprint to publish is **Google's app-signing
  certificate** (shown in the Play Console), not the upload key — which only exists once the app is created in the Play
  Console. A debug-keystore fingerprint can be listed alongside for development builds, but it is per-developer-machine.
- **App side:** an `<intent-filter android:autoVerify="true">` on `MainActivity` for `VIEW` / `DEFAULT` / `BROWSABLE`,
  `scheme="https"`, the production host, `pathPrefix="/creator"`; the same `appUrlOpen` listener.

### 4.3 Dependencies, stated plainly

1. **The production host** for the main SPA is **not documented in the repo as a fact.** `docs/runbooks/local-dev.md:20`
   says the SPAs live on `app.catalystengine.com` and `admin.catalystengine.com`; `docs/reviews/creator-app-inventory.md:522`
   cites a marketing domain `www.catalyst-growth.com`. `infra/terraform/production/main.tf` is a comment-only skeleton.
   The authority is the production `FRONTEND_MAIN_URL`, which I cannot read. **Pedram must confirm the host.**
2. **Both files must be served from the production web server**, and **how the SPA is served is not in the repo** (the
   runbook shows a single VM under `/var/www/catalyst`, colleague-managed). A single-page-app fallback that answers every
   unknown path with `index.html` would answer `/.well-known/*` with HTML and silently fail verification. Whoever owns the
   web server has to serve those two paths as static JSON with the right content type and no redirect. **Unverified from
   here.**
3. **Neither can be device-verified until the files are deployed** to that host (a deploy is Pedram's / the colleague's
   call, and the deploy log says nothing is deployed). iOS can be partly exercised earlier against any reachable HTTPS host
   that serves the file, in developer mode, on a real device (the simulator is not a faithful test); that needs a
   test host I do not know exists.
4. **Android is blocked on a release keystore / Play App Signing**, which does not exist. Verification state
   (`adb shell pm get-app-links`) needs an installed build signed with the key whose fingerprint is published.
5. **Path scope is a product decision.** The same host serves agency web users and creator web users; scoping to `/creator/*`
   leaves agency links untouched. It would also make every emailed creator CTA (`/creator/assignments/{ulid}`,
   `CampaignApplicationNotifier.php:405-408`) open the app when installed — usually the point, occasionally unwanted. It
   should **exclude** the emailed-token paths (verify-email, reset-password, accept-creator-invite) until the app has a
   sign-up (AH-104b); those are web flows today.
6. **The `appUrlOpen` handler must be conservative:** map only known creator routes (resolve through the router and check the
   matched route name), ignore everything else, and reuse the same `isReady()` gate as the push tap for the cold start.

### 4.4 Recommendation: **split to AH-103b**

Ship the resolver, the row click, the push tap, intended-route hardening and the parity guard as AH-103: all pure
app/SPA (plus the small additive backend in Q1/Q2), all buildable and testable now. Universal/app links go to **AH-103b**,
built when (a) the production host is confirmed, (b) the two JSON files can be deployed and the web server serves them
correctly, and (c) a signing key exists for Android. Reasons: nothing in 103b can be proven before those three, a build
shipped without them is code with no evidence (the failure mode of AH-102's "nothing has run on a device"), and the
resolver does not wait on any of it. The listener itself is small and can be built together with the files in 103b so the
whole path verifies in one device pass. **Not recommended as an interim:** a custom URL scheme (`catalyst://`). It needs no
deployed file, but nothing consumes it, and a custom scheme can be claimed by another app.

---

## 5. Leftover native-plumbing audit (scope check, not build)

The arc's original fence lists them together: "no push/camera/deep-links/biometrics (AH-102/103)"
(`creator-app-eyes-on-fixes-review.md:45`, `creator-app-figma-passes-review.md:36`), and two code comments name AH-103 as
the biometric chunk (`core/auth/tokenStore.ts:7-8`, `creator-app-client-shell-review.md:113`). So the brief's narrowing of
AH-103 to deep links is a **rescope**; the table says where the rest lands.

| Item                         | State                                              | Evidence                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         | Recommended home                                                                                                                                                                                                                                                                      |
| ---------------------------- | -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Biometric unlock**         | **Unbuilt**; the seam exists                       | No biometric plugin in `apps/main/package.json` (secure storage is `@aparajita/capacitor-secure-storage` only); no `NSFaceIDUsageDescription` in `Info.plist` (no `NS*UsageDescription` key at all); `TokenStore` is a three-method interface written so a biometric decorator can wrap `read()` (`tokenStore.ts:1-22`), `hasLapsed?` made optional for it                                                                                                                                                                                                                                                                                                                                       | **Its own chunk, after AH-103** (call it AH-103c). Security-relevant (token release, lock-out and fallback policy, App Store review copy), so Mode A on its own plan; not a rider on deep links. Interaction to design there: a cold-start tap behind a biometric prompt (§3.1).      |
| **Camera / gallery uploads** | **Partial**                                        | The system picker works through the WebView's `<input type="file">`: native dropzones say "Tap to choose a photo / files" (`creator.ui.upload.*_prompt_native`, AH-100 fidelity audit) on `AvatarUploadDrop.vue:125-126,180-181`, `PortfolioUploadGrid.vue:173-174`, draft media `CreatorAssignmentDetailPage.vue:749-751` (`accept="image/*,video/*"`), chat attachments (`ChatPanel.vue:400`, `RelationshipThreadView.vue:599`). **No camera plugin** (`@capacitor/camera` absent), no `capture` attribute, **no camera/photo usage descriptions** in `Info.plist`, only `INTERNET` in the app's manifest. I found no device-walk record of a camera capture or of the iOS "Take Photo" option | **Later (own chunk, or never).** The device check was run (§9.5): **Android's existing picker offers no camera entry; iOS's picker offers Take Photo, and choosing it sends the app out of the app** (consistent with the missing usage string, cause not diagnosed). Not this chunk. |
| **Offline-grace**            | **Unbuilt**, with one part free                    | The shell loads offline because the app bundles `dist` (`capacitor.config.ts` `webDir: 'dist'`, no `server.url`); the token is read once and served from memory (`tokenStore.ts`, `creator-app-client-shell-review.md:105-107`). **No** connectivity detection (`@capacitor/network` absent), **no** service worker or manifest (`index.html`: none), **no** cached data, no offline banner; a failed background refresh is swallowed (`appResume.ts`), and an offline cold start with a token lands on `error.auth-bootstrap` with a retry. The only offline copy is a generic "could not reach the server" string (`core/i18n/locales/en/auth.json:182`)                                       | **Later.** Needs a product definition (what "grace" means: read-only cache? queued writes?) before any build; the inventory called the cached-shell half of it a reason to keep the bundled shell (`creator-app-inventory.md:52`). Not 103/103b.                                      |
| **Deep links (this chunk)**  | Unbuilt: in-app routing none, universal links none | §1, §4                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | **AH-103** (resolver) + **AH-103b** (universal/app links)                                                                                                                                                                                                                             |

---

## 6. Riders — CI flake status at the tip

**Tip:** `cb79abfa`. CI [run 38201530491](https://github.com/pedram-kh/Engine/actions/runs/38201530491): **success on all four
jobs** (Backend, Frontend, E2E admin, E2E main).

| Run                                                                         | Tip                                             | Admin E2E (`admin-sign-in.spec.ts:69` and `admin-mandatory-mfa-enrollment.spec.ts:82`) | Main E2E                                   |
| --------------------------------------------------------------------------- | ----------------------------------------------- | -------------------------------------------------------------------------------------- | ------------------------------------------ |
| [38199714770](https://github.com/pedram-kh/Engine/actions/runs/38199714770) | `176f46fc` (the push that carried the C0.2 fix) | **2 passed**, no flaky annotation                                                      | **34 passed**, no flaky annotation         |
| [38201530491](https://github.com/pedram-kh/Engine/actions/runs/38201530491) | `cb79abfa`                                      | **2 passed**, no flaky annotation                                                      | **34 passed** (6.1 m), no flaky annotation |

- **`admin-sign-in.spec.ts:69` post-fix tally: 2 runs on `main`, 2 first-attempt passes, 0 retries.** The fix (`30b5894c`)
  reached `main` in the `176f46fc` push, so those two are the only post-fix `main` runs. Before it the spec flaked in 17 of
  18 `main` runs since 2026-10-06 (`admin-observability-review.md` §6). **Two clean runs are consistent with the fix and do
  not prove it** — the AH-102b "not settled" caveat (a rare, load-dependent recurrence is not excluded) stands. The tally
  continues; a recurrence reopens the tech-debt row with that run's trace.
- **`mfa-enrollment:82` and the main `2fa-enrollment:93`:** passed first time in both runs (the `BCRYPT_ROUNDS=4` fix, C0.2b).
- **Anything new:** one new CI **annotation**, not a failure — _"The ubuntu-latest label will migrate to Ubuntu 26 beginning
  **October 19, 2026**"_ (the run before it carried only the Node 20 deprecation notice). That is a runner-image change
  landing **seven days from now**, on a pipeline whose E2E timing has already been load-sensitive. It is not a finding
  against any code; it is a reason to read the first CI run after 2026-10-19 with fresh eyes rather than attributing a new
  flake to the commit that happens to be at the tip. Pinning the runner image is a CI-config call, not made here.
- **Local environment rider (unchanged, not new):** local Playwright still wants the dev stack down, the isolated E2E DB and
  `BCRYPT_ROUNDS=4`; the `2fa-enrollment:93` and `admin-sign-in:69` local failures recorded in AH-102 §23 are host load, and
  CI at the default timeouts is the authoritative run.

---

## 7. Risk and open questions

### 7.1 Live-data risk statement

**Expected build risk: LOW, app/SPA-side.** The resolver, the row click, the tap payload plumbing, the parity specs and the
`redirect` hardening are `apps/main` code with no schema, no migration and no write to existing production rows. The only
write the feature adds is the existing idempotent `PATCH …/read`, called from a new place.

**Backend that the payload gaps force, named so none is a surprise (all additive, none is a migration):**

| Item                                                                                      | Where                                                                           | Forced by                            | Production effect                                                                                     |
| ----------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------- | ------------------------------------ | ----------------------------------------------------------------------------------------------------- |
| Push route keys (`assignment_ulid`, `campaign_ulid`, `thread_ulid`) on a second allowlist | `PushMessageFactory`                                                            | Q1 = B (push tap needs ids)          | Only reachable with `push_notifications_enabled` armed (**OFF**, undeployed). No new row, no schema.  |
| `campaign_ulid` in the `campaign.job_posted` data bag                                     | `JobPostedFanOutService.php:256-261`                                            | push has no source for it (§2.2 #11) | One extra JSON key on **new** in-app rows. The fan-out itself is flag-gated.                          |
| `campaign_ulid` on `message.received_by_agency` and `campaign_application.submitted`      | `SendMessageNotifications.php:57-61`, `CampaignApplicationNotifier.php:112-120` | agency destinations #19, #21 (Q2)    | One extra JSON key on new in-app rows for agency recipients. Old rows keep resolving to the fallback. |
| Emit-site contract tests                                                                  | `tests/Feature/…`                                                               | §2.5                                 | Test-only.                                                                                            |

No existing row is rewritten; old rows resolve by the keys they already hold (§2.2) or fall back. **Nothing is armed or
deployed by any of this.** If the review prefers **zero** backend, Option C (§2.4) plus agency-gap rows as mark-read-only
achieves it at the cost named there.

### 7.2 Open questions

**Q1 — How does a push tap get its ids?** Options A / B / C in §2.4. **Recommend B** (additive ULID-only route keys on a
second allowlist, with sentinel tests, plus `campaign_ulid` on the `job_posted` bag). It reverses the "no backend payload
change" expectation in the AH-102 inventory, so it needs your explicit ruling. If you choose C instead, accept that a
creator who muted in-app for a type will get that type's push opening the list, not the screen.

**Q2 — Agency destinations: in scope, and do the two gap types get an additive `campaign_ulid`?** The shared component means
agency users click rows too (§1.3). **Recommend:** the registry covers all 21; AH-103 builds #17, #18, #20 from existing
data; and add `campaign_ulid` to #19 and #21 (two emit sites, additive, contract-tested) rather than shipping two agency
rows that only mark read. If you would rather not touch Messaging/Campaigns emit sites in this chunk, #19 and #21 ship as
mark-read-only and are named in the review.

**Q3 — Small SPA destinations-polish, in or out?** (a) `?tab=` on `CampaignDetailPage` so #17–19 and #21 land on
Drafts / Creators / Messages / Applications instead of Overview; (b) an in-page anchor so #8 lands at the chat on
`creator.assignment.detail`; (c) a "back to notifications" link on the assignment page's not-found alert (§3.3).
**Recommend (a) and (c) in, (b) in if it is cheap once (a) exists.** All SPA-only; without (a) the "exact right screen"
goal is only met to the page level for four agency types.

**Q4 — Destination choices to confirm:** `admin_connected` → the messages inbox (an exact thread needs an additive
`agency_ulid`; **recommend no**, inbox is honest); `disconnected` → fallback; `application.rejected` → the jobs board;
`creator.approved` / `creator.rejected` → the dashboard. And the thread routes (#10, #20) resolve by **lookup only**, no
additive `agency_ulid` (§2.3). Confirm or redirect.

**Q5 — Carry the current route through the two native lapse redirects?** (§3.2). **Recommend yes**: SPA-only, closes the one
real hole in "the target survives sign-in", edits the two policy specs consciously. Not needed for signed-out / cold-start.

**Q6 — Mark-as-read semantics.** Row click: navigate and mark read, navigation never waits on or fails with the PATCH, a read
row navigates without a PATCH. Push tap: fire-and-forget mark-read when a ulid and a session exist, plus a bell refresh;
nothing on a signed-out tap (§2.6). **Recommend as written.**

**Q7 — Universal links: split to AH-103b?** **Recommend yes** (§4.4). Two things you must supply before 103b can start:
the confirmed production host, and (Android) either Play Console app-signing enrolment or a release keystore. And one thing
only the web-server owner can do: serve the two files as static JSON with no SPA fallback and no redirect.

**Q8 — Authorise a device spike before the plan is finalised?** The one fact the whole push half rests on — what
`notificationActionPerformed` delivers in `notification.data` in the **background and killed** states, **on Android and on
the iPhone** — has never been observed (§0.5, §3.1). It decides the payload key names and the parser. **Recommend** a
throwaway, uncommitted debug read of the event on both devices (payload ids are non-secret; the bearer token is not
involved) as the first step after clearance, and that the plan names its result before any resolver code is written. This is
the AH-102 lesson again: every device-only bug in that chunk (cold-start tap, iOS channel) was invisible to the green suite.

**Q9 — Biometrics, camera, offline-grace** (§5): confirm the rescope — none in AH-103/103b; biometrics to its own chunk
(AH-103c) after this one; camera and offline-grace later and unscheduled.

---

## 8. Not verified

- **The tap event's `data` shape was unobserved when this inventory was written** (§0.5); §9 now records it. What §9 still does not cover is listed in §9.6.
- **No test was run for this inventory.** Every behavioural claim is from reading source at `cb79abfa`. In particular the
  `subject.ulid === null` signal for a gone subject (§3.3), the cold-start ordering of `isReady()` against a dead-token
  bootstrap (§3.2 row 2), and the offline `attempted` path (§3.1) are **code-read, not exercised**.
- **No production data was read.** The "old rows resolve" claim (§2.2) is by `git blame` of when each key entered the emit
  site, not by looking at any production notification row.
- **The production host, how the SPA is served, and whether any staging host serves the SPA** are not in the repo (§4.3).
- **Apple/Google behaviour in §4** (AASA CDN caching, developer mode, Play App Signing fingerprints, Android's
  verification trigger) is from memory of the vendor docs and is marked as such where it appears; nothing was fetched or run.
- **The iOS camera/usage-description claim** (§5) is from memory and was not tested.
- **CI claims (§6)** are from the GitHub run logs and annotations read at 2026-10-12 ~04:10 UTC.
- **Translations:** this chunk adds no copy in the inventory; if the plan adds any (a not-found link, an offline string), the
  24-locale rule applies and the flaky ten need real MT, as in AH-102.

---

## 9. Addendum — device spike results (Q8), 2026-10-12

Run after the inventory was cleared (`8378e4e5`), at the same tree. The spike was a **throwaway, uncommitted** debug read of
`@capacitor-firebase/messaging`'s `notificationActionPerformed` and `notificationReceived` events: a listener edit in
`pushClient.ts` plus an on-screen overlay that also persisted to localStorage so a killed-state result survives the process
dying. Pushes were sent by a throwaway script that bypasses the dispatcher (like `push:test`) with fixed fake ULIDs
(`01SPIKE…`) — no user content, no real notification rows. **The spike code and script are deleted; `git status` is clean at
`8378e4e5`.** Evidence is Pedram's screenshots of the overlay (not committed); the shapes below are transcribed from them.

### 9.1 Setup

|                 | iPhone                                                                                                             | Android                                                                                |
| --------------- | ------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------- |
| Device          | iPhone 16 Pro, iOS 27.0.1, Xcode debug install (sandbox APNs), over LAN to the local API                           | Samsung SM-A515F, Android 13, debug build, `adb reverse` to the local API              |
| User / token    | `user 14` (`pedram+32@defraged.com`), token `#5`                                                                   | same user, token `#6`                                                                  |
| Sender          | `full` = `type`, `v`, `notification_ulid`, `assignment_ulid`, `campaign_ulid`, `thread_ulid`; `bare` = `type`, `v` | same                                                                                   |
| Provider answer | `delivered` for every send                                                                                         | `delivered` for every send                                                             |
| Killed state    | swiped from the app switcher                                                                                       | swiped from Recents; `pidof com.catalystgrowth.platform` was **empty** before the send |

### 9.2 What was observed

| State                   | iOS                                                                                                                                                   | Android                                                                                                               |
| ----------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| **Foreground** (`full`) | `notificationReceived`; event keys `["notification"]`; notification keys `title, id, subtitle, body, data`                                            | same event; notification keys `id, data, title, body`                                                                 |
| **Background** (`full`) | `notificationActionPerformed`; event keys `["notification","actionId"]`; `actionId: "tap"`; notification keys `body, title, subtitle, data, id`       | event keys `["actionId","notification"]`; `actionId: "tap"`; **notification keys `id, data` only — no title or body** |
| **Killed** (`full`)     | tap delivered with the **full** `data`; route at the instant of the tap was **`/`** (before the first navigation); `/creator/notifications` 3 s later | tap delivered with the **full** `data`; route at the tap **`/`**; `/creator/notifications` 3 s later                  |
| **Background** (`bare`) | `data` = `type`, `v` only (plus OS extras); none of the ULID keys                                                                                     | not run (§9.6)                                                                                                        |

Route at the tap, backgrounded: `/creator/dashboard` on both, then `/creator/notifications` 3 s later — the AH-102 handler works
unchanged and the data is already present when it fires.

### 9.3 The shape of `notification.data`

- **Our keys arrive under the names we sent, in every state, on both OSes, as strings:** `type`, `v`, `notification_ulid`,
  `assignment_ulid`, `campaign_ulid`, `thread_ulid`. **`v` is the string `"1"`**, not the number `1` (as `FcmPushSender.php:157`
  predicts). Option B's route keys are therefore deliverable on both platforms without a payload-format change.
- **The `data` object also carries keys we never sent, and not all are strings.**
  - iOS adds: `aps` (a **nested object**: `alert`, `sound`), `gcm.message_id`, `google.c.a.e`, `google.c.fid`,
    `google.c.sender.id`.
  - Android adds: `from`, `collapse_key`, `gcm.n.analytics_data` (a **stringified Android Bundle**,
    `"Bundle[mParcelledData.dataSize=100]"`), `google.delivered_priority`, `google.original_priority`,
    `google.sent_time` (**number**), `google.ttl` (**number**), `google.product_id` (**number**). On a foreground event Android
    adds none of these.
  - None of these names collides with ours.
- **The event differs per platform and per state:** Android's tap event has no `title`/`body`; iOS's does. Neither carries
  anything the parser should need. `actionId` was `"tap"` on every tap.
- **A killed-state tap delivers its event before the router's first navigation on both OSes** (route `/` at that instant). This
  is the `isReady()` race AH-102 already fixed; the data is available early enough that the resolver only has to wait for
  `isReady()`, not for the data.
- No tap produced more than one `tap` event in the overlays that were readable (the second entry in each overlay is the
  spike's own +3 s re-record, not a second event).

### 9.4 What the plan's parser must therefore be

(Stated as the requirement the observations impose; the design is the plan's job.)

1. Read **only** `event.notification.data`; do not read `title`, `body`, `subtitle` or `id`.
2. Take **only the six known keys**, accept **only `typeof === 'string'` and non-empty**, and ignore every other key — including
   numbers, nested objects and Bundle strings.
3. Parse `v` as a string (`"1"`); an unrecognised `v` is not an error for the route keys, but must be decided at plan time.
4. A missing, wrong-typed or malformed value → the AH-102 fallback (the notifications list). Never a throw.
5. The resolver runs after `router.isReady()` (unchanged from the current handler).
6. The route keys must be validated as ULIDs before they are put into a route (the spike values were fixed fake strings, so this
   was not exercised).

### 9.5 Camera check (observation only, no build)

|                       | iPhone                                                                                                                | Android                                                                                                                                                                   |
| --------------------- | --------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Profile avatar upload | Sheet offered **Take Photo**; choosing it **sent the app out of the app** (Pedram's words: "it jumps out of the app") | **Android system photo picker** ("Photos" / "Collections" tabs, "Catalyst will only have access to the photos and videos that you select"). **No camera entry.**          |
| Messages attach       | Same: Take Photo exits the app                                                                                        | **System Documents UI**, "Recent" with Images / Audio / Videos / Docs and "Browse files in other apps" (Bug reports, Adobe Acrobat, Drive, Gallery). **No camera entry.** |
| Not tested            | the other upload controls (portfolio, draft media)                                                                    | the other upload controls                                                                                                                                                 |

On iOS the exit is **consistent with** `Info.plist` having no `NS*UsageDescription` key (§5), but **I did not capture a crash
log and did not confirm the cause**. This answers the §5 row; nothing was changed.

### 9.6 Still not observed

- **Android `bare`** push. Expected by analogy (we send only `type` and `v`), but not run.
- **TestFlight / production APNs** on iOS. This was a debug install on sandbox APNs; the production token split is untested here
  (the AH-102 walk already flagged it for the TestFlight pass).
- **A push whose `notification_ulid` is absent** (muted-in-app, push on) was not sent; only the `bare` variant, which also lacks
  it, was — on iOS.
- **Notification-permission-denied and signed-out taps** were not exercised in this spike; §3.2's code-read still stands.
- **A real emit** (`NotificationService::notify`). These were direct sends with fake ULIDs; the payload the factory will build
  after Q1 does not exist yet.
- **Other iOS/Android versions** than the two devices above.

_Provenance: drafted by Cursor at AH-103 inventory time (2026-10-12) from the tree at `cb79abfa`; evidence is file reads, `git blame`,
`git log` and `gh` run data, all cited above._
