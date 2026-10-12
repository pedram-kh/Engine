# AH-102 — Checkpoint B eyes-on and Android device walk (E1 · E2 · E3)

- **Plan:** [`push-notifications-plan.md`](push-notifications-plan.md) §5.6 (the 12 items) and §7.3 (E1–E3)
- **Review:** [`push-notifications-review.md`](push-notifications-review.md)
- **Status:** **Android walk run 2026-10-11** on a Samsung SM-A515F (debug build, local API, real FCM v1). Results below; the
  iOS spike (§6) has not been run.

## 0. What you need before the first step

| Need                                                                                                  | State at hand-off                                                  |
| ----------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| A Firebase project and its Android app `com.catalystgrowth.platform` (Q9)                             | done (project `catalyst-production-c28e9`)                         |
| `apps/main/android/app/google-services.json` (committed per Q14)                                      | committed (`9119b9c4`)                                             |
| `FCM_PROJECT_ID` and `FCM_SERVICE_ACCOUNT_JSON_B64` in `apps/api/.env`                                | **yours** (base64 on one line; see the comments in `.env.example`) |
| An Android phone, USB debugging on, `adb devices` shows it                                            | yours                                                              |
| JDK 21 for Gradle (`export JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-21.jdk/Contents/Home`) | the system default here is JDK 25, which Gradle 8.14 cannot run    |
| A creator account, and an admin account that can approve it                                           | existing dev fixtures                                              |

## 1. Run it

```bash
# API (terminal 1) — reachable from the phone through adb
cd apps/api && php artisan serve --host=127.0.0.1 --port=8000
adb reverse tcp:8000 tcp:8000

# The queue worker (terminal 2) — pushes are an after-commit job
cd apps/api && php artisan queue:work

# Arm the flag LOCALLY (default OFF)
cd apps/api && php artisan tinker --execute="Laravel\Pennant\Feature::activate('push_notifications_enabled');"

# Credential proof before any device: expect "Credential OK: the provider accepted authentication…"
cd apps/api && php artisan push:test --validate-only

# The app (debug build, talks to the local API over the debug-only cleartext path)
cd apps/main && pnpm build:native:dev
cd apps/main && npx cap sync android          # ANDROID ONLY. Never a bare `npx cap sync` (R-7)
cd apps/main/android && JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-21.jdk/Contents/Home ./gradlew installDebug
```

> ⚠ **Never run a bare `npx cap sync`.** It also regenerates the iOS Package.swift and would add the Firebase SDK to
> the iOS project without a `GoogleService-Info.plist`. A spec (`pushAndroidConfig.spec.ts`) fails if `Package.swift`
> mentions Firebase.

## 2. E1 — the matrix column and the push icon

| #    | Check                                                                                                                                 | Result | Screenshot                                                                                                                                                                                                                          |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------- | ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| E1.1 | Android: Settings → Notifications shows a **Push** column/toggle on every toggleable type (stacked cards on native)                   | Pass   | Settings, Android: Push toggle on every type that sends push (messages, jobs board, assignments). `assignment.live_verified` has none by design (in-app + email only), so "every toggleable type" means every type that sends push. |
| E1.2 | `creator.approved` / `creator.rejected` show Email and Push as always-on (caption), not toggles                                       | Pass   | `creator.approved` / `creator.rejected` show Always on                                                                                                                                                                              |
| E1.3 | `campaign.job_posted`: **Push is OFF by default**; every other type's Push is ON                                                      | Pass   | `job_posted` Push off, application-accepted Push on                                                                                                                                                                                 |
| E1.4 | **Web is unchanged:** `pnpm dev`, the same page has no Push column                                                                    | Pass   | web :5173 has no Push column                                                                                                                                                                                                        |
| E1.5 | The derived push glyph (`push-notifications-assets/push-icon-preview.png`) reads as the Catalyst mark in the status bar and the shade | Pass   | white Catalyst glyph on a transparent background in the status bar and on the lock-screen card; no grey square                                                                                                                      |

## 3. E2 — the soft-ask and the settings state row

