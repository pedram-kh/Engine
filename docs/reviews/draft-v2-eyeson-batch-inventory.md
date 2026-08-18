# Eyes-on fix batch — inventory (Draft Workflow v2 + drive-by)

**Step 1 of close-out. Read-only: no docs pass beyond this file, no push.** Step 2 (review) follows.

- **Base:** `origin/main` at session start, confirmed at kickoff and re-verified now —
  `2cad8ba9` (`docs: cite the final green tip and refresh resumption for AH-070's follow-through`).
  This is the AH-070 follow-through's own close-out commit; the kickoff's "HEAD = origin/main
  (post-AH-070)" claim is confirmed both then and at this inventory: `git fetch origin main` →
  `origin/main` is still `2cad8ba9`, `git rev-list --left-right --count origin/main...HEAD` → `0  16`
  (zero commits on origin not in HEAD, 16 commits on HEAD not on origin). Nothing has been pushed.
- **HEAD:** `3ef5d8ec` (`feat(roster,discover): square the profile photo and open it in a lightbox`).
- **Tree:** clean. `git status --short` at inventory time returns nothing. No finding here.

---

## 1. Commit list + state

`git log --oneline 2cad8ba9..HEAD`, newest first as returned, renumbered oldest→newest below since
that is the order the batch actually happened in:

| #   | SHA        | Subject                                                                                     | One-liner                                                                                                                                                                                                                    |
| --- | ---------- | ------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `5e61795a` | fix(campaigns): stop echoing the assignment's live status onto every historical draft round | The agency Drafts tab put a second, contradicting status chip on every draft row — pre-existing, made visible by AH-068's own readable round text.                                                                           |
| 2   | `088a6540` | fix(campaigns): preserve line breaks and color-code draft rounds by outcome                 | Multi-line feedback collapsed to one line (no `white-space` styling anywhere); round chips were always `primary` regardless of outcome. Added `roundStateColor()`.                                                           |
| 3   | `b132c65e` | fix(campaigns): bold the round title and fix unreadable feedback text on the warning card   | `text-medium-emphasis` on an amber tonal card read as pale-on-pale. Swapped for the audited `on-warning` token; caught first attempt's raw `rgba()` via the hard-coded-color architecture test.                              |
| 4   | `174b4ca3` | fix(creators): bold the round title in the creator profile's draft history                  | Parity follow-through of #3 on the creator's own surface.                                                                                                                                                                    |
| 5   | `7a9a01f1` | feat(boards): add a Drafts tab to the board card drawer                                     | New read-only tab on the board card drawer showing the same round history, reusing existing data + keys. Promoted #3's contrast fix into a shared helper.                                                                    |
| 6   | `49da7aa9` | feat(boards): let the board drawer's Drafts tab fully review a draft                        | Extracted `ReviewDraftDrawer`'s review logic into a shared `DraftReviewPanel`, hosted by both the original drawer and the board drawer's Drafts tab. Removed the now-dead `review`-emit chain.                               |
| 7   | `6134604c` | fix(campaigns): move the Reject/Request changes/Approve row above the feedback field        | Pure reorder, requested layout change.                                                                                                                                                                                       |
| 8   | `0099cbe5` | fix(campaigns): right-align the Reject/Request changes/Approve row                          | Pure alignment change, requested.                                                                                                                                                                                            |
| 9   | `f36ac4b9` | fix(creators): stop promising a counter-offer in the invitations subtitle                   | Stale copy from a July sprint chunk that removed the counter-offer flow. ×24 locales.                                                                                                                                        |
| 10  | `27512a6a` | fix(campaigns): stop telling agencies a creator can counter an offer                        | Same stale-copy class, agency-side dialog. ×24 locales.                                                                                                                                                                      |
| 11  | `1e062896` | fix(pools): show real avatars in the add-creators-to-pool picker                            | Dialog never adopted `avatar_url` after a July batch put it on the roster row type for a different picker.                                                                                                                   |
| 12  | `8a1f3ae4` | feat(roster,discover): keep the creator lists where the operator left them                  | Browse state (page/search/filters) moved into the URL via a new `useListQueryState` composable; back-to-list unwinds history; router grew a content-aware `scrollBehavior`. Drive-by: fixed a null-search crash on Discover. |
| 13  | `e30bb4c9` | fix(messaging): follow the conversation switch in the thread header                         | AH-013's two-pane shell keeps the thread page mounted across conversation switches; the header only ever resolved once, in `onMounted`.                                                                                      |
| 14  | `381b8d97` | fix(messaging): stamp message bubbles with the date, not just the hour                      | Bubbles carried time-only; a multi-day thread needs the date too.                                                                                                                                                            |
| 15  | `676cc802` | feat(roster,discover): show the creator's photo on both detail pages                        | Both detail headers had a signed `avatar_url` on the resource, unrendered.                                                                                                                                                   |
| 16  | `3ef5d8ec` | feat(roster,discover): square the profile photo and open it in a lightbox                   | Circle → rounded-rect (per a hand-drawn reference), click-to-enlarge sharing the portfolio gallery's existing lightbox pattern.                                                                                              |

