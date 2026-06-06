# S11.0 — Notification subsystem · Chunk 2 Review — Clean retrofit + agency fan-out (backend)

**Status:** Closed. Spot-check passed (no PMC) — staff-exclusion asserted as absence (#3 + #4 + the resolver unit test); `notifiableMembers()` filters at the query level and is soft-delete-clean (empirical leak test green); the uniform-actor resolution is null-safe for system-driven transitions (the path deferred-#5 will rely on, proven now); the F4 asymmetry built as N-in-app / 1-email with `assertQueued(...,1)` to the inviter; `VerifyPostedContentJobTest:108` untouched and green.

**Reviewer:** drafted by Cursor (implementation); independently spot-checked + accepted.

**Reviewed against:** the S11.0 Chunk-2 kickoff (D-1…D-9), the Ch2 pre-kickoff inventory, the Ch1 review (the as-built `NotificationService` seam + the proof-consumer pattern), `PROJECT-WORKFLOW.md §5` (5.1 tripwire, 5.2 Event::fake split, 5.6 idempotency), `tech-debt.md` (the three notification entries + the `invited_by_user_id` entry).

This chunk retrofits the **zero-new-vocabulary** send-sites onto the Ch1 in-app seam and introduces the **admins+managers fan-out** for the agency-facing assignment events — in-app _alongside_ the untouched emails. It deliberately **stops** before any site needing a net-new `AuditAction` verb (the bucket-c sites) or FE (the §945 banner), which are deferred to a consolidated tech-debt entry. The SPA notification centers are Ch3.

## Decisions (built as proposed)

- **D-1 · Zero-new-vocabulary scope** — only sites whose `NotificationType` is already a member or is a clean enum-add, plus the fan-out.
- **D-2 · Retrofit set = 5 sites** (in-app beside untouched `Mail::queue`): `PostManuallyVerifiedMail` (#2, creator), `DraftSubmittedForReviewMail` (#3, agency fan-out), `ContractAcceptedMail` (#4, agency fan-out), `CreatorApprovedMail` (#10, creator), `CreatorRejectedMail` (#11, creator). #1 was Ch1.
- **D-4 · Two clean enum-adds** — `creator.approved`, `creator.rejected` (both live `AuditAction` values) into `NotificationType` + both tripwire halves (`$expected` list + the `auditAction()` one-vocabulary tie).
- **D-5 · `Agency::notifiableMembers()`** — `memberships()->whereIn('role', [admin, manager])`, deduped, returns hydrated Users; staff excluded at the query level; soft-delete-clean (the relation auto-applies `whereNull(deleted_at)` before the role filter).
- **D-6 · F4 — in-app fans out, email stays single-inviter** for #3/#4 (N in-app, 1 email to the inviter); asymmetry commented at each seam.
- **D-7 · Placement** — #2/#3/#4 from `SendAssignmentNotifications`; #10/#11 inline after the existing `Mail::queue` in `AdminCreatorController`.
- **D-8 · #9 (blacklist) excluded** — a creator's own-blacklisting notification is counterproductive; `CreatorBlacklistedMail` unchanged (email-only, existing optional-creator flag); `creator.blacklisted` not added.
- **D-9 · Tenancy** — `agency_users` is a non-`BelongsToAgency` Pivot; no `runAs` needed; emitted rows are per-user, N above tenancy.
- **Approved deviation:** actor resolved uniformly from `AssignmentTransitioned::$triggeredByUserId` (the user who drove the transition) rather than the kickoff's "system → null" framing — more correct (every human-driven row gets a real actor), null-safe via a `!== null` guard before `User::find()`, falls to `null` only for genuinely system-driven transitions. Approved.

## Spot-check anchors → evidence

| Anchor                                             | Evidence                                                                                                                                            |
| -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Staff exclusion is absence                         | `NotificationFanOutTest`: `Notification::where(recipient, staff)->count() === 0` on #3 + #4; resolver `not->toContain(staff)`.                      |
| Fan-out filters at query level + soft-delete clean | `notifiableMembers()` `whereIn('role', …)` on the soft-delete-aware relation; empirical soft-deleted-manager test → no leaked recipient.            |
| Actor null-safe for system transitions             | `!== null` guard short-circuits `User::find()`; `manually_verified` w/ `triggeredByUserId: null` → row written, `actor_user_id === null`, no throw. |
| F4 asymmetry (email single-inviter)                | `assertQueued(DraftSubmittedForReviewMail/ContractAcceptedMail, 1)` to the inviter; `VerifyPostedContentJobTest:108` untouched + green.             |
| Preserve-current pref still gates emit             | opt-out test cases (unprompted) prove the Ch1 contract holds under real consumers.                                                                  |

## Verification

14 new Ch2 tests green (fan-out incl. staff-exclusion absence + soft-delete leak + null-actor + opt-out; creator-lifecycle ride-email + opt-out); Notifications+Agencies sweep 225 passed / 1 skipped; admin Creators 69 passed; Campaigns review/contract/resolution green; `VerifyPostedContentJobTest:108` untouched. Pint clean (pulled two inline FQCNs into imports); Larastan level 8 clean (622 files).

## Out of scope

- **Ch3:** agency + creator notification centers, badge, polling, prefs write-back UI.
- **Deferred to tech-debt (each blocked on a net-new `AuditAction` verb under one-vocabulary):** #5 verification-failed, #6 resubmit (fresh + in-place), #7 contract-attached, the accept/decline agency emitter, and the §945 approve/reject banner repoint (banner additionally needs FE). Trigger: the next Audit-verb mint (e.g. the Messaging system-message verbs) or a dedicated notifications cleanup pass.
- **Out entirely:** fanning email out (D-6); #9 blacklist in-app (D-8).

## Docs

The three notification tech-debt entries updated to genuinely-partially-resolved; a new consolidated entry records the deferred bucket-c sites + accept/decline + the `:945` banner repoint with the verb-mint trigger. No `03-DATA-MODEL`/`tenancy.md` change (code-level enum, no new routes).

---

_Provenance: drafted by Cursor (S11.0 Chunk 2 build); merged + spot-checked by Claude (staff-exclusion-as-absence / query-level-soft-delete-clean / null-safe-actor all verified at anchor; uniform-actor deviation approved). No PMC._
