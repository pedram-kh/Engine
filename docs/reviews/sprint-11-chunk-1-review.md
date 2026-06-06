# Sprint 11 — Messaging · Chunk 1 Review (single full chunk)

**Status:** Closed. Pre-merge spot-check passed (5 load-bearing seams verified; no PMC).

**Reviewer:** drafted by Cursor (implementation); independently spot-checked + accepted.

**Reviewed against:** the Sprint 11 — Messaging kickoff (D-1…D-18), `docs/20-PHASE-1-SPEC.md` (the Sprint 11 block), `docs/03-DATA-MODEL.md §11`, the closed S11.0 notification subsystem (Ch1…Ch3b reviews — `NotificationService::notify()`/`isChannelEnabled()`, the `LIVE_TYPES` registry, the Ch3b role-filter), the reuse precedents (`SendAssignmentNotifications` fan-out, `CampaignAssignmentController` thread hook + scope-bypass, `PortfolioUploadService` presign mechanics, the `BulkInvitePage` poll, the wide-`v-dialog` drawer), `docs/security/tenancy.md §4`, `docs/07-TESTING.md`, `docs/PROJECT-WORKFLOW.md §5`.

This is **Sprint 11 — Messaging**, built as **one full chunk** across 8 green commits (S1→S8) plus this docs/review slice (S9). Messaging is the **first new consumer** of the S11.0 notification subsystem. The chat core is per-assignment threads (idempotently provisioned), an agency + creator read/write API with thread-keyed attachments, a shared SPA chat surface (campaign-detail Messages tab + creator-assignment inline), system messages on lifecycle transitions, dual-recipient new-message notifications, and a daily unread-message digest (the app's first scheduled command).

## Chat-core size re-confirm (the one-chunk gate)

Re-inventoried at kickoff: the Messaging module was an empty scaffold (provider + empty `Routes/api.php`, no SPA `modules/messaging/`); `§11` defined three tables; everything was net-new. The plan-pause **challenged** one-chunk with a concrete 2-chunk split, but the chunk was approved as **one chunk, multi-commit** with coherent green slices (Q1). Built as approved; each commit S1→S8 is independently green (suite + static analysis).

## Commits (independently green slices)

- **S1** `bd3467e` — schema/models/enums/tripwire (3 tables, `MessageSenderRole`+`MessageKind` string-backed enums + catalogue tripwire) — D-15.
- **S2** `b662b6b` — idempotent per-assignment thread provisioning + `AssignmentTransitioned` listener + terminal helper — D-3.
- **S3** `c3c4f39` — agency + creator thread read/write API, read receipts, unread counts, terminal-state 422, tenancy + isolation tests — D-11/D-13/D-16.
- **S4** `65e8798` — thread-keyed presigned attachment uploads (`MessageAttachmentUploadService`) — D-6.
- **S5** `ccabd60` — SPA chat: shared `ChatPanel` + `ChatDialog`, two mounts, `useMessageThread` ~15s poll w/ unmount cleanup, compose-422 binding, `app.json` i18n — D-11/D-12.
- **S6** `5e1201c` — `WriteSystemMessage` listener + `SYSTEM_MESSAGE_TRANSITIONS` allowlist + backend `messages.php` lang — D-4/D-5.
- **S7** `e791653` — dual-recipient new-message notifications: 2 `AuditAction` verbs + 2 `NotificationType` + `LIVE_TYPES` `messaging` group + parity-spec break-revert proof + `SendMessageNotifications` — D-7.
- **S8** `2cffaf2` — `messages:send-digest` + `withSchedule(...->daily())` + prefs per-channel lift + cross-agency absence test — D-8/D-9/D-10.
- **S9** (this slice, uncommitted) — 6 docs + this draft review.

## Decisions (confirmed at kickoff, built as proposed)

- **D-1 · One full chunk, multi-commit** — size re-confirmed; the split challenge was logged at plan-pause and overruled to one chunk (Q1).
- **D-2 · System-message sender `sender_user_id` NULLABLE** — built nullable; `sender_role`/`kind = system` rows carry `sender_user_id = null` (no fictional bot user), mirroring the `notifications.actor_user_id`-nullable precedent. `§11` amended (spec-drift correction surfaced, not silent).
- **D-3 · Idempotent `firstOrCreate` thread provisioning, three sites, no backfill** — (a) the new `AssignmentTransitioned` listener filtering `AssignmentInvited`, registered after the existing three in `CampaignsServiceProvider`; (b) defensively before any system-message write; (c) lazily on first thread GET (agency + creator). The `assignment_id` UNIQUE backs idempotency; lazy-create heals thread-less assignments.
- **D-4 · `WriteSystemMessage` listener (separate class) gated by `SYSTEM_MESSAGE_TRANSITIONS`** — lifecycle events only (`contracted`, `draft_submitted`, `draft_approved`, `revision_requested`, `draft_rejected`, `posted_by_creator`, `live_verified`, `manually_verified`, `resubmit_requested`, `payment_released`); `system_event_key` = the `AuditAction` verb string; no params column; `Event::fake` test split.
- **D-5 · Localization key+context, never stored text** — backend `lang/{en,pt,it}/messages.php` (digest + system-message strings) + the `app.json` messaging block (FE chat); `system_event_key` + assignment context → localized at render.
- **D-6 · Dedicated thread-keyed `MessageAttachmentUploadService`** — mirrors `PortfolioUploadService` presign mechanics (not generalized — that one is creator-keyed); path `messages/{thread_ulid}/{ulid}.{ext}`; `attachment_only` (files, no body) is a real path; init/complete with Content-Type match + thread-prefix check.
- **D-7 · New-message in-app notification emits THROUGH `NotificationService`** — recipient = counterparty (creator-msg → agency via `Agency::notifiableMembers()`; agency-msg → `creator->user`). Built as **two types** (`message_received_by_creator` + `message_received_by_agency`), two `AuditAction` verbs (the one-vocabulary tie; no audit row written — D-17), two `LIVE_TYPES` entries in a new `messaging` group. Codified as `PROJECT-WORKFLOW.md §5.37`.
- **D-9 · Daily digest = dedicated `messages:send-digest` scheduled command** — queries each user's unread messages, calls `isChannelEnabled(user, type, Digest)` itself (the digest can't ride `notify()`, which is in-app-only by design), sends one aggregated email. The app's **first scheduled command**: registered via `withSchedule` in `bootstrap/app.php` (`->daily()`); establishes the scheduled-command test pattern (`07-TESTING.md §3.1`). Digest channel default OFF (opt-in).
- **D-10 · Co-deliver the digest toggle with its consumer** — the prefs page was hardcoded to a single `CHANNEL = 'in_app'`; lifted to a per-type "which channels this type supports" notion (`LIVE_TYPES[].channels`), so messaging types surface both `in_app` + `digest` toggles the moment the digest consumes the channel. No dead control, no un-opt-out-able digest.
- **D-11 · Shared wide-`v-dialog` chat, two mounts** — (a) campaign-detail Messages tab (agency roll-up of the campaign's assignment threads; the coming-soon tab ships real) and (b) inline on `CreatorAssignmentDetailPage` (creator's single thread). The Ch3a `NotificationBell` mount is untouched (collision note honored).
- **D-12 · Polling (no WebSockets)** — the `BulkInvitePage` `setTimeout`-reschedule + `onBeforeUnmount` cleanup, refs-only (no `localStorage`, §5.15). Open thread polls ~15s; independent of the bell's own poll.
- **D-13 · Terminal-state posture** — read always; human-send blocked (422) once the assignment is `declined`/`rejected`/`cancelled`; system messages still write. See divergence below for `payment_released`.
- **D-14 · Message soft-delete column-only** — `deleted_at` present-but-unwritten; no delete endpoint this sprint; tech-debt logged.
- **D-15 · String-backed `MessageSenderRole` + `MessageKind`** (columns `varchar(16)`) with a catalogue tripwire.
- **D-16 · Tenancy** — `message_threads` uses `BelongsToAgency` (`agency_id`); messages scope via thread, receipts via message; creator-self via the `CreatorAssignmentController` scope-bypass precedent. New `tenancy.md §4` rows (agency thread routes = standard; creator chat + attachment routes = creator-self bypass). Absence tests (404-not-403).
- **D-17 · No audit on message-send** — the two new `AuditAction` verbs exist only for the `NotificationType` one-vocabulary tie; no audit row is written when a message is sent. Verb naming reads sensibly under that constraint.
- **D-18 · No feature flag** — messaging isn't vendor-gated; the default-OFF "no silent vendor calls" convention doesn't apply.

