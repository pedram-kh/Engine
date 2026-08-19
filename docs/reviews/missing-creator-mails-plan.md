# Missing creator emails: invite (①) + debounced message (⑧) — PLAN (plan-pause)

- **Status:** Plan-pause. **No code written.** Awaiting clearance before sub-step 1.
- **Date:** 2026-08-19
- **Entry:** AH-083 (confirmed free — AH-082 is the latest landed entry, `adhoc-changes-log.md:73`).
- **Author:** Cursor, against the kickoff's locked decisions (D1–D6).
- **HEAD:** `db6ec03d` (`docs(notifications): creator email notification set — read-only inventory`).
  Working tree clean, nothing held.
- **Orientation re-read at plan time:** `WORKING-PROCESS.md` (all 9 sections), `PROJECT-WORKFLOW.md`
  §5 references cited inline, `docs/reviews/adhoc-changes-log.md` (AH-082 → AH-078 range),
  `docs/reviews/RESUMPTION-TEMPLATE.md` Part 2, `docs/reviews/creator-mail-set-inventory.md` (this
  chunk's own inventory — re-verified below, not re-trusted blind), `docs/feature-flags.md`,
  `docs/tech-debt.md`.

---

## 0. The §5.40 line, re-derived

> **⚠️ PROD-DATA RISK: LOW.**
>
> **What touches rows that already exist:** nothing. Both new migrations are `CREATE TABLE` (the ⑧
> debounce stamp) or none at all for ① (no schema change — `AssignmentInvited` already exists as both
> an `AuditAction` and a `NotificationType` case; nothing is added to either enum). No existing
> `campaign_assignments`, `message_threads`, `relationship_threads`, or `user_notification_preferences`
> row is read-for-mutation or rewritten by anything in this chunk.
>
> **What the feature writes to live data, once deployed and flag-armed:** new rows only —
> (a) `notifications` (in-app), if the D2 in-app proposal below is ratified; (b) the new debounce
> table, one row per `(thread, recipient)` pair that has ever received a debounced email, updated in
> place on each re-arm — never deleted, never touching another table.
>
> **The outbound-mail exposure, stated plainly.** Both new mails are flag-gated behind ONE new
> Pennant flag, default **OFF** — nothing sends at deploy regardless of how many invites are extended
> or messages sent. D6 is right that neither is fan-out-shaped (① is one creator per invite action;
> ⑧ is capped at one email per (thread, recipient) per 30 minutes by construction), so unlike the
> jobs-board arc's first mail (~279-creator reachable population in one job) there is no per-run cap
> to size — the ceiling is inherent to the emission shape, not an operator-configured limit. The
> **agency-side** direction of ⑧ (creator → agency, fanning to `notifiableMembers()`) is explicitly
> OUT of scope (D4) — this chunk touches ONLY the two "agency/system → creator" recipient branches in
> `SendMessageNotifications` and `RelationshipMessageNotifications`, never the fan-out branches. That
> is what keeps ⑧ bounded to "one creator, one thread, one 30-minute window" rather than inheriting
> the N-recipient fan-out shape the inventory's I5 flagged as the one volume-shaped reading of ⑧.
>
> **`down()` honesty (§5.40):** the debounce table's `down()` has a true structural inverse
> (`dropIfExists`) with a genuinely lossy content inverse — dropping it discards every recorded
> `last_emailed_at`, so a rollback-then-redeploy would re-arm every (thread, recipient) pair
> immediately (everyone whose last email was inside the 30-minute window gets a second one on the
> next message). Named explicitly in the migration's `down()` comment, matching the AH-054/AH-056
> house style for a lossy-but-honest inverse.
>
> **Blast radius of a bug, worst first:** (1) the debounce window comparison inverted (emails on
> _every_ message instead of one per 30 min) — this is why review priority 1 break-reverts that
> comparison specifically, since a silent flip from "debounced" to "every message" is the single
> highest-volume failure mode in the chunk, bounded only by however many messages a human can type;
> (2) the relationship-thread deep link resolving to the wrong route (see finding C5 below) — a
> broken link in a live email, not a data-safety issue, but user-facing and worth a dedicated assertion;
> (3) the flag-OFF path leaking a mail leg — bounded to "one extra email" per action, not a storm,
> because neither leg is fan-out-shaped even when miswired.
>
> **This is a downgrade from the inventory's own "eventual build" estimate (⚠️ LOW-MEDIUM)**, and the
> reason is D6 plus D4: the inventory's LOW-MEDIUM was sized against "new mail volume to live
> creators" in the abstract, before D4 fixed ⑧'s recipient set to creators-only (dropping the
> N-member agency fan-out that would have been the one genuinely volume-shaped leg) and before D6
> named both emissions as inherently uncapped-because-uncappable-in-a-bad-way. LOW is the honest
> re-derivation once those two decisions are in hand.

---

## 1. Corrections and read-pass findings that affect the kickoff

Ten findings, in the order they change what gets built. None of them overturn a locked decision;
several change _how much code_ implements it.

### C1 — AH id confirmed: **AH-083** is free.

