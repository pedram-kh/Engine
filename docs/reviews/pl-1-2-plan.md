# PL-1.2 plan: Campaign for a wave

**Date:** 2026-11-17. **Branch:** `pl`, from tip `55538abd`. **Status:** plan, **amended 2026-11-17 with Pedram's rulings Q1 to Q12 and the gaps G1 to G4** (sections 3.1, 4.4, 4.5 and 11). Docs only: no code, no migration run. No Cursor build prompt is issued until Pedram has said go (`production-line-sprints.md` §2, ruling 9).
**Inputs:** `pl-1-2-inventory.md` (read it first; "inventory §n" points into it), `production-line-sprints.md` §6 (R1 to R10 and P1 to P9, binding), `pl-1-1-review.md`. No screenshot is used for the board (Q10): it is built from the design system and the existing board components (§4.4).
**Standing rules applied:** schema-only migrations, the alarm rule, break-revert on every gate, the 24-locale gate, `WORKING-PROCESS.md` §4.1 local-run rules, the Erasure folder before every push, and the PostgreSQL rule below.

**Goal.** An agency user creates a campaign "for a wave", links it to a wave from either side (or creates it from the New wave dialog), and sees the nine-column board for that campaign. All behind `waves_enabled`, OFF by default. With the flag OFF, campaigns and boards are unchanged.

**Section numbers** follow `pl-1-1-plan.md` (1 data model to 12 size and order). The rulings come first; section 8, the PROD-DATA RISK line, is next because the alarm rule puts it before anything else.

---

## Rulings for this chunk (Pedram, 2026-11-17)

| #   | Ruling                                                                                                                                                                                                                   | Where it lands                      |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------- |
| P1  | A campaign has a type: normal or for a wave. In a for-a-wave campaign the creator does not post content (R3).                                                                                                            | §1.1, §3.1                          |
| P2  | Only for-a-wave campaigns can be linked to a wave. **This changes PL-1.1:** the dropdown and the server rule refuse a normal campaign, with the same 422 `wave.campaign_not_available` and the same body.                | §3.2, §6 (PL-1.1 tests that change) |
| P3  | The New wave dialog gains "Create a campaign for this wave now": a campaign name; the wave and a for-a-wave campaign are created together, linked, in one transaction. The rest is filled in later on the campaign page. | §3.2, §4                            |
| P4  | The link is shown on both sides.                                                                                                                                                                                         | §3.1, §4                            |
| P5  | Changing or removing the link is allowed only while no creator of the campaign is Selected or further. After that it is locked.                                                                                          | §3.3                                |
| P6  | The nine-column board, the Invited labels, the reserved script place, hand moves, no Paid columns.                                                                                                                       | §1.2, §3.4, §4                      |
| P7  | Existing campaigns keep type normal and their boards exactly.                                                                                                                                                            | §8, §6                              |
| P8  | Model-level guard on `Campaign` that refuses a change of `brand_id` (deferred from PL-1.1). The Campaigns module may be edited.                                                                                          | §3.5                                |
| P9  | Deleting a wave stays out.                                                                                                                                                                                               | §9                                  |

**Rulings on the plan's questions (Pedram, 2026-11-17).** Full table in section 11. Four change the plan: **Q3** (the lock reads where cards are now, not history), **Q4** (every working column needs an Accepted creator), **Q10** (no screenshot; a ClickUp-style board from existing components), **Q11** (Aurora version is a must-confirm before `pl` is merged to `main`). Q8 is agreed subject to G1, which found that a guard is needed (§3.2).

---

## 8. PROD-DATA RISK (stated first, before any code)

**Q11 (ruled):** the production Aurora PostgreSQL version is a **must-confirm before `pl` is merged to `main`**; it does not block the build.

**PROD-DATA RISK: LOW, and not "none".** This chunk adds a column to the existing, live `campaigns` table (and a nullable one to `board_columns`). No row is rewritten and no data is written by any migration. The exact statements:

**Migration M1, `campaigns`:**

```php
Schema::table('campaigns', function (Blueprint $table): void {
    $table->string('campaign_type', 16)->default('normal'); // NOT NULL, constant default
});
```

Equivalent SQL: `ALTER TABLE campaigns ADD COLUMN campaign_type varchar(16) NOT NULL DEFAULT 'normal';`

- **Default:** the constant `'normal'`. Every existing row reads `normal` (P7). **No backfill is needed**, so there is no data command and nothing to dry-run.
- **No table rewrite.** On PostgreSQL 11 and later, `ADD COLUMN … DEFAULT <constant>` stores the default in the catalog and does not rewrite the table (the "fast default"). Aurora PostgreSQL follows the same engine behaviour from its 11-compatible releases. **Not verified for this deployment:** the production Aurora engine version is not in the repo I read (Q11). The migration is rehearsed on a PostgreSQL 16 container (the local one) with a copy of production-sized row counts before it is trusted.
- **Lock behaviour.** The statement takes an `ACCESS EXCLUSIVE` lock on `campaigns` for the catalog update only: milliseconds. The risk is **queueing**: the statement waits behind any long-running transaction that has touched `campaigns`, and every other query then queues behind it. Mitigation, written into the migration: `SET LOCAL lock_timeout = '5s'` (a failed attempt aborts cleanly and is re-run) and run it at a quiet moment, as the deploy runbook already does for migrations (`production-line-sprints.md` §3: snapshot, then migrate).
- **No constraint, no index** on the new column. A `CHECK` would force a full-table validation scan; the type is enforced by the enum cast and the request rule, as `status` is. The link queries filter on `(agency_id, brand_id)`, which already has an index (`idx_campaigns_agency_brand`).
- **Rollback:** `dropColumn('campaign_type')`. Safe until a for-a-wave campaign exists; with the flag OFF at deploy none does.

**Migration M2, `board_columns`:**

```php
Schema::table('board_columns', function (Blueprint $table): void {
    $table->string('stage_key', 24)->nullable(); // no default
});
```

`ADD COLUMN … NULL` with no default is a catalog-only change on every PostgreSQL version. Same `lock_timeout`. Existing rows read NULL.

**What does not change:** no new table; `waves`, `brand_files`, `boards`, `board_cards`, `board_card_movements` and `campaign_assignments` are not altered (`board_cards.assignment_id` stays NOT NULL, which is why Applied remains a pseudo-column; Q1).

- **Writes:** the feature writes only when someone uses it, and only with the flag ON: a for-a-wave campaign, its eight board columns, two board automations. Flag OFF at deploy: no for-a-wave campaign can be created (section 5) and nothing is written.
- **Blast radius of a bug:** reads of `campaigns.campaign_type` are new code on every campaign path (create, update, resource, wave link). A bug there could affect normal campaigns, which is why P7 has its own tests (section 6) and why the type is read through one place (`CampaignType` enum plus `Campaign::isForWave()`).
- **Carried to the sprint-boundary merge.** The migrations reach production only when `pl` is merged into `main` and deployed, so they join that merge's snapshot-and-migrate obligations. Nothing is deployed from `pl`. **No deploy-log entry** until a deploy happens.

---

## 1. Data model

### 1.1 `campaigns.campaign_type` (new column, Campaigns module)

| Column          | Type                                    | Notes                                                                                                                                   |
| --------------- | --------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| `campaign_type` | `varchar(16) NOT NULL DEFAULT 'normal'` | Enum `CampaignType`: `normal`, `for_wave`. (Named `campaign_type`, not `type`, because the JSON:API envelope already has a `type` key.) |

Rules (all in the server, none in the database):

- **Set on create only; immutable after.** An update that names a different type: 422 `campaign.type_immutable`. Reason: a normal campaign with a board and cards cannot become a nine-column board without moving every card, and the reverse strands cards (the AH-069 flip-to-OFF precedent, inventory §4).
- **`for_wave` forces `creator_posts_content = false`** (P1, reuse of the AH-069 mechanism, inventory headline 3). A request that names `creator_posts_content: true` on a for-a-wave campaign: 422 `campaign.wave_posting_not_allowed`. The toggle is hidden in the form.
- `Campaign::isForWave()` is the one reader.

### 1.2 The nine columns, without touching normal campaigns

**Applied is not a data column** (Q1, recommended). It stays the existing pseudo-column `BoardApplicationsColumn.vue` (pending applications, no drag), **relabelled "Applied" on a wave board**. `board_cards.assignment_id` stays NOT NULL; there is **zero change** to `board_cards`, which is the same boundary AH-059 drew. The **eight real columns** are seeded for a for-a-wave board:

| Position | Name                  | `stage_key`     | Notes                                                                          |
| -------- | --------------------- | --------------- | ------------------------------------------------------------------------------ |
| 1        | Invited               | `invited`       | Cards arrive here on invite (existing automation). Declined and Accepted stay. |
| 2        | Selected              | `selected`      | First "Selected" step (P5 lock starts here).                                   |
| 3        | Filming               | `filming`       | Hand moves in this chunk.                                                      |
| 4        | Submitted / in review | `submitted`     | Hand.                                                                          |
| 5        | In edits              | `in_edits`      | Hand.                                                                          |
| 6        | Client review         | `client_review` | Hand.                                                                          |
| 7        | Approved              | `approved`      | Hand.                                                                          |
| 8        | Dropped               | `dropped`       | Failure terminal (`is_terminal_failure`). The cancel automation lands here.    |

Colours: tokens from the existing `boardStatus` palette only (`status-*`, `BoardDefaults::colorTokens()`); there is no screenshot (Q10), so the mapping is mine and Pedram changes it at the walk.

**How they are created without touching normal campaigns:**

- `BoardDefaults` gains `waveColumns()` and `waveAutomations()` next to the existing `columns()` and `automations()`. The existing two methods are **not edited**.
- `BoardProvisioningService::provisionDefaults()` picks the set by `$board->campaign->campaign_type`. A normal campaign takes the exact existing path (a test pins the seven names, positions and ten automations).
- A for-a-wave campaign gets its board **at creation**, in the same transaction (`BoardService::ensureBoard`), so all nine exist "from the start". The lazy heal on first GET stays as the fallback and provisions the same set.
- **Two automations only** on a wave board: `assignment.invited → Invited`, `assignment.cancelled → Dropped`. Every other move is by hand in this chunk (P6); later sprints add automations. Accepted and Declined have **no automation**, so they stay in Invited by the engine's own behaviour (inventory §1).
- **The wave board's structure is fixed:** column create, rename, reorder, delete, reset-to-defaults and automation edit answer 422 `board.wave_columns_fixed` (Q6). `reset-to-defaults` is wave-aware only in that it refuses. A rename would break the P5 lock's reading of the stage; `stage_key` makes it independent of names anyway.

### 1.3 `board_columns.stage_key` (new nullable column, Boards module)

NULL on every existing column. Set only on a for-a-wave board's eight columns. It is what P5 and the Selected rule read, instead of a name or a position.

### 1.4 The P5 lock: what it reads (Q3, ruled)

`WaveCampaignLink::isLocked(Campaign)` reads **where the cards are now, and nothing else.** It is true when, for the campaign's board, **at least one card is currently in a column whose `stage_key` is `selected`, `filming`, `submitted`, `in_edits`, `client_review` or `approved`.** A card in Applied, Invited or Dropped does not lock. `board_card_movements` is **not read**: a creator who was selected and then moved back to Invited, or to Dropped, no longer locks, and when no card is left in a working column the link is free again. A campaign with no board yet is unlocked. The lock is evaluated at the moment of the request, inside the locked transaction (§3.3), so it flips in both directions with the board.

### 1.5 The invite label (P6)

Derived on the wave board's card resource, not stored. In the Invited column (`stage_key` invited), `invite_state` is `invited` for assignment status invited or countered, `declined` for declined, and `accepted` for every status that counts as selected (an accepted creator's assignment moves on to contracted). `invite_origin` (`invite` or `application`) is sent with it, derived from an accepted application row for the same campaign and creator. The card label says how the creator got there: Invited, Accepted invite, Declined, Offer sent (an accepted applicant who has not yet answered), Application accepted. In the working columns the card keeps the assignment status label and no origin is sent. Declined never moves by itself (no automation). Re-invite (Declined to Invited) already exists. (Amended 2026-11-18 after the walk, D3.)

### 1.6 Where the per-script tracker is reserved, with no dead UI (P6)

- **API:** nothing. The card resource does **not** emit a `scripts` key until PL-3.
- **Web:** `BoardCard.vue` gets a **named slot** `scripts` inside the card body. An empty slot renders **no DOM node at all** (no wrapper, no placeholder, no empty bar). PL-3 fills the slot.
- A spec pins that a wave-board card's rendered HTML is identical with and without the slot declared (no extra element), and that the card has no `scripts` key.

---

## 2. Migrations

| File                                                   | Table           | Change    |
| ------------------------------------------------------ | --------------- | --------- |
| `2026_11_18_100000_add_campaign_type_to_campaigns.php` | `campaigns`     | M1 above. |
| `2026_11_18_100001_add_stage_key_to_board_columns.php` | `board_columns` | M2 above. |

- Both are **schema-only**: no `DB::table()`, no data write, no backfill.
- Both start with `SET LOCAL lock_timeout = '5s'` (PostgreSQL only; guarded so SQLite tests run).
- Run locally and on the test database only (standing rule). The rehearsal (above) is **part of the build**, with the timing recorded in the review file. **C1 (ruled 2026-11-17): the rehearsal uses synthetic rows generated locally in a throwaway database. It never uses a copy, export or sample of production data.** The review file says so.
- **If a backfill is ever needed it is a separate, dry-runnable artisan command**, not a migration. None is needed now because the default is the correct value for every existing row.

---

## 3. API

All new behaviour is behind `waves_enabled` unless stated. Errors use the existing envelope (`ErrorResponse::single`).

### 3.1 Campaigns

- `POST agencies/{agency}/campaigns`: new optional `campaign_type` (`normal` default, `for_wave`). Flag OFF and `for_wave` named: **422 `campaign.wave_type_disabled`** (the endpoint exists today, so it validates; it does not 404). `for_wave` forces `creator_posts_content = false`, creates the board in the same transaction (§1.2). The whitelist in `CampaignController::store` gets the new column (the AH-054 catch).
- `PATCH …/campaigns/{campaign}`: `campaign_type` named and different: 422 `campaign.type_immutable`. `creator_posts_content: true` on for-a-wave: 422 `campaign.wave_posting_not_allowed`.
- `CampaignResource` (agency side): new attribute `campaign_type`, **always present** (the data exists regardless of the flag; the web ignores it with the flag OFF). With the flag ON and a linked wave: a `wave` relationship `{id, name, go_live_on, status}` (**P4**, the campaign page shows its wave). Not added to any creator resource.
- Policy: create and update are unchanged (admin and manager).
- **Zero-budget guard (G1, Q8): new, for-a-wave campaigns only.** Today a campaign with budget 0 is valid on create and update (`min:0`), and **nothing blocks it from going Active or from being listed on the jobs board**: there is no publish action, `status` is a free field, and the listing floor (`LISTING_FLOOR_FIELDS`) does not include budget. P3's quick-create saves budget 0, so without a guard a for-a-wave campaign could go live unnoticed with no budget. `UpdateCampaignRequest::withValidator` (same place as the existing listing checks, reading the **resulting** state as they do) gets: on a `for_wave` campaign, if the resulting status is `active` or the resulting `listed_on_jobs_board` is true, and the resulting `budget_minor_units` is 0 or null, then **422 `campaign.wave_budget_required`** on field `budget_minor_units`. Draft, paused, completed and cancelled are not gated (a draft may carry 0). **Normal campaigns are unchanged** (P7). Web: the Settings tab shows the field error; the create-wave dialog's "create now" text says the budget is filled in on the campaign page before it can go live. Gate WB1 (§6).

### 3.2 Waves (the PL-1.1 change, P2, and P3)

