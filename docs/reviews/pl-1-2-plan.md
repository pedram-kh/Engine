# PL-1.2 plan: Campaign for a wave

**Date:** 2026-11-17. **Branch:** `pl`, from tip `55538abd`. **Status:** plan only: no code, no migration run, no push. No Cursor build prompt is issued until Pedram has read the plain-words brief, answered the open questions in section 11 and said go (`production-line-sprints.md` §2, ruling 9).
**Inputs:** `pl-1-2-inventory.md` (read it first; "inventory §n" points into it), `production-line-sprints.md` §6 (R1 to R10 and P1 to P9, binding), `pl-1-1-review.md`. Pedram's board screenshot: **not read** (it did not reach me; see section 11, Q10).
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

---

## 8. PROD-DATA RISK (stated first, before any code)

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

Colours: tokens from the existing `boardStatus` palette only (`status-*`, `BoardDefaults::colorTokens()`); the mapping is provisional until the screenshot is read (Q10).

**How they are created without touching normal campaigns:**

- `BoardDefaults` gains `waveColumns()` and `waveAutomations()` next to the existing `columns()` and `automations()`. The existing two methods are **not edited**.
- `BoardProvisioningService::provisionDefaults()` picks the set by `$board->campaign->campaign_type`. A normal campaign takes the exact existing path (a test pins the seven names, positions and ten automations).
- A for-a-wave campaign gets its board **at creation**, in the same transaction (`BoardService::ensureBoard`), so all nine exist "from the start". The lazy heal on first GET stays as the fallback and provisions the same set.
- **Two automations only** on a wave board: `assignment.invited → Invited`, `assignment.cancelled → Dropped`. Every other move is by hand in this chunk (P6); later sprints add automations. Accepted and Declined have **no automation**, so they stay in Invited by the engine's own behaviour (inventory §1).
- **The wave board's structure is fixed:** column create, rename, reorder, delete, reset-to-defaults and automation edit answer 422 `board.wave_columns_fixed` (Q6). `reset-to-defaults` is wave-aware only in that it refuses. A rename would break the P5 lock's reading of the stage; `stage_key` makes it independent of names anyway.

### 1.3 `board_columns.stage_key` (new nullable column, Boards module)

NULL on every existing column. Set only on a for-a-wave board's eight columns. It is what P5 and the Selected rule read, instead of a name or a position.

### 1.4 The P5 lock: what it reads

`WaveCampaignLink::isLocked(Campaign)` is true when, for the campaign's board, **a card is currently in, or a `board_card_movements` row ever moved a card into, a column whose `stage_key` is `selected`, `filming`, `submitted`, `in_edits`, `client_review` or `approved`.** Dropped does not count by itself; but a creator who was Selected and then Dropped **still locks** ("ever", Q3). A campaign with no board yet is unlocked.

### 1.5 The invite label (P6)

Derived on the wave board's card resource, not stored: `invite_state` = `invited` for assignment status invited or countered, `accepted` for accepted, `declined` for declined, absent for anything later or on a normal board. Declined never moves by itself (no automation). Re-invite (Declined to Invited) already exists.

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
- Run locally and on the test database only (standing rule). The rehearsal on a production-sized copy (above) is **part of the build**, with the timing recorded in the review file.
- **If a backfill is ever needed it is a separate, dry-runnable artisan command**, not a migration. None is needed now because the default is the correct value for every existing row.

---

## 3. API

All new behaviour is behind `waves_enabled` unless stated. Errors use the existing envelope (`ErrorResponse::single`).

### 3.1 Campaigns

- `POST agencies/{agency}/campaigns`: new optional `campaign_type` (`normal` default, `for_wave`). Flag OFF and `for_wave` named: **422 `campaign.wave_type_disabled`** (the endpoint exists today, so it validates; it does not 404). `for_wave` forces `creator_posts_content = false`, creates the board in the same transaction (§1.2). The whitelist in `CampaignController::store` gets the new column (the AH-054 catch).
- `PATCH …/campaigns/{campaign}`: `campaign_type` named and different: 422 `campaign.type_immutable`. `creator_posts_content: true` on for-a-wave: 422 `campaign.wave_posting_not_allowed`.
- `CampaignResource` (agency side): new attribute `campaign_type`, **always present** (the data exists regardless of the flag; the web ignores it with the flag OFF). With the flag ON and a linked wave: a `wave` relationship `{id, name, go_live_on, status}` (**P4**, the campaign page shows its wave). Not added to any creator resource.
- Policy: create and update are unchanged (admin and manager).

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

