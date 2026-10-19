# AH-103b — Universal links (iOS first) and the Android handler: plan

- **Status:** **PLAN CLEARED by Pedram, 2026-10-19**, with one change (**D2**, below). D1, D3, D4, D5 accepted as written; `_headers`
  keeps Netlify's default cache; C1–C7 are run by the colleague during the deploy and the output is forwarded for the deploy log.
  Mode A (the chunk parses untrusted URLs handed to the app by the OS, a security surface, and it changes native config).
- **Inventory:** [`universal-links-inventory.md`](universal-links-inventory.md) (accepted 2026-10-19). **Rulings:** R1–R5 below,
  baked in.
- **Stacks on:** AH-106, AH-107, AH-108 (all pushed, none deployed). One production deploy and one TestFlight build 3 for the batch;
  **build 3 comes after the web deploy** (R4).
- **PROD-DATA RISK: NONE.** No schema, no API, no data. The production-visible changes are two static files and one headers rule on
  the web deploy; the rest is client and native code that reaches users only through build 3. The one real production risk is a
  mis-scoped `_headers` rule (§6, check C6).
- **Hard stop-gates:** none of schema, API shape, validation, policy, route table, i18n key, state-machine edge or scheduled job is
  touched. **Security** is touched (URL handling), hence the plan-pause.

## 0. Rulings (Pedram, 2026-10-19)

| #   | Ruling                                                                                                                                                                          |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| R1  | Host `platform.catalyst-growth.com` (confirmed from a real email: `https://platform.catalyst-growth.com/creator/jobs/<ulid>`).                                                  |
| R2  | Scope **`/creator/*` only**; `/auth/*` and agency paths stay web.                                                                                                               |
| R3  | **iOS first.** Build the Android handler and manifest now. **Do not publish `assetlinks.json`** until a real signing fingerprint exists (tech-debt row, opened with this plan). |
| R4  | The web deploy carries the AASA file and `_headers`; build 3 after. Associated Domains is enabled on the App ID.                                                                |
| R5  | A signed-out tap returns to the destination after sign-in.                                                                                                                      |

## 1. What this chunk is, and is not

**Is:** a tap on an `https://platform.catalyst-growth.com/creator/…` link, from an email, Notes, Messages or anywhere else, opens
the creator app on that screen when the app is installed, and opens the website when it is not. Cold start, warm start, and signed out.

**Is not:** any link under `/auth/*` (verify-email, reset-password, accept-invite stay web flows); any agency path (`/campaigns/…`,
`/messages/…`, `/roster/…`); a custom URL scheme (nothing consumes it and another app can claim it); any backend, API, push-payload,
i18n or route-table change; biometric interplay (AH-103c); **publishing `assetlinks.json`** (R3).

## 2. Design

### 2.1 The link policy (pure, specced to death) — `core/platform/links/appLink.ts`

`parseAppLink(rawUrl: string): { path: string; query: Record<string, string> } | null`. Returns `null` (never throws) unless **all** hold:

1. `new URL(rawUrl)` parses; `protocol === 'https:'`.
2. `hostname` equals the constant `APP_LINK_HOST = 'platform.catalyst-growth.com'` **exactly** (case-insensitive). Not `endsWith`, not
   `includes`: `platform.catalyst-growth.com.evil.test` and `evilplatform.catalyst-growth.com` must fail.
3. No credentials (`username`/`password` empty) and no explicit port.
4. `pathname` starts with `/creator/` after the URL parser's normalisation, and contains no backslash, no encoded slash (`%2f`), no
   encoded or literal dot segment that survives parsing, no control characters, length ≤ 256.
5. Query: only the keys the creator routes read today are kept. At build time that is **`focus`** (value `messages`, from
   `CreatorAssignmentDetailPage`); everything else is dropped. The fragment is dropped.

The host is a **constant, not an env var** (D1): dev builds never receive production links, and a constant is one spec pin instead of
a configuration that can drift from the entitlement and the manifest (which §2.5 guards).

### 2.2 The route check — `resolveAppLink(router, rawUrl)`

