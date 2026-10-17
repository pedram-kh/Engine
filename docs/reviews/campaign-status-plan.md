# AH-107 — campaign status on the creator side — PLAN (plan-pause)

> **Status:** PLAN ONLY. No code until Pedram clears it. Mode A (full loop): the batch adds an API field to four creator resources, which is a stop-gate trigger by itself, and adds i18n keys.
> **Inventory:** the AH-106 inventory, item 3 (this chat). **Rulings:** Q1, Q2, Q3, Q9 (2026-10-17), baked in at §1.
> **Stacks on:** AH-106 (local, unpushed). One production deploy and one TestFlight build 3 for AH-106 + AH-107 + AH-108 (Q9).

---

## 0. The live-data risk line

**PROD-DATA RISK: LOW.**

- **No write, no migration, no schema change.** The change reads an existing column (`campaigns.status`) and puts it on four read responses the creator already receives for campaigns they are party to.
- **No gate.** Nothing is blocked server-side (Q2). A creator's actions on a paused, completed or cancelled campaign behave exactly as they do today.
- **Exposure:** the creator learns the campaign's lifecycle state, which they can already infer (an ended job disappears from the board). The one new disclosure is that a creator holding an assignment can now read `draft` on it, shown as "Upcoming". They hold the assignment because the agency invited them (Q1: invites on drafts stay allowed), so this is information about their own invitation.
- **Deploy order:** either order is safe. Old backend + new SPA: `campaign.status` is absent, and the SPA treats absent as "show nothing" (specced). New backend + old SPA: an extra field is ignored.
- **What can go wrong in production:** a new field a stale cache or an old mobile build does not know about (ignored); an exact-keyset test that was not updated (caught in CI, not production).

## 1. Rulings → where each is baked in

| Ruling                                                                                                                                                                                              | Baked in at                                                                                                                                                                                                                                      |
| --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Q1** Drafts stay visible on the board; invites on drafts stay allowed (AH-054 D5 stands); creator label for draft = **"Upcoming"**.                                                               | No backend filter change (`Campaign::LISTABLE_STATUSES` untouched, `Campaign.php:86`). The client maps `draft` → "Upcoming" in one place (§4.1). The AH-106 inventory's "draft must never be visible" is withdrawn and is not asserted anywhere. |
| **Q2** Display only. Banner on assignment detail for paused / completed / cancelled. Tech-debt row.                                                                                                 | §4.3 banner; no action is hidden or disabled (a spec pins that the draft form and the accept button still render under each banner). §7 tech-debt row text.                                                                                      |
| **Q3** Status chip on assignment list cards + detail header, all statuses incl. Active. Job cards/detail show **only Paused** (terminal ones are already hidden). New `creator.*` keys, 24 locales. | §3 contract, §4.2 chip, §4.4 job surfaces, §5 i18n.                                                                                                                                                                                              |
| **Q9** AH-107 is Mode A.                                                                                                                                                                            | Stages, Checkpoint A, break-revert list (§6).                                                                                                                                                                                                    |

## 2. Shape and the cut line

One chunk, three stages, one checkpoint, no scope beyond the rulings.

| Stage            | Content                                                                                                                                                    | Commit                                                                  |
| ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| **0**            | Preflight reads (§2.1). No commit unless a doc changes.                                                                                                    | —                                                                       |
| **1**            | Backend: `campaign.status` on the assignment list and detail, `campaign_status` on the job card and detail; keyset tests; api-client types. Test-first.    | C1.1 backend + tests, C1.2 api-client types                             |
| **2**            | SPA: the presentation mapping, the chip on both assignment-list render paths, the detail chip and banner, the job-surface Paused chip; i18n in 24 locales. | C2.1 mapping + chip, C2.2 detail + banner, C2.3 job surfaces, C2.4 i18n |
| **Checkpoint A** | Stop for clearance: field names, copy (EN) and the 24-locale pass reviewed.                                                                                | —                                                                       |
| **3**            | Close: AH-107 entry, deploy-log stack, tech-debt row, resumption refresh, gates.                                                                           | docs                                                                    |

**Cut line:** if the job-surface leg (C2.3) turns up anything unexpected, it is the one droppable piece: the assignment-side work stands alone and the Paused-on-job-card leg moves to a follow-up. Nothing else is separable.

### 2.1 Stage 0 reads (confirm, do not assume)

- The job-board controller's `select` list for `Campaign` (it must include `status`, otherwise the resource reads `null`). Inventory showed the resource reads `$campaign`; the select list was not re-read for this plan.
- The `show` response's `campaign` block in `CreatorAssignmentDraftController` (the `with(...)` at line 565 does not select `status` today; the response block near line 200 was not re-read).
- Every exact-keyset test that pins these four resources (they will fail on purpose; each edit is listed in §5.3).
- Whether `JobsBoardVisibility` or the job-posted fan-out would be touched by anything here (they should not: `LISTABLE_STATUSES` is read-only in this chunk).