| #    | Check                                                                                                                         | Result            | Screenshot                                                                                                                                                                                                                                                                                                                                                                                                  |
| ---- | ----------------------------------------------------------------------------------------------------------------------------- | ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| E2.1 | First dashboard landing (pending or approved creator): the soft-ask sheet appears — **not** at cold launch or sign-in         | Pass              | sheet on first dashboard landing, not at launch or sign-in                                                                                                                                                                                                                                                                                                                                                  |
| E2.2 | **Accept** → Android's own permission prompt → Allow → sheet closes                                                           | Pass              | Turn on, OS prompt, Allow, sheet closes, one token row                                                                                                                                                                                                                                                                                                                                                      |
| E2.3 | **Not now** → sheet closes, no OS prompt, does not return on the next launch (30-day cool-down)                               | Pass              | Not now: no OS prompt, 0 token rows, sheet did not return after relaunch (reported by Pedram)                                                                                                                                                                                                                                                                                                               |
| E2.4 | **Deny** at the OS prompt → the Settings row explains that notifications are off in system settings; no dead "Turn on" button | Pass, with a note | **After ONE denial** Android reports `prompt-with-rationale` ("you may ask again"); the app collapses that into `prompt`, so the row says "off on this device" with a **working** Turn on (it re-opens the OS prompt once). **After the SECOND denial** the OS reports `denied` and the row reads "blocked in your device settings" with no button. Read "no dead Turn on" as true after the second denial. |
| E2.5 | Settings row while permission is still `prompt`: shows a "Turn on" action                                                     | Pass              | Settings row Turn on, OS prompt, Allow, exactly one live token row                                                                                                                                                                                                                                                                                                                                          |

## 4. E3 — the plan §5.6 walk (12 items)

Reset between runs with `adb shell pm clear com.catalystgrowth.platform` (wipes the cool-down and the token).