**Serialising with moves.** A move into Selected (or later) on a wave board takes `FOR UPDATE` on the linked wave row (if any) before it inserts the movement, so a link change and a first selection cannot interleave: whichever commits first wins, and the other sees it.

### 3.4 Boards

- `GET …/board`: on a wave board the resource adds `columns[].stage_key` and `cards[].invite_state`; **the key is absent on a normal board**, so a normal board's JSON is identical to today (pinned by a test). `hiddenColumnIds()` is not applied (the wave board has no posting column).
- `POST …/board/cards/{card}/move` (unchanged route, ability `invite`: admin, manager, staff): on a wave board, **a move into `selected` requires the card's assignment to be Accepted** (else 422 `board.selection_requires_acceptance`; Q4). Every other hand move is free, as today. Moving to Dropped by hand is a **card move only**; it does not cancel the assignment (a board drag is consequence-free; Q5). Cancel and Reject stay the real actions, and cancel lands in Dropped by its automation.
- Column and automation routes on a wave board: 422 `board.wave_columns_fixed` (§1.2).
- **By role:** staff can move cards (as now) but cannot create, link or unlink; admin and manager can do all.

### 3.5 P8, the brand guard

`Campaign::booted()` registers an `updating` listener: if `brand_id` is dirty and the original is not null, throw `CampaignBrandChangeException` (a `LogicException`). Creating is unaffected. **Limit, stated:** a query-builder `update()` bypasses model events; no such call exists (grep of `app`, `database`, `tests`), and the database has no trigger. A test pins the model path; another greps nothing (it is a convention note in the model docblock). `UpdateCampaignRequest` already carries no `brand_id` (PL-1.1 G2).

### 3.6 Audit and erasure

No new audit action. `campaign.created` (P3 and the normal form), `wave.created`, `wave.updated` (link, unlink and change, with the literal `campaign_id` keys already decided), `board.card_moved_manually`. Registry entries for the two new string columns and the decided audit key `campaign_type` (section 6, E-gates).

---

## 4. Web

**Built from the design system, named here as the standing rule requires.** There is **no Figma frame** for: the campaign type option, the "create a campaign now" option, and the link display (campaign page Wave card, wave page campaign row, link and unlink controls). The nine-column board follows Pedram's screenshot (**not read**; Q10). Empty, loading and error states of the Wave card are also undrawn.

### 4.1 What is built

| Piece                | File (agency web, `apps/main/src/modules/…`)                                                          | Notes                                                                                                                                                                                                                                                                                                           |
| -------------------- | ----------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Type option          | `campaigns/components/CampaignForm.vue`, `pages/CampaignCreatePage.vue`                               | Two radio cards "Normal campaign" / "For a wave". **Shown only with `features.waves` ON and only on create.** "For a wave" hides the posting toggle and the posting-window fields and sends `creator_posts_content: false`.                                                                                     |
| Type pill            | `campaigns/pages/CampaignListPage.vue`, `CampaignDetailPage.vue` header                               | Shown for for-a-wave only (a data fact, so also shown after the flag is turned OFF).                                                                                                                                                                                                                            |
| Wave card (P4)       | `CampaignDetailPage.vue` Overview, new `campaigns/components/CampaignWaveCard.vue`                    | Wave name and go-live date as a link to the wave page; Link, Change and Unlink (admin and manager) while unlocked; a plain "Locked: a creator has been selected" line afterwards. Link opens a picker over `wave-options`. **Only with the flag ON.**                                                           |
| New wave dialog (P3) | `waves/components/NewWaveDialog.vue`                                                                  | The campaign field becomes three choices: no campaign yet; an existing for-a-wave campaign (the PL-1.1 dropdown, now filtered by the server); **"Create a campaign for this wave now"** with one name field.                                                                                                    |
| Wave page (P4, P5)   | `waves/pages/WaveDetailPage.vue`, `NewWaveDialog` edit mode                                           | Campaign row with a link to the campaign; the edit dialog's campaign field becomes editable (change or remove) while unlocked, read-only with the reason when `link_locked`.                                                                                                                                    |
| Wave board           | `boards/components/BoardView.vue`, `BoardColumns.vue`, `BoardCard.vue`, `BoardApplicationsColumn.vue` | A wave board renders Applied (the pseudo-column, relabelled) plus the eight real columns. Invited cards show an Invited / Accepted / Declined chip. Column add, rename, reorder, delete, reset and automation controls are **hidden** on a wave board (the server refuses them too). The `scripts` slot (§1.6). |
| api-client           | `packages/api-client/src/types/`                                                                      | `CampaignType`, `campaign_type`, `wave` relationship, `link_locked`, `invite_state`, `stage_key`, `new_campaign`. All additive, all agency-side.                                                                                                                                                                |

