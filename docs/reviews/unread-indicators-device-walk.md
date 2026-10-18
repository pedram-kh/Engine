# AH-108 — Checkpoint B: the icon-badge device walk (plan)

- **Plan:** [`unread-indicators-plan.md`](unread-indicators-plan.md) §7 (the seven acceptance items) and §3.3 / §3.4 / §4.4
- **Status:** **PLAN ONLY. Not run.** Nothing in this file is a result. Every cell in the Result column is empty until you walk it.
- **What is already proven without a device** (so the walk can stay short): the in-app badges (Playwright 390 px, Vitest), the server side
  (Pest: marks exactly its thread, counts, `aps.badge` present / absent / 0), the TypeScript side (platform gate, set / force / clear,
  `hasLoaded`), the iOS plugin bridge round trip in the simulator (`{"applied":true,"count":7}`, Stage 0), and a clean simulator build of
  the final state with `CFBundleVersion` 3. **What no test can prove, and this walk exists for:** that the number actually appears on the
  home-screen icon, that a push updates it with the app closed, and that the permission states behave.

## 0. What you need

| Need                                                                                                                           | Note                                                                                                                           |
| ------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------ |
| A real iPhone (the simulator cannot receive APNs, and its home-screen badge is not reachable by automation)                    | the Samsung is not needed: **no Android launcher badge exists by design (Q6)**; Android is item A below, a 30-second check     |
| Mac and iPhone on the same Wi-Fi; the Mac's LAN address (at hand-off: `192.168.1.131`, re-check with `ipconfig getifaddr en0`) | the iPhone cannot reach `localhost`; macOS firewall must allow `php`; the Info.plist already carries `NSAllowsLocalNetworking` |
| A creator account (approved, with a contracted campaign assignment) and an agency admin who can message them                   | existing dev fixtures                                                                                                          |
| A browser on the Mac signed in as the **agency admin** (this is the "counterparty" that sends)                                 | one browser window is the whole counterparty                                                                                   |
| `FCM_PROJECT_ID` and `FCM_SERVICE_ACCOUNT_JSON_B64` in `apps/api/.env`; `push:test --validate-only` passes                     | the same credential the AH-102 / AH-103 walks used                                                                             |
| Notifications **allowed** on the phone for the first six items; **denied** for item 6                                          |                                                                                                                                |

## 1. Run it

```bash
# API, reachable from the phone (terminal 1)
cd apps/api && php artisan serve --host=0.0.0.0 --port=8000

# The queue worker (terminal 2). PushMessageFactory and FcmPushSender changed in this chunk:
# a worker that was already running holds the OLD classes and will send pushes WITHOUT a badge.
cd apps/api && php artisan queue:restart && php artisan queue:work

# Arm the flag locally (default OFF), then prove the credential
cd apps/api && php artisan tinker --execute="Laravel\Pennant\Feature::activate('push_notifications_enabled');"
cd apps/api && php artisan push:test --validate-only

# The app, pointed at the Mac's LAN address (local, do not commit the edit)
#   apps/main/.env.native-development  ->  VITE_API_BASE_URL=http://192.168.1.131:8000/api/v1
cd apps/main && pnpm build:native:dev
cd apps/main && npx cap sync ios        # iOS ONLY. Never a bare `npx cap sync` (R-7)
cd apps/main && npx cap open ios        # Xcode: target App, your iPhone, Run (build number is already 3)
```

> **Two known traps.** (1) If push is dead on the device, check the binary size first: a stale DerivedData can produce an app
> **without** Firebase (about 260 KB instead of 2 MB) while reporting success; delete `~/Library/Developer/Xcode/DerivedData/App-*/Build`
> and rebuild. (2) An Xcode debug install uses **sandbox** APNs; **TestFlight build 3 uses production APNs.** Run the whole walk once
> from Xcode, then items 1–5 again from TestFlight build 3 (this is also the still-open production-APNs proof from AH-102).

**How to generate each event as the counterparty**

- **Campaign chat message:** in the browser as the agency admin, open the campaign, the assignment, its **Messages** tab, and send.
- **Relationship message:** as the agency admin, open the creator in the roster, and send in the **relationship** thread.
- **100+ unread (item 7):** do not type 100 messages. Seed rows tagged with a sentinel (dev database only):
  `cd apps/api && php artisan tinker --execute="App\Modules\Notifications\Models\Notification::factory()->ofType(App\Modules\Notifications\Enums\NotificationType::MessageReceivedByCreator)->count(120)->create(['recipient_user_id' => <creator user id>, 'data' => ['seed' => 'ah108-walk']]);"`
  and afterwards remove exactly those rows:
  `... Notification::query()->where('data->seed', 'ah108-walk')->delete();` (count them first with `->count()`).

## 2. The walk (plan §7, with the exact numbers to expect)

Start from a clean state: creator signed in, everything read (bell 0, tab clear, icon clear). Note the icon at the start of each item.

