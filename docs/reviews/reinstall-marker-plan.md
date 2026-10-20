# AH-103d — The reinstall marker — PLAN (plan-pause)

- **Status:** **Awaiting review.** No code until this is cleared.
- **Date:** 2026-10-20
- **Entry:** **AH-103d** (tech-debt 17: a reinstalled iPhone must not come back signed in). **Full loop** (Mode A), ruled 2026-10-20; the walk includes the reinstall case.
- **Inputs:** [`biometrics-inventory.md`](biometrics-inventory.md) §9 (the scope, corrected today: risk LOW, full loop), `tech-debt.md` item 17 (under "AH-102 (push
  notifications)"), [`creator-app-token-auth-review.md`](creator-app-token-auth-review.md) (AH-099). Code read at plan time is cited inline.
- **Orientation re-read:** `WORKING-PROCESS.md` (Mode A, §5.35 break-revert, §5.41 CI-green-at-tip, §6 alarm rule).

---

## 0. The live-data risk line

> **PROD-DATA RISK: LOW.** (Corrected from the "NONE" the earlier scoping carried: that line was wrong.)
>
> - **No schema, migration, endpoint, route, resource shape or API change. The API is not touched and needs no deploy.**
> - **The build deletes production rows**, through the sign-out that already exists: for every install that has a session and **no marker**, the app calls the existing logout,
>   which deletes that user's **`personal_access_tokens` row** and, by the existing cascade, their **`user_push_tokens` row** (the AH-102 logout cleanup also asks for the push row to be
>   removed first). Who: the TestFlight testers (builds 1 and 2) and Pedram's own Xcode install, **once each**, on the first launch of build 3. Nobody else: there is no store release.
> - **Offline** the revoke fails and the local session is still cleared (existing behaviour); the server token row then **lives until its `expires_at`** (up to 90 days). That is stated,
>   not hidden: it is the item-15 orphan, now also reachable by an offline reinstall (§3, walk W3).
> - A **false positive** (the marker is lost while the app stays installed) signs the user out once: no data is lost, they sign in again.

---

## 1. What the code does today (verified)

- `createTokenStore(backend)` reads the Keychain item once per app-open and serves it from memory; an unreadable backend reads as "no token"; `clear()` sets the in-memory cache to
  `null` **synchronously** before awaiting the backend delete (`tokenStore.ts:53-57`), so a clear takes effect for every later `read()` immediately. The backend is the Keychain item
  `catalyst.auth.token` with `whenUnlockedThisDeviceOnly` (`tokenStore.ts:83, 117`). iOS keeps Keychain items across Delete App; that is the whole bug.
- `bootstrap()` (`useAuthStore.ts:179-215`): on native, `tokens.read()`; `null` → anonymous, otherwise `/me`. `requireAuth` calls it (`guards.ts:60`); **`requireGuest` does not**
  (`guards.ts:98-106`), so the sign-in page can be reached without a bootstrap.
- `logout()` on native calls `nativeLogout()` (`useAuthStore.ts:393-415`, `:375-391`): `pushLogoutCleanup()` (3 s bound, `pushRegistration.ts:35`), then `authApi.logout()`, then the
  token clear and `clearUser()`. `nativeLogout` is **not exported** from the store; `logout()` is, and on native it runs it verbatim.
- `main.ts`: `app.use(router)` runs **before** the locale-preload `Promise.all(...).then(mount)` (`main.ts:32` and `:42-46`), and installing the router starts the first navigation, so
  `requireAuth` → `bootstrap()` → `tokens.read()` can run before the app is mounted. **Any marker check must therefore finish before `bootstrap()` reads the token**, not merely "at boot".
- The platform plugins installed: `@aparajita/capacitor-secure-storage` (Keychain, no UserDefaults), `@capacitor/app`, `@capacitor/core`, `@capacitor-firebase/messaging`. **No
  `@capacitor/preferences`.** The persistence the app already uses for local, non-secret state is WebView `localStorage` (`usePushPreference.ts`, cleared on uninstall).
- Nothing else reads the token before mount: the HTTP client's `getToken` and the 401 policy fire only as a consequence of a request, and the first request is `/me` inside `bootstrap()`;
  push, resume-refresh and app-links start after mount and no-op with no user (`appResume.ts` returns when `auth.user === null`).

---

## 2. The design