## Spec divergence (recorded explicitly — D-8, confirmed at plan-pause)

**The spec text (`20-PHASE-1-SPEC.md`, Sprint 11) says "in-app + email on new messages." This was NOT built as a literal per-message email.** Confirmed at plan-pause (Q3) and built as D-8 prescribes: messaging exposes **`in_app` (immediate in-app notification, default ON) + `digest` (daily aggregated email, default OFF / opt-in)** — there is **no immediate per-message email send**. Rationale: an email per chat message is spam, and the subsystem deliberately does not centralize immediate email; the email path is the opt-in daily digest (D-9). This reinterprets "email on new messages" as "email via the daily digest." The `email` channel is intentionally **not** surfaced for messaging types. This is the one deliberate spec-divergence in the chunk and is flagged here for the independent reviewer to ratify (or to direct a literal per-message email if the spec is to be read strictly).

## Approved deviations (amend the kickoff)

- **D-13 · `payment_released` kept OPEN for human-send (Q2).** The kickoff flagged this for a ruling. Decision: `payment_released` does **not** block human-send (post-delivery wrap-up is a legitimate use); the human-send 422 fires only on `declined`/`rejected`/`cancelled`. System messages still write on `payment_released` (it remains a `SYSTEM_MESSAGE_TRANSITIONS` event). The terminal write-guard set is therefore `{declined, rejected, cancelled}`, narrower than "all terminal states."
- **`tenancy.md §4` — creator attachment routes added.** Beyond the agency-standard + creator-self thread rows, the creator attachment `init`/`complete` routes were added to the creator-self bypass allowlist (they are tenancy-bypassing creator-scoped routes; omitting them would be a security-doc gap — same reasoning as the Ch3b `/me/notification-preferences` correction).