| #   | §5.6 item                                                                                                                                                    | How                                                                                                                                                                                                 | Result            | Evidence                                                                                                                                                                                                                                                                                                                                                                                                                |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Soft-ask → OS prompt → token POST → a `user_push_tokens` row                                                                                                 | accept; then `select id,user_id,platform,left(token,12),last_seen_at from user_push_tokens;`                                                                                                        | Pass              | 1 row after Allow: `android`, 142-char token, `invalidated_at` null                                                                                                                                                                                                                                                                                                                                                     |
| 2   | `push:test --validate-only` OK, then a real push on the lock screen, **neutral copy**, localised in a non-English locale                                     | `php artisan push:test --user=<id>`; set the app locale first                                                                                                                                       | Pass              | `push:test` delivered (English, fixed string by design: it is NOT localised). Real `creator.approved` with locale `es`: "Cuenta aprobada / Tu cuenta de creador fue aprobada. Abre la app para empezar", neutral, on the lock screen                                                                                                                                                                                    |
| 3   | A real emit path arrives: an admin approves a creator → `creator.approved` push                                                                              | approve in the admin SPA                                                                                                                                                                            | Pass              | admin SPA approve: `push.delivery creator.approved delivered`, neutral card, tap opens Notifications ("Your creator account has been approved.")                                                                                                                                                                                                                                                                        |
| 4   | Tap from **background** and from **killed** lands on `/creator/notifications`                                                                                | swipe-away vs home button, then tap                                                                                                                                                                 | Pass, after a fix | Background: pass. **Killed: FAILED first** (landed on the dashboard; the cold-start tap beat the router's first navigation). Fixed in `72afb8ae` (`pushTapRouting.ts`, waits for `router.isReady()`); re-run from a real kill (new pid) lands on Notifications                                                                                                                                                          |
| 5   | Foreground arrival: the bell updates, **no** banner                                                                                                          | app open, emit a notification                                                                                                                                                                       | Pass              | foreground: no banner, bell 5 to 6 without interaction                                                                                                                                                                                                                                                                                                                                                                  |
| 6   | A message burst yields **one** push (throttle) and collapses                                                                                                 | send several messages on one thread within 60 s                                                                                                                                                     | Pass              | 4 notifications on one `assignment_ulid` in one second: 1 `push.delivery`, 1 card. Unthreaded burst (no ulid): 4 pushes, 4 cards, as designed (the throttle and collapse key need the thread)                                                                                                                                                                                                                           |
| 7   | Muted in-app, push still arrives; push toggled off → none for that type; always-on types still arrive                                                        | toggle in Settings, emit the type                                                                                                                                                                   | Pass              | (a) In-app off: no in-app row, push still delivered. (b) Push off: no `push.delivery`, no card. (c) always-on `creator.approved` arrived (item 3)                                                                                                                                                                                                                                                                       |
| 8   | `job_posted` push is OFF by default; opt in → it arrives                                                                                                     | Settings, then invite to a campaign                                                                                                                                                                 | Pass              | default: in-app row, no push, no card. Opted in (stored `push` true): `push.delivery campaign.job_posted`, card arrived                                                                                                                                                                                                                                                                                                 |
| 9   | Logout → the row is gone (cascade) → a send delivers nothing                                                                                                 | sign out; query the table; `push:test --user=<id>`                                                                                                                                                  | Pass              | sign-out: `user_push_tokens` 0 rows, 0 PATs for the user; `push:test --user` says no live tokens, nothing sent                                                                                                                                                                                                                                                                                                          |
| 10  | Uninstall / `deleteToken` → a send answers `UNREGISTERED` → `invalidated_at` set, **no retry loop**                                                          | `adb uninstall`; `push:test` (2-3 sends: FCM needs a moment to notice; it never invalidates); then send a REAL notification (`notify`) and query `invalidated_at`; check `failed_jobs` is unchanged | Pass              | after `adb uninstall`: `push:test` sends 1 and 2 still "delivered" (FCM had not noticed), send 3 `UNREGISTERED`. **`push:test` never invalidates (by design).** A real notification wrote `push.token_invalidated`, `invalidated_at` set, reason `provider_unregistered`; a second real send made no attempt; `failed_jobs` 209 before and after                                                                        |
| 11  | Deny path: the matrix state row and the settings entry behave                                                                                                | as E2.4                                                                                                                                                                                             | Pass              | see E2.4 (second denial: "blocked in your device settings", no button)                                                                                                                                                                                                                                                                                                                                                  |
| 12  | **Auto-init off (R-8):** no token exists before the soft-ask is accepted; an explicit `getToken()` works; rotation re-POSTs once. Unverified until this step | before accepting: the table is empty; after: one row; `adb shell pm clear` then re-accept: still one live row                                                                                       | Partly verified   | **Verified:** 0 rows before consent, 1 after; explicit `getToken()` works with auto-init off; after `pm clear` and re-accept, one live row. **NOT verified:** that a token rotation fires `tokenReceived` and re-POSTs exactly once (could not be forced). Note: `pm clear` WITHOUT signing out leaves the old row live on the server until FCM answers `UNREGISTERED` (expected; the client cannot know the old token) |

**R-8 fallback.** If item 12 fails (no token minted with auto-init off), the fallback is the plugin's default auto-init:
drop the `firebase_messaging_auto_init_enabled` meta-data (a one-line manifest change plus the spec that pins it), and
record that Firebase would then hold an installation identifier from first launch, for the AH-105 privacy checklist.

## 5. What to paste back

For each row: pass or fail, and for 1, 9 and 10 the SQL output; for 2 to 8 a screenshot of the lock screen or shade;
for 12 the before and after of `user_push_tokens`. Anything that fails goes in the review as a finding, not a footnote.

## 6. iOS spike and walk (Stage 5: 5.1, then 5.3)

The wiring is committed (`0d51aed9`) and builds unsigned for a generic iOS device in Debug and Release, but it is **off**:
`IOS_PUSH_ENABLED` is `false`, so on an iPhone the app shows no Push column and never asks. **That is why the spike needs
one local, uncommitted edit.**

```bash
# 1. Local only, do NOT commit: let the iPhone build offer push
#    apps/main/src/core/platform/push/pushChannelAvailable.ts  ->  export const IOS_PUSH_ENABLED = true

cd apps/main && pnpm build:native:dev
cd apps/main && npx cap sync ios                 # now fine on iOS: the wiring exists
cd apps/main && npx cap open ios                 # Xcode: target App, Team LTW9262ZXP (already set), your iPhone, Run
```

If Xcode says it cannot find the Firebase package product after a sync, close Xcode, delete
`~/Library/Developer/Xcode/DerivedData/App-*/Build`, and build again. (During the wiring a stale build cache silently
produced an app **without** Firebase, a 260 KB binary instead of 2 MB, while still reporting success. If push is dead on
the device, check this first.)

| #    | 5.1 spike check (time-boxed; go / no-go is recorded here)                                                                         | Result | Evidence                                                                                                                                                                                                                                  |
| ---- | --------------------------------------------------------------------------------------------------------------------------------- | ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| S5.1 | The app builds and installs on the iPhone from Xcode with the Firebase package (R-6)                                              | Pass   | Built and installed on a real iPhone (iOS 27.0.1) from Xcode, team `LTW9262ZXP`, Firebase 12.19.2 in the package graph                                                                                                                    |
| S5.2 | Dashboard soft-ask appears; Accept shows the iOS permission prompt; Allow                                                         | Pass   | Soft-ask, then the iOS prompt, Allow. **First run found a bug:** `createChannel` is Android-only and rejects on iOS ("Not implemented on iOS"); `register()` swallowed it, so no token and no POST. Fixed in `9d28206c` (spec + BR-69/70) |
| S5.3 | A `user_push_tokens` row exists with `platform=ios` (an FCM token, not a raw APNs token: long, with a colon)                      | Pass   | After the fix, on relaunch: 1 row, `platform=ios`, 142-char token with a colon, `invalidated_at` null                                                                                                                                     |
| S5.4 | `php artisan push:test --user=<id>` delivers to the phone (debug install = sandbox APNs, through the one uploaded key)            | Pass   | `push:test --user=14`: `token #4 (ios): delivered`; card on the lock screen, title "Catalyst", body "Test notification", no red badge number on the app icon (matches the payload, which sets none)                                       |
| S5.5 | **Go / no-go:** go means FCM accepted the iOS token and the push arrived. No-go triggers plan §5.4 (fallback B, `ApnsPushSender`) | **GO** | FCM accepted the iOS token and the push arrived (debug install, sandbox APNs, the one uploaded key). Plan §5.4 (fallback B) is not needed                                                                                                 |

**iOS walk, run 2026-10-11** (real iPhone, iOS 27.0.1, Xcode debug install = sandbox APNs). Scope: the iPhone-specific items; the
server-side ones (3, 6, 7, 8, and the logic of 9 and 10) are platform-independent and passed on Android.

| #   | Item                                              | Result                  | Evidence                                                                                                                                                                                                                                                                          |
| --- | ------------------------------------------------- | ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Soft-ask, OS prompt, token POST, a row            | Pass (after a fix)      | 0 rows before consent; `createChannel` bug fixed in `9d28206c`; then 1 `ios` row, 142-char FCM token                                                                                                                                                                              |
| 2   | A real push, neutral, localised                   | Pass                    | real `creator.approved`: en "You're approved / Your creator account was approved. Open the app to get started."; es "Cuenta aprobada / Tu cuenta de creador fue aprobada. Abre la app para empezar." Locked card: "Catalyst / Notification" (iOS Show Previews default, not ours) |
| 4   | Tap from background and from killed               | Pass                    | background: Notifications; killed (swiped in the app switcher): Notifications, no dashboard landing                                                                                                                                                                               |
| 5   | Foreground: bell updates, no banner               | Pass                    | no banner; bell +1 untouched                                                                                                                                                                                                                                                      |
| 9   | Sign-out removes the row; a send delivers nothing | Pass                    | 0 `user_push_tokens` rows, no `creator-app:ios` token left; `push:test --user=14`: no live tokens                                                                                                                                                                                 |
| 10  | Uninstall, then `UNREGISTERED`, then invalidated  | **Not observed on iOS** | after Delete App: 6 `push:test` + 1 real send, all `delivered`; token not invalidated. The reinstall then reused the same row (Firebase keeps its install identity in the Keychain). The invalidation logic is server-side, proven on Android                                     |
| 11  | Deny path                                         | Pass                    | "Don't Allow": "Push notifications are blocked in your device settings", no button, no row; allowing in iOS Settings and relaunching registered the existing row with no soft-ask                                                                                                 |
| 12  | Auto-init off: no token before consent            | Pass (that half)        | 0 rows before consent on a fresh install, and after a denial. Rotation re-POST not forceable (as on Android)                                                                                                                                                                      |

**Found on iOS:** the `createChannel` bug (fixed); the sign-in surviving a delete and reinstall (tech-debt 17); not seen: any badge
(the payload sets none, and none appeared).

On **go**: revert the local edit, commit `IOS_PUSH_ENABLED = true` (the pinned spec for that constant is updated in the same
commit), then run items 1 to 12 of §4 on iOS, once from the Xcode debug install and once from TestFlight (production APNs),
to catch the sandbox/production token split. Item 12 on iOS is the `FirebaseMessagingAutoInitEnabled=NO` equivalent.
**Add to the TestFlight run (AH-103 finding 2):** after signing in on that build, confirm a new `user_push_tokens` row exists for the
iPhone and a push is delivered (the re-registration fix was device-verified on the Samsung only).

## 7. Gotchas met during the Android walk (2026-10-11)

1. **`push:test` sends a fixed English "Test notification" and never invalidates a token.** Localised copy, the throttle, the
   collapse key and token invalidation are only exercised by a REAL emit (`NotificationService::notify`). The throttle also
   needs the thread in the data (`assignment_ulid` for `message.received_by_creator`); without it every message pushes.
2. **`adb shell am force-stop` blocks FCM delivery** until the app is launched again. For a killed-state test use the swipe
   from Recents, then `am kill` once the app is off screen, and check `pidof com.catalystgrowth.platform` is empty before
   sending.
3. **A pending migration** (`create_user_push_tokens_table`) breaks `user_push_tokens` queries on a dev database that has not
   run it. `php artisan migrate` before the walk.
4. **`SANCTUM_STATEFUL_DOMAINS` with a bare `localhost` returns 419 to the Android app** (its origin is `https://localhost`),
   and an exported `SANCTUM_STATEFUL_DOMAINS` in the shell that starts `php artisan serve` overrides `.env`. Unset it and
   restart the server. `FRONTEND_EXTRA_ORIGINS` must contain `capacitor://localhost,https://localhost` (a bad line merge there
   once made every sign-in fail with "couldn't reach the server").
5. **The debug build logs plugin responses to the Android console, including the stored bearer token.** Filter logcat
   (`rg '"receive"'`); do not paste it. Signing out revokes the token.
6. **One denial is not "denied" on Android.** See E2.4.