### 4.2 Routes

No new route. The wave page route (`waves.detail`) and the campaign detail route are unchanged; the campaign-side link uses the existing campaign page.

### 4.3 Flag OFF leaves the screens unchanged

- The type option, the Wave card, the new dialog choices and the wave routes are drawn only when `features.waves` is true.
- The board for a **normal** campaign takes the same branches, with the same JSON, as today. A wave-board branch is chosen by `campaign_type`, which is `normal` for every existing campaign.
- **Proof, as in PL-1.1:** the agency walk on the 12 campaign targets (`campaigns`, `campaign-create`, `campaign-detail`, dark and light, both widths) plus the 2 `campaigns` crossover targets must be identical to the baseline with the flag OFF, **checked for stray comment nodes** (the lesson of PL-1.1: a false `v-if` leaves `<!--v-if-->`; use the one-item `v-for` pattern or a class binding where a branch would otherwise leave a node). The board tab is not a walk target; its flag-off sameness is a Vitest DOM pin.
- The frozen files (`agency.css`, `agencyStatusTones.ts`) will be edited again for the type pill and the chip; the `agency-frozen` hashes are re-pinned in the same commit with the reason stated, as in PL-1.1. No at-rules in `agency.css`.

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

| #   | Gate                            | Test (Pest)                                                                                                                                                                                             | Break-revert                                                                               |
| --- | ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| C1  | Type default and immutability   | New campaign without type is `normal`; `for_wave` forces `creator_posts_content` false; changing type and `creator_posts_content: true` on for-a-wave are 422                                           | Drop the immutability check; drop the force                                                |
| C2  | Flag gate on the type           | Flag OFF and `for_wave`: 422 `campaign.wave_type_disabled` and no row; flag ON: created with a board of 8 columns and 2 automations                                                                     | Remove the flag check                                                                      |
| C3  | Normal campaigns untouched (P7) | A normal campaign's board still seeds exactly the seven default names and ten automations; its board JSON has no `stage_key` or `invite_state` keys; all existing Boards and Campaigns tests stay green | Make provisioning use the wave set for every campaign                                      |
| L1  | Type rule on link (P2)          | A normal campaign is refused on wave create, wave edit and the campaign-side link with the same 422 body as a foreign one (the B4 identical-body test gains it); options list only for-a-wave           | Remove the type filter in `linkableCampaign`                                               |
| L2  | One transaction (P3)            | Campaign, board and wave created together and linked; a forced failure at each of the three steps leaves **zero** new rows (PostgreSQL run)                                                             | Move the wave create outside the transaction                                               |
| L3  | Link lock (P5)                  | Change and remove allowed with no selection; after a card is moved into Selected: 422 `wave.campaign_link_locked`; after Selected then Dropped still locked; a Declined or Invited card does not lock   | Read only current position, not movement history; remove the lock                          |
| L4  | Lock serialisation              | On PostgreSQL the link update and the Selected move both issue `for update` on the wave row (`DB::listen`); a stale-model sequence test as the F2 tests                                                 | Remove `lockForUpdate` in either path (the SQL assertion goes red; SQLite alone would not) |
| L5  | Both sides, one path            | Linking from the campaign side equals linking from the wave side (same errors, same audit); unlink; second wave for one campaign 422 `already_linked` (PostgreSQL unique violation, savepoint)          | Give the campaign side its own rule                                                        |
| B1  | Board fixed shape               | Column create, rename, reorder, delete, reset and automation edit on a wave board: 422 `board.wave_columns_fixed`; the same calls on a normal board still work                                          | Remove the check                                                                           |
| B2  | Selected rule                   | A move into Selected needs an Accepted assignment (Invited and Declined refused with 422); other moves free; Dropped by hand does not change the assignment                                             | Remove the rule; make Dropped cancel                                                       |
| B3  | Roles on moves                  | Staff can move; staff cannot link, unlink or create a for-a-wave campaign; another agency's board, campaign or wave is 404                                                                              | Widen the policy                                                                           |
| B4  | Invite label                    | `invite_state` for invited, countered, accepted, declined; absent later; Declined never moves; re-invite works                                                                                          | Route Declined to Dropped                                                                  |
| B5  | Automations                     | Invited creates the card in Invited; cancel lands in Dropped; no other event moves a card on a wave board                                                                                               | Add a default automation                                                                   |
| G1  | Brand guard (P8)                | Changing `brand_id` on a saved campaign throws; creating and saving other fields do not                                                                                                                 | Remove the listener                                                                        |
| T1  | Tenancy                         | Every new route: another agency gets 404 and no write; the wave and campaign must share agency and brand                                                                                                | Drop the agency check on the campaign-side routes                                          |
| E1  | Erasure                         | `ErasureRegistrySchemaTest`, `ErasureSchemaTest` and the audit guard pass with the two new columns and the key `campaign_type` decided                                                                  | Remove one decision (red)                                                                  |
| S1  | Migrations                      | Both migrations add the column with the stated default and leave every existing row's value `normal` / NULL; the up and down both run                                                                   | Change the default                                                                         |

