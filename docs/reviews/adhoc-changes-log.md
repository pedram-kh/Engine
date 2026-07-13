# Catalyst Engine — Ad-Hoc Changes Log

A running record of changes made **outside** the sprint plan and the phase roadmap —
out-of-band UX improvements, polish, small fixes, and tech-debt paydowns that don't
belong to any numbered sprint. The aim is simple: nothing the platform does should be
unexplained. If a change isn't a sprint and isn't on the roadmap, it lives here, so any
developer (or future us) can open this one file and know **what changed, why, and where**.

This file is the **index and history** for ad-hoc work. Changes that go through the full
chunk loop still get their own detailed review file in `docs/reviews/`; the entry here is
a short pointer to it, not a duplicate.

---

## How this file works

**Scope.** Any change not driven by the active sprint or the phase spec: UX tweaks, copy
fixes, accessibility, performance polish, small bugfixes, doc corrections, tech-debt
cleanup. If it _is_ part of a sprint, it belongs in that sprint's review, not here.

**Relationship to the existing workflow.** Ad-hoc changes still follow the house loop —
inventory (when the surface is unknown) → kickoff with locked decisions → plan-pause →
build → spot-check → two-commit pair → push. This file doesn't replace that; it's the
durable record so the work isn't invisible afterward. Larger ad-hoc changes get a full
`docs/reviews/<name>-review.md` and this log just links to it.

**Entry lifecycle.** An item starts in **Live Status** (below) when proposed, moves through
`In progress`, and graduates into the **Change Log** as `Landed` once merged. Parked or
dropped items stay in the log with that status so the decision is on record.

**IDs.** Each entry gets a stable `AH-NNN` id so it can be referenced from commits,
reviews, and conversations.

**Entry template** (copy this for each new change):

```
### AH-NNN · <short title>

- **Status:** Proposed | In progress | Landed | Parked
- **Date:** YYYY-MM-DD (landed date, or last-updated while open)
- **Why:** the user problem / motivation in one or two sentences
- **What:** the change in plain terms
- **Touched:** files / modules / surfaces affected
- **Decisions:** any locked calls made along the way
- **Ref:** kickoff / review file / commit(s), if applicable
```

---

## Live Status (open + in-flight)

| ID  | Title                                    | Status  | Notes                                                                |
| --- | ---------------------------------------- | ------- | -------------------------------------------------------------------- |
| —   | Campaign Drafts tab — independent review | Pending | Merged in code; review file reads "pending independent review pass." |

> Pointer, not an ad-hoc item: **Sprint 10 (Payments/Escrow)** remains the deepest pending
> roadmap dependency, Stripe-gated. Tracked in `tech-debt.md`, not here.

---

## Change Log (newest first)

### AH-047 · Creator sees a green "post verified" closure banner

- **Status:** Landed (push HELD)
- **Commit:** `aca03b0` — `feat(creators): show verified-by-agency success banner`
- **Date:** 2026-07-13
- **Why:** After a post was verified — automatically (`live_verified`) or by the agency's manual
  override (`manually_verified`) — the creator detail page showed nothing new: the status chip
  changed but no surface said "you're done." (Pedram's report: the page just goes quiet.)
- **What:** `CreatorAssignmentDetailPage.vue` gains an `isVerified` computed
  (`live_verified || manually_verified`) and a green success `v-alert`
  (`assignment-verified-notice`) in the state-dependent action slot: "Your post has been verified
  by the agency. This assignment is complete — no further action is needed." New i18n key
  `creator.ui.assignments.detail.verifiedNotice` across all 24 `creator.json` locales.
- **Touched:** `CreatorAssignmentDetailPage.vue` (+spec: manual + live variants),
  `locales/*/creator.json` ×24.
- **Decisions:**
  - **One message for both verified states** — the creator doesn't need to know whether a human
    or the checker confirmed it; "verified by the agency" covers both truthfully.
  - The Posted-content history line keeps the row's factual last automatic result (e.g. "Not
    found" under a manual override) — the banner above carries the assignment-level truth.
  - **Ruling (with AH-046):** the initial pass left this key in English for the flaky 10, matching
    surrounding placeholder strings — rejected on review; no English fallback for new
    creator-facing copy, all 24 locales (including the flaky 10 — `bg, el, et, fi, ga, hu, lt, lv,
mt, ro`) ship a real MT baseline at merge time, same standard as AH-028.
- **Ref:** report "when we manually verified, or automatically verified, we should show a message
  in green … so the creator knows that the process is done".

---

### AH-046 · Failed-verification copy tells the creator the agency can resolve it too

- **Status:** Landed (push HELD)
- **Commit:** `48f7afc` — `fix(creators): clarify failed-verification copy mentions manual agency review`
- **Date:** 2026-07-13
- **Why:** The creator-side "We couldn't verify your post" alert only said "check the link and
  resubmit" — implying the creator MUST act even when their link is fine (a false verification
  failure). With AH-045 giving the agency the Resolve action everywhere, the copy should say so.
- **What:** Rewrote `creator.ui.assignments.detail.resubmitInPlace.intro` to a two-branch
  instruction: link wrong → correct and resubmit below; link already correct → no action needed,
  the agency will review and can verify manually. Translated across all **24 SPA `creator.json`**
  locales — also fixed the pre-existing garbled Czech/Slovenian-mix text in this exact line for
  `hr`, `sk`, `sl`, and a stray mixed-language word in `bg` (incidental to the rewrite; the
  broader corruption in those three files beyond this one line is unrelated and tracked in
  `tech-debt.md`).
- **Touched:** `locales/*/creator.json` ×24. Copy-only — no logic or key changes.
- **Decisions:**
  - Keep the same single `intro` key (no new keys); parity gate stays green.
  - **Flaky-10 MT baseline (ruling, applies retroactively to this key too):** the initial pass
    left the 10 flaky locales (`bg, el, et, fi, ga, hu, lt, lv, mt, ro`) on English copy, reasoning
    that it matched already-English surrounding strings in those files. **Rejected on review** —
    "match the surrounding English" just inherits pre-existing debt rather than fixing it. All 10
    now carry a real MT-baseline translation of this key, same standard as AH-028 and AH-047.
- **Ref:** report "we should tell the creator … if the link is correct wait for the agency to
  verify the link manually".

---

### AH-045 · Resolve action surfaced on the board card drawer + Drafts tab rows

