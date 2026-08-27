# Platform-admin Agencies section + per-member 2FA controls — Inventory (I1–I4)

> **Outcome: this inventory was built on as AH-087.** See
> [`admin-agency-members-2fa-review.md`](admin-agency-members-2fa-review.md). One I2 finding
> changed the shape of the build: `users.mfa_required` turned out to be unusable as the exemption
> flip (it reads `false` for every agency user today), so the chunk added
> `users.mfa_exempted_at` + `users.mfa_exemption_reason` instead. This file is kept as the
> pre-build snapshot and is deliberately **not** retro-edited.

- **Scope:** read-only inventory only. NO edits, NO plan, NO code. Answers I1–I4 for the
  locked feature: a platform-admin "Agencies" left-menu section (browse agencies → members)
  plus two per-member MFA operations — **Reset 2FA** (clears enrollment, re-enroll required,
  account stays MFA-required) and **Exempt from 2FA** (removes the requirement itself,
  suspend-pattern treatment: mandatory reason, audit verb, row marker).
- **PROD-DATA RISK today: NONE** — this document changes nothing. The eventual build is
  **LOW-MEDIUM** per the kickoff's own framing (writes to live users' MFA enrollment state).
- **Session orientation (fresh session, per house pattern through AH-086b):** `HEAD` =
  `2de36602` = `origin/main` (branch `main`, zero ahead/behind — confirmed via
  `git rev-parse HEAD` / `git rev-parse origin/main` both returning
  `2de366028bc98dff7738bb5a002c30b414d37dca`). Most recent commits: `2de36602` (AH-086b
  Larastan-safe test fix) → `a1b5507c` (AH-086 close-out + AH-086b addendum docs) →
  `0edcffa1` (AH-086b: team-invite email CTA doors through `/agency/sign-in`) → `39c9e5af`
  (AH-086 review) → `2e7c02ad` (AH-086 feat). The load-bearing precedents cited throughout
  this inventory: the **suspend pattern** (agency suspend/reactivate, Sprint 13 D-3) and the
  **AH-051 admin-doors work** (admin-initiated agency↔creator connections).

---

## The two answers that shape the kickoff (headline)

1. **I2 — exempt-mechanism reality: a column exists but is NOT wired to any enforcement.**
   `users.mfa_required` (boolean, since the Sprint-1 `users` migration) is real schema, but
   nothing in the login graph or either SPA's route guards branches on it today (see §I2.3).
   MFA enforcement is currently **route/guard-based** (`EnsureMfaForAdmins` for every platform
   admin unconditionally; `requireMfaEnrolled` on a fixed list of admin-sensitive agency
   routes), not **per-user**. Building "exempt this member" as a real behavioural change means
   either (a) wiring `mfa_required` into the guards for the first time, or (b) introducing the
   new mechanism the kickoff will need to define — this is schema-adjacent, not schema-only,
   and the kickoff must decide which.
2. **I1 — how much surface: the Agencies section already exists; this is a drill-in, not a
   new section.** `Agencies` is already a top-level nav item (`apps/admin/src/core/nav/navItems.ts:57`)
   routing to a live list → detail flow (Sprint 13, D-3) with suspend/reactivate. It shows
   `member_count` on both list and detail but has **no members table** and **no per-member
   anything**. The kickoff's job is adding a members tab/section to the existing
   `AgencyDetailPage.vue`, cloning the shape of the agency-self `AgencyUsersPage.vue` members
   table — not building a browse section from zero.

---

## I1 — Admin browsing of agencies today

### I1.1 — Existing admin surfaces that list/browse people

Two surfaces exist; neither shows 2FA state:

- **Agencies list → detail (Sprint 13, D-3)** — the direct precedent.
  - Nav entry: `{ key: 'agencies', icon: 'mdi-office-building-outline', routeName: 'app.agencies.list' }` at
    `apps/admin/src/core/nav/navItems.ts:57` — a **top-level** item, not nested under a group
    (unlike Creators/Payments/Support/Operations/Compliance, which are `NavGroup`s with
    children). This matches the Phase-1 spec's sidebar listing exactly:
    `docs/09-ADMIN-PANEL.md:130` (`Agencies` is a flat top-level entry, no children shown).
  - Routes: `apps/admin/src/modules/agencies/routes.ts:14-27` — `/agencies` (`app.agencies.list`)
    and `/agencies/:ulid` (`app.agencies.detail`), both `layout: 'admin'`, guards
    `['requireAuth', 'requireMfaEnrolled']` (`apps/admin/src/modules/agencies/routes.ts:12`).
  - Pages: `apps/admin/src/modules/agencies/pages/AgencyListPage.vue` (server-paginated
    `v-data-table-server`, status filter chips all/active/suspended, name search, columns
    name/status/member_count/tier/created_at — `apps/admin/src/modules/agencies/pages/AgencyListPage.vue:34-61`)
    and `AgencyDetailPage.vue` (overview card + a suspension card with suspend/reactivate
    dialogs — `apps/admin/src/modules/agencies/pages/AgencyDetailPage.vue:149-218`). **Neither
    page renders a members table** — `member_count` (`apps/admin/src/modules/agencies/api/agencies.api.ts:28`)
    is the only member-shaped data surfaced.
  - Backend: `AdminAgencyController` (`apps/api/app/Modules/Admin/Http/Controllers/AdminAgencyController.php`)
    — `index` (list, filter by `status=active|suspended`, name search, line 43-84), `show`
    (detail, line 86-95), `suspend` (line 104-136, mandatory reason ≥10 chars, 409 on
    already-suspended), `reactivate` (line 145-175, 409 on not-suspended, no reason). Routes:
    `apps/api/app/Modules/Admin/Routes/api.php:56-61`. Allowlisted cross-tenant in
    `docs/security/tenancy.md:194-197`.
  - Authorization: `AgencyPolicy` (`apps/api/app/Modules/Agencies/Policies/AgencyPolicy.php`) —
    every ability (`viewAny`, `view`, `suspend`, `reactivate`) is a flat
    `$user->type === UserType::PlatformAdmin` check (lines 30-56); an agency's own
    `agency_admin` gets 403 even for their own agency (the "admin-console concern, not an
    in-agency one" doctrine, line 17-20).

- **Support → User search (Sprint 13, D-9)** — the impersonation-search surface, the other
  candidate "admin browsing" surface, but it is a flat cross-type user search
  (`apps/admin/src/modules/support/pages/UserSearchPage.vue`), not agency-scoped: it searches
  any non-admin user by name/email and starts an impersonation session with a mandatory
  reason (≥10 chars, `apps/admin/src/modules/support/pages/UserSearchPage.vue:29`). It does
  **not** show or filter by agency membership and has no MFA-state column. Backend:
  `GET /api/v1/admin/impersonate/users` (`apps/api/app/Modules/Admin/Routes/api.php:96`).
  Not a fit for "browse agency members" — different data shape (any user, not an agency's
  roster) and a different intent (impersonation, not member management).

### I1.2 — The closest existing page shape to clone for a members list

**`AgencyUsersPage.vue`** (agency-self "Team" page in the main app, NOT admin) is the
directly-reusable shape:

- `apps/main/src/modules/agency-users/pages/AgencyUsersPage.vue:1-32` — docblock states it
  consumes `GET /api/v1/agencies/{agency}/members` (paginated) and
  `GET /api/v1/agencies/{agency}/invitations` (admin-only history), `v-data-table-server`
  pattern, filter chip group (role), search, columns name/email/role/status/joined
  (`:67-73`).
- Backend: `MembershipController::index` (`apps/api/app/Modules/Agencies/Http/Controllers/MembershipController.php:41-110`)
  — `?role=`, `?search=` (ilike on name/email, Postgres/SQLite dual-path), `?sort=`
  (name/email/created_at, ±), pagination (`per_page` capped 100, default 25). Resource:
  `AgencyMembershipResource` (`apps/api/app/Modules/Agencies/Http/Resources/AgencyMembershipResource.php:49-61`)
  — shape: `{ id: user.ulid, attributes: { user_id, name, email, role, status
(active|pending, derived from `accepted_at`), created_at, last_active_at (from
`users.last_login_at`) } }`.
- This is agency-self-scoped (any member of the agency can list; the route group's
  `tenancy.agency` middleware enforces membership — comment at
  `apps/api/app/Modules/Agencies/Http/Controllers/MembershipController.php:24-27`). The
  admin equivalent would need its own controller (no `tenancy` context exists on the admin
  guard — see I4) but the query/resource shape (role filter, search, sort, pagination,
  active/pending status derivation) is a direct clone target. Adding an MFA-state column
  would be new work on top of this shape — `AgencyMembershipResource` does not carry it
  today (see I1.3).

**`CreatorDetailPage.vue` / `AgencyDetailPage.vue`** (admin) are the drill-in precedents for
where a per-member section would live structurally: `AgencyDetailPage.vue` already renders
two `v-card`s (overview, suspension) stacked in one page (`:149-218`); a members section would
be a third card/tab following the same pattern, not a new route (no `/agencies/:ulid/members`
route exists today — confirmed via `apps/admin/src/modules/agencies/routes.ts`, only two
records).

### I1.3 — Is MFA enrollment state visible as a boolean anywhere admin-readable today?

**No.** Checked every admin-facing resource that touches a `User`:

- `AgencyMembershipResource` (`apps/api/app/Modules/Agencies/Http/Resources/AgencyMembershipResource.php:49-61`)
  — no MFA field.
- `UserResource` (`apps/api/app/Modules/Identity/Http/Resources/UserResource.php:32-56`) —
  does carry `mfa_required` (line 45) and `two_factor_enabled` (line 46, the
  `hasTwoFactorEnabled()` projection — confirmed boolean, never the secret), but this is the
  **`/me` bootstrap resource for the authenticated user themselves**, not an admin-readable
  resource about _other_ users. No admin controller returns a `UserResource` for a browsed
  agency member.
- The admin impersonation user-search resource (`ImpersonationCandidate` type,
  `apps/admin/src/modules/support/api/impersonation.api.ts` — not yet inspected in full, but
  its rendered columns are name/email/user_type only, per
  `apps/admin/src/modules/support/pages/UserSearchPage.vue:146-153`) — no MFA field.

So: `two_factor_enabled` (boolean, never the secret) and `mfa_required` (boolean) both exist
as safe, already-hidden-from-secret projections on the `User` model (`$hidden` excludes
`two_factor_secret`/`two_factor_recovery_codes`, never the confirmed-at/enrollment-suspended
timestamps either — `apps/api/app/Modules/Identity/Models/User.php:99-104`), but **no admin
surface today exposes either for a browsed (non-self) user.** Building the members list's
2FA-status column is a new resource, not a new capability on an existing one — the boolean
projection pattern (`hasTwoFactorEnabled()`) already exists and is directly reusable.

---

## I2 — The MFA model, precisely

### I2.1 — Where enrollment lives

All on `users` (no separate MFA table). Columns, from the base migration
(`apps/api/database/migrations/0001_01_01_000000_create_users_table.php:54-64`) plus one
addendum (`apps/api/database/migrations/2026_05_08_211627_add_two_factor_enrollment_suspended_at_to_users_table.php`):

| Column                               | Type                                           | Cast              | Purpose                                                                                                                                                                         |
| ------------------------------------ | ---------------------------------------------- | ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `two_factor_secret`                  | `text`, nullable                               | `encrypted`       | TOTP secret. `$hidden`, never in audit allowlist.                                                                                                                               |
| `two_factor_recovery_codes`          | `text`, nullable                               | `encrypted:array` | JSON array of **bcrypt-hashed** recovery codes, encrypted at rest (defense-in-depth). `$hidden`, never in audit allowlist.                                                      |
| `two_factor_confirmed_at`            | `timestamp`, nullable                          | `datetime`        | Set only by `confirm()`; `hasTwoFactorEnabled()` = `!== null`. Never in audit allowlist.                                                                                        |
| `two_factor_enrollment_suspended_at` | `timestamp`, nullable (added Sprint 1 chunk 5) | `datetime`        | Set after 10 invalid TOTP/recovery attempts in 15 min; freezes the MFA login gate until cleared. Never in audit allowlist. **No admin clear-endpoint exists today** (see I2.5). |
| `mfa_required`                       | `boolean`, default `false`                     | `boolean`         | Set at account creation only (admins `true`, everyone else `false` by default — see I2.3). Auditable (in `User::auditableAllowlist()`).                                         |

Model: `apps/api/app/Modules/Identity/Models/User.php` — property docblock lines 40-44,
`casts()` lines 215-235, `$hidden` lines 99-104, `auditableAllowlist()` lines 123-143 (note:
all four `two_factor_*` columns are excluded from the audit allowlist by _absence_, listed
explicitly in the docblock comment lines 110-116 — `mfa_required` IS allowlisted).

### I2.2 — What "reset" must clear (every artifact)

Mirrors the existing self-service disable path exactly —
`TwoFactorEnrollmentService::disable()` (referenced in
`docs/reviews/sprint-1-chunk-5-review.md:16` and priority #5, line 41) nulls, in one
transaction:

1. `two_factor_secret` → `null`
2. `two_factor_recovery_codes` → `null`
3. `two_factor_confirmed_at` → `null`
4. `two_factor_enrollment_suspended_at` → `null` (the self-service path clears this too, per
   the same transactional `disable()`)

An admin "Reset 2FA" is structurally the **same four-column wipe**, with `mfa_required`
**untouched** (stays whatever it was — the kickoff's "account stays MFA-required" instruction
maps directly to leaving this column alone). This is a **net-new admin write path** — no
admin controller touches any `two_factor_*` column today (confirmed: `two_factor` does not
appear anywhere under `apps/api/app/Modules/Admin/`).

### I2.3 — Does the MFA requirement support per-user variance today?

**A column exists (`mfa_required`) but nothing reads it to gate anything.** Traced the full
chain:

- **Login graph** (`AuthService::login()`, `apps/api/app/Modules/Identity/Services/AuthService.php:242-296`)
  — the MFA gate is `if ($user->hasTwoFactorEnabled())` (line 242), i.e. gated on whether the
  user has **completed enrollment** (`two_factor_confirmed_at !== null`), never on
  `mfa_required`. A user with `mfa_required = true` who has never enrolled logs in with just a
  password — nothing in the login graph forces enrollment.
- **Admin-guard enforcement** (`EnsureMfaForAdmins`,
  `apps/api/app/Modules/Identity/Http/Middleware/EnsureMfaForAdmins.php:47-71`) — checks
  `$user->hasTwoFactorEnabled()` (line 60) and a **global** config flag
  `auth.admin_mfa_enforced` (line 75, default `true`, only overridable in `local` env). It
  does **not** read `$user->mfa_required` at all — every platform admin is forced into 2FA
  unconditionally (subject only to the local-dev override), not because their row says
  `mfa_required = true`.
- **Agency-side FE guard** (`requireMfaEnrolled`,
  `apps/main/src/core/router/guards.ts:117-131`) — reads `store.isMfaEnrolled`, which is
  `computed(() => user.value?.attributes.two_factor_enabled ?? false)`
  (`apps/main/src/modules/auth/stores/useAuthStore.ts:98`). Again keyed on **enrollment
  completion**, not `mfa_required`. This guard is applied to a **fixed route list**
  (`/agency-users`, `/creator-invitations/bulk` — per
  `docs/reviews/sprint-3-chunk-4-review.md:74`), not to every agency user's session — most
  agency routes (dashboard, brands, settings) carry `requireAuth` only, no MFA gate at all
  (`docs/tech-debt.md:822`, `docs/reviews/sprint-3-self-review.md:63`).
- **`mfa_required`'s only actual consumer**: it is set once at row-creation time by factories/
  seeders/commands (`CreateAdminUser.php:131,182` — `true` for admins, `false` for
  `--role=admin` non-super cases; `Sprint1IdentitySeeder.php:76,124`; `BulkInviteService.php:133`;
  `CreateAgencyWithAdminController.php:115` — all `false` for agency users) and exposed
  read-only via `UserResource` (`apps/api/app/Modules/Identity/Http/Resources/UserResource.php:45`)
  and the TS type comment `packages/api-client/src/types/user.ts:94-99` ("today always
  platform admins... the SPA uses this to drive the 2FA-enrollment-required flow" — **this
  comment is aspirational/stale**: the actual guard code reads `two_factor_enabled`, not
  `mfa_required`, per the two guard files cited above).

**Conclusion for the kickoff:** today, "the MFA requirement" is not a per-user toggle that
does anything — it's an artifact of (a) which route you're on and (b) whether you've
personally completed enrollment. There is no live code path where flipping `mfa_required`
from `true` to `false` changes observable behavior for an agency user, because no agency
route ever reads that column. **"Exempt from 2FA" as scoped by the kickoff (removing the
requirement) requires either wiring `mfa_required` into `requireMfaEnrolled`/the login graph
for the first time (repurposing existing schema, but new _logic_), or defining a distinct
mechanism** — this is the decision I2 flags as unresolved, not a pure "flip an existing
lever" operation.

### I2.4 — Blast radius: sessions, remember-tokens, in-flight sign-ins

No code path invalidates an existing session on any comparable mutation today — traced by
precedent, since Reset/Exempt would presumably follow the same posture unless the kickoff
decides otherwise:

- **`is_suspended` (user-level hard suspend)** — checked only at login
  (`AuthService::login()`, `apps/api/app/Modules/Identity/Services/AuthService.php:193-197`,
  step 4 of the documented decision graph at lines 46-47). `isSuspended()`
  (`apps/api/app/Modules/Identity/Models/User.php:197-200`) is referenced in exactly 4 PHP
  files project-wide (`AccountLockoutService`, `SignUpService`, `LoginResult`, `AuthService`)
  — never in a per-request middleware. A user suspended mid-session keeps their existing
  session until it naturally expires or they re-authenticate.
- **`agency.suspended` (agency-level)** — same posture, one level up:
  `AuthService::primaryAgencyIsSuspended()` (`apps/api/app/Modules/Identity/Services/AuthService.php:383-393`)
  is checked only inside `login()` (line 217). The `AdminAgencyController` docblock states
  this explicitly: "the auth-layer login block reads `suspended_at`"
  (`apps/api/app/Modules/Admin/Http/Controllers/AdminAgencyController.php:36`) — login-time
  only, not a live-session kill switch.
- **`sessions` table** — a standard Laravel DB session store
  (`apps/api/database/migrations/0001_01_01_000020_create_sessions_table.php`, not read in
  detail but no admin surface anywhere queries or deletes rows from it by `user_id`; the only
  session-adjacent admin surface is impersonation, an entirely separate mechanism with its
  own `admin_impersonation_sessions` table).
- **`remember_token`** — a standard Laravel column (`users.remember_token`,
  `apps/api/database/migrations/0001_01_01_000000_create_users_table.php:36`); no code
  anywhere clears it programmatically outside Laravel's own remember-me rotation.

**Precedent conclusion:** the house pattern for "does a punitive/state-changing admin action
kill an active session" is **no, not today, for anything** — reset/exempt would be consistent
with existing behavior if they also do nothing to live sessions (the mutation takes effect on
the user's _next_ authentication event, not their current one). If the kickoff wants
mid-session enforcement, that would be net-new infrastructure with no existing precedent to
clone, and should be flagged as such rather than assumed free.

### I2.5 — The enrollment-suspended-at gap (a related, not-yet-solved admin need)

`two_factor_enrollment_suspended_at`'s own migration comment says the state "lives on this
column so **admins** can list 'stuck' users in a single query" and "until an admin reviews
and clears it" (`apps/api/database/migrations/2026_05_08_211627_add_two_factor_enrollment_suspended_at_to_users_table.php:14-19`)
— but **no admin controller, route, or UI clears this column today** (confirmed: zero hits
for `enrollment_suspended` under `apps/api/app/Modules/Admin/`). This is a second, narrower
"unfreeze a locked-out user's 2FA" need adjacent to but distinct from "Reset 2FA" — the
kickoff's Reset operation (which the inventory's I2.2 shows already clears this column as
one of the four artifacts) would incidentally close this gap as a side effect, worth noting
explicitly since it's the first time any admin surface touches 2FA state at all.

---

## I3 — The two operations' precedents

### I3.1 — The suspend pattern, exact shape (cite it)

The complete, load-bearing precedent — agency suspend (Sprint 13, D-3):

- **Reason field:** `SuspendAgencyRequest` (`apps/api/app/Modules/Admin/Http/Requests/SuspendAgencyRequest.php:29-34`)
  — `'reason' => ['required', 'string', 'min:10', 'max:2000']`. FE mirrors the min-length as a
  UX gate (not just a submit-time 422): `apps/admin/src/modules/agencies/pages/AgencyDetailPage.vue:38-39`
  (`const MIN_REASON = 10`), disabling the confirm button until met (`:247`).
- **Audit verb:** `AuditAction::AgencySuspended = 'agency.suspended'`
  (`apps/api/app/Modules/Audit/Enums/AuditAction.php:416`), gated into
  `requiresReason(): true` explicitly (`:455`, grouped with `AdminImpersonationStarted` and
  `FeatureFlagToggled` under the "Sprint 13 — each...must answer 'why'" comment, `:447-452`).
  Reverse action (`AgencyReactivated`, `:417`) is **not** in `requiresReason()` — "restores the
  default/safe state" (`:453`).
- **Row marker rendering:** the agency-list status chip
  (`apps/admin/src/modules/agencies/pages/AgencyListPage.vue:189-202` — a `v-chip` keyed on
  `attributes.is_suspended`, color `error`/`success`, i18n label suspended/active) and the
  detail-page suspension card (`apps/admin/src/modules/agencies/pages/AgencyDetailPage.vue:191-218`
  — status chip in the header line 113-124, plus a card showing `suspended_at` +
  `suspended_reason` when suspended).
- **Idempotency:** 409 + a semantic code on the wrong-state transition
  (`agency.already_suspended` / `agency.not_suspended`,
  `apps/api/app/Modules/Admin/Http/Controllers/AdminAgencyController.php:108-110,149-151`) —
  never re-stamps or silently no-ops.
- **Transaction:** state flip + audit row in one `DB::transaction`
  (`apps/api/app/Modules/Admin/Http/Controllers/AdminAgencyController.php:116-129`).
- **Authorization:** flat `platform_admin` type check, no ownership nuance
  (`AgencyPolicy::suspend`/`reactivate`, lines 44-56).

This is the exact shape "Exempt from 2FA" is instructed to mirror. "Reset 2FA" is explicitly
**not** suspend-pattern in the kickoff's own framing (support-driven, not a security
downgrade) — the closer precedent for Reset is the self-service `disable()` path's
transactional four-column wipe (I2.2), without the reason/marker apparatus.

### I3.2 — AH-051's admin-action notification posture, applied here

Two distinct postures coexist in the codebase for admin-initiated actions, and the split is
exactly the axis the kickoff needs:

**Posture A — "admin operating on platform infrastructure": no notification.** The Sprint-13
admin-panel verbs (`agency.suspended`, `agency.reactivated`, `admin.impersonation_started`,
`admin.impersonation_ended`, `feature_flag.toggled`) are explicitly **excluded** from
`NotificationType` by design — the `AuditAction` enum's own comment: "Platform-admin-only
verbs (**NOT** NotificationType cases — admin actions notify through the admin consumer
shell, not the creator/agency notification vocabulary)"
(`apps/api/app/Modules/Audit/Enums/AuditAction.php:400-402`). Confirmed by absence: neither
`AgencySuspended` nor `AgencyReactivated` appears anywhere in
`apps/api/app/Modules/Notifications/Enums/NotificationType.php`, and no mailable is sent from
`AdminAgencyController::suspend()`/`reactivate()` (no `Mail::` call in either method). **An
agency whose login is cut off today receives zero email or in-app warning** — they simply
fail at their next login attempt.

**Posture B — "admin acting on an individual's account/relationship": dual-emit
notification.** AH-051's two individually-consequential verbs
(`agency_creator_relation.admin_connected`, `agency_creator_relation.disconnected`) both got
matching `NotificationType` cases and mailables — `RelationAdminConnected` /
`RelationDisconnected`
(`apps/api/app/Modules/Notifications/Enums/NotificationType.php:89-101`, comment: "the
recipient must see" these). Door 1 (`admin_requested`) deliberately did **not** get a type —
it rides the existing `ConnectionRequestMail`, "exactly like an agency-sent request" (`:97-99`).

**Recommendation input for the kickoff (per operation, as the kickoff itself invites):**

- **Reset 2FA** — support-driven, user-requested (kickoff's own framing: "the user lost their
  device"). The closest precedent is Posture B (an individually-consequential account change)
  but initiated _for_ the user, not _against_ them — arguably wants notification precisely
  because the user needs to know re-enrollment is required at next sign-in (functional, not
  just accountability), unlike agency-suspend's silence.
- **Exempt from 2FA** — a policy/security-downgrade act on a specific person's account,
  closer to Posture B's "recipient must see" framing than Posture A's silent
  infrastructure-toggle framing, since it names one person, not a whole tenant. But it is also
  the suspend-pattern's sibling, and agency-suspend (the suspend-pattern's own precedent) is
  Posture A (silent). **This is a genuine precedent conflict the kickoff must rule on**, not
  something answerable by pattern-matching alone — flagging rather than resolving it.

### I3.3 — Audit vocabulary fit

House convention: `<subject>.<verb>`, lowercase dot notation, subject = "the singular,
lower-case form of the model or domain noun the action operates on"
(`apps/api/app/Modules/Audit/Enums/AuditAction.php:22-24`). Existing `mfa.*` namespace is
scoped to **self-service** actions on one's own MFA (`mfa.enabled`, `.confirmed`, `.disabled`,
`.recovery_codes_regenerated`, `.recovery_code_consumed`, `.enrollment_suspended` — all listed
`apps/api/app/Modules/Audit/Enums/AuditAction.php:45-50`). The subject in every existing verb
is the _thing acted on_, not the _actor_ — an admin resetting someone else's MFA is not a
self-service `mfa.disabled` replay (that verb is also `isSensitiveCredentialAction() === true`,
line 480, which drives extra allowlist scrutiny that may or may not fit an admin-initiated
event the same way).

Two naming directions both fit the house convention and have direct precedent:

- **Extend the `mfa.*` namespace** with admin-distinguishable verbs, e.g. `mfa.admin_reset` /
  `mfa.admin_exempted` — mirrors how AH-051 distinguished admin action from user action
  _within_ an existing namespace (`agency_creator_relation.admin_requested` /
  `.admin_connected` sit alongside the non-admin `.created`/`.updated`/`.deleted` in the same
  `agency_creator_relation.*` family, comment: "make an admin-driven connection ... FOREVER
  distinguishable from an agency-initiated one" — `apps/api/app/Modules/Audit/Enums/AuditAction.php:177-180`).
- **A `user.*`-rooted pair**, e.g. `user.mfa_reset` / `user.mfa_exempted` — mirrors the
  existing `user.suspended`/`user.unsuspended` pair (`:55-56`) which is the closest existing
  "admin acts on a user's account state" precedent already in the enum, and which is already
  in `requiresReason(): true` (`:433-434`) — the exact treatment the kickoff wants for Exempt.

The `user.suspended`/`.unsuspended` pair is arguably the tighter fit for "distinguishable
admin verb naming a specific user-state consequence" than reusing `mfa.*` (which today means
"the user did this to themself"), but both directions are house-legal; this is a kickoff
naming decision, not a technical constraint.

---

## I4 — Ripple

### I4.1 — Admin routes + tenancy/allowlist notes

- No admin route for agency members exists today (`apps/api/app/Modules/Admin/Routes/api.php:56-61`
  only has index/show/suspend/reactivate under `agencies.*`). A new
  `GET /api/v1/admin/agencies/{agency}/members` (list) plus two per-member POST routes (reset,
  exempt) would all need:
  1. A new tenancy-allowlist row set in `docs/security/tenancy.md` §4 — the admin route group
     mounts no `tenancy`/`tenancy.set` middleware at all (comment,
     `apps/api/app/Modules/Admin/Routes/api.php:31-37`: "the fail-closed `tenancy` alias is
     NEVER mounted here — it would 500 every admin request"), so any new admin route is
     cross-tenant by construction and must be documented per the existing three-row template
     (`docs/security/tenancy.md:194-197`).
  2. A tenancy-scope decision on `AgencyMembership` reads: **`AgencyMembership` does NOT use
     the `BelongsToAgency` trait** (confirmed — grep for
     `use App\Core\Tenancy\BelongsToAgency;` across the codebase returns 17 models,
     `AgencyMembership` is not among them; contrast `AgencyUserInvitation`, which is). So,
     unlike AH-051's `AgencyCreatorRelation` reads/writes (which needed
     `withoutGlobalScope(BelongsToAgencyScope)` for reads and `TenancyContext::runAs(...)` for
     writes — `docs/security/tenancy.md:155-157`), a new admin members-list endpoint querying
     `AgencyMembership::where('agency_id', $agency->id)` needs **no scope bypass at all** —
     it's an ordinary tenant-less query, matching `AdminAgencyController`'s own existing
     `memberCountsFor()` pattern (`apps/api/app/Modules/Admin/Http/Controllers/AdminAgencyController.php:209-236`,
     which already reads `AgencyMembership` with a plain `whereIn`, no scope machinery).
  3. Whether the reset/exempt writes target `users.*` columns need `runAs` at all: **no** —
     `User` is not agency-scoped (a `BelongsToMany` via the pivot, not a tenant-scoped model
     itself), so unlike AH-051's `AgencyCreatorRelation` writes, these are ordinary
     `User::forceFill(...)->save()` calls with no tenancy machinery involved. This makes the
     write path structurally _simpler_ than AH-051's Door 2/disconnect, despite being reached
     from an agency-scoped browsing context.
- Route-model binding note: the member being acted on is a `User` reached via an `Agency`
  parent (`/admin/agencies/{agency}/members/{user}/...`), so both should be route-model bound
  the way AH-051 double-bound `{creator}` and `{agency}`
  (`docs/security/tenancy.md:157`) — with an explicit membership-exists check (the user must
  actually belong to that agency), since nothing currently enforces that relationship at the
  route layer.

### I4.2 — Existing tests pinning MFA enrollment behavior (both suites)

**Backend (Pest), `apps/api/tests/Feature/Modules/Identity/`:**
`TwoFactorEnrollmentTest.php`, `TwoFactorDisableTest.php`, `TwoFactorLoginTest.php`,
`TwoFactorAuditTest.php`, `TwoFactorEdgeCasesTest.php` — five files covering enable/confirm,
disable (the 4-column wipe a Reset operation would mirror), the login-gate branches, the
"no plaintext in audit" invariant (directly relevant — a Reset/Exempt audit row must honor the
same discipline), and edge cases (constant-time recovery-code check, hasher-exception
swallowing).

**Playwright E2E:**

- Main app (`apps/main`): `apps/main/playwright/specs/2fa-enrollment-and-sign-in.spec.ts` —
  **partially skipped**: the sole "full enrollment + re-sign-in flow" test carries
  `test.skip(...)` (tracked in `docs/tech-debt.md:963-969`, `TODO(spec-19-skip)` anchor) due
  to a TOTP-minting helper gap during in-flight enrollment; the rest of the spec (including
  step 7's re-sign-in `auth.mfa_required` assertion, `:210`) is active.
- Admin app (`apps/admin`): `apps/admin/playwright/specs/admin-mandatory-mfa-enrollment.spec.ts`
  (the D7 deep-link chained-enrollment journey) and `apps/admin/playwright/specs/admin-sign-in.spec.ts`
  (happy path against a pre-enrolled admin) — both active, per
  `docs/tech-debt.md:1089,1254`.
- Neither E2E suite exercises an _admin acting on another user's_ MFA state — both are
  self-service (an admin/agency user enrolling or signing in as themselves). A new
  Reset/Exempt admin-doors feature would need net-new E2E coverage; there is no existing spec
  to extend.

**Unit/component specs (Vitest, both apps):** `EnableTotpPage.spec.ts`,
`DisableTotpPage.spec.ts`, `VerifyTotpPage.spec.ts`, `RecoveryCodesDisplay.spec.ts` (both
`apps/main` and `apps/admin` have their own copies under `src/modules/auth/pages/`) plus the
router-guard architecture tests (`apps/main/tests/unit/core/router/guards.spec.ts`,
`apps/admin/tests/unit/core/router/guards.spec.ts` — `requireMfaEnrolled` describe blocks) and
the route-walking `agency-routes-mfa-guard.spec.ts` (referenced throughout
`docs/reviews/sprint-3-chunk-4-review.md`, e.g. line 78, the PMC-1 negative-case precedent for
"only the named routes carry `requireMfaEnrolled`").

### I4.3 — i18n scope estimate

Both apps run the full 24-locale set. Admin app already has a scoped `agencies.json` per
locale (`apps/admin/src/core/i18n/locales/{en,ro,el,fi,hr,ga,mt,fr,bg,lv,es,sl,cs,et,it,de,
da,pl,sv,sk,hu,pt,lt,nl}/agencies.json` — 24 files confirmed present). A members
tab/table/reset-dialog/exempt-dialog set of copy would extend this **existing** file (not a
new namespace) — by AH-051/AH-086b precedent (both call out "×24" locale key counts, e.g.
`docs/reviews/adhoc-changes-log.md:1978-1981` for AH-051's `creators.php` ×24), the estimate
is proportional to new UI copy strings (headings, table columns, two confirm dialogs with
reason fields, two success/error toasts, one row-marker label) × 24, following the "flaky 10"
real-MT discipline referenced throughout (`docs/reviews/agency-sign-in-review.md:33`).

### I4.4 — api-client admin types

**No centralized admin types exist in `packages/api-client`.** Confirmed:
`packages/api-client/src/types/` has 11 files (`creator.ts`, `agency.ts`, `auth.ts`, `index.ts`,
`messaging.ts`, `board.ts`, `campaign.ts`, `user.ts`, `availability.ts`, `agency.spec.ts`,
`notification.ts`) — no `admin.ts`. `packages/api-client/src/types/agency.ts` (883 lines) has
zero `Admin`-prefixed exports (single incidental mention of "Admin-only KYC PII" in a comment,
line 366). The existing `AdminAgency`/`AdminAgencyAttributes`/`AdminAgencyListResponse` types
live **locally** in `apps/admin/src/modules/agencies/api/agencies.api.ts:18-59` — the same
per-app-local pattern the admin `creators` module uses (`apps/admin/src/modules/creators/api/creators.api.ts`,
not itself centralized either). New `AdminAgencyMember`/reset/exempt request-response types
should follow this established local convention, not introduce a new centralized-types
precedent. (Contrast: `AgencyMembershipResource` — the agency-_self_ member type — IS
centralized in api-client, imported by `AgencyUsersPage.vue:35-40`; the admin/self split in
where types live is itself a house pattern, not an oversight.)

---

## Appendix — file/line index (for kickoff drafting)

| Area                                               | File                                                                                                       | Lines            |
| -------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- | ---------------- |
| Nav registry                                       | `apps/admin/src/core/nav/navItems.ts`                                                                      | 55-140           |
| Agencies routes                                    | `apps/admin/src/modules/agencies/routes.ts`                                                                | 1-27             |
| Agency list page                                   | `apps/admin/src/modules/agencies/pages/AgencyListPage.vue`                                                 | 1-236            |
| Agency detail page                                 | `apps/admin/src/modules/agencies/pages/AgencyDetailPage.vue`                                               | 1-294            |
| Agency admin API client                            | `apps/admin/src/modules/agencies/api/agencies.api.ts`                                                      | 1-83             |
| Admin agency controller                            | `apps/api/app/Modules/Admin/Http/Controllers/AdminAgencyController.php`                                    | 1-270            |
| Admin agency routes                                | `apps/api/app/Modules/Admin/Routes/api.php`                                                                | 1-120            |
| Suspend request                                    | `apps/api/app/Modules/Admin/Http/Requests/SuspendAgencyRequest.php`                                        | 1-40             |
| Agency policy                                      | `apps/api/app/Modules/Agencies/Policies/AgencyPolicy.php`                                                  | 1-57             |
| Agency-self members controller                     | `apps/api/app/Modules/Agencies/Http/Controllers/MembershipController.php`                                  | 1-166            |
| Agency-self members resource                       | `apps/api/app/Modules/Agencies/Http/Resources/AgencyMembershipResource.php`                                | 1-63             |
| Agency-self members page                           | `apps/main/src/modules/agency-users/pages/AgencyUsersPage.vue`                                             | 1-459            |
| User model                                         | `apps/api/app/Modules/Identity/Models/User.php`                                                            | 1-241            |
| AgencyMembership model                             | `apps/api/app/Modules/Agencies/Models/AgencyMembership.php`                                                | 1-103            |
| AuthService (login graph)                          | `apps/api/app/Modules/Identity/Services/AuthService.php`                                                   | 1-411            |
| EnsureMfaForAdmins                                 | `apps/api/app/Modules/Identity/Http/Middleware/EnsureMfaForAdmins.php`                                     | 1-85             |
| requireMfaEnrolled (main)                          | `apps/main/src/core/router/guards.ts`                                                                      | 117-132          |
| useAuthStore (isMfaEnrolled)                       | `apps/main/src/modules/auth/stores/useAuthStore.ts`                                                        | 96-99            |
| useAdminAuthStore                                  | `apps/admin/src/modules/auth/stores/useAdminAuthStore.ts`                                                  | 94-198           |
| UserResource                                       | `apps/api/app/Modules/Identity/Http/Resources/UserResource.php`                                            | 1-93             |
| user.ts types (mfa_required doc)                   | `packages/api-client/src/types/user.ts`                                                                    | 82-108           |
| AuditAction enum                                   | `apps/api/app/Modules/Audit/Enums/AuditAction.php`                                                         | 1-488            |
| NotificationType enum                              | `apps/api/app/Modules/Notifications/Enums/NotificationType.php`                                            | 1-190            |
| Tenancy cross-tenant allowlist                     | `docs/security/tenancy.md`                                                                                 | 89-207           |
| Users migration (base)                             | `apps/api/database/migrations/0001_01_01_000000_create_users_table.php`                                    | 1-75             |
| Enrollment-suspended migration                     | `apps/api/database/migrations/2026_05_08_211627_add_two_factor_enrollment_suspended_at_to_users_table.php` | 1-44             |
| Sprint 1 chunk 5 review (2FA build)                | `docs/reviews/sprint-1-chunk-5-review.md`                                                                  | 1-71+            |
| AH-051 ad-hoc log entry                            | `docs/reviews/adhoc-changes-log.md`                                                                        | 1951-2004        |
| AH-051 review                                      | `docs/reviews/admin-connections-review.md`                                                                 | 1-40+            |
| Admin panel spec (nav)                             | `docs/09-ADMIN-PANEL.md`                                                                                   | 106-158          |
| Admin agency mgmt tests                            | `apps/api/tests/Feature/Modules/Admin/AdminAgencyManagementTest.php`                                       | 1-187            |
| tech-debt (enrollment-suspended admin gap context) | `docs/tech-debt.md`                                                                                        | 963-969, 477-480 |
