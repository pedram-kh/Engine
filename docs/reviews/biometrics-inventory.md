# AH-103c — Biometric unlock: inventory (DEFERRED)

- **Status:** **DEFERRED to post-launch (Pedram, 2026-10-20).** Read-only inventory, written at HEAD `d1ad22d8`. No code was
  written and none is planned before launch. This file is the record to resume from: the inventory below, and the rulings Pedram
  would have given (§0).
- **Mode when it resumes:** A (security-relevant: token release, lock-out and fallback policy, App Store review copy).
- **Carved out of this chunk on 2026-10-20 (not deferred):** **AH-103d**, the tech-debt 17 reinstall marker (§9), and the
  **Security page** under the creator account menu, **web AND native** (ruled 2026-10-20; first scoped here as native-only), built as part of **AH-104b-del** (§10).
- **Read first when it resumes:** [`creator-app-token-auth-review.md`](creator-app-token-auth-review.md) (AH-099: the token, its 90-day
  ceiling, the revocation set), `tech-debt.md` item 17 (under "AH-102 (push notifications)"), [`deep-links-inventory.md`](deep-links-inventory.md)
  §3.1 (the cold-start tap behind a prompt).

## 0. Rulings (what Pedram would have given, 2026-10-20)

These are recorded as the default for the resumed chunk. They are not built, and a later plan-pause may refine them.

| #   | Ruling                                                                                                                                                                                               |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| R1  | **A UI gate is enough for v1.** The stored session is unlocked locally; the token is not cryptographically bound to the biometric. (This decides the plugin: no in-repo or capgo crypto-bound item.) |
| R2  | **Plugin: `@aparajita/capacitor-biometric-auth`** (10.0.0 at inventory time), behind the existing token-store seam so a swap stays possible.                                                         |
| R3  | **Relock after 1 minute in the background**, fixed in v1 (no user setting).                                                                                                                          |
| R4  | **iOS first.** Gate the feature to iOS (as the icon badge is), log Android as a tech-debt row, do not ship an unwalked security UI on Android.                                                       |
| R5  | **Device-passcode fallback allowed** (`allowDeviceCredential: true`), with "Use password instead" always on the lock screen. Accepted consequence: the gate is "anyone with the passcode".           |

**Recommended in the inventory but not explicitly ruled** (open when the chunk resumes): opt-in, default off, offered once after the
first password sign-in plus a toggle; preference cleared on explicit sign-out and on the AH-103d wipe, kept across a token lapse; the
preference stored inside the Keychain record, never in `localStorage`; lock screen before any content on cold start; `NSFaceIDUsageDescription`
text below; localisation of purpose strings; app-switcher snapshot cover (out of scope by default).

## 1. What is protected, and what Face ID gates today

**Face ID gates nothing today.**

- No biometric plugin in `apps/main/package.json`.
- No `NSFaceIDUsageDescription` in `apps/main/ios/App/App/Info.plist` (only the camera, microphone and photo-library strings).
- The only "Face ID" text in `apps/main/src` is a comment on the resume throttle (`core/platform/appResume.ts:75`).

**What protects the token now.** The Keychain item is written `whenUnlockedThisDeviceOnly`, never iCloud-synced
(`core/auth/tokenStore.ts:111-118`). Only the device being unlocked gates it. `read()` loads the secure record once per app-open and serves
it from memory (`tokenStore.ts:36-50`); the HTTP client calls `read()` on every request (`core/api/index.ts:283`).

**What can be protected.** Biometrics can only **unlock a session already on the device**. They cannot re-authenticate against the server:
Sanctum tokens have a fixed 90-day ceiling, no refresh, no sliding window (`apps/api/config/sanctum.php:75`;
`TokenLoginController.php:41-45`; AH-099 Q3), and there is no endpoint a biometric could call.

**Threat model, stated plainly.**

- With a plugin that only returns "passed", this is a **UI gate**. The token stays in the Keychain, readable by the app process. It stops
  someone holding the unlocked phone from opening the app. It does not stop an attacker with a debugger or a jailbreak.
- Cryptographic binding needs a Keychain `SecAccessControl` item or an Android Keystore key with user authentication required. The installed
  `@aparajita/capacitor-secure-storage` 8.0.1 has neither: no access-control code on iOS, and the Android key spec has no
  `setUserAuthenticationRequired` (`SecureStorage.java:328-335`). **The brief's premise that it has biometric options does not hold.**
