# AH-103 — deep links: a tap lands on the right screen — PLAN (plan-pause)

- **Status:** **Awaiting review.** No build code until this is cleared. Rulings Q1–Q9 (inventory review) and the Q8 device
  spike are already folded in.
- **Date:** 2026-10-12
- **Entry:** **AH-103** (resolver + row click + push tap + intended-route hardening + parity guards). **AH-103b** (universal /
  app links) and **AH-103c** (biometrics, its own Mode-A chunk) are out of scope here; camera and offline-grace are unscheduled.
- **HEAD:** `7d8c9166` (`docs: AH-103 inventory addendum — device spike results`), local, **not pushed**; `origin/main` is
  `cb79abfa`, pushed, **not deployed** (`docs/runbooks/deploy-log.md`). Working tree clean (the spike code is deleted).
- **Inputs:** [`deep-links-inventory.md`](deep-links-inventory.md) (cleared at `8378e4e5`) and its **§9 addendum** (the observed
  payload shapes). Inventory evidence is cited by section (`inv §…`); anything newly read for this plan is cited inline.
- **Orientation re-read at plan time:** `WORKING-PROCESS.md` (Mode A, §5.35 break-revert, §5.41 CI-green-at-tip),
  `push-notifications-plan.md` (format; §5.5 client behaviour spec), and the code paths cited below.

---

## 0. The live-data risk line

> **PROD-DATA RISK: LOW — app/SPA-side, with additive backend only.**
>
> 1. **No schema change, no migration, no new endpoint, no new route.** (Option B was chosen over the new
>    `GET /me/notifications/{ulid}` precisely so `docs/security/tenancy.md` §4 does not grow a row.)
> 2. **Backend changes are additive JSON keys**, reachable in production only through paths that already exist:
>    - `PushMessageFactory` forwards up to three more **ULID-shaped** values, only when push is armed
>      (`push_notifications_enabled` is **OFF**, nothing deployed);
>    - `campaign_ulid` is added to the `data` bag of **new** in-app rows at three emit sites (`campaign.job_posted`, the agency leg of
>      `message.received_by_agency`, `campaign_application.submitted`). No existing row is rewritten.
> 3. **The one write the feature adds is the existing idempotent `PATCH /me/notifications/{n}/read`**, called from two new places.
> 4. **Both deploy orders degrade gracefully** — old backend + new app: pushes carry no route keys, the tap falls back to the list
>    (today's behaviour); new backend + old app: the extra push keys are ignored (`v` stays `1`; inv §2.4, and the AH-102 inventory
>    already records "unknown fields are ignored by old app builds"). Neither ordering produces a dead tap.
> 5. **Privacy:** the new push keys are opaque ULIDs, the same class as `notification_ulid` already sent. The `data` bag is still
>    never forwarded; the new allowlist is names-only, per type, shape-validated, and has its own sentinel tests (§3.3).
>
> Nothing in this chunk is armed or deployed by the build.

---

## 1. Rulings → where each is baked in

| Ruling                            | What it fixes                                                                                                                                                                                  | Where it lands                                                                                 |
| --------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| **Q1** Option B                   | Additive ULID-only route keys on a **second allowlist** in `PushMessageFactory`; `campaign_ulid` on the `job_posted` bag; `v` stays `1`. Reverses the AH-102 "no backend payload change" note. | §3.2, §3.3, C1.2, C1.3; the correction note to `push-notifications-inventory.md:451-452` in §8 |
| **Q2** all 21 types               | `campaign_ulid` at the two agency emit sites; no mark-read-only agency rows                                                                                                                    | §3.1, C1.2, C2.1                                                                               |
| **Q3** (a)(c) in, (b) conditional | `?tab=` on the campaign page; a link out of the assignment not-found alert; chat anchor only if trivially cheap                                                                                | C4.2, C4.3, C4.4                                                                               |
| **Q4** destinations               | `admin_connected` → inbox; `disconnected` → fallback; `application.rejected` → jobs; `approved`/`rejected` → dashboard; thread types by **lookup only**                                        | §3.1 table, §4.3                                                                               |
| **Q5**                            | carry `redirect` through the two native lapse redirects; edit the policy specs consciously                                                                                                     | C4.1                                                                                           |
| **Q6**                            | mark-read semantics (inv §2.6), mark-read only after the guard admits the user                                                                                                                 | §4.5, C3.2, C3.3                                                                               |
| **Q7 / Q9**                       | universal links → AH-103b; biometrics → AH-103c                                                                                                                                                | §9 "not in this chunk"                                                                         |
| **Q8** spike                      | the parser is built on the observed shapes                                                                                                                                                     | §4.2 (the parser spec), inv §9                                                                 |
| **§2.7**                          | destination as a required field on `LIVE_TYPES`; a shared `{type → required keys}` fixture read by Pest and Vitest; emit-site contract tests on real emitter output                            | §3.1, §5 (the test spine), C1.1, C2.1                                                          |