### PL-1.1 tests that change because of P2 and P5 (named, so nothing is a surprise)

`tests/Feature/Modules/Waves/WaveCampaignLinkTest.php` (B4 "links an own-brand unlinked campaign" and the identical-body test; B5 race and already-linked; B6 options; **B14 "refuses to change or remove an existing link" becomes "allowed while unlocked, refused after a selection"**; B14 set-link; B15 cancelled), `WaveApiTest.php` (every create with a `campaign_id`), `WaveSchemaTest.php` (B5 direct inserts: unaffected by the type, which the database does not check; verify), the F2 stale-model tests (they must now build for-a-wave campaigns), and `tests/Support/WaveFixtures.php` (`campaign()` gains a type argument; a `waveCampaign()` helper is added; **the default stays normal**, so only the tests that link are edited). Web: `NewWaveDialog.spec.ts`, `WaveDetailPage.spec.ts`, `waveTestKit.ts` (campaign option fixtures get a type). `Pl11WalkSeeder` and `Pl11WalkSeedersTest` (below). Estimate: 4 backend files and 3 web files edited; I have **not read** those tests line by line.

### Web gates (Vitest)

| #   | Gate                                                                                                                                               | Break-revert                                  |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------- |
| W1  | Type option hidden with the flag OFF; shown ON; "For a wave" hides the posting toggle and sends `creator_posts_content: false`                     | Show regardless of flag                       |
| W2  | Flag-off DOM of the create form, campaign detail and a normal board equals today's (no comment nodes)                                              | Use a `v-if` that leaves a node               |
| W3  | Wave card: shows, links, changes, unlinks, locked state with the reason, role gating                                                               | Drop the role gate; ignore `link_locked`      |
| W4  | New wave dialog: three choices; "create now" sends `new_campaign: {name}` and no `campaign_id`; the name is required; the 422 codes map to strings | Send both fields                              |
| W5  | Wave board renders Applied plus eight columns in order, the chip on Invited cards, no column controls, no `scripts` DOM when the slot is empty     | Render the column controls; add a placeholder |
| W6  | Hand move calls the move endpoint; a refused Selected move shows the message and the card returns                                                  | Optimistic move without rollback              |
| W7  | Arch: `agency-frozen` re-pin, status tones, route guards, i18n key coverage in 24 locales                                                          | As PL-1.1                                     |

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