| #   | Do                                                                                                                                                                                         | Expect                                                                                                                                                                                                                  | Why it matters / what a fail means                                                                                                                                 | Result |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------ |
| 1   | App open in the foreground on the dashboard. Counterparty sends **one** campaign-chat message and **one** relationship message (two threads).                                              | Within ~45 s (or at once on a foreground push): bell **2**, Messages tab **2**, and after you press Home the **icon shows 2**. No banner while the app is in the foreground (presentation options are empty by design). | The count source and the plugin chain end to end. Bell and tab agree; the icon equals the **bell total**.                                                          |        |
| 2   | Open the **relationship** thread (leave the campaign thread unread).                                                                                                                       | Tab **1**, bell **1**, icon **1** (the icon may take until the next count update or return to foreground; note which).                                                                                                  | Opening clears exactly that thread's rows (server) and the shell re-reads at once (refresh event). The other thread's contribution stays.                          |        |
| 3   | **Kill the app** (swipe it away). Counterparty sends **one** more message in the **campaign** thread (wait 60 s after the last push on that thread first, or the throttle drops the push). | A push arrives. **Without opening the app, the icon shows 2** (the 1 left over plus the new one). Open the app: the icon stays 2 and the tab reads 2.                                                                   | The headline: `aps.badge` from the server updates the icon with the app closed. A fail here with item 1 passing means the worker is running old code (restart it). |        |
| 4   | Read everything (open the campaign thread; open the bell and tap **Mark all read** if anything else is unread).                                                                            | Bell 0, tab clear, **icon clears** (no number, not "0").                                                                                                                                                                | The clearing half of the loop; a real 0 reaches the plugin.                                                                                                        |        |
| 5   | Make 3 unread again. **Sign out.** Then sign in as a **different** creator (or the same one).                                                                                              | The icon clears the moment you sign out. After signing in as someone else: **no stale number**; their own count appears after the first poll.                                                                           | `clearUser` wipes the badge on every end of a session; a late poll cannot revive it.                                                                               |        |
| 6   | iOS Settings → Catalyst → Notifications → **turn off Allow Notifications** (or deny at the prompt on a fresh install). Receive a message.                                                  | **No icon badge, no crash, no error banner in the app.** In-app bell and tab still count.                                                                                                                               | The OS refuses the badge; the plugin resolves `applied: false` and the app carries on. (The in-app badges are independent of the permission.)                      |        |
| 7   | Seed 120 unread message notifications (see §1). Open the app.                                                                                                                              | Bell **99+**, tab **99+**, and the **icon shows 120** (the OS shows the real number; only the in-app glyphs cap).                                                                                                       | The cap lives in the in-app badges only; `PushMessage.badge` and the icon are uncapped.                                                                            |        |

**A. Android (no badge expected).** On the Samsung, run item 1: the in-app bell and Messages tab count; **no launcher badge is
promised and none is sent** (Q6; the FCM payload has no `notification_count`). Pass = the in-app counts work and nothing errors.

## 3. Things to watch while walking (not pass/fail, but write down what you see)

- **Foreground push does not move the icon by itself.** The app declares empty presentation options, so a push that lands in the
  foreground is handled by the in-app refresh (bell +1, then the icon follows the bell). If the icon lags there by seconds, that is expected.
- **Burst within 60 s on one thread:** the server sends **one** push per thread per minute (the existing throttle), so the icon can
  be one or two behind until the app next opens or returns to the foreground, which re-writes it. This is the accepted
  "stale until open" (Q6, tech-debt row to be written at close). Record how far behind it gets.
- **A message while `in_app` is muted** (Settings → Notifications → in-app off for messages): the push still arrives but that one
  message is not in the badge total (accepted, Q3, tech-debt row to be written at close).
- **Return to the foreground** after the icon and the bell disagree (for example after item 3): the icon should snap to the bell's number within a few seconds.

## 4. Pass criteria, and what happens next

- **Pass:** items 1–7 each show the Expect column, from the Xcode install; items 1–5 again from TestFlight build 3.
- **A fail on 1, 2, 4, 5 or 7** is a defect in this chunk: stop and report which item, what the icon and the bell showed, and the
  console of the Xcode run. **A fail on 3 only** is almost always a stale queue worker or the 60 s throttle; re-run once after
  `php artisan queue:restart` and waiting a minute before calling it a defect. **A fail on 6** is a defect (it must not crash).
- If the icon badge (4b) cannot be made to work on the device, the cut line stands (Q9): **4a ships alone** (the in-app badges, the
  backend clearing and the backlog command), and `aps.badge` is harmless without the plugin (iOS applies it on its own).
- After the walk: Stage 5 (the AH-108 entry, the deploy-log stack with `queue:restart` and the backlog command's dry-run and real
  counts, and the tech-debt rows) and the production steps below, then the single push.

## 5. Production steps this chunk adds (for the deploy log; nothing is done here)

1. **`php artisan queue:restart`** (named step): `PushMessageFactory` and `FcmPushSender` changed; a worker holding the old class
   map keeps sending badge-less payloads. A message queued by the previous release is still sent safely (pinned by a spec).
2. **The backlog command, right after `queue:restart`:** `php artisan notifications:clear-read-message-notifications --dry-run`
   first. **The dry-run count is reported to Pedram before the real run**, then the real run, both counts recorded in the deploy log.
3. Either deploy order degrades gracefully: old API + new app shows no message count (read as 0); new API + old app ignores the new field.
4. TestFlight build 3 is what carries the plugin. Testers on build 2 see the in-app badges (web bundle) but no icon badge from the app;
   their pushes still carry `aps.badge`, which iOS applies without the plugin.