- **P2.** `WaveService::linkableCampaign()` adds `->where('campaign_type', 'for_wave')`. A normal campaign gets the **same 422 `wave.campaign_not_available` and the same body** as a foreign, deleted or cancelled one (the B4 identical-body test gains a normal campaign). `WaveController::campaignOptions` lists only for-a-wave campaigns.
- **P3.** `POST brands/{brand}/waves` accepts `new_campaign: { name }`, **mutually exclusive** with `campaign_id` (both: 422 validation). `WaveService::createWithNewCampaign()` runs **one `DB::transaction`**: create the campaign (`for_wave`, `draft`, `creator_posts_content` false, objective `ugc`, budget 0 in the agency's `default_currency`, brand and agency from the route, `created_by` the actor; Q8), provision its board, create the wave linked to it. Any failure rolls back all three. Audit rows (`campaign.created`, then `wave.created`) are written after commit, as today. Policy: the actor needs both the wave create and the campaign create ability (both admin and manager, so no new role gap).
- **P4 (wave side).** `WaveResource` already carries the campaign; it gains the campaign's `campaign_type` and a `link_locked` boolean so the page can explain a disabled control.
- **Campaign side link (new, "from either side"):**
  - `GET agencies/{agency}/campaigns/{campaign}/wave-options`: this brand's waves with no campaign, newest first.
  - `PUT agencies/{agency}/campaigns/{campaign}/wave` body `{ wave_id }`: link. `DELETE …/wave`: unlink.
  - Both call the **same** `WaveService` methods as the wave side. There is one rule path, so the two sides cannot disagree.
  - Errors: `wave.campaign_not_available` (wrong type, wrong brand, cancelled, deleted), `wave.campaign_already_linked` (the partial unique index), `wave.campaign_link_locked` (P5), `wave.brand_archived`. Cross-agency or unknown wave or campaign: **404**, not 403.
  - Policy: admin and manager (the wave-edit policy).

### 3.3 The link lock (P5), which replaces PL-1.1's "locked once set"

In `WaveService::update()` (and the campaign-side link and unlink), inside the existing locked transaction (the F2 fix): re-read the wave `FOR UPDATE`; if it has a campaign and the request changes or removes it, **allow** unless `WaveCampaignLink::isLocked($campaign)`, in which case **422 `wave.campaign_link_locked`** (same code, same body as today). Setting a link on a wave with none is allowed (and still P2-checked). Asking for the same campaign stays a no-op.

**Serialising with moves.** Because the lock reads current positions, **both directions of a move matter**: a move _into_ a working column from outside the working set (it can lock) and a move _out of_ the last working column (it can unlock) each take `FOR UPDATE` on the linked wave row (if any) before the card row is changed. A link change and a move therefore cannot interleave: whichever commits first wins, and the other reads the result. Moves between two working columns, or between Applied-side columns, do not change the lock and take no wave lock.

### 3.4 Boards

- `GET …/board`: on a wave board the resource adds `columns[].stage_key` and `cards[].invite_state`; **the key is absent on a normal board**, so a normal board's JSON is identical to today (pinned by a test). `hiddenColumnIds()` is not applied (the wave board has no posting column).
- `POST …/board/cards/{card}/move` (unchanged route, ability `invite`: admin, manager, staff): on a wave board (Q4, ruled): **a move into any working column (`selected`, `filming`, `submitted`, `in_edits`, `client_review`, `approved`) requires the card's creator to have Accepted**, meaning the assignment status is Accepted or later (`AssignmentStatus::countsAsSelected()` is true; Invited, Countered, Declined and Cancelled are refused). Refusal: **422 `board.selection_requires_acceptance`**, its own code, with the existing envelope. **Any card can be moved to Dropped** (no check). **Moves back are free**: to Invited, to Dropped, or from one working column to an earlier one. The rule runs in `BoardCardMoveService` (the one write path), so no route can bypass it. The existing store already reverts a refused optimistic move (§4.4). Moving to Dropped by hand is a **card move only**; it does not cancel the assignment (a board drag is consequence-free; Q5). Cancel and Reject stay the real actions, and cancel lands in Dropped by its automation.
- Column and automation routes on a wave board: 422 `board.wave_columns_fixed` (§1.2).
- **By role:** staff can move cards (as now) but cannot create, link or unlink; admin and manager can do all.

### 3.5 P8, the brand guard

`Campaign::booted()` registers an `updating` listener: if `brand_id` is dirty and the original is not null, throw `CampaignBrandChangeException` (a `LogicException`). Creating is unaffected. **Limit, stated:** a query-builder `update()` bypasses model events; no such call exists (grep of `app`, `database`, `tests`), and the database has no trigger. A test pins the model path; another greps nothing (it is a convention note in the model docblock). `UpdateCampaignRequest` already carries no `brand_id` (PL-1.1 G2).

### 3.6 Audit and erasure

No new audit action. `campaign.created` (P3 and the normal form), `wave.created`, `wave.updated` (link, unlink and change, with the literal `campaign_id` keys already decided), `board.card_moved_manually`. Registry entries for the two new string columns and the decided audit key `campaign_type` (section 6, E-gates).

---

## 4. Web

**Built from the design system, named here as the standing rule requires.** There is **no Figma frame** for: the campaign type option, the "create a campaign now" option, and the link display (campaign page Wave card, wave page campaign row, link and unlink controls). The wave board has no design either (Q10): it is built from the design system and the existing board components, described in §4.4. Empty, loading and error states of the Wave card are also undrawn.

### 4.1 What is built

| Piece                | File (agency web, `apps/main/src/modules/…`)                                                          | Notes                                                                                                                                                                                                                                                                                                                                                 |
| -------------------- | ----------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Type option          | `campaigns/components/CampaignForm.vue`, `pages/CampaignCreatePage.vue`                               | Two radio cards "Normal campaign" / "For a wave". **Shown only with `features.waves` ON and only on create.** "For a wave" hides the posting toggle and the posting-window fields and sends `creator_posts_content: false`.                                                                                                                           |
| Type pill            | `campaigns/pages/CampaignListPage.vue`, `CampaignDetailPage.vue` header                               | Shown for for-a-wave only (a data fact, so also shown after the flag is turned OFF).                                                                                                                                                                                                                                                                  |
| Wave card (P4)       | `CampaignDetailPage.vue` Overview, new `campaigns/components/CampaignWaveCard.vue`                    | Wave name and go-live date as a link to the wave page; Link, Change and Unlink (admin and manager) while unlocked; a plain "Locked: a creator is Selected or further" line while a card sits in a working column. Link opens a picker over `wave-options`. **Only with the flag ON.**                                                                 |
| New wave dialog (P3) | `waves/components/NewWaveDialog.vue`                                                                  | The campaign field becomes three choices: no campaign yet; an existing for-a-wave campaign (the PL-1.1 dropdown, now filtered by the server); **"Create a campaign for this wave now"** with one name field.                                                                                                                                          |
| Wave page (P4, P5)   | `waves/pages/WaveDetailPage.vue`, `NewWaveDialog` edit mode                                           | Campaign row with a link to the campaign; the edit dialog's campaign field becomes editable (change or remove) while unlocked, read-only with the reason when `link_locked`.                                                                                                                                                                          |
| Wave board           | `boards/components/BoardView.vue`, `BoardColumns.vue`, `BoardCard.vue`, `BoardApplicationsColumn.vue` | A wave board renders Applied (the pseudo-column, relabelled) plus the eight real columns. Invited cards show an Invited / Accepted / Declined chip, and the card face is cut down (§4.4). Column add, rename, reorder, delete, reset and automation controls are **hidden** on a wave board (the server refuses them too). The `scripts` slot (§1.6). |
| api-client           | `packages/api-client/src/types/`                                                                      | `CampaignType`, `campaign_type`, `wave` relationship, `link_locked`, `invite_state`, `stage_key`, `new_campaign`. All additive, all agency-side.                                                                                                                                                                                                      |

### 4.2 Routes

No new route. The wave page route (`waves.detail`) and the campaign detail route are unchanged; the campaign-side link uses the existing campaign page.

### 4.3 Flag OFF leaves the screens unchanged

- The type option, the Wave card, the new dialog choices and the wave routes are drawn only when `features.waves` is true.
- The board for a **normal** campaign takes the same branches, with the same JSON, as today. A wave-board branch is chosen by `campaign_type`, which is `normal` for every existing campaign.
- **Proof, as in PL-1.1:** the agency walk on the 12 campaign targets (`campaigns`, `campaign-create`, `campaign-detail`, dark and light, both widths) plus the 2 `campaigns` crossover targets must be identical to the baseline with the flag OFF, **checked for stray comment nodes** (the lesson of PL-1.1: a false `v-if` leaves `<!--v-if-->`; use the one-item `v-for` pattern or a class binding where a branch would otherwise leave a node). The board tab is not a walk target; its flag-off sameness is a Vitest DOM pin.
- The frozen files (`agency.css`, `agencyStatusTones.ts`) will be edited again for the type pill and the chip; the `agency-frozen` hashes are re-pinned in the same commit with the reason stated, as in PL-1.1. No at-rules in `agency.css`.

### 4.4 The wave board (Q10, ruled; G2 read the existing board)

**No screenshot.** The board works like a ClickUp board: columns side by side, each with its name and a card count; cards dragged between columns; a horizontal scroll across the board; a click on a card opens its detail.

**What the existing board already does (read: `BoardView.vue`, `BoardColumns.vue`, `BoardColumn.vue`, `BoardCard.vue`, `BoardCardDrawer.vue`, `useBoardStore.ts`):**

- Columns side by side, 300px each, in a flex row inside a horizontal scroller (`overflow-x: auto`, `BoardColumns.vue`); the Applied pseudo-column is the first child of that scroller.
- Each column header has a colour dot, the name and a **count chip** (`board-column-count-<id>`).
- Cards drag between columns (`vuedraggable`, `group="board-cards"`); the store moves the card locally first, posts the move, and **reverts if the server refuses** (D-3), and a poll never clobbers a move in flight.
- A click on a card opens `BoardCardDrawer` (tabs Messages, Detail, Drafts, History, Profile).
- Columns scroll vertically inside themselves; terminal columns carry success and failure marks; the column menu (edit, delete) and the add-column and automations buttons exist for `canEdit` and `canConfigure`.

**What is missing for the wave board:**

1. **The nine-column set** (data, §1.2) and Applied relabelled.
2. **A slimmer card face.** Today the face shows avatar, name, days remaining (from `posting_due_at`), the status badge, the "declined, then re-invited" tag and **the agreed fee**. The wave card face is: **creator name (with the existing avatar), the Invited / Accepted / Declined label on Invited cards, and the reserved `scripts` place. No fee, contract, payment, due date or file.** Chosen by `campaign_type`; a normal board's face is untouched.
3. **The invite label** (`invite_state`, §1.5) and its chip.
4. **A specific refusal message** for `board.selection_requires_acceptance`, shown when the store reverts the drag (the store already reverts; today it does not know this code).
5. **Hiding the structure controls** on a wave board (column menu, add column, reset, automations), because the server refuses them.
6. **The card detail drawer stays as it is (Q13, ruled 2026-11-17).** Fee, offer, deliverables and due dates are **not** hidden: creators on a wave are paid and the strategist needs them. Only what AH-069 already hides for a non-posting campaign (the posting form and posting date) stays hidden. **No drawer change in this chunk.** The wave card **face** is still slimmed (item 2).

### 4.5 Nothing existing moves or alters a wave-board card (G2 answer)

| Mechanism                                                                    | What it does today (read)                                                                                                                                                                   | On a wave board                                                                                                                                                                                                                                                                                                                                                                                   |
| ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `BoardAutomationService::processEvent`                                       | Moves a card **only if** the board has an enabled `move_to_column` automation for that event key with a target column. It writes the card and a movement row.                               | The wave board has exactly two automations (invited, cancelled). Every other event finds none and returns without writing. **Stopped by the data, and pinned by test B5.** No condition evaluator branch is added.                                                                                                                                                                                |
| `OverdueScanService::scan`                                                   | Posting scan: only campaigns with `creator_posts_content = true`; draft scan: any assignment with `draft_due_at` set. Both set a flag column on the **assignment** and call `processEvent`. | **Cards: never moved**, because the overdue events have no automation on a wave board. The posting scan skips for-a-wave campaigns (posting is false). **The draft scan can still flag the assignment and fire its event** if a draft due date is set: that is the creator-draft behaviour left unchanged by Q9, and it does not touch a card. Test B5 pins "card unmoved after an overdue scan". |
| `BoardResetService::reset`                                                   | Deletes **all** columns and automations, re-seeds the default seven and ten, and re-homes every card by status. On a wave board it would **destroy the nine columns.**                      | **Stopped twice:** the controller answers 422 `board.wave_columns_fixed` (Q6) and `BoardResetService::reset` itself throws for a `for_wave` board (defence in depth). Test B1 covers both entry points.                                                                                                                                                                                           |
| `BoardCardService::resolveColumnForState` and the lazy heal in `forCampaign` | Places a card for an assignment status using the board's own automations, falling back to the first column.                                                                                 | With two automations it places invited and cancelled correctly and any other status in Invited. Test B5 pins the heal on a wave board.                                                                                                                                                                                                                                                            |
| Column and automation CRUD, reorder                                          | Free on a normal board for admin and manager.                                                                                                                                               | 422 `board.wave_columns_fixed` (Q6).                                                                                                                                                                                                                                                                                                                                                              |

---

## 5. Flag

`waves_enabled` (PL-1's flag; `features.waves` on `/me`, agency side only). No new flag.

**Flag OFF:**

- The type option is hidden. A request naming `for_wave` gets 422 `campaign.wave_type_disabled`. A for-a-wave campaign cannot be created.
- Waves routes answer 404 (unchanged). The Wave card and the new dialog choices are not drawn.
- Normal campaigns and normal boards: no change (P7).
- **At deploy no for-a-wave campaign exists**, so nothing on any existing screen differs.

**What happens to for-a-wave campaigns that exist when the flag is turned OFF again** (Q7, recommended): **they keep working.** The campaign stays a for-a-wave campaign (type pill shown), its nine-column board still renders and its cards stay movable by hand, because the board is data and hiding it would strand cards (the AH-069 lesson: never hide a row with no way to move it). What goes away is everything that needs the wave: the Wave card, linking and unlinking, and the wave endpoints (404). An existing link is **kept in the data**, simply not shown or editable; turning the flag back ON shows it again. Nothing is deleted or rewritten when the flag moves.

---

## 6. Tests (each gate has a break-revert)

Rules: tests for new code only; Pint, Larastan, `vue-tsc`, ESLint on what is touched; Vitest at `VITEST_MAX_WORKERS=4`; serial Playwright; clean slate first (`WORKING-PROCESS.md` §4.1).

**PostgreSQL rule (lesson of CI run 44180284980).** Any test that depends on database behaviour (unique violations, row locks, transactions, savepoints) is **also run locally against PostgreSQL**, not only SQLite: create a throwaway database in the local container (`CREATE DATABASE catalyst_pl12_test`), run `DB_CONNECTION=pgsql DB_DATABASE=catalyst_pl12_test` against the Waves, Campaigns-link, Boards-wave and Erasure folders, then drop it. Never against the dev database. Lock tests additionally assert, on PostgreSQL, that the SQL contains `for update` (`DB::listen`), because SQLite ignores row locks and a green SQLite run proves nothing about the lock. This is added to `WORKING-PROCESS.md` §4.1 in the build. **Any chunk adding an audit call, a table or a column runs the Erasure test folder locally** (already there).

### Backend gates

| #   | Gate                                  | Test (Pest)                                                                                                                                                                                                                                                                                                                                                   | Break-revert                                                                               |
| --- | ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| C1  | Type default and immutability         | New campaign without type is `normal`; `for_wave` forces `creator_posts_content` false; changing type and `creator_posts_content: true` on for-a-wave are 422                                                                                                                                                                                                 | Drop the immutability check; drop the force                                                |
| C2  | Flag gate on the type                 | Flag OFF and `for_wave`: 422 `campaign.wave_type_disabled` and no row; flag ON: created with a board of 8 columns and 2 automations                                                                                                                                                                                                                           | Remove the flag check                                                                      |
| C3  | Normal campaigns untouched (P7)       | A normal campaign's board still seeds exactly the seven default names and ten automations; its board JSON has no `stage_key` or `invite_state` keys; all existing Boards and Campaigns tests stay green                                                                                                                                                       | Make provisioning use the wave set for every campaign                                      |
| L1  | Type rule on link (P2)                | A normal campaign is refused on wave create, wave edit and the campaign-side link with the same 422 body as a foreign one (the B4 identical-body test gains it); options list only for-a-wave                                                                                                                                                                 | Remove the type filter in `linkableCampaign`                                               |
| L2  | One transaction (P3)                  | Campaign, board and wave created together and linked; a forced failure at each of the three steps leaves **zero** new rows (PostgreSQL run)                                                                                                                                                                                                                   | Move the wave create outside the transaction                                               |
| L3a | Link lock locks (P5, Q3)              | With no card in a working column, change and remove are allowed. With a card **currently** in each of the six working columns (one case per column): change and remove are 422 `wave.campaign_link_locked`, and the wave and its link are unchanged                                                                                                           | Make `isLocked` ignore one working column (the per-column cases go red); remove the lock   |
| L3b | Link lock unlocks (P5, Q3)            | After the card in a working column is moved back to Invited, or to Dropped, the lock releases (change and remove work again); a card only in Applied, Invited or Dropped never locks; **a card that was once in a working column but is not now does not lock**                                                                                               | Make `isLocked` also read `board_card_movements` (history): the "moved back" case goes red |
| L3c | Lock follows both directions          | A card moved into Selected (lock) then out (unlock) then into Filming (lock) flips the answer each time, from the same service call                                                                                                                                                                                                                           | Cache the answer, or only check on entry                                                   |
| L4  | Lock serialisation                    | On PostgreSQL the link update, a move **into** a working column and a move **out of** the last working column each issue `for update` on the wave row (`DB::listen`); a stale-model sequence test as the F2 tests                                                                                                                                             | Remove `lockForUpdate` in either path (the SQL assertion goes red; SQLite alone would not) |
| L5  | Both sides, one path                  | Linking from the campaign side equals linking from the wave side (same errors, same audit); unlink; second wave for one campaign 422 `already_linked` (PostgreSQL unique violation, savepoint)                                                                                                                                                                | Give the campaign side its own rule                                                        |
| B1  | Board fixed shape                     | Column create, rename, reorder, delete, reset and automation edit on a wave board: 422 `board.wave_columns_fixed`; the same calls on a normal board still work                                                                                                                                                                                                | Remove the check                                                                           |
| B2a | Acceptance rule (Q4), per column      | **For each working column** (`selected`, `filming`, `submitted`, `in_edits`, `client_review`, `approved`): a card whose assignment is Invited, Countered or Declined is refused with 422 `board.selection_requires_acceptance` and the card does not move and no movement row is written; an Accepted card moves                                              | Remove the rule for one column (that column's case goes red); remove it entirely           |
| B2b | Free moves (Q4, Q5)                   | Any card (including Invited and Declined) can be moved to Dropped; moves back to Invited are free; neither changes the assignment                                                                                                                                                                                                                             | Apply the acceptance rule to Dropped or to a move back; make Dropped cancel                |
| WB1 | Zero-budget guard (G1)                | On a for-a-wave campaign with budget 0: PATCH `status: active` and PATCH `listed_on_jobs_board: true` are 422 `campaign.wave_budget_required`; saving it as draft is fine; with a budget above 0 both work; a **normal** campaign with budget 0 still goes active (P7)                                                                                        | Remove the guard (red); apply it to normal campaigns (the P7 case goes red)                |
| CR1 | Creator sees nothing (G3)             | For a for-a-wave campaign, the creator job list, job detail and apply response contain no `campaign_type`, no wave key, no wave name, no budget; the creator job and detail resources keep their exact key sets                                                                                                                                               | Add `campaign_type` to `CARD_COLUMNS` or the resource (red)                                |
| B3  | Roles on moves                        | Staff can move; staff cannot link, unlink or create a for-a-wave campaign; another agency's board, campaign or wave is 404                                                                                                                                                                                                                                    | Widen the policy                                                                           |
| B4  | Invite label                          | `invite_state` for invited, countered, accepted, declined; absent later; Declined never moves; re-invite works                                                                                                                                                                                                                                                | Route Declined to Dropped                                                                  |
| B5  | Automations, reset, overdue (G2)      | Invited creates the card in Invited; cancel lands in Dropped; **no other event moves a card** (draft submitted, approved, posted); `OverdueScanService::scan` leaves a wave-board card in its column; the lazy heal places an Accepted-or-later assignment in Invited; `BoardResetService::reset` called directly throws and the board keeps its nine columns | Add a default automation; remove the throw in `reset` (the direct-call case goes red)      |
| G1  | Brand guard (P8)                      | Changing `brand_id` on a saved campaign throws; creating and saving other fields do not                                                                                                                                                                                                                                                                       | Remove the listener                                                                        |
| T2  | Flag gate on the campaign-side routes | Flag OFF: `PUT` and `DELETE …/campaigns/{campaign}/wave` and `GET …/wave-options` answer 404 `not_found` and write nothing; the route table has exactly three campaign-side wave routes                                                                                                                                                                       | Add a fourth route without the gate (the count goes red)                                   |
| T1  | Tenancy                               | Every new route: another agency gets 404 and no write; the wave and campaign must share agency and brand                                                                                                                                                                                                                                                      | Drop the agency check on the campaign-side routes                                          |
| E1  | Erasure                               | `ErasureRegistrySchemaTest`, `ErasureSchemaTest` and the audit guard pass with the two new columns and the key `campaign_type` decided                                                                                                                                                                                                                        | Remove one decision (red)                                                                  |
| S1  | Migrations                            | Both migrations add the column with the stated default and leave every existing row's value `normal` / NULL; the up and down both run                                                                                                                                                                                                                         | Change the default                                                                         |

### PL-1.1 tests that change because of P2 and P5 (named, so nothing is a surprise)

**Read line by line for this amendment (G4).** Nothing else in `tests/Feature/Modules/Waves` or the web specs needs a change: `WaveAccessTest.php` (the options route is only called for 404 and for staff-allowed, with no campaigns made), `WavesFlagTest.php`, `BrandFileApiTest.php` and `WaveSchemaTest.php` (direct inserts use `Campaign::factory()` with the default type; the database does not check the type) are **unchanged**; `BrandWavesPanel.spec.ts` and `BrandDetailPage.waves.spec.ts` only mock `campaignOptions` and are **unchanged**. The route-count test in `WaveApiTest` ("exactly five") counts URIs containing `/waves`; the campaign-side routes are `…/campaigns/{campaign}/wave` and `…/wave-options` and do not contain `/waves`, so it stays at five; a **new** flag-gate test (T2) lists the campaign-side routes.

**Support**

- `tests/Support/WaveFixtures.php`: `campaign()` (lines 45 to 53) gains a fifth argument `string $type = 'normal'`; a `waveCampaign(Agency, Brand, status = 'draft', name = '')` helper is added that calls it with `for_wave` and `creator_posts_content` false. The default stays normal, so every test that does not link is untouched.
- `database/factories` `CampaignFactory`: a `forWave()` state (`campaign_type` for_wave, `creator_posts_content` false). (The factory's other states are read by name only.)

**`WaveApiTest.php`**

- `B8: every accepted create field persists` (line 67): `WaveFixtures::campaign` becomes `waveCampaign`. Otherwise it would answer 422 `wave.campaign_not_available`.
- `B12: the resource shape` (line 219): same change; its `campaign` relationship assertion stays.
- Every other test in the file (B1 flag gate, route count, create, validation, archived brand, edit, B10 audit, F3) is unchanged. The B1 flag-gate test still passes because the campaign-side routes are separate (T2).

**`WaveCampaignLinkTest.php`** (line numbers as of `55538abd`)

- B4 "refuses to link a campaign of another brand, another agency, deleted or unknown" (the identical-body test): **add a fifth refused case, a normal campaign of the same brand and agency**, asserting the identical body (L1). The existing four are unchanged.
- B4 "links an own-brand, unlinked campaign": `campaign` becomes `waveCampaign`.
- B5 "a second wave for a linked campaign gives 422 `wave.campaign_already_linked`" (line 70): `campaign` becomes `waveCampaign`. Without it the answer changes to `campaign_not_available`.
- B5 "the race" (line 81): `campaign` becomes `waveCampaign`; same reason. Its assertions are unchanged.
- B6 "campaign-options returns only…" (line 111): the seven listed-or-excluded campaigns that must appear (`draft`, `active`, `paused`, `completed`, and the `linked` one) become `waveCampaign`; **a normal campaign of the same brand is added and must be absent**; the expected id order and status list are unchanged.
- B6 pin 1 (line 139) and pin 2 (line 143): **unchanged** (they test `UpdateCampaignRequest` and a PATCH with `brand_id`).
- B14 "sets a link on a wave that has none" (line 163): `campaign` becomes `waveCampaign`.
- B14 "refuses to change or remove an existing link with 422 `wave.campaign_link_locked`" (line 177): **rewritten.** The old behaviour (any existing link is locked) no longer exists. It becomes two tests: with no card in a working column, change to another for-a-wave campaign and removal both succeed (200, audit `wave.updated` with before and after); with a card in Selected, both are 422 `wave.campaign_link_locked` and nothing changes (this is L3a).
- B14 "the wave's own campaign id is a no-op" (line 194): unchanged in behaviour; `linked` becomes `waveCampaign` for coherence (a same-id request is a no-op either way).
- B14 "set-link obeys every create rule" (line 211): the `taken` campaign becomes `waveCampaign` (otherwise it is refused as not available instead of already linked). The four identical-body refusals are unchanged, and a normal campaign is added to that identical-body list.
- B15 "a Cancelled campaign is refused…" (line 239): `cancelled` becomes `waveCampaign(..., 'cancelled')`. Otherwise it would be refused by the type rule and the test would stop pinning the cancelled rule. `foreign` stays normal (it is the reference refusal).
- B15 "cancelled AFTER it was linked keeps its link" (line 262): `campaign` becomes `waveCampaign` for coherence; behaviour unchanged.
- F2 "second edit holding a STALE wave" (line 277): `first` and `second` become `waveCampaign`. **The expectation changes.** Today the loser gets `wave.campaign_link_locked` because any link is final. Under P5 a change is allowed while unlocked, so: (a) with **no** card in a working column the loser's different-campaign request is applied on the **re-read** row and audits the true "before" (the winner's campaign); (b) with a card in Selected on the winner's campaign the loser gets `wave.campaign_link_locked`. The same-campaign no-op half of the test is unchanged.
- F2 "the stale loser asking for the winner's campaign with a rename" (line 310): `first` becomes `waveCampaign`; behaviour unchanged.

**Seeder and its test**

- `database/seeders/Pl11WalkSeeder.php` lines 96 to 99: `Bolt Autumn Reels`, `Bolt Winter Launch` and `Bolt Summer` (the one linked to Wave 5) get the `forWave()` state so the PL-1.1 walk data stays valid under P2 (a normal campaign linked to a wave would be an illegal state). `Bolt Old Promo`, `Glovo Spring` and `Zeta Q4` stay normal. The class docblock (lines 20 to 26) says which are for-a-wave.
- `Pl11WalkSeedersTest.php` S1 (line 21): the existing counts stay (3 brands, Wave 5 and Wave 6, one linked wave, 4 users, flag OFF, 3 waves after a second run); **one assertion is added**: every linked wave's campaign is for-a-wave. S2 and S3 are unchanged.

**Web**

- `waves/waveTestKit.ts` `makeWave` (lines 41 to 47): the linked `campaign` attributes gain `campaign_type: 'for_wave'`, and the wave attributes gain `link_locked: false`.
- `waves/pages/WaveDetailPage.spec.ts` (lines 38 to 51): unchanged for the link and the "No campaign linked" text; **one test added** for a `link_locked: true` wave (shows the reason).
- `waves/components/NewWaveDialog.spec.ts`:
  - lines 98 to 107 (options list `['No campaign', 'Spring campaign · Active', 'Autumn · Draft']`): the list is unchanged, but it now sits under the second of three choices; the test selects that choice first.
  - lines 109 to 120 ("No campaign" sends `campaign_id: null`) and 122 to 128 (a chosen campaign sends its ulid): each selects its choice first; payloads are unchanged.
  - lines 130 to 140 (422 code mapping table): unchanged, plus no new code (the new `wave.campaign_*` codes are the existing ones).
  - lines 158 to 169 (edit mode, unlinked, only changed fields sent): unchanged.
  - **lines 171 to 181 (edit mode, a linked wave shows a read-only name and never sends `campaign_id`) are rewritten**: an unlocked linked wave shows the editable field, sends `campaign_id` when changed and `null` when removed; `link_locked: true` shows the read-only name with the reason and never sends it.
  - lines 183 to 198 (`wave.campaign_link_locked` mapping): unchanged.
  - **Added:** the "create a campaign now" choice (W4).

**Counts:** 3 backend test files edited (`WaveApiTest`, `WaveCampaignLinkTest`, `Pl11WalkSeedersTest`) plus the fixtures and the seeder; 3 web files edited (`waveTestKit`, `WaveDetailPage.spec`, `NewWaveDialog.spec`); 7 other files read and confirmed unchanged.

### Web gates (Vitest)

| #   | Gate                                                                                                                                                                                                                                                     | Break-revert                                                |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| W1  | Type option hidden with the flag OFF; shown ON; "For a wave" hides the posting toggle and sends `creator_posts_content: false`                                                                                                                           | Show regardless of flag                                     |
| W2  | Flag-off DOM of the create form, campaign detail and a normal board equals today's (no comment nodes)                                                                                                                                                    | Use a `v-if` that leaves a node                             |
| W3  | Wave card: shows, links, changes, unlinks, locked state with the reason, role gating                                                                                                                                                                     | Drop the role gate; ignore `link_locked`                    |
| W4  | New wave dialog: three choices; "create now" sends `new_campaign: {name}` and no `campaign_id`; the name is required; the 422 codes map to strings                                                                                                       | Send both fields                                            |
| W5  | Wave board renders Applied plus eight columns in order, a count chip on each, the chip on Invited cards, **a card face with name and label only (no fee, due date, contract or file text)**, no column controls, no `scripts` DOM when the slot is empty | Render the column controls; show the fee; add a placeholder |
| W6  | Hand move calls the move endpoint; a refused move into a working column shows the `selection_requires_acceptance` message and the card returns                                                                                                           | Optimistic move without rollback                            |
| W7  | Arch: `agency-frozen` re-pin, status tones, route guards, i18n key coverage in 24 locales                                                                                                                                                                | As PL-1.1                                                   |

**Local gates:** the Waves, Campaigns (link and type), Boards and **Erasure** folders on SQLite **and** PostgreSQL; the touched Vitest set (`tests/unit/architecture`, `core/i18n`, `campaigns`, `boards`, `waves`, `brands`, `agency`); Pint, Larastan, `vue-tsc`, ESLint on touched files; the existing `BoardView`, `CampaignDetailPage` and `CampaignForm` specs; Playwright campaign and board specs once at the end with the flag OFF (serial); the agency walk on `--only campaign` against the baseline with the flag OFF. **Not run locally:** the full Pest and Vitest suites (CI runs them), the 126-target walk beyond the campaign subset, the creator walk (no creator file changes; run only if a creator file is touched).

---

## 7. Strings

All in all 24 locales (`apps/main/src/core/i18n/locales/<xx>/app.json`, `app.waves.*` and `app.campaigns.*`, `app.board.*`). Rough count: **about 35 keys** (type option and help text, pill, Wave card states and lock reason, dialog choices and errors, nine board column labels, Applied label, Invited / Accepted / Declined chips, the new 422 messages). Same procedure and spec as PL-1.1 (`waveKeys.spec.ts` extended, same-as-English exceptions listed with reasons). **No new key ships with English in the other 23 locales.** Backend messages for the new 422 codes are server strings in `lang/`; existing locale handling applies (not read: whether `lang/` carries all 24).

---

## 9. Left out of this chunk

- **P9:** deleting a wave. Not built, not planned here.
- Hand-off, scripts, per-script tracker contents (PL-3); automated moves for Filming to Approved (later sprints); Paid and Payment due columns (R9); the "release creator" action (R8); the creator-side view of anything; the wave's creator picker (R5, PL-3); the wave project tracker (PL-1.3).
- Changing a campaign's type after creation (refused, §1.1).
- Moving an existing normal campaign onto the new board, and a "convert" tool. Existing campaigns keep their boards (P7).
- A database-level guard for `brand_id` (a trigger) and mass-update protection (§3.5).
- A virus scan of client files (tech-debt row 40, before PL-4) is unaffected.
- Linking or unlinking from the campaign list.

---

## 10. Walk script for Pedram (local)

**Setup (named seed data).** A new seeder pair, `Pl12WalkSeeder` and `Pl12WalkCleanupSeeder` (local and testing only, idempotent, additive, never touches the flag; Pedram turns it on and off by hand), every account under `@pl12-walk.local`, shared local password constant:

- **Agency A "PL-1.2 Walk Agency A":** `admin@`, `manager@`, `staff@pl12-walk.local`. Brands **Bolt Food** (active), **Glovo** (active).
- **Campaigns:** **Bolt Evergreen** (normal, active, **with an existing board and one invited assignment**: the "old board" proof); **Bolt Summer Wave Campaign** (for-a-wave, published and listed on the jobs board so creators can apply, no wave yet); **Bolt Normal Promo** (normal, no assignments, to prove P2).
- **Waves:** **Wave 7** (Bolt Food, no campaign), **Wave 8** (Bolt Food, no campaign).
- **Creators** (approved, KYC verified, rostered, as in the AH-120 seeder): `maya@`, `pia@`, `daniel@`, `eva@pl12-walk.local`. Maya applies; Pia is invited and accepts; Daniel is invited and declines; Eva is invited, accepts and is selected.
- **Agency B "PL-1.2 Walk Agency B":** `admin-b@pl12-walk.local`, brand Zeta Foods, a for-a-wave campaign and a wave, to prove isolation.

**Environment:** `127.0.0.1:5173` and `127.0.0.1:5174` (never `localhost`; `docs/runbooks/local-dev.md`). `docker compose up -d`; `php artisan migrate` (local); the seeder; `pnpm dev`. The platform admin for the Feature-flags page: `super@catalyst-engine.local` (its 2FA is already enrolled locally).

| #   | Step                                                                                                                                   | Expect                                                                                                                                                                                                                                   |
| --- | -------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | **Flag OFF.** Sign in as `admin@` on 5173. Open Campaigns, New campaign, a campaign page, Bolt Evergreen's board.                      | No type option, no Wave card, no pill. Everything as before. A normal board shows its seven columns.                                                                                                                                     |
| 2   | Admin app: turn `waves_enabled` ON. Reload as `admin@`.                                                                                | The type option appears on New campaign.                                                                                                                                                                                                 |
| 3   | New campaign, choose **For a wave**, brand Bolt Food, name "Walk Wave Campaign".                                                       | The posting toggle and posting-window fields are gone. Created.                                                                                                                                                                          |
| 4   | Open its Board tab.                                                                                                                    | **Nine columns in order:** Applied, Invited, Selected, Filming, Submitted / in review, In edits, Client review, Approved, Dropped. No Paid. All exist before any creator.                                                                |
| 5   | Brand page, Waves panel, **New wave**: name, date, markets, **Create a campaign for this wave now**, name "Created With Wave". Create. | Wave and campaign appear together. Open the wave: its campaign is shown, with a link. Open that campaign: its wave is shown.                                                                                                             |
| 6   | New wave again; the campaign dropdown.                                                                                                 | **Only for-a-wave campaigns**: Bolt Normal Promo is not offered.                                                                                                                                                                         |
| 7   | On Wave 7's page, edit and pick "Bolt Summer Wave Campaign". Then, from the campaign page, check its Wave card.                        | Link shown on both sides.                                                                                                                                                                                                                |
| 8   | On the campaign page, **Change** the wave to Wave 8; then **Unlink**.                                                                  | Both work while nobody is selected. Link it again to Wave 7.                                                                                                                                                                             |
| 9   | Sign in as `maya@` (creator), apply to the listed Bolt Summer Wave Campaign. As `admin@`, open its Board.                              | Maya is a card in **Applied**.                                                                                                                                                                                                           |
| 10  | Invite Pia and Daniel (and Eva). As Pia and Eva accept; as Daniel decline.                                                             | In **Invited**: Pia and Eva labelled **Accepted**, Daniel labelled **Declined**; all three **stay in Invited**. Nothing moves to Dropped.                                                                                                |
| 11  | Move Daniel (Declined) to **Selected**, and to Filming.                                                                                | Both refused with the message (only an Accepted creator can enter a working column). Daniel can be moved to **Dropped**. Move Pia to **Selected**. Works.                                                                                |
| 12  | Back on the campaign page and Wave 7's page, try **Change** and **Unlink**.                                                            | **Locked**, with the reason. The server also refuses (try from the wave page's edit dialog). Then move Pia back to **Invited**: Change and Unlink work again (the lock reads where cards are now). Move her to Selected again to relock. |
| 13  | Hand-move Pia on through Filming, Submitted / in review, In edits, Client review, Approved, and one card to Dropped.                   | All moves work; the movement history lists them. Sign in as `staff@`: staff can move cards but has no Link, Unlink or New wave. A creator sees no change.                                                                                |
| 14  | Open **Bolt Evergreen** (the old campaign).                                                                                            | Its **old seven-column board and card are exactly as before**.                                                                                                                                                                           |
| 15  | Sign in as `admin-b@` (agency B).                                                                                                      | Sees none of agency A's campaigns, waves or boards; a pasted agency A campaign or wave address is a not-found.                                                                                                                           |
| 16  | Admin app: turn `waves_enabled` **OFF** by hand. Reload as `admin@`.                                                                   | The type option and the Wave card are gone; the for-a-wave campaign and its nine-column board **still work**; Bolt Evergreen unchanged.                                                                                                  |
| 17  | `php artisan db:seed --class='Database\Seeders\Pl12WalkCleanupSeeder'`.                                                                | The cleanup does **not** touch the flag. MinIO is not involved in this chunk.                                                                                                                                                            |

---

## 11. Questions and rulings (Pedram, 2026-11-17)

| #       | Question                                                          | Ruling (2026-11-17)                                                                                                                                                                                                                                                                                                                                                                                        | Effect on the plan                                      |
| ------- | ----------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------- |
| **Q1**  | Is Applied a real column?                                         | **Agreed as recommended:** the existing pseudo-column, relabelled; eight data columns, nine on screen.                                                                                                                                                                                                                                                                                                     | §1.2 as written                                         |
| **Q2**  | Type column name and values.                                      | **Agreed:** `campaigns.campaign_type`, `normal` / `for_wave`, immutable after create, default `normal`.                                                                                                                                                                                                                                                                                                    | §1.1                                                    |
| **Q3**  | Does the lock read where cards are now or where any has been?     | **Changed from my recommendation: NOW.** Locked while at least one card is in Selected, Filming, Submitted / in review, In edits, Client review or Approved. A card moved back to Invited or to Dropped does not lock.                                                                                                                                                                                     | §1.4, §3.3, L3a to L3c, L4                              |
| **Q4**  | Can any card be moved to Selected?                                | **Only an Accepted creator's card can enter Selected or any later working column. Any card can be moved to Dropped. Moves back are free. Refusal is a 422 with its own code** (`board.selection_requires_acceptance`).                                                                                                                                                                                     | §3.4, B2a, B2b                                          |
| **Q5**  | What do Selected and a hand move to Dropped do to the assignment? | **Agreed as recommended:** nothing.                                                                                                                                                                                                                                                                                                                                                                        | §3.4                                                    |
| **Q6**  | Can an agency edit the wave board's columns and automations?      | **Agreed:** no; 422 `board.wave_columns_fixed`; reset refuses.                                                                                                                                                                                                                                                                                                                                             | §1.2, §4.5, B1                                          |
| **Q7**  | For-a-wave campaigns when the flag goes OFF.                      | **Agreed:** they keep working; the link stays in the data.                                                                                                                                                                                                                                                                                                                                                 | §5                                                      |
| **Q8**  | Create-now needs a budget.                                        | **Agreed, subject to G1.** G1 found no publish rule on budget, so a guard is added for for-a-wave campaigns.                                                                                                                                                                                                                                                                                               | §3.1 (zero-budget guard), WB1                           |
| **Q9**  | Creator drafts on a for-a-wave campaign.                          | **Agreed:** unchanged.                                                                                                                                                                                                                                                                                                                                                                                     | §4.5 (the draft overdue scan still runs on assignments) |
| **Q10** | The board screenshot did not reach me.                            | **No screenshot is used.** Built from the design system and the existing board components; works like a ClickUp board (columns side by side with name and count, drag between columns, horizontal scroll, click opens detail). Card face: creator name, the Invited / Accepted / Declined label on Invited cards, the reserved script-tracker place. **No fees, contracts, payments, due dates or files.** | §4.4, W5                                                |
| **Q11** | Production Aurora PostgreSQL version.                             | **Must-confirm before `pl` is merged to `main`** (Pedram asks a colleague). Recorded in `production-line-sprints.md`. **It does not block the build.**                                                                                                                                                                                                                                                     | §8; `production-line-sprints.md` §6                     |
| **Q12** | Does staff get to link?                                           | **Agreed:** no; admin and manager link and unlink; staff move cards only.                                                                                                                                                                                                                                                                                                                                  | §3.2, B3                                                |

**Raised by the gaps (both ruled 2026-11-17):**

| #       | Question                                                         | Recommendation                                                                                                                                                                                               |
| ------- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------- |
| **Q13** | Card detail drawer on a wave board.                              | **RULED 2026-11-17: stays as it is.** Fee, offer, deliverables and due dates stay; only what AH-069 hides for a non-posting campaign stays hidden.                                                           | §4.4 item 6 |
| **Q14** | Draft-overdue scan on a for-a-wave campaign.                     | **RULED 2026-11-17: left as it is.**                                                                                                                                                                         | §4.5        |
| **Q15** | The creator's "Submit your draft" form on a for-a-wave campaign. | **RULED 2026-11-18: left as it is in PL-1 (Q9).** PL-6 replaces it with the footage hand-in. `waves_enabled` must not be turned on in production for real creators before that is decided (PL-6 open items). | §4.5        |

---

## 12. Size estimate and build order

**Size: M** (a column on a live table, a wave-aware board, two web surfaces and a PL-1.1 amendment). Estimates, not commitments:

- Backend: about 12 new files (two migrations, `CampaignType` enum, `WaveCampaignLink` service, campaign-side link controller and requests, `CampaignBrandChangeException`, wave board defaults and wave-aware provisioning branch) and about 14 edited (`Campaign`, `CampaignController`, both campaign requests, `CampaignResource`, `WaveService`, `WaveController`, `WaveResource`, Boards provisioning, reset, column and automation controllers, move service, `BoardResource`, `BoardCardResource`, `ErasureRegistry`, `AuditPayloadKeys`).
- Web: about 3 new files (`CampaignWaveCard.vue`, a chip component, board wave helpers) and 12 edited; 24 locale files edited; api-client types.
- Tests: about 10 new backend test files and 8 web specs; 7 PL-1.1 test files edited; walk seeder pair (2 files).
- Rough effort: 6 sub-steps; about 4 working days of build plus one for translations, the PostgreSQL runs, the walk and the review.

**Build order** (each sub-step independently green on SQLite and PostgreSQL where it touches a lock or a unique index):

1. **Schema and type.** M1, M2, `CampaignType`, request rules, `store` whitelist, resource attribute, immutability, forced posting off, flag check. P8 guard. Tests C1, C2, G1, S1, E1.
2. **Wave board data.** `BoardDefaults::waveColumns()`, wave-aware provisioning at creation, fixed-shape refusals, the two automations, card `invite_state`, the Selected rule. Tests C3, B1 to B5.
3. **The link rules.** P2 in `WaveService` and options; the P5 lock and its serialisation; campaign-side routes; P3 one-transaction create. The PL-1.1 test amendments. Tests L1 to L5, T1. **PostgreSQL run.**
4. **Web: campaign side.** Type option, pill, Wave card, create page. Tests W1 to W3.
5. **Web: wave side and board.** New wave dialog choices, wave page link editing, the wave board, chip, slot. Tests W4 to W6; strings and `waveKeys` in 24 locales; arch re-pins (W7).
6. **Close-out.** Seeder pair, full local gate set (including Erasure and PostgreSQL), the flag-off agency walk on the campaign targets with a comment-node check, the review file with a Production posture section, the migration rehearsal timing, `WORKING-PROCESS.md` PostgreSQL rule, Pedram's walk (section 10).

Commits: feature commit and docs commit as a pair (`WORKING-PROCESS.md` §2). **No push until Pedram has walked**, as in PL-1.1.

---

## Read and not read

**Read for this plan:** `production-line-sprints.md` §6 and the PL-1 lines, `pl-1-1-plan.md` (headings, §8, §11, §12), `pl-1-1-review.md` (own work), the Boards module (`BoardDefaults`, `BoardProvisioningService`, `BoardService`, `BoardCardService`, `BoardCardMoveService`, `BoardAutomationListener`, `CreateBoardCard`, routes, `BoardCardController::move`, `BoardController::show`, `BoardResource::hiddenColumnIds`), the board and campaign migrations (`boards`, `board_columns`, `board_cards`, `campaigns`, `creator_posts_content`, `campaign_applications`, brand exclusivity), `Campaign` (fillable and casts only), `CreateCampaignRequest`, `CampaignController::store` and the posting-flip refusal, `CampaignPolicy` headings, `AssignmentStatus`, `CampaignApplicationDecisionService`, `CampaignInvitationService` (headings and line 107), `ErasureRegistry` rows for campaigns and boards, `BoardApplicationsColumn.vue` (header), `CampaignForm.vue` (header and props), `CampaignDetailPage.vue` (tab list), `agency-routes.mjs`, both walk baselines (target names), the Ah120 walk seeder (creator helper), `capacitor.config.ts` (`webDir`), `Pl11WalkSeeder` (campaign lines), `WaveController::campaignOptions`, `WaveService`. **Added by the gaps pass (G1 to G4):** `CampaignStatus`, `CreateCampaignRequest`, `UpdateCampaignRequest` (rules and the listing `withValidator` block), `ValidatesJobsBoardListing` (the floor), `ValidatesAssignmentOffer`, `CounterAssignmentRequest` (currency only), `CampaignController::update`; `BoardAutomationService`, `OverdueScanService`, `BoardResetService` (bodies), `BoardColumns.vue`, `BoardColumn.vue`, `BoardView.vue`, `BoardCard.vue`, `useBoardStore.ts` (the optimistic move and revert), the `BoardCardDrawer.vue` tab and section list (by its `data-test` names, not every line); `CreatorJobBoardController` (`apply`, the card select and the docs), `CreatorJobCardResource`, `CreatorJobDetailResource`, `CreatorAssignmentDraftController` (the `creator_posts_content` lines); every PL-1.1 test and support file named in the G4 list, and `Pl11WalkSeeder` in full.

**Not read:** the Aurora version and the deploy runbook's migration step in detail (Q11 is a must-confirm before the merge to `main`); the full body of `BoardCardDrawer.vue` (786 lines: only its tabs and sections were listed) and of `CampaignListPage.vue`; whether any assignment-invite gate checks campaign status (not opened; `AssignmentInviteGate` returned nothing for status); the admin SPA's campaign use; the native iOS and Android projects and the store-build process; whether `lang/` carries all 24 locales for server messages; `AuditPayloadKeys` entries for campaign keys; the Erasure schema tests' exact column-coverage rule (the build confirms it by running the folder); the first 70 lines of `WaveCampaignLinkTest.php` were read in the earlier pass and not again; `BrandFileApiTest.php` was searched for campaign use (none) and not read line by line; the draft-notification listeners that react to the draft-overdue event.

---

## Amendments at build time (2026-11-18)

- **Q13, Q14 ruled** (§4.4 item 6, §11): the drawer stays as it is; the draft-overdue scan stays as it is.
- **C1:** the migration rehearsal uses synthetic rows generated locally, never production data (§2).
- **C2:** tech-debt row 41 records that a **normal** campaign can be set Active or listed with budget 0 today and nothing refuses it. Not fixed in this chunk (the WB1 guard covers for-a-wave campaigns only).
- **C3 (read before any code): can a creator be invited to a campaign in draft status? Yes.** `AssignmentInviteGate` reads only the hard-blacklist and availability checks; `CampaignInvitationService::invite` writes the assignment with no campaign-status check; `CampaignAssignmentController` refuses only on tenancy (404), blacklist (422) and availability (409). The only status check on the creator path is in `JobsBoardVisibility::visibleTo` (via `listedOnJobsBoard`): a creator can **apply** only to a campaign that is listed and whose status is draft, active or paused (`Campaign::LISTABLE_STATUSES`). **Nothing is loosened.** What the walk needs: the for-a-wave campaign must be **listed** (so creators can apply), which under WB1 needs **budget above 0**, plus the listing floor fields (description, duration, fee, languages, regions). Status may be draft or active. The walk seeder therefore creates "Bolt Summer Wave Campaign" as `for_wave`, `active`, `listed_on_jobs_board`, budget above 0, floor fields filled. Invites themselves need no status or budget.
- **C4:** the full body of `BoardCardDrawer.vue` is read before any board web code is touched (Stage 2).

### Migration rehearsal (2026-11-18, local, synthetic data only — C1)

No production data was copied, exported or sampled. The rows were generated locally by `generate_series` in a throwaway local PostgreSQL database (`pl12_test`, dropped afterwards): 200,000 synthetic `campaigns` rows and 200,000 synthetic `board_columns` rows, with the two new columns removed first.

| Migration                                                                | Time    | Table rewritten?             |
| ------------------------------------------------------------------------ | ------- | ---------------------------- |
| `add_campaign_type_to_campaigns` (NOT NULL, constant default `'normal'`) | 4.15 ms | no (`relfilenode` unchanged) |
| `add_stage_key_to_board_columns` (nullable, no default)                  | 2.26 ms | no (`relfilenode` unchanged) |

Lock: `ACCESS EXCLUSIVE` on the one table, for the duration of the `ALTER` (read from `pg_locks` inside the transaction), capped by `SET LOCAL lock_timeout = '5s'`. After the run all 200,000 campaigns read `normal` and all 200,000 columns have a null `stage_key`. No data was written by the migrations themselves.
