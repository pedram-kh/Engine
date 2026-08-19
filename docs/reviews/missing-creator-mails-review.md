# Missing creator emails: invite (①) + debounced message (⑧) — REVIEW (AH-083)

- **Status: Closed — approved.** Read-only inventory (`creator-mail-set-inventory.md`) → kickoff with
  locked decisions (D1–D6) → plan-pause (`missing-creator-mails-plan.md`, ten findings C1–C10, two
  proposals, ten questions) → all ten rulings (Q1–Q10, Pedram, verbatim) → build, S1 through S8 in
  strict order, each green → docs pass (S9) → two-commit pair, push released, CI green at the tip
  (§5.41). No independent Claude review pass ran this session — Pedram gave the kickoff, the
  plan-pause rulings, and the build go-ahead directly in one continuous thread, then closed the chunk
  directly on the built state; this file is written by the same agent that built the chunk, per the
  compact-loop shape already used for AH-081/082. **Verdict:** D1–D6 + Q1–Q10 verified as built; the
  C2 listener-arm reinterpretation ratified; all three invite paths emitting with the fresh/re-offer
  discriminator; the debounce §5.34 set proven at BOTH service and dispatch levels; the one-path
  bypass break-revert accepted as the discriminating proof, including the honest note on the
  trivially-green fifth case ([§5](#5-s8--the-full-dispatch-path-set)); C5's two-assertion link
  pinning accepted as the model for asymmetric-route mails; Q9 ratified as named-not-fixed with its
  resolution path defined; C8's reversal named in docblocks; posture LOW confirmed.
- **Date:** 2026-08-19
- **Provenance:** kickoff (D1–D6) and the ten plan-pause rulings (Q1–Q10) by Pedram, directly, in
  this session; inventory, plan authoring, and build by Cursor; close-and-push approval by Pedram,
  directly, no independent review pass.
- **Evidence base:** `creator-mail-set-inventory.md` (I1, I3, I4, I5 — the ① and ⑧ rows, the
  preference-machinery read, the debounce shape, the flag-collision question);
  `missing-creator-mails-plan.md` (C1–C10, the two proposals, Q1–Q10) — re-cited below rather than
  re-derived, since the plan-pause's own findings are the record of what was verified at HEAD before
  a line of code was written.
- **⚠️ PROD-DATA RISK: LOW, as re-derived at plan-pause (§0 there) and unchanged at close.** Nothing
  existing is read-for-mutation or rewritten. Both new migrations-worth of schema are additive-only
  (in fact only ⑧ needs one — see §2 below); both new mail legs stay behind ONE new Pennant flag,
  default OFF, so **nothing sends at deploy** regardless of how many invites are extended or messages
  sent; neither emission is fan-out-shaped (① is one creator per invite action, ⑧ is capped at one
  email per `(thread, recipient)` per 30 minutes by construction). Full detail: [§8](#8-production-posture).

---

## 1. What shipped, against the kickoff's D1–D6 and the ten rulings

| Decision / ruling | Asked                                                                                  | Shipped                                                                                                                                                                                                                   |
| ----------------- | -------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **D1 / Q1**       | One new flag, default OFF, gating the MAIL legs only; §5.32 reinterpretation recorded. | Held. `missing_creator_mails_enabled`, `App\Modules\Creators\Features\MissingCreatorMailsEnabled`. Zero touch to `CampaignInvitationService` or `CampaignAssignmentStateMachine` — see [§2](#2-d2q1q2--the-invite-email). |
| **D2 / Q2**       | Dual-emit (mail + in-app) for ①, ratified as recommended.                              | Held. `assignment.invited` moved to `LIVE_TYPES`; both parity specs updated. See [§2](#2-d2q1q2--the-invite-email).                                                                                                       |
| **D3 / Q3**       | One mailable, context-discriminated, for ⑧.                                            | Held. `NewMessageMail`, `context: 'campaign'\|'relationship'`. See [§3](#3-d3q3--the-debounced-message-email).                                                                                                            |
| **Q4**            | Which invite-shaped call sites emit ①?                                                 | All three — fresh invite, the AH-035 re-offer after decline, and the agency's re-offer answering the creator's own counter. See [§2.2](#22-q4--all-three-invite-shaped-paths-emit).                                       |
| **Q5**            | Does ①'s copy need a fresh-vs-re-offer discriminator?                                  | Yes, a light `outcome` param — two subjects, one template family. See [§2.3](#23-q5--the-outcome-discriminator).                                                                                                          |
| **Q6**            | Should the daily digest skip a thread that already got a debounced ⑧ email?            | No — considered and declined verbatim. See [§3.3](#33-q6--the-digest-is-not-suppressed-considered-and-declined).                                                                                                          |
| **Q7**            | Debounce table name.                                                                   | `message_email_debounces`, confirmed as proposed.                                                                                                                                                                         |
| **Q8**            | Shared-service name/home.                                                              | `App\Modules\Messaging\Services\DebouncedMessageMailer`, confirmed.                                                                                                                                                       |
| **Q9**            | The `CampaignAssignmentController::store()` transaction-ordering residual.             | Named, not fixed — ratified. See [§6](#6-q9--the-transaction-ordering-residual-named-not-fixed).                                                                                                                          |
| **Q10 / C5 / C8** | Mechanical findings — the agency-ulid link shape, the D-8 docblock reversal.           | Both shipped. See [§4](#4-c5--the-two-link-shapes-are-not-symmetric) and [§7](#7-c8--the-sprint-11-d-8-reversal-named-in-the-docblocks).                                                                                  |

---

## 2. D2/Q1/Q2 — the invite email

### 2.1 The listener-arm shape (C2's reinterpretation, §5.32)

The plan-pause's C2 finding held exactly: `CampaignInvitationService::invite()` and
`CampaignAssignmentStateMachine::commit()` already dispatch `AssignmentTransitioned` on every path
that lands an assignment on `invited`, and that event already has exactly one consumer,
`SendAssignmentNotifications`. The entire build is one new `match` arm plus one new private method,
`notifyCreatorOfInvite()` — mirroring the existing `notifyCreatorOfManualVerification()` shape.
**Zero lines changed in `CampaignInvitationService` or `CampaignAssignmentStateMachine`.**

```php
AuditAction::AssignmentInvited, AuditAction::AssignmentReInvited => $this->notifyCreatorOfInvite($assignment, $event->action, $actor),
```

`notifyCreatorOfInvite()` dual-emits: the in-app row via `NotificationService::notify()` writes
unconditionally (as `NotificationType::AssignmentInvited`, regardless of which of the two audit
verbs fired it — there is no `AssignmentReInvited` `NotificationType` case, and the plan-pause's C4
finding records this cross-audit-action emission explicitly as a new-but-deliberate shape, not a
precedented one). The mail leg routes through one private checkpoint, `queueInviteMail()`, the ONE
place `Feature::active(MissingCreatorMailsEnabled::NAME)` is read for this email — the
`CampaignApplicationNotifier::queue()` precedent, so no future call site can bypass the flag.

### 2.2 Q4 — all three invite-shaped paths emit

The plan-pause's C3 finding surfaced a third call site the kickoff hadn't named:
`reinvite()` (`countered → invited`, the agency's answer to a creator's own counter-offer), sharing
one `AuditAction::AssignmentReInvited` with `reofferAfterDecline()`. Since a `match` arm keys on
`AuditAction`, one arm for `AssignmentReInvited` catches both by construction — including one meant
including both, or a context flag would need adding to `commit()`'s callers purely to exclude one.
Pedram's ruling: include the third path — "same audit verb, same landing state, cheapest correct
build... a creator whose counter was answered with a re-offer deserves the email most of the three."

**Pinned by `InviteEmailNotificationTest.php` (9 tests, 28 assertions)** — one dedicated test group
per path (fresh invite via `CampaignInvitationService::invite()`, re-offer after decline via
`reofferAfterDecline()`, and the counter-response re-offer via `reinvite()`), each asserting: the
`AssignmentTransitioned` event dispatches with the correct `AuditAction`; the mail queues with the
correct `outcome` and locale when the flag is ON; the mail queues **nothing** when the flag is OFF
while the in-app `Notification` row still writes. A combined single-test version covering all three
paths in one method was attempted and **removed** — it 404'd on the second cross-agency `postJson`
call, a pre-existing test-harness tenant-scope caching artifact unrelated to this chunk's logic (the
same class of issue independently rediscovered and worked around in S8's dispatch tests, see
[§5](#5-s8--the-full-dispatch-path-set)); three independent tests already give full coverage without
fighting the harness.

### 2.3 Q5 — the outcome discriminator

`InviteReceivedMail` takes an `outcome: 'fresh'|'re_offer'` param — `AssignmentInvited` maps to
`fresh`, both `AssignmentReInvited` paths map to `re_offer` (sharing copy — "the creator's experience
is identical either way," per the ruling). Two subjects
(`invite_received.email.subject_fresh` / `subject_re_offer`), one template family, one Blade view.
Localized in all 24 `lang/*/campaigns.php`, real MT baseline including the flaky 10
(`bg, el, et, fi, ga, hu, lt, lv, mt, ro`).

### 2.4 D2's dual-emit ripple — the frontend LIVE_TYPES move

`assignment.invited` moves from `DEFERRED_WITHOUT_EMITTER` (`templates.spec.ts`) and
`EMIT_LESS_TYPES` (`i18n-notifications-parity.spec.ts`) into `LIVE_TYPES`
(`recipient: 'creator'`, in-app only, no digest — matching every other assignment-lifecycle type).
New `notifications.types.assignment_invited` + `typeLabels` copy ×24 locales. The creator-facing
type/toggle counts on `NotificationPreferencesPage` moved 12→13 / 13→14 accordingly, and both counts
are pinned by name in `NotificationPreferencesPage.spec.ts`, not left to an unexplained magic number
bump.

---

## 3. D3/Q3 — the debounced message email

### 3.1 One mailable, one shared service, two dispatch paths

`NewMessageMail` (`App\Modules\Messaging\Mail`) takes a `context: 'campaign'|'relationship'`
discriminator, following `DraftReviewedMail`'s precedent exactly — the two contexts differ in
strictly two things: which counterparty name renders, and which URL builder runs ([§4](#4-c5--the-two-link-shapes-are-not-symmetric)). One Blade view, one
`lang/*/messages.php` block (`new_message.*`).

`DebouncedMessageMailer::maybeSend(Model $thread, User $recipient, NewMessageMail $mailable): bool`
is the single checkpoint — flag read exactly once (gating the WHOLE method, including the debounce
table read/write, so a flag-OFF window is a total no-op and never silently ticks a stamp in the
background), the atomic debounce decision, the queue call, and a structured emission log
(`sent` / `debounced` / `flag_suppressed` / `no_email`). Called from exactly two places — the
un-branched "agency→creator" tail of `SendMessageNotifications::dispatch()` and the identical tail of
`RelationshipMessageNotifications::dispatch()` — never their creator→agency fan-out branches, which
is what makes D4 ("creators only") a property of **placement**, not a role check inside the service.

### 3.2 The atomic debounce (§5.6)

```php
$row = MessageEmailDebounce::query()->firstOrCreate(
    ['thread_type' => ..., 'thread_id' => ..., 'recipient_user_id' => ...],
    ['last_emailed_at' => now()],
);
if ($row->wasRecentlyCreated) return true;
if ($row->last_emailed_at->greaterThan($threshold)) return false;
$rearmed = MessageEmailDebounce::query()->whereKey($row->getKey())
    ->where('last_emailed_at', '<=', $threshold)->update(['last_emailed_at' => now()]);
return $rearmed === 1;
```

`firstOrCreate()` resolves the "brand new row" race atomically on Laravel 11 (retries as a lookup on
a unique-constraint violation). The "existing row, past its window" race is closed by a single
conditional `UPDATE ... WHERE last_emailed_at <= :threshold` read back by affected-row count — exactly
one concurrent caller can ever see `1`, with no explicit transaction or `lockForUpdate()`, identical
on Postgres/MySQL/SQLite. Pinned directly: `DebouncedMessageMailerTest`'s
"the atomic upsert never double-sends for two calls resolving the same window decision" case.

### 3.3 Q6 — the digest is NOT suppressed (considered and declined)

Recorded verbatim, per the ruling: "no, recorded as considered-and-declined with your reasoning
verbatim (opt-in digest + rare overlap + cross-service coupling not worth a cosmetic double-notice)."
A creator opted into both the debounced email and the daily digest may see the same unread thread
reported twice in quick succession. This is named in both `MessageDigestService`'s and
`UnreadMessagesDigestMail`'s corrected docblocks ([§7](#7-c8--the-sprint-11-d-8-reversal-named-in-the-docblocks)), not just here.

---

## 4. C5 — the two link shapes are NOT symmetric

The creator-side relationship-thread route is keyed by the **agency's** ULID
(`/creator/messages/:agencyUlid`), not `RelationshipThread::$ulid` — both models carry `HasUlid`, so
a naive "reuse the thread's own ulid for both branches" URL helper (the pattern every existing
mailable uses) would silently 404 every relationship-thread email. `NewMessageMail::buildThreadUrl()`
branches explicitly on context:

```php
return match ($this->context) {
    'campaign' => $base.'/creator/assignments/'.$this->assignmentUlid,
    'relationship' => $base.'/creator/messages/'.$this->agencyUlid,
};
```

Pinned by **two dedicated assertions, not a shared "contains a ulid" check** (the trap the plan-pause
named explicitly — a shared check would pass even with the wrong ulid substituted):
`NewMessageMailTest`'s "the CAMPAIGN context links... via the ASSIGNMENT ulid" and "the RELATIONSHIP
context links via the AGENCY ulid — never the RelationshipThread ulid (C5 trap)", each asserting the
correct fragment is present **and** the other surface's fragment is absent.

---

## 5. S8 — the full dispatch-path set

S6 proved the §5.34 debounce set against `DebouncedMessageMailer` directly (service isolation, 7
tests / 21 assertions, `DebouncedMessageMailerTest`). S8 re-proves the **identical** set through the
real HTTP dispatch paths on **both** thread models — an actual `POST .../messages` call all the way
through `SendMessageNotifications` / `RelationshipMessageNotifications` into the shared service —
because C6's whole point is that the two paths must never drift, and running the same set against
both is the only way to prove that rather than assert it.

**`MessageEmailDebounceDispatchTest.php` — 11 tests, 44 assertions** — first-unread emails ·
within-30-silent · after-30-re-emails · per-recipient independence · flag-OFF total mail silence
with the in-app row still writing, each duplicated for the campaign path and the relationship path,
plus one test confirming a creator→agency send on either path never queues mail regardless of the
flag (D4 by placement, re-proven at the dispatch level).

**Two break-reverts executed and restored at this level** (on top of the two S6 already proved at
the service level):

1. **Flag-OFF, again, at the dispatch level (the "×2" the kickoff asked for).** The flag check inside
   `DebouncedMessageMailer::maybeSend()` was temporarily replaced with `if (false)`. Both flag-OFF
   dispatch tests (campaign + relationship) **reded** — `NewMessageMail` queued unexpectedly.
   Reverted; both green again.
2. **Bypass the shared service on ONE dispatch path.** The `$this->debouncedMailer->maybeSend(...)`
   call was temporarily removed from `SendMessageNotifications::dispatch()` alone (the relationship
   path untouched). Result: **exactly the 4 non-trivial campaign-path §5.34 cases reded** (first-unread,
   within-30-silent, after-30, per-recipient-independence — the campaign flag-OFF case trivially
   stayed green, since "no mail queued" is indistinguishable from the correct reason when the mailer
   call is gone entirely), while **all 6 relationship-path cases and the creator→agency test stayed
   green**. This is the exact split the plan-pause's review priority 4 asked for — a bypass on one
   path reds only that path's cases, proving both paths independently call the shared checkpoint
   rather than one riding the other's test coverage by coincidence. Reverted; `git diff` on the
   touched file returns to byte-identical with its pre-break-revert state; full 11/11 green again.

---

## 6. Q9 — the transaction-ordering residual, named, not fixed

`CampaignAssignmentController::store()` wraps `CampaignInvitationService::invite()` **and** the
subsequent `settlePendingApplication()` call in one `DB::transaction()`. Because
`AssignmentTransitioned`'s one listener is synchronous (not queued) and every mail connection runs
`'after_commit' => false`, `invite()`'s dispatch — and, from this chunk on, its new mail leg — fires
**before** that transaction commits, with one more statement running after it inside the same
transaction. If that statement were to throw, the assignment row rolls back after ①'s email has
already been irrevocably queued for an assignment that no longer exists.

**Ratified as named-not-fixed**, adopting the analysis verbatim: the residual is
database-failure-order-of-magnitude (`settlePendingApplication` is a mechanical query + status update
with no business-rule throw path — no validation, no user input on that branch), and restructuring a
heavily-tested, multi-decision control-flow method for a residual this narrow would be worse than
naming it. This is a narrower version of the same class of residual the jobs-board chunk-3 plan
already accepted by name for a different flow — the queue-then-stamp ordering ("the residual failure
mode is a creator stamped whose mail then fails at the transport layer... I want this ratified
explicitly because it is the one place the design accepts a silent miss," `jobs-board-c3-plan.md`,
Q3 point 4). **Not fixed in this chunk.** If a future chunk touches `store()`'s transaction boundary
for any other reason, the reorder (dispatching after the transaction returns, the C1 shape already
used elsewhere in this same listener's other emission sites) should ride along then.

---

## 7. C8 — the Sprint 11 D-8 reversal, named in the docblocks

Sprint 11's D-8 stated plainly, in two places, that there is "NO immediate per-message email; the
digest IS the email path." This chunk ships exactly the thing D-8 ruled out — a deliberate, new
product decision, not a silent scope concession. Per the house convention ("surface disagreements as
explicit decisions, never concede scope silently"), both docblocks that carried the old claim are
corrected in this same commit, each now naming the AH-083 reversal by date and cross-referencing the
new mailable/service, and each naming that the digest and the debounced email are deliberately not
cross-suppressed (Q6):

- `MessageDigestService`'s class docblock.
- `UnreadMessagesDigestMail`'s class docblock.

Neither file's actual behavior changes — the digest still gates on
`NotificationService::isChannelEnabled()` for the `digest` channel exactly as before; only the stale
claim is corrected.

---

## 8. Production posture

**Nothing existing is read-for-mutation or rewritten.** The invite email (①) adds no schema at all —
`AssignmentInvited` already exists as both an `AuditAction` and a `NotificationType` case. The
debounced message email (⑧) adds one additive `CREATE TABLE`
(`message_email_debounces`), a real `morphTo('thread')` pair, composite-unique on
`(thread_type, thread_id, recipient_user_id)`, `last_emailed_at` NOT nullable (a row only exists once
an email has been sent). `down()` is structurally a true inverse but content-lossy — dropping the
table discards every recorded `last_emailed_at`, so a rollback-then-redeploy re-arms every
`(thread, recipient)` pair immediately; named explicitly in the migration's own docblock, snapshot
recommended before any rollback against a populated database.

**The outbound-mail exposure.** Both new mails sit behind ONE new Pennant flag, default OFF —
nothing sends at deploy. Neither emission is fan-out-shaped: ① is one creator per invite action; ⑧
is capped at one email per `(thread, recipient)` per 30 minutes by construction — unlike the
jobs-board arc's fan-out mail (a per-run cap over an N-creator population), there is no operator-sized
ceiling to configure here because the ceiling is inherent to the emission shape.

**Blast radius of a bug, worst first, as re-derived at plan-pause and unchanged at close:** (1) the
debounce window comparison inverted (emails on every message instead of one per 30 min) — this is why
review priority 1 broke-reverted that comparison specifically, proven to bite at both S6 (service
level) and S8 (dispatch level); (2) the relationship-thread deep link resolving to the wrong route
([§4](#4-c5--the-two-link-shapes-are-not-symmetric)) — a broken link in a live email, user-facing but
not a data-safety issue; (3) the flag-OFF path leaking a mail leg — bounded to one extra email per
action, not a storm, since neither leg is fan-out-shaped even when miswired, and this specific failure
mode was itself broken-reverted at both levels ([§5](#5-s8--the-full-dispatch-path-set)).

**Deploy obligations, layered onto the already-held AH-068/069 range** (`docs/runbooks/deploy-log.md`
carries the authoritative posture): a SECOND additive migration; the queue-worker restart
requirement is reaffirmed (already owed, now also covering two more mail-copy surfaces — new
`lang/*/campaigns.php` and `lang/*/messages.php` keys ×24 each, plus the two new mailable classes); one
new flag registered but **not armed** by this deploy (`missing_creator_mails_enabled` stays OFF —
arming it is a separate, later, deliberate act, per its `feature-flags.md` row).

---

## 9. Gate board

| Gate                                                                                               | Result                                                                                                                                                                                                                |
| -------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend (Pest), full suite                                                                         | **2605 passed, 1 skipped** · 9733 assertions                                                                                                                                                                          |
| `InviteReceivedMailTest.php` (new, §5.3 renders ×24)                                               | **19 passed** · 103 assertions                                                                                                                                                                                        |
| `InviteEmailNotificationTest.php` (new, all 3 invite branches' §5.34 cases)                        | **9 passed** · 28 assertions                                                                                                                                                                                          |
| `DebouncedMessageMailerTest.php` (new, §5.34 set at service level)                                 | **7 passed** · 21 assertions                                                                                                                                                                                          |
| `MessageEmailDebounceModelTest.php` (new, schema + morphTo shape)                                  | **5 passed** · 10 assertions                                                                                                                                                                                          |
| `MessageEmailDebounceDispatchTest.php` (new, §5.34 set at dispatch-path level, both thread models) | **11 passed** · 44 assertions                                                                                                                                                                                         |
| `NewMessageMailTest.php` (new, §5.3 renders ×24 both contexts + C5 link assertions)                | **23 passed** · 136 assertions                                                                                                                                                                                        |
| Full Messaging module Pest                                                                         | **145 passed** · 630 assertions                                                                                                                                                                                       |
| Full Campaigns module Pest                                                                         | **547 passed** · 2350 assertions                                                                                                                                                                                      |
| PHPStan (project-wide)                                                                             | **0 errors** (932 files)                                                                                                                                                                                              |
| Pint (project-wide)                                                                                | **passed**                                                                                                                                                                                                            |
| Frontend (Vitest), full `apps/main` suite                                                          | **1625 passed / 166 files**                                                                                                                                                                                           |
| Frontend (Vitest), full `apps/admin` suite                                                         | **458 passed / 54 files** (unrelated to this chunk — confirms zero regression)                                                                                                                                        |
| Frontend (Vitest), `packages/api-client` suite                                                     | **204 passed / 9 files** (unrelated — confirms zero regression)                                                                                                                                                       |
| `vue-tsc --noEmit` (`apps/main`)                                                                   | **clean**                                                                                                                                                                                                             |
| ESLint (`apps/main`)                                                                               | **0 errors, 2 pre-existing unrelated `v-html` warnings** (`ClickThroughAccept.vue`, `ProfileBasicsForm.vue`) — 0 new                                                                                                  |
| Break-reverts (5 total, all restored)                                                              | 30-min comparison inversion (S6); flag-OFF at service level (S6); flag-OFF at dispatch level (S8); flag-OFF ×2 total, as asked; one-service-bypass-per-dispatch-path (S8)                                             |
| Playwright                                                                                         | **Not run this chunk** — no Playwright spec reaches the invite-accept flow's email content or the messaging debounce window (matching the inventory's I6 finding); the existing E2E board is the bar, not a new spec. |

---

## 10. What this chunk deliberately did not build

Per-type email preference reads (D5 — explicitly deferred to AH-084, which wires the email channel
through `notification_preferences` platform-wide; `tech-debt.md`'s AH-056 entry now carries this
chunk's own pointer to that split); the agency-side direction of ⑧ (D4 — creators only; the table's
`recipient_user_id` shape leaves it open, no schema change needed later); any cap or `--dry-run`
command for either emission (D6 — neither is fan-out-shaped, so there is nothing to preview or
drain); a fix for `CampaignAssignmentController::store()`'s transaction-ordering residual
([§6](#6-q9--the-transaction-ordering-residual-named-not-fixed) — named, not fixed); digest/debounced-email
cross-suppression ([§3.3](#33-q6--the-digest-is-not-suppressed-considered-and-declined) — considered
and declined); ⑨'s contract/onboarding vocabulary gap or ⑦'s `live_verified` mail gap (both named in
the original inventory, neither in this chunk's scope).