| #   | Step                                                                                                                                   | Expect                                                                                                                                                                    |
| --- | -------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | **Flag OFF.** Sign in as `admin@` on 5173. Open Campaigns, New campaign, a campaign page, Bolt Evergreen's board.                      | No type option, no Wave card, no pill. Everything as before. A normal board shows its seven columns.                                                                      |
| 2   | Admin app: turn `waves_enabled` ON. Reload as `admin@`.                                                                                | The type option appears on New campaign.                                                                                                                                  |
| 3   | New campaign, choose **For a wave**, brand Bolt Food, name "Walk Wave Campaign".                                                       | The posting toggle and posting-window fields are gone. Created.                                                                                                           |
| 4   | Open its Board tab.                                                                                                                    | **Nine columns in order:** Applied, Invited, Selected, Filming, Submitted / in review, In edits, Client review, Approved, Dropped. No Paid. All exist before any creator. |
| 5   | Brand page, Waves panel, **New wave**: name, date, markets, **Create a campaign for this wave now**, name "Created With Wave". Create. | Wave and campaign appear together. Open the wave: its campaign is shown, with a link. Open that campaign: its wave is shown.                                              |
| 6   | New wave again; the campaign dropdown.                                                                                                 | **Only for-a-wave campaigns**: Bolt Normal Promo is not offered.                                                                                                          |
| 7   | On Wave 7's page, edit and pick "Bolt Summer Wave Campaign". Then, from the campaign page, check its Wave card.                        | Link shown on both sides.                                                                                                                                                 |
| 8   | On the campaign page, **Change** the wave to Wave 8; then **Unlink**.                                                                  | Both work while nobody is selected. Link it again to Wave 7.                                                                                                              |
| 9   | Sign in as `maya@` (creator), apply to the listed Bolt Summer Wave Campaign. As `admin@`, open its Board.                              | Maya is a card in **Applied**.                                                                                                                                            |
| 10  | Invite Pia and Daniel (and Eva). As Pia and Eva accept; as Daniel decline.                                                             | In **Invited**: Pia and Eva labelled **Accepted**, Daniel labelled **Declined**; all three **stay in Invited**. Nothing moves to Dropped.                                 |
| 11  | Move Daniel (Declined) to **Selected**.                                                                                                | Refused with the message. Move Pia to **Selected**. Works.                                                                                                                |
| 12  | Back on the campaign page and Wave 7's page, try **Change** and **Unlink**.                                                            | **Locked**, with the reason. The server also refuses (try from the wave page's edit dialog).                                                                              |
| 13  | Hand-move Pia on through Filming, Submitted / in review, In edits, Client review, Approved, and one card to Dropped.                   | All moves work; the movement history lists them. Sign in as `staff@`: staff can move cards but has no Link, Unlink or New wave. A creator sees no change.                 |
| 14  | Open **Bolt Evergreen** (the old campaign).                                                                                            | Its **old seven-column board and card are exactly as before**.                                                                                                            |
| 15  | Sign in as `admin-b@` (agency B).                                                                                                      | Sees none of agency A's campaigns, waves or boards; a pasted agency A campaign or wave address is a not-found.                                                            |
| 16  | Admin app: turn `waves_enabled` **OFF** by hand. Reload as `admin@`.                                                                   | The type option and the Wave card are gone; the for-a-wave campaign and its nine-column board **still work**; Bolt Evergreen unchanged.                                   |
| 17  | `php artisan db:seed --class='Database\Seeders\Pl12WalkCleanupSeeder'`.                                                                | The cleanup does **not** touch the flag. MinIO is not involved in this chunk.                                                                                             |

---

## 11. Open questions for Pedram