`git log`/`adhoc-changes-log.md:73` shows AH-082 (insert-link button + cap raise) as the latest landed
entry. AH-083 is unused. Using it as locked.

### C2 — ⭐ §5.32 reinterpretation: D2's "both invite branches emit" needs ZERO new code at either

call site — `SendAssignmentNotifications` already consumes both, and the match arm is the entire
implementation surface.

This is the chunk's single biggest simplification. `CampaignInvitationService::invite()`
(`CampaignInvitationService.php:123-129`) and `CampaignAssignmentStateMachine::commit()`
(`CampaignAssignmentStateMachine.php:653-675`, the shared internals of both `reinvite()` and
`reofferAfterDecline()`) **both already dispatch the exact same `AssignmentTransitioned` event**, and
that event already has exactly one consumer for exactly this purpose:
`Event::listen(AssignmentTransitioned::class, [SendAssignmentNotifications::class, 'handle'])`
(`CampaignsServiceProvider.php:53`). The listener's `handle()` is a single `match ($event->action)`
(`SendAssignmentNotifications.php:84-98`) that currently sends `AuditAction::AssignmentInvited` and
`AuditAction::AssignmentReInvited` to `default => null` — i.e. **both branches the kickoff names are
already wired into the one listener that would emit ①'s mail; they just fall through today.**

The entire D2 build, mechanically, is: one new private method on `SendAssignmentNotifications`
(`notifyCreatorOfInvite()`, mirroring the existing `notifyCreatorOfManualVerification()` shape at
`:188-223` — mail + optional in-app, guarded on a non-empty recipient email) plus one or two new
`match` arms pointing at it. **No change to `CampaignInvitationService` or
`CampaignAssignmentStateMachine` at all** — they already do everything a new consumer needs (audit
row + event, with `$actor`, `from`/`to`, and the assignment). This is strictly less code than the
kickoff's framing ("Both invite branches emit... name both call sites") might suggest, because naming
the call sites turns out to be the whole job — they don't need editing, just consuming.

### C3 — ⚠ A THIRD invite-shaped call site exists that the kickoff does not name: `reinvite()`

