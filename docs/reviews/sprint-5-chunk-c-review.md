# Sprint 5 — Chunk C Review: Creator timezone auto-detect at sign-up

**Status:** Closed. Spot-check passed (no post-merge corrections) — June 3, 2026.

**Reviewer:** drafted by Cursor (build pass); independent spot-check passed.

**Reviewed against:** the Chunk-C kickoff (D-c1 … D-c5, the out-of-scope list, the honest-deviation triggers, the §5.17 coverage list + §5.35 break-revert discipline) and the Chunk-C read-only inventory (S1–S11).

This is a **small, bounded chunk**: capture the browser's IANA timezone at _both_ sign-up entry points so `users.timezone` stores a real zone instead of the always-`'UTC'` default — making the Sprint-5 calendar's tz round-trip render correctly by default. The creator **settings page** (manual correction + language/theme persistence) was **deferred** to a future chunk; the **travel-zone limitation** is accepted and logged.

---

## What shipped

### Backend

- **`SignUpRequest`** — net-new `timezone` rule: `['sometimes', 'nullable', 'string', 'max:64']`. **Deliberately permissive** (see the D-c2 divergence below): it exists so the client value survives `validated()` and reaches the service, _not_ to reject. A bad/absent tz must never 422 the registration.
- **`SignUpService::normaliseTimezone()`** — net-new private helper, mirroring the existing `normaliseLanguage()` in the same file. Validates the candidate against PHP's canonical IANA list (`\DateTimeZone::listIdentifiers()`) and degrades any non-string / empty / unknown value → `'UTC'`. Non-throwing by design.
- **`SignUpService::register()`** (was `:111`) — now reads `normaliseTimezone($attributes['timezone'] ?? null)` instead of `config('app.timezone','UTC')`, for both the direct-create path and the value it forwards to acceptance.
- **`SignUpService::acceptInvitationOnSignUp()`** (was `:208–218`) — gains a `string $timezone` parameter and now writes `$invitedUser->timezone = $timezone;` inside the existing transaction, overwriting the `'UTC'` seed the bulk-invite planted at invite time. **This is the load-bearing change** — the easy path to forget (S4).

### Frontend

- **`packages/api-client/src/types/auth.ts`** — `SignUpRequest` gains `timezone?: TimezoneIdentifier` (the existing opaque string alias from `types/user.ts`), with a docblock noting it's auto-captured, untrusted server-side, and rides both entry points.
- **`SignUpPage.vue`** — reads `Intl.DateTimeFormat().resolvedOptions().timeZone` once at setup and folds it into the `signUp()` payload as a hidden/auto field (no user-facing input). It rides both direct sign-up and the `?token=` invite-acceptance path because the same page handles both (D-c1).

### Unchanged (verified, per D-c4)

- **`BulkInviteService`** still seeds `'timezone' => 'UTC'` at invite time (`:132`) — correct: a bulk-invited row is created before the invitee has a browser. The real zone is captured at _acceptance_ via the `acceptInvitationOnSignUp()` write above. No change to this service.

---

## D-c3 — the two write-points (confirmed in code, both wired)

| Write-point                                 | Before                                                             | After                                                |
| ------------------------------------------- | ------------------------------------------------------------------ | ---------------------------------------------------- |
| `SignUpService::register()` direct-create   | `'timezone' => config('app.timezone','UTC')`                       | `'timezone' => $timezone` (validated, UTC-fallback)  |
| `SignUpService::acceptInvitationOnSignUp()` | wrote name/password/language/`email_verified_at` — **no tz write** | now also writes `$invitedUser->timezone = $timezone` |

Both paths read the _same_ normalized value (`register()` computes it once, forwards it into acceptance by named arg) — no duplicated IANA logic.

---

## Honest deviation — D-c2 implemented as a non-rejecting normalization (flagged per the kickoff trigger)

The kickoff's honest-deviation trigger anticipated this: _"If IANA validation via `DateTimeZone::listIdentifiers()` is awkward as a Laravel rule (custom Rule object vs. `in:`) — flag the approach; either is fine, just note which."_

**The tension:** D-c2 says "add an IANA-timezone validation rule to `SignUpRequest`," but the coverage requires _invalid tz → falls back to UTC, sign-up still succeeds (does not 400/reject)_. A genuine Laravel rule (`in:`, or a custom `Rule` object) **422s on failure** — which would reject an invalid tz, the exact opposite of the required behaviour and provably failing the coverage test.

**The resolution:** the IANA gate is implemented as a **non-rejecting server-side normalization** in `SignUpService::normaliseTimezone()` — server is authoritative (client untrusted, S3), invalid/missing degrades to UTC, sign-up never blocked. The `SignUpRequest` rule stays permissive passthrough. This mirrors the **existing** `normaliseLanguage()` precedent in the same file (note: `preferred_language` is _both_ hard-validated in the request _and_ normalized in the service — but tz cannot be hard-validated because it must never reject, so its gate lives only in the service). Both write-points share the one helper, so it stays DRY.

No further divergence from the spec. The acceptance path shared _enough_ with `register()` that the tz write was a one-line add inside the existing transaction (the second honest-deviation trigger did not fire).

---

## Coverage (§5.17) — tests added

