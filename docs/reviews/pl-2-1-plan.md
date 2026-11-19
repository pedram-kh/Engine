# PL-2.1 plan: Workspace, USPs and Personas

**Date:** 2026-11-19. **Branch:** `pl`, from tip `d790eb12`. **Status:** plan. Docs only: no code, no migration file, no push. No build starts until Pedram has answered section 11.
**Inputs:** `pl-2-1-inventory.md` (read it first; "inventory §n" points into it), `production-line-sprints.md` (PL-2 now four chunks, §1 and §6), `pl-1-3-plan.md` and `pl-1-3-review.md` (the template and the baselines), `WORKING-PROCESS.md` §4.1 and §4.2, `PROJECT-WORKFLOW.md` §12.
**Standing rules applied:** schema-only migrations, the alarm rule, break-revert on every gate, the 24-locale gate, one flag per sprint and never reused, `WORKING-PROCESS.md` §4.1 local-run rules and §4.2 (real browser, wide and narrow), the Erasure folder before every push, the PostgreSQL rule of section 6.

**Source note.** The developer brief ("Strategy & Script Writing Developer Brief v2") was relayed by Pedram as S1 to S10 and was **not read by Cursor**. The two frames are 13:428 (USPs) and 13:828 (Personas) in file `lYy90qzKk029vjoVCPExs2`.

**Goal.** From a wave, an agency user opens the **Strategy & Scripts** workspace: a header, a six-step stepper, step 1 **USPs** (one set per wave) and step 2 **Personas** (one set per market). Everything is typed by hand. It sits behind a **new flag, OFF by default**. With the flag off the wave page is exactly as PL-1 left it.

**Section numbers** follow `pl-1-3-plan.md` (the rulings first, then 8, then 1 to 7, 9 to 12). Section 8, the PROD-DATA RISK line, comes first after the rulings because the alarm rule puts it before anything else.

## Rulings for this chunk (Pedram's brief, S1 to S10) and where each lands

| #       | Ruling (short)                                                                                                          | Where it lands     |
| ------- | ----------------------------------------------------------------------------------------------------------------------- | ------------------ |
| **S1**  | A new flag; say how it relates to `waves_enabled`                                                                       | §5                 |
| **S2**  | Everything by hand, no AI                                                                                               | §1.3, §9           |
| **S3**  | USPs: one set per wave, audience title line, 5 fields, fixed colour from six, cap 6, warn below 4, drag order           | §1.1, §1.4, §3, §4 |
| **S4**  | Personas: per market, 2 to 8, tabs from the wave's markets, the card fields, pronoun, angles by hand, copy from market  | §1.2, §1.3, §3, §4 |
| **S5**  | Deleting a USP used by an angle needs a confirmation that lists what is affected; say what else will hang off a USP     | §3.4               |
| **S6**  | Admin, manager, staff create and edit; deleting an in-use USP is admin and manager only                                 | §3.3               |
| **S7**  | Step status shows counts only; no "done" rule; the tracker and the Brand page bar are not changed                       | §4.1, §5           |
| **S8**  | Stepper works for USPs and Personas; Matrix and Concepts present, not built; Creators and Scripts locked                | §4.1               |
| **S9**  | Left out: add market, script languages, notes and comments, Preview and Share, copy from an earlier wave, source labels | §9                 |
| **S10** | Every screen built from the design system is named                                                                      | §4.2               |

---

## 8. PROD-DATA RISK (stated first, before any code)

**PROD-DATA RISK: NONE for existing data, but brief write locks on live tables.** Four additive migrations. None reads, writes or backfills a row.

- **M1** adds one **nullable** column `usp_audience_title` (no default) to `waves`: catalogue-only (no rewrite) but an **AccessExclusiveLock on `waves`**.
- **M2, M3, M4** each create a **new, empty** table. Their foreign keys take a **ShareRowExclusiveLock on `agencies` and `waves`** (and, for M4, on the two new tables): **writes to those tables wait, reads do not**, for the length of the migration transaction. AccessExclusive only on the new tables.
- **Measured on a throwaway local PostgreSQL database** (`pl21_probe`, container `catalyst-postgres`, PostgreSQL 16, the PL-1 migrations run with `php artisan migrate`, 20 synthetic waves and 70 synthetic agencies from `WaveFactory`; **the proposed DDL was typed by hand in `psql` inside `BEGIN … COMMIT` with `SET LOCAL lock_timeout = '5s'`; no migration file exists**; the database is dropped). Lock modes were read from `pg_locks` before commit:

| Migration                          | Statement time (psql)    | Locks held on existing tables                                                               |
| ---------------------------------- | ------------------------ | ------------------------------------------------------------------------------------------- |
| M1 `ALTER TABLE waves ADD COLUMN`  | 0.47 ms                  | `waves`: AccessExclusive                                                                    |
| M2 `wave_usps` + 2 indexes         | 7.0 ms + 0.9 ms + 0.8 ms | `agencies`, `waves`: ShareRowExclusive                                                      |
| M3 `wave_personas` + 1 index       | 4.0 ms + 0.6 ms          | `agencies`, `waves`: ShareRowExclusive                                                      |
| M4 `wave_persona_angles` + 1 index | 4.3 ms + 0.7 ms          | `agencies`, `waves`, `wave_usps`, `wave_personas`: ShareRowExclusive (the last two are new) |

These are laptop numbers on a nearly empty database; the build repeats the rehearsal through `artisan migrate` and records the numbers in the review (the PL-1.3 rule). The production server version is not read here; the lock modes are the documented ones for `CREATE TABLE … REFERENCES` and `ADD COLUMN`.

- **Why no user foreign key.** The new tables carry **no `created_by` or `updated_by` user column**; who did what is in the audit log. That keeps `users` (the busiest table) out of the lock list and keeps the erasure registry `refs` empty. This is a deliberate choice (section 1.5).
- **`waves` is not in production yet.** PL-1 is built and walked, not merged, not deployed (`production-line-sprints.md` §6). So the four migrations will run in the same deploy that creates `waves`, on empty tables, once `pl` is merged. Stated from the sprint file, not from any server. If PL-1 deploys first, M1 to M4 are a second, millisecond-long lock event on `waves` and `agencies`; `lock_timeout = 5s` (PostgreSQL only) makes a blocked migration fail instead of hanging.
- **No data-writing migration.** Nothing writes a USP or persona until the flag is on and a person types one.
- `down()` is `dropIfExists` (reverse order) and `dropColumn`: lossy, a rollback tool only before the flag is armed.
- **A wave edit that removes a market is now refused when that market has personas** (§1.9). That is a behaviour change to `WaveService::update` that applies **even with the strategy flag off** (it is a data-integrity check; with no persona rows it never fires). It adds one query to a wave update.

---

## 1. Data model

All three tables live in a **new `Strategy` module** (`app/Modules/Strategy`, own provider registered in `bootstrap/providers.php` next to `WavesServiceProvider`; web `modules/strategy`). Reason: PL-2.2 to PL-2.3 (matrix, concepts) and PL-3 add tables here, and the web module must stay outside the frozen `modules/agency`. The wave row, the wave routes and `WaveService` stay in `Waves` (the Strategy module calls them).

Common to all: bigint `id`, `ulid` (unique, the route key), `agency_id` (FK `agencies`, RESTRICT), `wave_id` (FK `waves`, RESTRICT), timestamps; `BelongsToAgency` on the model; every FK is `ON DELETE RESTRICT` (no hidden cascades; deletes are explicit, audited service code).

### 1.1 `wave_usps` (M2)

| Column                                     | Type           | Notes                                                                                                             |
| ------------------------------------------ | -------------- | ----------------------------------------------------------------------------------------------------------------- |
| `color_key`                                | varchar(16)    | One of `violet, green, blue, amber, teal, pink` (the S3 order). **Stored**, fixed for the life of the USP (§1.4). |
| `position`                                 | smallint       | 1 to 6; the card number "01" to "06" and the future matrix row order. Rewritten 1 to n by reorder and delete.     |
| `title`                                    | varchar(40)    | Required.                                                                                                         |
| `explainer`, `creative_angle`, `copy_seed` | text, nullable | Limits in §1.6.                                                                                                   |