## 3. The contract (additive)

| Surface                     | File:line today                                                                                                                                              | Change                                                                                                |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------- |
| Assignment **list**         | `CreatorAssignmentController::index` selects `campaign:id,ulid,name,posting_window_*,starts_at,ends_at,brand_id` (`:50`); `toRow` builds `campaign` (`:200`) | add `status` to the `select`; add `'status' => $campaign->status->value` inside `attributes.campaign` |
| Assignment **detail**       | `CreatorAssignmentDraftController` select at `:565`; `campaign` block in the show response                                                                   | same: select + `attributes.campaign.status`                                                           |
| Job **card** and **detail** | `CreatorJobCardResource::cardAttributes` (`:88`), shared with the detail resource so the shapes cannot drift                                                 | add `'campaign_status' => $campaign->status->value`; the detail inherits it                           |
| Types                       | `packages/api-client/src/types/campaign.ts:461` (assignment campaign block); job types                                                                       | `status: CampaignStatus` / `campaign_status: CampaignStatus`                                          |

**Decision D1 (proposed): emit the raw enum value** (`draft|active|paused|completed|cancelled`), not a creator-facing token. The client owns the "Upcoming" wording, so a label change never needs a backend deploy, and the agency and creator surfaces share one vocabulary.

**Decision D2 (proposed): the job resources emit all five values even though the UI shows only Paused.** The board already filters to draft/active/paused server-side; a terminal value can only appear on a stale detail load, and sending it lets the detail render the right state instead of silently showing nothing. The UI filter ("only Paused", Q3) lives in the client mapping.

**Privacy:** the field is a lifecycle enum on a campaign the creator already holds or can already read. Nothing else on the campaign (agency notes, budget, internal status) is added. A spec pins that the `campaign` block gains exactly `status` and nothing else.

## 4. The client behaviour spec

### 4.1 One mapping, one place

`apps/main/src/modules/creators/campaignStatus.ts` (new): `campaignStatusPresentation(status) → { labelKey, color, bannerKey | null, showOnJob }`.

| status      | chip label | colour  | banner (assignment detail) | shown on job surfaces |
| ----------- | ---------- | ------- | -------------------------- | --------------------- |
| `draft`     | Upcoming   | neutral | none                       | **no**                |
| `active`    | Active     | success | none                       | **no**                |
| `paused`    | Paused     | warning | yes                        | **yes**               |
| `completed` | Completed  | neutral | yes                        | no                    |
| `cancelled` | Cancelled  | error   | yes                        | no                    |

Absent or unknown status → `null` (render nothing). A spec enumerates the full `CampaignStatus` enum against the table, so a sixth status fails a test until it is classified.

### 4.2 The chip on assignment cards

There are two render paths in `CreatorAssignmentsPage.vue` (native, `:164-176`; web, `:247-271`). Both get the chip. **The AH-106 lesson applies directly:** the card row already holds the assignment-status chip (`:169`, `:265`), and a second chip in the same flex row is exactly the squeeze AH-106 fixed. So the campaign-status chip goes in its own wrapping row under the name, `flex: 0 0 auto`, with the same class-presence spec and source-inspection spec as AH-106, plus a case added to the real-Chromium harness (long title + two chips + a French label at 360 and 390). It must show 0 px cut before it merges.

### 4.3 Detail header chip and banner

`CreatorAssignmentDetailPage.vue`: the chip in the header; a `v-alert` banner above the action area for paused / completed / cancelled, with one line of copy each. **Display only (Q2):** no button is hidden or disabled, and specs pin that the draft-submit form and the accept button still render under each banner. Known UX tension, named now so it is not a surprise: a "cancelled" banner above a live upload form reads oddly. That is the Q2 ruling, and the tech-debt row (§7) is what carries it.

### 4.4 Job surfaces

`CreatorJobsPage.vue` card and `CreatorJobDetailPage.vue`: a chip only when `campaign_status === 'paused'`. Active and Upcoming render nothing (Q3).

### 4.5 Dashboard invitation card

`DashboardInvitationCard` counts invited assignments (`CreatorDashboardPage.vue:148`). **No change proposed** (§9 Q1 asks): it is a count of things awaiting a response, and the rulings are display-only.

## 5. The test spine

### 5.1 Backend (Pest)

- Each of the four resources emits `status` / `campaign_status`, the exact value, for all five statuses (dataset).
- **Exact keyset:** `attributes.campaign` has the old keys plus `status`, nothing else; the job card/detail attribute keyset likewise. Existing exact-keyset specs are edited deliberately (§5.3), and each edit is listed in the commit message.
- **No-gate negatives (Q2 pinned on the server):** a creator can still accept, submit a draft and message on an assignment whose campaign is paused / completed / cancelled. These fail loudly if anyone adds a gate later without a ruling; they are the executable form of the tech-debt row.
- **Board visibility unchanged:** completed and cancelled campaigns are still absent from the board; draft and paused are still present (pins Q1, so the withdrawn premise cannot sneak back in).
- Cross-creator isolation: another creator's assignment is still a 404 (unchanged, re-asserted).

