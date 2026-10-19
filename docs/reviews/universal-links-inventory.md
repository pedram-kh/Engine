# AH-103b — Universal links and Android App Links: inventory

- **Status:** Inventory **accepted by Pedram, 2026-10-19**, with the rulings below. Read-only: nothing was built. The plan is
  [`universal-links-plan.md`](universal-links-plan.md).
- **Date:** 2026-10-19.
- **Orients on:** [`deep-links-inventory.md`](deep-links-inventory.md) §4 (the earlier scoping, 2026-10-12). Every claim in that
  section was re-checked against the repo as it is now; what changed is in §1.
- **Method:** repo reads, plus plain unauthenticated `GET`s against the public production web host (the same kind of outside read
  made on 2026-10-13). Nothing was read from the servers, and no credential was used.
- **Evidence vs memory:** statements marked _(from memory)_ are Apple, Google or Netlify behaviour that was **not** verified here;
  they are what the device walk and the post-deploy checks exist to prove.

## Rulings (Pedram, 2026-10-19)

1. **Host:** `platform.catalyst-growth.com`. Confirmed from a real email: `https://platform.catalyst-growth.com/creator/jobs/<ulid>`.
   (This closes the one open read in §1: the production `FRONTEND_MAIN_URL`.)
2. **Scope:** `/creator/*` only. `/auth/*` and every agency path stay web.
3. **Split:** **iOS first.** The Android handler and manifest are built now; **`assetlinks.json` is NOT published** until a real
   signing fingerprint exists (tech-debt row, opened with the plan).
4. **Order:** the web deploy carries the AASA file and `_headers`; **TestFlight build 3 comes after it.** Associated Domains is
   **now enabled on the App ID** (stated by Pedram; to be proven by the provisioning profile at build time, see the plan).
5. **Signed-out tap:** a link tapped while signed out returns to its destination after sign-in. Accepted.

## 1. What changed since the 2026-10-12 scoping

- **The production web host is known, and it is not on the box.** It is `platform.catalyst-growth.com`, a **Netlify** site
  (`server: Netlify`, no redirect on a plain `GET`). The 2026-10-13 deploy-log read recorded that it CNAMEs to
  `catalyst-engine-main.netlify.app`. The API is `platform-api.catalyst-growth.com`. The old §4.3 assumption (a single VM serving the
  SPA from `/var/www/catalyst`, with `app.catalystengine.com` as the host) is **reversed**.
- **The SPA fallback hides the files today.** `GET https://platform.catalyst-growth.com/.well-known/apple-app-site-association` and
  `/.well-known/assetlinks.json` both answer `200 text/html`: the SPA's `index.html` (532 bytes at the time of the probe). Neither
  file exists yet. Served as they are, Apple and Google would read HTML and verification would silently fail.
- **No Netlify config is in the repo.** There is no `netlify.toml`, `_redirects` or `_headers`. The SPA fallback is therefore a
  dashboard setting, which has not been read. How the sites deploy is the Bitbucket mirror (deploy-log, 2026-10-17), run by the colleague.
- **Everything else in the old §4 still holds** (re-verified):
  - `ios/App/App/App.entitlements` has `aps-environment` only; `Info.plist` has no URL types and no associated domains.
  - `AndroidManifest.xml` has the launcher filter only.
  - No `appUrlOpen` use anywhere in `apps/main/src`; `@capacitor/app` 8.1.2 is used for `appStateChange` only (`appResume.ts`) and by push registration.
  - `apps/main/public/` holds only `creator-guide.pdf` and `favicon.svg`.
  - `android/app/build.gradle` has no `signingConfigs`, `versionCode 1`, `versionName "1.0"`; no release keystore anywhere in the tree.
  - iOS identifiers: `DEVELOPMENT_TEAM = LTW9262ZXP`, `PRODUCT_BUNDLE_IDENTIFIER = com.catalystgrowth.platform`; Android `applicationId` is the same string.

## 2. What the repo already gives us (verified in code)

- **iOS bridge.** `SceneDelegate` forwards `continue userActivity` to Capacitor's `CAPSceneDelegateProxy`, which turns a
  browsing-web activity into the App plugin's `appUrlOpen` event. The event is `retainUntilConsumed`, so a cold-start link is held
  until a listener attaches.
- **Android bridge.** `BridgeActivity.onCreate` calls `onNewIntent(getIntent())`, so a cold-start VIEW intent also becomes `appUrlOpen`;
  `launchMode="singleTask"` is already set. `App.getLaunchUrl()` exists on both platforms as a cold-start fallback.