## Spot-check anchors → evidence

| Anchor                                       | Evidence                                                                                                                                                                                                                              |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Thread provisioning is idempotent            | `firstOrCreate` keyed on the `assignment_id` UNIQUE; re-invite / repeated lazy-GET yields `count() === 1`; lazy-create heals a thread-less assignment.                                                                                |
| System message has no human sender           | `system` rows persist `sender_user_id = null`, `sender_role/kind = system`; nothing assumes a non-null sender.                                                                                                                        |
| System messages only on the allowlist        | `SYSTEM_MESSAGE_TRANSITIONS` curated lifecycle set; a non-allowlisted transition (field churn) writes no system message (`Event::fake` split).                                                                                        |
| Dual-recipient = two types, partition intact | `message_received_by_creator` (recipient creator) + `message_received_by_agency` (recipient agency); the parity spec proves the role partition stays disjoint + complete over the now-10 live types — verified via 5.35 break-revert. |
| Notification emits THROUGH `notify()`        | creator-msg → agency `notifiableMembers()` rows; agency-msg → `creator->user` row; opt-out (sparse pref) suppresses the row; no bespoke notification path.                                                                            |
| No audit row on send                         | the two new `AuditAction` verbs are the one-vocabulary tie only; `audit_logs` has no message-send row.                                                                                                                                |
| Digest is tenancy-correct = ABSENCE          | the scheduled command runs with NO ambient tenancy; queries scope `agency_id`/`creator_id` explicitly; Agency A's digest never reflects Agency B's threads (the cross-agency absence test).                                           |
| Digest gate is explicit                      | the command calls `isChannelEnabled(user, type, Digest)` itself; opted-out / no-unread users get no email (`Mail::assertQueued`/`assertNothingQueued`).                                                                               |
| Scheduler registration                       | `schedule:list` contains `messages:send-digest` (`->daily()`); bootstraps the real `withSchedule` callback.                                                                                                                           |
| Toggle co-delivered with consumer            | messaging types expose `in_app` + `digest` toggles (`LIVE_TYPES[].channels`); non-digest types stay `in_app`-only; digest defaults OFF.                                                                                               |
| Terminal write-guard                         | human-send 422 on `declined`/`rejected`/`cancelled`; reads + system messages still work; `payment_released` stays open (the Q2 ruling).                                                                                               |
| Tenancy = ABSENCE (404-not-403)              | A's thread invisible to B (404); creator X can't read Y's; attachment prefix-check rejects cross-thread keys.                                                                                                                         |
| Attachments are thread-keyed                 | path `messages/{thread_ulid}/{ulid}.{ext}`; Content-Type match on complete; `attachment_only` (no body) is a valid send.                                                                                                              |
| FE poll cleans up                            | `useMessageThread` reschedules via `setTimeout`, clears on `onBeforeUnmount`; refs-only, no `localStorage`.                                                                                                                           |
| Compose form is 422-bound                    | `ChatPanel.vue` on `CANONICAL_422_FILES`; the compose form binds the per-field error envelope.                                                                                                                                        |

## Verification