- In a WebView app the token sits in JS memory after unlock anyway (`tokenStore.ts:36-41`), so binding would protect it at rest only.

**Why a naive wrapper around `read()` fails** (the seam's own comment, `tokenStore.ts:5-8`, says "wrap this interface in a biometric gate"):

- `read()` is called by background work (resume refresh, the push re-POST, the unread poll). A prompt in `read()` would fire from any of them.
- Because of the in-memory cache, `read()` would prompt once per process and never re-lock on resume.
- The design therefore needs: an explicit `lock()` on the store; a "has session" check that does not release the token; and requests and
  pollers held while locked. The seam is **not** a pure decorator.
- The seam's comment names "AH-103"; the work is now AH-103c, and the comment should say so when this resumes.

## 2. Plugin choice

| Option                                                             | Finding                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `@aparajita/capacitor-secure-storage` 8.0.1 (installed)            | No biometric support (read from the installed source). Ruled out.                                                                                                                                                                                                                                                                                                                                                                                                              |
| `@capacitor-community/biometric-auth`                              | **Does not exist on npm** (registry 404 on 2026-10-20). Likely a mix-up with the aparajita plugin.                                                                                                                                                                                                                                                                                                                                                                             |
| **`@aparajita/capacitor-biometric-auth` 10.0.0** (MIT, 2026-02-09) | **Chosen (R2).** Depends on Capacitor ^8 and `@capacitor/app` ^8 (we have 8.5.2 and 8.1.2). iOS: LocalAuthentication. Android: androidx.biometric 1.1.0 (`android/build.gradle:53`), minSdk 24 matches ours. No network or analytics code found. Returns pass or fail only, so it is a UI gate; **no `domainState`**, so it cannot detect a change of enrolled biometrics. **Its web fallback fakes success with a `confirm()` dialog**, so it must never be reachable on web. |
| `@capgo/capacitor-native-biometric` 8.9.0 (MPL-2.0)                | The only candidate with cryptographic binding (`biometryCurrentSet` / Keystore user-auth keys; the feature dates from 8.4.0, a young surface). Large. Only skimmed (flags and README), not audited. The escalation path if "biometrics changed means sign in again" is ever wanted.                                                                                                                                                                                            |
| In-repo plugin                                                     | Precedent: `CatalystBadgePlugin.swift`, registered in `MainViewController.swift:13-18`. Swift on iOS is small; Android would need Java or Kotlin, androidx.biometric and Keystore work. Would allow enrolment-change detection. The other escalation path.                                                                                                                                                                                                                     |

**Privacy-checklist view (feeds AH-105).** On-device only; no biometric template is accessible to the app; nothing new is sent to the
server; one boolean preference stored on the device. My reading is that no App Privacy label entry is needed. **That is a reading, not
verified** — AH-105 confirms it.

**Proposed `NSFaceIDUsageDescription`:**

> Catalyst uses Face ID to unlock the app so your account stays private when you open it.

UI labels name the biometry from `biometryType` (Face ID, Touch ID), never hard-coded, with the name as a parameter (a trademark, not
translated). Today the camera, microphone and photo purpose strings are English only (no `InfoPlist.strings`), so a localised plist would be
new work for all four strings, not only this one.

## 3. Behaviour matrix (recommended defaults, R1–R5 applied)

| Case                               | Recommended behaviour                                                                                                                                                                                                                                                                                                                                  |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Enrolment**                      | Opt-in, default off. Offered once after the first password sign-in when biometrics are available and enrolled ("Not now" is remembered), plus a toggle on the Security page (§10). Enabling runs a test authenticate, which is also where the system permission prompt appears. Never asked on cold start.                                             |
| **Cold start**                     | Lock screen before any content. Today `router.isReady()` waits on `requireAuth` → `bootstrap()` → `read()` (`core/router/guards.ts:60`; `useAuthStore.ts:195-203`), and the push-tap and app-link handlers wait on `router.isReady()` (`pushTapRouting.ts:79`), so a deep link is held until unlock. The lock needs a real screen, not a hung promise. |
| **Return from background**         | Relock after 1 minute away (R3). The Face ID sheet itself flips the app inactive then active, so the lock needs an "authenticating" guard against a relock loop. Resume refresh must run after unlock, not before. The camera and photo picker must be walked to confirm they do not trigger a relock.                                                 |
| **Biometric failure**              | Retry, then the device passcode (R5). The lock screen always offers "Use password instead", which runs `nativeLogout()` (`useAuthStore.ts:376-391`): revoke, then clear.                                                                                                                                                                               |
| **Permission denied / unenrolled** | Treat as unavailable and switch the preference off. Never re-ask or loop (Apple 5.1.1). A device with no passcode goes to password sign-in.                                                                                                                                                                                                            |
| **Biometrics changed on device**   | Not detectable with the chosen plugin, so it is a non-event; the passcode fallback already admits anyone with the passcode. Detection means the in-repo or capgo route.                                                                                                                                                                                |
| **Sign-out**                       | Clear the preference on explicit sign-out and on the AH-103d wipe.                                                                                                                                                                                                                                                                                     |
| **Token lapse (90 days)**          | The preference survives. A dead token is found as today (`read()` expiry, `tokenStore.ts:65-69`, or a 401), so the lock screen must not ask for Face ID only to say "expired": the "has session" check covers expiry.                                                                                                                                  |
| **Reinstall (tech-debt 17)**       | The preference must **not** live in `localStorage`: iOS clears it on delete while the Keychain token survives, so a locked session would come back unprotected. AH-103d (§9) removes the combination; storing the preference inside the Keychain record is the second line (fails closed).                                                             |

**AH-104 boundary.** 103c owns one choke point that clears session and biometric state (today `TokenStore.clear()`, called at
`useAuthStore.ts:217, 303, 386` and `core/api/index.ts:270`). AH-104 owns the erasure orchestrator, the delete flow and its re-authentication.
Biometrics never stand in for password or 2FA on deletion.

## 4. Backend

**No change.** Biometric unlock makes no network call; the 90-day ceiling is unchanged ("stay signed in" is already up to 90 days and
biometrics neither extend nor shorten it); no new server data, so nothing to erase at AH-104. A real biometric re-login would be a
refresh-token endpoint: security-critical, out of scope.

## 5. Android

R4: iOS first. The plugin gives Android parity from the same JS call (BiometricPrompt; on API 28-29 strength is effectively "weak" when the
device credential is allowed), but: the store target is iOS; tech-debt 17 is iOS-only; Android has no release keystore and AH-103b ran no
Gradle build; Android has no reinstall hole. Gate to iOS like the icon badge and log Android as a tech-debt row. Not verified: that
`USE_BIOMETRIC` reaches the merged manifest. Side note: Android has `allowBackup="true"` (`AndroidManifest.xml:5`); restored ciphertext
cannot be decrypted without the Keystore key and reads as "no token" (`tokenStore.ts:40-42`).

## 6. Web

No-op, with guards: the plugin's web fallback fakes success through `confirm()`, so it sits behind `isNativePlatform()` and a dynamic import
(as the token store does, `tokenStore.ts:97-100`); the lock overlay and preference row are native-only (the creator shell also serves web
creators; precedent `NotificationPreferencesPage.vue:37`); a new route is a route-table change and new keys need all 24 locales; pin it with
a "no biometric code in `dist`" test like the Firebase stub (tech-debt, AH-102 row 10).

## 7. Apple review guidelines (from memory; verify against the current text at AH-105)

- **5.1.1, permissions and purpose strings:** the purpose string must be clear and complete; do not manipulate or force consent; the app
  must work with Face ID declined.
- **5.1.1(v), account deletion:** AH-104.
- **2.1, completeness:** a missing `NSFaceIDUsageDescription` crashes on Face ID devices (the plugin says so too).
- **HIG:** always offer a fallback; name the biometry correctly.
- **Review notes:** the feature is off by default, so reviewers are unaffected; still mention it.

## 8. Open questions (for when the chunk resumes)

1. Is the 1-minute relock still right, fixed rather than a setting? (R3 says yes.)
2. Where does the toggle live? Resolved by §10: the Security page, built in AH-104.
3. Should the lock also cover the app-switcher snapshot (a privacy-screen overlay, extra scope)?
4. Preference survives a token lapse and clears on explicit sign-out (recommended): confirm.
5. `NSFaceIDUsageDescription`: localise ×24 (new work) or stay English like the other purpose strings, with one tech-debt row covering all four?
6. Does the "has session / lock" split (not a pure decorator, §1) change the AH-100 `TokenStore` contract enough to want its own spec set?

**Not verified:** nothing run on a device; the capgo plugin only skimmed; whether the aparajita plugin's SwiftPM package collides as the
Firebase one did (the standing "never a bare `cap sync`" trap applies); whether the photo and camera pickers flip the app inactive.

## 9. Carve-out 1 — AH-103d: the tech-debt 17 reinstall marker (full loop)

**Scoped on 2026-10-20, not built; ruled full loop (Mode A) the same day, plan in [`reinstall-marker-plan.md`](reinstall-marker-plan.md).** Ad-hoc entry AH-103d (Plan-pause). Tech-debt item 17 (`tech-debt.md`, "AH-102 (push notifications)") is
the origin; this is its resolution, and it satisfies "decide before the first iOS store build" without the biometric work.

- **Problem:** iOS keeps Keychain items across Delete App; a reinstalled, handed-on or sold phone comes back signed in as the previous creator,
  and the old push-token row stays live on the server.
- **Change:** on the first native launch of an install, record a marker in app storage that iOS clears on delete (WKWebView `localStorage`).
  If the marker is **missing** and a session is present, sign it out through **`nativeLogout()`** (`useAuthStore.ts:376-391`): the push-token
  cleanup, the revoke request, then the local clear. Then write the marker. If no session is present, write the marker and make no request.
  Platform-neutral code (on Android it finds no token and writes the marker).
- **Why `nativeLogout()` and not a local wipe:** a bare wipe leaves the server token and the push row alive for up to 90 days; the existing
  sign-out path already revokes while the token still works. Offline on that first launch the revoke fails and the local clear still
  proceeds (existing behaviour, the "R-4" residue).
- **Fails closed:** if WKWebView storage is ever evicted the marker disappears and the user signs in again; the other direction is not possible.
- **One-time tester sign-out on TestFlight build 3 (accepted, by design):** an install made before the marker existed (any tester on build 1
  or 2) has no marker, so the first launch of build 3 signs that tester out once, revokes their token server-side and clears the push row.
  Say so in the build-3 tester note. It cannot happen after build 3 and there is no store release yet.
- **PROD-DATA RISK: LOW.** (Corrected 2026-10-20 from "NONE", which was wrong.) No schema, no migration, no data command, but the build
  deletes production rows: the existing logout's deletes of the user's own token and push-token row, for every install with a session and no
  marker (the tester set, once).
- **Stop-gate note (WORKING-PROCESS §2):** wiping a session is security-relevant, a full-loop trigger. This section first scoped it as Mode B;
  Pedram ruled **full loop** on 2026-10-20 ("build now, full loop (not Mode B). Walk includes the reinstall case"), which supersedes that.
- **Proof expected at build:** a pure policy spec (marker present + token: nothing runs, the over-reach negative; marker missing + token:
  `nativeLogout` runs and the marker is written after it; marker missing + no token: no request); break-reverts including "wipe on every
  launch", "marker written before the wipe" and "local clear instead of `nativeLogout`".
- **Deploy:** app only; reaches users with TestFlight build 3. Nothing in the API deploy. Deploy-log disposition is added with the build, not now.

## 10. Carve-out 2 — the "Security" page under the creator account menu (web AND native; built in AH-104b-del, not now)

**Scoped on 2026-10-20, not built, not part of AH-103d.** It is built as part of **AH-104b-del** (the deletion request flow), which needs a home anyway. **Ruling 2026-10-20: web AND native, under the creator account menu** (first scoped here as native-only; the flag at the end of this section is how that changed).

- **Where:** the creator shell's More menu (`modules/creators/layouts/CreatorDashboardLayout.vue`, the list at ~419-441). Today More holds
  Profile, Availability and **Settings** (which is the notification preferences page in v1, AH-100 Q7). Security is a new entry next to them.
- **What:** a page on both surfaces (web and the creator app), holding account deletion first; native-only sections (biometrics, post-launch) are gated by `isNativePlatform()` inside it. A route-table change, a More-menu entry, new `i18n` keys ×24 (real MT in the flaky 10).
  Biometric unlock (this file) gets its section here when it resumes post-launch.
- **Flag for AH-104 (resolved by the ruling above):** account deletion must be reachable on the **web** creator surface too (Google's web-resource
  requirement and GDPR art. 17), so the Security page cannot be native-only if it is deletion's only home. See the AH-104 inventory
  ([`account-deletion-inventory.md`](account-deletion-inventory.md)), open questions.
