# Draft Workflow v2, chunk A — numbered visible draft rounds (AH-068)

- **Status:** Built, gated, **awaiting independent review**. Two commits: `36fa454` —
  `feat(drafts): number the review rounds and say so on every surface (AH-068)` — and the docs
  commit carrying this file, the AH-068 log entry, two `tech-debt.md` entries and the
  `RESUMPTION-TEMPLATE.md` refresh. **Push held** — Pedram's call.
- **Date:** 2026-08-16
- **Provenance:** built by Cursor against the ratified plan and Claude's Q1–Q8 rulings.
- **Ratified plan:** [`draft-rounds-plan.md`](draft-rounds-plan.md) (committed at plan-pause,
  `f9cc280`). **Reads from:** [`draft-workflow-v2-inventory.md`](draft-workflow-v2-inventory.md)
  §§0.1, 2, 3, 4 — cited, not re-derived.
  **Binds to:** [`campaign-drafts-tab-review.md`](campaign-drafts-tab-review.md) (the Drafts tab this
  chunk re-labels) and `sprint-9-chunk-2-review.md` (the creator/agency namespace split this chunk
  is explicitly forbidden from reversing — see D2 below).
- **Chunk base:** `f9cc280` — `docs(reviews): plan-pause for Draft Workflow v2 chunk A (numbered rounds, AH-068)`.
- **Gate board:** backend **2438 passed / 1 skipped** (9077 assertions), PHPStan level max **0
  errors** across 910 files, Pint clean; `apps/main` **1485 passed** / 152 files, `apps/admin`
  **449** / 53, api-client **204** / 9; typechecks clean, ESLint 0 errors (the same 2 pre-existing
  `v-html` warnings); every parity spec green; **full Playwright 27/27 effective**, two projects
  (26 first-pass + the documented 2FA cold-start flake, green on isolated re-run).
  Full table in [Gate board](#gate-board--full-at-final-code-head).
- **Two mutations executed and reverted**, byte-for-byte restore proven by SHA-256. Table in
  [Break-reverts](#break-reverts--two-mutations-verbatim).
- **§5.40 risk: LOW**, and it stayed LOW because Q1 was ruled to (a). The mechanism that keeps it
  there is pinned by a test, not by prose — see
  [The Q1(a) historical-row proof](#the-q1a-historical-row-proof).

---

## What shipped

The review cycle has always kept every round. `campaign_drafts` inserts one row per submission with
`version = max + 1` and closes it in place with `review_feedback` + `reviewed_at`. What it never did
was **say so**: the UI called rounds "Version 3" or "Draft v3" in one place and "Pending review" in
the next, the creator could not see the feedback that had already been delivered to their browser,
and no notification or email said which round it was about.

This chunk changes what the cycle **says** and nothing about what it **does**.

- **One vocabulary, "Draft {n}", on all five surfaces (D1/D2).** Creator detail history, the agency
  Drafts tab, the review drawer's heading and its history rows, and the board card drawer's draft
  chip all now read `Draft 2 — changes requested` from the same five-key set, resolved through one
  shared helper. The retired "Version {n}" / "Draft v{n}" keys are gone from all 24 locales, not
  deprecated in place.
- **The creator can finally read their own review trail (D3).** Each round in the creator's history
  now shows the agency's feedback and the timestamp it was reviewed. **Zero backend change** — the
  read pass confirmed both fields were already on the wire and simply never rendered.
- **The cycle's notifications and emails carry the round (D4).** In-app rows gain an additive
  `version` data key; the two mails gain a round clause in subject and body. Both read the number
  off the event context the domain already emits — **no new query, no new type, no new verb**.
- **The two draft mailables gained real render tests (D4/Q8).** They shipped in Sprint 9 with queue
  assertions only. Closing that §5.3 gap is the largest single piece of work in the chunk.
- **D6's zero-behaviour claim is evidenced twice** — by command output on the behaviour-bearing
  paths, and by an executable parity test that drives the whole cycle through the real endpoints.

**No migration. No schema change. No new column, index, route, gate, `NotificationType`,
`AuditAction`, `LIVE_TYPES` entry, feature flag, scheduled job or api-client type.** The only new
runtime write anywhere is one additive key inside the `data` bag of newly-created notification rows.

### Commit split, and why

Two commits, the house pair:

| Commit    | Contents                                                                                                        |
| --------- | --------------------------------------------------------------------------------------------------------------- |
| `36fa454` | S1–S6 — the keyset surgery, the five surfaces, the shared helper, the listener + mail round, and all test work. |
| _docs_    | S7 — this file, the AH-068 log entry, the tech-debt entries, `RESUMPTION-TEMPLATE.md` Part 2.                   |

The code did **not** split further. S1's keyset rename and S1's call-site repointing are the same
commit by necessity — split apart, both halves are red, because `i18n-locale-parity.spec.ts` fails
an orphaned key and `vue-tsc`/the component specs fail a missing one. The rest (S2–S6) rides with it
because every one of them is a consumer of the vocabulary S1 establishes, and a reviewer reading the
code commit wants the vocabulary and its five surfaces in one view.

---

## Per-decision evidence

### D1 — the round number is `campaign_drafts.version`, and nothing else

No storage was added, because none was needed. The plan's §1.1 confirmed the inventory's finding and
this chunk consumed it unchanged.

Pinned structurally rather than asserted, so a future counter column cannot be added quietly:

```164:173:apps/api/tests/Feature/Modules/Campaigns/DraftRoundCycleParityTest.php
it('the round number is the version column and nothing else — no counter, no second source of truth', function (): void {
    // D1 pinned structurally: whatever the surfaces call a "round", the only
    // number behind it is `campaign_drafts.version`. A counter column added later
    // would make this list longer and red the test, which is the intent.
    $columns = array_keys(CampaignDraft::factory()->makeOne()->getAttributes());

    expect($columns)->toContain('version')
        ->and($columns)->not->toContain('round')
        ->and($columns)->not->toContain('round_number');
});
```

The `version` column, its `unique (assignment_id, version)` and both draft resources are in the
[zero-diff set](#zero-diff-proofs).

### D2 — one vocabulary, and the two things it deliberately did not do

**What users read is unified.** The English source of truth, and the shape all 24 locales carry:

| Round state                                     | Key                                              | Copy                            |
| ----------------------------------------------- | ------------------------------------------------ | ------------------------------- |
| `pending`, assignment `draft_submitted`         | `app.campaigns.review.roundState.awaitingReview` | `Draft {n} — awaiting review`   |
| `revision_requested`                            | `…roundState.changesRequested`                   | `Draft {n} — changes requested` |
| `approved`                                      | `…roundState.approved`                           | `Draft {n} — approved`          |
| `rejected`                                      | `…roundState.notAccepted`                        | `Draft {n} — not accepted`      |
| `pending`, assignment **not** `draft_submitted` | `…roundState.submitted`                          | `Draft {n} — submitted`         |

The fifth row is the latent open-round case (inventory §4.2 — a cancel mid-cycle leaves a `pending`
round that is not awaiting anything). It has no caller today. It exists because the UI must not be
able to lie if a cancel path ever ships, and it costs one key.

**The resolution lives in exactly one place**, so five surfaces cannot drift:

```50:64:apps/main/src/modules/campaigns/draftRounds.ts
export function roundState(
  reviewStatus: DraftReviewStatus,
  assignmentStatus?: AssignmentStatus | null,
): RoundState {
  switch (reviewStatus) {
    case 'approved':
      return 'approved'
    case 'rejected':
      return 'notAccepted'
    case 'revision_requested':
      return 'changesRequested'
    case 'pending':
      return assignmentStatus === 'draft_submitted' ? 'awaitingReview' : 'submitted'
  }
}
```

**Five keys, not concatenation — and `hu` is why.** Q3 ruled one composite key per round state with
an `{n}` param, rather than composing `label + ' — ' + status` in the template. The shipped
Hungarian is the argument made concrete: `{n}. vázlat — ellenőrzésre vár`. The number precedes the
noun and takes an ordinal period. Any template-side composition would have hard-coded English
constituent order into all 24 locales and would have made the placeholder-parity gate's `{n}` check
meaningless, because `{n}` would live in a fragment the composed string never had to preserve. **Do
not "simplify" this back to concatenation.**

**Retired keys are deleted, not deprecated.** Gone from all 24 files:
`app.campaigns.review.draftVersion` (renamed to `draftRound`),
`creator.ui.assignments.detail.history.version`, and the whole
`creator.ui.assignments.detail.reviewStatus.*` block (4 keys), which had no remaining consumer once
the creator's rows moved to the composite form. `app.campaigns.review.history` kept its key and
re-copied its value from "Version history" to "Draft history", so both sides now name the same block
identically. Grep proof in [Orphan cleanup](#orphan-cleanup-gate-backed-not-prose-backed).

**Two things D2 deliberately did not do:**

1. **The namespace split stands (Q6).** `creator.*` and `app.campaigns.review.*` remain separate key
   paths. D2's "one vocabulary" was reinterpreted per §5.32 as _what users read_, not _key
   topology_: Sprint 9 Chunk 2 moved the agency drawer off the creator namespace because an agency
   component reading a creator key caused a real test-harness key-miss
   (`sprint-9-chunk-2-review.md:130`). Unifying the paths would silently reverse a decision made to
   fix a live failure. The copy is unified; the paths are not. The same reasoning drove the new
   `notifications.center.round` leaf rather than reading `app.campaigns.review.draftRound` from the
   notification centre — that component's spec mounts with the notifications namespace alone.
2. **`app.campaigns.assignmentStatus.*` is untouched (Q7).** That is the 16-status **assignment**
   vocabulary with six dynamic consumers, not the round vocabulary. Renaming inside it would fan out
   to every status chip in both SPAs.

**One divergence found and closed during the build, worth naming because it was mine.** The Drafts
tab's filter dropdown reads four bare `draftStatus.*` labels — a filter option cannot carry a round
number, so those keys survive Q6 correctly. But after S3 the filter said "Rejected" while the rows it
filtered said "not accepted", which is exactly the split D2 exists to close. The filter labels were
re-copied to the round-state register, each derived from **that locale's own** round-state clause so
grammatical agreement is preserved rather than translated twice: `rejected` in all 24 (`Rejected` →
`Not accepted`, `Abgelehnt` → `Nicht angenommen`) and `pending` in the 4 where it still diverged
(`Pending review` → `Awaiting review`, `Ausstehende Prüfung` → `Prüfung ausstehend`).
`draftStatus.approved` already matched everywhere.

### D3 — the creator's history rows, render-only

Confirmed at read pass and re-confirmed here: `CampaignDraftResource` already emits `review_feedback`
and `reviewed_at`, and the creator's own endpoint serialises through that exact resource. **The
backend diff for D3 is empty** — the resource is in the zero-diff set below.

The creator's rows now render, per round: the composite round label, the agency's feedback verbatim
when the round carried one, and `Reviewed {date}`.

**A round closed without a note renders only the timestamp**, and the block is omitted rather than
filled. That is a deliberate departure from the plan, which proposed reusing
`creator.ui.assignments.detail.revision.noFeedback` as an empty state: on the revision banner that
copy answers "why was this sent back", a question the creator is owed an answer to. In a **history
list** it would put a sentence in the agency's mouth that the agency never said, for every silently
approved round — the AH-043 line. The absence is the honest render, and it is asserted as an absence
rather than left untested.

Pinned with the 3-round fixture review priority 3 asked for (v3 `pending`, v2 `revision_requested` +
its own note, v1 `revision_requested` + a different note) — each round asserts its **own** feedback
and its **own** timestamp, and v1 asserts it does **not** contain v2's note, which is the assertion
that catches a "render the latest review on every row" bug. Three §5.34 negatives sit beside it: the
open round shows neither feedback nor timestamp; an approved round with no note shows the timestamp
and no invented sentence; and a `pending` round on a cancelled assignment reads `submitted`, not
`awaiting review`.

### D4 — round context, from the contract the domain already emits

**(a) The payload.** `SendAssignmentNotifications` reads `$event->context['version']`. Every one of
the four production paths already puts it there — the submit path sends the row it just wrote, the
three review verbs send the reviewed draft's. **No query was added to either leg**, which also keeps
the submit leg's notifier from loading a draft it has never needed.

**Omit-when-absent (Q2), and the invariant it encodes.** The machine can be driven directly with no
context — its own tests do exactly that. The listener returns `null` and the key is **absent** rather
than `null`, so a consumer can test presence:

```113:122:apps/api/app/Modules/Campaigns/Listeners/SendAssignmentNotifications.php
    private function withRound(array $data, ?int $round): array
    {
        if ($round === null) {
            return $data;
        }

        $data['version'] = $round;

        return $data;
    }
```

The invariant — _a direct machine call cannot invent a round number_ — is pinned by its own test
(`NotificationProofConsumerTest`, "omits the round entirely when the machine is driven with no
context"), sitting beside the positive, with the §5.2 `Event::fake` split preserved.

**Four types carry it, including `draft_rejected` (Q4).** There is no "resubmitted" notification —
resubmission re-fires `assignment.draft_submitted`, the same agency-recipient type the first
submission fires. Round context is what makes that shared type legible, and the mail test pins the
distinction directly ("distinguishes a resubmission from a first submission"). The copy reads
correctly at `n = 1`, where nothing was resubmitted.

**(b) The mail.** Both mailables gained an optional `round`, rendered through one shared trait so the
two mails cannot drift:

```23:45:apps/api/app/Modules/Campaigns/Mail/Concerns/CarriesDraftRound.php
    protected function roundLabel(?int $round): ?string
    {
        if ($round === null) {
            return null;
        }

        return (string) __('campaigns.assignment_notifications.round', ['n' => $round]);
    }

    /** The subject with the round appended, or the subject untouched. */
    protected function withRoundInSubject(string $subject, ?int $round): string
    {
        $label = $this->roundLabel($round);

        if ($label === null) {
            return $subject;
        }

        return (string) __('campaigns.assignment_notifications.round_subject', [
            'subject' => $subject,
            'round' => $label,
        ]);
    }
```

`round_subject` (`:subject (:round)`) is a locale-owned key for the same reason the composites are:
a locale that wants the clause elsewhere, or wants different brackets, can move it without a code
change.

### The Q1(a) historical-row proof

This is the decision that kept the risk line at LOW, so it gets its own evidence.

The in-app body renders client-side by spreading a row's stored `data` bag as vue-i18n named params.
Every notification row already in production was written **without** a `version`. Adding `{version}`
to the four review templates — D4 read literally, and option (c) — would have left a hole in the body
of every one of them, and would have falsified the invariant `bodyText`'s own comment asserts.

Q1 was ruled to (a): the round renders as its **own** element, only when present, following the
`detailText()` pattern the component already uses for optional data.

The proof is a fixture in the pre-AH-068 shape, asserted **byte-identical** against the
round-carrying row's body:

```425:442:apps/main/src/modules/notifications/components/NotificationCenter.spec.ts
  it('a pre-AH-068 row renders byte-identically, with no round element at all', async () => {
    const historical = await mountRow(HISTORICAL_ROW)
    const historicalBody = historical.find('[data-test="notification-body"]').text()

    expect(historical.find('[data-test="notification-round"]').exists()).toBe(false)
    // No hole, no literal placeholder, no stray punctuation.
    expect(historicalBody).toBe('Revisions were requested on your draft for Spring Launch.')
    expect(historicalBody).not.toContain('{')
    // The feedback detail line is untouched too.
    expect(historical.find('[data-test="notification-detail"]').text()).toContain(
      'Brighten the lighting.',
    )
    historical.unmount()

    const withRound = await mountRow({ ...HISTORICAL_ROW, version: 2 })
    expect(withRound.find('[data-test="notification-body"]').text()).toBe(historicalBody)
    withRound.unmount()
  })
```

Three further pins sit beside it: the round renders on a version-carrying row; a non-numeric
`version` is ignored rather than rendered as a broken label; and the agency fan-out leg — the one
verb serving both first-submit and resubmit — carries the round too.

`templates.spec.ts` pins the same claim from the other end: **no round-bearing body template
interpolates `{version}`**. That is the assertion that stops a future edit from quietly moving the
round into the body and reintroducing the hole.

### D5 — scope, as narrowed by evidence

| D5 surface              | Disposition                                                                                                                                                |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Creator detail history  | **In scope, done.**                                                                                                                                        |
| Agency Drafts tab rows  | **In scope, done** — two chips consolidated into one composite.                                                                                            |
| Drawer history blocks   | **In scope, done** — both the heading and the history rows.                                                                                                |
| Board card's draft chip | **In scope as the drawer row** (Q5). `BoardCard.vue` — the card **face** — contains no draft or version reference at all; a face chip would be net-new UI. |
| AH-061 hand-off row     | **Out of scope by evidence.** It does not name drafts: its Review button reuses `app.campaigns.review.action` and its row label comes from the status map. |
| Drawer timeline         | Out of scope. Recorded below.                                                                                                                              |
| Cancel-mid-cycle        | Out of scope. The neutral `submitted` copy means the UI would not lie if it ships; no behaviour was added.                                                 |
| Round cap / cap UI      | Out of scope. Recorded below.                                                                                                                              |

**A round chip on the board card face is a cheap future add** if Catalyst asks for it — the helper
and the five keys already exist, so it is a template change and nothing else. It is recorded here as
a product option, not a backlog item, because adding it is a UI decision rather than a rename.

### D6 — no behaviour changed, and here is why you do not have to take that on trust

Two artefacts, covering different failure modes. Both in the sections below:
[Zero-diff proofs](#zero-diff-proofs) for the paths, and the cycle parity test for the listener a
path-diff cannot see.

`DraftRoundCycleParityTest` drives submit → request changes → resubmit → approve through the **real
endpoints** as the real actors, then asserts what the cycle left behind: two rows with versions
`[1, 2]`, each carrying its **own** closing review (round 1 `revision_requested` with its feedback,
round 2 `approved` with none invented for it), each with its own caption, the assignment walking
`draft_submitted → revision_requested → draft_submitted → approved`, and the audit trail carrying
exactly those four rows in that order — `draft_submitted` twice, because a resubmission is the same
verb, which is the fact the round number exists to disambiguate.

It is deliberately one long assertion rather than one test per step: the claim being defended is
about the cycle as a unit.

---

## Break-reverts — two mutations, verbatim

Restores are proven by SHA-256, not by "I put it back".

### Mutation 1 — the D4 payload key, removed

`withRound()` reduced to `return $data;` — the round computed, then dropped.

```
⨯ it the review payload carries the round, read off the context the c… 0.08s
✓ it omits the round entirely when the machine is driven with no cont… 0.05s
  Failed asserting that null is identical to 2.
  148▕     // …and the same round reaches the inbox.
  149▕     Mail::assertQueued(DraftReviewedMail::class, fn (DraftReviewedMail $m): bool => $m->round === 2);
Tests:    1 failed, 5 passed (22 assertions)
```

Two things worth reading in that output. It reds on the **in-app payload** assertion —
`expect($notification?->data['version'] ?? null)->toBe(2)`, the `null is identical to 2` line — and
never reaches the mailable assertion two lines below it; both halves of the claim live in the one
test deliberately, and the mutation only had to break the first. And the **negative stays green**,
correctly: a test that asserts the key is absent cannot detect a mutation that makes it always
absent. That is exactly why both exist, and why the pair is the §5.34 set rather than either alone.

Restore: `b87b07b079a6db21e243d20e82bf29604662c3d4ebec779eccee2e8aa2abed7f` — identical to the
pre-mutation checksum. Re-green: **6 passed (23 assertions)**.

### Mutation 2 — the state machine's revision target, changed

`requestRevision()` committed to `AssignmentStatus::Producing` instead of `RevisionRequested` — a
behaviour change of exactly the class D6 forbids, made in a file this chunk does not touch.

```
⨯ it a full submit → changes → resubmit → approve cycle produces exac… 0.61s
✓ it the round number is the version column and nothing else — no cou… 0.04s
  Failed asserting that two variables reference the same object.
  ➜  96▕     expect($assignment->fresh()?->status)->toBe(AssignmentStatus::RevisionRequested);
Tests:    1 failed, 1 passed (9 assertions)
```

Restore: `cd00f7e8ba8d77dd3a4608880eeb619e1404e9bcf17b30c21405d3266e3d7dbe`, and
`git diff --quiet` on the file returns clean. Re-green: **2 passed (26 assertions)**.

---

## Zero-diff proofs

By command output, at final code HEAD against the chunk base `f9cc280`. Every path below is
**byte-identical**, working tree included:

```
$ for p in <paths>; do git diff --quiet HEAD -- "$p" && [ -z "$(git status --porcelain -- "$p")" ] \
      && echo "ZERO-DIFF  $p" || echo "CHANGED    $p"; done

ZERO-DIFF  apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php
ZERO-DIFF  apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentReviewController.php
ZERO-DIFF  apps/api/app/Modules/Creators/Http/Controllers/CreatorAssignmentDraftController.php
ZERO-DIFF  apps/api/app/Modules/Campaigns/Http/Resources/CampaignDraftResource.php
ZERO-DIFF  apps/api/app/Modules/Campaigns/Http/Resources/CampaignDraftListItemResource.php
ZERO-DIFF  apps/api/app/Modules/Campaigns/Enums
ZERO-DIFF  apps/api/app/Modules/Notifications
ZERO-DIFF  apps/api/app/Modules/Audit
ZERO-DIFF  apps/api/database/migrations
ZERO-DIFF  apps/api/routes
ZERO-DIFF  apps/api/app/Modules/Boards
ZERO-DIFF  packages/api-client
ZERO-DIFF  apps/admin
```

So: the state machine, both controllers, both draft resources, every `Campaigns` enum, the whole
`Notifications` and `Audit` modules, every migration, every route file, the whole Boards tree, the
shared client and the admin SPA are untouched. **No transition, no gate, no validation rule, no
resource shape, no audit verb, no notification type and no board automation was altered.** The
AH-044 empty-draft rule and the review endpoints are covered by the controller lines above.

**Scoped honestly:** `SendAssignmentNotifications.php` and the two mailables are deliberately **not**
in this set — S4/S5 touch them. Their narrower claim is the parity test's job, not this list's.

And the whole of what did change on the backend, for contrast — three production files plus one new
trait:

```
$ git diff --stat -- apps/api/app
 .../Listeners/SendAssignmentNotifications.php      | 63 ++++++++++++++++++----
 .../Modules/Campaigns/Mail/DraftReviewedMail.php   | 10 +++-
 .../Campaigns/Mail/DraftSubmittedForReviewMail.php | 14 ++++-
 3 files changed, 75 insertions(+), 12 deletions(-)

(+ one untracked file: app/Modules/Campaigns/Mail/Concerns/CarriesDraftRound.php)
```

---

## Orphan cleanup — gate-backed, not prose-backed

Review priority 2 asked for the rename's cleanup. Two gates enforce it by construction:
`i18n-locale-parity.spec.ts` flags both `MISSING` and `EXTRA` keys per locale against the `en` source
of truth, **and** asserts placeholder-token equality with `en`. A rename that leaves an orphan in one
of 24 files, or drops `{n}` in one locale, reds the suite. It is green.

The grep, for the reader who wants it directly:

```
draftVersion                           0 file(s)
history.version                        0 file(s)
ui.assignments.detail.reviewStatus     0 file(s)
```

The only surviving occurrences of the old vocabulary anywhere in `apps/*/src` are two **negative
assertions** in `ReviewDraftDrawer.spec.ts` — `expect(history.text()).not.toContain('Version
history')` and a comment recording that "the agency's old `Draft v{n}` is retired on both sides".
Those are the tests that keep it retired. One stale `<!-- Version history -->` template comment was
also found and corrected during the build.

---

## Locale parity and the copy counts

| Namespace                                         | New leaves | Retired | Renamed | Locales | Net total                |
| ------------------------------------------------- | ---------- | ------- | ------- | ------- | ------------------------ |
| `app.json` (`roundState.*`)                       | 5          | —       | 1       | 24      | 120 new                  |
| `creator.json` (`history.reviewedAt`)             | 1          | 5       | —       | 24      | 24 new, 120 retired      |
| `notifications.json` (`center.round`)             | 1          | —       | —       | 24      | 24 new                   |
| `lang/*/campaigns.php` (`round`, `round_subject`) | 2          | —       | —       | 24      | 48 new                   |
| **Total**                                         | **9**      | **5**   | **1**   | 24      | **216 new, 120 retired** |

Four leaves also had their **values** re-copied without changing key:
`app.campaigns.review.history` ("Version history" → "Draft history") and the three
`draftStatus.*` filter labels whose wording differed from their own round-state clause —
`rejected` ("Rejected" → "Not accepted"), `pending` ("Pending review" → "Awaiting review", 4 locales;
the other 20 already matched) and `revision_requested` ("Revision requested" → "Changes requested").
`draftStatus.approved` already matched everywhere.

### The five-key composite set — all 24, flaky-10 audited

Read out of the shipped files. `*` marks the flaky 10 (`bg el et fi ga hu lt lv mt ro`), where MT
baselines have gone missing before (AH-028/046/047). **Every one carries real translated copy; no
English fallback anywhere, and `{n}` is present in all 120.**

| Locale | `draftRound`   | `awaitingReview`                          | `notAccepted`                   |
| ------ | -------------- | ----------------------------------------- | ------------------------------- |
| `bg`\* | Чернова {n}    | Чернова {n} — очаква преглед              | Чернова {n} — не е приета       |
| `cs`   | Návrh {n}      | Návrh {n} — čeká na posouzení             | Návrh {n} — nepřijato           |
| `da`   | Udkast {n}     | Udkast {n} — afventer gennemgang          | Udkast {n} — ikke accepteret    |
| `de`   | Entwurf {n}    | Entwurf {n} — Prüfung ausstehend          | Entwurf {n} — nicht angenommen  |
| `el`\* | Πρόχειρο {n}   | Πρόχειρο {n} — αναμένει έλεγχο            | Πρόχειρο {n} — δεν εγκρίθηκε    |
| `en`   | Draft {n}      | Draft {n} — awaiting review               | Draft {n} — not accepted        |
| `es`   | Borrador {n}   | Borrador {n} — pendiente de revisión      | Borrador {n} — no aceptado      |
| `et`\* | Mustand {n}    | Mustand {n} — ootab ülevaatamist          | Mustand {n} — ei võetud vastu   |
| `fi`\* | Luonnos {n}    | Luonnos {n} — odottaa arviointia          | Luonnos {n} — ei hyväksytty     |
| `fr`   | Brouillon {n}  | Brouillon {n} — en attente d'examen       | Brouillon {n} — non accepté     |
| `ga`\* | Dréacht {n}    | Dréacht {n} — ag fanacht ar athbhreithniú | Dréacht {n} — níor glacadh leis |
| `hr`   | Nacrt {n}      | Nacrt {n} — čeka pregled                  | Nacrt {n} — nije prihvaćen      |
| `hu`\* | {n}. vázlat    | {n}. vázlat — ellenőrzésre vár            | {n}. vázlat — nem elfogadva     |
| `it`   | Bozza {n}      | Bozza {n} — in attesa di revisione        | Bozza {n} — non accettata       |
| `lt`\* | Juodraštis {n} | Juodraštis {n} — laukiama peržiūros       | Juodraštis {n} — nepriimtas     |
| `lv`\* | Melnraksts {n} | Melnraksts {n} — gaida pārskatīšanu       | Melnraksts {n} — nav pieņemts   |
| `mt`\* | Abbozz {n}     | Abbozz {n} — qed jistenna reviżjoni       | Abbozz {n} — mhux aċċettat      |
| `nl`   | Concept {n}    | Concept {n} — wacht op beoordeling        | Concept {n} — niet geaccepteerd |
| `pl`   | Szkic {n}      | Szkic {n} — oczekuje na recenzję          | Szkic {n} — nieprzyjęty         |
| `pt`   | Rascunho {n}   | Rascunho {n} — aguardando revisão         | Rascunho {n} — não aceito       |
| `ro`\* | Ciorna {n}     | Ciorna {n} — în așteptarea revizuirii     | Ciorna {n} — neacceptată        |
| `sk`   | Návrh {n}      | Návrh {n} — čaká na posúdenie             | Návrh {n} — neprijaté           |
| `sl`   | Osnutek {n}    | Osnutek {n} — čaka na pregled             | Osnutek {n} — ni sprejeto       |
| `sv`   | Utkast {n}     | Utkast {n} — väntar på granskning         | Utkast {n} — inte godkänt       |

`hr`/`sk`/`sl` were checked by hand as well as by gate: Czech text bled into their SPA draft keys
once (AH-046's class), and their round copy is genuinely Croatian, Slovak and Slovenian here — three
distinct words for "draft" (`Nacrt`, `Návrh`, `Osnutek`), with `hr` and `sk` differing in their
review clause. Their mail round label is pinned per-locale by the mail test's 23-locale loop for the
same reason.

The remaining two composite keys (`changesRequested`, `approved`) and the backend `round` clause
follow the same pattern in all 24; verified programmatically (`24 / 24 locales OK` on both sides, all
carrying `{n}` / `:n`) rather than reproduced here in full.

---

## Gate board — full, at final code HEAD

| Gate                                           | Result                                                            |
| ---------------------------------------------- | ----------------------------------------------------------------- |
| `pest` (apps/api, full, serial at 2G)          | **2438 passed, 1 skipped** (9077 assertions), 132.0s              |
| `phpstan` (level max, apps/api)                | **0 errors**, 910 files                                           |
| `pint --test` (run outside the sandbox, §5.18) | **passed**                                                        |
| `vitest` (apps/main, full)                     | **1485 passed** / 152 files                                       |
| `vitest` (apps/admin, full)                    | **449 passed** / 53 files                                         |
| `vitest` (packages/api-client)                 | **204 passed** / 9 files                                          |
| `vue-tsc --noEmit` (apps/main)                 | **clean**                                                         |
| `eslint` (apps/main)                           | **0 errors** (the same 2 pre-existing `v-html` warnings)          |
| `prettier --check` (all 24 `app.json`)         | **clean**                                                         |
| `i18n-locale-parity.spec.ts`                   | **green** — 4 tests, 24 locales, every namespace                  |
| `i18n-notifications-parity.spec.ts`            | **green** — 10 tests, live types unchanged (this chunk adds none) |
| `templates.spec.ts`                            | **green** — 15 tests (+5)                                         |
| **Playwright (apps/main, full suite)**         | **27/27 effective** in 6.1m, two projects — see the note below    |

Backend moved **+45 tests** (+ the two new mail files and the parity file, + 3 on
`NotificationProofConsumerTest`); `apps/main` moved **1457 → 1485** (+28 tests, +1 file).
`apps/admin` and `packages/api-client` are unchanged at 449 / 204, consistent with their zero diff.

### Playwright — claimed as gate, not as coverage

**No Playwright spec asserts any string this chunk renames, and no spec traverses the draft cycle.**
A grep across `apps/main/playwright` for
`Draft history|Version history|Draft v|Version {n}|draft-history|review-history|board-card-drawer-draft`
returns zero hits; the only spec mentioning "draft" is `jobs-board-full-lifecycle.spec.ts`, in the
comment explaining why it stops _before_ the draft cycle. So the suite is **not** evidence about this
work, and this file does not claim it is. It ran because "surely it still passes" has been wrong
twice (`WORKING-PROCESS.md:127-129`).

**Procedure, and three honest notes.** The run needs `TEST_HELPERS_TOKEN` exported and the local
Postgres/Redis containers up. **(1)** The first attempt failed at `global-setup` with the Docker
daemon down — an environment state, not a code signal. **(2)** With the containers up, all 27 specs
then failed identically on **missing Playwright browser binaries**;
`npx playwright install chromium` fixed it and that run was **27/27 first-pass in 5.3m, zero
retries**. **(3)** The suite was run **again at the final tree**, after the `draftStatus` filter
labels were re-copied, and on that pass **spec #19 (`2fa-enrollment-and-sign-in`) went red** — the
documented cold-start flake class, red once in AH-066's close and in AH-064's before it. It is
**green on isolated re-run** (21.0s), and it shares no code, route or i18n key with anything this
chunk touches.

```
$ npx playwright test                 # final tree, full suite
  1 failed
    [chromium] › playwright/specs/2fa-enrollment-and-sign-in.spec.ts:93:3 › spec #19 …
  26 passed (6.1m)

$ npx playwright test playwright/specs/2fa-enrollment-and-sign-in.spec.ts
  ✓  1 [chromium] › … › full enrollment + re-sign-in flow (21.0s)
  1 passed (30.4s)
```

So the honest number for the final tree is **27/27 effective** (26 first-pass + 1 on isolated
re-run); the clean 27/27 first-pass was the run taken before the filter-label re-copy, on a tree
otherwise identical. **No spec's assertions were altered to reach green**, and the flake is recorded
rather than quietly re-run into the headline.

### New and changed tests, by file

| File                                                  | Tests                                                                              |
| ----------------------------------------------------- | ---------------------------------------------------------------------------------- |
| `DraftReviewedMailTest.php` (**new, §5.3**)           | **21** (174 assertions)                                                            |
| `DraftSubmittedForReviewMailTest.php` (**new, §5.3**) | **19** (81 assertions)                                                             |
| `DraftRoundCycleParityTest.php` (**new, D6**)         | **2** (26 assertions)                                                              |
| `NotificationProofConsumerTest.php` (extended)        | 6 total (+3: the payload round, the absent-context negative, queued locale)        |
| `draftRounds.spec.ts` (**new**)                       | **7**                                                                              |
| `CreatorAssignmentDetailPage.spec.ts` (extended)      | 35 total (+6: the 3-round fixture, per-round feedback + `reviewedAt`, 3 negatives) |
| `NotificationCenter.spec.ts` (extended)               | 24 total (+4: the Q1(a) set)                                                       |
| `templates.spec.ts` (extended)                        | 15 total (+5: the `version` data-key pin, §5.25 / AH-058 Q8 precedent)             |
| `BoardCardDrawer.spec.ts` (extended)                  | 26 total (+2)                                                                      |
| `DraftsTab.spec.ts` (extended)                        | 10 total (+2)                                                                      |
| `ReviewDraftDrawer.spec.ts` (extended)                | 9 total (+2)                                                                       |

**The two §5.3 files close a real gap, not a formality.** Before them, `DraftReviewedMail` and
`DraftSubmittedForReviewMail` appeared in three specs and every appearance was
`Mail::assertQueued(...)`, which renders nothing: a broken Blade conditional or a missing locale
value would have sailed past all of them and reached a creator as a stack trace. The new files render
subject **and** body, across six locales plus the flaky 10 plus a 23-locale round-label loop, assert
the three outcomes produce three genuinely different bodies, assert the no-round shape reads as a
complete message with no empty parentheses, and pin the emitted deep link
(`/creator/assignments/{ulid}`) — **which was pinned nowhere before this chunk**.

---

## Review priorities — where each was discharged

| #   | Priority                             | Discharged                                                                                                                                                                             |
| --- | ------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | D6's zero-behaviour evidence         | 13 paths zero-diff by command output, **plus** the executable cycle parity test. Mutation 2 proves the parity test is load-bearing.                                                    |
| 2   | D2's rename cleanup                  | Both parity gates enforce it by construction; grep shows 0 orphans; the only survivors are two negative assertions.                                                                    |
| 3   | D3's rendering                       | The 3-round fixture — each round's own feedback, own timestamp, no leak between rounds, and three negatives incl. the feedback-less round rendering an absence, not a filler sentence. |
| 4   | D4's payload key pinned              | `templates.spec.ts` gains the `version` pin (AH-058 Q8 precedent) + the "no body template interpolates `{version}`" invariant. Mutation 1.                                             |
| 5   | flaky-10 audit                       | Table above, read out of the shipped files; 24/24 programmatic check both sides; `hr`/`sk`/`sl` hand-checked for AH-046's class.                                                       |
| 6   | full Playwright for the drafts cycle | **27/27 effective** (26 first-pass + the documented 2FA flake, green isolated), and the exposure stated honestly: no spec touches this work. Claimed as gate, not coverage.            |

---

## Production posture (§5.40, restated at final code HEAD)

**PROD-DATA RISK: LOW.** As re-derived at plan-pause, and it stayed there because Q1 was ruled to (a).

**No migration, no schema change, no column, no index.** D1 is satisfied entirely by storage that
shipped in Sprint 9 Chunk 1. `down()` honesty is trivially satisfied: there is nothing to reverse.

**No production row is read for mutation and none is rewritten.** No backfill, no one-shot command,
no scheduled job. Nothing joins the deploy-obligations tracker.

**No API field, no resource shape change, no api-client type.** D3's fields were already on the wire.

**The only new runtime write is one additive key in the `data` bag of newly-created notification
rows.** Additive, forward-only, rewrites nothing.

**The one live-data consequence was designed around rather than accepted.** Existing `notifications`
rows carry a `data` bag frozen at emit time and none of them has a `version`. The Q1(a) mechanism
means those rows render **byte-identically** — proven by the fixture test above, not asserted — and
the `bodyText` invariant stays true. This was the single thing in the chunk that could have reached a
real user as a visible bug, and it does not.

**Mail copy changed, so the queue-worker restart obligation applies** on deploy: the two draft
mailables are localized at queue time and rendered from `lang/*/campaigns.php`, which this chunk
edits in all 24. A worker holding old code would render old copy — never a broken message, since the
round parameter is optional and absent-safe, but stale.

**At T+0 the visible change is copy on surfaces that already existed.** No population becomes newly
reachable, no fan-out becomes newly triggerable, no operator action becomes newly available. The
round number rendered anywhere is a number that was already in the database.

**No `tenancy.md` §4 rows.** This chunk adds no route at all.

---

## What this chunk deliberately did not build

A round cap or any cap UI (the clause-2.4 product note stays a product question; engineering review
is not legal review, AH-029); the drawer timeline's latest-only limitation; the cancel-mid-cycle open
round (latent, no caller — the neutral `submitted` copy means the UI would not lie if it ships, but
no behaviour was added); a round chip on the board card **face** (recorded above as a cheap future
product option); any change to `app.campaigns.assignmentStatus.*`; a new notification type for
resubmission (the shared `draft_submitted` verb plus round context is what disambiguates it); the
§5.3 render gap for mailables this chunk does not touch (tech-debt entry instead); the garbled
English-mixed `impersonation.json` strings the read pass found in nine locales (reported, tech-debt
entry, deliberately not fixed here — a drive-by locale edit in an unrelated namespace would have
muddied this diff); chunk B, the per-campaign posting toggle, in any part; and any deploy.

---

_Provenance: built by Cursor against the ratified plan and Claude's Q1–Q8 rulings. Awaiting
independent review; push held._