- **Backend** — Messaging suite **45 tests / 148 assertions** green; Messaging + Notifications + Audit together **138 tests / 513 assertions** green (incl. the two enum tripwires, the message-notification emit test, the digest command test with cross-agency absence, and the `schedule:list` registration test). Pint + Larastan level 8 clean on all new/changed backend files.
- **Frontend** — Messaging module + Notifications + the parity arch-spec + the form-error arch-spec: **9 files / 85 tests** green (incl. the channel-aware prefs-page toggle counts, the digest opt-in/opt-out assertions, the `LIVE_TYPES` disjoint+complete partition with the S7 break-revert proof, and `ChatPanel` on `CANONICAL_422_FILES`). vue-tsc + ESLint clean; api-client typecheck clean.

## Docs (the S9 deliverable — uncommitted, ride the review's merge)

- **`03-DATA-MODEL.md §11`** — the three messaging tables flipped to **Built (Sprint 11)**; `messages.sender_user_id` amended to **nullable** (D-2 spec-drift correction).
- **`security/tenancy.md §4`** — message routes added: agency thread routes (standard `SetTenancyContext` + `tenancy`) + creator-self bypass rows for the creator chat routes **and** the creator attachment `init`/`complete` routes.
- **`tech-debt.md`** — §959 digest-channel-exposure marked **partially closed** (the digest now consumes the `digest` channel for messaging types; the rest of the email/digest exposure remains open); new entry logging the **message-deletion/moderation deferral** (D-14, `deleted_at` column-only, no endpoint).
- **`feature-flags.md`** — confirms **no messaging flag** (D-18); notes the digest establishes the **app's first scheduled command**.
- **`07-TESTING.md §3.1`** — added the **scheduled-command test pattern** (the app's first; both halves: effect + `Mail::fake`, with the mandatory cross-tenant absence test for fan-out jobs; and registration via `schedule:list`). Flagged to the reviewer as a new codified standard.
- **`PROJECT-WORKFLOW.md §5.37`** — appended the **dual-recipient notification-type pattern** (D-7: two types per recipient direction, the full `AuditAction`/`NotificationType`/`LIVE_TYPES` ripple) as a reusable numbered standard.

## Out of scope (confirmed untouched / deferred)

- **Message delete/moderation endpoint** — `deleted_at` column-only (D-14); deferred, tech-debt logged.
- **WebSockets / realtime** — polling only this sprint (D-12, P2).
- **Immediate per-message email** — intentionally not built (the D-8 divergence above); the email path is the opt-in daily digest.
- **`email` channel for messaging types** — not surfaced (D-8); only `in_app` + `digest`.

---

## Independent review — verdict (appended to Cursor's draft)

**Status:** Closed. Pre-merge spot-check passed (no PMC). Sprint 11 — Messaging is merge-ready.

**Reviewed against:** the Sprint 11 kickoff (D-1…D-18) + Q1/Q2/Q3, the S11.0 Ch3b parity-spec + role-filter contract, the Ch1 ABSENCE isolation anchor, `PROJECT-WORKFLOW.md §5` (5.6 idempotency, 5.15 allowlist, 5.17 arch-test, 5.35 break-revert, the new 5.37 dual-recipient pattern), `07-TESTING.md §3.1` (the new scheduled-command pattern).

Rather than re-deriving the whole chunk, the review focused on the **five load-bearing seams** the draft asserts hold — the ones a green-only suite could quietly fail to actually pin. Each was verified at its anchor (code read + test run), not summarised.

### Spot-check anchors → evidence

| #   | Seam                                                           | Verified                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| --- | -------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Parity spec has teeth (§5.35)                                  | The break-revert was reproduced live: mutating the spec's `LIVE_TYPES` to 8 (dropping the two `message.received_*`) **fails** `partition the 10 live types exactly` (`...spec.ts:167` — the two messaging types surface as the `+` diff against the real `preferenceGroupsForRole` output of 10); reverted byte-identical to HEAD → 9/9 green. The assertion bites on the wrong count, not just passes on the right one.                                                                                                |
| 2   | System messages do NOT double-notify                           | `SendMessageNotifications::dispatch()` returns on `sender_user_id === null` (line 36-38) BEFORE any `notify()`; `MessageService::writeSystemMessage()` never calls the dispatcher at all (only `sendHumanMessage()` does, line 74). Test `a system message writes no counterparty notification` asserts the `message_received_*` row count `=== 0` on a lifecycle write; the two human-send tests fire the rows.                                                                                                        |
| 3   | Digest tenancy = ABSENCE, asserted as absence                  | Agency thread set is explicitly `agency_id`-scoped under a console with no ambient tenancy (`MessageDigestService` line 77-80); unread is per-user-via-receipts (`whereNotExists` a receipt for the viewer, human-sender only, not-own — line 236-248). The cross-agency test asserts **B-absence** (A's one digest reflects only A's single unread + A's campaign — `totalUnread === 1`, `lines[0]['campaign'] === 'Agency A Campaign'`), not "both got an email." The zero-unread test confirms the receipts scoping. |
| 4   | Terminal write-guard = explicit three, not `isTerminal()` (Q2) | `HUMAN_SEND_BLOCKED_STATUSES = {Declined, Rejected, Cancelled}` (deliberately narrower than `AssignmentStatus::isTerminal()`, which includes `payment_released`); `humanSendBlocked()` does an `in_array` over exactly that set. Test asserts a human send on a `payment_released` assignment returns **201 Created** (stays open for wrap-up) while `declined` → 422 `message.thread_closed`.                                                                                                                          |
| 5   | Isolation = ABSENCE (404-not-403) + attachment prefix-check    | `completePresignedUpload()` does `str_starts_with($uploadId, "messages/{thread_ulid}/")` BEFORE the existence check, so a foreign object that genuinely exists is still rejected; the cross-thread test `Storage::put`s a real object under a different thread ulid → 422 `message.attachment_invalid`. Thread-read isolation returns **404, not 403** for agency B → A's thread and creator Y → X's thread (no existence leak).                                                                                        |

### Decisions confirmed (built as specified)

D-2 nullable system sender; D-3 idempotent `firstOrCreate` three sites; D-4 `WriteSystemMessage` + `SYSTEM_MESSAGE_TRANSITIONS` allowlist (incl. `payment_released` still writing a system message); D-5 localized key+context; D-6 thread-keyed uploader + prefix backstop; D-7 dual-recipient two-type emit through `notify()`; D-9 first scheduled command + `schedule:list` registration test; D-10 digest toggle co-delivered with its consumer (parity spec pins the digest channel to messaging types only); D-15 string-backed enums + tripwire; D-16 tenancy ABSENCE; D-17 no audit on send.

### Deviation / divergence rulings

1. **D-8 (digest, not per-message email) — APPROVED as the correct spec reinterpretation.** "in-app + email on new messages" → immediate in-app + opt-in daily digest (default OFF); no `email` channel surfaced for messaging types. Per-message email is spam and the subsystem does not centralize immediate email. The divergence is recorded prominently in the draft and was confirmed at plan-pause (Q3).
2. **D-13 / Q2 (`payment_released` stays open for human-send) — APPROVED.** The guard set is the three "ended badly / called off" terminals; a paid-out assignment stays open for post-delivery wrap-up. System messages still write on `payment_released`. The narrower-than-`isTerminal()` set is verified at seam 4.
3. **`tenancy.md §4` creator-attachment rows — APPROVED as a corrected assumption.** The creator `init`/`complete` attachment routes are tenancy-bypassing creator-scoped routes; omitting them would be a security-doc gap (same reasoning as the Ch3b `/me/notification-preferences` correction).

### Verification

Messaging suite 45/148; Messaging+Notifications+Audit 138/513 green; Pint + Larastan level 8 clean. FE messaging + notifications + parity + form-error arch specs 9 files / 85 tests green (incl. the live break-revert reproduction); vue-tsc + ESLint + api-client typecheck clean. The 5 spot-check seams re-run at anchor during review: notification + digest 9/30, API + attachment 17/58.

### Sprint 11 close

Messaging ships as one chunk across 8 green commits (S1→S8) + this docs/review slice (S9). It is the first new consumer of the S11.0 notification subsystem, the first scheduled command, and the product's first attachment-bearing chat surface. Remaining deferred: the message delete/moderation endpoint (D-14, `deleted_at` column-only), WebSockets/realtime (D-12, P2), and the broader email-channel exposure beyond the digest. The docs ride this review's merge commit.

---

_Provenance: drafted by Cursor (Sprint 11 — Messaging build, S1→S9); verdict appended + spot-checked (5 load-bearing seams — parity break-revert reproduced live, system-message no-double-notify, digest cross-agency ABSENCE, terminal-guard explicit-three, isolation 404 + attachment prefix-guard — all verified at anchor). The D-8 divergence + the two approved deviations are ratified above. No PMC. This file stays uncommitted; it rides the merge commit with the 6 docs._