**Tree clean, all 16 committed, nothing pushed.** No uncommitted-work finding.

---

## 2. Theme grouping

One theme = one AH candidate. Eight themes, `AH-071`–`AH-078` in the order they'd naturally log
(build order above):

### AH-071 (candidate) · Draft-round history: readable feedback, colored by outcome, bold title, honest per-row status

- **Why:** three separate eyes-on findings, all on the same three draft-review surfaces (agency Drafts
  tab, agency Review-draft drawer, creator's own draft history): (a) an already-approved assignment's
  earlier "changes requested" rounds displayed "Approved" too, because a second chip echoed the
  assignment's _current_ status onto every row; (b) multi-line feedback text collapsed to one line —
  no surface applied `white-space: pre-wrap`; (c) every round chip was the same `primary` blue and the
  changes-requested round's feedback was unreadable pale-on-amber.
- **What:** removed the contradicting status chip; added `roundStateColor()` (round outcome →
  Vuetify's existing success/warning/error/info vocabulary, not a new palette) and rendered rounds as
  colored tonal cards; applied `pre-wrap`/`break-word` to every feedback-rendering spot; bolded round
  titles; computed the on-card foreground from the same `on-<color>` design tokens Vuetify's own solid
  surfaces use, shared via a new `roundCardTextStyle()` helper.
- **Touched:** `DraftsTab.vue` (+spec), `ReviewDraftDrawer.vue` (+spec), `CreatorAssignmentDetailPage.vue`,
  `draftRounds.ts` (+spec) — two new pure functions appended, nothing existing changed.
- **Commits:** `5e61795a`, `088a6540`, `b132c65e`, `174b4ca3`.

### AH-072 (candidate) · Board card drawer gets a full-review Drafts tab

- **Why:** the board card drawer could preview a card's draft history but not act on it — approving,
  requesting changes, or rejecting still meant leaving the board for the campaign's own Drafts tab.
- **What:** extracted `ReviewDraftDrawer`'s preview + feedback field + three review actions + reject
  confirmation into a shared `DraftReviewPanel`, hosted both by `ReviewDraftDrawer` (now dialog chrome
  only) and by the board drawer's own Drafts tab (embedded). The Detail tab's "Review" button now
  switches to the drawer's own Drafts tab instead of closing the drawer and handing off to the
  campaign page; the resulting dead `review`-emit chain (`BoardCardDrawer` → `BoardView` →
  `CampaignDetailPage`) was removed. The panel gates its actions on an explicit `canReview` prop since
  the board drawer's Drafts tab has no upstream ability check the way the drawer's own mount condition
  did.
- **Touched:** `BoardCardDrawer.vue` (+spec), `BoardView.vue`, `DraftReviewPanel.vue` (new, +spec),
  `ReviewDraftDrawer.vue` (+spec), `CampaignDetailPage.vue` (+spec),
  `tests/unit/architecture/form-error-pattern.spec.ts` (allowlist swap, see §4).
- **Commits:** `7a9a01f1`, `49da7aa9`.

### AH-073 (candidate) · Review-action row: position + alignment

- **Why/What:** two direct layout requests — actions above the feedback field (act, then the field for
  the act you picked), then right-aligned within their row.
- **Touched:** `DraftReviewPanel.vue`.
- **Commits:** `6134604c`, `0099cbe5`. Pure polish; no bug, no root cause, no pin (DOM reorder only,
  existing `data-test`-keyed specs are order-independent and stayed green unmodified).

### AH-074 (candidate) · Retire two stale counter-offer copy strings

- **Why:** the creator counter-offer flow was dropped by an earlier (July, sprint-numbered, not
  AH-numbered) chunk, but two strings kept promising it: the creator's invitations-list subtitle and
  the agency's accept-application dialog body.
- **What:** rewrote both keys across all 24 locales. Several locales also carried a stale
  half-translation or another language's text entirely and got a proper translation while touched.
- **Touched:** `en/...json` ×2 keys, mirrored in all 24 locale files for `creator.json` (subtitle) and
  `app.json` (accept body) — 48 file edits, one line each.
- **Commits:** `f36ac4b9`, `27512a6a`.

### AH-075 (candidate) · Real creator photo in the add-to-pool picker

- **Why:** the picker rendered initials for every row on the premise that the slim roster row carries
  no avatar — true when it was written, false since a July batch (`eb901dbb`) added `avatar_url` to
  that same row type for a different picker (campaign invites). This dialog just never caught up.
- **What:** render the photo when present, initials as fallback — the identical shape
  `InviteCreatorsDialog` already uses on the same roster row.
- **Touched:** `AddCreatorsToPoolDialog.vue` (+spec).
- **Commits:** `1e062896`.

### AH-076 (candidate) · Browse-state persistence for Roster and Discover

- **Why:** opening a creator from Roster or Discover and coming back reset to page 1 with search and
  filters cleared, because all of it lived in local component refs that unmounted with the list. The
  detail pages' own "Back" made it worse — it pushed a bare `/roster` or `/discover` rather than
  unwinding to wherever the operator actually came from.
- **What:** new `useListQueryState` composable puts page/search/filters in the URL (validated codecs,
  `router.replace` so paging doesn't spam history); new `useBackToList` composable unwinds
  `router.back()` when the previous history entry is the originating list (preserving its full URL +
  scroll), else falls back to a bare push; router gained a `scrollBehavior` that waits for the async
  list's content to actually render before restoring scroll offset (otherwise it lands at the bottom of
  a skeleton). Drive-by: Discover's `clearable` search box writes `null` on clear, which threw inside
  `.trim()` in the load path — now reads through the same guarded computed Roster already used.
- **Touched:** `useListQueryState.ts` (new, +spec), `useBackToList.ts` (new, +spec),
  `core/router/index.ts` (+spec), `DiscoverPage.vue` (+spec), `DiscoverProfilePage.vue`,
  `CreatorRosterPage.vue` (+spec), `CreatorDetailPage.vue`.
- **Commits:** `8a1f3ae4`.

### AH-077 (candidate) · Two-pane messaging: header follows the conversation, bubbles carry the date

- **Why:** two independent findings on the relationship-messaging surface (AH-013's two-pane shell,
  not part of this batch's own Draft Workflow v2 scope): (a) the two-pane shell keeps the thread page
  mounted when the operator clicks a different conversation — only the route param changes — but the
  header resolved the counterparty's name/photo once in `onMounted`, so a fast switch left the _feed_
  correct and the _header_ showing the previous person; (b) message bubbles stamped only the hour, so
  a thread spanning days never said which one.
- **What:** (a) both thread pages (`AgencyRelationshipThreadPage.vue`,
  `CreatorRelationshipThreadPage.vue`) now re-resolve on every param change via `watch`, clearing the
  resolved row first so the header falls back to the `?name=` hint instead of holding a stale face, and
  discard any inbox response that lands after the operator has moved on again; (b) both bubble
  surfaces (`RelationshipThreadView.vue`, `ChatPanel.vue`) switched their `Intl.DateTimeFormat` from
  `{hour, minute}` to `{dateStyle: 'short', timeStyle: 'short'}`, kept on one line via `white-space:
nowrap` so a wrapped stamp can't read as two.
- **Touched:** `AgencyRelationshipThreadPage.vue` (+spec, new file), `CreatorRelationshipThreadPage.vue`,
  `RelationshipThreadView.vue` (+spec), `ChatPanel.vue`.
- **Commits:** `e30bb4c9`, `381b8d97`.

### AH-078 (candidate) · Creator profile photo on both detail pages, squared and previewable

- **Why:** neither the Roster creator-detail header nor the Discover profile header rendered a photo
  at all — name only — despite both resources already carrying a signed `avatar_url` nobody consumed.
  Follow-up ask: square it off (a hand-drawn reference showed a rounded rectangle, not the initial
  circle mask, which was cropping too much of a portrait) and make it click-to-enlarge.
- **What:** first pass rendered a plain `v-avatar` + `v-img`/initial-fallback on both headers. Second
  pass replaced that with one shared `CreatorAvatar` component: `rounded="lg"` thumbnail, click (or
  Enter/Space) opens a `v-dialog` lightbox with a blurred scrim and a floating close button — the same
  pattern the portfolio gallery lower on both pages already uses, so a profile has one preview
  behaviour, not two. Inert (no click affordance at all) when there is no photo.
- **Touched:** `CreatorDetailPage.vue` (+spec), `DiscoverProfilePage.vue` (+spec), `CreatorAvatar.vue`
  (new, +spec).
- **Commits:** `676cc802`, `3ef5d8ec`.

---

## 3. Bug root causes

Six items were bugs (not polish/feature-add). Per item: root cause class, the pin, and whether a
closed review's decision/section missed it.

| Item                                               | Root cause                                                                                                                                                                                                                                                                                                                                                                       | Pin                                                                                                                                                                                                                                                                                                                                                                                    | Closed-review gap?                                                                                                                                                                                                                                                                                    |
| -------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `5e61795a` — double status chip                    | **Pre-existing**, older than AH-068 (traced to `a8c48233`, the tab's original commit). Class: **spec gap** — reproducible in Vitest the whole time; nobody wrote the negative ("a historical round does not also show the assignment's live status"). AH-068 didn't cause it — it made it _visible_, by giving the round chip readable, distinguishable text for the first time. | New test in `DraftsTab.spec.ts`: `a changes-requested round does not also show the assignment's current status`.                                                                                                                                                                                                                                                                       | **No.** The bug predates AH-068's own review (`draft-rounds-review.md`); AH-068 built on top of an already-buggy row, it didn't introduce or overlook the bug in its own decision set. No addendum target.                                                                                            |
| `088a6540` (line-breaks)                           | **Eyes-on-only class.** No surface anywhere in the app had ever applied `white-space: pre-wrap` to this specific field; it isn't a regression, it's a gap that existed since `review_feedback` first shipped as free text.                                                                                                                                                       | Visual/CSS-only; covered by existing render assertions plus the architecture test's general vigilance. No dedicated whitespace-rendering spec exists for jsdom (jsdom doesn't compute CSS white-space collapsing), so this is a **self-reported pin gap** — see §9.                                                                                                                    | N/A — not tied to a specific closed review's decision.                                                                                                                                                                                                                                                |
| `088a6540` (flat chip color)                       | **Eyes-on-only class**, same reasoning — never was color-coded, not a regression from any review.                                                                                                                                                                                                                                                                                | `draftRounds.spec.ts` gained coverage for `roundStateColor()`'s full outcome mapping.                                                                                                                                                                                                                                                                                                  | N/A.                                                                                                                                                                                                                                                                                                  |
| `1e062896` — pool picker missing avatars           | **Stale catch-up.** `eb901dbb` (2026-07-12, sprint-numbered, no independent review file) added `avatar_url` to the roster row type for a _different_ picker (`InviteCreatorsDialog`); `AddCreatorsToPoolDialog` consumes the same row type but was out of that batch's scope and nobody circled back.                                                                            | New test in `AddCreatorsToPoolDialog.spec.ts` asserting the photo renders when present and initials when null.                                                                                                                                                                                                                                                                         | **No closed AH review exists for that batch** — it's a sprint commit, not an AH chunk, so there is no `docs/reviews/*.md` to addend. Named here instead, per the instruction to say which decision missed it when no formal doc exists.                                                               |
| `f36ac4b9` / `27512a6a` — stale counter-offer copy | **Stale catch-up**, same class. `5626ddf7` (2026-07-12, sprint-numbered, "re-offer after decline — drop creator counter") removed the counter-offer flow but the copy audit didn't reach these two strings.                                                                                                                                                                      | **Gap, self-reported**: neither commit added a spec asserting the _literal new sentence_. The existing `i18n-locale-parity.spec.ts` only proves key-set/placeholder/CLDR-plural-form parity across locales — it would not catch a re-introduction of "counter" in the English copy. See §9.                                                                                            | **No closed AH review** — same as above, sprint commit with no independent review file. Named here as the equivalent of an addendum.                                                                                                                                                                  |
| `e30bb4c9` — stale messaging header                | **Eyes-on-only class.** AH-013 (2026-06-29) shipped the two-pane shell that keeps the thread page mounted across a conversation switch; the header's `onMounted`-only resolution was correct for the single-pane predecessor and became stale the moment AH-013 changed the page's lifecycle, but no test ever simulated a _switch_ — only a fresh open.                         | New file `AgencyRelationshipThreadPage.spec.ts`, 4 tests, including one holding the inbox response mid-flight to prove no stale face renders and one proving an out-of-order late response is discarded. **Verified as a real pin**: reverted to the pre-fix `onMounted` code, 2 of the 4 tests fail (`AssertionError: expected 'Nessa' to be 'Dan Richards'`); reapplied, all 4 pass. | AH-013 has an ad-hoc-log entry (`adhoc-changes-log.md` §"AH-013") but **no independent `-review.md` file with a decision/section structure** the way `draft-rounds-review.md` / `draft-posting-toggle-review.md` have. There is no formal addendum target; this table entry is the equivalent record. |
| `8a1f3ae4` (drive-by null-search crash)            | **Eyes-on-only class**, pre-existing since Discover's `clearable` search box was added — nobody had exercised "type, then clear" against the load path's `.trim()`.                                                                                                                                                                                                              | Covered by an existing `DiscoverPage.spec.ts` assertion for clearing search (extended in the same commit).                                                                                                                                                                                                                                                                             | N/A — not tied to any closed AH review.                                                                                                                                                                                                                                                               |

**No item in this batch was a gap in AH-068's or AH-069's own closed review.** Every bug traces to
either (a) code that predates both chunks and was merely made visible by them, or (b) an unrelated
July sprint chunk / AH-013 with no independent review document to addend. This satisfies the batch's
own stop-gate framing — a bug _in the just-shipped chunks_ would have needed a pin plus a review
finding; none of these were that.

---

## 4. Pinned-surface audit

Checked every named surface against the full session diff (`git diff --name-only 2cad8ba9..HEAD`,
87 files, listed in full in §5). **Zero `apps/api` files touched this session** — that alone rules out
the Q1 two-layer default, Q3 one-mail behavior, the refuse-flip, the render filter, and the
round-numbering templates, since all five live exclusively in backend code:

| Pinned surface                                                                                       | Lives in                                                                                                           | Touched?                                                                                                                                                                                            |
| ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Q1 two-layer default (`creator_posts_content` DB default `true` + create-form default `false`)       | `apps/api` migration + `CampaignPostingToggleTest.php` + the campaign create form (a file this batch never opened) | **No.**                                                                                                                                                                                             |
| Q3 one-mail proof (`DraftReviewedMail` vs `AssignmentCompletedOnApprovalMail`, mutually exclusive)   | `SendAssignmentNotifications` listener, `apps/api`                                                                 | **No.**                                                                                                                                                                                             |
| The refuse-flip (Q4, 422 on toggle-ON with posting cards present)                                    | `CampaignController.php`                                                                                           | **No.**                                                                                                                                                                                             |
| The render filter (`hiddenColumnIds()`)                                                              | `BoardResource.php`                                                                                                | **No.** — `BoardCardDrawer.vue`/`BoardView.vue` were touched (AH-072), but that's the card **drawer**, a different surface from the column-hiding resource; no column-visibility logic was touched. |
| The banner copy (`creator.ui.assignments.detail.completedOnApprovalNotice`, "no verification claim") | `en/creator.json` line 370                                                                                         | **No.** This batch's `f36ac4b9` touched a _different_ key in the same file (`creator.ui.assignments.subtitle`, line 355) — confirmed by diff, no overlap.                                           |
| Round-numbering templates/conditional (no body template interpolates `{version}`)                    | mail Blade templates + `templates.spec.ts`, `apps/api`                                                             | **No.**                                                                                                                                                                                             |
| D5 mapping (which surfaces render the "Draft {n}" round label)                                       | `DraftsTab.vue`, `ReviewDraftDrawer.vue`, `CreatorAssignmentDetailPage.vue`                                        | **Yes — the containing files, not the mechanism.** See below.                                                                                                                                       |
| Any §5.34-pinned case (either chunk)                                                                 | Backend mutation-tested cases, `apps/api`                                                                          | **No.**                                                                                                                                                                                             |

### D5 — the one surface genuinely on this list, and what actually changed

D5's three in-scope surfaces (creator detail history, agency Drafts tab rows, drawer history blocks)
are exactly the three files AH-071 and AH-072 touched most. The label mechanism itself —
`roundState()` / `roundStateKey()` in `draftRounds.ts`, the functions the D5 pin depends on — is
**byte-for-byte unchanged**: `git diff 2cad8ba9..HEAD -- draftRounds.ts` shows only two new functions
appended after the existing code (`roundStateColor`, `roundCardTextStyle`); nothing existing was
edited or removed.

Verified the same at each call site — the exact interpolation D5 pins, unchanged in all three files:

```299:301:apps/main/src/modules/campaigns/components/DraftReviewPanel.vue
              t(roundStateKey(draft.attributes.review_status, assignmentStatus), {
                n: draft.attributes.version,
              })
```

(Identical call, same `{n}` param, present verbatim in `CreatorAssignmentDetailPage.vue` and
`DraftsTab.vue` before and after this session — only the surrounding container changed, from
`v-list-item`/`v-chip` wrapping to a colored `v-sheet`/tonal-`v-list-item`.)

**What changed around it:** container styling only — tonal card background, bold weight, contrast-safe
foreground color, and (AH-071's actual bug fix) deletion of the _second_, contradicting chip that was
never part of D5's own mapping in the first place. D5's pinned tests — `NotificationCenter.spec.ts`'s
Q1(a) historical-row proof and `templates.spec.ts`'s "no round-bearing body template interpolates
`{version}`" — live in files **not present anywhere in this session's diff**. **Green, untouched.**

### The one test that _was_ modified — not a §5.34 case, but named per the instruction's spirit

`tests/unit/architecture/form-error-pattern.spec.ts`'s `CANONICAL_422_FILES` allowlist was edited in
AH-072, because the 422-binding code it tracks physically moved:

```diff
-  // Sprint 9 Chunk 2 (D-8): the agency review drawer binds the feedback-required
-  // 422 onto the review_feedback textarea (request-revision / reject).
-  'modules/campaigns/components/ReviewDraftDrawer.vue',
+  // Sprint 9 Chunk 2 (D-8): the agency review drawer binds the feedback-required
+  // 422 onto the review_feedback textarea (request-revision / reject). Eyes-on
+  // fix batch, 2026-08-17: the binding moved WITH the reviewable content when it
+  // was extracted into the shared `DraftReviewPanel` (now also hosted by the
+  // board card drawer's Drafts tab) — `ReviewDraftDrawer.vue` itself is dialog
+  // chrome only now, so it comes off this list and the panel goes on.
+  'modules/campaigns/components/DraftReviewPanel.vue',
```

This is a rename-tracking edit (the file the 422 binding lives in changed name), not a weakening of
the assertion — the test still requires exactly one canonical file per pattern and still fails if the
binding is duplicated or dropped. Not one of the two closed chunks' pinned surfaces; recorded here
because it is the only test-assertion edit in the entire session.

**Conclusion: no item in this batch touched the Q1/Q3/refuse-flip/render-filter/banner-copy/
round-numbering/§5.34 pinned surfaces. D5's containing files were touched, its mechanism and its own
pinned tests were not — verified by diff, not asserted.**

---

## 5. Scope verification (evidence)

- **i18n keyset diff.** Flattened every key in `en/app.json` and `en/creator.json` at base vs HEAD:
  `added: set()`, `removed: set()` for both namespaces. **Zero new or removed keys this entire
  session** — only two existing key _values_ changed (`app.campaigns.applications.accept.body`,
  `creator.ui.assignments.subtitle`), mirrored identically across all 24 locale files (confirmed: each
  locale's diff is exactly one line, for exactly that key). **"New i18n key family" stop-gate:
  correctly never triggered — there were no new keys to trigger it.**
- **Flaky-10 VALUES check on new keys.** N/A — no new keys were added, so there is nothing to run this
  check against. (Printed all 24 locales' new `subtitle` value as a sanity pass instead — 24 distinct,
  plausible translations, no placeholder/English leak.)
- **API/resource shape diff.** `git diff --name-only 2cad8ba9..HEAD -- apps/api packages/api-client` →
  **empty.** No resource, no DTO, no envelope touched.
- **Gates/policies.** `git diff --name-only 2cad8ba9..HEAD -- '*Policy*' '*Gate*' '*ability*'
'*Ability*'` → **empty.**
- **Migrations (schema-only rule check).** `git diff --name-only 2cad8ba9..HEAD -- '*migrations*'
'apps/api/database'` → **empty.** No migration this session, so the schema-only standing rule was
  never in play.
- **Route tables.** No route file matched (`api.php`, `web.php`, `RouteServiceProvider`, `*routes*`) →
  **empty.**
- **`packages/ui` fan-out.** `git diff --name-only 2cad8ba9..HEAD -- packages/ui` → **empty.** The new
  `CreatorAvatar` component was deliberately placed in `apps/main/src/components/` (app-local), not
  promoted to the shared package — it is presentation glue over two Vuetify primitives plus the app's
  own i18n-label convention, not a new design-system primitive.
- **Listener/state-machine/enum diff.** `git diff --name-only 2cad8ba9..HEAD -- '*Listener*'
'*StateMachine*' '*Enum*'` → **expected empty, confirmed empty.**

All eight verification queries returned exactly what the stop-gate rules predicted for a
frontend-only, no-new-i18n-key batch. Nothing named a surprise here.

---

## 6. Stop-gate log

**Zero stop-gates were hit.** Every item resolved inside the batch's own bounds — no migration, no
API/resource shape change, no validation rule, no gate/policy, no state-machine/listener touch, no new
i18n key family, and (per §4) no pinned surface from either closed chunk.

One near-miss, self-caught rather than requiring a stop: in `b132c65e`, the first attempt at the
warning-card contrast fix used a raw `rgba(0, 0, 0, 0.75)` literal, which the pre-existing
`no-hard-coded-colors.spec.ts` architecture test caught immediately (it is designed to catch exactly
this). The fix was to switch to the sanctioned pattern — an existing, pre-audited design token
(`on-warning`) computed per-round — rather than to touch the architecture test. Not a stop-gate in the
sense of "exceeds the batch," since the correction stayed inside the same commit and needed no
decision from Pedram; recorded here as the log asked for ("self-report misses").

No item was flagged mid-batch and then walked back; no item was deferred to a later session.

---

## 7. Playwright exposure per theme

19 spec files under `apps/main/playwright/specs`, 2 under `apps/admin/playwright/specs` (21 total).
Checked which specs traverse the touched surfaces, by selector:

| Theme                                 | Exposure                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| ------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| AH-071 (draft round display)          | None of the 21 specs assert on round-chip color, bold weight, or feedback whitespace. `hand-off-at-approval-lifecycle.spec.ts` (see below) traverses the surface structurally but asserts nothing about these specifics.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| AH-072 (board Drafts tab full review) | **`hand-off-at-approval-lifecycle.spec.ts` — the OFF-lifecycle leg — directly traverses this.** It opens a draft via `[data-test^="drafts-review-"]`, asserts `[data-test="review-draft-drawer"]` visible, clicks `[data-test="review-approve"]`. All three selectors were verified present, unchanged, post-refactor: `review-draft-drawer` stayed on `ReviewDraftDrawer.vue` (now dialog chrome), `review-approve` moved into the extracted `DraftReviewPanel.vue` with the identical `data-test`, `drafts-review-*` is untouched in `DraftsTab.vue`. **This leg's surface moved (the review UI now lives in a different component) but its E2E contract did not — confirmed by grep, not run** (E2E requires a live backend/browser this inventory pass didn't spin up; flagging as the one thing Step 2 should actually run before push). |
| AH-073 (button position/alignment)    | Same drawer, same `hand-off-at-approval-lifecycle.spec.ts` click path — order-independent selector, unaffected.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                               |
| AH-074 (stale copy)                   | None — no E2E spec asserts on either changed sentence.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| AH-075 (pool avatar)                  | `talent-pools.spec.ts` adds a creator to a pool but doesn't assert on the picker row's avatar rendering.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| AH-076 (list persistence)             | `roster-search-and-affordances.spec.ts` and `creator-detail.spec.ts` navigate `/roster` → detail → back, but neither asserts URL query params or scroll restoration — this is genuinely **new, E2E-uncovered surface**.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| AH-077 (messaging header/dates)       | None of the 21 specs open the relationship-messaging two-pane surface at all.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| AH-078 (profile photo)                | `creator-detail.spec.ts`'s `navigates from a roster row and renders the composed detail` test **does render `CreatorDetailPage`** and would fail if the avatar addition broke the page, but asserts only on `creator-detail-name`/`-email`, not the avatar itself.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |

**Net:** one theme (AH-072/AH-073's shared drawer) has real, selector-level E2E exposure and its
contract was verified intact by inspection. Two themes (AH-076, AH-078) pass _through_ an E2E-covered
page without the E2E asserting on the new behavior. The rest have no E2E exposure at all — consistent
with this being UI/i18n-copy/composable-level work the unit suite is built to catch.

---

## 8. Gates at HEAD

Full board run on `apps/main` at `3ef5d8ec` (backend untouched this session, so its suite was not
re-run here — nothing in `apps/api` changed):

| Gate                             | Result                                                                                                                                                                                                                                                 |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `npx vue-tsc --noEmit`           | **Clean.** No output, exit 0.                                                                                                                                                                                                                          |
| `npx vitest run` (full suite)    | **159 files / 1556 tests, all passed.** 0 failures.                                                                                                                                                                                                    |
| `npx eslint . --max-warnings=0`  | **2 warnings, 0 errors** — both `vue/no-v-html` in `ClickThroughAccept.vue` and `ProfileBasicsForm.vue`, confirmed pre-existing (last touched `77ef15b8`, an unrelated master-agreement commit; neither file appears anywhere in this session's diff). |
| `npx prettier --check src tests` | **Clean.** (A repo-wide `prettier --check .` also flags `dist/` and `test-results/` — stale build/coverage artifacts, not source; scoping to `src`/`tests` is the correct check and it's clean.)                                                       |
| Backend (Pint + Larastan + Pest) | **Not re-run** — zero `apps/api` files in this session's diff (§5), so there is nothing for it to catch that the last green run didn't already cover.                                                                                                  |
| CI at the pushed tip             | **N/A yet** — nothing has been pushed. Per the AH-070 rule (`PROJECT-WORKFLOW.md` §5.41), this becomes a required gate the moment this batch pushes, not before.                                                                                       |

**Changed specs, named with one-line whys** (every `*.spec.ts` touched this session, 16 files):

| Spec                                                 | Why it changed                                                                                                                                                                                                                           |
| ---------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `DraftsTab.spec.ts`                                  | New negative: a historical round no longer echoes the assignment's live status (AH-071 bug fix).                                                                                                                                         |
| `draftRounds.spec.ts`                                | New coverage for `roundStateColor()` and `roundCardTextStyle()` (AH-071/AH-072, two new pure functions).                                                                                                                                 |
| `ReviewDraftDrawer.spec.ts`                          | New assertion for the bold title + contrast-safe feedback color (AH-071); later, all internal review-logic assertions removed since that logic moved to `DraftReviewPanel.spec.ts` (AH-072 refactor — the drawer became a thin wrapper). |
| `BoardCardDrawer.spec.ts`                            | Rewrote the Drafts tab tests against the new embedded `DraftReviewPanel`; added approve-from-board and `canReview`-gate tests (AH-072).                                                                                                  |
| `DraftReviewPanel.spec.ts`                           | **New file** — dedicated coverage for the extracted panel: empty state, `canReview` gating, action flows, local-state reset (AH-072).                                                                                                    |
| `CampaignDetailPage.spec.ts`                         | Updated for the removed `review`-emit chain — the Review button now switches an internal tab rather than emitting (AH-072).                                                                                                              |
| `tests/unit/architecture/form-error-pattern.spec.ts` | Allowlist swap tracking the 422-binding code's move to `DraftReviewPanel.vue` (§4).                                                                                                                                                      |
| `AddCreatorsToPoolDialog.spec.ts`                    | New assertion: photo renders when `avatar_url` present, initials fallback when null (AH-075).                                                                                                                                            |
| `useListQueryState.spec.ts`                          | **New file** — the new composable's own unit coverage (AH-076).                                                                                                                                                                          |
| `useBackToList.spec.ts`                              | **New file** — same, for the back-navigation composable (AH-076).                                                                                                                                                                        |
| `tests/unit/core/router/index.spec.ts`               | New coverage for the added `scrollBehavior` (immediate restore / wait-for-content / timeout) (AH-076).                                                                                                                                   |
| `DiscoverPage.spec.ts`                               | New coverage for URL-resumed browse state, invalid-param handling, and the null-search drive-by fix (AH-076).                                                                                                                            |
| `CreatorRosterPage.spec.ts`                          | Same, Roster's mirror (AH-076).                                                                                                                                                                                                          |
| `AgencyRelationshipThreadPage.spec.ts`               | **New file** — 4 tests pinning the header-follows-the-switch fix; verified to fail on the pre-fix code (AH-077).                                                                                                                         |
| `RelationshipThreadView.spec.ts`                     | New assertion: bubble stamp includes the date, timezone-agnostic (AH-077).                                                                                                                                                               |
| `DiscoverProfilePage.spec.ts`                        | New assertion: avatar renders with photo/initial fallback (AH-078).                                                                                                                                                                      |
| `CreatorDetailPage.spec.ts`                          | Same, Roster's mirror (AH-078).                                                                                                                                                                                                          |
| `CreatorAvatar.spec.ts`                              | **New file** — the shared component's own coverage: shape, open/close, keyboard access, inert-with-no-photo, `data-test` fallthrough (AH-078).                                                                                           |

---

## 9. Surprises

- **The messaging header bug (AH-077) and the pool-avatar/copy bugs (AH-074/AH-075) are not part of
  Draft Workflow v2 at all** — they surfaced because Pedram was eyes-on across the whole app in the
  same session, not because they're downstream of AH-068/069. Worth naming plainly: this batch's real
  boundary turned out to be "whatever Pedram looked at," not "Draft Workflow v2." The close-out ritual
  (AH-071–078) should probably log them as independent single-item entries rather than pretend they're
  sub-chunks of a Draft Workflow v2 epic.
- **Two self-reported pin gaps, both already flagged in §3, restated here because they're the honest
  finding of this inventory pass:**
  - The line-break and chip-color fixes (AH-071) have no dedicated regression test for the specific
    visual defect — jsdom doesn't compute CSS `white-space` collapsing, so a test can prove the style
    rule is _applied_ (a class/style assertion) but not that the browser _renders_ it correctly. This
    was accepted as a known limit of the unit-test layer rather than escalated, which is defensible but
    should be named rather than silently assumed.
  - Neither stale-copy fix (AH-074) added a spec asserting the literal new English sentence. The
    locale-parity architecture test proves structural parity (same keys, same placeholders, same CLDR
    plural forms across 24 locales) but would not catch "counter" creeping back into the English
    source on a future edit. This is a real, closeable gap — a one-line `toContain`/`not.toContain`
    assertion per key would close it — and it's flagged here rather than fixed silently, since Step 1
    is read-only.
- **`AH-013`'s bug (the messaging header) has no independent review file to addend**, unlike AH-068/069.
  The ad-hoc log's convention seems to be: full chunk-loop work gets a `-review.md`; smaller landed
  items get a log entry only. That's a real asymmetry in what "post-close addendum" can even mean —
  there's no place to attach one for AH-013 short of editing its log entry directly, which the log's
  own "no retro-edit" convention (§"Reading a `Status:` line") argues against. This inventory's §3 row
  is the closest available substitute.
- **The AH-070-era kickoff prompt cited "the suite is 28 specs."** Recounting now: 19 under
  `apps/main/playwright/specs` + 2 under `apps/admin/playwright/specs` = 21 files. Either the count
  drifted, counted something else (e.g. `test()` blocks — a `grep -c` for `test(`/`test.describe(`
  across the same files returns 38), or was simply off. Not investigated further since it doesn't
  change this batch's own exposure analysis (§7), but flagging the discrepancy rather than silently
  using the old number.
- **Nothing in this batch required a schema-only migration, an API shape change, or touched any of the
  two closed chunks' eight named pinned surfaces** — for a batch billed as "eyes-on fixes on Draft
  Workflow v2," the actual footprint turned out to be entirely presentational/composable-level, with the
  one exception (D5's containing files) landing exactly where the review predicted it could safely land
  (styling around an untouched mechanism). That is the outcome the stop-gate rules were designed to
  produce, and it held for all 16 commits without needing to invoke a single stop.

---

_No docs beyond this file were written. No push. Awaiting review before Step 2 (docs pass + push)._