---

## 2. Shape and the cut line

Stage 0 preflight → Stage 1 backend (additive keys, test-first) → Stage 2 SPA registry and resolver → Stage 3 the two entry
points (tap, row) → Stage 4 hardening and polish → **Checkpoint A** (code complete, CI green, device walk, review) → push is the
user's trigger → Stage 5 docs close. Each commit is conventional, one concern, and leaves the tree green.

```
 push tap ──► parsePushTapData(data)  ─┐
                                       ├─► resolveNotificationDestination(type, ids, subject, deps) ─► route | fallback
 row click ─► idsFromRow(row)         ─┘                 ▲
                                                         │ one registry: LIVE_TYPES[type].destination
                              fixture: {type → bag keys, push keys}  ◄── read by Pest AND Vitest
```

**What is deliberately not in this chunk:** universal/app links and the `appUrlOpen` listener (AH-103b); biometrics (AH-103c); any
camera work (inv §9.5 is observation only); offline-grace; a new notification type or channel; any change to which types push.

---

## 3. The contract

### 3.1 The shared fixture — one source of truth for "which ids does this type carry"

A JSON file read by **both** test suites. `bag` = keys the in-app row's `data` must contain (what the resolver reads);
`push` = keys the push payload must carry (⊆ `bag`; empty for types that do not push). It is a **contract**, not an example of
data: it lists names, never values.

| #     | Type                                                                                                                              | `bag`                     | `push`                    | Registry destination (route · params)                                      |
| ----- | --------------------------------------------------------------------------------------------------------------------------------- | ------------------------- | ------------------------- | -------------------------------------------------------------------------- |
| 1–6   | `assignment.invited`, `.revision_requested`, `.draft_approved`, `.draft_rejected`, `.completed_on_approval`, `.manually_verified` | `assignment_ulid`         | `assignment_ulid`         | `creator.assignment.detail` · `ulid`                                       |
| 7     | `assignment.live_verified`                                                                                                        | `assignment_ulid`         | — (never pushes)          | `creator.assignment.detail` · `ulid`                                       |
| 8     | `message.received_by_creator`                                                                                                     | `assignment_ulid`         | `assignment_ulid`         | `creator.assignment.detail` · `ulid` (chat anchor only if C4.4 is a go)    |
| 9     | `campaign_application.accepted`                                                                                                   | `assignment_ulid`         | `assignment_ulid`         | `creator.assignment.detail` · `ulid`                                       |
| 10    | `message.relationship_received_by_creator`                                                                                        | `thread_ulid`             | `thread_ulid`             | **thread lookup, creator side** → `creator.messages.thread` · `agencyUlid` |
| 11    | `campaign.job_posted`                                                                                                             | `campaign_ulid` **(new)** | `campaign_ulid` **(new)** | `creator.job.detail` · `ulid`                                              |
| 12    | `campaign_application.rejected`                                                                                                   | —                         | —                         | `creator.jobs`                                                             |
| 13–14 | `creator.approved`, `creator.rejected`                                                                                            | —                         | —                         | `creator.dashboard`                                                        |
| 15    | `agency_creator_relation.admin_connected`                                                                                         | —                         | —                         | `creator.messages` (inbox)                                                 |
| 16    | `agency_creator_relation.disconnected`                                                                                            | —                         | —                         | **fallback by design** (the list)                                          |
| 17    | `assignment.draft_submitted`                                                                                                      | `campaign_ulid`           | —                         | `campaigns.detail` · `ulid` · `?tab=drafts`                                |
| 18    | `assignment.contracted`                                                                                                           | `campaign_ulid`           | —                         | `campaigns.detail` · `ulid` · `?tab=creators`                              |
| 19    | `message.received_by_agency`                                                                                                      | `campaign_ulid` **(new)** | —                         | `campaigns.detail` · `ulid` · `?tab=messages`                              |
| 20    | `message.relationship_received_by_agency`                                                                                         | `thread_ulid`             | —                         | **thread lookup, agency side** → `messages.thread` · `creatorUlid`         |
| 21    | `campaign_application.submitted`                                                                                                  | `campaign_ulid` **(new)** | —                         | `campaigns.detail` · `ulid` · `?tab=applications`                          |