`parseAppLink` → `router.resolve({ path, query })` → accept only when `matched.length > 0` **and** `typeof name === 'string'` **and**
`name.startsWith('creator.')`. The result is one of three outcomes (D2, as cleared):

- **`route`** — a real creator screen: push its `fullPath`.
- **`dashboard`** — the link is **in scope** (HTTPS, exact host, no credentials, no port, and a path that is under `/creator/` after the
  URL parser's normalisation) but does not resolve: an unknown route (`/creator/nonsense`), a hostile path form under `/creator/`
  (encoded slash, backslash, control character, over-long). Push `{ name: 'creator.dashboard' }`, a fixed route name; the offending
  path is never given to the router.
- **`ignore`** — **out of scope**: not HTTPS, another host or a look-alike, credentials, a port, or a path that is not under
  `/creator/` once normalised (`/creator/../auth/…` normalises to `/auth/…`). The OS should not have handed these to the app; if one
  arrives anyway, do nothing.

The raw URL is never handed to the router.

### 2.3 The handler — `core/platform/links/appLinkRouting.ts` (same shape as `pushTapRouting.ts`)

`createAppLinkHandler(router, deps)` returns `(url: string) => void`:

1. `await router.isReady()` (a cold-start link arrives before the router has settled; an immediate push would be overtaken by the
   initial navigation, the lesson of AH-102/103).
2. `resolveAppLink`: `route` → push it; **`dashboard` → push the creator dashboard** (D2, as cleared: the user tapped one of our links, so
   the app opens somewhere sensible); `ignore` → do nothing (the link is not ours). A signed-out user sent to the dashboard goes
   through `requireAuth` and sign-in like any other link.
3. `router.push(resolved.fullPath)`; a rejected push is swallowed; **the whole chain cannot throw**.
4. A newer link supersedes an older one still waiting on `isReady()` (the `latest` token, as in the push handler).
5. **Duplicate guard (D3):** on a cold start Capacitor can deliver the same URL through `appUrlOpen` and through `getLaunchUrl()`;
   an identical URL within 3 s of the last handled one is ignored.

Signed out: `requireAuth` already redirects to `/sign-in?redirect=<fullPath>`, and sign-in honours it (R5). **No new code**; pinned by a
spec that drives the real guard, and by walk item W3.

### 2.4 Wiring — `main.ts` / `startAppLinks`

Native only (`isNativePlatform()`): `App.addListener('appUrlOpen', ({ url }) => handle(url))`, plus one `App.getLaunchUrl()` read at
start. Dynamic import of `@capacitor/app`, as `appResume.ts` does; a listener that fails to attach is swallowed (the app must still
boot). Web builds register nothing.

### 2.5 Native and static files

- **iOS:** `App.entitlements` gains `com.apple.developer.associated-domains` = `["applinks:platform.catalyst-growth.com"]`. The
  committed value has **no** `?mode=developer` (D4); a developer-mode variant is a local, uncommitted edit for any pre-deploy test.
- **AASA:** `apps/main/public/.well-known/apple-app-site-association` (no extension):
  `{"applinks":{"details":[{"appIDs":["LTW9262ZXP.com.catalystgrowth.platform"],"components":[{"/":"/creator/*"}]}]}}`.
- **`apps/main/public/_headers`:** one rule, scoped to the one path: `/.well-known/apple-app-site-association` →
  `Content-Type: application/json`. Nothing else, no wildcard.
- **Android:** an `<intent-filter android:autoVerify="true">` on `MainActivity`: `VIEW`, `DEFAULT`, `BROWSABLE`, `scheme="https"`,
  `host="platform.catalyst-growth.com"`, `pathPrefix="/creator/"` (D5: with the trailing slash, so `/creatorfoo` does not match).
- **No `assetlinks.json` in the tree** (R3). A spec fails if the file appears, with a message that names the tech-debt row, so
  publishing it is a conscious change.
- The AASA file and `_headers` are copied into `dist/` and so into the native bundle too (harmless; noted).

### 2.6 Guards (Vitest, reading the real files from disk)

- **AASA ↔ native identifiers:** the file parses; `appIDs[0]` equals `${DEVELOPMENT_TEAM}.${PRODUCT_BUNDLE_IDENTIFIER}` read from
  `project.pbxproj`; `components` is exactly `[{"/":"/creator/*"}]`.
- **Entitlement ↔ constant:** `App.entitlements` contains `applinks:` + `APP_LINK_HOST`.
- **Manifest ↔ constant:** the filter's scheme, host and `pathPrefix` equal the constants; `autoVerify` is true.
- **Route-table parity:** a table of every emailed creator CTA shape (`/creator/assignments/<ulid>`, `/creator/jobs/<ulid>`,
  `/creator/messages/<ulid>`, `/creator/dashboard`) resolves, through the **real** route table, to a `creator.*` name. A route rename
  that would silently stop a link from resolving fails here.
- **Negative table:** `/auth/verify-email?token=…`, `/auth/reset-password`, `/auth/accept-invite`, `/campaigns/<ulid>`, `/messages/<ulid>`,
  `/roster`, `/`, `/sign-in`, and every hostile form in §2.1 resolve to `null`.
- **No `assetlinks.json`** (above).

## 3. Stages and checkpoints

1. **Stage 1 — policy and handler, pure.** `appLink.ts`, `appLinkRouting.ts`, their specs, the route-table parity and negative tables.
   One commit. No native, no wiring.
2. **Stage 2 — wiring and native.** `startAppLinks` in `main.ts` (+ spec with a faked `@capacitor/app`), the entitlement, the manifest
   filter, the AASA file, `_headers`, the guards. **Proof the capability is on the App ID:** build for a real device with automatic
   signing, then read the entitlements out of the built app and the associated-domains entry out of the embedded provisioning
   profile (`security cms -D -i …/embedded.mobileprovision`). Simulator build of the final state. Commit(s).
3. **Stage 3 — proof.** Break-reverts (§4), gates (Pint/PHPStan untouched; ESLint, vue-tsc, Prettier, full `apps/main` Vitest), and the
   **full local Playwright suite**, because `main.ts` and `public/` are traversed by the E2E (the pre-existing local `brands.spec.ts:108`
   30 s timing flake is expected, as at AH-108).
4. **Stage 4 — docs close.** AH-103b entry; the deploy-log block (§6) folded into the combined PENDING entry; tech-debt rows (§8);
   Resumption Part 2.
5. **Checkpoint — stop.** Nothing after Stage 4 can be proven without the web deploy and build 3. The walk (§7) is Pedram's, after both.

## 4. Break-reverts planned (each mutation confirmed applied, the right spec watched failing, restored clean)

Host check removed; host matched with `endsWith`; host matched with `includes`; `http:` accepted; credentials allowed; port allowed;
`/creator/` prefix check removed; prefix check loosened to `/creator`; the in-scope-unresolvable case sent to `ignore` (the old D2);
the dashboard fallback pointed at a route that does not exist; encoded-slash / dot-segment check removed; route-name check
(`creator.`) removed; `matched.length` check removed; query allow-list removed; fragment kept; `isReady()` wait removed; supersede
removed; duplicate guard removed; a throw let escape from the chain; `getLaunchUrl` read dropped; AASA `components` widened to `/*`;
`appIDs` team id changed; entitlement domain changed; manifest host changed; `autoVerify` dropped; `assetlinks.json` created.

## 5. Decisions proposed (for the review)

- **D1** host is a constant, not an env var. **D2 (CHANGED by Pedram at clearance)** an **in-scope but unresolvable** link opens the
  **dashboard**; only a link that is out of scope is ignored (§2.2). The plan originally proposed a no-op. **D3** a 3 s duplicate guard between `appUrlOpen` and `getLaunchUrl()`.
  **D4** no `?mode=developer` in the committed entitlement. **D5** Android `pathPrefix="/creator/"` with the trailing slash.
- **Cleared:** D1, D3, D4, D5 as written; D2 as changed above.

## 6. Deploy steps for the colleague (the web deploy; goes into the deploy-log PENDING entry)

**What deploys:** the web build only, through the usual Bitbucket-mirror → Netlify route. **No API change, no `queue:restart`, no
migration, no env change** for AH-103b (the batch's other steps are in the same entry). **Nothing is configured in the Netlify
dashboard unless check C1 or C2 fails.**

**Post-deploy checks.** Run from any machine; no login needed. Today (before this deploy) the two `.well-known` URLs return the SPA's
HTML, so a failing C1 is the expected "before" state. Record each result in the deploy-log entry.

```bash
H=https://platform.catalyst-growth.com
A=$H/.well-known/apple-app-site-association

# C1 — the static file wins over the SPA fallback (HTTP 200, JSON, NOT index.html)
curl -sS -D - -o /tmp/aasa.json "$A" | head -12
#   expect: HTTP/2 200 ; no `location:` header
head -c 1 /tmp/aasa.json          # expect: {   (a `<` means the fallback answered: FAIL)
jq . /tmp/aasa.json >/dev/null && echo "C1 valid JSON"

# C2 — _headers is honoured (content type on that one path)
curl -sS -o /dev/null -w '%{content_type}\n' "$A"
#   expect: application/json

# C3 — no redirect on the file (Apple does not follow redirects for it)
curl -sS -o /dev/null -w '%{http_code} redirects=%{num_redirects} url_effective=%{url_effective}\n' "$A"
#   expect: 200 redirects=0 url_effective=<the same URL>

# C4 — the content is exactly what the app expects
jq -e '.applinks.details[0].appIDs[0]=="LTW9262ZXP.com.catalystgrowth.platform"
       and .applinks.details[0].components[0]["/"]=="/creator/*"
       and (.applinks.details|length)==1' /tmp/aasa.json
#   expect: true

# C5 — assetlinks.json is still NOT published (R3): the SPA fallback must still answer it
curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' "$H/.well-known/assetlinks.json"
#   expect: 200 text/html   (anything application/json here means a placeholder was published: STOP)

# C6 — the headers rule did not leak, and the site still works
curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' "$H/"                  # expect: 200 text/html
curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' "$H/sign-in"           # expect: 200 text/html
curl -sS -o /dev/null -w '%{http_code} %{content_type}\n' "$H/creator/jobs/01ZZZZZZZZZZZZZZZZZZZZZZZZ"   # expect: 200 text/html

# C7 — Apple's CDN view of the file (from memory: the URL form and its lag are unverified)
curl -sS "https://app-site-association.cdn-apple.com/a/v1/platform.catalyst-growth.com" | jq -c .
#   expect: the same JSON as C4. It can lag the origin; record when it matches.
```

**If C1 or C2 fails** (HTML or the wrong type): a Netlify dashboard rule is shadowing the file or `_headers` is not being published.
Do **not** release build 3. Report back; the fix is in the dashboard (exempt the path from the fallback rule, or set the header there),
not in the repo. **If C3 fails** (a 3xx): same, plus tell us which hop (a domain-level redirect would also break the files).
**If C5 fails:** stop, a placeholder `assetlinks.json` has been published, which R3 forbids.

**Gate for build 3:** C1–C6 pass **and** C7 matches. Associated domains are read when the app is installed or updated _(from memory)_,
so a tester who installs build 3 before the file is live will not have links until they update or reinstall. Order, again: **web
deploy → C1–C7 → build 3 to TestFlight**.

## 7. The device walk (iOS, real iPhone) — after the deploy **and** build 3

**Setup.** Build 3 installed from **TestFlight** (production APNs, associated domains from the provisioning profile; not an Xcode
install). Links sent to the phone in Notes or Messages. At least one **real emailed** job link (the job-posted email) opened from the
Mail app. Two accounts: a creator with a contracted assignment and a relationship thread; the same creator signed out. Pre-checks: C1–C7 recorded.

| #   | Do                                                                                                         | Expect                                                                                                                        |
| --- | ---------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| W1  | App in the background, signed in. Tap `https://platform.catalyst-growth.com/creator/jobs/<ulid>` in Notes. | The app opens on that job's detail. **Not Safari.**                                                                           |
| W2  | **Kill the app.** Tap `/creator/assignments/<ulid>` in Notes.                                              | Cold start lands **on that assignment**, not the dashboard, still signed in.                                                  |
| W3  | Sign out. Tap a `/creator/assignments/<ulid>` link.                                                        | Sign-in screen; after signing in you land on **that assignment** (R5). Repeat once from killed.                               |
| W4  | Tap `/creator/messages/<agencyUlid>` with an unread message in that thread.                                | The thread opens; the unread clears (AH-108 interplay: bell, tab and icon drop).                                              |
| W5  | Tap, from Notes, in turn: `/auth/verify-email?token=x`, `/campaigns/<ulid>`, `/messages/<ulid>`, `/`.      | **Each opens in Safari**, never the app (R2).                                                                                 |
| W6  | Tap `/creator/nonsense` and `/creator/jobs/not-a-ulid`.                                                    | `nonsense`: the app opens on the **dashboard**, no crash (D2). `not-a-ulid`: the job-detail not-found state, no blank screen. |
| W7  | Long-press a creator link in Notes or Messages.                                                            | The menu offers **Open in Catalyst**. (Record what it offers for `/auth/…` too: it should not.)                               |
| W8  | A real **emailed** job link, tapped in the Mail app.                                                       | Opens the app on the job. Also tap it from **Gmail or another in-app browser** if one is installed: record, do not pass/fail. |
| W9  | Regression: a push tap, and the icon badge, after the above.                                               | Push tap still lands on its screen; the icon equals the bell total.                                                           |
| W10 | App **deleted**; tap a creator link; then install and tap again.                                           | Without the app: the website opens normally. After install: W1 holds.                                                         |

**Pass:** W1–W7, W9 and W10 as expected; W8 as expected for the Mail app (the in-app-browser result is recorded, not judged).
**A fail on W1 with the deploy checks green** is most often Apple's cache (C7 not yet matching), a link typed into Safari's address
bar (which does not hand off) _(from memory)_, or an install that predates the file (reinstall). Rule those out before calling it a defect.

**Android (no verification claimed).** On the Samsung, with a debug build: `adb shell am start -a android.intent.action.VIEW -d
"https://platform.catalyst-growth.com/creator/jobs/<ulid>" com.catalystgrowth.platform` should open the job. This exercises the
handler and the manifest filter end to end. It **does not** prove App Link verification (R3), and `adb shell pm get-app-links
com.catalystgrowth.platform` is expected to show the domain as **not verified**.

## 8. What the walk cannot prove, and until when

- **Netlify serving the file, the header and no redirect:** unproven until the web deploy and checks C1–C6. Nothing in the repo can show it.
- **Apple fetching the file and associating the domain:** unproven until the file is live **and** build 3 is installed from
  TestFlight; the simulator and an Xcode install cannot show the production association _(from memory)_.
- **That the capability is on the App ID:** Pedram has said it is; Stage 2 proves it from the provisioning profile of a device build,
  and the TestFlight archive proves it for distribution.
- **Behaviour from other apps' in-app browsers, and across iOS versions:** observations only; the walk covers the one phone.
- **Android App Link verification, and anything about assetlinks:** **cannot be proven at all** until a real signing fingerprint
  exists (R3, tech-debt row). Only the handler is proven, through an explicit intent.
- **A real emailed link in every locale and mail client:** the walk uses one real email in one client.
- **Production `FRONTEND_MAIN_URL`:** confirmed by one real email (R1), not read from the server.

## 9. Tech-debt rows opened with this plan

- **Android App Links are not verified, and no `assetlinks.json` is published** (R3). Opened now in `tech-debt.md` ("AH-103b").
  Resolution: a release signing configuration (or Play App Signing enrolment), then publish the real fingerprint, then a device walk
  of the Android half.
- At close, if the walk shows it: in-app-browser hand-off behaviour; the unverified-creator-follows-a-link edge (inventory §5).

## 10. Questions for the review

All four answered by Pedram, 2026-10-19: (1) **D2 changed**: an in-scope unresolvable link opens the dashboard. (2) D3, the 3 s guard,
accepted. (3) `_headers` cache: Netlify's default stays. (4) The colleague runs C1–C7 during the deploy and sends the output to
Pedram, who forwards it so the assistant records it in the deploy log.

**Stopping here for review.**