### 2.1 The marker

`localStorage['catalyst.install.marker']` = the ISO time of the first launch of this install. **iOS deletes WebView storage with the app; it keeps the Keychain.** The marker is
therefore present exactly when this install has run before. Non-secret; never sent anywhere. (Question Q1 is "WebView `localStorage` or a new `@capacitor/preferences`".)

### 2.2 The policy (a pure function, injected storage, wipe and clock)

`reconcileInstall({ isNative, marker, token, wipe, now, boundMs })`:

| Marker read    | Token read        | Action                                                                                                                                                                |
| -------------- | ----------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| not native     | n/a               | **Nothing.** The web build never reads or writes the marker (negative spec).                                                                                          |
| present        | not read          | **Nothing.** The token is not even read (the common launch costs one `localStorage` read).                                                                            |
| **absent**     | present           | **Wipe through `logout()`** (⇒ `nativeLogout()`), bounded (§2.3); **then** write the marker.                                                                          |
| absent         | none (or expired) | Write the marker; **no request**. If the read consumed an expired token, reset the "lapsed" flag so a fresh install does not show "your session expired".             |
| read **threw** | n/a               | **Nothing, nothing written** (fail open: we only wipe on a definitive "absent", never on an error).                                                                   |
| write threw    | n/a               | Boot proceeds; **no second wipe in this app-open** (in-memory guard). If storage is permanently unwritable the app has broader problems (theme, locale), noted in §6. |

"Absent" means `getItem` returned `null`. The wipe is **idempotent** (a second run finds no token) and the reconcile is **memoised**: one promise per app-open, so concurrent callers share it.

### 2.3 The wipe is bounded, and the bound protects the safety property

`logout()` is what the ruling asks for, but `authApi.logout()` has **no timeout of its own** that I could find (`packages/api-client` has none outside uploads), so on a blackholed
network the first launch after a reinstall could hang until the platform's fetch timeout. The reconcile therefore races the wipe against a **5 s bound** (`push cleanup` already
uses 3 s). **When the bound wins it calls `nativeTokenStore().clear()` directly**, which empties the in-memory cache synchronously (§1), so the safety property
("a reinstalled phone is not signed in as the previous owner") holds **at 5 s regardless of the network**; the slow `logout()` keeps running and finishes harmlessly (its revoke goes out
with no bearer and is refused: the server row then lapses at `expires_at`, as in the offline case). The marker is written **after** the local clear in every branch, so a crash mid-wipe
retries on the next launch.

### 2.4 Where it is called, and why twice

- **Eager:** `main.ts` starts the reconcile right after `app.use(createPinia())` (the store needs an active Pinia), before the router is installed. One line.
- **Gated, both ways in:** `bootstrap()`'s native branch **awaits** the reconcile before `tokens.read()`, **and so does `loginWithToken` before `tokens.write()`**. The second matters
  because `requireGuest` has no bootstrap: without it, a user who reaches the sign-in page first could sign in, and a late reconcile (marker absent, token present: the **new** token)
  would wipe the session they just made. The gate is lazy as well: if `main.ts` were ever edited so the eager start was lost, the first gate starts it. A spec pins that, so the protection
  cannot be removed silently by deleting one line.
- **Not** in the token store: `logout()` → `nativeLogout()` → `authApi.logout()` reads the token through the store; a gate there would deadlock the wipe.

### 2.5 Files

`apps/main/src/core/auth/installMarker.ts` (policy, memoisation, the `localStorage` adapter marked `c8 ignore` like the secure-storage adapter), its spec, the two `await`s in
`useAuthStore.ts`, one line in `main.ts`. **The `localStorage` allowlist:** `tests/unit/architecture/use-theme-is-sot.spec.ts:67` forbids `localStorage` outside a listed set, and
"adding to this set requires a code review and a tech-debt note". This chunk adds `core/auth/installMarker.ts` to that set (a deliberate edit of an architecture guard, called out in
the commit) and adds the tech-debt note.

---

## 3. Behaviour matrix (what the walk must show)

