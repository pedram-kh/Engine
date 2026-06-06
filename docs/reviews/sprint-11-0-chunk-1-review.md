# S11.0 — Notification subsystem · Chunk 1 Review — Subsystem core (backend)

**Status:** Closed. Spot-check passed (no PMC).

**Reviewer:** drafted by Cursor (implementation); independently spot-checked + accepted.

**Reviewed against:** the S11.0 Chunk-1 kickoff (D-1…D-11), the Chunk-1 pre-kickoff inventory, `docs/tech-debt.md` (the three notification entries), `docs/security/tenancy.md`, `docs/07-TESTING.md`, `docs/PROJECT-WORKFLOW.md §5` (5.1 source-inspection tripwire, 5.2 Event::fake split, 5.6 idempotency, 5.8 reasoned-removal).

This is the **spine** of the deferred notification subsystem — the store, the typed vocabulary, the single emit seam, per-user prefs with preserve-current defaults, and the four `/me/notifications` endpoints — proven end-to-end through one real consumer (draft-reviewed → creator). It deliberately **stops** before the retrofit + agency fan-out (Ch2) and the SPA centers (Ch3). Email is untouched: the subsystem emits in-app _alongside_ the existing Mailables, not in place of them.

## Decisions (confirmed at plan-pause, built as proposed)

- **D-4 · Custom `notifications` table** (not Laravel's stock database channel): `recipient_user_id` RESTRICT, nullable `actor_user_id` (nullOnDelete), nullable manual polymorphic subject, `type` varchar(64), `data` jsonb, `read_at`, append-only `created_at`. Indexes: unread-count `(recipient_user_id, read_at)`, feed `(recipient_user_id, created_at)`, subject. Plain FK recipient (P1 recipients are always Users — no polymorphic notifiable; YAGNI).
- **D-5 · `NotificationType` enum** — 13 curated `AuditAction`-shared values (assignment-lifecycle + forward payment verbs so the deferred-S10 alerts are drop-in) + `auditAction()` tie + the one-vocabulary catalogue-tripwire.
- **D-6 · `NotificationService::notify()` is the single emit seam, in-app only** this chunk — reads the `in_app` pref, writes a row; **email is not centralized or touched.**
- **D-7 · `user_notification_preferences`** `(user_id, type, channel)` unique, CASCADE on user, digest channel present-but-unconsumed; **preserve-current defaults computed** (missing row → `defaultEnabled()`), so the Ch2 retrofit can never silently disable an existing email.
- **D-8/D-9 · Four `/me/notifications` endpoints** (`auth:web` + `tenancy.set`, mirroring `/me`), owner-scoped by `recipient_user_id = auth user`, idempotent mark-read; per-user isolation, not `BelongsToAgency`.
- **D-10 · Proof consumer** — `SendAssignmentNotifications::notifyCreatorOfReview()` emits in-app (reviewer as actor, assignment as subject) alongside the untouched `DraftReviewedMail`.
- **D-11 · `Notifiable` removed** from `User` (reasoned-removal, §5.8 — it was dormant scaffold).
- **Deviation (approved):** a small `NotificationChannel` enum (not named in the plan) homes `defaultEnabled()` — type-safe, match-exhaustive over all 3 cases (compiler enforces the channel ripple), and the correct centralized home for the preserve-current guarantee.

## Spot-check anchors → evidence

| Anchor                          | Evidence                                                                                                                                              |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Unread-count is count-only      | Single `select count(*) … where recipient_user_id = ? and read_at is null`; zero rows hydrated; hits `idx_notifications_recipient_unread`.            |
| Isolation is ABSENCE            | A (zero own rows) sees `count(0)` + `meta.total`/`unread_count` = 0; direct `{B-ulid}/read` → 404 `notification.not_found`, B's `read_at` stays null. |
| Preserve-current on missing row | `isChannelEnabled` → `defaultEnabled()` when no pref row; test asserts in_app/email on, digest off with zero pref rows; notify writes a row.          |
| Mark-read idempotency (§5.6)    | Re-mark is a no-op.                                                                                                                                   |
| Proof consumer (§5.2 split)     | Event::fake test asserts the listener path; non-faked test asserts the row is written + email still rides.                                            |

## Verification

22 new tests green (service emit + default resolution, enum tripwire + one-vocabulary, four endpoints incl. mark-read idempotency / unread-count / absence, proof-consumer Event::fake split); existing review test confirms email + in-app ride together. Pint clean repo-wide; Larastan level 8 clean (620 files); 37 targeted + 311 Identity/Unit green.

## Out of scope (confirmed untouched)

- **Ch2:** the rest of the retrofit (remaining funnel events + the 6 controller/job sites), the admins+managers fan-out query replacing `invited_by`, the net-new accept/decline emitter, the approve/reject dashboard-banner migration to read notifications.
- **Ch3:** agency + creator notification centers, bell/badge, polling, prefs write-back UI, i18n.
- **Messaging sprint:** digest + `withSchedule`. **S13:** admin consumer (no admin shell; alert data is S10/S13) — designed for drop-in.

## Docs

`03-DATA-MODEL.md §14` rewritten to the built schema (P1, tech-debt-resolution note); four rows in `security/tenancy.md`; the three notification tech-debt entries marked **in-progress (S11.0)** with Ch2/Ch3 closure pointers.

---

_Provenance: drafted by Cursor (S11.0 Chunk 1 build); merged + spot-checked by Claude (count-only / ABSENCE / preserve-current-on-missing-row all verified at anchor; NotificationChannel deviation approved). No PMC._