| #       | Question                                                                                                                                                                                                                                                    | Recommendation                                                                                                                                                                                                                                                                                                                  |
| ------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Q1**  | **Is Applied a real column?** Today applications are not cards (`board_cards.assignment_id` is NOT NULL and UNIQUE). A real column needs a card per application, which means changing `board_cards` and the cascade rules, with risk to every normal board. | **Keep Applied as the existing pseudo-column, relabelled, so the board shows nine columns and the data has eight.** Zero change to `board_cards`. Behaviour is the same on screen (one card per creator; an accepted application leaves Applied and the invited creator appears in Invited).                                    |
| **Q2**  | **Type column name and values.**                                                                                                                                                                                                                            | `campaigns.campaign_type`, values `normal` and `for_wave`, **immutable after create**, default `normal`.                                                                                                                                                                                                                        |
| **Q3**  | **Does the P5 lock look at where cards are now, or where any card has ever been?** If only now, a creator selected and then dropped would reopen the link.                                                                                                  | **Ever** (movement history): once a creator has been selected the link stays locked, even if that creator is later dropped or moved back. Simple to explain; avoids a wave whose scripts were started against one campaign being re-pointed.                                                                                    |
| **Q4**  | **Can any card be moved to Selected?** Today any move is allowed.                                                                                                                                                                                           | **Only an Accepted creator can be Selected** (server rule; Invited and Declined are refused). Every other hand move stays free, as today.                                                                                                                                                                                       |
| **Q5**  | **What do Selected and a hand move to Dropped do to the assignment?**                                                                                                                                                                                       | **Nothing.** A board drag stays consequence-free (the existing AH-059 principle); Selected is a board position until PL-3's hand-off (R7). Dropping a card by hand does not cancel the assignment; the Cancel and Reject actions do, and cancel lands in Dropped by automation.                                                 |
| **Q6**  | **Can an agency edit the wave board's columns and automations?** Today they can on a normal board.                                                                                                                                                          | **No.** The nine columns are fixed (422 `board.wave_columns_fixed`; reset-to-defaults refuses too). Reason: the P5 lock and the Selected rule read stage keys, and "all nine exist from the start".                                                                                                                             |
| **Q7**  | **For-a-wave campaigns that already exist when the flag goes OFF.**                                                                                                                                                                                         | **They keep working** (type pill, nine-column board, hand moves); only the wave-dependent parts disappear (Wave card, linking, wave endpoints 404). The link stays in the data. Hiding the board would strand cards.                                                                                                            |
| **Q8**  | **"Create a campaign now" needs only a name, but a campaign requires a budget and currency.**                                                                                                                                                               | **Budget 0 in the agency's default currency, objective ugc, status draft, posting off.** The user fills the rest in later. **Not read:** whether a budget of 0 blocks publishing; the build checks it and, if it does, the campaign simply cannot be published until a budget is entered (an existing rule I would not change). |
| **Q9**  | **Creator drafts on a for-a-wave campaign.** Draft submit and review still exist (hand-off at approval).                                                                                                                                                    | **Leave them unchanged.** On a wave board no draft event moves a card (no automation), so nothing breaks; Filming to Approved are hand moves now and are automated in a later sprint. Say if drafts should be refused on for-a-wave campaigns instead.                                                                          |
| **Q10** | **The board screenshot did not reach me.** Colours, card face, chip wording and column header counts come from the rulings only.                                                                                                                            | **Please re-attach it before the build prompt.** It does not block the data model or the API; it blocks the board visual details.                                                                                                                                                                                               |
| **Q11** | **Production Aurora PostgreSQL version.** The "no table rewrite" claim holds from PostgreSQL 11.                                                                                                                                                            | Confirm the version. The migration is rehearsed on a production-sized copy either way, with `lock_timeout`. If the version is older than 11, the column is added nullable and the default applied by a separate, dry-runnable command (a backfill); I would tell you before building.                                           |
| **Q12** | **Does staff get to link?** Today wave create and edit are admin and manager.                                                                                                                                                                               | Keep it: link and unlink are admin and manager; staff move cards only.                                                                                                                                                                                                                                                          |

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

**Read for this plan:** `production-line-sprints.md` §6 and the PL-1 lines, `pl-1-1-plan.md` (headings, §8, §11, §12), `pl-1-1-review.md` (own work), the Boards module (`BoardDefaults`, `BoardProvisioningService`, `BoardService`, `BoardCardService`, `BoardCardMoveService`, `BoardAutomationListener`, `CreateBoardCard`, routes, `BoardCardController::move`, `BoardController::show`, `BoardResource::hiddenColumnIds`), the board and campaign migrations (`boards`, `board_columns`, `board_cards`, `campaigns`, `creator_posts_content`, `campaign_applications`, brand exclusivity), `Campaign` (fillable and casts only), `CreateCampaignRequest`, `CampaignController::store` and the posting-flip refusal, `CampaignPolicy` headings, `AssignmentStatus`, `CampaignApplicationDecisionService`, `CampaignInvitationService` (headings and line 107), `ErasureRegistry` rows for campaigns and boards, `BoardApplicationsColumn.vue` (header), `CampaignForm.vue` (header and props), `CampaignDetailPage.vue` (tab list), `agency-routes.mjs`, both walk baselines (target names), the Ah120 walk seeder (creator helper), `capacitor.config.ts` (`webDir`), `Pl11WalkSeeder` (campaign lines), `WaveController::campaignOptions`, `WaveService`.

**Not read:** Pedram's board screenshot (never received); the Aurora version and the deploy runbook's migration step in detail; `BoardCardDrawer.vue`, `BoardView.vue`, `BoardColumns.vue` (beyond a header), `useBoardStore.ts`, `BoardAutomationService.php`, `BoardResetService.php`, `OverdueScanService.php` bodies; the creator apply endpoint and `CreatorJobCardResource`; `CampaignListPage.vue`; the admin SPA's campaign use; the native iOS and Android projects and the store-build process; publish rules for a zero budget; whether `lang/` carries all 24 locales for server messages; every PL-1.1 test named above (listed by grep, not line by line); `AuditPayloadKeys` entries for campaign keys; the Erasure schema tests' exact column-coverage rule (the plan assumes a new string column needs a decision, which the build confirms by running the folder).