(`countered → invited`, D-7's counter-response), and it is not obviously in or out of scope.

The kickoff says "Both invite branches emit (fresh invite AND the AH-035 re-offer)." Reading the state
machine (`CampaignAssignmentStateMachine.php:130-155`) turns up a third: `reinvite()`, the agency's
answer to a creator's **counter-offer** (`countered → invited`, Sprint 8 D-7) — distinct from
`reofferAfterDecline()` (`declined → invited`, the AH-035 chunk this kickoff does name). Both land the
assignment back on `invited` and both fire `AuditAction::AssignmentReInvited`
(`CampaignAssignmentStateMachine.php:141`, `:188` — the docblock at `:170` says `reofferAfterDecline`
"reuses the `assignment.re_invited` audit verb," confirming they share one verb by design). Since a
`match` arm keys on `AuditAction`, one arm for `AssignmentReInvited` would silently catch **both**
paths — I cannot build for "the AH-035 re-offer" alone without either explicitly excluding the
countered-response path or accepting it comes along for free.

**I have not assumed an answer.** Arguments for including it: the creator experience is identical
("I have an open offer waiting" — whether it arrived as a first invite, a re-offer after decline, or a
re-offer after their own counter, the actionable fact is the same, and excluding it would need a
context flag neither `commit()` caller sets today). Argument against: the kickoff's own prose named
exactly two branches, and a creator who just countered an offer already knows a reply is coming — they
initiated the exchange, unlike a cold invite or a re-offer to someone who had declined and moved on.
**Q4 below asks for a ruling; my lean is to include it (same audit verb, same landing state, cheapest
to build correctly, and excluding it would need to special-case one `commit()` caller against another
with no context to key on)** but D2 named two, not three, and I will not silently build the third.

### C4 — `NotificationType::AssignmentInvited` is the only case in this family; there is no

`NotificationType::AssignmentReInvited`. If ①'s notification fires from a `re_invited` audit event,
it fires under the `AssignmentInvited` _type_ regardless.

Confirmed by grep: exactly one `case` in `NotificationType.php` matches this family
(`NotificationType.php:44`, `case AssignmentInvited = 'assignment.invited';`). `AuditAction` and
`NotificationType` are two independent axes tied by convention, not 1:1 enforcement — the existing
`CampaignApplicationRejected` precedent already proves one `NotificationType` can be emitted from
multiple call sites, but always under the _same_ `AuditAction`. This chunk would be the first case
where a `NotificationType` is emitted in response to a _different_ `AuditAction` value
(`AssignmentReInvited`) than its own name/value implies. I think this is the right call — from the
recipient's seat, "you have an open offer" is one notification concept regardless of which of the
three machine paths produced it — but naming it explicitly here because it is a new shape, not a
precedented one, and the reviewer should see it stated rather than discover it in a diff.

### C5 — ⚠ The relationship-thread deep link needs the AGENCY's ULID, not the thread's own ULID — a

real link-shape trap, caught before it shipped.

The creator-side relationship-thread route is `path: ':agencyUlid'` nested under `/creator/messages`
(`apps/main/src/modules/creators/routes.ts:136-158`) — keyed by the **agency's** ULID, not
`RelationshipThread.ulid`. Both models carry `HasUlid` (`MessageThread.php:49`,
`RelationshipThread.php:53`), so a naive "use the thread's own ulid for both branches" mailable-URL
helper (the pattern every existing mailable uses, e.g. `ContractAttachedMail.php:49` — assignment
ulid) would silently 404 for every ⑧ relationship-thread email. The two link builders are therefore
NOT symmetric:

- Campaign thread → `{frontend}/creator/assignments/{assignment->ulid}` (re-using the exact
  `ContractAttachedMail.php:45-50` shape — the thread already renders inline via `ChatPanel` on that
  page, confirmed at `CreatorAssignmentDetailPage.vue:47,585`, so no new route or anchor is needed).
- Relationship thread → `{frontend}/creator/messages/{agency->ulid}` — `$thread->agency` must be
  eager-loaded (both notifier classes already do this — `RelationshipMessageNotifications.php:41`)
  and its `ulid`, not the thread's, is what goes in the URL.

This will be pinned by a dedicated assertion per link (not a shared "the URL contains _a_ ulid" check,
which would pass even with the wrong one substituted).

### C6 — The two message-notifier classes already isolate exactly the branch D4 needs; there is no

risk of the debounce hook accidentally reaching the agency-fan-out branch.

Both `SendMessageNotifications::dispatch()` (`:34-76`) and `RelationshipMessageNotifications::dispatch()`
(`:39-85`) have the identical shape: an early-return branch for "creator sent → fan out to
`notifiableMembers()`", then falls through to a single, un-branched "agency sent → the creator's
`User`" tail (`SendMessageNotifications.php:63-75`; `RelationshipMessageNotifications.php:73-84`).
D3's shared service will be called from that tail alone, in both classes — never inside the
`fanOutToAgency()` / creator→agency loop. This makes D4 ("creators only") a property of _where_ the
call is placed, not a condition the shared service has to check — cheaper and safer than a runtime
role check that could be gotten wrong.

### C7 — The debounce table's natural shape is a real Eloquent `morphTo('thread')`, and its column

names (`thread_type`, `thread_id`) are exactly what that relation would produce by convention — not a
coincidence worth re-deriving from scratch.

D3 specifies the columns literally as `(thread_type, thread_id, recipient_user_id, last_emailed_at)`.
Laravel's default morph-column naming for a relation named `thread()` is precisely `thread_type` /
`thread_id`. The house already has exactly this idiom for a polymorphic subject —
`notifications.subject_type` / `subject_id`, a real `morphTo()` (`Notification.php:97`,
`NotificationService.php:55` stores `$subject?->getMorphClass()`) — and the app registers **no**
`Relation::morphMap()` anywhere (confirmed by grep), so `getMorphClass()` returns the raw FQCN
(`App\Modules\Messaging\Models\MessageThread` / `...\RelationshipThread`), exactly as
`notifications.subject_type` already stores raw FQCNs today. I will build the debounce row as a real
`morphTo('thread')` relation rather than a hand-rolled string discriminator, matching the one
polymorphic precedent that already exists rather than inventing a second, differently-shaped one.

### C8 — D3 reverses a prior, explicit, on-the-record architectural ruling (Sprint 11 D-8): "there is

NO immediate per-message email." That reversal should be named, not silently absorbed.

`MessageDigestService`'s docblock states plainly: _"The digest is the messaging email channel (D-8
spec-divergence: there is NO immediate per-message email; the digest IS the email path, opt-in /
default OFF)"_ (`MessageDigestService.php:21-23`, restated verbatim in
`UnreadMessagesDigestMail.php:14-16`). D3 is a deliberate, new decision to ship exactly the thing D-8
ruled out — which is fine (decisions get revisited; the kickoff is explicit about wanting immediate,
debounced mail), but the house convention is "surface disagreements as explicit decisions, never
concede scope silently" (`WORKING-PROCESS.md:30-31`), and a future reader of `MessageDigestService`'s
docblock would otherwise be told something this chunk makes false. **I will update that docblock's
claim in the same commit**, pointing forward to this chunk rather than leaving a stale architectural
claim sitting next to the code it now contradicts.

### C9 — An asymmetry D3 doesn't mention: campaign threads already have a digest that can double-tell

the same story; relationship threads have none at all.

