# Draft Workflow v2 — Chunk A (numbered visible rounds): Plan

**Status:** PLAN-PAUSE. No code written. Awaiting Claude's clearance per
`WORKING-PROCESS.md` §2 Mode A step 3.

**Author:** Cursor (read pass + plan)

**Date:** 2026-08-16

**HEAD at read time:** `ba89907` — `docs(reviews): Draft Workflow v2 read-only inventory (asks A + B)`. Working tree clean.

**AH entry reserved:** AH-068.

**Kickoff:** Draft Workflow v2 chunk A, decisions D1–D6, review priorities 1–6.

**Evidence base:** `docs/reviews/draft-workflow-v2-inventory.md` §§0.1, 2, 3, 4 —
cited rather than re-derived, per the kickoff. This plan adds a targeted read pass
over the surfaces D2–D5 name, and §1 below records what that pass **confirmed**
and what it **overturned**.

---

## 0. §5.40 re-derivation

**PROD-DATA RISK: LOW.**

Confirmed against the plan actually proposed below, not inherited from the
kickoff:

- **No migration, no schema change, no new column, no index.** D1 is satisfied by
  storage that shipped in Sprint 9 Chunk 1 (inventory §2.1).
- **No production row is read for mutation, and none is rewritten.** No backfill,
  no one-shot command, no scheduled job. Nothing joins the deploy-obligations
  tracker.
- **No new API field.** The read pass confirmed D3's fields are already emitted
  (§1.2), so `packages/api-client` types are untouched and no resource gains a key.
- **No behaviour change** — D6, evidenced in S6.
- The only writes the chunk introduces at runtime are **one additive key in the
  `data` bag of newly-created notification rows** (S4). Additive, forward-only,
  and it rewrites nothing.

**One live-data consequence that is NOT a write and must be designed around.**
Existing `notifications` rows in production already carry their `data` bag, frozen
at emit time. The in-app body renders client-side by spreading that bag as
vue-i18n named params:

```58:65:apps/main/src/modules/notifications/components/NotificationCenter.vue
function bodyText(row: NotificationResource): string {
  const key = notificationTemplateKey(row.attributes.notification_type)
  // Pass the row's `data` bag as named-interpolation params. Each template
  // references ONLY the keys its emit site sends; extra keys are ignored and
  // missing keys never appear because no template references a key its own
  // emit site doesn't provide.
  return t(key, { ...row.attributes.data })
}
```

If the four body templates gain a `{version}` placeholder (D4 read literally),
**every notification row already in production renders with a hole where the round
number should be**, because those rows have no `version` key. Note what that would
do to the comment above: the invariant it asserts — _"missing keys never appear
because no template references a key its own emit site doesn't provide"_ — becomes
false the moment a template references a key that historical emit sites did not
provide. This is a read-time correctness defect on live data introduced by a pure
copy change, and it is the one thing in this chunk that could reach a real user as
a visible bug. §5 Q1 proposes the mechanism that avoids it while preserving D4's
intent; the risk line stays LOW **on the condition** that Q1 is resolved in favour
of a missing-key-tolerant shape. If Claude directs the literal `{version}`-in-body
mechanism instead, the line becomes **⚠️ PROD-DATA RISK: LOW-with-a-named-defect**
— no row is altered, but historical in-app rows degrade — and I will say so again
before building.

---

## 1. Read-pass findings

Five findings. Two confirm the kickoff's premises, one narrows a decision, one
**overturns part of D4's mechanism**, and one is a scope discovery that adds real
work.

### 1.1 CONFIRMED — D1 needs no storage, and D3 needs no resource change

`campaign_drafts.version` + the unique `(assignment_id, version)` + the per-row
review trail are exactly as the inventory recorded (§2.1–2.2). Nothing to add.

### 1.2 CONFIRMED — D3's fields are already on the wire; no additive field needed

The kickoff asked me to verify this at read pass rather than trust §2.3–2.4.
Verified end to end:

```37:50:apps/api/app/Modules/Campaigns/Http/Resources/CampaignDraftResource.php
            'attributes' => [
                'version' => $draft->version,
                'submitted_at' => $draft->submitted_at?->toIso8601String(),
                'caption' => $draft->caption,
                'hashtags' => $draft->hashtags,
                'mentions' => $draft->mentions,
                'media' => $this->mapMedia($draft),
                // External reference links (draft-composer facelift): plain
                // url+name pairs, no signing — they are external URLs.
                'links' => $draft->links,
                'review_status' => $draft->review_status->value,
                'reviewed_at' => $draft->reviewed_at?->toIso8601String(),
                'review_feedback' => $draft->review_feedback,
            ],
```

