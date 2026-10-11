# AH-102 — Checkpoint B eyes-on and Android device walk (E1 · E2 · E3)

- **Plan:** [`push-notifications-plan.md`](push-notifications-plan.md) §5.6 (the 12 items) and §7.3 (E1–E3)
- **Review:** [`push-notifications-review.md`](push-notifications-review.md)
- **Status:** **scaffold only. Nothing below has been run on a device.** Every evidence cell is empty on purpose; it is
  filled by whoever holds the phone and pasted into the review (§C4.3) afterwards.

## 0. What you need before the first step

| Need                                                                                                  | State at hand-off                                                                                   |
| ----------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| A Firebase project and its Android app `com.catalystgrowth.platform` (Q9)                             | **yours to supply**                                                                                 |
| `apps/main/android/app/google-services.json` (committed per Q14)                                      | **absent.** The build succeeds without it, but Firebase cannot initialise at runtime until it lands |
| `FCM_PROJECT_ID` and `FCM_SERVICE_ACCOUNT_JSON_B64` in `apps/api/.env`                                | **yours** (base64 on one line; see the comments in `.env.example`)                                  |
| An Android phone, USB debugging on, `adb devices` shows it                                            | yours                                                                                               |
| JDK 21 for Gradle (`export JAVA_HOME=/Library/Java/JavaVirtualMachines/temurin-21.jdk/Contents/Home`) | the system default here is JDK 25, which Gradle 8.14 cannot run                                     |
| A creator account, and an admin account that can approve it                                           | existing dev fixtures                                                                               |

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

| #    | Check                                                                                                                                 | Result | Screenshot |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------- | ------ | ---------- |
| E1.1 | Android: Settings → Notifications shows a **Push** column/toggle on every toggleable type (stacked cards on native)                   |        |            |
| E1.2 | `creator.approved` / `creator.rejected` show Email and Push as always-on (caption), not toggles                                       |        |            |
| E1.3 | `campaign.job_posted`: **Push is OFF by default**; every other type's Push is ON                                                      |        |            |
| E1.4 | **Web is unchanged:** `pnpm dev`, the same page has no Push column                                                                    |        |            |
| E1.5 | The derived push glyph (`push-notifications-assets/push-icon-preview.png`) reads as the Catalyst mark in the status bar and the shade |        |            |

## 3. E2 — the soft-ask and the settings state row

| #    | Check                                                                                                                         | Result | Screenshot |
| ---- | ----------------------------------------------------------------------------------------------------------------------------- | ------ | ---------- |
| E2.1 | First dashboard landing (pending or approved creator): the soft-ask sheet appears — **not** at cold launch or sign-in         |        |            |
| E2.2 | **Accept** → Android's own permission prompt → Allow → sheet closes                                                           |        |            |
| E2.3 | **Not now** → sheet closes, no OS prompt, does not return on the next launch (30-day cool-down)                               |        |            |
| E2.4 | **Deny** at the OS prompt → the Settings row explains that notifications are off in system settings; no dead "Turn on" button |        |            |
| E2.5 | Settings row while permission is still `prompt`: shows a "Turn on" action                                                     |        |            |

## 4. E3 — the plan §5.6 walk (12 items)

Reset between runs with `adb shell pm clear com.catalystgrowth.platform` (wipes the cool-down and the token).

| #   | §5.6 item                                                                                                                                                    | How                                                                                                           | Result | Evidence |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------- | ------ | -------- |
| 1   | Soft-ask → OS prompt → token POST → a `user_push_tokens` row                                                                                                 | accept; then `select id,user_id,platform,left(token,12),last_seen_at from user_push_tokens;`                  |        |          |
| 2   | `push:test --validate-only` OK, then a real push on the lock screen, **neutral copy**, localised in a non-English locale                                     | `php artisan push:test --user=<id>`; set the app locale first                                                 |        |          |
| 3   | A real emit path arrives: an admin approves a creator → `creator.approved` push                                                                              | approve in the admin SPA                                                                                      |        |          |
| 4   | Tap from **background** and from **killed** lands on `/creator/notifications`                                                                                | swipe-away vs home button, then tap                                                                           |        |          |
| 5   | Foreground arrival: the bell updates, **no** banner                                                                                                          | app open, emit a notification                                                                                 |        |          |
| 6   | A message burst yields **one** push (throttle) and collapses                                                                                                 | send several messages on one thread within 60 s                                                               |        |          |
| 7   | Muted in-app, push still arrives; push toggled off → none for that type; always-on types still arrive                                                        | toggle in Settings, emit the type                                                                             |        |          |
| 8   | `job_posted` push is OFF by default; opt in → it arrives                                                                                                     | Settings, then invite to a campaign                                                                           |        |          |
| 9   | Logout → the row is gone (cascade) → a send delivers nothing                                                                                                 | sign out; query the table; `push:test --user=<id>`                                                            |        |          |
| 10  | Uninstall / `deleteToken` → a send answers `UNREGISTERED` → `invalidated_at` set, **no retry loop**                                                          | uninstall; `push:test`; query `invalidated_at`; check the failed-jobs table                                   |        |          |
| 11  | Deny path: the matrix state row and the settings entry behave                                                                                                | as E2.4                                                                                                       |        |          |
| 12  | **Auto-init off (R-8):** no token exists before the soft-ask is accepted; an explicit `getToken()` works; rotation re-POSTs once. Unverified until this step | before accepting: the table is empty; after: one row; `adb shell pm clear` then re-accept: still one live row |        |          |

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

| #    | 5.1 spike check (time-boxed; go / no-go is recorded here)                                                                         | Result | Evidence |
| ---- | --------------------------------------------------------------------------------------------------------------------------------- | ------ | -------- |
| S5.1 | The app builds and installs on the iPhone from Xcode with the Firebase package (R-6)                                              |        |          |
| S5.2 | Dashboard soft-ask appears; Accept shows the iOS permission prompt; Allow                                                         |        |          |
| S5.3 | A `user_push_tokens` row exists with `platform=ios` (an FCM token, not a raw APNs token: long, with a colon)                      |        |          |
| S5.4 | `php artisan push:test --user=<id>` delivers to the phone (debug install = sandbox APNs, through the one uploaded key)            |        |          |
| S5.5 | **Go / no-go:** go means FCM accepted the iOS token and the push arrived. No-go triggers plan §5.4 (fallback B, `ApnsPushSender`) |        |          |

On **go**: revert the local edit, commit `IOS_PUSH_ENABLED = true` (the pinned spec for that constant is updated in the same
commit), then run items 1 to 12 of §4 on iOS, once from the Xcode debug install and once from TestFlight (production APNs),
to catch the sandbox/production token split. Item 12 on iOS is the `FirebaseMessagingAutoInitEnabled=NO` equivalent.
