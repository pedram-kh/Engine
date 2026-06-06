# S11.0 — Notification subsystem · Chunk 3b Review — Notification preferences (read + write + minimal UI)

**Status:** Closed. Spot-check passed (no PMC).

**Reviewer:** drafted by Cursor (implementation); independently spot-checked + accepted.

**Reviewed against:** the S11.0 Chunk-3b kickoff (D-1…D-7), the Ch3b pre-kickoff inventory, the Ch1 review (the preserve-current contract — missing row → `defaultEnabled()`), the Ch3a review (the shared notifications module + the `notificationTemplateKey` live-set), `docs/PROJECT-WORKFLOW.md §5` (5.6 idempotency, 5.13 module-api, 5.15 allowlist discipline, 5.17 arch-test coverage), `docs/07-TESTING.md`, `docs/security/tenancy.md §4`.

This is the **final chunk** of the S11.0 notification mini-sprint and the product's **first user self-write surface**: the per-user notification-preference read + write (`GET`/`PATCH /me/notification-preferences`) plus a minimal in-app prefs page mounted off the user menu in both shells. It deliberately stays **in-app-only** (the only consumed channel) and exposes only the types a given user can actually receive.

## Decisions (confirmed at kickoff, built as proposed)

- **D-1 · Sparse write — the contract-preserving core.** `NotificationService::setPreference()`: a toggle that diverges from `defaultEnabled()` → `updateOrCreate((user_id,type,channel), is_enabled)`; a toggle that returns to the default → **delete** the row. The table holds only divergences, so "missing row → default" stays the single source of truth. The `(user_id,type,channel)` unique backs `updateOrCreate` against a double-write race. Dense is forbidden (it would freeze a user at today's default and break the Ch2 preserve-current guarantee).
- **D-2 · Expose `in_app` only** — `email`/`digest` hidden. The service consumes only `in_app`; email rides independently of prefs, digest has no consumer until Messaging. A toggle that gates nothing is a dishonest control. The wire contract + sparse backend carry all three channels, so they light up with no change when a consumer ships.
- **D-3 · Net-new `GET /me/notification-preferences`** (not an extended `/me`). Returns the caller's **sparse rows** AND a server-authoritative `defaults` block, so the FE composes display state (`row?.is_enabled ?? defaults[channel]`) without ever hardcoding the Ch1 contract over the wire. Keeps the hot `/me` cold-load lean.
- **D-4 · The 8 live-emit types only, grouped** — not the 45-cell matrix, not 2 global switches. The 7 emit-less types (the 2 payment verbs + the 5 lifecycle verbs awaiting an emitter) are omitted — same no-dead-control logic as D-2.
- **D-5 · A user-scoped `NotificationPreferencesPage.vue`**, two routes (`/notifications/preferences` agency, `/creator/notifications/preferences` creator) rendering the same shell-agnostic page, reached from a "Notification settings" item in the user menu in both shells. Not the agency-admin `SettingsPage` (wrong owner model). The agency route is added to the `requireAgencyUser` arch-test route-set (§5.17).
- **D-6 · No audit, off `CANONICAL_422_FILES`.** Low-stakes self-config; no prefs verb in `AuditAction` and minting one under one-vocabulary would be disproportionate. The form is pure enum+boolean — nothing a user can mis-type, so no per-field 422 to bind.
- **D-7 · Owner-scope, self-resolved.** `auth:web` + `tenancy.set` (the no-op, mirroring `/me/notifications`); owner = `$request->user()`, no path id, no policy. `FormRequest` validates per-row `{notification_type ∈ NotificationType, channel ∈ NotificationChannel, is_enabled: bool}` (batch `preferences: [...]`), against the full channel enum so future channels need no request change.

## Approved deviations (amend the kickoff)

- **`tenancy.md §4` row added** (kickoff said "no tenancy.md change"). §4 is the literal enumeration of every tenancy-bypassing route; the `/me/notifications` siblings are already listed, so omitting the self-write prefs routes was a security-doc gap. The two prefs rows are correct.
- **Per-role dead-control fix — role-filter the exposed types.** D-5's "one shared page, all 8 types" smuggled back the exact dead-control D-2/D-4 eliminated (a creator saw 2 agency-only toggles; an agency user 6 creator-only). Resolved by making `recipient` an attribute of the **single live-type definition** (`LIVE_TYPES` in `templates.ts`) — so the Ch3a renderer's template map, the Ch3b prefs list, and the per-role partition all derive from one structure rather than a third hand-maintained list. The honesty principle ("a toggle exists only for a notification that can arrive for _this_ user") is now structural + CI-enforced.

## Spot-check anchors → evidence

| Anchor                                       | Evidence                                                                                                                                                                                            |
| -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Return-to-default = DELETE (not dense write) | `count()->toBe(0)` after toggle-to-default; `isChannelEnabled` → `defaultEnabled()` with zero rows. A dense write fails the count.                                                                  |
| Sparse upsert is unique-backed               | Re-writing the same divergence keeps `count() === 1` (no duplicate); the `(user_id,type,channel)` unique collides + updates.                                                                        |
| Write owner-scope = ABSENCE                  | A's PATCH on B's same `(type,channel)` leaves B's row byte-for-byte (id / `is_enabled` / `updated_at`); `user_id` from `$request->user()`, no path segment to address B.                            |
| Read owner-scope = ABSENCE                   | A's read returns only A's sparse rows; B's divergence never appears.                                                                                                                                |
| Defaults are server-authoritative            | The read ships `defaults` ({in_app:true, email:true, digest:false}); the FE composes `row ?? default` and never hardcodes the contract.                                                             |
| Live-set = one source of truth               | `LIVE_TYPES` (templateKey + recipient + group); the parity spec proves the role partition is disjoint + complete vs the 8 live types, and every exposed type has a bespoke (non-fallback) template. |
| Role filter (FE)                             | Creator → 6 toggles (4 assignment + 2 creator); agency → 2 toggles (assignment fan-out); each role's other-principal toggles absent.                                                                |

## Verification

Backend prefs 11 tests / 40 assertions green; Pint + Larastan level 8 clean. Main suite 940 tests / 105 files green (incl. the updated arch-test route-set, the role-partition single-source-of-truth spec, and the role-filtered page spec); api-client 100 tests + typecheck; vue-tsc + ESLint clean.

## Out of scope (confirmed untouched)

- **`email`/`digest` channel exposure** — in-app-only until those channels get a consumer (email-centralization or the Messaging digest); the sparse backend already accepts any channel.
- **Bucket-c verb-mints + the §945 banner repoint** — still deferred (the Ch2 tech-debt entry), each blocked on a net-new `AuditAction` verb.
- **No `03-DATA-MODEL`/schema/migration change** — the `user_notification_preferences` table already exists (Ch1).

## Docs

`tech-debt.md` — the three notification entries updated: the **user-facing-surface portion is now complete** (Ch3a center/badge/poll + Ch3b prefs); remaining open = the bucket-c verb-mints + the §945 banner + the email/digest channel exposure (with the consumer-wired trigger). `security/tenancy.md §4` — two rows added for the `GET`/`PATCH /me/notification-preferences` cross-tenant user-global routes (the corrected kickoff assumption).

---

## Independent review — verdict (appended to Cursor's draft)

**Status:** Closed. Spot-check passed (no PMC). Final chunk of S11.0 — the notification subsystem is complete.

**Reviewed against:** the Ch3b kickoff (D-1…D-7) + the Ch1 preserve-current contract, `PROJECT-WORKFLOW.md §5` (5.6 idempotency, 5.15 allowlist, 5.17 arch-test), the Ch1 ABSENCE isolation anchor.

### Spot-check anchors → evidence

| Anchor                                       | Evidence                                                                                                                                                                                        |
| -------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Return-to-default = DELETE (not dense write) | `count()->toBe(0)` after toggle-to-default; `isChannelEnabled` → `defaultEnabled()` with zero rows. A dense write fails the count.                                                              |
| Write owner-scope = ABSENCE                  | A's PATCH on B's same `(type,channel)` leaves B's row byte-for-byte (id/value/`updated_at`); `user_id` from `$request->user()`, no path segment.                                                |
| Live-set = one source of truth               | `LIVE_TYPES` (templateKey + recipient + group); Ch3a renderer + Ch3b role partition both derive from it; parity spec proves disjoint + complete + non-fallback-template for every exposed type. |

### Deviation rulings

1. **`tenancy.md §4` row added (kickoff said "no change") — APPROVED as a corrected assumption.** §4 is the literal enumeration of every tenancy-bypassing route; the `/me/notifications` siblings are already listed, so omitting the self-write prefs routes was a security-doc gap. The kickoff's "no tenancy.md change" was wrong; the row is correct.
2. **Per-role dead-control fix (role-filter the exposed types) — APPROVED, improves on the kickoff.** D-5's "one shared page, all 8 types" smuggled back the exact dead-control D-2/D-4 eliminated (a creator saw 2 agency-only toggles, an agency user 6 creator-only). Cursor surfaced it and resolved it correctly by making `recipient` an attribute of the live-type definition — so the template map, prefs list, and role partition share one source of truth rather than a third hand-maintained list. The honesty principle ("a toggle exists only for a notification that can arrive for _this_ user") is now structural and CI-enforced. This is the better spec; the kickoff is amended to match.

### Decisions confirmed (built as specified)

D-1 sparse write (diverge → `updateOrCreate`, return-to-default → delete); D-2 in-app only (email/digest hidden — no consumer); D-3 net-new `GET` returning sparse rows + server-authoritative `defaults` block (FE composes, never hardcodes the contract); D-4 the 8 live-emit types only, grouped; D-5 user-scoped page, two routes off the user menu in both shells + the §5.17 arch-test route-set edit; D-6 no audit, off `CANONICAL_422_FILES` (pure enum+bool); D-7 `auth:web`+`tenancy.set`, owner self-resolved.

### Verification

Backend prefs 11 tests / 40 assertions green, Pint + Larastan clean; main suite 940 / 105 files green; api-client 100 green; vue-tsc + ESLint clean.

### S11.0 close

The notification subsystem is complete across four chunks: core (Ch1) → retrofit + agency fan-out (Ch2) → center/badge/poll/render (Ch3a) → preferences (Ch3b). Remaining open under the three notification tech-debt entries: the bucket-c verb-mints + the §945 banner repoint (deferred, Ch2 entry) and the email/digest channel exposure (trigger: those channels get a consumer — email-centralization or the Messaging digest). The subsystem is now ready to consume in the Messaging sprint.

---

_Provenance: drafted by Cursor (S11.0 Ch3b build); verdict appended + spot-checked by Claude (return-to-default-DELETE / write-ABSENCE / single-source-live-set all verified at anchor; tenancy.md-row + role-filter deviations approved as kickoff amendments). No PMC._