Indexes: `unique (wave_id, color_key)` (the cap-6 belt: six keys, so a 7th row cannot exist), `unique (wave_id, id)` (target of the composite foreign keys of M4), `index (wave_id, position)` (**non-unique**, as the board columns, so a reorder never collides mid-rewrite), `index (agency_id)`.

**Audience title (S3)** is **one per wave**, so it is a column on `waves`, `usp_audience_title` varchar(80) nullable (M1), not a table. Alternative: a one-row-per-wave table, which keeps `waves` out of the AccessExclusive list at the cost of one more table. Not chosen: the lock is 0.47 ms on a table that is empty in production.

### 1.2 `wave_personas` (M3)

| Column                                      | Type           | Notes                                                                                                                         |
| ------------------------------------------- | -------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `market`                                    | char(2)        | A code from `waves.markets`. **No FK** (there is no markets table, inventory §3); checked in code under the wave lock (§1.9). |
| `slot`                                      | smallint       | 1 to 8: the first free slot at creation. Fixes display order, the default code and the avatar colour (§1.4).                  |
| `code`                                      | varchar(8)     | The chip, default `<market><slot>` (SK1). Editable text, 2 to 8 characters, `A–Z 0–9` (Q3). `unique (wave_id, market, code)`. |
| `title`                                     | varchar(60)    | "The Office Lunch Organiser". Required.                                                                                       |
| `name`                                      | varchar(40)    | "Zuzana". Required. An invented name (§3.6).                                                                                  |
| `pronoun`                                   | varchar(8)     | `she`, `he`, `they`. Required, default `they`. Drives the labels only.                                                        |
| `summary`                                   | text, nullable | The short explainer under the header.                                                                                         |
| `core_job`, `need`                          | text, nullable | Core job; What they need (paragraphs).                                                                                        |
| `drivers`, `goals`, `self_talk`, `barriers` | json, nullable | Lists of strings: Emotional drivers; Values & goals; What they tell themselves; Barriers & frictions.                         |

Indexes: `unique (wave_id, market, slot)` (the cap-8 belt per market), `unique (wave_id, market, code)`, `unique (wave_id, id)`, `index (agency_id)`. **No `avatar` column**: the avatar is the initials of `name` on the persona colour (frame); no image upload exists in this chunk.

### 1.3 `wave_persona_angles` (M4)

| Column        | Type         | Notes                                                                                                                    |
| ------------- | ------------ | ------------------------------------------------------------------------------------------------------------------------ |
| `persona_id`  | bigint       | With `wave_id`: composite FK `(wave_id, persona_id) → wave_personas (wave_id, id)`, RESTRICT.                            |
| `usp_id`      | bigint       | With `wave_id`: composite FK `(wave_id, usp_id) → wave_usps (wave_id, id)`, **RESTRICT**.                                |
| `explanation` | varchar(140) | One line. Required.                                                                                                      |
| `origin`      | varchar(16)  | `manual` only in PL-2.1. A later chunk may add `matrix`; PL-9 may add `ai`. The card shows "Added by hand" for `manual`. |