- **Routing.** Every creator route is under `/creator/*` (`dashboard`, `profile`, `availability`, `assignments`, `assignments/:ulid`,
  `jobs`, `jobs/:ulid`, `notifications`, `notifications/preferences`, `messages`, `messages/:agencyUlid`) and the router uses web
  history. `requireAuth` sends a signed-out user to `/sign-in?redirect=<fullPath>` and sign-in honours `redirect` first
  (`SignInPage.vue`, `postLoginTarget`). A link tapped while signed out therefore returns to its destination after sign-in. The
  push tap cannot do that today (the accepted P-Q2 degradation), so links will be better than pushes here.
- **A gate to copy.** `createPushTapHandler` (`core/platform/push/pushTapRouting.ts`) waits for `router.isReady()`, never throws,
  lets a newer tap supersede an older one and falls back to a safe route. A link handler can follow the same shape.
- **Emailed links all use `FRONTEND_MAIN_URL`.** Creator CTAs: `/creator/assignments/{ulid}`, `/creator/jobs/{ulid}`,
  `/creator/messages/{agencyUlid}`, `/creator/dashboard`. Agency CTAs: `/campaigns/{ulid}`. Token links: `/auth/verify-email?token=`,
  `/auth/reset-password?…`, `/auth/accept-invite?token=`. Scoping to `/creator/*` therefore leaves the agency links and every
  token flow in the browser.
- **The only query key a creator route reads is `focus`** (`?focus=messages` on the assignment detail). `?tab=` is agency-only.
- **Vite copies dotted folders.** `copyDir` in the installed Vite copies `public/.well-known/` into `dist/` (read from its source).
  The native bundle (`webDir: dist`) will carry those files too, which is harmless.

## 3. The two files

**iOS: `/.well-known/apple-app-site-association`** — no extension, HTTPS, **no redirect**, no auth, JSON. Needs only identifiers we
already have: `LTW9262ZXP.com.catalystgrowth.platform`, `components` `[{"/":"/creator/*"}]`. Because the file has no extension, Netlify
needs a `_headers` rule to serve it as `application/json` _(from memory: Netlify infers the type from the extension)_. App side: the
`com.apple.developer.associated-domains` entitlement with `applinks:platform.catalyst-growth.com`, and a JS `appUrlOpen` listener.

**Android: `/.well-known/assetlinks.json`** — needs the SHA-256 of the certificate Android will verify. **That fingerprint does not
exist yet**: no `signingConfigs`, and the earlier Samsung check ran a release APK re-signed with the local debug key. If the app ships
through Play App Signing, the fingerprint to publish is **Google's app-signing certificate**, which only exists once the app is in
the Play Console _(from memory)_. A debug fingerprint exists per developer machine and must not go to production just to test.
App side: an `<intent-filter android:autoVerify="true">` for `VIEW` / `DEFAULT` / `BROWSABLE`, `https`, the host, a `/creator/`
prefix. Without a published file the filter stays unverified: links open in the browser and the app is only an option in the
"open by default" settings _(from memory)_. That is harmless, and it is the intended state until the row is resolved.

## 4. What can be built now, and what waits

**Now (no external dependency):** the `appUrlOpen` listener, the `getLaunchUrl()` cold-start read, a pure URL-to-route policy
(HTTPS only, exact host, `/creator/` prefix, resolved through the router to a `creator.*` route name, query allow-list), specs
and parity guards; the iOS entitlement; the AASA file and `_headers`; the Android manifest filter.

**Waits:**

1. **The web deploy** (after AH-105): the files ship with the SPA through the Bitbucket mirror to Netlify. Apple's CDN fetches the
   AASA file at install or update _(from memory)_, so the file must be live **before** testers install build 3.
2. **Netlify behaviour, provable only after the deploy by curl:** whether a static file wins over the dashboard's fallback rule
   (believed yes unless the rule is forced, unverified), whether `_headers` is honoured, and that no redirect appears on `/.well-known/*`.
3. **iOS device proof:** needs 1 and 2 plus a build with the entitlement. The simulator is not a faithful test.
4. **Android App Link verification:** blocked on a real signing certificate. (The handler itself can be exercised earlier with an
   explicit VIEW intent, which proves routing but **not** verification.)
5. **Apple and Google verification behaviour** is _(from memory)_ throughout; it is proven only by the walk.

## 5. Risks recorded at inventory time

- A Netlify dashboard rule we have not read could shadow the files (the post-deploy checks catch it; the fix would be in the
  dashboard, not the repo).
- A malformed or over-broad `_headers` could change headers on every path of the live site. The plan scopes the rule to one path
  and checks `/` afterwards.
- A creator who has **not** verified their email and follows a link: `postLoginTarget` honours `redirect` before the
  unverified-email branch, so the destination guard chain may reject. Unverified creators have no assignments or jobs to be linked
  to, so the practical exposure is small; recorded, not changed.
- Links from in-app browsers (mail clients, chat apps) often do not hand off to the app _(from memory)_. Not a defect of this
  chunk; the walk records what it sees.