- **Status:** Landed (push HELD)
- **Commit:** `55fc474` — `feat(campaigns): surface manual Resolve action for failed verifications`
- **Date:** 2026-07-13
- **Why:** After a failed post verification, the **Resolve** action lived only on the Creators-tab
  row — the agency operator looking at the board card drawer's Detail timeline (the "Live verified —"
  row) or at the Drafts tab had no way to act (Pedram's report + explicit request for both spots).
- **What:**
  - **Board card drawer** (`BoardCardDrawer.vue`): a `Resolve` button now sits inline on the
    **Live verified** timeline row when the assignment is `posted` and the LATEST posted-content
    row's verification is `not_found`/`mismatch` (the D-7 detail already carried the data —
    `posted_content` is newest-first). The drawer emits a `CampaignAssignmentResource` stub;
    `BoardView` closes the card drawer and bubbles it to `CampaignDetailPage`, which opens the
    existing page-level `ResolveVerificationDrawer`. New `canResolve` prop threads the `review`
    ability down (`:can-resolve="canReview"`).
  - **Drafts tab** (`DraftsTab.vue`): a warning-colored `Resolve` button renders next to `Review`
    on rows meeting the same gate, emitting `open-resolve` with the same assignment stub the Review
    flow uses. `onResolved` now also reloads the Drafts tab (mirrors `onReviewed`).
  - **Backend** (`CampaignDraftListItemResource` + `CampaignDraftController`): the draft-list
    assignment stub now emits `verification_status` (the latest posted row's status, D-7 mirror),
    with `assignment.latestPostedContent` eager-loaded. api-client type extended (optional field —
    back-compat).
- **Touched:** `BoardCardDrawer.vue` (+spec), `BoardView.vue`, `DraftsTab.vue` (+spec),
  `CampaignDetailPage.vue`, `packages/api-client` `campaign.ts`, `CampaignDraftListItemResource`,
  `CampaignDraftController`, `CampaignDraftListTest` (latest-post status + null cases).
- **Decisions:**
  - **One drawer, three doors, zero new backend surface:** all three UI entry points (Creators
    tab, Drafts tab, board drawer) open the SAME pre-existing page-level `ResolveVerificationDrawer`
    with the same `CampaignAssignmentResource` stub shape, calling the same pre-existing
    `manuallyVerify` / `requestResubmitFresh` / `requestResubmitInPlace` endpoints and the same
    authorization gate (`canReview`) — no new backend action, route, or authorization path; this
    chunk is UI wiring only (confirmed: `ResolveVerificationDrawer.vue` has zero diff in this batch).
  - **`verification_status` is additive and back-compat:** a new, optional field on the existing
    `CampaignDraftListItemResource` assignment stub (agency-only resource — the route sits under
    `auth:web + tenancy.agency`), null when `latestPostedContent` isn't eager-loaded; the
    `packages/api-client` type change is optional (`?:`), so no existing consumer breaks.
  - **Same gate everywhere:** `canReview && status === 'posted' && verification ∈ {not_found,
mismatch}` — copied verbatim from the Creators-tab `canResolveVerification`.
  - No new i18n keys — reuses `app.campaigns.resolution.action` ("Resolve").
- **Ref:** report "no place to verify it or manually verify it" → request "add the resolve button
  on the card details … and on the draft tab next to review".

---

### AH-044 · Draft submit — a link alone is a valid draft (media no longer mandatory)

- **Status:** Landed (push HELD)
- **Commit:** `ebf736f` — `feat(creators): allow link-only draft submissions`
- **Date:** 2026-07-13
- **Why:** A creator added an external link to a draft but the **Submit/Resubmit** button stayed dead
  with no explanation (Pedram's report). The draft composer required at least one uploaded **media**
  file — a link alone couldn't carry a draft — and nothing told the creator why the button was disabled.
- **What:**
  - **Backend** (`CreatorAssignmentDraftController::submitDraft`): `media` relaxed from
    `required|array|min:1` to `nullable|array`; a new **"at least one of {media, links}"** invariant is
    enforced after validation, returning `422 draft.empty` when both are empty. Empty media now persists
    as `null` (mirrors the `links` normalisation).
  - **Frontend** (`CreatorAssignmentDetailPage.vue`): the submit gate is now
    `(readyMedia > 0 || draftLinks > 0) && !mediaUploading`, so a link alone enables submit. Added an
    `emptyHint` caption next to the button explaining the requirement while the draft is empty.
  - New i18n key `creator.ui.assignments.detail.draft.emptyHint` across all **24 SPA `creator.json`**
    locales (full parity gate).
- **Touched:** `CreatorAssignmentDraftController`; `CreatorAssignmentDetailPage.vue`;
  `locales/*/creator.json` ×24; `CreatorAssignmentDraftTest` (link-only success + empty-draft 422),
  `CreatorAssignmentDetailPage.spec.ts` (gate + hint).
- **Decisions:**
  - **Media OR links** (not media-mandatory): a draft hosted entirely on an external link is a
    first-class draft. The only hard rule — "at least one of {media, links}" — is enforced once,
    after validation, in `submitDraft()`; submit and resubmit are the **same endpoint/method**
    (producing / contracted / revision_requested all route through it), so the rule applies
    identically to both.
  - **`media: null` is safe downstream:** the only reader of `media_attachments` outside the model
    is `CampaignDraftResource::mapMedia()`, which already null-coalesces to `[]` before
    serialization — so every consumer (`ReviewDraftDrawer`'s `.media.map(...)`, the board drawer's
    latest-draft row, which doesn't render media at all) sees a plain array, never `null`. The
    creator's own detail page doesn't render past-draft media either — no null-safety change was
    needed anywhere.
  - **Silent-disabled is a bug:** a disabled primary action always states its precondition (the
    `emptyHint`).
- **Ref:** report "added a link but couldn't resubmit, or submit".

---

### AH-043 · Toggle-OFF: the thread system message stops claiming a signed contract

- **Status:** Landed (push HELD)
- **Commit:** `b99ac31` — `fix(messaging): fork system-message copy for contract-less advances`
- **Date:** 2026-07-13
- **Why:** Direct follow-on to AH-042. With the per-campaign contract toggle OFF, the assignment
  **Messages** tab still showed the lifecycle system line _"The contract was signed — production can
  begin."_ (Pedram's report). AH-042 gated the _notification_ surface (Q1) but missed the **in-thread
  system message** — a third contract-announcement surface written by a separate listener. The false
  line also fired on the agency's manual proceed-without-contract path (contract-less since the
  decouple chunk).
- **What:**
  - `WriteSystemMessage` now forks the rendered copy on `contract_id`: a **contract-less**
    `AssignmentContracted` (`contract_id === null`) writes the new key
    `assignment.contracted_without_contract` → _"Production can begin."_; a real contract keeps
    `assignment.contracted` → _"The contract was signed — production can begin."_ Same Q1 discriminator
    (`contract_id === null`), so it covers **both** the requires=false auto-advance and the agency
    manual proceed-without-contract.
  - New i18n key `messaging.system.assignment.contracted_without_contract` added across **all 24 SPA
    locales** and `lang/*/messages.php`, reusing each locale's own "production can begin" clause
    (full 24-locale parity gate applied).
- **Touched:** `WriteSystemMessage` (listener); `lang/*/messages.php` ×24,
  `apps/main/.../locales/*/app.json` ×24; `SystemMessageTest` (split real vs. contract-less),
  `ChatPanel.spec.ts` (truthful no-contract render).
- **Decisions:**
  - **Neutral copy, not suppression:** `contracted` on an OFF campaign genuinely _does_ mean
    production can begin — only the "contract was signed" clause is false. A distinct, truthful key
    preserves the production-start milestone rather than dropping it.
  - **Same invariant as AH-042 Q1:** a contract-less advance never announces a contract, on any
    surface (notification, and now the thread system message + digest render).
  - **This closes a gap the AH-042 review itself missed:** that review's coverage table enumerated
    only the notification listener; `WriteSystemMessage` is a distinct `AssignmentTransitioned`
    consumer with the identical invariant and was never swept. Recorded as a dated,
    clearly-marked **Post-close addendum (AH-043, 2026-07-13)** appended to
    `docs/reviews/contract-toggle-off-flow-review.md`, with the review's original closed text left
    verbatim and unmodified above it.
- **Ref:** report "toggle off … in the messages i can still see the signing contract phase";
  see the Post-close addendum in `docs/reviews/contract-toggle-off-flow-review.md`.

---

### AH-042 · Toggle-OFF campaigns flow without contract involvement

- **Status:** Landed (push HELD)
- **Date:** 2026-07-13
- **Why:** A campaign's "Require a per-campaign contract" toggle (`requires_per_campaign_contract`)
  was set OFF but the assignment pipeline still dead-ended the creator at `accepted` with no step
  forward — identical to the ON behaviour. The decouple chunk had added an _agency_ escape button but
  the creator remained stuck. OFF must flow with **zero** contract involvement; ON stays as-is.
- **What:**
  - The state-machine `contract()` gate now reads `$contract !== null` — a **contract-less advance is
    permitted regardless of the `per_campaign_contract_enabled` flag** (the flag gates the contract
    _feature_, irrelevant when no contract is involved).
  - `CreatorAssignmentController::accept` **auto-advances** `accepted → contracted` (contract-less, one
    outer transaction) when the campaign toggle is OFF, landing the creator straight on the draft form.
  - The creator detail copy ("the agency will send a contract" / signing-disabled) now consults the
    campaign toggle via a new `requires_per_campaign_contract` meta key (belt-and-suspenders).
  - New one-shot idempotent command `campaigns:advance-contractless-accepted` (`--dry-run`,
    accepted-only + requires=false-only) to advance rows stuck before this shipped.
  - **Pre-existing false-fire fixed:** the agency proceed-without-contract path had been announcing
    "the creator accepted the contract" for contracts that never existed — the contract-acceptance
    notification is now gated on `contract_id !== null` everywhere.
- **Touched:** `CampaignAssignmentStateMachine`, `CreatorAssignmentController`,
  `CreatorAssignmentDraftController` (meta), `SendAssignmentNotifications`,
  `AdvanceContractlessAcceptedAssignments` (new command); `campaign.ts` type,
  `CreatorAssignmentDetailPage.vue`; backend + FE + console tests.
- **Decisions:**
  - **D1 (flag vs. toggle):** the toggle is the single source of "does this campaign need a contract";
    the flag is the single source of "is the contract feature operational." The machine permits
    `contract(null)` irrespective of the flag; the flag stays load-bearing for `contract !== null`.
  - **D5 (accept-time snapshot posture):** flipping the toggle ON after acceptance does **not**
    retroactively demand a contract (contracted rows stay contracted); flipping OFF advances stuck rows
    **only** via the D4 command or the agency button, never automatically on campaign edit.
  - **Q2 asymmetry (recorded):** the machine permits `contract(null)` while the agency
    proceed-without-contract _endpoint_ keeps its `flagGate` — the endpoint is part of the contract
    feature's surface (flag territory), the auto-advance is the absence of the feature (toggle
    territory). Manifests only when the flag is manually OFF; the D4 command drives the machine
    directly so remediation is never blocked.
  - **Uniform notification gate (incl. the pre-existing false-fire):** a contract-less advance never
    announces a contract acceptance, regardless of path (auto-advance, backfill, or agency button);
    the agency still learns of the accept itself.
  - **D6 (audit distinguishability):** three contract-less paths carry distinct audit signatures —
    accept-chained (`auto_advanced: true`), backfill (`auto_advanced: true, source: backfill`), agency
    manual (neither key).
  - **D4 post-deploy step:** run `php artisan campaigns:advance-contractless-accepted` once after
    deploy — **joins the AH-026 `creators:recompute-completeness` in the pending-deploy list**
    (see `RESUMPTION-TEMPLATE.md` Part 2). Idempotent; no scheduler.
- **Ref:** kickoff "Toggle-OFF campaigns flow without contract involvement" (investigation I1–I6);
  review `docs/reviews/contract-toggle-off-flow-review.md`.

---

> **AH-033 → AH-041 are one direct-iteration fix batch** (the AH-007 pattern: Pedram
> directs each change interactively, no per-item kickoff; one independent review + one
> close-out at the end). Nine themes, committed as small conventional commits
> (`cc86bb8 … fdbec40`, atop the AH-032 baseline `7051123`). Stop-gate exceptions taken
> mid-batch on Pedram's explicit call are recorded per entry. Close-out Steps 1–2 ran
> the full backend Pest suite, both SPA Vitest suites, and the **entire Playwright E2E
> suite (24/24 green — 22 main + 2 admin)** against all four new migrations. **This batch
> adds three schema migrations + one data backfill to the next deploy** (see the deploy
> note in `RESUMPTION-TEMPLATE.md` Part 2). Push HELD at close-out.

### AH-041 · Reject guard + board wiring (Cancelled / Rejected)

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** Rejecting a draft is a **terminal** action (the assignment ends, the creator
  cannot resubmit, the thread closes) but the agency got no warning before clicking it;
  and a rejected assignment's card stayed wherever it was — no board column reflected
  "rejected".
- **What:**
  - A confirmation dialog guards the terminal draft-reject action in `ReviewDraftDrawer`
    ("Rejecting is final… use Request changes instead"), with a "Keep reviewing" escape.
  - The default **"Cancelled"** board column is renamed **"Cancelled / Rejected"**, and the
    `assignment.draft_rejected` audit event is wired as a **10th default automation** that
    auto-moves the card to that column.
  - A **data backfill** migration renames existing default-named terminal-failure columns
    and inserts the new automation for boards that lack it.
  - Column name forced onto one line (`text-no-wrap` + `text-truncate`); the
    closed-conversation chat notice restyled from `info` to **`error`** (red).
- **Touched:** `apps/api/app/Modules/Boards/Support/BoardDefaults.php`,
  `apps/api/database/migrations/2026_07_13_110000_backfill_cancelled_rejected_board_column.php`,
  `apps/api/tests/Feature/Modules/Boards/{BoardApiTest,BoardAutomationServiceTest,BoardLazyHealTest,BoardProvisioningServiceTest,OverdueScanTest}.php`,
  `apps/main/src/modules/boards/components/BoardColumn.vue`,
  `apps/main/src/modules/campaigns/components/ReviewDraftDrawer.{vue,spec.ts}`,
  `apps/main/src/modules/messaging/components/ChatPanel.vue`,
  `apps/main/src/core/i18n/locales/*/app.json` (24, `rejectConfirm` block).
- **Decisions:** **reject now has a cross-module side effect** — Campaigns' draft-reject
  fires an audit event that the Boards automation engine consumes (a new Campaigns→Boards
  coupling, recorded here). The backfill **renames only default-named terminal-failure
  columns** (`name = 'Cancelled' AND is_terminal_failure = true`), so an agency that
  renamed the column keeps its name — **test-pinned** in `BoardProvisioningServiceTest`.
  Automation insert is **idempotent on `(board_id, event_key)`**. `down()` is deliberately
  **blunt** in the opposite direction: it **deletes ALL `assignment.draft_rejected`
  automations** (including any later seeded by provisioning — the default is now part of
  provisioning) and **conditionally renames back** any `Cancelled / Rejected` terminal-failure
  column to `Cancelled` (which would also catch a legitimately custom column of that exact
  name — low risk, since that name is now the default). The `is_terminal_*` flags stay
  semantic labels, not gating logic.
- **Ref:** `18d9845` (confirm dialog), `30bdcd8` (rename + automation + backfill),
  `1f16fe8` (one-line column + red notice). **Stop-gate exceptions** (Pedram's explicit
  call): the `rejectConfirm` i18n keys ×24 and the rename + data-backfill migration.

### AH-040 · Draft submissions — external links + chat-style composer

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** The draft form asked for **hashtags/mentions** that nothing consumed, the media
  input was a bare `v-file-input`, and creators needed to attach **external links**
  (hosted video, doc, reference) alongside files.
- **What:** Hid the hashtags/mentions inputs on **both** the creator draft form and the
  agency `ReviewDraftDrawer`; replaced the file input with a **chat-style two-icon
  composer** (paperclip = file, link = link dialog, mirroring the messaging composer);
  added **real external-link support** persisted on the draft (`links` jsonb) and rendered
  back on the review side.
- **Touched:** `apps/api/database/migrations/2026_07_13_100000_add_links_to_campaign_drafts.php`,
  `apps/api/app/Modules/Campaigns/Models/CampaignDraft.php`,
  `apps/api/app/Modules/Campaigns/Http/Resources/CampaignDraftResource.php`,
  `apps/api/app/Modules/Creators/Http/Controllers/CreatorAssignmentDraftController.php`,
  `apps/api/tests/Feature/Modules/Creators/CreatorAssignmentDraftTest.php`,
  `apps/main/src/modules/creators/pages/CreatorAssignmentDetailPage.{vue,spec.ts}`,
  `apps/main/src/modules/campaigns/components/ReviewDraftDrawer.{vue,spec.ts}`,
  `packages/api-client/src/types/campaign.ts`.
- **Decisions:** URL validation is a **`url:http,https` scheme allowlist**, **max 10 links
  / 2048-char url / 255-char name**. Links render as **plain anchors with
  `rel="noopener noreferrer"` + `target="_blank"`** (no preview fetch, no unfurl — a link
  is inert text). Hashtags/mentions follow the **AH-032 retained-and-preserved-by-omission
  pattern**: the columns, validation, and Resource emission stay; only the UI is dropped,
  so nothing is lost and re-surfacing them is a pure front-end change.
- **Ref:** `832f9ca` (links backend + migration + resource), `44afe5c` (composer + drop
  hashtags/mentions), `e1ee4b2` (Media label above the icons). **Stop-gate exceptions**
  (Pedram's explicit call, "do both a and b"): the `links` migration + api-client shape +
  validation rules (real link persistence, not just a visual affordance).

### AH-039 · Board card facelift + drawer Detail-tab redesign

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** The board card face and the drawer's Detail tab were sparse — no photo, no fee
  context, no deliverables, no progress at a glance.
- **What:**
  - **Card face:** creator avatar, **bold** name (matching the drawer header), deliverable
    chips, fee-per line, and the brand **aurora gradient** as the accent strip (replacing
    the per-column color token).
  - **Drawer Detail tab:** redesigned into an identity header (avatar + name + status +
    campaign · brand), **invite-offer terms** (fee / per / description / attachment),
    deliverable chips, a **five-step progress timeline**, and latest-draft / posted-link
    rows, with locale-aware date-format fixes.
  - Card face is **preserved across a move** (the `move` response re-selects avatar, fee,
    and decline-history so the face doesn't degrade after a drag).
- **Touched:** `apps/api/app/Modules/Boards/Http/Controllers/{BoardController,BoardCardController}.php`,
  `apps/api/app/Modules/Boards/Http/Resources/BoardCardResource.php`,
  `apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentReviewController.php`,
  `apps/api/tests/Feature/Modules/Boards/{BoardApiTest,BoardManualMoveTest}.php`,
  `apps/api/tests/Feature/Modules/Campaigns/CampaignAssignmentReviewTest.php`,
  `apps/main/src/modules/boards/components/{BoardCard,BoardCardDrawer}.{vue,spec.ts}`,
  `apps/main/src/modules/boards/components/BoardColumn.vue`,
  `apps/main/tests/unit/architecture/form-error-pattern.spec.ts`,
  `packages/api-client/src/types/{board,campaign}.ts`,
  `apps/main/src/core/i18n/locales/*/app.json` (24, `board.drawer.detail` block).
- **Decisions:** **API-resource-shape stop-gate exception** — `BoardCardResource` now emits
  `avatar_url` + fee fields, and the review `show` emits `fee_per` / `offer_description` /
  `offer_attachment` (signed) / `invited_at`. Signed attachment URL is emission-scoped
  (60-min, AH-004). The aurora strip uses `var(--brand-aurora-gradient)` (an allowed
  surface per the architectural CSS-token lint); the `colorToken` prop was removed.
- **Ref:** `32e21f6` (concept card), `ec1596d` (keep face on move), `0930db1` (drawer
  Detail redesign), `3ebbfb4` (bold name). **Stop-gate exceptions**: API resource shape +
  the `board.drawer.detail` i18n keys ×24 (approved as items 1–5 of the proposal).

### AH-038 · Discover card redesign (Phase A — front-end only)

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** The discover card was a plain list tile; Pedram wanted a **photo-forward,
  concept-inspired** card (from an uploaded reference), explicitly **Phase A only** —
  pure restyle on existing data.
- **What:** Full-width hero avatar block, name with a connection-state indicator, an
  icon-based meta row (country + language/accent), ≤3 category chips with a **"+N"
  overflow** chip, and a footer row (connection state + view-profile icon). Cards made
  **~30% smaller** via tighter grid breakpoints, a **5:4 landscape** hero to match the
  concept ratio, and **CSS container queries** so text/chips/icons scale with the card
  (the font-to-card ratio holds as the viewport shrinks).
- **Touched:** `apps/main/src/modules/discover/pages/DiscoverPage.vue`.
- **Decisions:** **Phase A = pure front-end** — no backend, resource, gate, or i18n change
  (all data already on the wire). `container-type: inline-size` + `cqi`/`clamp()` units for
  proportional scaling. No stop-gate triggered.
- **Ref:** `7b49e54` (photo-forward restyle), `71800a0` (~30% smaller), `3e96a53` (5:4
  ratio), `fd3630e` (one-line categories + `+N`), `677cd64` (container-query scaling).

### AH-037 · Board card drawer — Campaign messages tab

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** Agencies wanted to read and reply to the per-assignment chat **from the board
  card** rather than navigating away to the campaign messaging surface.
- **What:** A **"Messages"** tab added as the **first and default** tab in the board card
  drawer, mounting the existing `ChatPanel` keyed per assignment; a "no conversation" note
  when the card has no assignment data.
- **Touched:** `apps/main/src/modules/boards/components/BoardCardDrawer.{vue,spec.ts}`,
  `apps/main/src/core/i18n/locales/*/app.json` (24, tab label + `none` note).
- **Decisions:** **`ChatPanel` reuses `agencyChatTransport` with ZERO new provisioning**
  — the AH-012 lesson held. The drawer is a **read/reply mount of the existing
  campaign-messaging surface**; it introduces no new thread-creation path and inherits the
  same Sprint-11 campaign-messaging gate. The Messages tab is **independent of the
  detail/movements fetch**, so it renders even if the Detail tab's data errors.
- **Ref:** `79298f8`. **Stop-gate exception**: the Messages-tab i18n keys ×24 (label +
  "no conversation" note).

### AH-036 · Invitation + admin readability fixes

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** Three small visibility problems: the admin **"Pending approval"** nav item was
  truncated, the creator invitation **fee + start/end dates** were crammed on one line, and
  the **"View post"** button was near-invisible in dark mode.
- **What:** Widened the admin sidebar `280px → 304px`; put fee and posting window on
  separate lines in the creator invitation list; brightened the View-post button
  (`secondary` → `primary`).
- **Touched:** `apps/admin/src/core/layouts/AdminLayout.vue`,
  `apps/main/src/modules/creators/pages/CreatorAssignmentsPage.vue`,
  `apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue`.
- **Decisions:** Pure styling — no shape, prop, or i18n change. No stop-gate.
- **Ref:** `a4c778b` (sidebar width), `a073b47` (fee/date lines), `3286590` (View-post
  brightness).

### AH-035 · Re-offer after decline (declined → invited)

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** Re-inviting a **declined** creator silently no-op'd — the invite endpoint's
  idempotency returned the existing declined row with a `200`, so the agency saw a success
  toast but no new invitation and no updated offer ever reached the creator.
- **What:** A new state-machine edge `reofferAfterDecline` (`declined → invited`); the
  invite controller routes a declined existing row through it (other statuses keep the
  idempotent no-op); a muted **"Declined"** history tag surfaces on the Creators tab, the
  board card face, and the drawer; the creator-side **counter UI is removed entirely**.
- **Touched:** `apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php`,
  `apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentController.php`,
  `apps/api/app/Modules/Campaigns/Http/Resources/CampaignAssignmentResource.php`,
  `apps/api/app/Modules/Campaigns/Models/CampaignAssignment.php`,
  `apps/api/database/migrations/2026_07_12_110000_add_previously_declined_to_campaign_assignments.php`,
  `apps/api/app/Modules/Boards/Http/{Controllers/BoardController.php,Resources/BoardCardResource.php}`,
  `apps/api/tests/Feature/Modules/{Campaigns/CampaignAssignmentStateMachineTest,Campaigns/CampaignAssignmentInviteTest,Boards/BoardApiTest}.php`,
  `apps/main/src/modules/boards/components/{BoardCard,BoardCardDrawer}.{vue,spec.ts}`,
  `apps/main/src/modules/campaigns/pages/CampaignDetailPage.{vue,spec.ts}`,
  `apps/main/src/modules/creators/pages/CreatorAssignmentsPage.{vue,spec.ts}`,
  `packages/api-client/src/types/{campaign,board}.ts`.
- **Decisions:**
  - `declined → invited` **overwrites the full offer** (fee / currency / per / description /
    attachment) + **clears `responded_at`** + **raises `previously_declined`**.
  - **Fail-closed from any non-declined source** (`assertSource([Declined])`) — the batch's
    only state-machine change, so a **break-revert was executed at close-out** (Part A3):
    widening the guard to include `Accepted` turned the fail-closed unit test red
    (`reofferAfterDecline: a non-declined source throws invalid_transition`), then reverted
    to green — proving the guard is load-bearing.
  - **Idempotent no-op preserved** on non-declined existing rows (invited/accepted/etc.
    still return the existing row unchanged).
  - Audit verb **reuses `assignment.re_invited`** (`AssignmentReInvited`) — no new action.
  - The **creator counter UI is removed** while the **counter API remains fail-closed**
    (`invited`-only) — recorded as **API-without-UI tech-debt**.
  - `previously_declined` is **agency-side only** (`CampaignAssignmentResource` +
    `BoardCardResource`), **never creator-visible** (verified, S7).
- **Ref:** `34f5e84` (machine edge + migration), `64222b5` (api-client),
  `5626ddf` (history tag + drop counter), `2d56cbd` (board resource),
  `c9cba2a` (api-client board), `edfc56e` (card + drawer tag). **Stop-gate exception**
  (Option 2, Pedram's explicit call): the `previously_declined` migration + state-machine
  edge + resource shape.

### AH-034 · Invite-offer context — fee-per, description, attachment, roster avatars

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** An invite carried only a bare fee. Agencies wanted to say what the fee is **per**
  (e.g. "per script"), add a free-text **description** of expectations, attach a **file**,
  and see **real creator photos** (not initial avatars) in the invite modal.
- **What:** Added `fee_per` + `offer_description` free-text to the invite payload and the
  assignment; a **presigned-S3 offer attachment**, **campaign-keyed** (uploaded once per
  invite batch, before any assignment row); surfaced the offer context on the invitation
  card and the creator's assignment surfaces; showed real `avatar_url` in the invite
  roster.
- **Touched:** `apps/api/app/Modules/Campaigns/Http/{Controllers/CampaignAssignmentController.php,Requests/InviteAssignmentRequest.php,Resources/CampaignAssignmentResource.php}`,
  `apps/api/app/Modules/Campaigns/Models/CampaignAssignment.php`,
  `apps/api/app/Modules/Campaigns/Routes/api.php`,
  `apps/api/app/Modules/Campaigns/Services/AssignmentOfferAttachmentUploadService.php`,
  `apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorController.php`,
  `apps/api/app/Modules/Creators/Http/Controllers/{CreatorAssignmentController,CreatorAssignmentDraftController}.php`,
  `apps/api/database/migrations/2026_07_12_100000_add_offer_fields_to_campaign_assignments.php`,
  `apps/api/tests/Feature/Modules/{Campaigns/CampaignAssignmentInviteTest,Creators/CreatorAssignmentTest,Agencies/AgencyCreatorRosterTest}.php`,
  `apps/main/src/modules/campaigns/{api/campaigns.api.ts,components/InviteCreatorsDialog.vue,pages/CampaignDetailPage.vue}`,
  `apps/main/src/modules/creators/pages/{CreatorAssignmentDetailPage.vue,CreatorAssignmentsPage.{vue,spec.ts}}`,
  `packages/api-client/src/types/{campaign,agency}.ts`,
  `apps/main/src/core/i18n/locales/*/app.json` (24).
- **Decisions:**
  - The presigned flow **mirrors the messaging-attachment posture**: supported raster
    images are re-encoded/EXIF-stripped at complete time (`PortfolioImageProcessor`, 50 MP
    decompression-bomb guard); **non-image types are stored without content sniffing** —
    recorded as **tech-debt** (extends the platform-wide AV gap).
  - **Emission-scoped signed URLs** (60-min TTL, AH-004 posture), minted only inside an
    already-authorized resource emission.
  - **Cross-campaign prefix isolation pinned** — `assertUploadBelongs` requires the
    `upload_id` to sit under `agencies/{agency}/campaigns/{campaign}/offer-attachments/`;
    cross-campaign / cross-agency paths are rejected.
  - **`tenancy.md §4` updated in the closure commit** (`fdbec40`) with the two attachment
    routes, annotated as full-standard-tenant-stack (NOT scope bypasses).
  - Roster `avatar_url` added on a **bounded, paginated** list (the AH-013 precedent — not
    an N+1 concern).
- **Ref:** `ac76e0f` (backend + migration + service + routes), `eb901db` (api-client),
  `6b2dc5a` (invite dialog: Per + description + attachment + real avatars),
  `8e9093b` (creator sees the offer context). **Stop-gate exception** (Items 1 + 3,
  Pedram's explicit call): the offer-fields migration + resource shape + validation +
  two new routes + i18n keys.

### AH-033 · Campaign overview — name, duration, full description, contract requirement

- **Status:** Landed
- **Date:** 2026-07-13
- **Why:** The campaign overview should show the campaign **name**, its **duration**, and
  the **full description**, drop the **Objective (UGC)** row, and state whether the campaign
  **requires a per-campaign contract**.
- **What:** Show campaign name + start/end duration; removed the objective row; render the
  full description **without** Vuetify's subtitle truncation; added a "Requires a
  per-campaign contract" row as the **last** item.
- **Touched:** `apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue`.
- **Decisions:** **No new i18n key** — reused existing keys and represented the
  contract-requirement boolean with an **icon** (Required / Not required) rather than new
  text keys, keeping the item inside the fast batch. A scoped style overrides
  `v-list-item-subtitle` truncation (`white-space: pre-wrap; word-break: break-word;`). No
  backend or resource change — everything reads from existing `CampaignResource`
  attributes.
- **Ref:** `cc86bb8` (name + duration + full description, drop objective), `9805b3b`
  (contract-requirement row), `0ae30d9` (row moved to last). No stop-gate (existing i18n +
  icon for the boolean).

### AH-032 · Campaign-creation form simplification

- **Status:** Landed
- **Date:** 2026-07-12
- **Why:** The campaign create/edit form asked for more than agencies needed: an `objective`
  select (rarely meaningful — most work is UGC), a write-only `target_creator_count`, and a
  structured brief block (`deliverables` / `hashtags` / `usage_rights`) that nothing in the product
  ever rendered back. The brief inputs also carried a latent data-loss bug (see Decisions).
- **What:** Removed three things from the form, relaxing (never breaking) the API contract:
  - **Objective (D-1):** dropped the select. `CreateCampaignRequest` now validates `objective` as
    `['sometimes', Enum]` and `prepareForValidation()` defaults it to `ugc` when absent. The enum,
    column, `CampaignResource` emission, and Overview-tab display row are untouched — existing
    campaigns keep and display their objective; new ones default to UGC. An explicit objective in a
    payload is still honored.
  - **Target creator count (D-2):** dropped the input. Column, `sometimes|nullable` validation, and
    Resource emission stay — write-only became API-only. No backend change (omission preserved by
    `sometimes`).
  - **Brief (D-3):** removed the three inputs and `assembleBrief()`; the form no longer sends `brief`
    at all. Backend brief validation and Resource emission are untouched. On edit, omission +
    `sometimes` preserves the stored brief blob byte-identical.
  - **Description (D-4):** absorbs the prose role via a new persistent hint
    (`app.campaigns.fields.descriptionHint`) inviting deliverables and usage terms as free text.
    `max:5000` unchanged.
  - **i18n (D-5):** removed the orphaned `fields.{targetCreatorCount,deliverables,deliverablesHint,`
    `hashtags,hashtagsHint,usageRights}` and `board.drawer.detail.deliverables` across all 24
    locales; added `fields.descriptionHint` ×24 (real MT baseline for the 10 flaky locales, not
    English fallback). `fields.objective` + the `objective.*` block are **kept** — the Overview tab
    consumes them. Parity green.
- **Wipe-bug fix (by omission, not by design):** the shipped form rebuilt the entire `brief` jsonb
  from only its three visible inputs on every save, silently wiping any other stored sub-keys
  (`dos`/`donts`/`mentions`/`links`/`attachments`) written by any other path. Removing the inputs so
  the form stops sending `brief` eliminates the wipe as a side effect of the simplification — it is
  fixed **by omission, not by a deliberate merge fix**. A named regression test
  (`preserves the stored brief byte-identical when the edit omits it`) pins this as an invariant, and
  a forward-guard is recorded in `tech-debt.md` so a future brief editor can't reintroduce the class.
- **Touched:** `apps/api/app/Modules/Campaigns/Http/Requests/CreateCampaignRequest.php`,
  `apps/api/tests/Feature/Modules/Campaigns/CampaignCrudTest.php`,
  `packages/api-client/src/types/campaign.ts`,
  `apps/main/src/modules/campaigns/components/CampaignForm.vue`,
  `apps/main/src/modules/campaigns/pages/CampaignCreatePage.vue`,
  `apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue`,
  `apps/main/src/core/i18n/locales/*/app.json` (24), `docs/tech-debt.md`.
- **Decisions:** contract only relaxes (`objective` optional at edge + in the TS mirror); the form
  never sends `brief`/`target_creator_count`, preserving stored values by omission via backend
  `sometimes` rules; `seedEditForm()` deliberately does NOT re-seed the removed fields (re-seeding
  would revive the overwrite path and make the preservation test theatre). Out of scope, logged not
  built: creator-visible campaign description/brief (product gap — `tech-debt.md`); the vestigial
  `posting_window_*` fields absent from the form (validated backend, no input); admin campaign
  surfaces. No Playwright exposure exists for campaigns — none created this chunk.
- **Gates:** backend Campaigns suite 167 passed; `CampaignCrudTest` 16 passed (3 new); FE campaigns
  vitest 68 passed (no spec edits needed); api-client + main typecheck clean; ESLint clean; Pint +
  PHPStan clean; locale parity 23/23. Break-revert on the brief-preservation invariant confirmed it
  bites (forced `brief = null` in the controller → test red → reverted, empty diff).
- **Ref:** `d1f2608` (feat) + `797ba05` (docs); independent review approved and closed in the AH-032
  close-out commit (`docs/reviews/campaign-form-simplification-review.md`, Status: Closed). Pushed on
  Pedram's call at close-out.

---

### AH-031 · Platform rebrand: Engine C → Catalyst Engine

- **Status:** Landed
- **Date:** 2026-07-12
- **Why:** The platform name was still "Engine C" across emails, SPA titles, and backend strings.
- **What:** Two-lever rollout — `APP_NAME` first (cascades through every mail surface), then
  everything else: both SPA titles/`VITE_APP_NAME`, 48× `app.json` titles, 24× `lang/app.php`, the
  API root JSON response, the seeded admin display name, and brand-layer code comments
  (`packages/design-tokens`, `packages/ui` comment-only, fan-out diff-verified against both SPA
  consumers).
- **Touched:** `apps/api/.env(.example)`, `apps/main/.env(.example)`, `apps/admin/.env(.example)`,
  `apps/api/lang/*/app.php` (24), `apps/main/src/core/i18n/locales/*/app.json` (24),
  `apps/admin/src/core/i18n/locales/*/app.json` (24), `apps/api/routes/web.php`,
  `apps/api/database/seeders/Sprint1IdentitySeeder.php`, `apps/main/index.html`,
  `apps/admin/index.html`, comment-only touches in `packages/design-tokens`, `packages/ui`,
  `apps/api/config/mail.php`, `apps/api/.gitignore`,
  `apps/api/app/Modules/Creators/Enums/ContractKind.php`,
  `apps/api/app/Modules/Creators/Services/CreatorWizardService.php`, a contracts-table migration
  docblock, and `apps/api/resources/views/vendor/mail/html/themes/catalyst.css`. Test assertions
  updated in `apps/main`/`apps/admin` unit specs and `apps/main/playwright/specs/smoke.spec.ts`.
- **Decisions:** value-only swaps on existing keys — zero keyset change, i18n parity green; the
  brand name is a proper noun, correctly left untranslated in all 24 locales (the 10
  historically-flaky locales were explicitly checked, not assumed). The `catalyst.css` comment edit
  is accepted as non-durable — it's a published vendor asset that may be regenerated by a future
  `mail:publish`, at which point the comment (not the styling) reverts; harmless. Backend files were
  touched under a UI-batch label — recorded as an accepted exception (every change is an inert
  string value, no behavior/shape change) rather than pretending the batch stayed UI-only.
- **Ref:** `a32c042` (APP_NAME lever), `9f37609` (everything else). Playwright
  `creator-wizard-happy-path` and `smoke` re-run green post-batch (smoke's own assertions changed in
  `9f37609`) — see close-out Step 2.

---

### AH-030 · Contract step: duplicate heading removed

- **Status:** Landed
- **Date:** 2026-07-12
- **Why:** "Sign the master agreement" rendered twice on the same screen — the page-level title and a
  redundant component-level heading inside `ClickThroughAccept`.
- **What:** Removed the component's own `<h2>` (the page-level title is retained as the single
  heading), demoted the explanation paragraph to sub-text, dropped the now-orphaned CSS rule.
- **Touched:** `apps/main/src/modules/onboarding/components/ClickThroughAccept.vue`.
- **Decisions:** none beyond the obvious dedupe — no i18n key change, no gate/behavior change.
- **Ref:** `27d6017`.

---

### AH-029 · Master agreement replaced with Catalyst Creator T&Cs

- **Status:** Landed
- **Date:** 2026-07-12
- **Why:** The click-through agreement still named the old entity and governing law; replaced with
  the real contracting terms (Catalyst Performance Ltd, England & Wales) per the supplied PDF.
- **What:** Full rewrite of the server-rendered `master-agreement.en.md` — new contracting entity,
  new governing law, restructured to a 10-clause layout, real privacy-notice URL. Two
  content-coupled Pest tests updated to match the new title/section headings. The AH-028 scroll gate
  and the accept endpoint are untouched by this change.
- **Touched:** `apps/api/resources/contracts/master-agreement.en.md`,
  `apps/api/tests/Feature/Modules/Creators/ContractTermsEndpointTest.php`,
  `apps/api/tests/Feature/Modules/Creators/ClickThroughContractRecordTest.php`.
- **Decisions:** version deliberately held at `1.0` (Pedram's call) — new acceptances snapshot the
  new text; existing signed contracts keep their immutable Engine C/Ireland snapshot untouched
  (the DB snapshot, not the version label, is the authority — no code compares version strings
  today). Consequence, logged as mandatory tech-debt: the label `"1.0"` now denotes two distinct
  legal documents, and there is no re-consent flow — pre-swap signees will never see the new terms
  unless a future feature prompts them. This review covers the **engineering** (snapshots immutable,
  tests updated, gate intact, Playwright happy-path re-verified green); whether holding the version
  at 1.0 and skipping re-consent for existing signees is legally sound is explicitly **a question for
  counsel**, not something this review blesses.
- **Ref:** `7eb5f20`.

---

### AH-028 · Scroll-to-end gate on the click-through master agreement

- **Status:** Landed
- **Date:** 2026-07-09
- **Why:** A creator could accept the master agreement without scrolling past the visible fold —
  a weak attestation for a binding e-sign-equivalent acceptance.
- **What:** The acceptance checkbox disables until the terms region is scrolled to within 4px of
  the bottom (`SCROLL_END_THRESHOLD`, zoom-tolerant); content that doesn't overflow auto-satisfies
  on mount (a mis-measure can never permanently block onboarding — branch spec-pinned). Help text
  swaps keys by gate state. Client-side only — the accept endpoint and backend are untouched and
  unaware.
- **Touched:** `apps/main` `ClickThroughAccept.vue` + spec, 24× `creator.json`
  (`click_through_scroll_hint`), parity green. Closure commit also touched
  `creator-wizard-happy-path.spec.ts` (E2E now genuinely scrolls the terms region — the real
  master-agreement markdown overflows the region, so the happy-path exercises the actual gate, not
  the auto-satisfy branch).
- **Decisions:** shipped as a UI batch despite the one additive i18n key — retroactively accepted
  exception (parity green, single key), recorded rather than normalized: new keys still flag
  mid-batch per the mode guidance. The key initially carried English fallback in 10 locales (`bg`,
  `el`, `et`, `fi`, `ga`, `hu`, `lt`, `lv`, `mt`, `ro` — the AH-001 debt class propagating via the
  same generation path) — fixed in the closure commit with a machine-translation baseline; the
  pre-existing neighboring fallbacks in those same files remain AH-001 debt, untouched.
- **Ref:** `9fce489` (feat) + `ddeed88` (closure: auto-satisfy branch spec, MT fill, Playwright
  scroll fix) + this docs commit.

### AH-027 · Creator completeness % on the agency discover detail

- **Status:** Landed
- **Date:** 2026-07-09
- **Why:** Agencies evaluating a creator on discover couldn't see the completeness signal the
  platform already computes and exposes.
- **What:** The discover-detail page renders the creator's `profile_completeness_score` as a `%`
  bar. Read-only display of an already-on-the-wire field (`CreatorPublicProfileResource` has exposed
  it since the AH-009-era) — no resource, gate, or score-formula change. Bar colour keys `>= 100`
  cosmetically (cleared in the AH-026 sub-100 sweep).
- **Touched:** `apps/main` discover detail page + spec (`modules/discover/pages/DiscoverProfilePage.vue`
  - `DiscoverProfilePage.spec.ts`), 24× `app.json` (`app.discover.detail.completeness`), parity green.
- **Decisions:** rode the AH-026 session by explicit go-ahead but logged separately (separate surface,
  separate entry — the house rule). No BE diff.
- **Ref:** `ffe4ab9`.

### AH-026 · Onboarding floor + score reweight + wizard % display

- **Status:** Landed
- **Date:** 2026-07-09
- **Why:** Region wasn't in the profile floor (so a creator could reach submit and only then
  discover it was missing), optional profile fields earned nothing (the completeness meter didn't
  move as they filled bio/accent/contact), and the wizard chrome showed only "Step X of N" — never
  the completeness % that agencies actually see on discovery.
- **What:** Six-field profile floor (region joined `display_name`/`country`/`primary_language`/
  `categories`/`avatar`), mirrored 1:1 FE↔BE. The profile unit's 25 points split into an
  all-or-nothing **floor (13)** + per-optional **credit (12)**: bio 4, accent 2, phone 2, whatsapp
  2, street 1, postal 1 — the gate boolean stays floor-only, the score numerator goes partial
  (`profileEarned()`). Step-2 forward gate aligned to the full floor. Both wizard chromes + the rail
  now surface `profile_completeness_score` as a `%` alongside "Step X of N" (static prop threaded
  past the animation state machines — no competing calculation). Review-step copy rewritten to the
  explicit two-signal model ("everything required is done; add more to strengthen"). Mandatory fields
  marked with `*`; bio/accent gained an "Optional" hint. One-shot `creators:recompute-completeness`
  artisan command (idempotent, `--dry-run`, count summary) for the cohort. New source-scan
  floor-mirror parity spec pins the six tokens once and asserts both `isProfileComplete` (BE) and
  `floorMet` (FE) reference exactly that set.
- **Touched:** `apps/api` (`CompletenessScoreCalculator` floor+`profileEarned`, `RecomputeCreatorCompleteness`
  command + test, calculator/endpoint/flag-off/reopen fixtures gain region+optionals), `apps/main`
  (`ProfileBasicsForm` floor+required markers, `Step2ProfileBasicsPage` full-floor gate,
  `CreatorProfilePage`, both `AnimatedWizardChrome*` + `OnboardingProgress` + `OnboardingLayout` %
  display, `Step9ReviewPage` two-signal copy + submit-ready colour re-key, `WelcomeBackPage` docblock,
  floor-mirror parity spec, FE specs, Playwright happy-path region fill), 25 locale files (i18n
  done-gate, parity green).
- **Decisions:**
  - **D1/D2 (floor):** region is a floor field on both sides (FE trimmed-non-empty; BE `!== null`,
    and the SPA already maps empty→null so the two agree). Validation requests stay
    `sometimes|nullable` — the floor gates, validation doesn't, so partial saves keep working.
  - **D3 (backfill-on-next-edit, no grandfather clause):** a `pending`/`rejected` creator with
    `region = null` hard-blocks on their next profile edit until region is filled (deliberate forced
    backfill — one field, self-healing, the block always names the fillable field). Approved creators
    stay soft-warn (unchanged). No creator is permanently stranded.
  - **D4 (gate/score separation):** `stepCompletion['profile']` stays floor-only; the score awards
    partial optional credit. **Q2 = award-regardless:** optional credit is granted independently of
    floor state (the meter must never lie by refusing to move). Denominator, hidden-step exclusion,
    and every other unit's ratio are untouched — a fully-complete creator still scores 100, pinned by
    the sum-to-25 sub-split assertion + the sum-to-100 weights pin.
  - **Q1 (WelcomeBack drift, accepted):** under D4, `score > 0` now means "any engagement, including
    optionals" — a creator who typed only a bio gets "Welcome back / resume", which is the correct
    experience. Docblock updated; the alternative (re-deriving first-time-ness from structural
    signals) was rejected as fragile.
  - **Q3 (durable parity):** source-scan spec pins the six floor tokens once; both sides must contain
    exactly that set — a legitimate floor change is a one-line fixture edit, a silent one-sided edit
    is a red. Break-revert verified both directions.
  - **Sub-100-submit sweep (negative):** no gate anywhere reads the completeness score; the review
    submit gate is `incompleteSteps.length === 0`. The `Step9ReviewPage` bar colour was re-keyed from
    `score >= 100` to submit-readiness so "success" tracks done-ness, not perfection. Dashboard bar
    left as-is (genuinely just a progress bar). Recorded in `tech-debt.md`.
  - **D7/D8:** submit-gate unit membership (social ≥1, portfolio ≥1, contract) and the admin
    approval path are untouched — approval is never gated on completeness (the existing recorded
    decision, now reinforced in `tech-debt.md`).
- **Post-deploy step (D5):** after this ships, run `php artisan creators:recompute-completeness` once
  (optionally `--dry-run` first) so every existing creator's persisted `profile_completeness_score`
  moves to the new formula. Idempotent — safe to re-run; a second run reports 0 changes. Recorded as
  an operational obligation in `tech-debt.md` (there is no scheduled recompute).
- **Ref:** AH-026 feat+docs pair (push HELD).

### AH-025 · Production admin bootstrap command (admin:create)

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** No safe way to mint a production platform admin (the seeder is dev-guarded).
- **What:** New `admin:create` artisan command — email as argument, names prompted or passed,
  password generated server-side (`Str::password(24)`) and printed once, never accepted as an
  argument. Minted admin gets `mfa_required => true` (first sign-in forces TOTP enrollment —
  flagged INTO the Sprint-13 admin MFA posture, not around it). Deliberately NOT idempotent:
  an existing email (incl. soft-deleted, via `withTrashed()`) is refused — the command can
  never rotate a live password or escalate an existing account. No HTTP invocation path
  exists. User-creation audit rides the `Audited` trait (`actor_type='system'` in console
  context).
- **Touched:** `apps/api` `Console/Commands/CreateAdminUser.php` + `CreateAdminUserCommandTest.php`.
- **Decisions:** refuse-don't-upsert on duplicate email (§5.6 posture inverted deliberately —
  refusal IS the safe idempotency here); generated-not-supplied password (no shell-history
  leak); `AdminProfile`/`AgencyMembership` rows not independently audited — pre-existing trait
  coverage posture, recorded in tech-debt.
- **Ref:** `2e197a7`.

### AH-024 · Reset-password route moved to match the emailed link

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** Emailed reset links pointed at `/auth/reset-password`; the SPA registered
  `/reset-password` — links landed on an unmatched route.
- **What:** SPA route moved to `/auth/reset-password`.
- **Touched:** `apps/main` `auth/routes.ts` + `ResetPasswordPage.spec.ts`.
- **Decisions:** second instance of the emailed-URL↔SPA-route mismatch class (after
  verify-email) — a backend-minted-URL↔registered-route parity test is now logged as
  tech-debt (the two-strikes ratchet).
- **Ref:** `1d9a85c`.

### AH-023 · Surname at sign-up + account-creation details on three surfaces

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** Sign-up collected no surname, and the account-creation identity (name/email)
  wasn't visible anywhere post-signup.
- **What:** `users.last_name` (nullable varchar(160)); `last_name` required at sign-up
  (`min:1,max:120`); read-only account-details sections on creator self-profile, admin
  creator detail (`admin_attributes` block), and connected-agency roster detail
  (`account_name`/`account_last_name` beside the email, same relation-exists privacy basis,
  in-source "NEVER on discover" comment). Discover surfaces got nothing — proven by the
  exact-keyset discovery assertion + the untouched AH-005 negative assertions.
- **Touched:** `apps/api` (migration, `SignUpRequest`, `SignUpService`, `User`, 3 resources,
  tests), `packages/api-client` (4 type files), `apps/main` (sign-up, profile, roster +
  specs), `apps/admin` (detail + spec), 96 locale files (i18n done-gate, parity green).
- **Decisions:** column nullable for pre-existing accounts (render "—", no backfill possible —
  tech-debt); sign-up contract change is safe because the SPA form ships in the same deploy;
  column width 160 vs validation max 120 is a recorded cosmetic inconsistency (validation is
  the effective bound).
- **Ref:** `ce3bbda`.

### AH-022 · Full ISO country/language pickers + creator accent field

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** 58 hand-picked countries and 24 languages were too narrow for a worldwide creator
  base.
- **What:** Full ISO 3166-1 (250) country and ISO 639-1 (174) language pickers; new nullable
  `creators.accent` (free text, `max:80`, deliberately not an enum) shown on discover
  cards/profile, roster list/detail, and admin — an explicit product ask, same sensitivity
  class as `primary_language`. Completeness-inert.
- **Touched:** 86 files — `packages/api-client` (`countries.ts` new, `locales.ts`, types),
  `apps/api` (migration, `Locale` enum, 2 requests, 5 resources, 2 controllers, model,
  tests), `apps/main`, `apps/admin`, 48 locale files (parity green).
- **Decisions:** AH-001 reinterpretation, structural intent preserved: the two-concept locale
  split becomes three — enum cases stay the 24 EU languages (agency/brand content validation,
  unchanged), `UI_LOCALES` stays 24 (render set, unchanged), new `WORLD_LANGUAGES` (174)
  validates creator spoken-language metadata only, pinned by a §5.25 parity spec
  (`locales.spec.ts` + `LocaleEnumTest`). `00-MASTER-ARCHITECTURE.md` §13 updated to the
  three-concept model in this pass. Accent sits outside the AH-005 contact block (profile
  data, not contact data).
- **Ref:** `7faeff8`.

### AH-021 · Review page numbering + account step surfaced

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** The wizard sidebar numbered steps; the review page didn't match.
- **What:** Numbered review rows incl. "Account created". UI-only.
- **Touched:** `apps/main` `Step9ReviewPage.vue` + spec.
- **Ref:** `b6f49eb`.

### AH-020 · Verify-email pending page — email carry on the unverified bounce

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** After an unverified sign-in bounce, the pending page showed no address and resend
  failed.
- **What:** Sign-in/TOTP redirects carry `?email=`; the page uses it as display/prefill only
  (auth-store fallback), grants nothing. Resend endpoint untouched — the §5.9 silent-204
  enumeration posture stands.
- **Touched:** `apps/main` `SignInPage.vue`, `VerifyTotpPage.vue`,
  `EmailVerificationPendingPage.vue` + 2 specs.
- **Ref:** `80ac4c0`.

### AH-019 · Category taxonomy 16→28 + chip-grid picker with select-all

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** The 8-category cap and cramped dropdown didn't fit the taxonomy.
- **What:** 12 new categories (28 total), backend cap `max:8`→`max:28` with the enumerated
  whitelist in both requests, dropdown→chip grid + "Select all" (selects exactly the 28-key
  registry).
- **Touched:** `apps/main` (`ProfileBasicsForm.vue`, roster page), `apps/api` (2 requests),
  `apps/admin` field-edit config, 48 locale files (parity green), category specs.
- **Decisions:** FE has no numeric cap — structurally bounded by the 28-chip registry (no
  free entry); per §5.25 honesty the number 28 is enforced backend-only. Admin↔backend
  registry parity is spec-pinned; main↔backend is NOT — logged as tech-debt, and the
  overclaiming in-code comment corrected in this batch's closure commit.
- **Ref:** `6cf26cb` + `d0462a2`.

### AH-018 · Verify-email :app placeholder fix

- **Status:** Landed
- **Date:** 2026-07-08
- **Why:** Verification emails rendered a literal `:app` in the greeting/ignore lines.
- **What:** Passed the `app` parameter to the two `trans()` calls; regression now pinned by a
  `not->toContain(':app')` assertion in the existing §5.3 real-rendering test (closure
  commit, break-revert verified).
- **Touched:** `apps/api` verify-email Blade template; rendering test (closure commit).
- **Ref:** `be87dc0` + `10ac480` (closure commit).

### AH-017 · Creator assignments mobile card redesign

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** The creator assignments list was cramped on mobile.
- **What:** Restructured the assignment cards for mobile; View action → outlined, Decline → red
  outlined.
- **Touched:** `apps/main` `modules/creators/pages/CreatorAssignmentsPage.vue`.
- **Decisions:** UX polish only.
- **Ref:** commit-pair (this entry's landing commit).

### AH-016 · Creator mobile Profile-nav bootstrap fix

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** On a deep-link or hard refresh into a creator route, the Profile nav item could be
  missing because the onboarding store hadn't bootstrapped (nav visibility depends on it).
- **What:** `CreatorDashboardLayout` now bootstraps the onboarding store on mount, so the
  Profile nav item renders reliably on deep-link/refresh. Bugfix, not polish.
- **Touched:** `apps/main` `modules/creators/layouts/CreatorDashboardLayout.vue` + spec.
- **Decisions:** a nav-visibility correctness fix — not an auth gate (nav visibility ≠ route
  authorization, which the guards still enforce independently).
- **Ref:** commit-pair (this entry's landing commit).

### AH-015 · Portfolio inline collapsible drawer + preview download

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** The portfolio "View all" opened a popup; a download affordance was missing from the
  preview lightbox.
- **What:** Replaced the View-all popup with an inline collapsible drawer on the roster + discover
  profile surfaces, and added a download icon to the top-left of the `PortfolioGallery` preview
  lightbox. Unrelated to messaging.
- **Touched:** `packages/ui` `PortfolioGallery.vue`, `apps/main` `roster/CreatorDetailPage.vue` +
  `discover/DiscoverProfilePage.vue`.
- **Decisions:** UX presentation only — no resource-shape or authz change (download inherits the
  existing AH-004 presigned-GET path).
- **Ref:** commit-pair (this entry's landing commit).

### AH-014 · Campaign ChatPanel parity with relationship chat

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** After the relationship-chat redesign (AH-013), the campaign `ChatPanel` looked
  inconsistent — older bubbles, no composer parity.
- **What:** Restyled campaign chat bubbles + timestamps and brought the composer to parity
  (inline send, `+` file menu, auto-scroll, desktop-only Enter-to-send). Campaign messaging
  surface only — the relationship spine is unaffected.
- **Touched:** `apps/main` `modules/messaging/components/ChatPanel.vue`.
- **Decisions:** styling/composer parity only — no change to campaign messaging behavior, data, or gate.
- **Ref:** commit-pair (this entry's landing commit).

### AH-013 · Two-pane (WhatsApp Web) messaging + real contact avatars

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** Relationship messaging was a single-column inbox→thread navigation; on
  desktop it didn't read like WhatsApp Web, and contact/inbox rows showed initials only.
- **What:**
  - **Two-pane shell** on both inboxes (list left, active thread right) via route nesting —
    `messages.thread` / `creator.messages.thread` are now children of their inbox routes
    (same URLs, full guard chain preserved) + a `meta.wide` flag driving a fluid container.
  - **Active-row highlight** (`RelationshipInbox.activeId`) for the two-pane selection.
  - **Real contact avatars** (resolves the AH-012 D5 deferral): new shared `ContactMediaUrl`
    resolver (passthrough absolute URL / sign a bare S3 key / null on non-S3 disk); both
    inboxes gain `creator.avatar_url` / `agency.logo_url` and both picker endpoints gain the
    same — additive response-shape change, api-client types updated, backend assertions added.
  - **Thread-view redesign** (`RelationshipThreadView`): back-chevron header, inline send,
    `+` attach menu (file picker + link dialog), 100dvh, desktop-only Enter-to-send, auto-scroll.
  - New i18n key `app.messaging.relationship.selectConversation` across all 24 locales (parity green).
- **Touched:** `apps/api` (both relationship message controllers, `MessageableContactsController`,
  `MessageableContactsFinder` eager-load, new `Support/ContactMediaUrl`, tests), `packages/api-client`
  (`messaging.ts` row types), `apps/main` (auth/creators routes nesting + `wide`, both `*MessagesPage`,
  both thread pages, `RelationshipInbox`, `RelationshipThreadView`, `CreatorDashboardLayout` wide
  container, locales + specs).
- **Decisions:** route-nesting (not new URLs) for two-pane so guards/URLs are unchanged; avatar URLs
  additive (no field removed); `ContactMediaUrl` is the single shared resolver (passthrough/sign/null).
  Gate untouched — `MessageableContactsFinder` changed only its eager-load, not `scopePermitsMessaging`.
- **Ref:** commit-pair (this entry's landing commit).

### AH-012 · WhatsApp-style new-conversation flow (symmetric contact picker, both sides) + provisioning fix

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** AH-010b shipped the messaging inbox but left **no way to start a
  conversation** — the agency had only a roster-detail "Message" shortcut, the
  creator had no initiation surface at all, and an empty inbox was a dead end.
  This adds a WhatsApp-style "new chat → gate-filtered contact list → thread" entry
  point on **both** sides, and corrects a provisioning bug where opening a thread
  (not sending) persisted a row — contradicting the code's own "lazy on first send"
  docblocks.
- **What:**
  - **D1 · Provisioning deferred to intent.** A gate-passing GET with no existing
    thread now returns a **transient (unsaved) thread** — no row persisted on open.
    The row materializes on the **first sent message** OR an **attachment upload**
    (both are intent; opening alone never provisions). This corrects the live
    behaviour and makes the roster "Message" shortcut stop creating ghost threads.
  - **D2 · Inbox shows only threads with ≥1 message.** Both inbox queries filter to
    threads that have at least one message — a safety net against empty ghosts.
  - **D3 · One shared messaging predicate, gate ⇔ picker.** Extracted
    `AgencyCreatorRelation::scopePermitsMessaging()` (roster + non-blacklisted); the
    single-pair `CreatorPolicy` gate is **re-sourced** through it, and the new
    set-valued `MessageableContactsFinder` shares the same scope (+ the identical
    creator-`approved` leg) so the two forms **cannot drift**. An agreement test
    pins it (every set member passes the gate; every gate-reject is absent), and the
    break-revert (diverge the finder's predicate → agreement fails) is **proven**.
    The `CreatorPolicy` spine tests stay green-unchanged (the preservation proof).
  - **D4 · Two net-new gate-filtered endpoints** (controller homes in Messaging):
    `GET /creators/me/messageable-agencies` → `{ulid, name, logo_path}` (unpaginated,
    small list); a dedicated `GET /agencies/{agency}/messageable-creators` →
    `{ulid, display_name}` with name search + pagination (NOT a flag on the
    display-oriented roster endpoint — that one deliberately includes
    blacklisted/prospect/non-approved).
  - **D5 · Avatars:** initials fallback on the agency picker (no per-row signed-URL
    minting — the roster-index N+1 judgment); the creator side gets agency
    `logo_path` free. Real creator avatars on the agency picker were **deferred** —
    now **resolved by AH-013** (the shared `ContactMediaUrl` resolver + per-row
    `avatar_url` on the picker; per-row signing is acceptable on the bounded,
    paginated picker list).
  - **D6 · Search + pagination** on the agency-side creator picker (simple
    case-insensitive `LIKE` on `display_name`); creator-side agency list is small,
    so unpaginated.
  - **D7/D8 · Shared `ContactPicker` surface + entry points.** A presentation-only
    picker reusing `RelationshipInbox`'s row shape; a "New conversation" button in
    each inbox header **and** an injected "Start a conversation" empty-state CTA
    (the dead end). The CTA action is injected per side (creator →
    messageable-agencies, agency → messageable-creators), not hardcoded.
  - **i18n:** new strings (picker title, search placeholder, CTAs, empty states) →
    `en` → 24-locale regen → **parity green**.
- **Touched:**
  - BE: `AgencyCreatorRelation` (shared scope), `CreatorPolicy` (re-sourced gate),
    `MessageableContactsFinder` (new), `MessageableContactsController` (new) + routes,
    `RelationshipMessageService` (transient thread + page/meta/mark-read guards),
    both `*RelationshipMessageController`s (open=transient, send/attachment=provision,
    inbox ≥1-message filter).
  - FE: `ContactPicker.vue` (new), `RelationshipInbox.vue` (empty-state CTA),
    both `*MessagesPage.vue` (header button + picker wiring), api-client
    `messaging.ts` types (+ nullable transient thread-meta `id`) and
    `relationshipMessaging.api.ts` methods.
  - Tests: `MessageableContactsAgreementTest` (D3 proof + break-revert),
    `MessageableContactsApiTest`, additions to `RelationshipMessageApiTest`
    (no-provision-on-open both sides, transient mark-read, open-then-send,
    attachment-upload provisions, inbox ghost-hiding), `ContactPicker.spec.ts`,
    `RelationshipInbox.spec.ts` (CTA emit).
  - Docs: this entry + the attachment-orphan tech-debt note (below).
- **Decisions:** D1–D8 above; Q1 — attachment-upload provisions (intent), the
  uploaded-then-abandoned orphan is **logged** (not resolved by D2) in
  `tech-debt.md` (S3-hygiene family); Q2 — both controllers home in Messaging,
  creator route keeps its `creators/me/*` prefix; Q4 — picker lists all messageable
  contacts incl. those with an existing thread (the UNIQUE pair routes into it).
- **Gates:** BE `Messaging|CreatorPolicy` 125 passed; FE messaging specs 28 passed;
  `@catalyst/main` typecheck + lint clean; `@catalyst/api-client` typecheck clean;
  verify-locale parity green across all 23 non-`en` locales.
- **Ref:** AH-012 kickoff + approval; two-commit pair — `68e0266` (feat) + this
  docs commit. Spot-check passed (predicate-agreement + both-ways break-revert,
  no-provision-on-open, transient-meta id-null safety, inbox ≥1-message filter).

### AH-011 · Onboarding architecture-test cleanup (two pre-existing reds)

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** Two source-scan architecture tests were red on `main`, both fallout
  from recent onboarding work (surfaced — not caused — by the AH-010b suite run).
  Standalone cleanup so the FE arch gates are honest again.
- **What:**
  - **`no-hard-coded-colors` (AH-007 fallout):** `AnimatedWizardChromeMobile.vue`
    carried a literal `--chip-active: <hex>` for the active-step "go" green. Traced
    the value: it is exactly `semantic.success[500]` and already exposed as the
    Vuetify `success` theme color (both modes) — same value AND role. Replaced the
    literal with `rgb(var(--v-theme-success))` (no palette addition needed; mapping
    to "nearest" was explicitly avoided). The hex must also stay out of the
    surrounding comment — the scan reads raw text.
  - **`form-error-pattern` (AH-009 fallout):** `Step2ProfileBasicsPage.vue` was on
    the 422-binding allowlist but had dropped its `extractFieldErrors` import. Traced
    where step-2's 422s surface now: the AH-009 profile-edit extraction moved the
    whole body into the host-agnostic `ProfileBasicsForm` (shared by the wizard page
    AND `/creator/profile`), and the binding (`extractFieldErrors` + per-field
    `error-messages` + the `ApiError` catch) moved with it. So this is a genuine
    relocation, not a silent drop: the page comes **off** the allowlist and
    `ProfileBasicsForm` (which now carries the binding) goes **on**.
- **Touched:** `apps/main/src/modules/onboarding/components/AnimatedWizardChromeMobile.vue`,
  `tests/unit/architecture/form-error-pattern.spec.ts` (allowlist swap).
- **Decisions:** Use the existing `success` token (value+role match), not a new one.
  Allowlist relocation is valid because the binding is pointed-at in `ProfileBasicsForm`
  (the invariant the test guards) — confirmed by the page's per-field-422 runtime spec
  still passing through the extracted form.
- **Ref:** this cleanup pair (fix + docs). Both `no-hard-coded-colors` and
  `form-error-pattern` green; `Step2ProfileBasicsPage` spec (10 tests, incl. the
  through-the-form 422 binding) green.

### AH-010b · Relationship messaging — WhatsApp-shaped inbox + thread (frontend)

- **Status:** Landed (push held for final spot-check)
- **Date:** 2026-06-29
- **Why:** AH-010a shipped the backend spine; AH-010b is the surface — a
  WhatsApp-shaped 1:1 inbox + thread for the connected agency↔creator DM, on the
  existing 15s poll (D7, NOT realtime).
- **What:** A net-new conversations surface, both sides, with zero new chat engine.
  - **Generic engine reuse (zero blast radius):** the thread runs on the now-generic
    `useMessageThread<TMessage, TMeta, TSend>` (campaign defaults unchanged) via new
    relationship transports. The 5 `ChatPanel` + the `useMessageThread` campaign
    specs stay green — the engine was generalized, not forked.
  - **Inbox (D8):** a shared, direction-agnostic `RelationshipInbox` (avatar, name,
    last-message preview, timestamp, unread badge). Both pages normalize their own
    rows to one item shape — agency keyed by creator, creator keyed by agency. A 45s
    poll refreshes unread badges.
  - **Thread (D7/D10):** `RelationshipThreadView` — bubbles (own-right/theirs-left),
    per-message sender name on incoming (Q4 — the creator sees which agency member
    wrote each line), HH:mm timestamps, file + link attachments (D4), composer with a
    client-side `http(s)`-only link guard. The **two-state read tick reads straight
    from `read_by_counterparty`** (server truth, never a client guess): single check =
    sent, double check (primary) = read; no tick on incoming.
  - **Entry points (D9, Q5 symmetric):** creator top-level `/creator/messages`
    - thread, "Messages" nav in both the desktop topbar and the AH-007 mobile
      bottom-nav; agency top-level `/messages` + thread (pinned into the
      `requireAgencyUser` arch-test) and a roster-detail "Message" shortcut whose
      visibility mirrors the backend gate (approved + roster + non-blacklisted).
  - **i18n done-gate:** en `app.messaging.relationship.*` + `nav.messages` +
    `availability.creatorNav.messages`, then the full **23-locale fill** (app +
    availability + notifications) — the locale-parity gate is genuinely green
    (`{sender_name}` placeholder preserved across every locale).
- **Touched:** new `apps/main/src/modules/messaging/{components,pages}/Relationship*`
  (+ specs), `relationshipMessaging.api.ts`, generic `useMessageThread`,
  `creators/routes.ts` + `auth/routes.ts` (+ guard arch-test), `CreatorDashboardLayout`
  - `AgencyLayout` nav, `roster/CreatorDetailPage`, notification registry/union/en
  - parity specs, 23 locales × {app,availability,notifications}.json.
- **Build assertions met:** new inbox/thread/transport specs green; the 5 campaign
  `ChatPanel` + `useMessageThread` specs untouched; the agency-route guard spec
  updated + green; locale-parity + notifications-parity green; typecheck + lint clean.
- **Ref:** this FE pair (feat + docs). Backend is AH-010a (`2656e5a`); push held for
  the final spot-check before both ship.

### AH-010a · Relationship messaging — backend spine + gate + attachments + notifications

- **Status:** Landed (push held for AH-010b sequencing)
- **Date:** 2026-06-29
- **Why:** A connected agency and an approved creator had no way to talk outside a
  campaign. AH-010 adds 1:1 direct messaging (WhatsApp-shaped, AH-010b is the FE)
  gated by the relationship — so a blacklisted/declined/prospect agency cannot DM,
  consistent with the AH-005 contact-visibility posture but stricter.
- **What:** A backend relationship-messaging layer built **alongside** campaign
  messaging, not on top of it.
  - **Mirrored spine (Q1, deliberate duplication-debt):** `relationship_threads`
    (`UNIQUE(agency_id, creator_id)`) / `relationship_messages` /
    `relationship_message_read_receipts` + `RelationshipMessageService`. NOT shared
    with the `messages` table / `MessageService` — the campaign `messages.thread_id`
    FK forbids it without a campaign-path change (AH-010 Step-0). Consolidation
    trigger logged in tech-debt.
  - **Gate (D2, load-bearing):** `CreatorPolicy::canMessageRelationship` —
    approved creator + roster + non-blacklisted + active membership/ownership.
    Built from a new status-aware relation query, NOT `canSeeContactDetails`/
    `hasNonBlacklistedRelation` verbatim. Break-revert verified: loosening to the
    not-blacklisted-only predicate fails the declined/prospect/pending/external/
    non-approved specs; reverted.
  - **Attachments (D4):** thread-keyed presigned files + net-new http/https links
    (`javascript:`/`data:` rejected); **synchronous on-complete EXIF strip**
    (reuses `PortfolioImageProcessor`, 25 MB / 50 MP) before any row or signed URL
    — undecodable image → clean 422, not a 500.
  - **Notifications (D5):** two dual-recipient `NotificationType` +
    `AuditAction` verbs. The AuditAction verbs are **inert vocabulary** required
    only by the NotificationType↔AuditAction one-vocabulary tie (the Sprint-11
    `message.received_by_*` precedent) — **NO `audit_logs` row is written on a
    message send**, so a private DM leaves no content or metadata trail. Enforced
    by a guard test (`writes NO audit row on message send`). Recipient resolution
    is relationship-shaped (no assignment to deref).
- **Touched:** `apps/api/app/Modules/Messaging/*` (models, factories, services,
  controllers + concern, request, resource, routes), `database/migrations/2026_06_29_1000{00,01,02}`,
  `CreatorPolicy`, `AuditAction` + `NotificationType` enums (+ their tripwires),
  new `RelationshipMessage{Api,Attachment}Test` + `CreatorPolicyTest` cases.
- **Decisions:** Q1 mirror (duplication-debt + named consolidation trigger);
  Q2 roster-only gate (`external` unreachable + non-roster); Q3 synchronous
  on-complete EXIF strip; Q4 agency-org-level participants (`sender_user_id` per
  message); Q5 symmetric inboxes both sides; Q6 no extra agency eligibility.
  Digest deferred + virus-scan out (tech-debt). `deleted_at` present-but-unwritten.
- **Build assertions met:** full suite **1755 passed / 0 failed** (zero blast
  radius on campaign messaging), gate break-revert, EXIF genuinely stripped on a
  sent image, idempotent per-pair provisioning, PHPStan + Pint clean.
- **Ref:** `2656e5a` (feat) + this docs commit (the AH-010a pair). Kickoff +
  Step-0 in chat; duplication-debt in [`tech-debt.md`](../tech-debt.md). AH-010b
  (WhatsApp-shaped inbox) is the next, separate pair.

### AH-009 · Standalone creator Profile-edit page (reuses wizard steps 2 & 3)

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** The wizard was the only creator self-edit path (logged as the wizard-as-settings
  stopgap). Post-onboarding creators had no place to update their profile, socials, or
  portfolio.
- **What:** A "Profile" nav item (desktop topbar + AH-007 mobile bottom-nav) opens an editable
  `/creator/profile` page with two bordered sections — Profile basics (the extracted step-2 form
  body, incl. AH-005 contact) and Socials & portfolio (the two step-3 sub-sections mounted
  unmodified). Reuses the existing save paths (`PATCH /creators/me/wizard/profile` + the social /
  portfolio writes); a single `GET /creators/me` bootstrap hydrates everything. Step 2's `<v-form>`
  body was extracted into a shared `ProfileBasicsForm` (avatar, display name, bio + preview,
  country, region, contact fieldset, language, categories, the `updateProfile` save + 422 mapping)
  that exposes `save()` / `hydrate()` / `isPristine` + a `readiness` emit — **one form, two hosts**:
  the wizard host keeps its chrome (forward-gate, "Save and continue", nav to
  `onboarding.connections`, onMounted + guarded re-hydration watch); the profile host owns its own
  sections, snackbar, and the floor. New strings (`creatorNav.profile`, `creator.ui.profile.*`
  incl. the floor copy) authored in `en` and across all 24 locales (parity green).
- **Touched:** `apps/main` — new `onboarding/components/ProfileBasicsForm.vue` (extracted body),
  `onboarding/pages/Step2ProfileBasicsPage.vue` (now hosts the shared form, keeps wizard chrome),
  new `creators/pages/CreatorProfilePage.vue` (+ `CreatorProfilePage.spec.ts`),
  `creators/routes.ts` (+`creator.profile`), `creators/layouts/CreatorDashboardLayout.vue`
  (conditional nav item), 24× `creator.json` + `availability.json` locales.
- **Decisions:**
  - **Editable, extract-not-duplicate.** Not read-only; the wizard keeps working on the same
    shared `ProfileBasicsForm` body rather than a forked copy (break-revert verified: mutating the
    shared form fails a wizard step-2 spec).
  - **`requireAuth`-only on the creator shell — NOT `requireOnboardingAccess`** (that guard
    redirects every non-`incomplete` creator to the dashboard, which would have made the page
    unreachable for its own audience — the highest-risk finding of the inventory).
  - **Post-submission audience only** (pending / approved / rejected). The nav item is hidden for
    `incomplete` creators, and an `incomplete` deep-link is soft-redirected to
    `onboarding.welcome-back` **from the page** (not the guard, so the route stays `requireAuth`).
  - **D3 literal — sub-sections mounted unmodified.** `ConnectionsSocialSection` /
    `ConnectionsPortfolioSection` are mounted as-is; the page reacts to the store count rather than
    reaching into them, so removal warnings are **post-hoc** (fire when the count lands at zero).
  - **Lifecycle-aware completeness floor (host/page-owned — `CreatorWizardService` untouched).**
    The save paths recompute `profile_completeness_score` / `next_step` with no backend status
    guard, so the regression is guarded at the page edge, split three ways by lifecycle:
    - **pending / rejected → hard block** on profile-basics (`floorMet`, a 1:1 mirror of the
      backend `isProfileComplete`: display_name + country + primary_language + ≥1 category +
      avatar). Save is disabled and guarded, **including the avatar-delete-then-save path**
      (delete avatar → `avatar_path` null → `floorMet` false → blocked).
    - **approved → soft-warn, never block.** The edit is allowed (creator agency) but a warning is
      surfaced; the save genuinely proceeds.
    - **socials / portfolio (all states) → page-level warn at count-zero, never block.** Removing
      the last social / portfolio item is allowed; the page warns when the store count hits zero.
  - **Why approved is soft-warn, not free-edit (the load-bearing finding).** The gating
    read-question — _does anything read `next_step` / `profile_completeness_score` for an approved
    creator?_ — resolved to: `next_step` is **vestigial** post-approval (only wizard surfaces read
    it, all gated to `incomplete`), BUT `profile_completeness_score` is **agency-visible on
    discovery** — `CreatorPublicProfileResource` exposes it for `approved + is_discoverable`
    creators (the same fail-closed gate as the discovery / connection-request reads). So an
    approved creator's edit that lowers completeness lowers a signal prospective agencies see on
    discovery — which is precisely why approved is soft-warned rather than left to edit freely or
    silently. It is also surfaced on the creator's own dashboard (`CompletenessBar`, all statuses)
    and the admin list/detail.
  - **Backend status guard deferred to tech-debt** — the write endpoints have no
    `application_status` guard; this floor is the page-edge defense. (See also the recorded
    decision in `tech-debt.md`: a pending creator below 100% completeness is intentional, not a
    bug — approval is admin judgment, not a completeness gate.)
- **Ref:** `1dcd180` (refactor: extract `ProfileBasicsForm`) + `2ef98ed` (feat: standalone
  profile-edit page + floor).

### AH-007 · Creator platform mobile-responsive pass

- **Status:** Landed
- **Date:** 2026-06-29
- **Why:** The creator-facing surfaces (onboarding wizard + post-submit dashboard) were
  built desktop-first and were cramped/unusable on small viewports — the wizard's left step
  rail and the dashboard/wizard topbar controls overflowed, the framed wizard content was
  locked to a fixed-viewport inner scroll, and several step-2/step-3 fields broke layout on a
  phone.
- **What:** A frontend-only pass (`apps/main` + one `packages/ui` component), with mobile
  behaviour gated on Vuetify `smAndDown` so desktop is unchanged except where noted:
  - **Navigation reflow.** Onboarding topbar collapses the locale switcher + "Save and exit"
    into a right-side `v-navigation-drawer` hamburger (`v-app-bar-nav-icon`); the creator
    dashboard moves its primary nav from the inline topbar to a `v-bottom-navigation` bar.
  - **Mobile wizard chrome.** New `AnimatedWizardChromeMobile` — a horizontal top step rail
    (fixed edge-anchored number boxes: completed pinned left, upcoming pinned right, active
    centred; thin per-state rectangle outlines) with a snap → SVG-frame-draw → typewriter
    step transition, used instead of the desktop left-rail chrome on `smAndDown`.
  - **Full-height framed content.** The mobile frame moved from a fixed-viewport box with an
    inner scroll to a full-height card the _page_ scrolls; the SVG outline draws the card's
    full height (all four antennas still fire), the step rail is `position: sticky` under the
    app-bar, and a panel `ResizeObserver` (`syncFrameSize`) keeps the outline glued as content
    height changes.
  - **Per-step scroll reset.** Both chromes (desktop + mobile) reset the framed content to its
    top on each step change so a step never opens inheriting the previous step's scroll.
  - **Step-level fixes.** Step 2: the bio/profile preview wraps long unbroken strings
    (`overflow-wrap`/`word-break`) and the dial-code autocomplete no longer wraps to two lines
    on mobile focus. Step 3 social: a mobile-only stacked card with a view/edit toggle
    (read-only `@handle` → Edit reveals the input with Save/Cancel). Step 8: spacing between
    the agreement alert and "Save and continue".
  - **Light-mode logo regression fix.** The light-header logo darkening (added with the
    Catalyst-logo branding swap) used a `:global(...)` scoped rule that Vue's compiler
    collapsed to a bare `.v-theme--light { filter: brightness(0) }`, blacking out the whole
    dashboard in light mode; re-driven from a theme-bound class on the `<img>`.
  - **i18n:** added `app.nav.menu` (hamburger aria-label) and `creator.ui.wizard.actions.cancel`
    across all 24 locales.
- **Touched:** `apps/main` onboarding (`OnboardingLayout`, new `AnimatedWizardChromeMobile`,
  `AnimatedWizardChrome` scroll-reset only, `Step2ProfileBasicsPage` CSS, `Step8ContractPage`
  CSS, `ConnectionsSocialSection` mobile card + view/edit), creator dashboard
  (`CreatorDashboardLayout` bottom nav + logo theme-class fix), shared `packages/ui`
  (`PortfolioGallery` copy-link clipboard fallback), locales (`app.json` `nav.menu` +
  `creator.json` `actions.cancel`, all 24).
- **Decisions:** all mobile branches gated on `smAndDown` (desktop untouched) — _except_ the
  social **Remove** button, deliberately given an outline (`variant="text"` →
  `variant="outlined"`) on **both** desktop and mobile. Mobile wizard frame grows with content
  and the page scrolls (not an inner scroll box). The mobile social view/edit toggle is local
  UI state only and reuses the existing connect/remove flows verbatim (no payload change). Logo
  darkening re-expressed as a theme-driven class, not an ancestor `:global` selector (the
  scoped-CSS footgun that caused the blackout). Beyond-CSS notes: the `PortfolioGallery`
  `execCommand` copy fallback is `<script>` logic in a shared component (affects all consumers,
  copy-feedback only — no content change; desktop success path verified unchanged); no
  API/resource-shape changes; the AH-005 contact card is untouched.
- **Ref:** `dd7d93a` (mobile nav) · `d4e282b` (mobile chrome + polish) · `7e2c327` (scroll
  reset) · `0b176a3` (full-height frame) · `1da5dae` (light-mode blackout fix) + this docs
  commit.

### AH-008 · Portfolio link cards — copy-URL button

- **Status:** Landed
- **Date:** 2026-06-28
- **Why:** Portfolio link items showed their destination URL but offered no quick way to copy
  it — agencies/creators had to open the link and copy from the address bar.
- **What:** Added a copy-link affordance to link-kind cards in the shared `PortfolioGallery` —
  an icon button that writes the item's `externalUrl` to the clipboard and shows a ✓ tick for
  1.5 s. Surfaced on every gallery consumer (creator onboarding, roster detail, discover
  profile, admin creator detail) via a localized `copyLinkLabel` aria-label across all 24
  locales (main `creator.json` + admin `creators.json`); the consumer pages only pass the new
  label prop. No API or data-shape change.
- **Touched:** `packages/ui` (`PortfolioGallery` button + `PortfolioDrawer` label passthrough),
  `apps/main` (`ConnectionsPortfolioSection`, roster + discover detail pages), `apps/admin`
  (creator detail page), all 24 `creator.json` / `creators.json` locales.
- **Decisions:** the copy logic lives in the shared component, which stays i18n-free (label via
  prop); no persistence/analytics. The HTTP/iOS `execCommand` copy fallback was added later as
  part of the AH-007 mobile pass, not here.
- **Ref:** `185f1a9` (feat) + this docs commit.

### AH-006 · Finish the Connect→Add rename (step-3 social copy)

- **Status:** Landed
- **Date:** 2026-06-28
- **Why:** AH-003 renamed the social-account button Connect→Add (nothing actually connects —
  username entry only), but left the surrounding step-3 headings/labels saying "Connect," so the
  screen contradicted itself across all 24 locales.
- **What:** Swept the remaining "Connect"-family copy on the social-account CTA surface to "Add"
  framing — three value edits (`connections.title`, `social.title`, `social.description` in
  `creator.json`) — and regenerated across all 24 locales. Several locales (bg, el, et, fi, ga,
  hr, hu, lt, lv, mt, ro, sk, sl) had never received a translation for the social sub-keys at all;
  hr/sk/sl had Czech copy-pasted into their social block. All corrected in this pass.
- **Decisions:** copy-only, no behavior change; value-edit over key-rename to avoid keyset churn;
  unrelated "connect" uses left untouched (Stripe payout copy, agency connection-request
  workflow, discover connection-status badges, network-error strings, JS identifiers). Two
  agency-side social-metrics empty-state strings flagged as ambiguous but left untouched and
  recorded as tech-debt (social integration deferred).
- **Ref:** `33f2941` (feat) + `90832f4` (docs)

### AH-005 · Creator contact details (phone, WhatsApp, address) — connected-agency-visible

- **Status:** Landed
- **Date:** 2026-06-28
- **Why:** Connected agencies had no way to reach a creator directly — only the related User's
  email was exposed, and creators had nowhere to provide a phone, WhatsApp, or mailing address.
- **What:**
  - **Four optional plaintext fields on `creators`** — `phone`, `whatsapp`, `address_street`,
    `address_postal_code` (all nullable). The full mailing address composes from the existing
    `country_code` + `region` (city line) + the two new fields — no field stored twice. Plaintext,
    NOT the tax address's `encrypted:array` handling, because these are deliberately agency-visible.
  - **Agency-scoped visibility gate** — `CreatorPolicy::canSeeContactDetails(User, Creator, Agency)`
    = admin OR (active member of _that_ agency AND _that_ agency's relation is non-blacklisted). The
    "non-blacklisted relation" check is one shared `hasNonBlacklistedRelation()` primitive that
    `hasAgencyAccess()` also calls — one canonical blacklist rule. Agency-scoped, not a user-wide
    union: a multi-agency user on Agency A's page for a creator A has blacklisted sees no contact,
    even if their Agency B has a clean relation.
  - **Surfaced only on roster detail** (`AgencyCreatorDetailResource`, gated) + creator-owner
    self-read + admin view-only (base `CreatorResource` attributes — no `admin_attributes`
    duplicate, `EDITABLE_FIELDS` untouched so it stays creator-owned, not admin-editable).
  - **Explicitly withheld** from six surfaces (discover detail, discover list, roster list row,
    talent-pool member, campaign assignment, messaging thread list) — each by omission, each with a
    negative absence assertion that fails if a contact key is ever added there.
  - **Self-edited** via a "Contact details" sub-section on the profile wizard step; rendered to the
    connected agency as a Contact card on roster detail (shown only when the server surfaced it).
  - **i18n done-gate:** new contact-sub-section labels regenerated across all 24 locales; parity green.
- **Touched:** `apps/api` (`creators` migration + four columns, `Creator` model/factory,
  `UpdateProfileRequest`, `CreatorPolicy` gate + shared primitive, `AgencyCreatorDetailResource`,
  `CreatorResource` base attributes, `AgencyCreatorDetailController`), `packages/api-client`
  (`creator.ts` / `agency.ts` types), `apps/main` (`Step2ProfileBasicsPage` contact sub-section,
  roster `CreatorDetailPage` Contact card), locales, policy + withholding + render specs.
- **Decisions:** plaintext not encrypted (agency-visible by design); inline columns not a dedicated
  table; agency-scoped blacklist-aware gate (not the looser relation-exists); `region` reused as the
  city line (no duplicate city column); admin view-only (not the `EDITABLE_FIELDS` contract);
  distinct WhatsApp number (not a flag). Break-revert surfaced and fixed a `toHaveKey($key, $msg)`
  misuse that had silently neutered the withholding guards.
- **Follow-up — country-code dial selector (`1399ee3`):** the phone + WhatsApp contact inputs
  this entry added gained a searchable dial-code selector (a `v-autocomplete` of `+NN` codes,
  backed by new static `countries.ts` / `dialCodes.ts` data and a small `vuetify.ts` default),
  so the dial code is picked separately from the national number on `Step2ProfileBasicsPage`.
  Frontend + static data only — no `apps/api` / `packages/api-client` change, so the `phone` /
  `whatsapp` resource shape is unchanged.
- **Ref:** `5dc1e1f` (feat) + `e58dfec` (docs); dial-code follow-up `1399ee3`.

---

### AH-002 · Digest/invite email locale docblock + English-only decision

- **Status:** Landed
- **Date:** 2026-06-28
- **Why:** The `UnreadMessagesDigestMail` docblock falsely implied per-recipient locale handling,
  and the deliberate English-only disposition of the digest + agency-invite emails was unrecorded.
- **What:** Corrected the docblock to state the digest renders in the application default locale
  (`en`) for all recipients — no `->locale(...)` at the send site — and logged the English-only
  decision as tech-debt, including why the digest is harder to localize than a normal mailable: its
  lines are built with `__()` in console context inside `MessageDigestService` (`:204`/`:212`/
  `:220`) before the job is queued, so a future fix must localize at line-build time, not just chain
  `->locale()` at the send site. No behavior change; no test change.
- **Touched:** `apps/api/app/Modules/Messaging/Mail/UnreadMessagesDigestMail.php` (docblock only),
  `docs/tech-debt.md` ("Digest + agency-invite emails are English-only (deliberate)").
- **Ref:** `766d925` (docblock + tech-debt entry); this log reconciliation commit.

---

### AH-004 · Portfolio overhaul (schema + async image worker + drawer)

- **Status:** Landed
- **Date:** 2026-06-27
- **Why:** The portfolio was a thin, image-only path: small per-creator cap, no full-resolution
  download, raw EXIF-bearing originals served straight back, no link entries, and three separate
  resources each minting signed URLs with their own copy of the (missing) safety logic. It also
  presented inconsistently across the creator, agency-roster, agency-discover, and admin surfaces.
- **What:**
  - **`processing_status` lifecycle** (`processing` → `ready` / `failed`) on portfolio items —
    new enum + migration (`default('ready')` so all existing rows + link items are ready) + model
    cast + factory `processing()` / `failed()` states.
  - **Presigned image uploads** mirroring the proven video path: `POST portfolio/images/init`
    (presigned `PUT`) → client `PUT` with **progress + a client timeout** → `POST
portfolio/images/complete`, which dispatches the worker. Uniform **500 MB** ceiling for all
    file types; per-creator cap raised **10 → 30**.
  - **`ProcessPortfolioImageJob` + `PortfolioImageProcessor`** — an async worker that re-encodes
    the upload at **full resolution with EXIF stripped** (not the avatar downscale path),
    generates a 512px-max-edge thumbnail, and guards a **`MAX_MEGAPIXELS = 50`** decompression-bomb
    cap. On success → `ready`; on over-cap / corrupt input → `failed`. The 50 MP cap is a **matched
    pair** with the memory pins (below): a near-cap decode stays inside the 512 MB test / 768 MB
    worker envelope.
  - **Shared `PortfolioItemPresenter`** — the single source of truth that all **three** portfolio
    mint sites (`CreatorResource`, `AgencyCreatorDetailResource`, `CreatorPublicProfileResource`)
    now route through, so the **server-authoritative `ready`-gate lives in one place**: `view_url`,
    `thumbnail_view_url`, and `download_url` are minted **only** when `processing_status === ready`;
    otherwise null. A break-revert on this gate is the load-bearing spec.
  - **Download** = a presigned GET on the **same already-authorized resource** with
    `ResponseContentDisposition=attachment` (full-res source, never the thumbnail). It therefore
    **inherits each surface's view authz** and the same `ready`-gate — never a broader grant than
    view. Per-surface authz feature tests pin that a caller who 404s the resource never receives a
    `download_url`.
  - **Link portfolio items** — `POST portfolio/links` with http/https-only URL validation (XSS
    guard), surfaced as `ready`-by-definition items with an `external_url`.
  - **`PortfolioDrawer`** — one reusable `v-dialog` (the `ReviewDraftDrawer` pattern) wrapping
    `PortfolioGallery`, wired into all four surfaces with a "View all" affordance + processing
    spinner / failed-state overlays / download button.
  - **Deleting an item cleans up its S3 objects** (raw + thumbnail), including `failed` items whose
    raw object is unreachable behind the gate but would otherwise orphan.
  - **Memory pins (matched pair):** `composer test` runs at `-d memory_limit=512M`; the prod/dev
    `queue:work` worker is sized at `--memory=768` and documented in `local-dev.md`.
  - **i18n done-gate:** new `creator` (main) + `creators` (admin) strings — processing / failed /
    download / view-all labels and the add-link form — regenerated across all 24 locales;
    parity/placeholder/plural gates green.
- **Touched:** `apps/api` (`PortfolioProcessingStatus` enum, migration, `CreatorPortfolioItem`
  model + factory, `PortfolioImageProcessor`, `ProcessPortfolioImageJob`, `PortfolioUploadService`,
  `PortfolioController`, routes, the shared `PortfolioItemPresenter`, the three portfolio resources,
  `composer.json`), `packages/api-client` (`presigned.ts` progress/timeout, `types/creator.ts`),
  `packages/ui` (`PortfolioGallery`, new `PortfolioDrawer`, `index.ts`), `apps/main` (onboarding
  api/composable + spec, `ConnectionsPortfolioSection`, `PortfolioUploadGrid`, roster + discover
  detail pages), `apps/admin` (creator detail page), all `creator.json` / `creators.json` locales,
  `package.json`, `docs/runbooks/local-dev.md`, backend feature/job tests.
- **Decisions:** `MAX_MEGAPIXELS = 50` (not 100) to keep a near-cap decode inside the 512/768 MB
  envelope while still guarding the bomb line; download inherits view authz rather than being a
  separate (broader) grant; the legacy direct-multipart image endpoint is kept for the Playwright
  seed but bypasses the worker (recorded in tech-debt). Resume/multipart, presign-expiry handshake,
  and S3 storage-cost-at-scale remain deferred (tech-debt AH-004 carry-overs).
- **Ref:** `docs/reviews/ah-004-portfolio-overhaul-plan.md` (audited plan); tech-debt
  "Portfolio upload — resume / presign-expiry / storage cost (AH-004 plan carry-overs)" +
  its build-time addendum. Commit-pair: `7b62272` (feat) + `b0605be` (docs); pre-push
  reconciliation follow-up adds the corrected legacy-endpoint disposition + the AH-001 i18n
  completeness debt entry.

---

### AH-003 · Wizard slim + profile-basics polish

- **Status:** Landed
- **Date:** 2026-06-27
- **Why:** Sprint 10 (payments) and automated KYC aren't built, and KYC is manual today, so
  the Identity-verification / Tax / Payout steps collect nothing actionable yet — they made
  onboarding longer without value. Separately, the wizard hard-coded its step count (and a
  comment falsely claimed it rendered dynamically), "Connect" misled on form-only social, and
  the profile photo was circular.
- **What:**
  - **Reversible-hide of kyc/tax/payout** via a single static registry
    (`WizardStep::WIZARD_HIDDEN_STEPS`, mirrored by the TS `WIZARD_HIDDEN_STEPS`), held in
    lockstep by a 5.25 parity test. Hidden steps are excluded from the rail, numbering,
    completeness denominator, and the submit gate (so the always-required `tax_profile_complete`
    no longer dead-locks submit). Re-introduction = remove from the list (+ flip the kyc/payout
    Pennant flags ON). NOT a feature flag — it's a build-time "not ready yet" hide.
  - **Merged Social + Portfolio** into one "connections" step with the two kept as distinct
    sub-sections (backend keeps them as separate completion units; APIs/weights unchanged).
  - **Derived numbering/progress/geometry** from a single visible-step list
    (`useWizardSteps`) — removed `TOTAL_STEPS = 9`, the index maps, and the animated chrome's
    `/7`·`/8` divisors, and fixed the false "renders dynamically" comment. A future hide/show
    is now a one-line registry flip.
  - **Profile-basics polish:** photo rectangular (was circular, style-only); "Primary
    language" → "Native language" (label only, column unchanged); removed the "Other languages"
    onboarding input (the `secondary_languages` column + its roster/discover/detail/admin
    displays from AH-001 are untouched; the save payload omits the field so existing data is
    preserved); social CTA "Connect" → "Add" (empty) / "Edit" (added).
  - **i18n done-gate:** the changed/new `creator` strings regenerated across all 24 locales;
    the orphaned `creator.ui.wizard.fields.secondary_languages` key deleted from all 24
    (verified wizard-only first); parity/placeholder/plural gates green.
- **Touched:** `apps/api` (`WizardStep` enum + hidden registry, `CompletenessScoreCalculator`,
  `CreatorResource` bootstrap), `packages/api-client` (`wizard.ts` registry + parity spec),
  `apps/main` onboarding module (new `useWizardSteps`, merged `Step3ConnectionsPage` + two
  section components, `OnboardingLayout`, `OnboardingProgress`, `AnimatedWizardChrome`,
  `Step2ProfileBasicsPage`, `Step9ReviewPage`, `AvatarUploadDrop`, routes), all `apps/main`
  `creator.json` locales, unit + architecture specs, Playwright happy-path.
- **Decisions:** Q1 submit gate ignores `tax_profile_complete` while tax is hidden (the
  alternative is a literal deadlock) — **re-introduction obligation recorded in tech-debt**:
  Sprint 10 must backfill tax for creators who onboard during the hidden window, since tax is
  legally required before a first payout. Q2 static config (not Pennant); hidden takes
  precedence over the existing flag-based skip. Q8 the orphaned `secondary_languages` key is
  deleted from all 24 (parity forces all-24 anyway). D7 "Connect"→"Add", added→"Edit".
- **Ref:** kickoff "Creator onboarding + profile + portfolio reshape (AH-003 + AH-004)";
  tech-debt entries "Hidden onboarding steps (kyc/tax/payout) — Sprint-10-gated" + the AH-004
  upload-ceiling debt. Commit-pair (this entry's landing commit).

---

### AH-001 · EU locale support (24 languages) + persistence

- **Status:** Landed
- **Date:** 2026-06-27
- **Why:** The language switcher reset on every reload/login (a selected language never
  stuck), and the platform shipped only 3 locales (en/pt/it) while serving EU-wide users.
- **What:** A selected UI language now persists across reload and login in both SPAs
  (server-authoritative via `PATCH /me`, with localStorage for the pre-auth choice), and the
  UI + content-language sets expanded from 3 to all 24 official EU languages via a
  model-authored machine-translation baseline. Includes lazy per-locale loading (only the
  active language is fetched), CLDR pluralization rules for all 24, a request-locale
  middleware so server error strings/emails follow the caller, and parity/placeholder/plural
  CI gates across both SPAs and the backend `lang/` tree. Legally binding content
  (`resources/contracts/**`) is carved out and stays English.
- **Touched:** `packages/api-client` (locale + plural-rules + format registries), both SPA
  i18n bootstraps + switchers + auth stores, Identity module (`PATCH /me`, `SetLocale`
  middleware), `apps/api/lang/**`, all locale JSON across `apps/main` + `apps/admin`,
  architecture parity specs, SOT docs (`00-MASTER-ARCHITECTURE §13`, `CURSOR-INSTRUCTIONS`,
  `02-CONVENTIONS`), new `docs/i18n-glossary.md`.
- **Decisions:** `preferred_language` validates against the rendered `UI_LOCALES`;
  content-language fields against the full `EU_LANGUAGES` (24). `PATCH /me` ignores unknown
  fields rather than 422-ing (matches the notification-preferences precedent; extra fields
  are provably inert). Translation baseline is structurally validated (keys/placeholders/
  plural-form-counts), **not** meaning-verified — per-market human review is a go-live gate,
  not a merge gate. Digest + agency-invite emails remain English-only by decision (see AH-002).
- **Ref:** `docs/reviews/eu-locale-support-review.md` (full review, 9 sub-steps, 48/48 parity).

---

_Maintained alongside the work: when an ad-hoc change lands, its entry moves here in the
same pass — the log and the build move together, never as an afterthought._