**Backend — `tests/Feature/Modules/Identity/SignUpTest.php`:**

- ✅ Direct sign-up with `Asia/Tokyo` → row stores `Asia/Tokyo`, not UTC. **(break-revert anchor 1)**
- ✅ Direct sign-up with `Europe/Madrid` → stored verbatim (IANA accepts real zones).
- ✅ Invalid tz (`Not/AZone`) → **201**, stored `'UTC'` (never rejects — D-c2; IANA rejects junk).
- ✅ Omitted tz → **201**, stored `'UTC'` (back-compat).
- ✅ Empty-string tz → **201**, stored `'UTC'`.

**Backend — `tests/Feature/Modules/Identity/SignUpInvitationTest.php`:**

- ✅ Invite-acceptance (row seeded UTC) with `Europe/Madrid` → row's tz updated to `Europe/Madrid`. **(break-revert anchor 2 — the load-bearing one)**
- ✅ Invite-acceptance with invalid tz → **201**, stays `'UTC'`.

**Frontend — `apps/main/src/modules/auth/pages/SignUpPage.spec.ts`:**

- ✅ New: the auto-detected browser tz is forwarded in the `signUp()` payload (asserted against the same `Intl…` source the component reads — deterministic regardless of CI runner zone).
- ✅ Updated the two existing exact-payload assertions (happy path + invite-token path) to include `timezone: expect.any(String)`.

### Break-revert (§5.35) — git-verified

1. **Direct-store anchor:** reverting `register()`'s read back to `config('app.timezone','UTC')` → the `Asia/Tokyo` stored-tz test fails (stored value would be `'UTC'`). Restore verified clean.
2. **Acceptance anchor:** removing the `$invitedUser->timezone = $timezone` write in `acceptInvitationOnSignUp()` → the "accepted invitee tz updated from UTC to Europe/Madrid" test fails (the row would stay UTC). Restore verified clean.

---

## Verification results

| Gate                                                 | Result                                                     |
| ---------------------------------------------------- | ---------------------------------------------------------- |
| `apps/api` Pest — `--filter=SignUp`                  | **35 / 35** (184 assertions) — incl. all 7 new tz tests    |
| `apps/main` Vitest (full suite)                      | **682 / 682** (75 files) — incl. SignUpPage + new tz test  |
| `pnpm typecheck:frontend` (5 workspaces)             | 0 errors — `timezone?: TimezoneIdentifier` import resolves |
| `pint --test` (changed PHP)                          | passed                                                     |
| `phpstan analyse` (changed PHP, `--memory-limit=1G`) | No errors                                                  |

---

## Files touched

**Backend (`apps/api`):**

- `app/Modules/Identity/Http/Requests/SignUpRequest.php` — permissive `timezone` rule + rationale comment.
- `app/Modules/Identity/Services/SignUpService.php` — `normaliseTimezone()` helper; `register()` reads it; `acceptInvitationOnSignUp()` gains `$timezone` param + writes it.
- `tests/Feature/Modules/Identity/SignUpTest.php` — 5 direct-path tz tests.
- `tests/Feature/Modules/Identity/SignUpInvitationTest.php` — 2 acceptance-path tz tests.

**Frontend:**

- `packages/api-client/src/types/auth.ts` — `timezone?: TimezoneIdentifier` on `SignUpRequest` (+ import).
- `apps/main/src/modules/auth/pages/SignUpPage.vue` — browser-tz capture into the submit payload.
- `apps/main/src/modules/auth/pages/SignUpPage.spec.ts` — new forward test + 2 payload-assertion updates.

**Docs:**

- `tech-debt.md` — two new entries (see below).
- `services.md` — **no change** (per kickoff).
- `reviews/sprint-5-chunk-c-review.md` — this file.

---

## Out-of-scope (logged at close)

- **The creator settings page (half 2)** → deferred to a future "creator settings" chunk, re-scoped to **timezone correction + persist `preferred_language` + persist `theme_preference`** (S7: all three are client-only / never persisted after row creation today). The deferred chunk owns: the first creator settings route + page + nav item, a net-new own-record-only User self-update endpoint (`users/me`-style), a lean IANA picker (`v-autocomplete` over the full `Intl.supportedValuesOf('timeZone')`), and language/theme persistence. **Logged in `tech-debt.md`.**
- **The travel-zone limitation** (D-c1's accepted limit) — an auto-detected wrong zone can't be corrected in-app until the settings page ships. Strictly better than always-UTC, rare, named honestly. **Logged in `tech-debt.md`.**

---

## Proposed commit shape (two-commit pair — not committed until spot-check)

1. `feat(identity): capture browser IANA timezone at sign-up (backend) — Sprint 5 Chunk C` — `SignUpRequest` + `SignUpService` + the two backend test files.
2. `feat(main): forward auto-detected browser timezone from SignUpPage — Sprint 5 Chunk C` — `api-client` type + `SignUpPage.vue` + its spec; plus the `tech-debt.md` entries + this review.

(Grouping is flexible; the surfaces split cleanly backend / frontend+docs.)

---

_Provenance: drafted by Cursor (Sprint 5 Chunk C build pass, 2026-06-03); independent spot-check passed (no post-merge corrections) per `PROJECT-WORKFLOW.md` § 3._
