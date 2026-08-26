# Agency sign-in — one component, parameterized (AH-086)

- **Status: Closed — approved.** Compact full loop — kickoff with locked decisions (D1–D4) →
  brief plan-pause (mechanism ratified: route-name-driven variant on the existing page, mirroring
  `AuthLayout.vue`'s own `isHero` pattern) → build → two-commit pair, **push HELD**.
- **Date:** 2026-08-27
- **Provenance:** kickoff (D1–D4) locked by Pedram; mechanism resolved at plan-pause per the
  kickoff's explicit delegation ("mechanism yours at plan-pause"); built by Cursor.
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