### 5.2 SPA (Vitest)

- The mapping spec against the whole enum (§4.1), absent and unknown → null.
- Assignment list: chip present on **both** render paths for each status, label from i18n, with the AH-106-style class and source-inspection pins.
- Detail: chip and banner present for paused / completed / cancelled, absent for draft / active; the actions-still-render pins (§4.3).
- Job card and detail: chip for paused only.
- Locale parity gate (24 locales) and the placeholder/format specs, unchanged files, new keys.

### 5.3 Conscious edits to existing tests (the only ones allowed)

The exact-keyset specs for the creator assignment list/show and the job card/detail resources. Each gains the one new key. Anything else that turns red is a regression, not an edit.

### 5.4 Playwright

One leg on the existing creator jobs/assignments spec: seed a paused campaign with an assignment and read the chip and banner on the web app. Flagged **unrun locally** if Docker is still down (CI is its first execution).

## 6. Break-revert list (each: mutate, watch the named spec fail, revert, verify a clean restore)

| Condition                     | Mutation                                                | Must fail                   |
| ----------------------------- | ------------------------------------------------------- | --------------------------- |
| Resource emits the status     | drop `status` from `toRow`                              | list keyset + value dataset |
| Select list includes `status` | remove `status` from the `select` (resource reads null) | value dataset               |
| Job card shows Paused only    | let `active` through `showOnJob`                        | job card spec               |
| Draft is labelled "Upcoming"  | map `draft` to a "Draft" key                            | mapping spec                |
| Banner only on the three      | add a banner for `active`                               | detail spec                 |
| Display only (Q2)             | hide the draft form under `cancelled`                   | actions-still-render spec   |
| Both list render paths        | remove the chip from the web path only                  | web path spec               |
| Chip not squeezed             | delete the flex rule                                    | source-inspection spec      |

Mutations are applied with Python string replacement and the mutation is verified to have applied (the macOS `sed -i` false-pass lesson from AH-106).

## 7. Docs that move with the build

- **Tech-debt row (Q2):** "Campaign status does not gate creator actions. A creator can still upload a draft, submit, accept or message on a paused / completed / cancelled campaign; the creator UI only displays the status. Pinned by the no-gate negatives in the Pest suite so a future gate is a deliberate edit." Priority and trigger: revisit when payments (Sprint 10) make an action on a dead campaign cost money.
- AH-107 entry in `docs/reviews/adhoc-changes-log.md`; stack on the AH-106 PENDING entry in `docs/runbooks/deploy-log.md`; Part 2 refresh of `docs/reviews/RESUMPTION-TEMPLATE.md`; this plan is the review artefact.

## 8. Risk register

| Risk                                                                                              | Likelihood        | Mitigation                                                                             |
| ------------------------------------------------------------------------------------------------- | ----------------- | -------------------------------------------------------------------------------------- |
| A resource select list omits `status`, so the field reads null in production only for one surface | Medium            | Stage 0 read of every select; the value dataset runs against real queries, not mocks   |
| The second chip re-creates the AH-106 clip                                                        | High if unguarded | Own wrapping row, `flex: 0 0 auto`, source-inspection spec, real-Chromium harness case |
| "Cancelled" banner next to a working form confuses creators                                       | Medium            | Q2 accepted; tech-debt row; no-gate negatives                                          |
| 24-locale copy quality for the "flaky 10"                                                         | Low               | Same MT process as AH-101/AH-103; parity gate                                          |
| An old native build meets the new field                                                           | None              | Extra field ignored; the SPA treats absent as nothing                                  |

## 9. Plan-review questions

1. **Dashboard invitation card:** leave the invited count alone (recommended; display-only), or exclude invitations on cancelled / completed campaigns from the count?
2. **Job card "Upcoming":** Q3 says job cards show only Paused, so a draft campaign on the board shows no chip while the same campaign on the assignment card says "Upcoming". Confirm that asymmetry is intended (recommended: yes, as ruled), or show "Upcoming" on job cards too?
3. **D1/D2:** raw enum values on all four resources, with the "only Paused" filter in the client. Confirm, or prefer a boolean `is_paused` on the job resources?

## Not verified (carried and new)

- Any device walk (the chip and banner on an iPhone), planned for TestFlight build 3.
- E2E: Docker is down locally; the Playwright leg would be first run in CI.
- Stage 0 reads (§2.1) have not been done: the select lists and the show-response block are named from the inventory, not re-read.