| Case                                                       | Marker | Token | Result                                                                                                      |
| ---------------------------------------------------------- | ------ | ----- | ----------------------------------------------------------------------------------------------------------- |
| Normal relaunch, backgrounded, killed, Xcode "Run" over it | yes    | yes   | Stays signed in; nothing runs; no request.                                                                  |
| **Delete App, reinstall, online** (tech-debt 17)           | no     | yes   | Signed out; server token and push row gone; sign-in page, no message.                                       |
| **Delete App, reinstall, offline**                         | no     | yes   | Signed out locally at once (≤5 s); server token row **remains until `expires_at`**; recorded, not a leak.   |
| **Upgrade from a pre-marker build** (builds 1 and 2)       | no     | yes   | Signed out **once**; second launch after signing in stays signed in. The accepted one-time tester sign-out. |
| Fresh install, never signed in                             | no     | no    | Marker written; **no request**.                                                                             |
| Expired token + reinstall                                  | no     | exp.  | Treated as no token; marker written; **no "session expired" banner**.                                       |
| Android reinstall                                          | no     | no    | Keystore is cleared with the app, so no token; marker written. Platform-neutral code, no Android branch.    |
| Web SPA                                                    | n/a    | n/a   | No read, no write, no behaviour change.                                                                     |
| WebView storage evicted while installed                    | no     | yes   | Signed out once (the safe direction: a lost marker cannot keep someone signed in).                          |

