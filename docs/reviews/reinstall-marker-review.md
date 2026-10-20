# AH-103d — The reinstall marker — PROOF REPORT

- **Status:** **Built. Stopped at the proof report (Checkpoint A: code complete, gates green, break-reverts done).** Awaiting **Pedram's device walk (W1–W6; W7 optional)** and review.
  **Not pushed** (the push is Pedram's trigger). **Not deployed** (app only; the API is untouched).
- **Date:** 2026-10-20 · **Mode:** A (full loop, ruled by Pedram) · **Plan:** [`reinstall-marker-plan.md`](reinstall-marker-plan.md) (cleared 2026-10-20; Q1–Q6 in §8).
- **Commits (feat + test; the docs commit follows this file):** `31446982` the marker policy, `e4a72325` the gates and the wiring, `4189f57a` the architecture spec.
- **Closes:** tech-debt item 17 for the online reinstall; residuals are in `tech-debt.md` under "AH-103d (reinstall marker)".

> **PROD-DATA RISK: LOW.** No schema, migration, config or data command, and no API change. The build deletes production rows through the existing sign-out: for every install
> that has a session and no marker (the testers, once, on the first build-3 launch) the user's own `personal_access_tokens` row and `user_push_tokens` row are deleted by the normal
> logout. That is by design, expected, and stated in the deploy log with the tester note.

## 1. Rulings applied

| Q   | Ruling (Pedram, 2026-10-20)                                                                                                                                     | Where it is in the code                                                                                                                                          |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Q1  | The marker lives in WebView `localStorage` (`catalyst.install.marker`)                                                                                          | `localStorageMarker` in `installMarker.ts`; allowlisted in `use-theme-is-sot.spec.ts` with a tech-debt note                                                      |
| Q2  | No message on the one-time sign-out; the tester note carries it                                                                                                 | No i18n key added; note in `docs/runbooks/deploy-log.md`                                                                                                         |
| Q3  | 5 s bound; when it fires the token is cleared locally and the server token is left to lapse                                                                     | `INSTALL_WIPE_BOUND_MS = 5000`; `wipeBounded()`                                                                                                                  |
| Q4  | Gated design: `bootstrap()` and `loginWithToken()` wait; eager start in `main.ts`                                                                               | `useAuthStore.reconcileInstall()`; `main.ts`; the gates spec                                                                                                     |
| Q5  | W7 (Android) optional                                                                                                                                           | In the walk below, marked optional                                                                                                                               |
| Q6  | **W0 not run.** The walk build replaced the pre-marker install on the phone, so the bug (still signed in after Delete App) cannot be reproduced there any more. | The break-reverts are the detector proof instead: the same policy with the check removed (M01) or the sign-out replaced by a local clear (M03) fails named specs |

## 2. What was built

- `apps/main/src/core/auth/installMarker.ts` (new): `reconcileInstall(deps)` is a **pure policy** (injected marker storage, token store, wipe, clock and bound). Marker unreadable → fail open, nothing
  written. Marker present → nothing runs, **no token read**. Marker absent: no token → write the marker, no request (an expired token reads as none and its "lapsed" flag is reset, so a
  fresh install never shows "session expired"); a token → sign out through the real path, bounded at 5 s with a **local clear** if it fails or stalls, then write the marker (after the clear, so a
  crash mid-wipe retries next launch). `ensureInstallReconciled` memoises one run per app-open and never rejects.
- `useAuthStore.ts`: `reconcileInstall()` (web → `'skipped-web'`, nothing touched); the wipe is `logout()`, which on native runs `nativeLogout()` verbatim (push cleanup, revoke, local clear). Two gates:
  `bootstrap()` before the token read, `loginWithToken()` before the token write (`requireGuest` does not bootstrap, so a late reconcile could otherwise wipe a just-made session).
- `main.ts`: eager `void useAuthStore().reconcileInstall()` after Pinia, before `app.use(router)`. The gates start it lazily if that line is ever lost.
- `tokenStore.ts` is **deliberately not gated** (the sign-out reads the token to revoke it; a gate there would deadlock). A spec pins that.
- `tests/unit/setup.ts` seeds the marker and resets the memo before every test, so older specs are deterministic.

## 3. Proof

### 3.1 Specs added (all green)

| File                                                         | Tests | What it pins                                                                                                                                                                                                           |
| ------------------------------------------------------------ | ----: | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/core/auth/installMarker.spec.ts`                        |    21 | the §2.2 table, order of effects, the fake-timer bound, memoisation, the adapter (key, read-error ≠ absent)                                                                                                            |
| `src/modules/auth/stores/useAuthStore.installMarker.spec.ts` |    10 | reinstall order, marker after sign-out, a slow reconcile holds `bootstrap()`, normal launch, fresh install makes no request, the login gate does not wipe the new session, shared single run, lazy start, web polarity |
| `tests/unit/architecture/install-marker-gates.spec.ts`       |     7 | gate before `tokens.read()`, gate before the write, `main.ts` order, `requireGuest` never bootstraps, token store ungated, the marker module's imports, who may use `nativeTokenStore`                                 |

### 3.2 Break-reverts (§5.35): 22 mutations, **22 caught**

Each mutation was applied to the committed file by a harness, the three marker specs run, the **exit code of the vitest run read** (non-zero = caught), the failing test names recorded, and
the file restored with `git checkout`. The tree was clean afterwards (last block of the appendix). M01–M16 are the plan's list (§6 says "fifteen" but names sixteen); the extra six are the web gate in the store (M12b), `main.ts` order (M17), the ungated token store (M18), the adapter
key (M19), a throwing store read as absent (M20) and a marker written on a read error (M21).

| Mutation                                                            | Exit | Tests failed | First failing test                                                                                                                     |
| ------------------------------------------------------------------- | ---: | -----------: | -------------------------------------------------------------------------------------------------------------------------------------- |
| M01 marker check removed: wipe on every launch                      |    1 |            2 | `installMarker.spec.ts` — marker present: nothing runs, and the token is not even read                                                 |
| M02 marker written BEFORE the wipe                                  |    1 |            7 | `installMarker.spec.ts` — marker absent + a session: signs out through the wipe, THEN writes the marker                                |
| M03 local clear() instead of the real sign-out                      |    1 |            6 | `useAuthStore.installMarker.spec.ts` — a reinstall (marker absent, token held): the REAL sign-out runs first, then bootstrap reads ... |
| M04 marker check inverted                                           |    1 |           18 | `installMarker.spec.ts` — marker present: nothing runs, and the token is not even read                                                 |
| M05 wipe runs even when the marker is present                       |    1 |            3 | `installMarker.spec.ts` — marker present: nothing runs, and the token is not even read                                                 |
| M06 a marker READ ERROR treated as absent                           |    1 |            1 | `installMarker.spec.ts` — the marker read THROWING is "unknown": fail open, nothing wiped, nothing written                             |
| M07 the 5 s bound removed                                           |    1 |            1 | `installMarker.spec.ts` — the bound firing clears the token LOCALLY, writes the marker and lets boot proceed while the...              |
| M08 bound/failure without the local token clear                     |    1 |            2 | `installMarker.spec.ts` — the wipe REJECTING still ends with a local clear and the marker written (after it)                           |
| M09 bootstrap() does not await the reconcile                        |    1 |            6 | `install-marker-gates.spec.ts` — bootstrap() awaits the reconcile BEFORE the native token read                                         |
| M10 loginWithToken() does not await the reconcile                   |    1 |            2 | `install-marker-gates.spec.ts` — loginWithToken() awaits the reconcile BEFORE it writes the token                                      |
| M11 bootstrap() gate deleted (no lazy start)                        |    1 |            7 | `install-marker-gates.spec.ts` — bootstrap() awaits the reconcile BEFORE the native token read                                         |
| M12a web polarity: policy gate removed                              |    1 |            1 | `installMarker.spec.ts` — web: touches nothing (the marker is never read or written off native)                                        |
| M12b web polarity: store gate removed                               |    1 |            1 | `useAuthStore.installMarker.spec.ts` — reconcileInstall() resolves skipped-web and touches no storage, no token store, no request      |
| M13 no memo: every caller runs its own reconcile                    |    1 |            4 | `installMarker.spec.ts` — concurrent callers share ONE run: the wipe happens once                                                      |
| M14 marker not written when there is no token                       |    1 |            4 | `installMarker.spec.ts` — marker absent + no session (a fresh install): writes the marker and makes no request                         |
| M15 expired-token lapsed flag not reset                             |    1 |            1 | `installMarker.spec.ts` — an expired token (read() discarded it and flagged "lapsed"): no wipe, flag reset, marker wri...              |
| M16 token read on a marker-present launch                           |    1 |            9 | `installMarker.spec.ts` — marker present: nothing runs, and the token is not even read                                                 |
| M17 main.ts starts the reconcile AFTER the router                   |    1 |            1 | `install-marker-gates.spec.ts` — main.ts starts the reconcile after Pinia is active and before the router is installed                 |
| M18 the token store references the marker (gated store => deadlock) |    1 |            1 | `install-marker-gates.spec.ts` — the token store does not import or call the reconcile (a gate there would deadlock the sign-...       |
| M19 adapter persists under a different key                          |    1 |            6 | `installMarker.spec.ts` — reads absent as { ok: true, value: null } and present as the stored value                                    |
| M20 a throwing localStorage read reports 'absent'                   |    1 |            1 | `installMarker.spec.ts` — a throwing store reads as { ok: false } (unknown), never as absent                                           |
| M21 marker written on a read error                                  |    1 |            1 | `installMarker.spec.ts` — the marker read THROWING is "unknown": fail open, nothing wiped, nothing written                             |

The two that prove the §2.4 ordering (the part easy to get subtly wrong), M09 and M10, each fail **both** an architecture-spec assertion and a behavioural store test.
Verbatim diffs and output for every mutation are in the appendix.

### 3.3 Gates (final committed tree, `4189f57a`, working tree clean)

| Gate                                    | Result                                                                                                                                                |
| --------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| `apps/main` Vitest, full suite          | **254 files, 2990 tests passed**, exit 0                                                                                                              |
| `vue-tsc --noEmit`                      | clean                                                                                                                                                 |
| ESLint (`eslint src tests`)             | **0 errors**; 2 warnings, both pre-existing `vue/no-v-html` (`MasterAgreementTerms.vue:149`, `ProfileBasicsForm.vue:466`), none in files touched here |
| Prettier (`--check src tests`)          | clean                                                                                                                                                 |
| lint-staged + commitlint on each commit | passed                                                                                                                                                |
| Pint, PHPStan, Pest                     | not applicable: no API change                                                                                                                         |
| CI                                      | not run: nothing is pushed. CI-green-at-tip (§5.41) is checked at the push                                                                            |

A note on the first full run: it overlapped a `lint-staged` stash taken by an unrelated docs commit, so I **re-ran the whole suite on the final clean tree** and the figures above are from that second run.
A first attempt at the break-revert harness died with its shell mid-mutation; the file was restored with `git checkout`, the tree verified clean, and the harness re-run in full. The log below is the complete run.

## 4. Residuals (also in `tech-debt.md`)

1. **Offline reinstall:** cleared on the phone at once (≤5 s), but the server token row stays valid until `expires_at` (90 days), and no `sanctum:prune-expired` is scheduled, so the dead row is never deleted.
2. **Storage eviction** signs the user out once (the safe direction).
3. **`localStorage` unwritable:** the marker cannot persist, so the sign-out would repeat each launch; the write failure is swallowed so boot is never blocked.
4. **A slow `logout()` that finishes after the 5 s bound** can clear a session created inside that window (needs a blackholed network and a quick sign-in; effect: one extra sign-in). **Not mitigated.**
5. The `use-theme-is-sot.spec.ts` allowlist grew by one file.

**Not verified:** anything on a real device. No specs run native code; the Keychain-survives-Delete-App premise is the finding from the 2026-10-11 walk, not re-proved here.

## 5. The device walk (Pedram, iPhone)

Against the local API, reading `personal_access_tokens` and `user_push_tokens` for the user before and after each step. **W0 is not run** (Q6, above).

| #   | Steps                                                                                              | Expect                                                                                                                 |
| --- | -------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| W1  | Install this build **over** the pre-marker build now on the phone, signed in. Launch.              | Lands on the sign-in page; that user's token row and push row are **gone**; nothing fails loudly; no message.          |
| W1b | Sign in; force-quit; relaunch twice.                                                               | Stays signed in both times (the marker now exists).                                                                    |
| W2  | Signed in on this build: **Delete App**, reinstall (online).                                       | Sign-in page; token row and push row gone.                                                                             |
| W3  | Signed in; note the token row; **Delete App**; **airplane mode on**; reinstall from Xcode; launch. | Sign-in page within ~5 s; the token row **still exists**; go online, sign in: works; the old row remains (residual 1). |
| W4  | Xcode "Run" over the existing install (no delete); background and foreground; kill and relaunch.   | Stays signed in; no sign-out request in the API log.                                                                   |
| W5  | Fresh install, never signed in; watch the API log.                                                 | Sign-in page; **no** `POST /auth/logout`.                                                                              |
| W6  | The web SPA in a browser, signed in, throughout.                                                   | Untouched.                                                                                                             |
| W7  | _(optional)_ Android reinstall.                                                                    | Sign-in page.                                                                                                          |

W1, W2 and W3 are the ones that matter. Please report what you see for W3 in particular (the ≤5 s claim is only proved with fake timers).

## 6. Docs half of the pair (this commit)

`tech-debt.md` (item 17 updated, new "AH-103d (reinstall marker)" section) · `adhoc-changes-log.md` (entry and Live Status) · `runbooks/deploy-log.md` (disposition stacked on the open
`AH-106 + …` `PENDING` entry, with the tester note) · `RESUMPTION-TEMPLATE.md` · this file. The Resumption and the adhoc log also record the AH-104a Stage 0 state; the Stage 0 documents themselves
are the previous commit.

## Appendix — break-revert log, verbatim

### M01 marker check removed: wipe on every launch

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..09371c7e 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -81,3 +80,0 @@ export async function reconcileInstall(deps: ReconcileDeps): Promise<ReconcileOu
-  if (current.value !== null) {
-    return 'present'
-  }
```

vitest exit code: 1 => CAUGHT
Test Files 2 failed | 1 passed (3)
Tests 2 failed | 36 passed (38)
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > marker present: nothing runs, and the token is not even read
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > marker present (every normal launch): nothing is signed out and the token is read once

### M02 marker written BEFORE the wipe

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..588905d8 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -84,0 +85 @@ export async function reconcileInstall(deps: ReconcileDeps): Promise<ReconcileOu
+  deps.marker.write('early')
```

vitest exit code: 1 => CAUGHT
Test Files 2 failed | 1 passed (3)
Tests 7 failed | 31 passed (38)
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > marker absent + a session: signs out through the wipe, THEN writes the marker
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > marker absent + no session (a fresh install): writes the marker and makes no request
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > an unreadable Keychain item reads as no session: marked, not wiped
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > an expired token (read() discarded it and flagged "lapsed"): no wipe, flag reset, marker written
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — a sign-out that fails or stalls > the wipe REJECTING still ends with a local clear and the marker written (after it)
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — a sign-out that fails or stalls > the bound firing clears the token LOCALLY, writes the marker and lets boot proceed while the revoke is still pending
...

### M03 local clear() instead of the real sign-out

```diff
diff --git a/apps/main/src/modules/auth/stores/useAuthStore.ts b/apps/main/src/modules/auth/stores/useAuthStore.ts
index 54ae3212..5adc213f 100644
--- a/apps/main/src/modules/auth/stores/useAuthStore.ts
+++ b/apps/main/src/modules/auth/stores/useAuthStore.ts
@@ -425 +425 @@ export const useAuthStore = defineStore('auth', () => {
-      wipe: logout,
+      wipe: () => nativeTokenStore().clear(),
```

vitest exit code: 1 => CAUGHT
Test Files 1 failed | 2 passed (3)
Tests 6 failed | 32 passed (38)
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > a reinstall (marker absent, token held): the REAL sign-out runs first, then bootstrap reads null and never calls /me
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > the marker is written only after the sign-out completed
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > a slow reconcile HOLDS bootstrap: the token is not read until the decision is made
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > loginWithToken() waits for the reconcile before it writes a token > the sign-in page can be reached without a bootstrap: a pending reconcile must not wipe the NEW session
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > the reconcile is shared, lazy and never a second wipe > bootstrap + login + the eager start share ONE run: the sign-out happens once
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > the reconcile is shared, lazy and never a second wipe > is lazy: with no eager start, the first gate starts it (deleting the main.ts line cannot silently disable the protection)

### M04 marker check inverted

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..8a5acac8 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -81 +81 @@ export async function reconcileInstall(deps: ReconcileDeps): Promise<ReconcileOu
-  if (current.value !== null) {
+  if (current.value === null) {
```

vitest exit code: 1 => CAUGHT
Test Files 2 failed | 1 passed (3)
Tests 18 failed | 20 passed (38)
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > marker present: nothing runs, and the token is not even read
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > marker absent + a session: signs out through the wipe, THEN writes the marker
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > the marker WRITE failing does not stop boot, and the wipe still ran once
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > marker absent + no session (a fresh install): writes the marker and makes no request
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > an unreadable Keychain item reads as no session: marked, not wiped
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > an expired token (read() discarded it and flagged "lapsed"): no wipe, flag reset, marker written
...

### M05 wipe runs even when the marker is present

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..fecdcf40 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -81,0 +82 @@ export async function reconcileInstall(deps: ReconcileDeps): Promise<ReconcileOu
+    await wipeBounded(deps)
```

vitest exit code: 1 => CAUGHT
Test Files 2 failed | 1 passed (3)
Tests 3 failed | 35 passed (38)
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > marker present: nothing runs, and the token is not even read
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > marker present (every normal launch): nothing is signed out and the token is read once
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > loginWithToken() waits for the reconcile before it writes a token > with the marker present the write is not delayed and nothing is signed out

### M06 a marker READ ERROR treated as absent

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..ed5eb9f1 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -78,4 +78 @@ export async function reconcileInstall(deps: ReconcileDeps): Promise<ReconcileOu
-  if (!current.ok) {
-    return 'read-error'
-  }
-  if (current.value !== null) {
+  if (current.ok && current.value !== null) {
```

vitest exit code: 1 => CAUGHT
Test Files 1 failed | 2 passed (3)
Tests 1 failed | 37 passed (38)
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > the marker read THROWING is "unknown": fail open, nothing wiped, nothing written

### M07 the 5 s bound removed

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..4ff07560 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -119 +119 @@ async function wipeBounded(deps: ReconcileDeps): Promise<ReconcileOutcome> {
-  const result = await Promise.race([run, bound])
+  const result = await run
```

vitest exit code: 1 => CAUGHT
Test Files 1 failed | 2 passed (3)
Tests 1 failed | 37 passed (38)
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — a sign-out that fails or stalls > the bound firing clears the token LOCALLY, writes the marker and lets boot proceed while the revoke is still pending

### M08 bound/failure without the local token clear

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..e7c74323 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -128 +127,0 @@ async function wipeBounded(deps: ReconcileDeps): Promise<ReconcileOutcome> {
-  await deps.tokens.clear().catch(() => undefined)
```

vitest exit code: 1 => CAUGHT
Test Files 1 failed | 2 passed (3)
Tests 2 failed | 36 passed (38)
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — a sign-out that fails or stalls > the wipe REJECTING still ends with a local clear and the marker written (after it)
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — a sign-out that fails or stalls > the bound firing clears the token LOCALLY, writes the marker and lets boot proceed while the revoke is still pending

### M09 bootstrap() does not await the reconcile

```diff
diff --git a/apps/main/src/modules/auth/stores/useAuthStore.ts b/apps/main/src/modules/auth/stores/useAuthStore.ts
index 54ae3212..e4430785 100644
--- a/apps/main/src/modules/auth/stores/useAuthStore.ts
+++ b/apps/main/src/modules/auth/stores/useAuthStore.ts
@@ -205 +205 @@ export const useAuthStore = defineStore('auth', () => {
-          await reconcileInstall()
+          void reconcileInstall()
```

vitest exit code: 1 => CAUGHT
Test Files 2 failed | 1 passed (3)
Tests 6 failed | 32 passed (38)
FAIL tests/unit/architecture/install-marker-gates.spec.ts > install marker — the gates exist and are ordered > bootstrap() awaits the reconcile BEFORE the native token read
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > a reinstall (marker absent, token held): the REAL sign-out runs first, then bootstrap reads null and never calls /me
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > the marker is written only after the sign-out completed
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > a slow reconcile HOLDS bootstrap: the token is not read until the decision is made
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > the reconcile is shared, lazy and never a second wipe > bootstrap + login + the eager start share ONE run: the sign-out happens once
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > the reconcile is shared, lazy and never a second wipe > is lazy: with no eager start, the first gate starts it (deleting the main.ts line cannot silently disable the protection)

### M10 loginWithToken() does not await the reconcile

```diff
diff --git a/apps/main/src/modules/auth/stores/useAuthStore.ts b/apps/main/src/modules/auth/stores/useAuthStore.ts
index 54ae3212..0740a013 100644
--- a/apps/main/src/modules/auth/stores/useAuthStore.ts
+++ b/apps/main/src/modules/auth/stores/useAuthStore.ts
@@ -374 +374 @@ export const useAuthStore = defineStore('auth', () => {
-      await reconcileInstall()
+      void reconcileInstall()
```

vitest exit code: 1 => CAUGHT
Test Files 2 failed | 1 passed (3)
Tests 2 failed | 36 passed (38)
FAIL tests/unit/architecture/install-marker-gates.spec.ts > install marker — the gates exist and are ordered > loginWithToken() awaits the reconcile BEFORE it writes the token
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > loginWithToken() waits for the reconcile before it writes a token > the sign-in page can be reached without a bootstrap: a pending reconcile must not wipe the NEW session

### M11 bootstrap() gate deleted (no lazy start)

```diff
diff --git a/apps/main/src/modules/auth/stores/useAuthStore.ts b/apps/main/src/modules/auth/stores/useAuthStore.ts
index 54ae3212..acd23f9c 100644
--- a/apps/main/src/modules/auth/stores/useAuthStore.ts
+++ b/apps/main/src/modules/auth/stores/useAuthStore.ts
@@ -205 +204,0 @@ export const useAuthStore = defineStore('auth', () => {
-          await reconcileInstall()
```

vitest exit code: 1 => CAUGHT
Test Files 2 failed | 1 passed (3)
Tests 7 failed | 31 passed (38)
FAIL tests/unit/architecture/install-marker-gates.spec.ts > install marker — the gates exist and are ordered > bootstrap() awaits the reconcile BEFORE the native token read
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > a reinstall (marker absent, token held): the REAL sign-out runs first, then bootstrap reads null and never calls /me
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > the marker is written only after the sign-out completed
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > a slow reconcile HOLDS bootstrap: the token is not read until the decision is made
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > a fresh install (no marker, no token): the marker is written and NO request is made
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > the reconcile is shared, lazy and never a second wipe > bootstrap + login + the eager start share ONE run: the sign-out happens once
...

### M12a web polarity: policy gate removed

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..f37a3a3e 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -73,3 +72,0 @@ export async function reconcileInstall(deps: ReconcileDeps): Promise<ReconcileOu
-  if (!deps.isNative) {
-    return 'skipped-web'
-  }
```

vitest exit code: 1 => CAUGHT
Test Files 1 failed | 2 passed (3)
Tests 1 failed | 37 passed (38)
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > web: touches nothing (the marker is never read or written off native)

### M12b web polarity: store gate removed

```diff
diff --git a/apps/main/src/modules/auth/stores/useAuthStore.ts b/apps/main/src/modules/auth/stores/useAuthStore.ts
index 54ae3212..fd061546 100644
--- a/apps/main/src/modules/auth/stores/useAuthStore.ts
+++ b/apps/main/src/modules/auth/stores/useAuthStore.ts
@@ -416 +416 @@ export const useAuthStore = defineStore('auth', () => {
-    if (!isNativePlatform()) {
+    if (false as boolean) {
```

vitest exit code: 1 => CAUGHT
Test Files 1 failed | 2 passed (3)
Tests 1 failed | 37 passed (38)
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > the web SPA is untouched > reconcileInstall() resolves skipped-web and touches no storage, no token store, no request

### M13 no memo: every caller runs its own reconcile

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..1e0b500b 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -139 +139 @@ export function ensureInstallReconciled(makeDeps: () => ReconcileDeps): Promise<
-  reconciled ??= (async () => {
+  reconciled = (async () => {
```

vitest exit code: 1 => CAUGHT
Test Files 2 failed | 1 passed (3)
Tests 4 failed | 34 passed (38)
FAIL src/core/auth/installMarker.spec.ts > ensureInstallReconciled — one run per app-open > concurrent callers share ONE run: the wipe happens once
FAIL src/core/auth/installMarker.spec.ts > ensureInstallReconciled — one run per app-open > a later caller after completion gets the same result without running again
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > loginWithToken() waits for the reconcile before it writes a token > the sign-in page can be reached without a bootstrap: a pending reconcile must not wipe the NEW session
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > the reconcile is shared, lazy and never a second wipe > bootstrap + login + the eager start share ONE run: the sign-out happens once

### M14 marker not written when there is no token

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..35f43858 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -106 +106 @@ export async function reconcileInstall(deps: ReconcileDeps): Promise<ReconcileOu
-  deps.marker.write((deps.now ?? (() => new Date()))().toISOString())
+  if (outcome !== 'marked') deps.marker.write((deps.now ?? (() => new Date()))().toISOString())
```

vitest exit code: 1 => CAUGHT
Test Files 2 failed | 1 passed (3)
Tests 4 failed | 34 passed (38)
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > marker absent + no session (a fresh install): writes the marker and makes no request
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > an unreadable Keychain item reads as no session: marked, not wiped
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > an expired token (read() discarded it and flagged "lapsed"): no wipe, flag reset, marker written
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > a fresh install (no marker, no token): the marker is written and NO request is made

### M15 expired-token lapsed flag not reset

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..c73fd71c 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -97 +97 @@ export async function reconcileInstall(deps: ReconcileDeps): Promise<ReconcileOu
-    if (deps.tokens.hasLapsed?.() === true) {
+    if (deps.tokens.hasLapsed?.() === true && false) {
```

vitest exit code: 1 => CAUGHT
Test Files 1 failed | 2 passed (3)
Tests 1 failed | 37 passed (38)
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > an expired token (read() discarded it and flagged "lapsed"): no wipe, flag reset, marker written

### M16 token read on a marker-present launch

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..92d52a8e 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -77,0 +78 @@ export async function reconcileInstall(deps: ReconcileDeps): Promise<ReconcileOu
+  await deps.tokens.read().catch(() => null)
```

vitest exit code: 1 => CAUGHT
Test Files 2 failed | 1 passed (3)
Tests 9 failed | 29 passed (38)
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > marker present: nothing runs, and the token is not even read
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > marker absent + a session: signs out through the wipe, THEN writes the marker
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > marker absent + no session (a fresh install): writes the marker and makes no request
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > an expired token (read() discarded it and flagged "lapsed"): no wipe, flag reset, marker written
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > the marker read THROWING is "unknown": fail open, nothing wiped, nothing written
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — a sign-out that fails or stalls > the wipe REJECTING still ends with a local clear and the marker written (after it)
...

### M17 main.ts starts the reconcile AFTER the router

```diff
diff --git a/apps/main/src/main.ts b/apps/main/src/main.ts
index 6d8a4993..81f22cf3 100644
--- a/apps/main/src/main.ts
+++ b/apps/main/src/main.ts
@@ -37 +36,0 @@ app.use(createPinia())
-void useAuthStore().reconcileInstall()
@@ -38,0 +38 @@ app.use(router)
+void useAuthStore().reconcileInstall()
```

vitest exit code: 1 => CAUGHT
Test Files 1 failed | 2 passed (3)
Tests 1 failed | 37 passed (38)
FAIL tests/unit/architecture/install-marker-gates.spec.ts > install marker — the gates exist and are ordered > main.ts starts the reconcile after Pinia is active and before the router is installed

### M18 the token store references the marker (gated store => deadlock)

```diff
diff --git a/apps/main/src/core/auth/tokenStore.ts b/apps/main/src/core/auth/tokenStore.ts
index 9994942e..11022b90 100644
--- a/apps/main/src/core/auth/tokenStore.ts
+++ b/apps/main/src/core/auth/tokenStore.ts
@@ -10,0 +11 @@ import { isNativePlatform } from '@/core/platform/isNativePlatform'
+import '@/core/auth/installMarker'
```

vitest exit code: 1 => CAUGHT
Test Files 1 failed | 2 passed (3)
Tests 1 failed | 37 passed (38)
FAIL tests/unit/architecture/install-marker-gates.spec.ts > install marker — why the second gate is needed, and why the store is not gated > the token store does not import or call the reconcile (a gate there would deadlock the sign-out)

### M19 adapter persists under a different key

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..18aa75aa 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -165 +165 @@ export const localStorageMarker: MarkerStorage = {
-      window.localStorage.setItem(INSTALL_MARKER_KEY, value)
+      window.localStorage.setItem('other.key', value)
```

vitest exit code: 1 => CAUGHT
Test Files 2 failed | 1 passed (3)
Tests 6 failed | 32 passed (38)
FAIL src/core/auth/installMarker.spec.ts > localStorageMarker (the WebView adapter) > reads absent as { ok: true, value: null } and present as the stored value
FAIL src/core/auth/installMarker.spec.ts > localStorageMarker (the WebView adapter) > persists under the documented key
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > a reinstall (marker absent, token held): the REAL sign-out runs first, then bootstrap reads null and never calls /me
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > the marker is written only after the sign-out completed
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > marker present (every normal launch): nothing is signed out and the token is read once
FAIL src/modules/auth/stores/useAuthStore.installMarker.spec.ts > bootstrap() waits for the reconcile before it reads the token > a fresh install (no marker, no token): the marker is written and NO request is made

### M20 a throwing localStorage read reports 'absent'

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..8154a052 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -160 +160 @@ export const localStorageMarker: MarkerStorage = {
-      return { ok: false }
+      return { ok: true, value: null }
```

vitest exit code: 1 => CAUGHT
Test Files 1 failed | 2 passed (3)
Tests 1 failed | 37 passed (38)
FAIL src/core/auth/installMarker.spec.ts > localStorageMarker (the WebView adapter) > a throwing store reads as { ok: false } (unknown), never as absent

### M21 marker written on a read error

```diff
diff --git a/apps/main/src/core/auth/installMarker.ts b/apps/main/src/core/auth/installMarker.ts
index bbd8e948..308b12b5 100644
--- a/apps/main/src/core/auth/installMarker.ts
+++ b/apps/main/src/core/auth/installMarker.ts
@@ -78,0 +79 @@ export async function reconcileInstall(deps: ReconcileDeps): Promise<ReconcileOu
+    deps.marker.write('x')
```

vitest exit code: 1 => CAUGHT
Test Files 1 failed | 2 passed (3)
Tests 1 failed | 37 passed (38)
FAIL src/core/auth/installMarker.spec.ts > reconcileInstall — the §2.2 table > the marker read THROWING is "unknown": fail open, nothing wiped, nothing written

## post-run tree check

`git status --short -- apps/main` => (clean)