and the creator's own endpoint serialises through that exact resource —
`'drafts' => CampaignDraftResource::collection($drafts)->resolve($request)`
(`CreatorAssignmentDraftController.php:140`), ordered `orderByDesc('version')`
(`:85-88`). So `review_feedback` and `reviewed_at` are **already delivered to the
creator today and simply not rendered**.

**D3 is therefore a pure render change.** No resource field, no api-client type,
no backend diff at all. The parenthetical escape in D3 ("if a resource must gain
a field, it's additive and named in the plan") is not needed.

### 1.3 NARROWED — D5's "board card's draft chip" is the drawer's row, not the card face

`BoardCard.vue` — the card face — contains **no draft or version reference
whatsoever** (grep for `draft|version`, case-insensitive: zero matches). It shows
the assignment-status chip only (`BoardCard.vue:48`).

The draft chip D5 means is in the card **drawer**:

```504:524:apps/main/src/modules/boards/components/BoardCardDrawer.vue
                <v-list-item v-if="latestDraft">
                  <v-list-item-title>{{
                    t('app.campaigns.board.drawer.detail.latestDraft')
                  }}</v-list-item-title>
                  <v-list-item-subtitle
                    class="d-flex align-center ga-2"
                    data-test="board-card-drawer-draft"
                  >
                    <v-chip size="x-small" variant="tonal">
                      {{
                        t('app.campaigns.review.draftVersion', {
                          n: latestDraft.attributes.version,
                        })
                      }}
                      ·
                      {{
                        t(
                          `app.campaigns.review.draftStatus.${latestDraft.attributes.review_status}`,
                        )
                      }}
                    </v-chip>
```

That row is in scope and is already a "Draft v{n} · status" composite — the
closest thing in the product to D2's status-bearing form. **Adding a draft chip to
the card face would be net-new scope, not a rename**, so I am reading D5 as the
drawer row and will say so in the review file. Flagged as Q5 in case the intent
was the face.

### 1.4 NARROWED — the AH-061 hand-off row does not name drafts, so it is out of scope by evidence

D5 scoped it conditionally ("if it names drafts"). It does not. AH-061's Review
button reuses `app.campaigns.review.action` ("Review") and the row it sits on is
labelled from the assignment-status map
(`app.campaigns.assignmentStatus.draft_submitted`). AH-061's own entry records
"**An existing key, reused** — `app.campaigns.review.action`, so this adds nothing
to the 24-locale surface" and "No new i18n key" (`adhoc-changes-log.md:469`,
`:473-474`). Nothing to rename. One line in the review file.

**Boundary this establishes, and I want it ratified:** the
`app.campaigns.assignmentStatus.*` block (16 keys × 24 locales, six dynamic
consumers per inventory §8.4) is the **assignment-status** vocabulary, not the
round vocabulary. D2 does **not** touch it. Renaming "Draft submitted" there would
fan out to every status chip in both SPAs and is not what D2 asks for.

### 1.5 OVERTURNED — D4's "resubmitted" leg has no dedicated type, and its payload has no draft loaded

Two mechanical facts that change how S4 must be built:

**(a) There is no "resubmitted" notification.** Resubmission re-fires
`assignment.draft_submitted` — the same agency-recipient type the first submission
fires (inventory §3.3). So D4's three-way "changes-requested / resubmitted /
approved" maps onto **four** existing types, one of which serves both first-submit
and resubmit: `assignment.draft_submitted` (agency), plus
`assignment.revision_requested`, `assignment.draft_approved`,
`assignment.draft_rejected` (creator). Round context makes the shared type _more_
useful, not less — "Draft 3 submitted for review" is precisely what disambiguates
a resubmit from a first submit. But it means the copy must read correctly at
`n = 1`, where "Draft 1 submitted" is a first submission and nothing was
resubmitted. D4 names no rejected-leg requirement; I propose including it for
family consistency (Q4).

**(b) The submit leg's notifier never loads a draft.**
`notifyAgencyOfSubmission()` uses only campaign + creator
(`SendAssignmentNotifications.php:120-157`); only the review legs query the latest
draft, and they select two columns:
`->first(['review_feedback', 'reviewed_by_user_id'])` (`:181-184`).

