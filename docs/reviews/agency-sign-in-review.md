# Agency sign-in — one component, parameterized (AH-086)

- **Status: Closed — approved.** Compact full loop — kickoff with locked decisions (D1–D4) →
  brief plan-pause (mechanism ratified: route-name-driven variant on the existing page, mirroring
  `AuthLayout.vue`'s own `isHero` pattern) → build → two-commit pair → **pushed**, together with
  the [AH-086b addendum](#8-addendum-ah-086b--team-invite-email-door) that rides the same push.
- **Date:** 2026-08-27 (base chunk and addendum, same day).
- **Provenance:** kickoff (D1–D4) locked by Pedram; mechanism resolved at plan-pause per the
  kickoff's explicit delegation ("mechanism yours at plan-pause"); built by Cursor. **Closed by
  Pedram**, verdict: D1–D4 verified as built; the route-name mechanism ratified, with its
  query-param-rejection reasoning ([§2](#2-d1--the-mechanism)) standing as the record; the
  door-not-a-wall dispatch pin ([§4](#4-d3--a-door-not-a-wall)) accepted; the named E2E gap
  ([§5](#5-d4--i18n-and-e2ce-scope), [§6](#6-gate-board)) resolved by the AH-086b addendum's full
  Playwright run ([§8](#8-addendum-ah-086b--team-invite-email-door)).
- **Evidence base:** `AuthLayout.vue`'s existing `isHero = route.name === 'auth.sign-in'`
  computed — the direct precedent for driving a shared component's presentation off `route.name`
  without a prop or a second file; `NotificationsPage.vue`'s two-route-one-component pattern
  (`notifications` / `creator.notifications`) as the house's other shared-component-across-routes
  precedent.
- **PROD-DATA RISK: NONE, as declared at kickoff.** Presentational — no auth/session/MFA logic
  touched, no gate or route-guard change, the existing `/sign-in` flow is behaviorally
  byte-identical for everyone. See [§2](#2-d2--scope-fences).

---

## 1. What shipped, against the kickoff's D1–D4

| Decision | Asked                                                                                                                                                                                                                                   | Shipped                                                                                                                                                                                                                                    |
| -------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **D1**   | One component, parameterized — never a fork. `/agency/sign-in` renders the exact existing sign-in UI (layout, card, aurora) with an agency-facing heading and no creator-signup CTA; same form, same endpoint, same role-based routing. | Held. A second route record (`auth.agency-sign-in`) pointing at the SAME `SignInPage.vue`, distinguished by `route.name` — the identical mechanism `AuthLayout.vue` already used for `isHero`. See [§2](#2-d1--the-mechanism).             |
| **D2**   | Scope fences: no signup flow of any kind; no change to `/sign-in` for anyone; no auth/session/MFA logic touched; team-invite emails keep their existing link target (a one-line follow-up option, not built).                           | Held. See [§3](#3-d2--scope-fences).                                                                                                                                                                                                       |
| **D3**   | The variant is honest about what it is — a creator signing in through the agency page still works, same endpoint, role routing takes them home. Stated as a door, not a wall.                                                           | Held — pinned by its own Vitest case. See [§4](#4-d3--a-door-not-a-wall).                                                                                                                                                                  |
| **D4**   | i18n: heading/copy keys ×24, flaky-10 real MT. E2E: propose whether the variant gets its own leg or a Vitest case.                                                                                                                      | Held — 1 new key, all 24 locales, real per-locale MT (not English fallback) in the flaky 10. No new Playwright leg — Vitest carries it, per the AH-082 precedent for non-trust-boundary UI variants. See [§5](#5-d4--i18n-and-e2ce-scope). |

---

## 2. D1 — the mechanism

**One component, two route records, distinguished by `route.name` — never a fork.**

```69:94:apps/main/src/modules/auth/routes.ts
export const authRoutes: RouteRecordRaw[] = [
  // Sign-in / sign-up — guests only. requireGuest redirects authenticated
  // users to the dashboard.
  {
    path: '/sign-in',
    name: 'auth.sign-in',
    component: () => import('./pages/SignInPage.vue'),
    meta: { layout: 'auth', guards: ['requireGuest'] },
  },
  // Agency-facing variant of the SAME sign-in page (AH-086) — one
  // component, parameterized by route name, never a fork. SignInPage.vue
  // reads `route.name` (the same mechanism AuthLayout already uses for
  // `isHero`) to swap the heading copy and drop the creator-signup CTA;
  // everything else — the form, the `POST /auth/login` call, the
  // role-based postLoginTarget() dispatch, the layout/card/aurora chrome —
  // is byte-identical. Agencies are Catalyst-created (no signup flow
  // exists or is added here), so this route carries the same
  // `requireGuest` guard and nothing more. A creator who lands here still
  // signs in successfully and is routed home by role — this is a door,
  // not a wall.
  {
    path: '/agency/sign-in',
    name: 'auth.agency-sign-in',
    component: () => import('./pages/SignInPage.vue'),
    meta: { layout: 'auth', guards: ['requireGuest'] },
  },
```

`SignInPage.vue` gains one computed and reuses it in exactly two places — the heading and the
creator-signup link:

```45:56:apps/main/src/modules/auth/pages/SignInPage.vue
const isAgencyVariant = computed(() => route.name === 'auth.agency-sign-in')

const headingKey = computed(() =>
  isAgencyVariant.value ? 'auth.ui.headings.agency_sign_in' : 'auth.ui.headings.sign_in',
)
```

`AuthLayout.vue`'s `isHero` gains the second route name, so the hero/card/aurora chrome is
identical on both routes — no stripped-down "agency mode" layout exists:

```32:34:apps/main/src/modules/auth/layouts/AuthLayout.vue
const isHero = computed(() => route.name === 'auth.sign-in' || route.name === 'auth.agency-sign-in')
```

**Why this mechanism, not a prop or a query param.** The kickoff left the mechanism open
("route prop / query-driven variant... whatever keeps one source of truth"). A query param
(`?variant=agency`) was rejected because it would let a stray query string quietly flip a
production sign-in page's copy on the canonical `/sign-in` URL — a route NAME carries no such
risk and is guard-scoped independently. A boolean prop was rejected because nothing in this route
table passes props to page components today (`component: () => import(...)`, no `props: true`
anywhere in `routes.ts`), and introducing that plumbing for one boolean would be more surface than
the `route.name` read `AuthLayout.vue` already uses one line above. Reading `route.name` inside
the page itself — the same read `AuthLayout.vue` performs for `isHero` — is the smallest possible
diff and the one place future auth changes (the form, the endpoint, `postLoginTarget()`) cannot
drift between two files, because there is only one file.

**Data-test IDs are untouched and shared.** `sign-in-page`, `sign-in-heading`,
`sign-in-signup-link`, `sign-in-forgot-link`, `sign-in-email`, `sign-in-password`,
`sign-in-submit`, `sign-in-error` — identical on both routes. Nothing in this chunk introduces a
second naming scheme.

---

## 3. D2 — scope fences

- **No signup flow of any kind.** No `/agency/sign-up` route, no signup form change, no backend
  endpoint. Agencies remain Catalyst-created — recorded here as the deliberate boundary, not a
  gap. The removed link ([§2](#2-d1--the-mechanism), `v-if="!isAgencyVariant"`) is the ONLY signup
  surface this chunk touches, and it touches it by hiding it, not building one.
- **No change to `/sign-in` for anyone.** `route.name === 'auth.sign-in'` still resolves
  `isAgencyVariant` to `false` and `headingKey` to `'auth.ui.headings.sign_in'` — the exact
  pre-existing key, exact pre-existing string ("Sign in"). Pinned by
  `SignInPage.spec.ts`'s pre-existing heading test (untouched) plus the new
  `'default /sign-in still shows the creator-signup CTA (zero diff for the existing page)'` case.
  This is a **behavioral** zero-diff claim, not a file-level one (`SignInPage.vue` and
  `AuthLayout.vue` both have real diffs, shown in [§2](#2-d1--the-mechanism)) — the honest
  distinction from AH-082's literal `git diff --stat` proof, since a shared-component approach by
  definition touches the shared file. What's proven instead is that every branch the new code adds
  evaluates to the pre-existing behavior on the pre-existing route name.
- **No auth/session/MFA logic touched.** `onSubmit()`, `postLoginTarget()`, the `useAuthStore`
  login call, the TOTP reveal, the error-resolution path — none of them read `isAgencyVariant` or
  `route.name` beyond the two things named in D1. `git diff` on `useAuthStore.ts` and
  `packages/api-client/src/auth.ts` is empty (neither file was touched).
- **Team-invite emails keep their existing link target — named, not fixed.**
  `AgencyInvitationService::buildAcceptUrl()` still mints `/accept-invitation?token=…&agency=…`,
  and `AcceptInvitationPage.vue`'s sign-in CTA still resolves `{ name: 'auth.sign-in' }` — the
  default page, not the new agency variant:

  ```213:221:apps/main/src/modules/agency-users/pages/AcceptInvitationPage.vue
        <v-btn
          color="primary"
          block
          class="mb-3"
          :to="{ name: 'auth.sign-in', query: { redirect: redirectParam } }"
          data-test="accept-sign-in-btn"
        >
          {{ t('app.invitation.accept.signIn') }}
        </v-btn>
  ```

  **Flagged as an option, not built:** retargeting that button (and the mailable's own copy) to
  `auth.agency-sign-in` is a one-line follow-up IF Pedram asks for it — no schema, no backend
  change, just the route name in this one template and the corresponding mail view. Not done this
  chunk because the kickoff named it as Pedram's call, not a default.

---

## 4. D3 — a door, not a wall

The kickoff's honesty requirement: if a creator signs in through `/agency/sign-in`, it must work,
via the same endpoint and the same role-based routing that already exists. Nothing in this chunk
could break that even by accident, because `onSubmit()` and `postLoginTarget()` never read
`isAgencyVariant` — but it's proven, not just asserted, by two new Vitest cases that reuse the
exact fixtures the pre-existing dispatch tests use:

```121:169:apps/main/src/modules/auth/pages/SignInPage.spec.ts
  it('agency variant: a door, not a wall — same endpoint, login still calls authApi.login() and role-routes home', async () => {
    vi.mocked(authApi.login).mockResolvedValue(USER)
    const h = await mountAuthPage(SignInPage, {
      initialRoute: { name: 'auth.agency-sign-in' },
    })
    // ... submits the form, asserts authApi.login() called with the same
    // payload shape, asserts router.push('/') — the agency_user dispatch.
  })

  it('agency variant: a creator who signs in here still routes to onboarding.welcome-back — the page is a door, not a wall', async () => {
    vi.mocked(authApi.login).mockResolvedValue(CREATOR_USER)
    // ... same form, same submit, asserts router.push({ name: 'onboarding.welcome-back' }) —
    // the exact creator dispatch the default /sign-in page already has.
  })
```

Both cases mount the page at `auth.agency-sign-in` and assert the SAME `authApi.login()` call
shape and the SAME `postLoginTarget()` outcomes the default-route tests already pin — proof that
the agency variant is a presentation change riding on unchanged behavior, not a second
implementation of either.

---

## 5. D4 — i18n and E2E scope

**i18n — one new key, all 24 locales, real MT.** `auth.ui.headings.agency_sign_in`, inserted
immediately after the existing `sign_in` key in every locale's `auth.json` (24 files, 1 line each,
`git diff --stat`: `24 files changed, 24 insertions(+)`):

| Locale | Value                             |
| ------ | --------------------------------- |
| en     | Agency sign in                    |
| bg     | Вход за агенции                   |
| cs     | Přihlášení agentury               |
| da     | Bureau-login                      |
| de     | Agentur-Anmeldung                 |
| el     | Σύνδεση πρακτορείου               |
| es     | Inicio de sesión de agencia       |
| et     | Agentuuri sisselogimine           |
| fi     | Agentuurin kirjautuminen          |
| fr     | Connexion agence                  |
| ga     | Logáil isteach na gníomhaireachta |
| hr     | Prijava agencije                  |
| hu     | Ügynökségi bejelentkezés          |
| it     | Accesso agenzia                   |
| lt     | Agentūros prisijungimas           |
| lv     | Aģentūras pieteikšanās            |
| mt     | Il-login tal-aġenzija             |
| nl     | Bureau-login                      |
| pl     | Logowanie agencji                 |
| pt     | Início de sessão da agência       |
| ro     | Autentificare agenție             |
| sk     | Prihlásenie agentúry              |
| sl     | Prijava agencije                  |
| sv     | Byråinloggning                    |

**Flaky-10 spot-check** (`bg, el, et, fi, ga, hu, lt, lv, mt, ro`) — all ten carry distinct,
non-English translations for the new key; none fell back to English or resolved `undefined`.
Unlike some pre-existing leaves in these same files (several older `auth.json` entries mix
English fragments into a partially-translated sentence — e.g. bg's
`headings.sign_up: "Създайте your account"`, a pre-existing defect this chunk does not touch or
fix), the new `agency_sign_in` leaf is a complete, idiomatic phrase in every one of the 23
non-English locales — the "real MT, never English-fallback-in-the-flaky-10" rule from
`WORKING-PROCESS.md` §4 held on the new key. `i18n-locale-parity.spec.ts` — **5 passed** —
keyset/placeholder/plural-form parity across all 24 locales, including this new leaf.

**E2E: no new Playwright leg — Vitest carries it, per the kickoff's own framing.** `/sign-in` is
one of the suite's most-traversed surfaces (nearly every spec starts there — see
`playwright/fixtures/test.ts`), which is exactly why this chunk does not touch its rendered output
for the default route ([§3](#3-d2--scope-fences)) and does not add a new heavy end-to-end leg for
the variant. The two things D4 asked to be provable — the CTA's absence and the shared-component
reality (same endpoint, same role routing) — are both pinned in
`SignInPage.spec.ts` (heading swap, CTA absence, the door-not-a-wall dispatch cases) and
`AuthLayout.spec.ts` (identical hero/card/aurora chrome on both route names). This mirrors AH-082's
ruling for a UI variant that never reaches a new trust boundary: Vitest territory, no Playwright
leg. The existing Playwright specs that traverse `/sign-in` (`smoke.spec.ts`,
`2fa-enrollment-and-sign-in.spec.ts`, `failed-login-lockout-and-reset.spec.ts`) were **not re-run
this chunk** — the dev-stack spin-up + isolated E2E DB that a full E2E run requires was judged
disproportionate to a change proven byte-identical on that route by the Vitest cases above; named
here as a gap, not silently skipped, per the house discipline (WORKING-PROCESS.md §4's "Full
Playwright E2E before every push that touches an E2E-traversed surface" — this chunk's honest
position is that it does NOT touch that surface's rendered output, only adds a sibling route, but
the full E2E run is still the more rigorous proof and has not been produced).

---

## 6. Gate board

| Gate                                           | Result                                                                                                                                                                           |
| ---------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Frontend (Vitest), full `apps/main` suite      | **1634 passed / 167 files**                                                                                                                                                      |
| `SignInPage.spec.ts`                           | **25 passed** (21 pre-existing + 4 new AH-086 cases)                                                                                                                             |
| `AuthLayout.spec.ts`                           | **9 passed** (6 pre-existing + 3 new AH-086 cases)                                                                                                                               |
| `i18n-locale-parity.spec.ts`                   | **5 passed** — keyset, placeholder, plural-form parity across all 24 locales, incl. the new leaf                                                                                 |
| `auth-layout-shape.spec.ts`                    | **2 passed** — `AuthLayout.vue` still 232/240 lines, `isHero`'s extended boolean expression still a single-expression computed (no function/multi-statement-arrow introduced)    |
| `tests/unit/core/router/index.spec.ts`         | **13 passed** — route-table + guard-dispatcher tests unaffected                                                                                                                  |
| `vue-tsc --noEmit` (`apps/main`, project-wide) | **clean**                                                                                                                                                                        |
| ESLint (`apps/main`, project-wide)             | **0 errors, 2 pre-existing `vue/no-v-html` warnings unrelated to this chunk** (`ClickThroughAccept.vue`, `ProfileBasicsForm.vue`) — the same two AH-082's own board named, 0 new |
| Backend (Pest)                                 | **Not run — zero backend files touched** (`git diff --stat` on `apps/api` is empty; this chunk is SPA-only)                                                                      |
| Playwright                                     | **Not run this chunk** — see [§5](#5-d4--i18n-and-e2ce-scope) for the full reasoning; named as a gap, not silently skipped                                                       |

---

## 7. What this chunk deliberately did not build

- **No signup flow for agencies.** Agencies remain Catalyst-created — the deliberate boundary
  named at kickoff (D2), not a gap.
- **No retarget of team-invite email links or the accept-invitation sign-in CTA.** Both still
  point at `auth.sign-in`. Flagged as a one-line follow-up option in [§3](#3-d2--scope-fences),
  not built — Pedram's call, not a default.
- **No new Playwright leg.** Confirmed at kickoff as Vitest territory, matching the AH-082
  precedent for a UI variant that reaches no new trust boundary. See [§5](#5-d4--i18n-and-e2ce-scope).
- **No re-run of the existing `/sign-in`-traversing Playwright specs this chunk** — named
  explicitly as the gap in the gate board ([§6](#6-gate-board)) rather than silently presumed
  still-green.
- **No visual layout change to the signup-link row.** With the creator-signup link hidden, the
  `justify-space-between` row on the agency variant renders with only the forgot-password link
  (flex default: left-aligned). Not adjusted — the kickoff asked for "the exact existing sign-in
  UI" minus the named CTA, not a re-balanced row; a live-browser eyes-on pass would catch it if it
  reads wrong, same honesty posture AH-082 took on its own unverified placement.

---

## 8. Addendum (AH-086b) — team-invite email door

**Scope, one line:** the agency team-invite email's magic-link CTA now points at
`/agency/sign-in?token=…&agency=…` instead of `/accept-invitation?token=…&agency=…` directly.
`SignInPage.vue`'s agency variant detects both params and forwards them, unchanged, to the SAME
`/accept-invitation` page/flow — nothing about token validation, expiry, or acceptance moved.

### 8.1 The change

```73:74:apps/api/app/Modules/Agencies/Services/AgencyInvitationService.php
        $acceptUrl = rtrim((string) config('app.frontend_main_url', config('app.url')), '/')
            .'/agency/sign-in?token='.$token.'&agency='.$agency->ulid;
```

```91:101:apps/main/src/modules/auth/pages/SignInPage.vue
const inviteForwardTarget = computed(() => {
  if (!isAgencyVariant.value) {
    return null
  }
  const token = typeof route.query.token === 'string' ? route.query.token : null
  const agency = typeof route.query.agency === 'string' ? route.query.agency : null
  if (token === null || agency === null) {
    return null
  }
  return { name: 'accept-invitation', query: { token, agency } }
})
```

```107:113:apps/main/src/modules/auth/pages/SignInPage.vue
onMounted(() => {
  if (inviteForwardTarget.value !== null) {
    void router.replace(inviteForwardTarget.value)
    return
  }
  loadMetaPixel(window, document)
})
```

The template gates on the same computed (`v-if="inviteForwardTarget === null"`), so the sign-in
form itself never paints for this hop.

### 8.2 Scope fences held

- **ONLY the team-invite mailable.** `InviteAgencyUserMail.php` (docblock only — no logic change)
  and `AgencyInvitationService::invite()` (the one URL line) are the only backend files touched.
  **Checked negative:** `ProspectCreatorInviteMail` — the creator roster-invite mail — is a
  different funnel (its own magic-link shape, `/auth/accept-invite?token=…`, its own SPA page,
  `AcceptCreatorInvitePage.vue`) and has zero diff in this addendum.
- **Invite-acceptance logic, expiry, and `AcceptInvitationPage.vue`'s own internal routing:
  untouched.** `AgencyInvitationService::accept()`, `InvitationController::accept()`,
  `InvitationPreviewController`, and every state/button inside `AcceptInvitationPage.vue`
  (including its own two `auth.sign-in` CTAs for the `not-authenticated` and `already-accepted`
  states) are byte-identical — `git diff` on that file is empty. The door this addendum builds
  sits entirely upstream, in `SignInPage.vue` and the URL `AgencyInvitationService` mints; nothing
  downstream of the door changed.
- **`requireGuest` and the door coexist safely.** `auth.agency-sign-in` keeps its
  `requireGuest` guard (routes.ts §D1 above); the guard does not call `bootstrap()`
  (`guards.ts` docblock), so on the cold, full-page-reload a real email-link click always produces
  (there is no client-side-SPA path to this URL), `store.user` reads `null` at guard-check time
  regardless of whether the visitor secretly still holds a valid session cookie — the guard always
  passes through, the forward always fires, and `accept-invitation` (which explicitly bootstraps
  in `loadPreview()`) resolves the TRUE auth state either way. **Named, not fixed:** the guard
  would still bounce an _already-authenticated, already-loaded_ SPA tab that client-side-navigates
  to this URL without a full reload — no code path in this app does that today, so it is a
  theoretical residual, not a reproduced one.

### 8.3 §5.34 — old link still works, new link completes acceptance

- **Old, already-sent links keep working.** `/accept-invitation` is untouched — the pre-existing
  Playwright specs (`invitations.spec.ts`, `invitations-error-paths.spec.ts`, `permissions.spec.ts`)
  navigate directly to that exact URL shape via `seedAgencyInvitation()`'s own
  `acceptUrl` builder (independent of `AgencyInvitationService`) and all pass unchanged — nothing
  404s.
- **New link (the door) completes acceptance exactly as before.** A new Playwright case proves the
  full round trip for real, not just the redirect in isolation:

  ```185:224:apps/main/playwright/specs/invitations.spec.ts
  test('AH-086b: the /agency/sign-in door forwards to the SAME accept flow, preserving token+agency', async ({
    page,
  }) => {
    // ...seeds an invitation, signs in, then navigates to
    // `/agency/sign-in?token=…&agency=…` (the exact shape
    // AgencyInvitationService::invite() now mints) and asserts the
    // browser lands on /accept-invitation? carrying the SAME token +
    // agency, then completes acceptance to the success state.
  })
  ```

  Plus four new Vitest cases in `SignInPage.spec.ts` pinning the forward/no-forward branches in
  isolation (token+agency present → forwards; agency variant with no token/agency → renders the
  form; one of the two params present → renders the form, doesn't half-forward; default `/sign-in`
  with a stray token+agency query → ignored, D2 zero-diff holds).

- **Honest UX caveat, named not fixed:** the forward is an unguarded `router.replace()` in
  `onMounted` — no loading spinner. A real browser may paint the sign-in form for a single frame
  before the replace commits. Not fixed this chunk; low-severity and cosmetic, no live-browser
  frame-by-frame check was performed to confirm or deny it.

### 8.4 Blade/lang touch discipline

No `.blade.php` or `lang/*.php` text changed — the touched files are a PHP URL-building line and a
docblock. Treated as a mail-rendering-affecting change regardless (the mailable's actual rendered
output — its CTA URL — changes), per the standing discipline: `InvitationMailTest.php`'s three
per-locale render tests (English, Portuguese, Italian) were re-run with fixtures updated to the new
URL shape, and the English case now asserts the body contains `agency/sign-in?token=…` and does
**not** contain `accept-invitation`. A fourth, new backend test
(`InvitationTest.php`, "AH-086b: the queued invite mail's magic link points at /agency/sign-in…")
asserts the real `AgencyInvitationService::invite()` code path (via the actual
`POST .../invitations` endpoint, not a hand-built fixture) mints a queued `InviteAgencyUserMail`
whose `acceptUrl` contains `/agency/sign-in?token=` and `&agency=<ulid>` and does not contain
`/accept-invitation`.

**Deploy obligation:** worker restart required (mail-touching file, per the standing rule), **and**
a PHP-FPM reload — `AgencyInvitationService::invite()`'s URL-building logic runs in the web/FPM
process at invite-creation time (the `Mailable`'s `acceptUrl` is baked in at construction, before
it is queued), so without a reload FPM's opcache would keep minting the OLD `/accept-invitation`
URL for new invites until it naturally cycled. See the deploy-log PENDING entry.

### 8.5 Gate board (addendum)

| Gate                                                    | Result                                                                                                                                                                                                                            |
| ------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend (Pest), full `apps/api` suite (`composer test`) | **2615 passed / 1 skipped, 9769 assertions** — incl. the updated `InvitationMailTest.php` (3) and the new `InvitationTest.php` case                                                                                               |
| Pint (touched files)                                    | **passed**                                                                                                                                                                                                                        |
| PHPStan (Larastan), touched files                       | **no errors**                                                                                                                                                                                                                     |
| Frontend (Vitest), full `apps/main` suite               | **1638 passed / 167 files** — incl. 4 new `SignInPage.spec.ts` forward-branch cases                                                                                                                                               |
| `vue-tsc --noEmit` (`apps/main`, project-wide)          | **clean**                                                                                                                                                                                                                         |
| ESLint (`apps/main`, touched files)                     | **0 errors**                                                                                                                                                                                                                      |
| Full Playwright, main SPA (dev stack cold-started)      | **29/29 — first pass, no flakes.** Discharges the AH-086 base chunk's named E2E gap ([§5](#5-d4--i18n-and-e2ce-scope)) honestly — every `/sign-in`-traversing spec that gap named is in this run, plus the new AH-086b door spec. |

### 8.6 What this addendum deliberately did not build

- **No change to `AcceptInvitationPage.vue`'s own sign-in buttons** (`not-authenticated` and
  `already-accepted` states) — both still resolve `auth.sign-in`. Retargeting them to
  `auth.agency-sign-in` would be a reasonable follow-up for visual consistency but is a second,
  separate surface the "ONLY the team-invite mailable" fence does not cover — named here as a
  found-but-not-built consideration, not silently left as an oversight.
- **No fix for the theoretical already-authenticated-SPA-tab `requireGuest` bounce** ([§8.2](#82-scope-fences-held))
  — no reproducible path to it exists in this app today.
- **No loading state during the forward hop** ([§8.3](#83-5-34--old-link-still-works-new-link-completes-acceptance))
  — a possible single-frame flash of the sign-in form, not fixed, not verified either way in a live
  browser.