`MessageDigestService::agencyDigests()`/`creatorDigests()` (`:70-150`) query `MessageThread` only —
`RelationshipThread` is never touched by the digest (confirmed absent from both private methods, and
independently by `RelationshipMessageNotifications.php:31`'s own docblock: _"Digest is deferred (D5 —
in-app unread covers it)"_). So for a **campaign** thread, a creator could plausibly get the new
debounced ⑧ email _and_ see the same still-unread thread summarized again in tomorrow's digest (if
they're opted into the digest, which is itself opt-in/default-OFF) — mild duplication, not a bug, but
worth naming since nobody asked for it explicitly. For a **relationship** thread, ⑧'s email is the
first email path of any kind — a strictly additive surface with no interaction to reason about.
**Q6 below** asks whether the digest should skip a thread that already got a debounced email inside
its own window, or whether the duplication is acceptable (my lean: acceptable — the digest is opt-in
and rare in practice, and suppressing cross-service is a coupling this chunk doesn't need to buy).

### C10 — `lang/*/messages.php`'s existing `digest.*` block is English-only by a \*\*recorded deliberate

decision\*\* (not oversight) — the new ⑧ keys must NOT inherit that shortcut.

`UnreadMessagesDigestMail.php:19-21`: _"Renders in the application default locale (en) for all
recipients... by deliberate decision. See docs/tech-debt.md 'Digest + agency-invite emails are
English-only.'"_ All 24 `lang/*/messages.php` files exist, but the `digest.*` leaves in the 23
non-English ones are presumed byte-identical to English by that same recorded decision (not
re-verified value-by-value here — out of this chunk's scope to audit). The kickoff's own review
priority 5 (§5.3 renders + flaky-10) and the "Expected ripple" line ("i18n (mail copy ×24)") make clear
that ⑧'s new copy gets the **real MT-baseline** treatment the digest explicitly opted out of — I am
flagging the precedent in the same file specifically so it is not copy-pasted by accident.

---

## 2. Cross-chunk contracts consumed, verified at HEAD (§5.11)

| Contract consumed                                               | Verified shape                                                                                                                                                   | Where                                                                              |
| --------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| `AssignmentTransitioned` event + its one listener               | Single `Event::listen` registration; `match($event->action)` keyed on `AuditAction`; both target audit actions currently fall to `default => null`               | `CampaignsServiceProvider.php:53`; `SendAssignmentNotifications.php:84-98`         |
| `CampaignInvitationService::invite()`                           | Writes `AssignmentInvited` audit row + dispatches the event unconditionally; does no notification itself by design                                               | `CampaignInvitationService.php:112-131`                                            |
| `CampaignAssignmentStateMachine::commit()`                      | Every legal transition (incl. both re-invite edges) wraps in its own `DB::transaction()`, writes audit, dispatches the event, all inside that transaction        | `CampaignAssignmentStateMachine.php:642-676`                                       |
| `SendMessageNotifications` / `RelationshipMessageNotifications` | Both isolate an un-branched "agency→creator" tail distinct from the "creator→agency fan-out" branch                                                              | `SendMessageNotifications.php:34-76`; `RelationshipMessageNotifications.php:39-85` |
| `MessageThread` / `RelationshipThread`                          | Both `HasUlid`; both loaded with `agency`/`assignment` relations at the notifier call sites already                                                              | `MessageThread.php:49`; `RelationshipThread.php:53`                                |
| `Agency`                                                        | `HasUlid` confirmed (needed for the relationship deep link, C5)                                                                                                  | `Agency.php:49`                                                                    |
| Flag registration mechanics                                     | Three-file touch: a `Features/*Enabled.php` class, one line in `CreatorsServiceProvider::registerFeatureFlags()`, one row in `AdminFeatureFlagController::FLAGS` | `CreatorsServiceProvider.php:253-263`; `AdminFeatureFlagController.php:55-88`      |
| The "one-checkpoint" mail pattern                               | `CampaignApplicationNotifier::queue()` — flag read exactly once, structured emission log, queue-time locale                                                      | `CampaignApplicationNotifier.php:262-327`                                          |
| The shared-family mailable pattern                              | `DraftReviewedMail` — one class, one Blade view, an `outcome` discriminator picking subject/body variant                                                         | `DraftReviewedMail.php:33-65`                                                      |
| Debounce-table shape precedent                                  | `user_notification_preferences` — composite-unique, `updateOrCreate`-shaped, NOT the once-only `campaign_job_notifications` family                               | `2026_06_06_100001_create_user_notification_preferences_table.php:41-60`           |
| Messaging module's own mail precedent                           | `UnreadMessagesDigestMail` — `App\Modules\Messaging\Mail`, markdown view, `lang/*/messages.php`                                                                  | `UnreadMessagesDigestMail.php`                                                     |
| Polymorphic-subject precedent                                   | `notifications.subject_type`/`subject_id`, real `morphTo()`, no morph map registered anywhere                                                                    | `Notification.php:97`; `NotificationService.php:55`                                |

No gap found against any of these. The two parity specs' allowlists (`templates.spec.ts:35-43`'s
`DEFERRED_WITHOUT_EMITTER`, `i18n-notifications-parity.spec.ts:93-101`'s `EMIT_LESS_TYPES`) both
currently list `'assignment.invited'` — re-verified directly, matching the inventory's citation.

---

## 3. Sub-step plan

Nine sub-steps in four phases, ordered so the flag exists before anything it could gate, and so the
listener/service changes are provably no-ops (flag OFF) before the call sites that would trigger them
are wired.

### Phase A — the flag (S1)

**S1 · The flag.** `MissingCreatorMailsEnabled` in `app/Modules/Creators/Features/` (joining the
existing one-registry convention, C-precedent from every sibling flag), `NAME =
'missing_creator_mails_enabled'`, default-OFF closure. Registered in
`CreatorsServiceProvider::registerFeatureFlags()` and `AdminFeatureFlagController::FLAGS` (label +
description explicitly stating it gates MAIL ONLY for ① and ⑧; in-app, where it exists, is
unaffected). `docs/feature-flags.md` row, matching the `application_notifications_enabled` row's shape
(off-state behavior described explicitly: both mails are complete no-ops; in-app rows, if D2 is
ratified dual-emit, still write). No consumer yet — this ships as an inert, toggleable, audited
no-op flag, provably so because nothing reads it until S3/S6.

Green on: the flag round-trips through `GET`/`POST /api/v1/admin/feature-flags` (the "HTTP arm/disarm
test" the kickoff names, AH-056's tinker-only lesson), a `feature_flag.toggled` audit row on flip,
Pint, PHPStan.

### Phase B — D2, the invite email (S2–S4)

**S2 · The mailable.** `InviteReceivedMail` (or similar) in `App\Modules\Campaigns\Mail`, following
the `DraftReviewedMail` shared-family shape only if C3/Q4 puts more than one semantic variant in play
(fresh invite vs. re-offer copy could differ — "you've been invited" vs. "an updated offer is
waiting" — Q5 below asks whether that distinction is worth a discriminator param or whether one
subject/body serves all landings-on-`invited`). Localized copy in `lang/*/campaigns.php` (joining the
existing block family: `reviewed`, `completed_on_approval`, `manually_verified`,
`contract_attached`), real MT baseline across all 24 including the flaky 10. Deep-links to
`/creator/assignments/{ulid}` (re-using the `ContractAttachedMail` URL-builder shape exactly, C5).
§5.3 real-rendering test on the `DraftReviewedMailTest` model — instantiate with scalars, force
`App::setLocale()`, call `envelope()`/`render()` per locale, plus the queued-locale assertion.

Green on: 24-locale render test, Pint, PHPStan.

**S3 · The listener wiring.** One new private method on `SendAssignmentNotifications`
(`notifyCreatorOfInvite()`), guarded on `Feature::active(MissingCreatorMailsEnabled::NAME)` for the
mail leg only (mirroring `ApplicationNotificationsEnabled`'s "flag gates mail; in-app honours
preference" split — though D5 defers the preference read itself). Match arms added for
`AuditAction::AssignmentInvited` and — pending Q4 — `AssignmentReInvited`. **If D2's in-app proposal
(§5 below) is ratified**, this step also adds the `NotificationType::AssignmentInvited` emission via
`NotificationService::notify()`, which pulls in the parity-spec edits (removing `'assignment.invited'`
from both `DEFERRED_WITHOUT_EMITTER` and `EMIT_LESS_TYPES`), a new `LIVE_TYPES` entry
(`recipient: 'creator'`, `IN_APP_ONLY` per every other assignment-lifecycle type, no `digest`), and the
`notifications.types.assignment_invited` template key ×24. If mail-only is ratified instead, none of
that ripple exists and S3 is materially smaller.

Green on: review priority 3 in full — both named invite branches emit (and, if C3/Q4 says yes, the
counter-response branch too, or an explicit exclusion test proving it does NOT if the ruling goes the
other way); flag-OFF silences the mail leg via break-revert (flip the flag, watch the assertion fail,
revert); if dual-emit, `templates.spec.ts` + `i18n-notifications-parity.spec.ts` green with the moved
entries; `NotificationTypeEnumTest`'s one-vocabulary tie test unaffected (no new enum case).

**S4 · The §5.2 event splits + full Campaigns Pest.** One `Event::fake` pair per newly-consumed audit
action (event dispatches ↔ mail/in-app consequence actually happens, split per
`PROJECT-WORKFLOW.md:192-201`), plus the flag-OFF/flag-ON split.

Green on: full backend Pest for the Campaigns + Notifications modules, Pint, PHPStan.

### Phase C — D3/D4, the debounced message email (S5–S8)

**S5 · Schema.** One additive migration, `create_message_email_debounces_table` (name proposed —
Q7 asks for a better one if this collides conceptually with anything). Columns: `id`, `thread_type`,
`thread_id` (the `morphTo('thread')` pair, C7), `recipient_user_id` (FK to `users`, matching the
table's own recipient-scoping precedent), `last_emailed_at` (timestamp, NOT nullable — a row only
exists once an email has actually been sent), timestamps. `unique(['thread_type', 'thread_id',
'recipient_user_id'])` — the row is looked up and updated in place, never inserted twice for the same
triple, mirroring `user_notification_preferences`' composite-unique shape (C7) rather than the
once-only stamp family. `down()` drops the table with an explicit lossy-content comment (§0 above).
No index beyond the unique constraint — lookups are always by the full composite key, never a partial
scan.

Green on: migrate + rollback both directions on a scratch DB, model + factory, Pint, PHPStan.

**S6 · The shared service.** `App\Modules\Messaging\Services\DebouncedMessageMailer` (name proposed,
Q8), the "one checkpoint" pattern (`CampaignApplicationNotifier::queue()` precedent, C2's sibling for
messaging): one public method taking the thread model, the recipient `User`, a context discriminator
(`campaign` | `relationship`), and the render data; internally it (a) reads or creates the debounce
row via a single atomic `updateOrCreate`-with-conditional-guard (avoiding the read-then-write race —
§5.6 idempotency, since two messages in the same thread within milliseconds must not both pass the
30-minute check), (b) checks `last_emailed_at` against `now()->subMinutes(30)`, (c) queues
`NewMessageMail` and re-stamps `last_emailed_at = now()` only when the row is new or past the window,
(d) checks `Feature::active(MissingCreatorMailsEnabled::NAME)` exactly once, inside this method,
mirroring `CampaignApplicationNotifier`'s "the flag is read in exactly ONE place" discipline
(`CampaignApplicationNotifier.php:65-66`), (e) logs a structured line per call (sent / debounced /
flag-suppressed), the `logEmission()` precedent. **Called from exactly two places** (D3's own
requirement): the "agency→creator" tail of `SendMessageNotifications::dispatch()` and the identical
tail of `RelationshipMessageNotifications::dispatch()` (C6) — never their fan-out branches, which
satisfies D4 by placement rather than by a role check inside the service.

Green on: review priority 1 in full (the 30-minute comparison's disjoint §5.34 set — first-unread
emails, within-30-min silent, after-30-min re-emails, per-recipient independence — plus the named
break-revert inverting the comparison); review priority 4 (bypass the service from one dispatch path
in a mutation test, watch only that path's case go red, revert); the atomic-upsert race test (two
near-simultaneous messages, one email); Pint, PHPStan.

**S7 · The mailable.** ONE mailable, `App\Modules\Messaging\Mail\NewMessageMail` (D3's own "context
param" option, argued for at §5 below against `DraftReviewedMail`'s own precedent), with a `context`
discriminator picking (a) the subject/body variant and (b) which URL builder runs (C5's two distinct,
non-symmetric link shapes). Localized copy lands in `lang/*/messages.php` (C10 — matching the module
that owns both dispatch paths, sibling to the existing `digest.*` block, and explicitly NOT inheriting
that block's English-only shortcut), real MT baseline ×24 including the flaky 10. §5.3 render test per
locale per context (both branches actually render, not just one).

Green on: 24-locale × 2-context render test, the two link-shape assertions from C5 (dedicated, not a
shared "contains a ulid" check), queued-locale assertion, Pint, PHPStan.

**S8 · Full Messaging Pest + the §5.34 debounce set at the dispatch-path level.** Feature tests
exercising the real `sendHumanMessage()` paths on both `MessageService` and `RelationshipMessageService`
(not just the service in isolation), asserting: flag-OFF → zero mail from either path, in-app rows
unaffected (the review-priority-2 break-revert anchor); flag-ON → first unread message in a thread
emails, a second message inside 30 minutes does not, a message sent 31 minutes later does, and two
different recipients (rare today, common once agency-side is added — the D4 "leaves the door open"
property) get independent windows. Time control via `Carbon::setTestNow()`, per the inventory's own
I6 note that this is not something Playwright's real-time model covers.

Green on: full backend Pest for the Messaging module, the full §5.34 set named above, both
break-reverts (priority 1 and priority 4) executed and restored, Pint, PHPStan.

### Phase D — docs + full board (S9)

**S9 · Docs + the full board.** The review file (`docs/reviews/missing-creator-mails-review.md`) with
its mandatory Production-posture section; the AH-083 log entry; `docs/feature-flags.md` row (S1);
`docs/tech-debt.md`'s AH-056 email-channel entry gains D5's one-line pointer ("these two mails, like
all existing mail, are not yet individually opt-out-able; the flag is the kill switch; AH-084 wires
the channel"); the `MessageDigestService` docblock correction (C8); `RESUMPTION-TEMPLATE.md` Part 2
per §5.39 including the deploy obligations (below).

Green on: the full gate board — backend Pest serial at 2G, `apps/main` + `apps/admin` Vitest,
api-client, `vue-tsc`, ESLint, `pint --all`, PHPStan with an explicit memory limit, locale parity, full
Playwright (no new E2E-traversed surface is added by this chunk — confirmed no Playwright spec reaches
the invite-accept flow's email content or the messaging debounce window, matching the inventory's I6
finding — so the existing 28/2 board is the bar, not a new spec).

---

## 4. Review priorities → where each is discharged

| Priority                                                                       | Sub-step(s) | Notes                                                                                                                                                          |
| ------------------------------------------------------------------------------ | ----------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1 · debounce §5.34 set + break-revert the 30-min comparison (inverted)         | S6, S8      | The comparison lives in exactly one place (`DebouncedMessageMailer`) so one break-revert covers both dispatch paths at once.                                   |
| 2 · flag-OFF silences both mails + break-revert                                | S3, S6, S8  | Two independent flag reads (listener, service) both gated on the same `Feature::active()` call — one break-revert per read site.                               |
| 3 · both invite branches emit (+ the re-offer case)                            | S3          | Extends to three branches pending Q4's ruling on the counter-response path (C3).                                                                               |
| 4 · both thread models through the one service (break-revert: bypass one path) | S6, S8      | Bypassing `SendMessageNotifications`'s call (leaving `RelationshipMessageNotifications`'s in place) must red exactly the campaign-thread case, and vice versa. |
| 5 · §5.3 renders + flaky-10                                                    | S2, S7      | Two mailables, one context-discriminated (S7 covers 2 contexts × 24 locales).                                                                                  |
| 6 · full board + CI at tip                                                     | S9          | Per §5.41 — cited by run URL in the completion package, not asserted from the local board alone.                                                               |

---

## 5. The two proposals the kickoff asked for at plan-pause

### D2 in-app proposal — **recommend dual-emit (mail + in-app)**, ripple accepted.

Arguments weighed:

- **For dual-emit:** every other assignment-lifecycle moment that matters to a creator already has an
  in-app row — draft reviewed, draft approved, manually verified, completed-on-approval,
  application-accepted (which dual-emits identically to what ① would become). An invite is at least as
  notification-worthy as any of those, arguably more so (it's the first ask). Today a creator has
  **zero passive signal** that a new invite landed — no bell, no badge, nothing in `LIVE_TYPES` — only
  the assignments list page if they think to check it. Mail-only would leave that gap in place for
  anyone who reads their notification bell but doesn't check their inbox promptly, which is a strange
  asymmetry for the platform's literal first touchpoint with a creator on a given campaign.
- **Against dual-emit:** the assignments list (`CreatorAssignmentsPage`) already surfaces pending
  invites prominently with Accept/Decline actions, so an in-app row is arguably telling a creator
  something the page they'd act on already tells them — less true of, say, a draft-approved
  notification, which reports an _agency's_ action taken elsewhere. And dual-emit is the only thing
  that pulls in the parity-spec edits + `LIVE_TYPES` entry + i18n-for-in-app-template ripple the
  kickoff's own "Expected ripple" line names as conditional ("if D2 goes dual-emit").

**My recommendation is dual-emit**, on the "zero passive signal today" argument — an in-app row is the
cheap, low-risk half of every other notification in this system (silenced by the recipient's own
`in_app` preference, never leaves the platform) and the parity-spec edit is mechanical, not risky. I
would not fight hard for this if overruled toward mail-only; it is a real judgment call, not a
correctness question, and the mail-only path is strictly less code (S3 shrinks, no `LIVE_TYPES`/parity
work).

### D3 mailable-shape proposal — **recommend ONE mailable with a context param**, following

`DraftReviewedMail`'s own precedent.

`DraftReviewedMail` already proves the shape works for genuinely different-content variants sharing one
class, one Blade view, and one lang block with an `outcome`-keyed sub-structure
(`DraftReviewedMail.php:33-65`; `lang/en/campaigns.php`'s `reviewed` block). The two ⑧ contexts differ
in strictly fewer ways than `DraftReviewedMail`'s three outcomes do: only (a) which counterparty name
renders (campaign name + sender name vs. agency name + sender name — `UnreadMessagesDigestMail`
already proves this kind of counterparty-naming split can live in one mailable's data array, C7's
sibling finding) and (b) which URL builder runs (C5). A `context: 'campaign'|'relationship'`
discriminator on one `NewMessageMail` class, picking the subject/body variant and the link builder,
keeps this to one class, one Blade view, one lang block (`messages.php`'s `new_message.*`, with
`campaign`/`relationship` sub-keys mirroring `reviewed`'s `outcome`-keyed shape) — versus two thin
classes duplicating the envelope/content boilerplate and doubling the render-test surface for no
copy that couldn't share a home. Two thin mailables would only be the better call if the two contexts'
_visual_ templates diverged (different Blade partials, different layout needs) — nothing in the
existing `UnreadMessagesDigestMail`/`DraftReviewedMail` precedents suggests that's true here, and I see
no product reason for a campaign-thread notice and a relationship-thread notice to look meaningfully
different beyond the one link and one name.

---

## 6. Open questions

**Q1 — AH id.** AH-083, confirmed free (C1). Assume yes unless corrected.

**Q2 — D2 in-app or mail-only?** My recommendation is dual-emit; see §5 above. Ratify one way or the
other before S3.

**Q3 — D3 one mailable or two?** My recommendation is one, context-discriminated; see §5 above.

**Q4 — Does ①'s emission cover the THIRD invite-shaped call site (`reinvite()`, `countered →
invited`, D-7's counter-response — C3), or only the two the kickoff names (fresh invite + the AH-035
re-offer)?** Both `reinvite()` and `reofferAfterDecline()` share one `AuditAction`
(`AssignmentReInvited`), so a single match arm cannot cheaply distinguish them — including one means
including both, or a context flag needs adding to `commit()`'s call sites to tell them apart (real but
small extra work). **My lean is include it** (same landing state, same audit verb, cheapest correct
build) but the kickoff named two, and I will not silently build a third.

**Q5 — Does ①'s copy need a discriminator between "fresh invite" and "re-offer" (different sentence:
"you've been invited" vs. "an updated offer is waiting"), or does one subject/body serve every landing
on `invited` regardless of which of the (two or three) paths produced it?** Affects whether S2's
mailable needs its own `outcome`-style param. My lean: a light discriminator is worth it — "you've been
invited" reads oddly for a re-offer to someone who already knows the agency — but this is a copy
call, not an architecture one, and cheap either way.

**Q6 — Should the daily digest (`MessageDigestService`) skip a `MessageThread` that already received
a debounced ⑧ email inside the digest's own lookback window, to avoid telling a creator about the same
unread thread twice in quick succession (C9)?** My lean: no — the digest is opt-in/default-OFF, the
overlap is rare in practice (both would have to be true: opted into the digest AND the debounced email
already fired within roughly a day), and cross-service suppression is a coupling this chunk doesn't
need to buy for a cosmetic double-notice. Recording as a considered-and-declined item if this lean
holds, not silently skipped.

**Q7 — Debounce table name.** `message_email_debounces` proposed. Confirm, or a better name (e.g.
`message_notification_debounces`, matching `user_notification_preferences`'s `notification` vocabulary
more closely).

**Q8 — Shared-service name and module home.** `App\Modules\Messaging\Services\DebouncedMessageMailer`
proposed (Messaging module, since it's called from and owns both dispatch paths). Confirm, or propose
better.

**Q9 — The `CampaignInvitationService::invite()` transaction-ordering residual, named rather than
silently accepted.** `CampaignAssignmentController::store()` wraps `invite()` **and** the subsequent
`settlePendingApplication()` call in one `DB::transaction()` (`CampaignAssignmentController.php:253-257`).
Because `AssignmentTransitioned`'s one listener (`SendAssignmentNotifications`) is synchronous, not
queued, `invite()`'s dispatch — and, once this chunk ships, its new mail leg — fires **before** that
transaction commits, and one more statement (`settlePendingApplication`) runs after it inside the same
transaction. If that statement were to throw, the assignment row would roll back after ①'s email had
already been irrevocably queued (`after_commit => false` project-wide) for an assignment that no
longer exists. This is a narrower version of the same class of residual the jobs-board chunk-3 plan already accepted
by name for a different flow (queue-then-stamp ordering: "the residual failure mode is a creator
stamped whose mail then fails at the transport layer... I want this ratified explicitly because it is
the one place the design accepts a silent miss" — `jobs-board-c3-plan.md`, Q3 point 4).
`settlePendingApplication`
is a mechanical query + status update with no business-rule throw path (no validation, no user input on
that branch), so the practical exposure is on the order of "the database itself fails between these
two statements" — not zero, but the same order of risk the state-machine's own `commit()` already
accepts for the other six verbs this same listener handles (whose transactions are `commit()`'s own,
scoped even tighter). **I am not proposing to restructure `store()`'s transaction to fix this** —
reordering a heavily-tested, multi-decision control-flow method for a residual this narrow is worse
than naming it. Flagging for ratification rather than silently shipping it unmentioned.

**Q10 — Migration timestamp / exact filename.** Will use the next available date-ordered slot; no
functional decision here, naming for completeness.

---

## 7. Standards this chunk will apply

§5.2 (Event::fake splits per newly-consumed audit action, S4); §5.3 (real-rendering mailable tests,
both mailables, all locales × both ⑧ contexts, S2/S7); §5.6 (idempotency on the debounce row's atomic
upsert, S6); §5.11 (§2 above); §5.32 (C2's reinterpretation — the listener-arm shape rather than
call-site edits — and C4's cross-audit-action `NotificationType` emission, both recorded for
ratification); §5.34 (the debounce disjoint set, S6/S8; both invite branches, S3); §5.35 (break-revert
on the 30-minute comparison, the flag-OFF silence×2, and the both-thread-models-through-one-service
bypass, all named in §4's table); §5.39 (resumption template refresh in the closing docs commit); §5.40
(the line in §0, additive-only migration, honest lossy `down()`, the Production-posture section).

Two standing operational rules that bind this chunk specifically: **restart the queue worker on
deploy** (new mailable classes + new `lang/**` copy in both `campaigns.php` and `messages.php`); **no
scheduler dependency** — both triggers are synchronous dispatch-path calls (an invite action, a message
send), never a cron.

---

## 8. What this chunk deliberately does not build

Per-type email preference reads (D5 — explicitly deferred to AH-084, which wires the channel this
chunk's flag stands in for); the agency-side direction of ⑧ (D4 — creators only; the table's
`recipient_user_id` shape leaves it open, no schema change needed later); any cap or `--dry-run`
command for either emission (D6 — neither is fan-out-shaped, so there is nothing to preview or drain);
⑨'s contract/onboarding vocabulary gap or ⑦'s `live_verified` mail gap (both named in the inventory,
neither in this kickoff's scope); a fix for the digest's pre-existing English-only posture (C10 — that
debt is named, not resolved, here); any restructuring of `CampaignAssignmentController::store()`'s
transaction boundary (Q9 — named, not fixed).

---

**No code will be written until this plan is cleared.**