**The one-time tester sign-out (accepted):** any install made before the marker (TestFlight build 1 or 2, and Pedram's Xcode install) is signed out once on the first launch of
build 3. It cannot recur once the marker exists, and there is no store release yet. **It goes in the build-3 tester note** (§7).

---

## 4. Sub-steps (each independently green)

| #   | Sub-step                                                                                                                                                                                                                      | Green when                                                                                    |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| 1   | `installMarker.ts`: the policy, memoisation, the bound, the adapter; its spec (the table in §2.2 plus the cases below). **Not wired.**                                                                                        | Spec green; Prettier, ESLint, `vue-tsc` clean; nothing else changes                           |
| 2   | Wire it: the `await` in `bootstrap()` (native branch, before `read()`) and in `loginWithToken` (before `write()`); the `main.ts` start; the allowlist entry and the tech-debt note.                                           | Store specs for order and the web negative; the architecture spec passes with its edited list |
| 3   | The guard-path specs: `requireAuth` reaches `bootstrap()`; `requireGuest` does not (so the `loginWithToken` gate is the guard for that path); no token reader runs before mount other than `bootstrap()`; the lazy-start pin. | Specs green                                                                                   |
| —   | **Checkpoint A:** gates, **the device walk** (§5), review. Push is your trigger.                                                                                                                                              |                                                                                               |
| 4   | Docs: tech-debt 17 closed with the new rows, the adhoc entry, `biometrics-inventory.md` §9 note, Resumption, **the deploy-log disposition**, the build-3 tester note.                                                         | You read them verbatim                                                                        |

Gates per commit: `apps/main` Vitest, `vue-tsc`, ESLint, Prettier. No backend, so no Pint or PHPStan. **No native change**: no plugin, no `cap sync` (the standing "never a bare
`cap sync`" trap does not apply); the web assets reach the device through the normal build-3 path.

**Cases the §2.2 spec carries beyond the table:** the memoised reconcile runs the wipe **once** under concurrent calls; the wipe throwing still ends with a local clear and the marker written;
the bound firing (fake timers) clears locally, writes the marker and lets boot proceed while the slow `logout()` is still pending; the marker is written **after** the wipe (order spy);
the common launch performs **no token read**.

---

## 5. The device walk (Pedram, iPhone; the reinstall case is in it)

Against the local API (the AH-108 precedent) with `personal_access_tokens` and `user_push_tokens` rows read before and after; the TestFlight leg against production comes with build 3.

| #   | Steps                                                                                                                                                      | Expect                                                                                                           |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| W0  | **Baseline on the pre-marker build** (the build currently on the phone): sign in, **Delete App**, reinstall. Reproduces tech-debt 17: **still signed in**. | Proves the walk can detect the bug, so a pass means something.                                                   |
| W1  | Install the marker build **over** the pre-marker build, signed in. Launch.                                                                                 | Lands on sign-in; token row and push row for that user **gone**; no request fails loudly.                        |
| W1b | Sign in; force-quit; relaunch twice.                                                                                                                       | Stays signed in both times (the marker now exists).                                                              |
| W2  | Signed in on the marker build: **Delete App**, reinstall (online).                                                                                         | Sign-in page; token row and push row gone.                                                                       |
| W3  | Signed in: note the token row; **Delete App**; **airplane mode on**; reinstall from Xcode; launch.                                                         | Sign-in page within ~5 s; the token row **still exists**; go online, sign in: works; old row remains (recorded). |
| W4  | Xcode "Run" over the existing install (no delete); background and foreground; kill and relaunch.                                                           | Stays signed in; no sign-out request in the API log.                                                             |
| W5  | Fresh install, never signed in; watch the API log.                                                                                                         | Sign-in page; **no** `POST /auth/logout`.                                                                        |
| W6  | The web SPA in a browser, signed in, throughout.                                                                                                           | Untouched.                                                                                                       |
| W7  | Android reinstall (optional, if a device is to hand).                                                                                                      | Sign-in page.                                                                                                    |

W0 first, W1 next, W2 and W3 are the ones that matter; the rest are over-reach checks.

---

## 6. Proof expected (break-revert, §5.35)

Each mutation must fail a named spec, logged verbatim, with the harness reading the **run's exit code** as well as the assertion:

wipe on every launch (marker ignored) · marker written **before** the wipe · local `clear()` **instead of** `logout()` · the marker check inverted · wipe when the marker is present ·
a read error treated as "absent" · the bound removed · the bound firing without a local clear · **`bootstrap()` not awaiting the reconcile** · **`loginWithToken` not awaiting it** ·
the lazy start removed · the web gate removed (marker touched off native) · the concurrent callers each wiping · the marker not written when there is no token · the expired-token
"lapsed" flag not reset · the token read on the common (marker-present) launch. **Fifteen.** The two "not awaiting" ones prove the §2.4 ordering, which is the part of this chunk
that is easy to get subtly wrong.

---

## 7. Docs and deploy-log

- **Deploy-log:** the work rides **TestFlight build 3**, stacked on the open `AH-106 + … PENDING` entry; **the API is untouched**. The build-3 obligations gain: _the marker build
  signs every pre-marker install out once; tell the testers; `personal_access_tokens` rows for those testers disappear at that moment (expected)_.
- **Tester note (draft for build 3):** "This build signs you out once on first launch. Sign in again and you stay signed in as before. It also means deleting and reinstalling the app now
  signs you out, which it did not before."
- **Tech-debt:** item 17 closed; new rows: an **offline reinstall leaves the server token until `expires_at`** (the item-15 orphan, reachable a second way); the marker lives in WebView
  storage, so an OS eviction signs the user out once (safe direction, accepted); `localStorage` unwritable ⇒ the marker cannot persist (broader problems already); the allowlist note.
- **Not in this chunk:** biometrics (AH-103c, parked), the Security page (AH-104b-del), any new user-visible string.

## 8. Questions for review

1. **Q1 — Where does the marker live?** WebView `localStorage` (recommended: no native change, no plugin, no privacy-manifest line, and the failure direction is safe: eviction signs
   the user out once) vs a new `@capacitor/preferences` (durable against eviction, but a native dependency, a `cap sync`, and a required-reason-API declaration for UserDefaults to carry
   into the AH-105 checklist, for one boolean). The earlier biometrics rule "the preference must not live in `localStorage`" was about a preference whose loss fails **open**; this marker's
   loss fails **closed**, which is why the answer differs here. Confirm `localStorage`.
2. **Q2 — No message on the one-time sign-out.** The user lands on the normal sign-in page with no explanation, because any copy is a new key in 24 locales (an i18n stop-gate) for
   something that happens once per tester. The tester note carries the explanation instead. Confirm, or ask for a banner and I will plan the keys.
3. **Q3 — The 5 s bound** (§2.3), and that when it fires the server token is left to lapse. Confirm the number, or ask for a different one.
4. **Q4 — The two gates plus the eager start** (§2.4), rather than moving `app.use(router)` behind an `await` in `main.ts`. Moving it would change the web boot order for a native-only
   concern and risk a top-level-await build-target problem (iOS deployment target is 15.0, `project.pbxproj:257`); I did not verify the Vite target. Confirm the gated design.
5. **Q5 — Android:** the code is platform-neutral and harmless there; the walk's W7 is optional. Confirm, or drop W7.
6. **Q6 — W0 (reproducing the bug first)** needs the pre-marker build still on the phone. If you have already replaced it, I will say so and W0 becomes "not run, and why".