**Old rows** (rows already written by today's emitters; I read none from any database). #1–10 and #17–18 already carry their key
(inv §2.2). **#11** old rows lack `campaign_ulid` in the bag: the resolver falls back to the row's `subject.ulid` when
`subject.type === 'Campaign'` (a row-click-only path; a push carries its own key). **#19 and #21** old rows have no campaign id:
they resolve to the fallback (the list), never a dead click. The fixture's `bag` describes **new** rows; the old-row behaviour
is pinned separately (§5.3).

### 3.2 The backend changes (additive)

- **C1.2 — emit sites** (`campaign_ulid` only; nothing else changes):
  - `Campaigns/Services/JobPostedFanOutService.php:256-261` — add `'campaign_ulid' => $campaign->ulid` to the `job_posted` bag.
  - `Messaging/Services/SendMessageNotifications.php` — add `campaign_ulid` **only in `fanOutToAgency()`** (`:100-116`), not to the
    shared `$data` (`:62-66`), so creator-bound rows are byte-identical to today.
  - `Campaigns/Services/CampaignApplicationNotifier.php:112-120` — add `'campaign_ulid' => $campaign->ulid` to
    `campaign_application.submitted`.
- **C1.3 — the second allowlist in `PushMessageFactory`:** `ROUTE_KEYS_BY_TYPE` — a map from push-eligible type to the route
  key(s) it may forward, mirroring the fixture's `push` column exactly. A value is forwarded **only if** the key is listed for that
  type **and** the value is a string matching the ULID shape (case-insensitive, 26 Crockford-base32 characters; Stage 0 confirms
  against the codebase's `HasUlids` lowercase form). Otherwise it is dropped silently (the push still sends, the tap falls back).
  Per-type, not a flat three-key list, because a flat list would forward `campaign_ulid` on `message.received_by_creator` if a
  later change added it to the shared bag.
- **No change** to `PushDispatcher` (it already hands the factory the `data` bag), `FcmPushSender` (it already stringifies,
  `:157`), `PushMessage`, the sender interface, `NotificationService::notify()`, the preferences, or any route.

### 3.3 Privacy: the "never forward the bag" rule stays structural

The factory header (`PushMessageFactory.php:12-22`) currently promises `data = {type, v, notification_ulid?}` and "reads exactly four
named keys". Both sentences change, consciously (§5.4). The structural guarantees after C1.3:

1. Two allowlists, both named constants: `NAME_KEYS` (placeholder names, unchanged) and `ROUTE_KEYS_BY_TYPE` (this chunk). There is
   still no code path that copies the bag.
2. **Sentinel tests**, one per rule: (a) a free-text value placed under a route key (e.g. `'assignment_ulid' => 'SENTINEL-FEEDBACK'`)
   does not reach the payload; (b) a route key not listed for that type (`campaign_ulid` on `message.received_by_creator`) does not
   reach the payload; (c) every other bag key (`feedback`, `rejection_reason`, `body`, `creator_name`) still never reaches it; (d) the
   payload's key set is exactly `{type, v, notification_ulid?} ∪ ROUTE_KEYS_BY_TYPE[type]` for every eligible type, derived from
   the fixture.
3. `PushMessage::$data`'s docblock (`:22`) already says "small scalar payload (type, ulid, version) — never user content"; it
   stays true.

---

## 4. The client behaviour spec

### 4.1 The registry (Stage 2)

`LiveNotificationType` (`modules/notifications/templates.ts:78`) gains a **required** `destination` field. A required property is
the one-source-of-truth mechanism `recipient` and `preference` already use: `vue-tsc` fails a new live type that omits it. Three
variants, shape only:

- a **route** destination: a route name, the params (each param names which id key supplies it), an optional static `query`, and an
  optional declared `subject` fallback (only #11 uses it);
- a **thread** destination: `side: 'creator' | 'agency'`, resolved by lookup (§4.3);
- an explicit **list** destination (#16) — a positive assertion, like `preference: null`: "no destination, on purpose".

### 4.2 The parser (built on inv §9, not assumed)

`parsePushTapData(data: unknown): { type: string; notificationUlid?: string; ids: Partial<Record<RouteIdKey, string>> } | null`. It
encodes the six observed requirements (inv §9.4):

1. Input is **only** `event.notification.data` (the client extracts it; the tap event has no `title`/`body` on Android).
2. Reads **only** `type`, `notification_ulid`, `assignment_ulid`, `campaign_ulid`, `thread_ulid` (and `v`, below); **ignores every other
   key** — the observed extras include numbers (`google.ttl`, `google.sent_time`, `google.product_id`), a nested object (`aps`) and a
   stringified Bundle (`gcm.n.analytics_data`).
3. Accepts a value only if `typeof === 'string'` and non-empty; **ULID keys must additionally match the ULID shape** (the spike used
   fixed fake strings, so shape validation was not exercised on a device).
4. `v` is a **string** (`"1"`): the parser compares `Number(v)`; an absent or unparseable `v` is treated as `1`. A `v` greater than the build knows → route keys are still read (additive contract),
   but this is called out as a plan question (P-Q3).
5. A missing/ill-typed `type` → `null` → the fallback. The parser never throws.
6. An unknown `type` string is **not** an error: the resolver returns the fallback (today's AH-102 behaviour).

### 4.3 The resolver (Stage 2)

`resolveNotificationDestination(input, deps): Promise<Resolution>`, `Resolution = { kind: 'route', to } | { kind: 'fallback' }`. Pure
except for the injected `deps`:

- **Route destinations:** every required id present and ULID-shaped → `{ name, params, query }`; `router.hasRoute(name)` must be true
  (defence against a renamed route) → else fallback.
- **#11 only:** `ids.campaign_ulid ?? (subject.type === 'Campaign' ? subject.ulid : undefined)`.
- **Thread destinations (#10, #20):** `deps.lookupThread(side, thread_ulid)` — creator: `relationshipMessagingApi.creatorInbox()`, find
  `id === thread_ulid`, take `attributes.agency.id`; agency: `agencyInbox(useAgencyStore().currentAgencyId)`, take
  `attributes.creator.id`. The inbox is **unpaginated** (`->get()`, `CreatorRelationshipMessageController.php:58-73`), so "not in the
  response" is a real "gone"; no thread → fallback; any error (offline, 401, 5xx) → fallback. **No `agency_ulid` is added** (Q4).
- **No session** (the auth store's user is `null`) → the lookup is **skipped** and the resolution is the fallback; `requireAuth` then
  sends the user to sign-in with `redirect=` the list. That is a **named degradation** for the two thread types only (P-Q2): a
  signed-out thread tap lands on the list after sign-in, not on the thread.
- **Unknown type, `null` parse, missing id, malformed id, thrown error** → fallback. The resolver **never rejects**.

### 4.4 The push tap (Stage 3)

The tap seam is `void`-typed end to end (inv §2.8); the data must travel through it:

| File                                                | Change                                                                                                                                                                                                                                                                                                                                                               |
| --------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `core/platform/push/pushClient.ts:43,99-106`        | `PushClient.onNotificationTapped: PushSubscription<unknown>`; the capacitor listener hands over **`event.notification.data`** (nothing else)                                                                                                                                                                                                                         |
| `core/platform/push/pushRegistration.ts:77,249-253` | `PushHandlers.onTap(data: unknown)`; passed through unchanged                                                                                                                                                                                                                                                                                                        |
| `core/platform/push/pushTapRouting.ts`              | `createPushTapHandler(router, deps)` returns `(data: unknown) => void`: `await router.isReady()` → `parsePushTapData` → resolver → `router.push(route)` or `router.push({ name: 'creator.notifications' })`; the whole chain is wrapped so it can **never** throw or leave the user on a blank screen; a second tap while a lookup is in flight supersedes the first |
| `main.ts:56-61`                                     | injects the resolver deps and the mark-read callback beside the router (only the wiring layer touches singletons)                                                                                                                                                                                                                                                    |

The `isReady()` gate **stays** as is (the observed cold-start order: data arrives with the route still `/`, on both OSes).

### 4.5 Mark-as-read (Q6, inv §2.6)

- **Row click:** the existing optimistic flip and `PATCH …/read` start immediately; **navigation never waits on the PATCH, and a failed
  PATCH never blocks it** (the existing local rollback stays). A **read** row navigates with no PATCH.
- **Push tap:** if `notification_ulid` is present: after `router.push` has settled **and** the landed route is not an auth redirect
  (`auth.sign-in` / `error.auth-bootstrap`) **and** a session exists → fire-and-forget `markRead(ulid)` + `requestNotificationsRefresh()`;
  a 404 or any error is swallowed. No ulid (muted in-app) → nothing to mark. A signed-out tap marks nothing.
- This ordering is why mark-read cannot trip the native 401 policy before sign-in (`core/api/index.ts:191-209`).

### 4.6 Row click (Stage 3)

`NotificationCenter.vue:138-152` (`onRowClick`) calls the same resolver through a small composable, then `router.push`. It is the one
component for three mounts (agency dropdown, agency page, creator page; web and native), so **agency users on the web get working rows
too**. The bell dropdown already closes on route change (`NotificationBell.vue:40-55`). The header comment "D-7 deferred" (`:19-20`) is
rewritten. A `row` whose resolution is the fallback marks read and **stays** (the user is already on the list).

### 4.7 Hardening and polish (Stage 4)

- **C4.1 — `redirect` through the two native lapse redirects** (`core/api/index.ts:204-207`, `main.ts:45-47`): one helper builds
  `{ name: 'auth.sign-in', query: { reason: 'session_expired', redirect: <current fullPath> } }`, and **omits `redirect`** when the
  current route is already an auth/error route or `/` (so the existing `SignInPage` rule — it ignores `/` — and no redirect loop).
  `SignInPage.vue:186-190` already honours it. Policy specs are edited consciously (§5.4).
- **C4.2 — `?tab=` on `CampaignDetailPage`** (`:237`): initial `tab` from `route.query.tab` when it is one of the eight real tab values
  (`overview, attachments, creators, board, drafts, applications, payments, messages`, from the `<v-tab>`s at `:497-521`), else
  `overview`; a `watch` on `route.query.tab` handles a second tap on the same campaign. No tab → query writeback (not needed).
- **C4.3 — a link out of the assignment not-found alert** (`CreatorAssignmentDetailPage.vue:1079-1086`): a button to
  `creator.notifications`. One new i18n key, translated for **all** SPA locales under the same process AH-102 used (P-Q8).
- **C4.4 — chat anchor (conditional):** `?focus=messages` scrolls the `ChatPanel` (`:622`) into view once the page has loaded. **Go
  only if** it is ≤ ~15 lines plus one spec after C4.2 exists; the go/no-go is recorded in the review. If no-go, #8 lands at the top
  of the page and the registry carries no `query` for it.

---

## 5. The test spine (accepted, §2.7) — and what each test is for

### 5.1 Backend (Pest)

1. **Fixture-parity test:** the set of types in the fixture == the live types the SPA registry covers (read from
   `templates.ts` source, the `templates.spec.ts` precedent) == the backend's notification types minus the explicit deferred list.
2. **Emit-site contract test — real emitter output.** A data-driven test, **one case per fixture type**, each driving the **real**
   emit path (the same entry points the existing feature tests use: the assignment state machine, the message services, the
   application notifier, the job-posted fan-out, the admin controllers) and asserting the **persisted** notification's `data` contains
   every `bag` key for that type, as a ULID-shaped string. A **meta-assertion** requires the case list to equal the fixture's type list,
   so a new type or a new fixture row fails until a driver exists. One extra case asserts the **creator leg** of
   `SendMessageNotifications` writes a bag byte-identical to today's (no `campaign_ulid`), pinning R8. This is the AH-102b §9 rule applied: the test reads what the system
   produced, not a literal the test author typed. Today's gap (inv §2.5: `thread_ulid`, `campaign_ulid` and several `assignment_ulid`
   sites unpinned) closes here.
3. **Factory tests (C1.3):** for every push-eligible type, the payload's key set is exactly `{type, v, notification_ulid?} ∪ push keys`
   from the fixture; the §3.3 sentinels (a)–(d); a malformed value under a listed key is dropped; a `v` of `1`.
4. **Dispatcher/collapse:** unchanged tests keep passing; one new case confirms the route keys ride a real dispatch end to end with
   the fake sender.

### 5.2 SPA (Vitest)

1. **Registry parity spec** (extends `templates.spec.ts`): every live type has a destination; each destination's id keys equal the
   fixture's `bag` for that type; the fixture's `push` keys ⊆ `bag`; **recipient parity** — a creator-recipient type never points at a
   `requireAgencyUser` route and vice versa (`agency-routes-agency-user-guard.spec.ts` already pins the agency set).
2. **Route-table spec:** every route name a destination references exists in the real route table, every required `:param` is supplied
   by the declared ids, every `?tab=` value is a real tab.
3. **Resolver specs:** each of the 21 types given a bag built **from the fixture's required keys** resolves to the expected
   location; every failure mode in §4.3 resolves to the fallback and never throws; the #11 `subject` fallback; the #19/#21 old-row
   fallback; the thread lookup (found / not found / offline / 401 / no session).
4. **Parser spec — fed the observed shapes.** The two real device payloads from inv §9 (iOS with `aps` and `google.c.*`; Android with
   numeric `google.*` and the Bundle string), transcribed as test inputs with a comment citing the addendum, plus the hostile cases
   (not an object, `data` missing, numbers where strings belong, a non-ULID under a ULID key).
5. **Tap handler spec:** `isReady()` ordering preserved (the existing "does not push while the first navigation is in flight" test),
   the data reaches the resolver, fallback on every error, supersede-in-flight, mark-read only after a settled non-auth landing.
6. **Row click, campaign tab, not-found link, redirect helper** specs as named in §4.

### 5.3 How the chain is honest about its weak link

The SPA resolver is tested against bags built from the **fixture**; the backend contract test proves the **emitter ⊇ fixture**. Together
they cover the producer→consumer chain _if_ the fixture is right, and both suites fail if either side drifts from it. They do **not**
prove a real row on a real device resolves to the real screen — that is the **device walk** (§7), and the plan treats it as the
acceptance, not an afterthought.

### 5.4 Conscious edits to existing tests (the only ones allowed)

| Test                                                                                                                                                  | Edit                                                                                                        | Why                       |
| ----------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- | ------------------------- |
| `PushMessageFactoryTest.php` payload-shape assertions (and the `round` fixture key at `:42-45`, which the emitter never writes — it writes `version`) | the expected `data` keys now include the per-type route keys; fixture keys corrected to what emitters write | the second allowlist (Q1) |
| `PushDispatcherTest.php` `data` expectations                                                                                                          | as above                                                                                                    | same                      |
| `pushTapRouting.spec.ts`, `pushRegistration.spec.ts:401`, `startPush.spec.ts`                                                                         | `onTap` now receives the data; the unchanged `isReady()` test stays                                         | §4.4                      |
| `NotificationCenter.spec.ts:171` ("clicking an already-read row does nothing")                                                                        | a read row now navigates; the **no-PATCH** assertions stay                                                  | §4.5                      |
| native-policy specs (`core/api` index spec), the `main.ts` lapse wiring spec if present                                                               | the pushed location now carries `redirect`                                                                  | Q5                        |
| `templates.spec.ts`                                                                                                                                   | the new required field and the fixture parity                                                               | §2.7                      |

**Break-revert (§5.35)** — recorded with failing counts in the review, each reverted after: delete one type's `destination` → registry
spec + `vue-tsc` fail; rename a referenced route → the route-table spec fails; drop `thread_ulid` from an emitter → the Pest contract
test fails; put a free-text value under a route key → sentinel (a) fails; forward `campaign_ulid` on a creator message → sentinel (b)
fails; drop the `isReady()` gate → the existing ordering test fails; delete the fixture row for one type → the parity test fails.

---

## 6. Stage plan

### Stage 0 — preflight and riders (C0.1, no commit unless a doc changes)

- Confirm the tree is clean, `HEAD` is `7d8c9166` or descendant, CI at the tip green (§5.41); tally the `admin-sign-in:69` rider.
- **Verify, before depending on, each "Not verified" item in §10 that is cheap to verify:** the ULID shape/case of real values in this
  codebase; `subject.type` for `campaign.job_posted` is `Campaign` (`class_basename`, `NotificationResource.php:71-85`);
  `useAgencyStore().currentAgencyId` is the accessor; the existing payload-shape assertions in `PushMessageFactoryTest` (what exactly
  they pin).
- Confirm the rider from the addendum: **the spike code is gone** (`git status` clean, no `spike` strings in the bundle).

### Stage 1 — backend: the keys, test-first

- **C1.1** the shared fixture file + the fixture-parity test (§5.1.1). Green on its own: it checks the type set, not the keys.
- **C1.2** emit-site `campaign_ulid` at the three sites **and** the emit-site contract test in the **same** commit (a contract test
  committed before the keys exist would be a red commit on `main`). The §5 break-revert is what proves the contract test can fail
  (§3.2, §5.1.2).
- **C1.3** the `ROUTE_KEYS_BY_TYPE` allowlist, ULID-shape filter, the sentinels, the conscious test edits (§3.2, §3.3, §5.1.3).
- Gate: Pint + Larastan + Pest, full.

### Stage 2 — SPA: the registry and the resolver

- **C2.1** `destination` required on all 21 + registry parity + route-table specs (§4.1, §5.2.1–2).
- **C2.2** the pure parser and resolver, `lookupThread`, and specs (§4.2, §4.3, §5.2.3–4).
- Gate: lint + typecheck + unit, full.

### Stage 3 — the two entry points

- **C3.1** the tap seam carries the data (client, registration, startPush) + specs.
- **C3.2** the push tap handler uses the parser and resolver, fallback everywhere, mark-read after a settled non-auth landing (§4.4, §4.5).
- **C3.3** `NotificationCenter` row click: resolve, navigate, mark read (§4.5, §4.6); the "D-7 deferred" comment rewritten.

### Stage 4 — hardening and polish

- **C4.1** `redirect` through the two native lapse redirects (§4.7). **C4.2** `?tab=`. **C4.3** the not-found link + i18n. **C4.4** the chat
  anchor, conditional (go/no-go recorded).

### Checkpoint A — stops for clearance

Code complete, full gates green locally, CI green at the pushed tip is the user's trigger (§5.41). **Pushes are held.** The device walk
(§7) runs **before** the push, as AH-102's did, because the unobserved risk class here is device-only.

### Stage 5 — close (docs)

§8, after the walk and the push.

---

## 7. Device acceptance (the chunk's acceptance) — real emits, both devices

**Setup:** the AH-102 walk setup (`push-notifications-device-walk.md` §1, §7), with the flag armed **locally** and the queue worker
running so pushes come from **real emits**, not `push:test`; the Samsung over `adb reverse`, the iPhone over LAN; an agency user and
a creator user so both recipients can be exercised. (The spike build used fixed fake ULIDs; this walk uses the real chain.)

| #   | Case                                                                                                      | Expected                                                                                          |
| --- | --------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| W1  | Real `assignment.invited` push tapped: foreground (row), background, killed                               | lands on that assignment's detail, all three; row click lands there too                           |
| W2  | Real `message.received_by_creator`                                                                        | lands on the assignment (and the chat if C4.4 shipped)                                            |
| W3  | Real `message.relationship_received_by_creator` (thread type)                                             | lands on the right thread via lookup                                                              |
| W4  | Real `campaign.job_posted` push; and an **old** `job_posted` row (no `campaign_ulid`) clicked in the list | job detail both ways                                                                              |
| W5  | `creator.approved` / `admin_connected` / `disconnected`                                                   | dashboard / inbox / the list                                                                      |
| W6  | Muted-in-app, push on: a push for an id-bearing type                                                      | still lands on the screen (the case Q1 was chosen for)                                            |
| W7  | Signed-out tap, then sign in                                                                              | lands on the target after sign-in (route types); the list for thread types (the P-Q2 degradation) |
| W8  | Token revoked server-side, warm tap                                                                       | sign-in, then the **target** after sign-in (Q5)                                                   |
| W9  | Subject gone (soft-delete the campaign/assignment), then tap                                              | the destination's own not-found state, with a link back to the list (C4.3)                        |
| W10 | Agency web: bell row → campaign detail on the right tab (`drafts`, `applications`)                        | right tab                                                                                         |
| W11 | A push for a type with `bare` payload while backgrounded                                                  | the list (today's behaviour), never a dead tap                                                    |

Each row is recorded with its evidence in the review; anything that fails is a finding, not a footnote.

---

## 8. Docs that move with the build

- `docs/reviews/deep-links-review.md` — the chunk review (evidence, break-reverts, walk results, CI tally).
- **A dated correction note** at `push-notifications-inventory.md:451-452` ("AH-103 needs **no** backend payload migration"), pointing at
  Q1 of this chunk — a reversed statement should not sit unmarked.
- `docs/runbooks/deploy-log.md` — an AH-103 entry, `PENDING`, with the deploy-order note (§0.4: either order degrades gracefully) and
  no flag change.
- `docs/tech-debt.md` — the two camera observations (inv §9.5: Android's picker offers no camera entry; iOS Take Photo exits the app,
  cause undiagnosed), unscheduled; and the P-Q2 thread-signed-out degradation if accepted.
- `docs/reviews/adhoc-changes-log.md` and `docs/reviews/RESUMPTION-TEMPLATE.md` (the two files AH-102b's close touched), and the working-process entries a closed chunk requires.
- **Not touched:** `docs/security/tenancy.md` §4 (no new route), `apps/api` routes, the preferences contract, any lang file on the backend.

---

## 9. Risk register

| #   | Risk                                                                        | Likelihood           | Containment                                                                                  |
| --- | --------------------------------------------------------------------------- | -------------------- | -------------------------------------------------------------------------------------------- |
| R1  | The resolver sends a tap to the wrong screen                                | low                  | the fixture + both suites; the 21-case contract; the device walk W1–W11 on real emits        |
| R2  | A dead tap (blank/crash)                                                    | low                  | every failure resolves to the fallback; the handler cannot throw; hostile-input parser specs |
| R3  | A route key leaks free text to a lock screen                                | very low             | per-type allowlist + ULID-shape filter + sentinels (a)–(d)                                   |
| R4  | Cold start races the router again                                           | low                  | the `isReady()` gate is unchanged and its test kept; the walk's killed cases W1/W3           |
| R5  | A thread tap while signed out lands on the list                             | certain, by design   | named degradation (P-Q2), shown in W7                                                        |
| R6  | A renamed route silently breaks a destination                               | low                  | route-table spec + `router.hasRoute` guard                                                   |
| R7  | Old rows resolve wrongly                                                    | low                  | #11 `subject` fallback and #19/#21 fallback are pinned; no old row is rewritten              |
| R8  | Adding `campaign_ulid` to the agency message leg changes a row creators see | none by construction | added only in `fanOutToAgency`; a test asserts the creator leg's bag is unchanged            |
| R9  | CI instability (the `ubuntu-latest` → 26 migration lands 2026-10-19)        | medium, not ours     | read the first post-migration CI run with that in mind (inv §6)                              |
| R10 | iOS verified on a debug install (sandbox APNs) only                         | medium               | the TestFlight pass (AH-102's open item) covers production APNs; named in §10                |

---

## 10. Plan-review questions

**P-Q1 — Where does the shared fixture live?** It must be readable by Pest (`apps/api`) and Vitest (`apps/main`). **Recommend**
`packages/api-client/contracts/notification-route-keys.json` (the package that already carries the shared API types; Pest reads it by
repo-relative path, like `LangParityTest.php:32` reads `lang`). Alternative: `docs/`, which I advise against (a doc is not a contract).

**P-Q2 — Accept the named degradation for the two thread types when signed out?** The lookup needs a session; a signed-out thread tap
lands on the list after sign-in. **Recommend accept** (the alternative — persisting the thread intent across sign-in — is a new
mechanism for a case that needs both a signed-out device and a message push).

**P-Q3 — `v` handling.** Absent or unparseable → treat as `1`; **greater than known → still read the route keys** (the contract is
additive). **Recommend as written**; the alternative (fallback on an unknown `v`) would make a future additive bump a regression for
old builds.

**P-Q4 — Chat anchor (C4.4).** Go/no-go rule: ≤ ~15 lines + one spec once `?tab=` exists. **Recommend the rule**, decided at build time.

**P-Q5 — Per-type push route keys (a map) rather than a flat three-key list.** **Recommend per-type** (§3.2); the cost is one more
constant mirrored by the fixture.

**P-Q6 — Camera observations → `tech-debt.md`, unscheduled.** **Recommend yes**, with the iOS exit labelled "cause undiagnosed (no crash
log)".

**P-Q7 — Web E2E.** One Playwright spec for the web row click (agency bell → campaign detail on the right tab) is runnable in CI; the native
paths are device-only. **Recommend one spec**, in C3.3, and no E2E for native.

**P-Q8 — Locales for the not-found link (C4.3).** One key, all SPA locales, under the AH-102 process (including the locales that
needed real MT). **Recommend that process**, named in the commit.

**P-Q9 — Device walk before the push.** **Recommend yes** (§6 Checkpoint A, §7); real emits need the local flag armed and the queue
worker running, as in the AH-102 walk.

---

## Not verified (carried and new)

- **ULID shape in this codebase** (case, length) — Stage 0 confirms against real values; the parser and the factory filter depend on it.
- **`subject.type === 'Campaign'` for `campaign.job_posted`** — read from `JobPostedFanOutService.php:251-262` (`subject: $campaign`), not
  executed.
- **`useAgencyStore().currentAgencyId`** as the agency id for `agencyInbox` — `AgencyMessagesPage.vue:82` and
  `AgencyRelationshipThreadPage.vue:73` use an `agencyId`; Stage 0 reads the exact accessor.
- **What `PushMessageFactoryTest` / `PushDispatcherTest` currently pin about payload shape** — only the `round`/`version` mismatch
  (`:42-45`) was read; Stage 0 reads the assertions so the §5.4 edits are complete.
- **Android `bare`, TestFlight/production APNs, a muted-in-app push, other OS versions, a real emit** were not observed in the spike
  (inv §9.6).
- **The walk setup for real emits** (local flag armed, worker) is carried from the AH-102 walk doc, not re-run here.
- **Nothing in this plan was built or run.** Every behavioural claim is from code read at `7d8c9166` or from the spike observations in
  inv §9.

_Provenance: drafted by Cursor at AH-103 plan time (2026-10-12) from the tree at `7d8c9166`._
