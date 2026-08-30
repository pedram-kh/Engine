# Invite-acceptance creates the account (AH-095)

- **Status: Closed — approved.** Full loop: kickoff with locked decisions (D1–D6) →
  plan-pause (six ratified decisions + the D3 guard enumeration) → build (S1–S9) →
  independent review closure (this file) → two-commit pair, push held then pushed.
  **Verdict:** D1–D6 verified as built; the email-immutability proof accepted as the
  model — absence by construction, pinned by attempting the attack, not merely a
  disabled field; the rollback and byte-identical-existing-path proofs verified; the
  E2E fix-not-extension with the genuine-race leg accepted; the dropped revoke guard,
  the dedicated audit case, and the auth-ip-only posture as ruled; the found-not-fixed
  tenancy allowlist gap on the sibling `accept`/`preview` routes recorded honestly
  rather than silently backfilled; LOW confirmed.
- **Date:** 2026-08-30.
- **Provenance:** kickoff (D1–D6) and plan-pause rulings by Claude, relayed and
  decision-owned by Pedram; built by Cursor; independent review closure by Claude.
- **PROD-DATA RISK: LOW** (re-derived from the kickoff's LOW-MEDIUM, ratified at
  plan-pause). One new unauthenticated write endpoint that creates `users` +
  `agency_users` rows, gated entirely by the existing signed 64-char invite token. No
  migration. The strongest safeguard is structural: the request schema carries no
  `email` field at all, so the created account's address is foreclosed by construction,
  not by a value check — see [§2](#2-review-priority-1--email-immutability-by-construction).

---

## 1. What shipped, against the kickoff's D1–D6

| Decision | Asked                                                                                                                                                                                        | Shipped                                                                                                                                                                                                                                                                                                                                                                 |
| -------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **D1**   | Branch on account existence: existing users take the byte-identical sign-in-then-accept path; new users see a set-password form (first/last name, password + confirmation, read-only email). | Held exactly. `InvitationPreview` gained `invited_email` + `account_exists`; `AcceptInvitationPage.vue` branches to a new `'no-account'` `PageState` only when unauthenticated AND `account_exists === false`. An authenticated user always lands on `'pending'` regardless of `account_exists` — see [§3](#3-d1--the-branch-and-the-byte-identical-existing-path).     |
| **D2**   | One transaction: create user (agency-typed, `email_verified_at = now()`), activate membership, consume the token, establish session, land on the dashboard. Rollback-safe.                   | Held. `AgencyInvitationService::acceptWithNewAccount()` wraps all four writes in one `DB::transaction()`; a forced mid-transaction failure leaves zero rows anywhere. See [§4](#4-d2--the-atomic-transaction).                                                                                                                                                          |
| **D3**   | Guards: token expiry/tamper, invite-revoked (propose), password rules, rate limiting, collision race.                                                                                        | Token expiry/tamper + password rules reuse the existing `accept()` guards and `SignUpRequest`'s rule set exactly. Invite-revoked **dropped from scope** — no such feature exists on this platform (named as a tech-debt follow-up, not built). Rate limiting: `auth-ip` only. Collision race: two-layer guard. See [§5](#5-d3--the-guards-carried-and-the-one-dropped). |
| **D4**   | New account created un-enrolled; standard agency-role MFA requirements apply, no special path.                                                                                               | Held, with nothing to build: `mfa_required` defaults `false` (the existing agency-user-creation default), `two_factor_confirmed_at` is left `null` by the plain `User::create()` call, and the AH-087 guards read the same two fields for every agency user regardless of creation path.                                                                                |
| **D5**   | No new mail; invite copy checked for staleness; new form copy ×24, flaky-10.                                                                                                                 | No mail added. The invite email's "sign in to accept" copy does not exist verbatim (checked — the mail says "You've been invited… click below to accept", generic enough to cover both paths unchanged). Five new keys × 24 locales + one new key × 24 locales, flaky-10 verified clean. See [§7](#7-d5--i18n-24-locales-flaky-10-verified-clean).                      |
| **D6**   | Full stranger-path E2E; the existing unauthenticated-sign-in-prompt test needs fixing, not extending.                                                                                        | The test was fixed (not extended) — its own precondition (a brand-new email, no prior account) now hits the NEW branch, so its assertion was simply wrong post-AH-095. Two new legs added alongside: the still-sign-in-prompt existing-account case, and the collision-race message. See [§8](#8-d6--e2e-the-fix-and-two-new-legs).                                     |

**Plan-pause rulings carried into the build:** preview disclosure confirmed (the token is
already the capability; disclosing `invited_email`/`account_exists` to a token holder
teaches a thief nothing they couldn't learn by attempting the flow); the revoke guard
dropped as (a) — you cannot guard a feature that does not exist; the audit case is a
dedicated `InvitationAcceptedWithNewAccount` enum value, not a payload flag on
`invitation.accepted`; the endpoint is named `create-account`; the rate limit is
`auth-ip` only (a) — the token is unbruteforceable and single-use by construction; risk
re-derived to LOW on the strength of the email-field's structural absence.

---

## 2. Review priority #1 — email immutability, by construction

The kickoff named this the top review priority: _"the form cannot submit a different
address — server-side, not just UI."_ The mechanism is the strongest form available:
`CreateAccountAndAcceptInvitationRequest` (the new Form Request backing
`POST /invitations/create-account`) has **no `email` key in its `rules()` at all** —
not a disabled field, not a server-side override of a submitted value, an **absence**.
`AgencyInvitationService::acceptWithNewAccount()` writes
`'email' => $invitation->email` directly from the invitation row; there is no code path
by which a value from the request body could reach that column, because no such value
exists in the validated set to reach from.

Pinned twice:

- **Backend** (`CreateAccountAndAcceptInvitationTest.php`): _"the created account email
  is always the invitation email — a client-submitted email field has no effect"_ — the
  test POSTs an `email` key anyway (a payload a curious or malicious client might try)
  and asserts the created row's email is the invitation's, not the submitted value.
- **Frontend** (`AcceptInvitationPage.spec.ts` + the E2E leg): the email `<v-text-field>`
  renders `preview.invited_email`, carries the `readonly` attribute, and there is no
  second, editable email input anywhere on the `'no-account'` card — asserted at both the
  component-test and the real-browser layer.

Disclosure reasoning (ratified at plan-pause, recorded here per the kickoff's
instruction): the 64-character token **is** the capability. It was mailed to the
address the preview now discloses; the only party who learns anything new by calling the
preview endpoint is someone who already possesses the token, and that party could learn
the same fact — indirectly, more slowly — by simply attempting the `create-account` call
and reading whether it 409s. Disclosing `invited_email`/`account_exists` costs nothing a
token holder didn't already have access to, and the D1 branch is structurally impossible
to implement client-side without it.

---

## 3. D1 — the branch, and the byte-identical existing path

**The branch lives in exactly one place**: `AcceptInvitationPage.vue`'s `loadPreview()`,
on the unauthenticated leg only —

```
if (res.data.is_expired) { state.value = 'expired' }
else if (res.data.is_accepted) { state.value = 'already-accepted' }
else if (!isAuthenticated.value) {
  state.value = res.data.account_exists ? 'not-authenticated' : 'no-account'
} else {
  state.value = 'pending'
}
```

An authenticated user takes the `else` branch unconditionally — `account_exists` is
never read once a session exists. This is the byte-identical guarantee the kickoff asked
for stated as code, not as a promise: **nothing** about the existing sign-in-then-accept
path changed in this diff. `AcceptInvitationPage.vue`'s `pending`/`acceptInvitation()`
branch, `InvitationController::accept()`, and `AgencyInvitationService::accept()` are
**untouched bytes** — `git diff` on those functions is empty. Pinned by a dedicated
unit test (_"an authenticated user ALWAYS lands on the pending accept path, even when
account_exists is false"_) and a live-browser leg (`invitations.spec.ts`'s
`'authenticated invitee can accept invitation and land in workspace'`, unmodified,
still green).

**The two unauthenticated branches, both real cards, never both:**

| `account_exists` | State               | What renders                                                                                                     |
| ---------------- | ------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `true`           | `not-authenticated` | The pre-AH-095 sign-in CTA, byte-identical (`AcceptInvitationPage.vue`'s `not-authenticated` card is untouched). |
| `false`          | `no-account`        | AH-095: the new set-password card (`accept-invitation-create-account`).                                          |

The only template change on the `not-authenticated` card itself was a **removal**: the
"Don't have an account? Sign up" link (`app.invitation.accept.noAccount` /
`.signUp`) is gone, because there is no longer a scenario where an unauthenticated,
account-exists-false visitor sees that card — they see the set-password form instead.
Leaving the link would have offered a detour into the generic `/sign-up` flow (the exact
wrong-funnel problem this chunk exists to close) from a card that, post-AH-095, only ever
renders for an email that already has an account.

---

## 4. D2 — the atomic transaction

`AgencyInvitationService::acceptWithNewAccount()`, one `DB::transaction()`, four writes
in order: `User::create()` (agency-typed, `email_verified_at = now()` — the token is
the verification proof, recorded here as the kickoff instructed), `AgencyMembership::create()`
at the invitation's role, `$invitation->update(['accepted_at', 'accepted_by_user_id'])`,
and the `Audit::log()` call for `InvitationAcceptedWithNewAccount`. `Auth::guard('web')->login()`

- `$request->session()->regenerate()` run last, inside the same transaction, so a
  session is only ever established for a row that actually committed.

**Rollback proof, not rollback assumption.** The test forces a failure at the audit-log
call — the very first write inside the transaction to depend on an external collaborator
— by swapping `Audit`'s facade target for a throwing double. `User::create()` has
already executed by that point (its `Audited::created` listener is what calls the
now-throwing logger), so this is the sharpest version of "no half-account": even the row
written **first** does not survive.

```
$this->assertDatabaseMissing('users', ['email' => 'rollback@example.com']);
$this->assertDatabaseCount('agency_users', 1); // only the inviter's own membership
$invitation->refresh();
expect($invitation->accepted_at)->toBeNull();
```

`email_verified_at = now()` reasoning, stated per the kickoff's instruction: the
invitation token is itself proof of address control — it was delivered to that address by
the existing mail flow, and the invitee had to read it to reach this form at all. Treating
a freshly-created invited account as unverified would force a second, redundant
verification email for a fact the token already established.

---

## 5. D3 — the guards carried, and the one dropped

**Carried, reusing existing mechanisms exactly:**

- **Token expiry / tamper.** Same `token_hash = hash('sha256', $token)` lookup as
  `accept()` and `InvitationPreviewController` — an unknown hash 404s (tamper defence: a
  guessed or truncated token misses the index, not a decrypt failure), an expired
  invitation 410s and logs `InvitationExpiredOnAttempt`, an already-accepted invitation
  409s.
- **Password rules.** `CreateAccountAndAcceptInvitationRequest` reuses the exact same
  `StrongPassword` + `PasswordIsNotBreached` rule objects `SignUpRequest` uses — the
  platform's one password policy, not a parallel one for this form.
- **Rate limiting.** `throttle:auth-ip` on the route, `auth-ip` only — the plan-pause's
  ruling (a): the token is unbruteforceable (64 hex chars, hashed lookup) and the
  operation is single-use by nature (the first success consumes the token), so an
  email-keyed second limiter's dominant real-world effect would be locking out the
  legitimate invitee fumbling their own password confirmation. Considered and declined,
  recorded here per the kickoff's instruction.

**Dropped, not guarded:** invite revocation. The read pass at plan-pause found no
`revoked_at` column, no revoke endpoint, and no SPA affordance anywhere in the
codebase — "invitation revocation" is not a feature that exists, so D3's "invite-revoked
case" had nothing to guard against. Recorded as a named tech-debt follow-up
([`tech-debt.md`](../tech-debt.md), "No agency-invitation revocation path exists"),
not silently absorbed.

**The collision race — the two-layer guard.** Between a stranger loading the accept page
and submitting the form, a real window exists in which an account could be created for
that exact email (a second tab, a race with another invite, simple bad luck). Two
independent layers, both funnelling into one exception so the frontend has a single code
to branch on:

1. **Controller pre-check** (`InvitationController::createAccount()`): a cheap
   `User::query()->where('email', ...)->exists()` before a transaction is even opened —
   catches the common, non-concurrent case for free.
2. **Service re-check + unique-index catch** (`AgencyInvitationService::acceptWithNewAccount()`):
   re-checks existence **inside** the transaction (closing the window between layer 1's
   read and this write), and separately catches the `users.email` unique-index violation
   for the genuinely-concurrent case that slips past both reads. Neither layer trusts the
   other.

Both funnel into `InvitationAccountExistsException` → the controller's 409
`invitation.account_exists` — never a bare 500. The SPA treats this **identically** to
`account_exists: true` from the preview call: re-branch to the sign-in state with a
clear message, not a dead end. Pinned at three altitudes: `layer 1` and `layer 2` each
have a dedicated backend test that exercises that layer specifically (layer 2 calls the
service directly, bypassing the controller, to isolate it); the frontend unit test
`'on invitation.account_exists (409, the collision race), re-branches to the sign-in
state rather than erroring'`; and the E2E leg that creates the real race via a second,
independent `signUpUser()` call while the stale form sits on screen, then submits into
the 409.

---

## 6. D4 — MFA posture: nothing built, because nothing was needed

The kickoff asked this be verified, not assumed. `User::create()` in
`acceptWithNewAccount()` sets `mfa_required: false` and leaves `two_factor_confirmed_at`
untouched (defaults `null`) — the exact shape every other agency-user-creation path in
this codebase already produces. The AH-087 guards (`requireMfaEnrolled`,
`requireMfaUnlessExempt`) read `mfa_required` / `mfa_exempt` / `two_factor_enabled` off
the `User` row; they do not know or care which endpoint created that row. A stranger who
creates their account via this new path and is later promoted onto an MFA-gated agency
surface meets the identical enrollment prompt any other un-enrolled member would.

---

## 7. D5 — i18n, 24 locales, flaky-10 verified clean

**Removed:** `app.invitation.accept.noAccount` / `.signUp` (the sign-up-link keys,
no longer reachable — see [§3](#3-d1--the-branch-and-the-byte-identical-existing-path)).
**Added:** `app.invitation.accept.createAccount.{heading,subheading,submit,submitting,
alreadyHaveAccount}` (5 keys) + `auth.invitation.account_exists` (1 key), × 24 locales
(48 files touched, a clean net-positive diff — nothing else in those bundles was rewritten).

**Invite-mail copy checked for staleness (D5's second half):** the existing invite
email's "sign in to accept" framing does not exist verbatim — the actual copy is
generic ("You've been invited to join… click below to accept"), which reads correctly
for both the existing-account and the new stranger path without any edit. No mail
template touched; no worker-restart obligation from this chunk's i18n or mail surface.

**Flaky-10 discipline, verified not assumed.** `tech-debt.md`'s standing AH-001 entry
names ten locales (`bg`, `et`, `fi`, `el`, `hu`, `ga`, `lv`, `lt`, `mt`, `ro`) whose
existing MT baseline carries English-fragment contamination in over half their leaves.
The kickoff's D5 instruction was explicit: new form copy must not add to that debt.
Read back after generation, all ten:

```
bg: "Създайте своя акаунт" / "Поканени сте да се присъедините към {agencyName}…"
et: "Loo oma konto" / "Teid kutsutakse liituma {agencyName}-ga…"
fi: "Luo tilisi" / "Sinut on kutsuttu liittymään {agencyName}:iin…"
el: "Δημιουργήστε τον λογαριασμό σας" / "Σας προσκαλούν να συμμετάσχετε…"
hu: "Hozza létre a fiókját" / "Meghívást kapott, hogy csatlakozzon…"
ga: "Cruthaigh do chuntas" / "Tugadh cuireadh duit páirt a ghlacadh…"
lv: "Izveidojiet savu kontu" / "Jūs esat uzaicināts pievienoties…"
lt: "Susikurkite paskyrą" / "Esate kviečiami prisijungti prie…"
mt: "Oħloq il-kont tiegħek" / "Ġejt mistieden tissieħeb ma'…"
ro: "Creați-vă contul" / "Ați fost invitat să vă alăturați…"
```

Complete, idiomatic, full sentences in the target language — none of the "English
fragment under a foreign label" pattern the AH-001 debt entry documents (its own worked
example: `bg`'s pre-existing `"Творецs you invite…"`, an English sentence with a single
Cyrillic noun substituted in). This chunk's new keys do not extend that debt; the
pre-existing contamination elsewhere in those ten bundles is untouched and remains
tracked under AH-001.

**Both architecture tripwires re-run green after the change:**
`i18n-locale-parity.spec.ts` (8 tests: keyset/placeholder/plural parity across all
namespaces, including the pluralised-message CLDR-form-count check) and
`i18n-used-keys-resolve.spec.ts` (the AH-094 tripwire — every `t()` call site in
`apps/main/src` statically resolves against the deep-merged `en` bundle, so the new
`createAccount.*` / `account_exists` call sites in `AcceptInvitationPage.vue` are proven
to resolve, not just assumed to).

---

## 8. D6 — E2E: the fix, and two new legs

**The fix (not an extension).** `invitations.spec.ts`'s
`'unauthenticated invitee sees sign-in prompt on accept page'` seeded a brand-new
invitee email that had never signed up — exactly the `account_exists: false`
precondition. Before AH-095 that was the _only_ unauthenticated branch that existed, so
the test's assertion (sign-in prompt) was correct for its own precondition. AH-095
changed what that precondition renders — the assertion became wrong the moment the
feature shipped, not "incomplete." Renamed and rewritten as the full stranger-path leg
the kickoff's D6 asked for: seeded invite → loads the accept page unauthenticated → sees
the set-password form (not the sign-in prompt) → the email field renders the invited
address, read-only → fills + submits → lands on the success card → the page's own
2-second self-redirect completes and the invitee is signed in on the real agency
dashboard (`agencyLayout` visible), not just a success message.

**New leg 1 — the existing-account case, now correctly isolated.** The scenario the old
test's _name_ implied but its _body_ never actually set up: an unauthenticated invitee
whose account already exists. Signs the invitee up FIRST via production sign-up, seeds
the invitation for that same email second, then asserts the sign-in prompt (not the
set-password form) renders — the byte-identical existing-account path, proven at the
browser layer with the correct precondition this time.

**New leg 2 — the collision race, for real.** Loads the accept page while the account
does not yet exist (the set-password form renders), then — with that stale form still on
screen — creates the account for the identical email via a **second, independent**
production sign-up call (simulating a second tab / a genuine race), then submits the
stale form. Asserts the 409 collision-race path: the page re-branches to the sign-in
state, not a crash or a dead-end error.

All three legs run against the real API + real dev-mode SPA, one worker, isolated
`catalyst_e2e` database (the standing chunk-6.8 hermeticity contract) — `invitations.spec.ts`
now carries 7 tests (was 5; the fix repurposed one, two are net-new), all green. The
sibling `invitations-error-paths.spec.ts` (email-mismatch, already-member) — untouched by
this diff — re-run to confirm no regression: 2/2 still green.

---

## 9. Files touched

**Backend — new:** `InvitationAccountExistsException`,
`CreateAccountAndAcceptInvitationRequest`, `CreateAccountAndAcceptInvitationTest.php`
(11 tests), `InvitationPreviewControllerTest.php` (9 tests).

**Backend — modified:** `AuditAction` (one enum case), `InvitationPreviewController`
(`invited_email` + `account_exists` in the response), `InvitationController` (the new
`createAccount()` action), `AgencyInvitationService` (`AuthFactory` injected,
`acceptWithNewAccount()` added — `accept()` itself untouched), `Agencies/Routes/api.php`
(the new `throttle:auth-ip`-gated route), `AuditActionEnumTest` (the new case joins the
pinned catalogue).

**Shared:** `packages/api-client/src/types/agency.ts` (`InvitationPreview`'s two new
fields, the new `CreateAccountAndAcceptInvitationPayload` interface).

**Frontend — modified:** `invitations.api.ts` (`createAccount()`),
`AcceptInvitationPage.vue` (the `'no-account'` state, the set-password form, the
`createAccountAndAccept()` handler — the existing `pending`/`acceptInvitation()` code
path is byte-identical), 48 locale files (24 × `app.json` + `auth.json`).

**Frontend — new:** `AcceptInvitationPage.spec.ts` (17 tests).

**E2E:** `invitations.spec.ts` (one test fixed/repurposed, two new legs — net 5 → 7),
`playwright/helpers/selectors.ts` (9 new `data-test` selectors for the set-password form,
1 dead selector removed — `acceptSignUpLink`, whose underlying element no longer exists
post-D1).

**Docs:** this file, the AH-095 log entry, `tech-debt.md` (the invitation-revocation
follow-up), `security/tenancy.md` (the new route's allowlist row, plus a pre-existing gap
named honestly rather than silently backfilled — see the row itself).

---

## 10. Gate board

| Gate                                       | Result                                                                                                                                                                                                                                                                                                                                                                                                   |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend Pest (full suite)                  | **2910 tests, 11138 assertions, 0 failures** (exit 0). Local run labels almost every test "deprecated" rather than "passed" — a PHP 8.5 `PDO::MYSQL_ATTR_SSL_CA` deprecation notice fires suite-wide on this machine's PHP version and is captured per-test by Pest; pre-existing and environmental (also seen, and named out-of-scope, in the AH-093/AH-094 sessions), not a regression from this diff. |
| Backend Pest (new files, isolated)         | **20 tests / 90 assertions**, 0 failures — `CreateAccountAndAcceptInvitationTest.php` (11) + `InvitationPreviewControllerTest.php` (9).                                                                                                                                                                                                                                                                  |
| PHPStan (Larastan)                         | **No errors** (3 found and fixed during this pass — a nullable `->first()` call site and two `$this->app[...]` accesses inside Pest closures that PHPStan's `TestCall` stub doesn't type; fixed with `->firstOrFail()` and `app('session')` respectively).                                                                                                                                               |
| Pint                                       | Clean (auto-fixed a handful of files during the build, then clean).                                                                                                                                                                                                                                                                                                                                      |
| `apps/main` Vitest (full suite)            | **175 files / 1723 tests**, 0 failures (net +1 file / +17 tests over the AH-094 baseline of 174/1706 — exactly `AcceptInvitationPage.spec.ts`'s 17 tests, confirming nothing else moved).                                                                                                                                                                                                                |
| `apps/main` frontend typecheck (`vue-tsc`) | Clean.                                                                                                                                                                                                                                                                                                                                                                                                   |
| `apps/main` ESLint                         | **0 errors** (2 pre-existing `vue/no-v-html` warnings, untouched files — same two named in the AH-087 review).                                                                                                                                                                                                                                                                                           |
| `apps/main` i18n architecture tripwires    | `i18n-locale-parity.spec.ts` (8 tests) + `i18n-used-keys-resolve.spec.ts` (3 tests), both green.                                                                                                                                                                                                                                                                                                         |
| Playwright E2E (`apps/main`, desktop)      | **31/31 passing** (full suite, one worker, isolated `catalyst_e2e` DB). `invitations.spec.ts` 7/7 (was 5 — one fixed, two new); `invitations-error-paths.spec.ts` 2/2 (regression check, untouched by this diff).                                                                                                                                                                                        |

**Net-new tests:** 20 backend (2 new files) + 17 frontend unit (1 new file) + 2 net-new
E2E legs (1 repurposed + 2 added on the same file) = **39 new/repurposed automated
checks**, plus 1 existing backend test (the audit catalogue) extended to cover the new
enum case.

### What broke, then was fixed, during this pass

Three PHPStan errors surfaced only once the new backend test file was analysed (not at
write time): a `->first()` call assigned to a variable later called with `->update()`
without a null-check (`AgencyUserInvitation|null`, fixed with `->firstOrFail()`), and two
`$this->app['session']->driver()` accesses inside Pest's function-based test closures,
where `$this` types as `Pest\PendingCalls\TestCall` rather than the underlying
`TestCase` — PHPStan correctly flags the property as undefined on that stub. Fixed by
using the `app('session')` helper instead of `$this->app`, which resolves the same
binding without depending on a property PHPStan cannot see through. No production code
was affected — all three were in the new test file.

---

## 11. Deploy obligations

**One route, no migration.** The new controller method + new Form Request + new
service method are unreachable without a fresh PHP-FPM opcode reload. No
`php artisan migrate` — no schema changed. No queue-worker restart — no mail template
touched, no mailable added.

Joins the deploy-log's PENDING entry as a rider (see
[`deploy-log.md`](../runbooks/deploy-log.md)): PHP-FPM reload, plus the `apps/main`
bundle rebuild (the new `AcceptInvitationPage.vue` branch + the 48 touched locale
files are invisible without it).