**The mechanism I propose instead of a new query — the event already carries the
round number.** `AssignmentTransitioned` exposes a public `context` array
(`AssignmentTransitioned.php:31-38`), and every one of the four verbs is dispatched
with `version` in it: the submit path passes
`['draft_id' => …, 'version' => $version, 'media_count' => …, 'link_count' => …]`
(`CreatorAssignmentDraftController.php:349-355`), and the review path passes
`['draft_id' => $draft->ulid, 'version' => $draft->version]`
(`CampaignAssignmentReviewController.php:206`). So `$event->context['version']`
serves **both** legs with **zero new queries** and zero new coupling — the §5.38
posture of reading the contract the domain already emits.

One guard needed: the machine can be driven directly with no context (the state
machine's own tests do exactly that — `submitDraft($assignment, $user)`), so the
listener must tolerate an absent key. Proposed fallback in Q2.

### 1.6 SCOPE DISCOVERY — neither draft mailable has a §5.3 real-rendering test today

D4 requires "§5.3 re-render + queued-locale on every touched mailable". The read
pass found those tests **do not exist** for either mailable this chunk touches.
`DraftReviewedMail` and `DraftSubmittedForReviewMail` are referenced in exactly
three test files, and every reference is a queue assertion, not a render:

- `CampaignAssignmentReviewTest.php:85`, `:106`, `:145` —
  `Mail::assertQueued(DraftReviewedMail::class, fn (…) => $m->outcome === …)`
- `NotificationProofConsumerTest.php:102`, `:120` — same shape
- `NotificationFanOutTest.php:132-136`, `:243` —
  `Mail::assertQueued(DraftSubmittedForReviewMail::class, 1)` + `hasTo`

Eight files in the suite _do_ carry real-render mail tests
(`ApplicationMailTest`, `JobPostedMailTest`, `InvitationMailTest`,
`AdminRelationMailTest`, `CreatorLifecycleMailTest`,
`IncompleteCreatorNudgeMailTest`, `MailLocalizationTest`,
`MailThemeBrandingTest`) and **none of them covers the Sprint-9 draft mails** —
`MailThemeBrandingTest` renders four unrelated mailables
(`VerifyEmailMail`, `ResetPasswordMail`, `InviteAgencyUserMail`,
`ProspectCreatorInviteMail`).

So S5 **creates** two §5.3 test files rather than extending existing ones. This is
a pre-existing standards gap that D4 forces closed for the two mailables it
touches. It is the single largest piece of work in the chunk and the reason S5 is
its own sub-step. I am not proposing to close the gap for untouched mailables.

---

## 2. Standards this chunk applies

| Standard                        | How it lands here                                                                                                                                                                                                       |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| §5.32 decision reinterpretation | Two mechanisms adapt while intent survives: D4's payload source becomes the event context, not a query (§1.5); D4's round-in-copy becomes a missing-key-tolerant shape, not a body placeholder (§0, Q1). Both recorded. |
| §5.34 negative cases            | The D6 behaviour-parity assertion is the positive; its negatives are the three copy-only surfaces asserting the _absence_ of any transition/notification delta. Plus the `n = 1` case for the shared submit type.       |
| §5.35 break-revert              | On the D6 parity pin and the D4 payload-key pin (mutate → watch the right spec red → revert → verify restore via `git status`/`git diff` → re-green).                                                                   |
| §5.3 real-rendering mailables   | Two new test files, subject + body per locale + the queued-locale assertion + pinned URL shape (§1.6).                                                                                                                  |
| §5.2 `Event::fake` split        | Applies to the S4 listener work: the dispatched leg and the no-side-effect leg stay separate tests.                                                                                                                     |
| 24-locale done-gate + flaky-10  | Every renamed and new key ships a real MT baseline in all 24, including `bg, el, et, fi, ga, hu, lt, lv, mt, ro`. No English fallback (AH-028/046/047 ruling).                                                          |
| §5.25 constant parity           | `templates.spec.ts` gains the `version` data-key pin (D4's AH-058 Q8 precedent).                                                                                                                                        |
| §5.40                           | §0 above; re-stated at build start.                                                                                                                                                                                     |
| §5.39                           | S7 refreshes `RESUMPTION-TEMPLATE.md` Part 2 in the closing docs commit.                                                                                                                                                |

**Two gates do the D2 orphan-cleanup enforcement for us**, which is worth naming
because review priority 2 asks for it. `i18n-locale-parity.spec.ts` flags both
`MISSING` and `EXTRA` keys per locale against the en source-of-truth (`:113`,
`:118`) and separately asserts placeholder-token equality with en (`:126-147`).
So a rename that leaves an orphan in even one of 24 files, or that drops `{n}` in
one locale, reds the suite by construction — the cleanup claim will be gate-backed,
not prose-backed.

---

## 3. Sub-step plan

Seven sub-steps. Each ends green on the gates named. No sub-step depends on a
later one.

### S1 — D2: the vocabulary rename (i18n keyset + every consumer)

The keyset surgery and the call sites move together; split apart, both halves are
red.

_Keys retired / added_ (en source, then all 24):

| Namespace      | Today                                              | Becomes                                                               |
| -------------- | -------------------------------------------------- | --------------------------------------------------------------------- |
| `creator.json` | `…detail.history.version` = "Version {n}"          | round-family key (Q3 names it)                                        |
| `creator.json` | `…detail.history.title` = "Draft history"          | retained or re-copied (Q3)                                            |
| `creator.json` | `…detail.reviewStatus.*` (4 keys)                  | copy aligned to the family; **key path stays creator-owned** (see Q6) |
| `app.json`     | `app.campaigns.review.draftVersion` = "Draft v{n}" | round-family key                                                      |
| `app.json`     | `app.campaigns.review.history` = "Version history" | round-family key                                                      |
| `app.json`     | `app.campaigns.review.draftStatus.*` (4 keys)      | copy aligned; **stays agency-owned** (Q6)                             |

_Consumer call sites repointed_ — 15 sites across 4 components, enumerated so the
review can check completeness:

- `CreatorAssignmentDetailPage.vue:920` (title), `:930` (version), `:934` (status)
- `ReviewDraftDrawer.vue:215` (heading, latest draft), `:285` (history title),
  `:294` (history version), `:296` (history status)
- `DraftsTab.vue:47`, `:48`, `:49`, `:50` (filter dropdown labels), `:194`
  (row version), `:197` (row status)
- `BoardCardDrawer.vue:514` (drawer draft chip version), `:521` (status)

**Green:** `i18n-locale-parity.spec.ts` (4), the four affected Vitest specs
updated for new copy, `vue-tsc`, ESLint on touched files.

### S2 — D3: the creator's history rows gain feedback + `reviewed_at`

Render-only, per §1.2. The creator's `v-list-item` gains the feedback the agency
already sees plus the review timestamp, formatted through the existing
`formatDate`/`formatDateTime` helpers the page already imports. Reuses the
existing `revision.noFeedback` empty-state copy where a round closed without
feedback (inventory §3.2) rather than inventing a second one.

**Green:** `CreatorAssignmentDetailPage.spec.ts` with a **3-round fixture**
(v1 revision_requested + feedback, v2 rejected-or-revision + feedback, v3 pending)
per review priority 3 — asserting each round renders its own feedback and its own
timestamp, and that a feedback-less round renders the empty state rather than a
blank.

### S3 — D2: the status-bearing composite forms

"Draft 2 — awaiting review" / "— changes requested" / "— approved" on the five
surfaces: creator history rows, agency drawer heading, agency drawer history rows,
Drafts-tab rows, board-drawer draft chip. Copy shapes proposed in §4; mechanism
(one composite key with two params vs. template-side composition) is Q3.

**Green:** the four component specs + parity.

### S4 — D4a: round context in the in-app notification payload + templates

Backend: `SendAssignmentNotifications` reads `$event->context['version']`
(§1.5) and threads it into the `data` bag for all four types — the two review
legs at `:212-218`, the agency submit fan-out at `:151-156`. No new query, no new
type, no `LIVE_TYPES` change, no `AuditAction`, no recipient change.

Frontend: the round surfaces in the notification centre per Q1's resolution
(recommended: a conditional detail line following the existing
`NotificationCenter.vue:69-74` optional-data pattern, which leaves historical rows
byte-identical).

**Green:** `NotificationProofConsumerTest` + `NotificationFanOutTest` extended
with the payload-key assertion (§5.2 split preserved); `templates.spec.ts` gains
the `version` data-key pin (AH-058 Q8 precedent, review priority 4);
`i18n-notifications-parity.spec.ts` (10) green; parity green.
**Break-revert** on the payload-key pin.

### S5 — D4b: mail copy + the two new §5.3 real-rendering test files

`DraftReviewedMail` and `DraftSubmittedForReviewMail` gain a round parameter;
`lang/*/campaigns.php` × 24 gains the round clause under
`assignment_notifications.reviewed.email.*` and `.draft_submitted.email.*`; the
two Blade views render it —
`apps/api/resources/views/mail/campaigns/draft-reviewed.blade.php` and
`…/draft-submitted.blade.php` (the views behind
`markdown: 'mail.campaigns.draft-reviewed'` at `DraftReviewedMail.php:48` and
`markdown: 'mail.campaigns.draft-submitted'` at
`DraftSubmittedForReviewMail.php:46`).

**New test files** per §1.6 — subject + body per locale, the flaky-10
real-translation assertion in the `ApplicationMailTest.php:169-180` shape, the
queued-locale assertion, and a pinned emitted-URL shape (the mail builds
`/creator/assignments/{ulid}` at `DraftReviewedMail.php:59-64`, currently pinned
nowhere).

**Green:** the two new mail test files; the three existing queue-assertion specs
still green unmodified where possible.

### S6 — D6: the zero-behaviour evidence

Two artefacts, because the kickoff asks for command output and offers the parity
assertion as the alternative — I propose **both**, since they cover different
failure modes:

1. **Zero-diff by path** (`git diff --stat` output verbatim, empty), for:
   `CampaignAssignmentStateMachine.php`, `CampaignAssignmentReviewController.php`,
   `CreatorAssignmentDraftController.php`, `CampaignDraftResource.php`,
   `CampaignDraftListItemResource.php`, `apps/api/database/migrations/`,
   the whole `Modules/Boards/` tree, and `packages/api-client/`.
   **Scoped honestly:** `SendAssignmentNotifications.php` and the two mailables
   are _not_ in this set — S4/S5 touch them by design. Their claim is narrower and
   is artefact 2's job.
2. **A behaviour-parity assertion** driving a full
   submit → request-changes → resubmit → approve cycle and asserting the row set,
   the transition set, the audit verbs, and the notification type + recipient +
   count are identical to today — with the only permitted delta being one
   additive `data` key. This is the assertion that catches a listener regression
   the path-diff cannot see.

Plus the AH-044 empty-draft rule and the review endpoints proven untouched by (1).
**Break-revert** on the parity assertion.

### S7 — Docs (second commit of the two-commit pair)

AH-068 log entry; the chunk review file with the **Production posture** section
(§5.40); the three D5 out-of-scope recordings (drawer-timeline latest-only
limitation, cancel-mid-cycle latency, no-cap + the clause-2.4 product note in the
review file's own words); `tech-debt.md` entry for the §5.3 gap on the mailables
this chunk did _not_ touch; `RESUMPTION-TEMPLATE.md` Part 2 per §5.39.

### Then: the full gate board

Full backend Pest (serial, `-d memory_limit=2G`), full Vitest on `apps/main`,
api-client, `vue-tsc`, ESLint, `pint --all`, PHPStan, locale parity — and the
**full Playwright suite** per review priority 6, dev stack down, isolated E2E DB,
restart + health-check after. Exposure stated honestly in §6.

---

## 4. Proposed copy shapes (D2/D3 — mine to propose per the kickoff)

Within the "Draft {n}" family. English shown; all 24 get real MT baselines.

**The round label.** `Draft {n}` — no "v", no "Version". Chosen because it is
already the noun both sides use for the artefact ("Draft history", "Review draft",
"Draft submitted"), so the counter attaches to a word the UI owns rather than
introducing "round" as a second concept the copy would then have to teach.

**Status-bearing forms** (S3), one per `review_status`, with the assignment's own
state disambiguating `pending`:

| Round state                                                                                  | Copy                            |
| -------------------------------------------------------------------------------------------- | ------------------------------- |
| `pending`, assignment `draft_submitted`                                                      | `Draft {n} — awaiting review`   |
| `revision_requested`                                                                         | `Draft {n} — changes requested` |
| `approved`                                                                                   | `Draft {n} — approved`          |
| `rejected`                                                                                   | `Draft {n} — not accepted`      |
| `pending`, assignment not `draft_submitted` (the cancel-adjacent open round, inventory §4.2) | `Draft {n} — submitted`         |

Note the last row: it exists because a `pending` round is not always "awaiting
review" — that is exactly the latent open-round case D5 records as out of scope,
and giving it neutral copy costs one key and means the UI cannot lie if a cancel
path ever ships. "Not accepted" for `rejected` follows the existing mail copy's
register ("your draft … was not accepted",
`lang/en/campaigns.php:26`) rather than the harsher chip label.

**Block titles.** Creator: `Draft history` (unchanged — already correct).
Agency: `Version history` → `Draft history`, so both sides name the same thing
identically, which is the whole point of D2.

**D3's creator rows.** Round label + status, then the agency's feedback verbatim,
then `Reviewed {date}`. Feedback-less closed rounds reuse
`creator.ui.assignments.detail.revision.noFeedback`.

**Notification / mail round clause** (D4). Body copy stays as-is; the round rides
as a leading fragment on the mail subject/body and as a conditional detail in-app
per Q1 — e.g. subject `Changes requested on your {campaign} draft` gains
`(Draft {n})`, so the string reads correctly whether or not the round is known.

---

## 5. Open questions

Blocking ones first.

**Q1 (blocking, §5.40-adjacent) — how does the round reach the in-app body without
breaking historical rows?** Per §0, adding `{version}` to the four
`notifications.types.*` templates makes every pre-existing production notification
row render with a hole. Options:

- **(a) RECOMMENDED — a conditional round detail, not a body placeholder.** Render
  the round as a separate element shown only when `data.version` is present,
  following the pattern this very component already uses for optional data —
  `detailText()` is documented as a _"Free-text detail line, rendered only when its
  key is present"_ and returns a label+text only for a present, non-empty key
  (`NotificationCenter.vue:67-77`). Historical rows render exactly as they do
  today; new rows gain the round; the `bodyText` invariant quoted in §0 stays
  true. Preserves D4's intent ("the cycle's notifications carry round context")
  with zero live-data degradation.
- **(b) Two template keys per type**, picked by data presence. Correct but doubles
  four keys × 24 locales for no user-visible gain over (a).
- **(c) Literal `{version}` in the body**, accepting degraded historical rows.
  Cheapest, and the only option that matches D4's letter exactly.

**Q2 (blocking) — the absent-context fallback.** `$event->context['version']` is
present on every production path (§1.5) but absent when the machine is driven
directly, as its own tests do. Propose: **omit the key when absent** (the payload
simply carries no `version`, which under Q1(a) renders as today) rather than
falling back to a query. It keeps the listener query-free, keeps the §5.2 split
clean, and means a direct machine call cannot invent a round number. Confirm, or
direct the query fallback.

**Q3 — the round key names and whether `history.title` re-copies.** I propose
`…history.round` (creator) and `app.campaigns.review.draftRound` (agency) for the
label, `app.campaigns.review.history` retained as a key with re-copied value
("Draft history"), and the S3 composites as **one key per round state with an
`{n}` param** (five keys) rather than template-side concatenation — concatenation
breaks in locales that order the clause differently and would defeat the
placeholder-parity gate's usefulness. Confirm the naming, or name your own.

**Q4 — does the rejected leg get round context too?** D4 names
changes-requested / resubmitted / approved. `assignment.draft_rejected` is the
fourth type in the same family and its mail already exists. I propose including it
for family consistency (one more key, same mechanism, no new type). Confirm.

**Q5 — D5's "board card's draft chip": drawer row, or the card face?** Per §1.3
the card face carries no draft data at all. I have scoped it as the drawer row
(`BoardCardDrawer.vue:504-524`). Adding a round chip to the card face is
defensible product-wise but is **net-new UI, not a rename**, and I would want it
as an explicit decision rather than absorbed into D2.

**Q6 — the two status blocks stay in their own namespaces, yes?** D2 says "one
vocabulary". The creator's `reviewStatus.*` and the agency's
`app.campaigns.review.draftStatus.*` are four-label twins that were **deliberately
split**: Sprint 9 Chunk 2 moved the agency drawer off the creator namespace
because an agency component reading a creator key caused a real test-harness
key-miss (`sprint-9-chunk-2-review.md:130`). I propose unifying the **copy** and
keeping both **key paths**, so D2 does not silently reverse that decision. Confirm.

**Q7 — is `app.campaigns.assignmentStatus.*` confirmed out of scope?** Per §1.4.
It is the 16-status assignment vocabulary with six dynamic consumers; touching it
is a different chunk.

**Q8 — S5's size.** Closing the §5.3 gap for two mailables across 24 locales
(subject + body + flaky-10 + queued-locale + URL pin) is the bulk of this chunk's
test work. Confirm it stays in chunk A rather than splitting to its own chunk — I
recommend keeping it, because D4 changes the copy and shipping changed mail copy
with no render test would be the exact false-green §5.3 exists to prevent.

---

## 6. Playwright exposure — stated honestly

**No Playwright spec asserts any string this chunk renames, and no spec traverses
the draft cycle.** Evidence: a grep across `apps/main/playwright` for
`Draft history|Version history|Draft v|Version {n}|draft-history|review-history|board-card-drawer-draft`
returns **zero hits**, and the only spec that mentions "draft" at all is
`jobs-board-full-lifecycle.spec.ts` — in the comment explaining why it stops
_before_ the draft cycle (inventory §8.7).

So E2E regression risk for this chunk is genuinely nil, and I will not claim
Playwright as evidence of anything. Per review priority 6 the full suite still
runs before push (dev stack down, isolated E2E DB, restart + health-check after),
because "surely it still passes" has been wrong twice (`WORKING-PROCESS.md:127-129`)
— but its role here is the standing pre-push gate, not coverage of this work.

**Incidental, out of scope, reported:** the read pass surfaced garbled
English-mixed strings in `impersonation.json` for `et`, `ga`, `el`, `lv`, `fi`,
`lt`, `hu`, `ro`, `mt` (e.g. `"Ei hand-off token was provided"`,
`"Níl hand-off token was provided"`). Same class as the garbled `hr`/`sk`/`sl`
text AH-046 found and tracked. Unrelated to this chunk — noted for `tech-debt.md`
in S7, not fixed here.

---

## 7. Expected ripple

| Area                           | Touched                                                                                                                                               |
| ------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Backend app code               | `SendAssignmentNotifications.php` (payload key), `DraftReviewedMail.php`, `DraftSubmittedForReviewMail.php`, the two Blade views                      |
| Backend lang                   | `lang/*/campaigns.php` × 24                                                                                                                           |
| Backend tests                  | 2 **new** §5.3 mail test files; `NotificationProofConsumerTest`, `NotificationFanOutTest` extended; 1 new behaviour-parity spec (S6)                  |
| SPA components                 | `CreatorAssignmentDetailPage.vue`, `ReviewDraftDrawer.vue`, `DraftsTab.vue`, `BoardCardDrawer.vue`, `NotificationCenter.vue`                          |
| SPA locales                    | `creator.json` × 24, `app.json` × 24, `notifications.json` × 24 (Q1-dependent)                                                                        |
| SPA tests                      | `CreatorAssignmentDetailPage.spec.ts`, `ReviewDraftDrawer.spec.ts`, `DraftsTab.spec.ts`, `BoardCardDrawer.spec.ts`, `templates.spec.ts`, parity specs |
| Untouched, and proven so in S6 | state machine, review controller, draft controller, both draft resources, migrations, `Modules/Boards`, `packages/api-client`                         |

**No** migration, **no** new route, **no** new gate or ability, **no** new
`NotificationType` / `AuditAction` / `LIVE_TYPES` entry, **no** new flag, **no**
scheduled job, **no** api-client change.

---

## 8. What this plan does not do

- Not a round cap and not a cap UI (D5 / Q3 of the inventory). The clause-2.4
  product note goes in the review file; engineering review is not legal review
  (AH-029).
- Not the drawer timeline's latest-only limitation (inventory Q4) — recorded, one
  line.
- Not the cancel-mid-cycle open round (inventory Q5) — latent, no caller;
  recorded. The proposed neutral `Draft {n} — submitted` copy means the UI would
  not lie if it ships, but no behaviour is added.
- Not chunk B (the posting toggle). No `campaigns` column, no state-machine edge,
  no board-column work.
- Not the §5.3 gap for mailables this chunk does not touch — `tech-debt.md` entry
  instead.

---

_Provenance: drafted by Cursor at plan-pause for Draft Workflow v2 chunk A
(`WORKING-PROCESS.md` §2 Mode A step 3). No code written. Awaiting Claude's
clearance; Q1 and Q2 are blocking._