`unique (persona_id, usp_id)` (one angle per USP per persona, as the frame's "Linked"), `index (usp_id)` (the in-use check). Display order = `id` (insertion order, as the frame; no reorder). **The composite FKs make "a USP must belong to the wave" and "a persona must belong to the wave" true in the database**, not only in code: an angle cannot point at a USP of another wave even if every service check were removed.

**Nothing in PL-2.1 reads these as "done".** S2: no AI column, no generator, no import.

### 1.4 Colour: stored for USPs, derived for personas

- **USP colour is stored** (`color_key`), because S3 says _fixed colour per USP_: the colour belongs to the card, not to its place. Reorder moves the card and keeps its colour; the number ("01") follows the position. A new USP takes the **first palette key not used in this wave** (in the order purple, green, blue, amber, teal, pink); deleting 03 and adding another gives blue again.
- **Persona colour is derived** from `slot` with the same ordered list, so it is not stored. Slots 7 and 8 need a rule: the palette has six colours (Q2). Recommendation: **wrap** (slot 7 violet, slot 8 green); the code chip and the name tell them apart; red is avoided because it reads as an error.
- The web maps a key to a colour with `brandColorVar()` (an existing, frozen helper, imported not edited); the six USP keys are all valid brand keys (inventory §1.2), so **no new token and no frozen-file edit**. The API keeps its own ordered list in a `UspColor` enum.

### 1.5 No user columns, no soft delete

USPs, personas and angles are **hard-deleted** (the delete is the user's act, confirmed and audited; a soft delete would keep text nobody can see). The audit row holds ids, counts and field names, not the text (§3.5). Deleting a wave is a soft delete and leaves children in place, unread.

### 1.6 Free-text limits (proposals; the frames draw none, Q8)

Counted in **characters** (`mb_strlen`, after trimming), enforced by the request class and, for lengths, by the column size:

| Field                                           | Limit                            | Frame's longest   |
| ----------------------------------------------- | -------------------------------- | ----------------- |
| Audience title                                  | 80                               | 30                |
| USP title                                       | **40** (S3)                      | 26                |
| USP explainer                                   | 400                              | 152               |
| USP creative angle                              | 300                              | about 100         |
| USP copy seed directions                        | 300                              | 96                |
| Persona code                                    | 2 to 8, `A–Z 0–9`                | 3                 |
| Persona title                                   | 60                               | 26                |
| Persona name                                    | 40                               | 6                 |
| Persona summary                                 | 300                              | 113               |
| Core job; What they need                        | 300 each                         | 126               |
| Each list (drivers, goals, self talk, barriers) | **6 items**, 120 characters each | 3 items, about 55 |
| Angle explanation                               | 140                              | 51                |

An empty list item is dropped; a list with more than 6 items or an item over 120 is 422. A text field that is only whitespace is `null` (optional) or 422 (required).

### 1.7 Sanitising and escaped rendering

- **Stored as typed**, not HTML-stripped (stripping would change legitimate text such as "<3" or "A & B"). The server removes only **NUL and other control characters except tab and line feed**, turns CRLF into LF, and trims.
- **Rendered escaped everywhere.** The new module uses `{{ }}` only; **no `v-html`**, no `innerHTML`, no markdown. Line breaks are shown with CSS `white-space: pre-line`. A spec reads every `.vue` file under `modules/strategy` and fails on `v-html` (gate X6). Anything that later prints this text (the matrix, a PDF, the client portal, an AI prompt) must escape for its own medium; PL-5 and PL-9 plans must say so.
- A hostile string (`<img src=x onerror=alert(1)>`) is stored and shown as plain text; the walk types one (§10).

### 1.8 Save model and two people on one card (Q4)

- **No autosave** (none exists in the repo; inventory §4). A USP or persona is edited in a **dialog with an explicit Save**; an angle is added or edited inline with its own Save. A drag reorder saves on drop.
- **The PATCH sends only the fields that changed** (the form's dirty fields). The server writes only those. Two people editing **different** fields of one card both keep their changes; editing the **same** field is **last write wins** (the PL-1.3 Q11 precedent). A list field counts as one field.
- A save that finds the card gone (deleted by someone else) gets 404; the dialog shows "This card was removed by someone else" and the grid reloads. A save that races a delete is decided by the row lock (§1.10).
- Not chosen: a version column with 409 on mismatch. It prevents silent overwrite of the same field but adds a conflict dialog nobody has designed. Offered as Q4.

### 1.9 Markets and what happens to personas when a market is removed

- A persona names a market code. A wave edit that **removes** a code that still has personas is **refused with 422 `wave.market_has_personas`**, carrying the list of markets and persona counts; the edit dialog shows "Remove the personas of SK first (3)". It is checked **inside the wave-row lock** that `WaveService::update` already takes, so it cannot interleave with a persona being created in that market (§1.10).
- Not chosen: deleting the personas with the market (silent loss of long hand-written text), or keeping hidden orphan rows (they would count against nothing and show nowhere).
- **Adding** a market changes nothing: the new tab shows the empty state. USPs are per wave, so a market change never touches them (Q5).
- "Market must belong to the wave" is enforced on persona create, persona copy (both ends) and the personas list (`?market=` not in the wave is 422).

### 1.10 Server-side limits that are safe under concurrent requests

Every strategy write runs in a transaction that starts with **`Wave::query()->whereKey($id)->lockForUpdate()`** (the wave-row lock `WaveService::update` and `WaveStepService` use). **Lock order is always wave row first, then card rows by ascending id**, so two strategy writes (and a wave edit) are serialised per wave and cannot deadlock. Contention is low: one agency team on one wave.

- **USP cap 6.** Under the lock: `count < 6` else 422 `usp.max_reached`; the first free `color_key` is taken. Belt: `unique (wave_id, color_key)` (a 7th row cannot exist even if the lock is missing; on a unique violation the service retries once, then 422, as `WaveStepService`).
- **Persona cap 8 per market.** Under the lock: market in `waves.markets`, `count < 8` else 422 `persona.max_reached`; the first free `slot`. Belt: `unique (wave_id, market, slot)`.
- **Copy** takes the lock once and is all-or-nothing (§3.2).
- **Reorder** takes the lock, compares the posted list with the wave's current ulids, 422 `usp.order_mismatch` if different (a stale drag after someone added a card), writes positions 1 to n.
- **Delete USP** counts angles under the lock and compares with the count the user confirmed (§3.4).
- SQLite ignores row locks: the tests assert the `for update` SQL, and the unique-index and two-connection tests also run on PostgreSQL (§6).

---

## 2. Migrations

Four, in this order, each schema-only, each with the docblock "PROD-DATA RISK" and `SET LOCAL lock_timeout = '5s'` on PostgreSQL only (the `create_wave_steps_table` style). Dates are fixed at build.

| #      | Name                               | What                                                                                    |
| ------ | ---------------------------------- | --------------------------------------------------------------------------------------- |
| **M1** | `add_usp_audience_title_to_waves`  | One nullable `varchar(80)`.                                                             |
| **M2** | `create_wave_usps_table`           | §1.1.                                                                                   |
| **M3** | `create_wave_personas_table`       | §1.2.                                                                                   |
| **M4** | `create_wave_persona_angles_table` | §1.3 (the composite FKs need the `unique (wave_id, id)` of M2 and M3, so M4 runs last). |

The build rehearses all four on a throwaway database with synthetic waves (the PL-1.2 C1 rule), through `artisan migrate`, and records timing and lock modes in the review.

---

## 3. API

Under `agencies/{agency}/brands/{brand}/waves/{wave}/strategy`, same middleware stack as the wave routes, in `Strategy/Routes/api.php`. **Every action starts with the flag check** (both flags, §5), then `assertBrand`, then `findWave` (same trait, reused or copied), then the policy, then the body. Names `waves.strategy.*`.

### 3.1 The first three lines of every action

1. Both flags (`waves_enabled` and the new one): either off is **404 `not_found`** before anything is read.
2. Brand belongs to the agency, else 404; wave found by ulid in this agency and brand (soft-deleted or foreign is 404).
3. Cards are looked up **through the wave** (`wave_id = $wave->id`), so a USP or persona ulid from another wave or agency is 404.

### 3.2 Endpoints

| Method and path                                               | What                                                                                                                                                                                                                                                                                                                                                                                                                            |
| ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET strategy`                                                | Summary for the stepper and tabs: USP count and limits, audience title, persona count per market of the wave (markets with none are included with 0), the constants (6, 4, 2, 8).                                                                                                                                                                                                                                               |
| `GET strategy/usps`                                           | The USPs in order with `angle_count` each.                                                                                                                                                                                                                                                                                                                                                                                      |
| `PATCH strategy/audience-title`                               | `{ usp_audience_title }`, empty clears. Audit `wave.audience_title_updated`.                                                                                                                                                                                                                                                                                                                                                    |
| `POST strategy/usps`                                          | Create (title required). 422 `usp.max_reached` at 6.                                                                                                                                                                                                                                                                                                                                                                            |
| `PATCH strategy/usps/{usp}`                                   | Partial update.                                                                                                                                                                                                                                                                                                                                                                                                                 |
| `PUT strategy/usps/order`                                     | `{ order: [ulid × n] }`, the full list. 422 `usp.order_mismatch`.                                                                                                                                                                                                                                                                                                                                                               |
| `DELETE strategy/usps/{usp}`                                  | §3.4.                                                                                                                                                                                                                                                                                                                                                                                                                           |
| `GET strategy/personas?market=SK`                             | The personas of one market with their angles (and each angle's USP colour key and title). 422 if the market is not the wave's.                                                                                                                                                                                                                                                                                                  |
| `POST strategy/personas`                                      | `{ market, … }`. 422 `persona.market_not_in_wave`, `persona.max_reached`, `persona.code_taken`.                                                                                                                                                                                                                                                                                                                                 |
| `PATCH strategy/personas/{persona}`                           | Partial update (market cannot change).                                                                                                                                                                                                                                                                                                                                                                                          |
| `DELETE strategy/personas/{persona}`                          | Deletes the persona and its angles in one transaction (confirm in the UI states the angle count).                                                                                                                                                                                                                                                                                                                               |
| `POST strategy/personas/copy`                                 | `{ from_market, to_market, persona_ids? }`. Both markets must be the wave's and different. Copies the chosen personas (default all) **with their angles** (USPs are the same wave's). New slots and default codes in the target; a code that the source had edited is not carried (the default is used). **All or nothing:** if target count plus copied count exceeds 8, 422 `persona.copy_exceeds_cap` with the numbers (Q6). |
| `POST strategy/personas/{persona}/angles`                     | `{ usp, explanation }` (`usp` is a USP ulid of this wave). 422 `angle.already_linked`.                                                                                                                                                                                                                                                                                                                                          |
| `PATCH` / `DELETE strategy/personas/{persona}/angles/{angle}` | Edit the explanation; remove the angle.                                                                                                                                                                                                                                                                                                                                                                                         |

Resources carry `ulid`, fields, `position` or `slot`, `color_key` (USP), `angle_count` or `in_use`, and the limits; never `id`, `agency_id` or `wave_id`.

### 3.3 Roles per action (S6), each a gate

`WaveStrategyPolicy`, built on `CurrentAgencyRole` (a pure function of the membership):

| Action                                                                | Admin | Manager | Staff       |
| --------------------------------------------------------------------- | ----- | ------- | ----------- |
| Read (all GETs)                                                       | yes   | yes     | yes         |
| Create and edit USPs, personas, angles, audience title, reorder, copy | yes   | yes     | yes         |
| Delete a persona or an angle                                          | yes   | yes     | yes         |
| Delete a USP **no angle uses**                                        | yes   | yes     | yes         |
| Delete a USP **an angle uses**                                        | yes   | yes     | **no: 403** |

The "in use" test is made **inside the wave lock** at the moment of the delete, so a USP that became in-use between the page render and the click is judged correctly (staff: 403; the web reloads). Staff see the delete item disabled with a reason ("Only an admin or manager can delete a USP that personas use"), from `angle_count` in the resource; the server is the authority. A user of another agency, or a non-agency user, is 404 as everywhere.

### 3.4 Deleting a USP that angles use (S5)

1. `DELETE strategy/usps/{usp}` with no confirmation: if no angle uses it, deleted. If any does, **409 `usp.in_use`** carrying `affected`: for each, the persona's market, code, title and the angle's explanation, plus `angle_count`.
2. The web shows a confirmation dialog listing them ("Removing _Affordability_ also removes 3 angles: SK1 The Office Lunch Organiser, SK2 …"). Confirming resends `DELETE …?confirm_angle_count=3`.
3. Under the lock the server recounts. **Same count:** deletes the angles, then the USP, renumbers the remaining positions 1 to n, in one transaction; colours of the others do not change. **Different count** (someone added or removed an angle meanwhile): 409 again with the fresh list. Nothing is half-deleted.
4. Admin or manager only for step 1 on an in-use USP (§3.3); the 409 for staff is replaced by 403.

**What will hang off a USP in later chunks** (so the rule is written once and extended, not rewritten): PL-2.2 the **messaging matrix** (one row per USP; cells per market and persona); PL-2.3 **concepts** (each concept says which USP it expresses); PL-3 **scripts** (through concepts); PL-5 client **approvals and comments** on a USP; PL-9 **AI drafts**. All use `ON DELETE RESTRICT` to `wave_usps`, so a later table cannot silently cascade. The in-use check is **one service method** (`UspUsage::for($usp)` returning every dependent) that each later chunk extends, and the 409 list and the audit payload read from it. A later chunk may decide that a USP with approved concepts cannot be deleted at all; that is its ruling, not this one.

### 3.5 Audit

New `AuditAction` cases (added to the enum **and** to `AuditActionEnumTest`'s catalogue): `wave_usp.created`, `wave_usp.updated`, `wave_usp.reordered`, `wave_usp.deleted`, `wave_persona.created`, `wave_persona.updated`, `wave_persona.deleted`, `wave_persona.copied`, `wave_persona_angle.added`, `wave_persona_angle.updated`, `wave_persona_angle.removed`, `wave.audience_title_updated`. One row per successful write, **in the same transaction**; `wave_usp.updated` and `wave_persona.updated` are written **only when a field changed**. Payloads are **literal arrays of ids, counts, colour keys, market codes and changed field names, never the card text** (and never the persona name). New payload keys (`usp`, `persona`, `angle`, `market`, `from_market`, `to_market`, `color_key`, `changed`, `count`, `angles_removed`) each get a decision in `AuditPayloadKeys::notPersonalKeys()` with a reason ("an id, a code or a field name of an agency's planning data, no creator is a field of it"); the existing `name` decision is untouched because `name` is never put in a payload.

### 3.6 Erasure and tenancy

- **Registry** (`ErasureRegistry.php`, next to `waves`): `wave_usps`, `wave_personas`, `wave_persona_angles` as `NotApplicable`, reason "Agency-authored (§3.7); marketing planning text about a brand's wave; no creator or user is a field of it", `refs` **empty** (no user column, §8). `wave_personas.name` is **classified** ("An invented persona name, not a real person's: a marketing archetype written by the agency, linked to no creator or user row"), as `PII_SEGMENTS` contains `name`. `title`, `summary`, `core_job`, `need`, the four list columns and `explanation` are classified with a one-line reason each ("agency-authored free text") although their names are not PII segments, so a reader never has to guess. There is **no `avatar` column**.
- **Why an invented persona name is not personal data.** A persona is an archetype for a target audience, not a record about an identified person, and nothing joins it to a user, creator or client. The remaining risk is a user typing a real person's name into a free-text field. That risk exists for every agency text field (wave titles, step titles, notes); the controller of such text is the agency, a creator erasure never reaches these tables (no creator column), and the agency can edit or delete the card. The form hint reads "An invented name for the persona, not a real person."
- **Tenancy.** `BelongsToAgency` on the three models; the Tenancy suite's list of tenant models gains them (the file is not named here, inventory §8); a foreign agency, foreign brand, or foreign wave is 404 on every endpoint.

---

## 4. Web

New module `apps/main/src/modules/strategy` (pages, components, api, composable, constants, tests). **No file in `modules/agency` is edited** (22 pinned files; the pin is re-run).

### 4.1 What is built

**Routes.** `/brands/:brand/waves/:wave/strategy` redirects to `/strategy/usps`; `/strategy/usps` and `/strategy/personas` (market in `?market=SK`, default the wave's first market). Registered in `auth/routes.ts` beside `waves.detail`, guarded like the wave route (both `features.waves` and `features.strategy`; the existing guard is copied, not re-read here). Deep links and a reload keep the step and market.

**Entry.** On `WaveDetailPage.vue` (waves module, not frozen) a button **"Strategy & Scripts"** in the header `actions` slot, all agency roles, rendered only when `features.strategy` is true. With the flag off nothing renders, not even a comment node (§6, W-OFF).

**Header.** Back arrow (to the wave page), title "Bolt Food — Wave 7" (brand name and wave name). **No breadcrumb, no status pill, no Preview, no Share** (inventory D6, D11, Q12).

**Stepper** (one bar, six steps, from the frame, numbers and names as drawn):

| Step                | State in PL-2.1 | How it shows (no dead UI, no date)                                                                                                                                         |
| ------------------- | --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1. USPs             | **Works**       | A real link (`router-link`). Status line: counts only, "4 USPs" (singular "1 USP", none "No USPs yet").                                                                    |
| 2. Personas         | **Works**       | A real link. Status: "9 personas · 2 of 3 markets" (counts only: personas in all markets, markets with at least one persona).                                              |
| 3. Messaging matrix | **Not built**   | A non-interactive element: not a link, not a button, not in the tab order, `aria-disabled="true"`, no hover state, no pointer cursor, dimmed. Status: "Not available yet". |
| 4. Concepts         | **Not built**   | As step 3.                                                                                                                                                                 |
| 5. Creators         | **Locked**      | As step 3, with a lock icon. Status: "Locked until all concepts are approved".                                                                                             |
| 6. Scripts          | **Locked**      | As step 5.                                                                                                                                                                 |

No tick appears in PL-2.1 on any step: there is **no "done" rule** (S7). The circle of steps 1 and 2 shows the number (the frame's grey circle with a number, e.g. "6"); the tick circle is not used. The tracker rows (PL-1.3) and the Brand page bar are **not read or written** by this chunk (§5, F1 and F3 are PL-2.4).

**Step 1, USPs.** Title "USPs", sub-line "4–6 per wave, shared across all markets." Audience bar: label "Audience title", the title (or a muted "Add an audience title" for admin, manager and staff), an Edit control opening a small dialog; on the right the counter (**"n of 6 USPs"**, at 6 "6 of 6 USPs · maximum reached") and **Add USP** (disabled at 6; at 6 a tooltip repeats the reason). **Below 4** (0 to 3) a warning line under the counter: "Add at least 4 USPs for a complete set" (amber, not blocking). Grid: three columns on wide screens, one on narrow; cards as the frame (number chip in the card's colour, title, three-dot menu, EXPLAINER, CREATIVE ANGLE, COPY SEED DIRECTIONS in the tinted quote block). Empty fields are omitted from the card. Drag by a **grip handle** (vuedraggable, as the board columns); the menu also has **Move earlier / Move later** for keyboard use. Menu: Edit, Delete. Empty state: an illustration-free panel "No USPs yet" with an Add USP button.

**Step 2, Personas.** Market tabs from `wave.markets` (code chip and name; the active tab filled), each tab with its count (e.g. "SK · 2"; **a count below 2 shows an amber dot** and the tooltip "Fewer than 2 personas"). Title "Personas · Slovakia", sub-line "2–8 personas per market. Creative angles link to the USPs from step 1." Buttons: **Copy from…** and **Add persona**; below the grid the dashed tile "Add persona · n of 8 used in Slovakia"; at 8 both Add controls are disabled and the tile reads "8 of 8 used in Slovakia · maximum reached". Under 2 a warning line: "Add at least 2 personas for Slovakia" (amber, not blocking). Card as the frame (tinted header band, initials avatar, code chip, title, "Name · persona name", three-dot menu, the seven body blocks, the angle list, "Add angle from USPs"). Labels follow the pronoun: **she** "What she tells herself" / "What she needs"; **he** "…himself" / "…he needs"; **they** "What they tell themselves" / "What they need". Empty list or paragraph blocks are omitted. Angle list: a pill in the USP's colour with the USP title, the explanation, a small "Added by hand", a menu (Edit, Remove). The dropdown lists the wave's USPs in order: linked ones grey with "Linked" and not selectable; others with "+"; choosing one opens an inline row (one text field and Add / Cancel). With **no USPs** the dropdown says "Add USPs in step 1 first" with a link.

**Copy from…** (S4): a dialog; choose the source market (a select of the wave's other markets that have personas), tick the personas (all ticked), a live line "Copying 3 → SK will have 6 of 8". Over 8 the Copy button is disabled with the reason. Copied angles come with the personas.

### 4.2 Screens with no design, built from the design system (S10)

Named; none is invented visually beyond existing Vuetify components and the agency theme classes:

- **Add USP / Edit USP dialog**: `v-dialog` (max-width 560, fullscreen below 600 px), `v-text-field`, `v-textarea` with counters, Save and Cancel; colour shown as a read-only dot.
- **Delete USP confirmation** (with the affected list) and **Delete persona** / **Remove angle** confirmations: the existing confirm dialog pattern (`v-dialog` + `v-card`).
- **Audience title dialog**: small `v-dialog`, one `v-text-field`.
- **Add / Edit persona dialog**: a scrollable `v-dialog` (max-width 720, fullscreen on narrow) in the same section order as the card; the four list fields use a small repeater (one `v-text-field` per item, add and remove, a counter "3 of 6"); pronoun is a `v-select`; code is a `v-text-field`.
- **Copy from… dialog**: `v-dialog`, `v-select`, `v-checkbox` list.
- **Angle add and edit row**: inline `v-text-field` with `v-btn` pair.
- **Three-dot menus**: `v-menu` with `v-list`, z-index `MENU_ABOVE_DIALOG_Z` (2500).
- **Empty, loading, error and "removed by someone else" states**: `v-skeleton-loader`, `v-alert`, an empty panel.
- **Warning lines and limit counters**: `v-alert` density compact (tonal, warning), plain text counters.
- **Locked and not-built steps**: the stepper element of §4.1 (a styled `div` with the agency theme tokens).
- **The page shell**: `AgencyPageHeader` (imported) and the `agency-page`, `agency-card` classes.
- **Narrow layout**: cards stack in one column; the stepper scrolls horizontally inside its bar (no wrapping of step names); market tabs scroll horizontally; the dialogs go fullscreen.

### 4.3 Widths

Checked at **1440** (a design width is 1600) and **390**; nothing runs off the page; every dialog and menu is opened above its parent (the PL-1.3 z-index lesson).

### 4.4 Frozen files

None edited. The agency pin test is run before and after. `brandColorVar()` is imported from `modules/agency/composables`. A new import of a frozen file is not an edit.

---

## 5. Flag

**New flag `strategy_enabled`** (Pennant, `Feature::define` in `StrategyServiceProvider`, default **false**, a `StrategyEnabled` class with `NAME`). Registered in the four places: `AdminFeatureFlagController::FLAGS` (label "Strategy workspace", description "PL-2: USPs, personas and the strategy workspace. Needs the Waves flag."), `SetFeatureFlagController` allowlist, `docs/feature-flags.md`, and `UserResource` `features` as `'strategy' => agencySide && Feature::active(WavesEnabled::NAME) && Feature::active(StrategyEnabled::NAME)`. It is **never reused by a later sprint** (the standing rule); PL-2.2 to PL-2.4 ship behind the same flag because they are the same sprint. The build never turns it on.

| `waves_enabled` | `strategy_enabled` | What the user sees                                                                                                                                     |
| --------------- | ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------ |
| off             | off                | Nothing (today).                                                                                                                                       |
| off             | on                 | **Nothing.** The strategy API is 404 and `features.strategy` is false: strategy work hangs off waves, and no wave page is reachable. Not a half-state. |
| on              | off                | **PL-1 exactly:** the wave page, tracker and Brand panel as PL-1.3 left them. No button, no route, every strategy endpoint 404.                        |
| on              | on                 | The Strategy & Scripts button on the wave page and the workspace.                                                                                      |

With the flag **off**:

- Every strategy route answers 404 from the first line; nothing is read or written.
- The web route redirects as the wave route does; the wave page renders no new node.
- **The tracker and the Brand page bar are not changed in either state** (S7): no strategy step is ticked by a USP or persona; that is PL-2.4 (F1 to F3). No campaign, board or brand file is touched.
- A wave edit that removes a market with personas is refused even with the flag off (§8); with no persona rows it is identical to PL-1.
- If the flag is turned off after use, the rows stay and nothing reads them.
- Production note carried from PL-1: `waves_enabled` must not be on in production for real creators before PL-6; `strategy_enabled` has no such constraint but needs `waves_enabled`.

---

## 6. Tests (each gate has a break-revert)

A break-revert is: remove the guard or code the gate protects, watch the named test fail, restore, watch it pass. The review records each.

### Backend gates (Pest)

| ID    | Gate                                                                                                                                                                                                                                                                   | Break it by                                           |
| ----- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------- |
| K1    | `waves_enabled` off, `strategy_enabled` on: every strategy endpoint is 404 `not_found`                                                                                                                                                                                 | dropping the waves check from the first line          |
| K2    | `strategy_enabled` off, waves on: every endpoint 404, wave endpoints unchanged                                                                                                                                                                                         | dropping the strategy check                           |
| K3    | Both on: GET works; `UserResource.features.strategy` true only when both on and agency side                                                                                                                                                                            | returning `true` for one flag only                    |
| K4    | The flag is in all four registries (admin list, allowlist, docs table, `UserResource`)                                                                                                                                                                                 | removing it from one                                  |
| T1    | Agency B cannot read, create, edit, delete or reorder anything of agency A (404 on every endpoint)                                                                                                                                                                     | removing `BelongsToAgency` or the wave lookup scope   |
| T2    | A wave of another brand, or a soft-deleted wave, is 404                                                                                                                                                                                                                | dropping `assertBrand`                                |
| T3    | A USP ulid of wave B in an angle POST on wave A is 404; a direct insert of an angle whose USP is in another wave is rejected by the **composite FK** (both databases)                                                                                                  | looking the USP up without `wave_id`; dropping the FK |
| R1–R6 | Roles (S6): R1 staff create and edit USP, persona, angle; R2 staff delete an unused USP; R3 **staff delete an in-use USP is 403**; R4 manager and admin delete an in-use USP with confirmation; R5 every role may delete personas and angles; R6 a foreign user is 404 | R3: relaxing the policy to `canManage` for all        |
| L1    | The 7th USP is 422 `usp.max_reached`; deleting then adding re-uses the freed colour                                                                                                                                                                                    | removing the count check                              |
| L2    | `unique (wave_id, color_key)`: a direct insert of a 7th row, or a duplicate colour, fails (both databases)                                                                                                                                                             | removing the unique index                             |
| L3    | The 9th persona in a market is 422 `persona.max_reached`; a 9th in another market is allowed                                                                                                                                                                           | removing the check                                    |
| L4    | `unique (wave_id, market, slot)` belt fails a duplicate on both databases                                                                                                                                                                                              | removing the index                                    |
| L5    | **Concurrency (PostgreSQL, two connections):** two simultaneous creates at 5 USPs give one success and one 422; the same for persona 7 of 8; the SQL contains `for update` (SQLite)                                                                                    | removing `lockForUpdate`                              |
| L6    | Deleting down to 0 USPs or 1 persona is allowed (the minimums only warn)                                                                                                                                                                                               | adding a minimum check                                |
| M1    | Persona create or list with a market not in the wave is 422 `persona.market_not_in_wave`                                                                                                                                                                               | removing the check                                    |
| M2    | Copy: both markets must be the wave's and different; over 8 is 422 `persona.copy_exceeds_cap` and **nothing** is copied; angles come along                                                                                                                             | copying before counting                               |
| M3    | **A wave edit that removes a market with personas is 422 `wave.market_has_personas`**; removing a market with none is allowed; works with `strategy_enabled` off                                                                                                       | removing the check in `WaveService::update`           |
| M4    | **Concurrency (PostgreSQL):** a persona create in SK racing a wave edit removing SK: either the persona is created and the edit refused, or the edit succeeds and the create is 422; never a persona in a removed market                                               | taking the lock in one path only                      |
| U1    | An angle can only link a USP of the same wave (the code path, 404)                                                                                                                                                                                                     | (see T3)                                              |
| C1    | Delete in-use USP without confirmation: 409 `usp.in_use` with the affected list and count; nothing deleted                                                                                                                                                             | deleting before the check                             |
| C2    | With the matching `confirm_angle_count`: angles and USP removed, positions renumbered 1 to n, other colours unchanged                                                                                                                                                  | skipping the renumber                                 |
| C3    | With a stale count: 409 again with the fresh list; nothing deleted                                                                                                                                                                                                     | ignoring the count                                    |
| C4    | Atomic: a failure injected after the angles are deleted leaves everything in place                                                                                                                                                                                     | removing the transaction                              |
| C5    | Deleting a persona removes its angles and nothing else; the FK RESTRICT blocks a raw `delete` of a USP that angles use                                                                                                                                                 | changing an FK to cascade                             |
| O1–O4 | Reorder: happy path; mismatch is 422 `usp.order_mismatch`; a duplicate or foreign ulid is 422; positions are 1 to n and colours do not move                                                                                                                            | removing the set comparison                           |
| X1    | Every field limit of §1.6 (boundary and boundary + 1; multibyte counted as characters); required fields; whitespace-only                                                                                                                                               | raising a limit                                       |
| X2    | Lists: 7 items is 422; an item over 120 is 422; empty items are dropped                                                                                                                                                                                                | removing the rule                                     |
| X3    | A hostile string is stored verbatim minus control characters; NUL is removed; CRLF becomes LF                                                                                                                                                                          | stripping tags instead (the test expects verbatim)    |
| A1    | Each write makes exactly one audit row of the right action in the same transaction; an update with no change writes none; **no payload contains card text or the persona name**                                                                                        | adding the name to a payload                          |
| A2    | `AuditActionEnumTest` catalogue lists the new actions; `AuditPayloadKeys` decides every new key                                                                                                                                                                        | omitting one                                          |
| E1    | The Erasure registry verifier passes: new tables classified, `name` classified, no stale entry, `refs` empty                                                                                                                                                           | removing the `name` classification                    |
| P1    | Migrations M1 to M4 run up and down on PostgreSQL and SQLite; the lock rehearsal numbers are recorded                                                                                                                                                                  | n/a                                                   |

### Web gates (Vitest and Playwright)

| ID    | Gate                                                                                                                                                                                                                                                                                                                                                                                                                                                                          |
| ----- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| W1    | Stepper: steps 1 and 2 are links with counts; steps 3 and 4 are not links, not focusable, `aria-disabled`; steps 5 and 6 likewise with the lock text; no tick anywhere                                                                                                                                                                                                                                                                                                        |
| W2    | USP step: counter at 0, 3, 4, 5, 6; Add USP disabled at 6 with the reason; the warning shows at 0 to 3 and not at 4 to 6                                                                                                                                                                                                                                                                                                                                                      |
| W3    | USP dialog: required title, counters, a server 422 shown on the field; "removed by someone else" on 404                                                                                                                                                                                                                                                                                                                                                                       |
| W4    | Drag reorder posts the full list; a 422 reloads the grid; the keyboard Move earlier / later works; the number follows the position and the colour the card                                                                                                                                                                                                                                                                                                                    |
| W5    | Delete in-use USP shows the affected list from the 409 and resends with the count; staff see the item disabled with the reason                                                                                                                                                                                                                                                                                                                                                |
| W6    | Personas: tabs from the wave's markets, counts, amber dot under 2, Add disabled at 8, the tile text "n of 8 used in X"                                                                                                                                                                                                                                                                                                                                                        |
| W7    | Angle dropdown: linked rows grey with "Linked" and not selectable; add inline; "Added by hand"; no USPs shows the hint                                                                                                                                                                                                                                                                                                                                                        |
| W8    | Pronoun labels for she, he, they                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| W9    | Copy dialog: live count line, disabled over 8; the 422 message is shown                                                                                                                                                                                                                                                                                                                                                                                                       |
| W10   | **No `v-html`, no `innerHTML`** under `modules/strategy` (a spec reads the files); a hostile string renders as text                                                                                                                                                                                                                                                                                                                                                           |
| W11   | The 24 locales hold every new key (`verify-locale.mjs`, a `strategyKeys.spec.ts` as `waveKeys.spec.ts`)                                                                                                                                                                                                                                                                                                                                                                       |
| W-OFF | **Flag off, identical (the alarm gate).** With both flags off, and with waves on and strategy off: the wave page DOM and its existing spec snapshot are byte-identical to the PL-1.3 end state, **including no new comment node**; the agency walk is **126** targets with the **12 campaign** and **16 brand** targets identical to `ah-115-agency-baseline/baseline.json`; the creator walk is **146**. Break: render the button unconditionally and the spec and walk fail |
| W-PIN | The agency-frozen pin test (22 files) passes unchanged                                                                                                                                                                                                                                                                                                                                                                                                                        |

### §4.2 real-browser checks (WORKING-PROCESS.md)

In a real browser, at **1440** and **390** wide, on both steps: every dialog (Add USP, Edit USP, audience title, delete USP with the list, Add persona, Edit persona, Copy from…, delete persona), every menu (card menus, the angle dropdown, the persona select inside the persona dialog), **opened, closed and reopened quickly several times** (the three-dot menu over a dialog, Esc then reopen, click-away then reopen), market tab switching during a save, and a drag at both widths. Nothing may stay stuck open, hide behind another layer, or leave a scrim. Screenshots at each width go in the review.

### Local gates (before any push, `WORKING-PROCESS.md` §4.1)

Clean slate first; one suite at a time; `VITEST_MAX_WORKERS=4`; Pest serial with the memory limit raised. **Waves, Brands, Audit, Erasure, Tenancy** folders (and the new `Strategy` folder) on **SQLite and PostgreSQL** (throwaway database through `phpunit.pgsql.xml`, `DB_PORT=5435`, never a database with data), then the web suites: type check, lint, Vitest, i18n verify, the agency-frozen pin, the Playwright spec, the flag-off agency and creator walks (W-OFF).

---

## 7. Strings

All new strings in the **24 locales** (`verify-locale.mjs`, a `strategyKeys.spec.ts`), under `app.strategy`: workspace title and entry button; step names and the six status texts (counts with singular and plural, "Not available yet", "Locked until all concepts are approved"); USP step (title, sub-line, audience title, counter, maximum reached, warning, empty state, card labels EXPLAINER, CREATIVE ANGLE, COPY SEED DIRECTIONS, menu items, move earlier and later); USP dialog (labels, hints, counters); delete USP (title, "also removes n angles", list line, buttons, the staff reason); persona step (title with market, sub-line, tab count, warning, tile text, maximum reached, empty state); card headings (Core job, Emotional drivers, Values & goals, What she/he/they tell(s) …, What she/he/they need(s), Barriers & frictions, Creative angles that unlock), pronoun names, "Added by hand", "Linked", "Link a USP", "Add angle from USPs", "Add USPs in step 1 first"; persona dialog (labels, hints including "An invented name for the persona, not a real person.", repeater counters); copy dialog; delete persona and remove angle; every error code of §3.2 (`usp.max_reached`, `persona.max_reached`, `persona.copy_exceeds_cap`, `wave.market_has_personas`, …); "removed by someone else"; loading and error. Roughly 140 keys, English first, the other 23 locales translated as in PL-1.3 §7.

---

## 9. Left out of this chunk

- **S9, by ruling:** add market from the workspace (markets change only in the wave edit dialog); script languages and the "Script language" label (PL-3); notes and comments, including the Notes panel, comment counts and "Reply / Resolve" (PL-2.4 for notes, PL-5 for client comments); "Preview as client" and "Share with client" (no client portal until PL-4); copy from an **earlier wave**; source labels that depend on the client or AI ("Client brief", "built out by Jess", "Supplied by the client").
- **From the frames, not in S9** (inventory §6): the **Approval** dropdown (S7, no "done" rule, no approval before PL-2.3); **Import from brief** (S2); "Synced from matrix" (no matrix yet, replaced by "Added by hand"); the stepper status words; the breadcrumb and the status pill.
- **Not built, by S8:** Messaging matrix and Concepts (PL-2.2 and PL-2.3) and the unlocking of Creators and Scripts.
- **Not built, by this plan:** persona reorder (order is by slot), angle reorder, persona avatar upload, persona or USP export or print, version history, undo after delete, autosave, any change to the tracker rows, the strategy steps' status (F1), the markets roll-up (F2) and "open on the first incomplete step" (F3), all PL-2.4.
- **No AI** anywhere (S2); no `ai` origin value is accepted.

---

## 10. Walk script for Pedram (local)

**Setup (named seed data).** A new seeder pair, **`Pl21WalkSeeder`** and **`Pl21WalkCleanupSeeder`** (local and testing only, refuse to run in production, idempotent, additive, never touches a flag; Pedram turns both flags on and off by hand; the password is printed by the seeder, nothing secret in the repo):

- **Agency A "PL-2.1 Walk Agency A":** `admin@`, `manager@`, `staff@pl21-walk.local`. Brand **Bolt Food**. Markets by wave:
  - **Wave 6**: PL-1 wave with no strategy data (the flag-off and entry proof).
  - **Wave 7** (markets SK, RO, CZ): the frame's **6 USPs** (Category entry points … Local restaurant discovery, with the frame's texts) and the audience title "Bolt Food Eaters — 6 core USPs"; SK has the two personas of the frame (Zuzana and Peter, with their angles); RO and CZ have none.
  - **Wave 8** (SK, RO): empty (everything is created in the walk).
  - **Wave 9** (SK, RO): **3 USPs** (the warning), SK **1 persona** (the warning), RO **8 personas** (the maximum), the first RO persona linked to USP 1.
  - **Wave 10** (SK, RO): **5 USPs**, SK 3 personas, RO 6 personas (the copy limit), one angle on USP 2 (the in-use delete).
- **Agency B "PL-2.1 Walk Agency B":** `admin@pl21-walk-b.local`, brand **Glovo**, wave **Glovo Wave 1** with 2 USPs.

**Steps:**

1. **Both flags OFF.** As `admin@`, open Bolt Food and Wave 6: the page is as before; no Strategy button. Paste `/brands/<id>/waves/<wave>/strategy/usps`: you are sent away as the wave route does. **Waves ON, strategy OFF:** same (PL-1 exactly). **Waves OFF, strategy ON:** nothing.
2. **Both ON.** Wave 6 shows a **Strategy & Scripts** button; open it: the stepper (1 USPs "No USPs yet", 2 Personas, 3 and 4 dimmed "Not available yet", 5 and 6 with a lock and "Locked until all concepts are approved"); click 3 to 6: nothing happens, Tab skips them.
3. **Wave 7, USPs.** Six cards in the frame's order and colours (violet, green, blue, amber, teal, pink); the counter "6 of 6 USPs · maximum reached"; **Add USP is disabled**. The audience title is above the grid; edit it and reload.
4. **Delete and re-add.** As `manager@`, delete USP 03 (no angles use it): the cards renumber 01 to 05, the others keep their colours. Add a USP: it takes blue. The counter is 6 of 6 again.
5. **Reorder.** Drag card 06 to the first place: numbers follow positions, colours stay with cards. Reload: same. Use Move earlier / later from the menu with the keyboard.
6. **Wave 9, warnings.** USPs: counter "3 of 6 USPs" and the warning; Personas SK: "1 of 8 used" and the warning; RO: 8 of 8, **Add persona** and the tile disabled.
7. **Create a USP by hand in Wave 8:** fill title, explainer, creative angle, copy seed; a title of 41 characters is refused; type `<img src=x onerror=alert(1)>` in the explainer: the card shows it as plain text, no alert.
8. **Personas, Wave 8.** Add a persona in SK with the pronoun **she**, then one with **he**, then **they**: the labels read "tells herself / himself / themselves". Edit the code chip "SK1" to "SK-A"; a duplicate code in SK is refused; the same code in RO is accepted. Add three bullets in each list; a 7th bullet is refused.
9. **Angles.** On an SK persona open "Add angle from USPs": all USPs show "+"; pick Affordability, type a line, Add: the pill is green and the dropdown now shows "Linked" for it. Edit and remove it.
10. **No USPs.** In Wave 8 before adding USPs the dropdown says "Add USPs in step 1 first".
11. **Copy.** In Wave 10 open RO, choose **Copy from… SK**, all 3 ticked: "RO will have 9 of 8", Copy is disabled; untick one: "8 of 8", copy; the copied cards have angles and new codes (RO7, RO8).
12. **Delete an in-use USP.** In Wave 10 as `staff@`: the Delete item of USP 2 is disabled with the reason. As `manager@`: Delete shows the confirmation listing the persona and its angle; confirm; the angle is gone from the persona. Cancel once and check nothing changed.
13. **Two tabs:** in tab 1 delete a USP; in tab 2 (opened before) edit it and Save: "This card was removed by someone else". In tab 2, add a USP while tab 1 drags to reorder: tab 1's drop is refused and the grid reloads.
14. **Same card, two people:** tab 1 edits only the title, tab 2 only the explainer; both Save: both changes are present. Both edit the title: the later save wins.
15. **Markets.** In Wave 7 open the wave Edit dialog and remove SK: refused, "Remove the personas of SK first (2)". Remove CZ (no personas): accepted; the CZ tab disappears. Add a market in the dialog: the new tab shows the empty state.
16. **Staff and manager:** `staff@` can create, edit, drag and delete unused cards; `manager@` can delete an in-use USP.
17. **Second agency:** as `admin@pl21-walk-b.local` open Glovo Wave 1 (works); paste a Wave 7 strategy address: not found; Agency B sees none of Agency A's cards.
18. **Narrow width** (a 390 px window): cards stack, the stepper and the market tabs scroll sideways inside their bar, dialogs open fullscreen, nothing runs off the page; open and close each dialog and menu quickly several times; the three-dot menu opens above a dialog.
19. **Flag back OFF** after use: the wave page returns to PL-1; reopen the flags: the data is still there.
20. **Cleanup:** `Pl21WalkCleanupSeeder`; the flag state is reported, not changed.

---

## 11. Open questions (for Pedram), each with a recommendation

| #       | Question                                                                                                                                                                                                                                      | Recommendation                                                                                                                                              |
| ------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Q1**  | **Flag name and relation.** `strategy_enabled`, requires `waves_enabled`; with waves off and strategy on the user sees nothing (§5). The flag covers all four PL-2 chunks.                                                                    | Yes, as written.                                                                                                                                            |
| **Q2**  | **Persona colour for slots 7 and 8** (the palette has six). Wrap (violet, green again) or use two more brand colours (red, slate).                                                                                                            | **Wrap.** Red reads as an error; slate as disabled. The code chip and name tell the cards apart.                                                            |
| **Q3**  | **What is editable in the code chip.** S4 says "market code plus number, editable". The plan makes the whole code editable text (2 to 8 characters, `A–Z 0–9`), default `SK1`, unique per market. The alternative is to edit the number only. | **Whole code editable**, unique within the market. The number is already the slot; a free code lets a team name "SK-B2B".                                   |
| **Q4**  | **Two people on one card.** Per-field last write wins with partial saves (the PL-1.3 Q11 precedent), or a version check with a 409 conflict dialog.                                                                                           | **Per-field last write wins.** It matches the precedent and needs no new dialog; the audit log shows who wrote what.                                        |
| **Q5**  | **A market removed from the wave while it has personas.** Refuse until the personas are removed (plan), delete them with the market, or keep them hidden.                                                                                     | **Refuse (422 with the counts).** No silent loss of long hand-written text; it also touches `WaveService::update` even with the flag off, adding one query. |
| **Q6**  | **Copy from another market.** All or nothing when the result would exceed 8, copy includes the angles, chosen personas (default all). Or copy as many as fit.                                                                                 | **All or nothing, with angles, with a tick list.** "As many as fit" silently drops cards.                                                                   |
| **Q7**  | **Required fields.** The plan requires USP title; persona title, name and pronoun (default `they`); everything else optional. Or require every field.                                                                                         | **Minimal required.** A strategist builds a card up over several visits; the counts-only status makes no promise of completeness.                           |
| **Q8**  | **The limits of §1.6** (the frames draw none): USP title 40, explainer 400, angle and copy seed 300, persona summary and paragraphs 300, lists 6 × 120, angle line 140, audience title 80, persona title 60, name 40.                         | Accept as written; they are 2 to 3 times the frame's longest text. Changing one is a constant.                                                              |
| **Q9**  | **Left out although in the frames and not in S9:** the Approval dropdown, "Import from brief", the stepper status words, the breadcrumb and status pill (inventory D1 to D3, D6, D11).                                                        | Confirm all left out.                                                                                                                                       |
| **Q10** | **Stepper wording and look.** Steps 3 and 4: dimmed, non-interactive, "Not available yet". Steps 5 and 6: lock icon, "Locked until all concepts are approved". No dates, no "coming soon".                                                    | As written. "Not available yet" is true and carries no promise.                                                                                             |
| **Q11** | **Counter wording.** The frame says "6 of 6 USPs · maximum reached"; the brief says "6 of 6, maximum reached".                                                                                                                                | Use the frame's text (it is the design and is the brief's text plus the noun).                                                                              |
| **Q12** | **No breadcrumb.** `AgencyPageHeader` has no breadcrumb slot and no other agency page I read has one; the back arrow returns to the wave page.                                                                                                | Leave it out; a breadcrumb would be a new shared pattern.                                                                                                   |
| **Q13** | **Who may delete a persona, and does it need a confirmation?** S6 only covers USPs. The plan: admin, manager and staff, with a confirmation that states the angle count.                                                                      | As written.                                                                                                                                                 |
| **Q14** | **Size.** This is one "L" chunk (three tables, a new module, two big surfaces, about 140 strings). Keep it one chunk, or split into USPs-and-workspace and Personas.                                                                          | **Keep one chunk** (PL-2 is already cut to four), but build and commit in the order of §12 so a USP-only stop point exists.                                 |
| **Q15** | **Audience title** is typed by hand, optional, one per wave, a column on `waves` (M1), with a placeholder "Add an audience title". The frame's "— 6 core USPs" suffix looks generated from the count.                                         | Typed by hand, no suffix. A generated suffix would be a "rule" the brief does not give.                                                                     |
| **Q16** | **Hard delete** of USPs, personas and angles (no soft delete, no undo).                                                                                                                                                                       | Yes, since each delete is confirmed and audited; the audit row holds ids and counts, not the text.                                                          |

---

## 12. Size estimate and build order

**Size: L** (three tables, a composite-FK data model, a new module on both sides, two interactive surfaces with drag, dialogs and a dropdown, a concurrency gate set on PostgreSQL, a 24-locale string set). Estimates, not commitments:

- Backend: about 28 new files (4 migrations, `UspColor`, `PersonaPronoun`, three models and factories, `WaveStrategyService`, `UspUsage`, `StrategyRuleViolation`, the controllers (summary, USPs, personas, angles, copy), requests, resources, policy, provider, flag class, routes, `StrategyWalkSeeder` pair) and about 9 edited (`WaveService::update` for §1.9, `AuditAction`, `AuditPayloadKeys`, `ErasureRegistry`, `AdminFeatureFlagController`, `SetFeatureFlagController`, `UserResource`, `docs/feature-flags.md`, `bootstrap/providers.php`).
- Web: about 22 new files (workspace page, stepper, USP step and card, USP dialog, delete dialog, audience dialog, persona step, tabs, persona card, persona dialog, list repeater, angle list and dropdown, copy dialog, api, composable, constants, helpers) and 3 edited (`routes.ts`, `WaveDetailPage.vue`, api-client types); 24 locale files.
- Tests: about 22 new backend test files and 12 web specs; one Playwright spec.
- Rough effort: 9 sub-steps; about 8 working days of build plus two for translations, the PostgreSQL runs, the real-browser pass and the walk.

**Build order** (each sub-step independently green on SQLite and, where it touches a lock or a unique index, on PostgreSQL):

1. **Flag and schema.** Flag class and the four registries, M1 to M4, enums, models, registry and audit-key decisions. Tests K1 to K4, E1, A2, P1, T3 (FK part). **PostgreSQL run and the rehearsal.**
2. **USPs, backend.** Service, endpoints, colour assignment, reorder, caps, roles, audit. Tests T1, T2, R1 to R3, L1, L2, L5 (USP), L6, O1 to O4, X1 to X3, A1. **PostgreSQL run.**
3. **Personas, backend.** Slots, codes, market rule, copy, angles, `WaveService::update` market rule (works with the flag off). Tests L3, L4, L5 (persona), M1 to M4, U1, R4 to R6. **PostgreSQL run.**
4. **Delete USP in use.** `UspUsage`, 409 list, confirm count, atomic delete. Tests C1 to C5, R3, R4. **PostgreSQL run.**
5. **Web: shell and stepper.** Route, entry button, header, stepper, summary, strings skeleton. Tests W1, W-OFF (the spec part), W-PIN.
6. **Web: USPs.** Grid, dialogs, drag, delete confirmation, audience title. Tests W2 to W5, W10.
7. **Web: personas.** Tabs, cards, dialog, angles, copy. Tests W6 to W9.
8. **Strings** in 24 locales. Test W11.
9. **Close-out.** Walk seeder pair, **both steps opened in a real browser at 1440 and 390** (§4.2), the Playwright spec, the full local gate set (Waves, Brands, Audit, Erasure, Tenancy on SQLite and PostgreSQL), the flag-off agency (126, 12 and 16) and creator (146) walks, the review file with a Production posture section and the migration rehearsal timing.

Commits: feature commit and docs commit as a pair (`WORKING-PROCESS.md` §2). **No push until Pedram has walked.**

---

## Read and not read

**Read:** Figma frames 13:428 and 13:828 (every text node, colour and size through `get_design_context`, plus the screenshot of each and the tree through `get_metadata`); everything listed under "Read" in `pl-2-1-inventory.md` §8; `production-line-sprints.md` (the table, rulings, F1 to F3); `pl-1-3-plan.md` sections 5, 7, 10, 12 and the section headings of the rest; `Waves/Routes/api.php`, `ResolvesWaveRoute`, `WaveRuleViolation` (to confirm the 422 and brand-archived pattern and the `WaveController` order); the registry `waves` and `wave_steps` entries; `AuditPayloadKeys` wave lines.

**Run:** one local lock probe on a throwaway PostgreSQL database that I created and dropped (§8). No test, no walk, no browser, no migration file, no server.

**Not read:**

- **The developer brief** (only the relay S1 to S10).
- **Every frame state not drawn** (inventory §1.2 and §1.3) and any width below 1600, any light theme.
- **Not re-read in this pass:** `WaveController::show` and `update` bodies; `WaveService::update` beyond its docblock and lock (the §1.9 change is designed from the summary of it, so the build must read it first); the route guard of `waves.detail`; the market-name helper behind `MarketPicker`; `BrandWavesPanel.vue`; `WaveStepKey` and `WaveTrackerCalculator` (F1 is quoted from `production-line-sprints.md`, not from the code); the Tenancy suite file that lists tenant models; the local seed's account names; whether the six strategy tracker rows share anything with the new step names beyond text (they are not touched).
- **The production PostgreSQL version and any production table size.** The lock numbers are from a local PostgreSQL 16 container with synthetic rows.
- **Limits and wording that are proposals:** every number in §1.6, the error code names, the strings of §4.1 and §10 that the frames do not carry. None is read from a design.
