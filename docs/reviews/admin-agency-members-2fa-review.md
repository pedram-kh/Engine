# Agency members drill-in + per-member 2FA controls (AH-087)

- **Status: Closed — approved, pushed.** Full loop: read-only inventory
  ([`admin-agencies-2fa-inventory.md`](admin-agencies-2fa-inventory.md), commit `cfd776a2`) →
  kickoff with locked decisions (D1–D6) → plan-pause (R1–R7 ratified) → build (S1–S11) →
  gate board → **pushed**.
- **Date:** 2026-08-27.
- **Provenance:** kickoff decisions D1–D6 locked by Pedram; the plan-pause questions R1–R7
  answered by Pedram before any code was written; built by Cursor. **Closed by Pedram**,
  verdict: R1–R7 verified as ruled; the structural-inertness proof accepted with both polarity
  break-reverts ([§8](#8-gate-board)); the [§2](#2-production-posture--read-this-before-approving)
  Production posture accepted as the record — the presentational-gate limitation stated plainly,
  the tech-debt trigger named; the six break-reverts confirmed, including the #6 EN-fallback
  finding written up as a standing lesson (a missing locale string silently renders the English
  string rather than erroring — the `LangParityTest` en-SOT keyset gate is the actual tripwire,
  not the mailable render test); the D2 `TwoFactorDisabled`-not-dispatched shape verified
  ([§4](#4-d2--reset-2fa-the-support-operation)); the `ah087` helper-prefix fix accepted as the
  AH-085 bug class caught locally rather than in CI; three mailables' §5.3 real-render coverage
  ×24 confirmed green, flaky-10.
- **Evidence base:** the inventory file above (every claim about the pre-existing MFA graph
  in this document traces to it); the **suspend pattern** (`AdminAgencyController::suspend` /
  `reactivate` — mandatory reason, audit verb, row marker) as the shape both write operations
  copy; the **AH-051 admin-doors** work as the notification-posture precedent (admin-initiated,
  individually-targeted, email-only, admin never named).
- **PROD-DATA RISK: LOW.** Re-derived at plan-pause and again here — see
  [§2](#2-production-posture--read-this-before-approving). One additive-nullable migration that
  writes no data. Two write endpoints that touch live users' MFA state, both platform-admin-only
  and both fully audited. The exemption branch is structurally inert for the entire existing
  population.

---

## 1. What shipped, against the kickoff's D1–D6

| Decision | Asked                                                                                                                                                                                    | Shipped                                                                                                                                                                                                                                                                              |
| -------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **D1**   | A Members section on the existing `AgencyDetailPage` (tab or card — propose), cloning the agency-side table shape, server-paginated, with an MFA column rendering exactly three states.  | Held, as a **third stacked card** (`AgencyMembersCard.vue`). `v-tabs` appears nowhere in the admin SPA and this page was already two stacked outlined cards. Three MFA states from two booleans. See [§3](#3-d1--the-surface).                                                       |
| **D2**   | Reset 2FA: clears every enrollment artifact in one transaction; the MFA requirement is untouched; mandatory reason; `user.mfa_reset`; 409 when nothing is enrolled; the user is emailed. | Held. Four artifacts wiped, `mfa_required` and the exemption columns untouched, 409 `mfa.nothing_to_reset`, email-only notice ×24. See [§4](#4-d2--reset-2fa-the-support-operation).                                                                                                 |
| **D3**   | Exempt / un-exempt: flips the requirement and wires it into enforcement for the first time; platform admins never exemptable; mandatory reason **both** directions; the row marker chip. | Held, with the mechanism changed at plan-pause (**R1**): a new additive-nullable column pair, **not** `mfa_required`. Enforcement wiring is client-guard-only (**R2**). See [§5](#5-d3--exemption-the-policy-operation) and [§2](#2-production-posture--read-this-before-approving). |
| **D4**   | State the boundary: admins can never view a secret, generate codes, or complete enrollment for a user — with the endpoint's exact-keyset pin as proof.                                   | Held. The boundary is written **on the surface itself**, not only in this document, and pinned by an exact-keyset test. See [§6](#6-d4--what-an-admin-can-never-do).                                                                                                                 |
| **D5**   | Session/blast-radius posture per the inventory: whatever the code says, state it and pin it.                                                                                             | Held — reset does **not** kill sessions, and that is asserted rather than assumed. See [§7](#7-d5--blast-radius-what-actually-happens-to-the-person).                                                                                                                                |
| **D6**   | Scope fences: no E2E leg (named gap); admin types stay local to `apps/admin`; the mailables ride no flag; deploy notes join the PENDING entry.                                           | All four held. See [§9](#9-d6--scope-fences-and-what-this-chunk-deliberately-did-not-build).                                                                                                                                                                                         |

**Plan-pause rulings carried into the build (R1–R7):** R1 new column pair (not `mfa_required`);
R2 client-guard-only enforcement + the tech-debt entry; R3 the `requireMfaEnrolled` /
`requireMfaUnlessExempt` split; R4 three distinct mailables; R5 reason required in both
directions, divergence recorded; R6 Exempt wins the chip, tooltip carries "also enrolled";
R7 the deploy entry gains the migrate step.

---

## 2. Production posture — read this before approving

Two paragraphs Pedram specifically asked to be stated plainly.

**The exemption is structurally inert, and that is why it is a new column pair.** The kickoff
assumed `users.mfa_required` was the flip. It is not usable for this. `mfa_required` is a
two-state boolean that cannot distinguish "never required" from "deliberately exempted", and it
already reads `false` for **every** agency user in production — every agency-user creation path
writes `false` explicitly (inventory, §I2). Branching enforcement on `mfa_required === false`
would therefore have exempted the entire live agency population on deploy: the exact inverse of
the inertness requirement. Making it usable would have required a population-wide production
data write purely to render a new branch inert, which is the §5.40 tail wagging the dog. So
AH-087 adds `users.mfa_exempted_at` + `users.mfa_exemption_reason`, mirroring the suspend
pattern's own shape (`agencies.suspended_at` + `suspended_reason`) exactly: a nullable timestamp
that **is** the state, plus the operator-visible reason. NULL means not exempt, so inertness is
**structural** — every pre-existing row is NULL the instant the migration finishes, there is no
backfill, and the migration writes no data at all. `mfa_required` is left alone with its
documented meaning.

**The honest limitation: this is a presentational gate, not a server-side control.** The
agency-side MFA requirement has never been server-enforced. For platform admins MFA is a real
control — `EnsureMfaForAdmins` sits on the whole admin route group, unconditionally, and is
deliberately blind to the exemption columns. For **agency** users it has only ever been a
client-side router guard; the agency endpoints those pages call carry `auth:web` + tenancy +
policy checks and no MFA middleware. AH-087 therefore branches the client guards only, per
ruling R2: adding a server-side gate in this chunk would **tighten** behaviour for the whole
default population, which is the inverse of the inertness requirement, and would put a new gate's
first production exercise in the same deploy as a new bypass branch. The consequence to hold in
mind: a member row now renders an "Exempt" chip and the trail records `user.mfa_exempted`, which
together read as though a security control was relaxed for that person — when in truth no
server-side control was ever applied to that population. Harmless while MFA is an internal
convention; misleading the moment it is described as a control to anyone outside the team. That
is tracked in [`tech-debt.md`](../tech-debt.md) with an explicit trigger: **before MFA is
presented to agencies, customers, auditors, or compliance as a security control, the server-side
gate ships first and the exemption branch moves into it.**

**Re-derived risk: LOW** (kickoff said LOW-MEDIUM). It came down for two reasons. The exemption
mechanism became additive-nullable schema instead of a live column already carrying meaning, so
deploy writes nothing and changes no existing behaviour. And no server-side gate ships, so no
existing request path changes for anyone. What remains is genuinely low but not zero: two
endpoints that write real users' MFA state. Both are platform-admin-only, both are
reason-mandatory, both are audited, and the destructive one (reset) is recoverable by the user
re-enrolling.

---

## 3. D1 — the surface

**A third stacked card, not a tab.** `AgencyDetailPage.vue` was already an overview card plus a
suspension card; `v-tabs` appears nowhere in the admin SPA. A tab would have been a new
navigation idiom introduced for one section.

`AgencyMembersCard.vue` is a server-paginated `v-data-table-server` — the same shape as the
agency-side members table — with a role chip-group filter, a debounced search field, and columns
name / email / role / status / joined / **MFA** / actions. The role filter reuses the same
`roles.*` strings the row renders, so a role reads identically in both places.

**The MFA column: three states from two booleans.**

| `is_mfa_exempt` | `is_mfa_enrolled` | Chip         | Tooltip                                              |
| --------------- | ----------------- | ------------ | ---------------------------------------------------- |
| `false`         | `false`           | Not enrolled | none                                                 |
| `false`         | `true`            | Enrolled     | none                                                 |
| `true`          | `false`           | **Exempt**   | exempt since \<date\> · reason                       |
| `true`          | `true`            | **Exempt**   | exempt since \<date\> · **"also enrolled"** · reason |

Per ruling **R6**, Exempt wins the chip in the fourth row because it is the security-relevant
fact; the tooltip carries "also enrolled" so the operator is not misled into thinking the
authenticator was removed. An enrolled-and-exempt person is still challenged at sign-in — login
reads _enrollment_, not the requirement — and mail #2's copy tells them exactly that.

Row actions live in a `v-menu`: **Reset 2FA** always, plus **Exempt** or **Remove exemption**,
never both (asserted). Every action opens one parameterized dialog
(`MemberMfaActionDialog.vue` — the AH-086b one-component-parameterized pattern) whose confirm
stays disabled until a min-10-character reason is typed, mirroring `MemberMfaActionRequest`
so the operator never learns about the requirement from a 422. Each action re-renders its row
from the server's response, so the table shows what the backend actually committed.

**Backend.** `AdminAgencyMemberController` on the existing admin route group
(`auth:web_admin` + `EnsureMfaForAdmins`), four routes, gated by two new flat-PlatformAdmin
`AgencyPolicy` abilities (`viewMembers`, `manageMemberMfa`). Four allowlist rows added to
[`docs/security/tenancy.md`](../security/tenancy.md) §4 per the suspend/reactivate precedent.
A target who is not a member of the path agency gets **404, not 403** — the tenant check, not an
authorization failure.

---

## 4. D2 — Reset 2FA (the support operation)

**The complete artifact set, in one transaction.** Partial clearing is the dangerous failure
mode: a NULLed secret beside a stale `two_factor_confirmed_at` reads as "enrolled" to the login
gate while no authenticator on earth can satisfy it. All four go:

| Column                               | Why it must be cleared                                                                        |
| ------------------------------------ | --------------------------------------------------------------------------------------------- |
| `two_factor_secret`                  | the TOTP seed itself (encrypted at rest)                                                      |
| `two_factor_recovery_codes`          | the bypass set — leaving these would leave a working second factor behind                     |
| `two_factor_confirmed_at`            | **the enrollment flag** — `hasTwoFactorEnabled()` reads it; a stale value strands the account |
| `two_factor_enrollment_suspended_at` | the failed-attempt freeze; leaving it re-strands a person who is being un-stranded            |

That last one is the non-obvious case and it earned its own test: a member who failed enrollment
often enough to be frozen but never confirmed is _exactly_ the person a support reset is for.
Checking only `hasTwoFactorEnabled()` would have 409'd them — the reset refuses precisely when
it is most needed. The endpoint therefore accepts either condition.

**Untouched by design:** `mfa_required`, `mfa_exempted_at`, `mfa_exemption_reason`. Reset is not
an exemption. The account's posture is identical afterwards; the person simply re-enrolls.

**`TwoFactorDisabled` is deliberately NOT dispatched.** The self-service disable path fires it,
and its listener writes an `mfa.disabled` audit row attributed to **the user as actor**. Firing
it here would forge that attribution: the trail would say the member disabled their own 2FA when
a platform admin reset it. The `user.mfa_reset` row (actor = the admin, subject = the member,
reason mandatory, `isSensitiveCredentialAction()`) is the honest record. This is the §5.5
listener-intentionally-not-registered shape, and it has a dedicated negative test so a future
"why doesn't this dispatch the event?" cleanup reddens instead of silently forging attribution.

**409 `mfa.nothing_to_reset`** when there is neither enrollment nor a freeze to clear — no audit
row, no email, nothing written.

---

## 5. D3 — exemption (the policy operation)

**Mechanism (R1).** `users.mfa_exempted_at` + `users.mfa_exemption_reason`, additive-nullable,
schema-only migration, honest `down()`. The migration's own docblock carries the full argument
for why `mfa_required` was rejected, so the reasoning survives next to the schema rather than
only in this file. The rollback note is stated honestly: dropping the columns discards
exemptions granted after the migration ran, which **re-imposes** the MFA gate on anyone
currently exempt — fail-closed, not fail-open — while the audit trail survives regardless.

**Enforcement seam (R2, R3).** Exactly one seam gained the branch: the main SPA's router guards.
They were split rather than parameterized:

| Guard                    | Applies to                                      | Semantics                                                          |
| ------------------------ | ----------------------------------------------- | ------------------------------------------------------------------ |
| `requireMfaEnrolled`     | `auth.2fa.disable`                              | a **precondition** — you cannot disable what you have not enrolled |
| `requireMfaUnlessExempt` | `agency-users.list`, `creator-invitations.bulk` | a **policy gate** — the admin-sensitive surfaces, exemption-aware  |

The split (R3) exists because the two uses were conflated in one guard, and an exemption means
different things to each. An exempt, un-enrolled user landing on the disable page with nothing
to disable is the incoherence the split prevents. Route-name branching _inside_ a security gate
was considered and rejected: a gate that behaves differently depending on which route asked is
the kind of thing that gets misread later. The architecture test's pinned-set change is that
test **working as designed** — it exists to make exactly this kind of guard-list movement
deliberate and visible, which is why its diff message says so.

**Platform admins are never exemptable.** `EnsureMfaForAdmins` is unconditional and does not
consult the new columns, so an exemption on an admin would be a silent no-op that _reads_ like a
granted exemption. The endpoint refuses it: **422 `mfa.platform_admin_not_exemptable`**, nothing
written, no audit row, no email. Removing an exemption from a platform-admin target is
deliberately still allowed — that direction only ever restores a requirement, and refusing it
could strand a row created before this rule existed.

**Both directions take a reason (R5) — a deliberate divergence.** `agency.suspended` requires a
reason and `agency.reactivated` takes none; the un-exempt direction here **does** require one.
Restoring a requirement on a _person_ is a security-posture change about an individual, not the
restoration of an org's default state, and both directions of that deserve a why in the trail.
Recorded as the deliberately-divergent precedent.

**Idempotency.** 409 `mfa.already_exempt` on re-exempting (the original timestamp and reason are
never overwritten — the first grant's provenance is the one that matters) and 409
`mfa.not_exempt` on removing an exemption that does not exist.

**Audit vocabulary.** `user.mfa_reset`, `user.mfa_exempted`, `user.mfa_exemption_removed` — the
`user.*` root, all three `requiresReason()`. Only `user.mfa_reset` joins
`isSensitiveCredentialAction()`: it destroys credential material, while the exemption pair
changes policy about a person and destroys nothing. The free-text
`mfa_exemption_reason` is deliberately **excluded** from `User::auditableAllowlist()` per the
redaction discipline — the state (`mfa_exempted_at`) is auditable as a model diff, the operator's
prose about a named person reaches the trail through the audit row's own `reason` field instead.

**Notifications: three distinct mailables, email-only (R4).** No `NotificationType` entries at
all, following the blacklist precedent — an admin's security action on someone's account does not
belong in the in-app feed alongside campaign activity. Each mail states what changed, what
happens next, and what to do if it was not requested. Two disciplines carried from the AH-051
eyes-on lesson, and enforced at the type level rather than by review: these mailables take a
recipient name and **nothing else**, so there is no admin identity and no rationale available to
leak into the copy.

| Mail                      | Says                                                                                                                                      |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `MfaResetByAdminMail`     | your 2FA was reset by a platform administrator; set it up again at next sign-in; if you did not request this, contact support immediately |
| `MfaExemptionGrantedMail` | the requirement was removed; **if you already have an authenticator it stays active and you will still be asked for a code** (R6)         |
| `MfaExemptionRemovedMail` | the requirement is restored; you will be asked to set 2FA up                                                                              |

---

## 6. D4 — what an admin can never do

Three things, stated as the boundary:

1. **View any secret.** Neither `two_factor_secret` nor `two_factor_recovery_codes` (nor any
   derivative — no count, no "codes remaining", no last-four) is read, selected, or emitted
   anywhere on these paths.
2. **Generate codes for a user.** No endpoint does it; there is no code-generation call in this
   diff.
3. **Complete enrollment on a user's behalf.** Reset _clears_ enrollment; only the user can
   create it, from their own device.

**The proof is a pin, not a promise.** The members endpoint's response is asserted against an
**exact keyset** — not `assertJsonStructure` (which passes on extra keys, the whole failure mode
here). 2FA state reaches the client as two booleans and nothing else. The break-revert:
adding a `recovery_codes_remaining` field to the serializer reddens the pin immediately.

The boundary is also written **on the surface**, under the card heading, in all 24 locales — an
operator reading the page learns what the tool cannot do without opening this document.

---

## 7. D5 — blast radius: what actually happens to the person

Stated from what the code does, and pinned:

- **Active sessions survive a reset.** No session invalidation, no remember-token clearing, no
  `logoutOtherDevices`. Enrollment gates the sensitive surfaces, not the session, so the next
  gated access is where they meet the enrollment prompt. Asserted rather than assumed.
- **Login still challenges an enrolled person.** Login reads _enrollment_
  (`two_factor_confirmed_at`), so an exempt-but-enrolled member is still asked for a code. That
  is correct behaviour, not a bug in the exemption — and mail #2 tells them so in advance.
- **A reset lands mid-session as a re-enrollment prompt**, not a logout.
- **Exempting changes nothing about the person's session or device.** It removes a requirement;
  it does not touch enrollment artifacts.

---

## 8. Gate board

| Gate                            | Result                                                                                                             |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| Backend Pest (full)             | **2688 passed, 10285 assertions**, 0 failures                                                                      |
| Pint                            | clean (2 test files auto-fixed during the build, then clean)                                                       |
| PHPStan (Larastan)              | **no errors** (10 found and fixed in the new test files — see below)                                               |
| `apps/main` Vitest (full)       | **1646 passed / 167 files**                                                                                        |
| `apps/admin` Vitest (full)      | **469 passed / 55 files**                                                                                          |
| `packages/api-client` Vitest    | **204 passed / 9 files**                                                                                           |
| Frontend typecheck (5 projects) | clean                                                                                                              |
| Frontend ESLint                 | **0 errors** (2 pre-existing `vue/no-v-html` warnings, untouched files)                                            |
| Prettier                        | clean on every file this chunk touched                                                                             |
| E2E (Playwright)                | **not run — the named scope fence.** See [§9](#9-d6--scope-fences-and-what-this-chunk-deliberately-did-not-build). |

**Net-new tests:** 5 backend files (~73 tests) + 1 admin SPA component spec (11 tests) + 4 new
guard cases and a new architecture case in `apps/main`.

### The six break-reverts

Every review priority was verified by breaking the implementation, watching a specific test go
red, then reverting and watching it go green. Not "the tests pass" — "the tests would have caught
this".

| #      | Priority                        | What was broken                                                                                | What went red                                                                                            |
| ------ | ------------------------------- | ---------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| **1a** | Structural inertness (server)   | `isMfaExempt()` polarity inverted (`=== null`) — i.e. every NULL row reads as exempt           | 15 tests across 3 files, including the migration test's default-state case and the members keyset render |
| **1b** | Structural inertness (client)   | `requireMfaUnlessExempt`'s branch inverted — the default population walks through the gate     | 3 guard cases, including the explicit INERTNESS case and the byte-identical-to-`requireMfaEnrolled` case |
| **2**  | Platform admin never exemptable | the `isPlatformAdmin()` refusal deleted from `exempt()`                                        | the 422 case                                                                                             |
| **3**  | Complete artifact wipe          | one of the four columns (`two_factor_enrollment_suspended_at`) left out of the reset           | 2 cases, including the frozen-enrollment case                                                            |
| **4**  | Both idempotency directions     | the `already_exempt` and `not_exempt` guards deleted; separately, the `nothing_to_reset` guard | the matching 409 case each time (3 total)                                                                |
| **5**  | The D4 keyset pin               | a `recovery_codes_remaining` field added to the member serializer                              | the exact-keyset case                                                                                    |
| **6**  | Mail i18n ×24                   | one `mfa_admin` string deleted from `lang/lt/auth.php`                                         | `LangParityTest`'s en-SOT keyset gate, naming `lt/auth.php: MISSING mfa_admin.reset.body`                |

**Break-revert #6 is worth reading in full**, because the first attempt did **not** fail. The new
mailable render test passed with the `lt` string missing, since Laravel's translator silently
falls back to `en` for a missing key — the body still rendered, still differed from the English
body (the other lines were Lithuanian), and still contained no raw key. The real tripwire is the
standing `LangParityTest` en-SOT keyset gate, which named the exact missing key immediately. Both
tests are kept: the parity gate catches a _missing_ key, the render test catches a _broken
template_. Neither subsumes the other, and it took breaking the thing to learn which one was
load-bearing.

### Two problems the gate board found that targeted runs could not

1. **A Pest global-helper collision.** Every new backend test file passed when run on its own; the
   **full** suite died with a PHP fatal — `Cannot redeclare function resetUrl()`, already declared
   in `Modules/Boards/BoardResetTest.php`. Pest's top-level `function` declarations share one
   global namespace across the whole suite, so a generically-named helper is a suite-wide
   landmine. This is the **AH-085 bug class recurring** (that chunk's CI failure was the same
   fatal, from the same cause). Fixed by prefixing every helper this chunk adds with `ah087`, and
   the reason is written into the test file's docblock so the next author does not rediscover it.
2. **`UserAttributes.mfa_exempt` broke 12 existing user fixtures.** Adding a _required_ field to
   the shared user type reddened `vue-tsc` across both SPAs and `packages/api-client`. Kept
   required (the backend always emits it, so optional would be a lie) and every fixture updated —
   a small, honest cost that an optional field would have hidden.

---

## 9. D6 — scope fences and what this chunk deliberately did not build

- **No E2E leg.** Named honestly as a pre-existing gap: admin-acting-on-another-user's-MFA has
  no Playwright coverage, and this chunk does not add one. Pest + Vitest carry it (including the
  six break-reverts).
- **No `api-client` admin types.** The admin member types stay local to
  `apps/admin/src/modules/agencies/api/agencies.api.ts`, per the inventory's I4 ruling — the
  admin SPA is the only consumer.
- **No feature flag on the mailables.** Consistent with the house posture for
  individually-targeted admin-action notices.
- **No server-side agency MFA gate** — the deliberate R2 omission, with its own tech-debt entry
  and trigger. This is the single most important thing in this document after
  [§2](#2-production-posture--read-this-before-approving).
- **No bulk operations.** No "exempt all", no CSV. Per-person security changes are per-person.
- **No self-service view of one's own exemption.** `/me` exposes `mfa_exempt` (the guard needs
  it) but no agency-side UI renders it; the affected person learns from the email.
- **`mfa_required` still dormant.** Left with its documented meaning; this chunk did not wire it
  into anything.

---

## 10. Files touched

**Backend — new:** the migration, `AdminAgencyMemberController`, `MemberMfaActionRequest`, three
mailables + three Blade views, four test files.
**Backend — modified:** `User` (two properties, `isMfaExempt()`, cast, fillable, allowlist),
`AuditAction` (three verbs + both flag methods), `AgencyPolicy` (two abilities), `Admin/Routes/api.php`
(four routes), `UserResource` (`mfa_exempt`), `AuditActionEnumTest`, `lang/*/auth.php` ×24.

**Main SPA:** `guards.ts` (the split + the new guard), `useAuthStore` (`isMfaExempt`),
`auth/routes.ts` (two routes repointed), the guard spec + the PMC-1 architecture spec.

**Admin SPA:** `AgencyMembersCard.vue` + spec, `MemberMfaActionDialog.vue`,
`agencies.api.ts` (types + four methods), `AgencyDetailPage.vue` (+ its spec's API mock),
`locales/*/agencies.json` ×24.

**Shared:** `packages/api-client/src/types/user.ts` (`mfa_exempt`, and a corrected comment on
`mfa_required`'s dormancy), plus **12 existing user fixtures** across both SPAs and the
api-client package that had to gain `mfa_exempt: false` — the cost of keeping the field
required rather than optional.

**i18n beyond the two planned bundles.** The three mail **subject** keys also had to be mirrored
into both SPAs' `auth.json` ×24 (48 files). This was not in the plan and is not decoration: the
house `i18n-auth-codes` architecture spec harvests every `auth.*` literal out of
`apps/api/app/Modules/Identity/**` and requires each to resolve in the SPA bundle. The existing
`auth.reset.subject` and `auth.email_verification.subject` keys are mirrored the same way, so
this is the established convention rather than a workaround. The mirrored text was extracted
programmatically from the API lang files, so the subject a person receives and the string the
bundle carries cannot drift apart.

**Docs:** this file, the AH-087 log entry, `security/tenancy.md` (4 allowlist rows),
`03-DATA-MODEL.md` (the two columns + the `mfa_required` dormancy note), `tech-debt.md` (the R2
entry), `runbooks/deploy-log.md` (the PENDING entry).

---

## 11. Deploy obligations (R7)

Three, all in the PENDING entry in [`deploy-log.md`](../runbooks/deploy-log.md):

1. **`php artisan migrate`** — one migration, additive-nullable, **no backfill, no data write**.
2. **Queue-worker restart** — three new mailables + touched `lang/*/auth.php` files (the standing
   mail-touch rule).
3. **PHP-FPM reload** — auth-path PHP moved (`User`, `UserResource`, `AgencyPolicy`, the new
   controller); without fresh bytecode `/me` keeps omitting `mfa_exempt` and the new routes 404.

Plus the two SPA bundles: `apps/admin` (the members card is invisible without it) and
`apps/main` (the guard split).
