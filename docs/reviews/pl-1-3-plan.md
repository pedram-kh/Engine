# PL-1.3 plan: Wave project tracker

**Date:** 2026-11-18. **Branch:** `pl`, from tip `47572aad`. **Status:** plan. Docs only: no code, no migration run, no push. No Cursor build prompt is issued until Pedram has answered section 11 and said go (`production-line-sprints.md`).
**Inputs:** `pl-1-3-inventory.md` (read it first; "inventory §n" points into it), `production-line-sprints.md` §6 (R1 to R10 and P1 to P9, binding), `pl-1-2-review.md`.
**Standing rules applied:** schema-only migrations, the alarm rule, break-revert on every gate, the 24-locale gate, `WORKING-PROCESS.md` §4.1 local-run rules and §4.2 (real browser), the Erasure folder before every push, and the PostgreSQL rule in section 6.

**Goal.** The wave page becomes the wave's plan: stream cards across the top, a steps table below with owner, dates and status, and the Progress column on the Brand page fed from it. All behind `waves_enabled`, OFF by default. With the flag OFF nothing changes.

**Section numbers** follow `pl-1-2-plan.md` (1 data model to 12 size and order). The rulings come first; section 8, the PROD-DATA RISK line, is next because the alarm rule puts it before anything else.

## Rulings for this chunk (Pedram, 2026-11-18)

| #      | Ruling                                                                                                                                                                                                         | Where it lands |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------- |
| **T1** | A new wave starts with a ready-made list of steps (the standard steps of frame 01b). The strategist can edit, add and remove. Existing waves get the same list on first open, not by a data-writing migration. | §1.4, §3.1     |
| **T2** | Owners are agency team members only. No client owners (PL-4).                                                                                                                                                  | §1.7, §3.2     |
| **T3** | Admin and manager add and remove steps. Admin, manager and staff update a step's status, owner and dates.                                                                                                      | §3.3           |
| **T4** | Everything is updated by hand here. Later sprints tick steps automatically, so a step needs an identity that is not its name.                                                                                  | §1.3           |
| **T5** | No notifications or reminders to owners. No calendar or timeline view unless frame 01b has one; if it has, report it and leave it out.                                                                         | §9             |
| **T6** | Screens with no design are built from the existing design system and named.                                                                                                                                    | §4.2           |

Frame 01b has **no calendar or timeline**. It has a day-grouped "Due this week" list (right column) which is reported in the inventory (§0.2) and left out (§9, question Q6).

---

## 8. PROD-DATA RISK (stated first, before any code)

**PROD-DATA RISK: NONE for existing data.** Both migrations are additive. Neither reads, writes or backfills a row.

- **M1** creates a **new, empty** table `wave_steps`.
- **M2** adds one **nullable** column, `steps_provisioned_at`, with no default, to `waves`. A nullable column with no default is a catalogue-only change in every PostgreSQL version (no rewrite), and production is PostgreSQL 17.9 (Q11, confirmed 2026-11-18, source: the colleague's read-back relayed by Pedram, not read by Cursor). It starts with `SET LOCAL lock_timeout = '5s'`.
- **The `waves` table does not exist in production yet.** PL-1.1 is built and walked, not merged, not deployed (`production-line-sprints.md` §6). So M2 will run in the same deploy that creates `waves`, on an empty table, once `pl` is merged. That is stated from the sprint file, not from any server.
- **No data-writing migration (T1).** Existing waves get their steps on first open, by application code behind the flag (§3.1). Nothing writes a step until `waves_enabled` is on and someone opens a wave or creates one.
- `down()` for M1 is `dropIfExists` (lossy, a rollback tool only before the flag is armed, as `create_waves_table`). `down()` for M2 drops the column.
- **Rehearsal** is part of the build: both migrations run on a local throwaway database with synthetic waves (the PL-1.2 C1 rule: synthetic rows, nothing from production), timing recorded in the review file. Local and test databases only.

---

## 1. Data model

### 1.1 `wave_steps` (new, Waves module)

| Column          | Type                                 | Notes                                                                                                |
| --------------- | ------------------------------------ | ---------------------------------------------------------------------------------------------------- |
| `id`, `ulid`    | bigint, ulid unique                  | `HasUlid`; the wire id is the ulid.                                                                  |
| `agency_id`     | FK `agencies`, restrict              | `BelongsToAgency` (tenancy scope).                                                                   |
| `wave_id`       | FK `waves`, restrict                 | The wave must be in the same agency (service rule and test).                                         |
| `stream`        | string(16)                           | The stream (§1.2): `ugc_video`, `statics`, `stills`. The PHP enum `WaveStream` is the constraint.    |
| `step_key`      | string(64), **nullable**             | The stable identity (§1.3). Null for a step a person added.                                          |
| `name`          | string(160)                          | Shown text. Free, editable, never used to find a step.                                               |
| `position`      | unsigned int                         | Order inside the wave and stream (§1.3).                                                             |
| `status`        | string(16), default `not_started`    | `not_started`, `in_progress`, `done` (enum `WaveStepStatus`; no migration to add a case).            |
| `owner_user_id` | FK `users`, nullable, `nullOnDelete` | An agency member (§1.7).                                                                             |
| `starts_on`     | date, nullable                       | Planned start.                                                                                       |
| `due_on`        | date, nullable                       | Planned due date.                                                                                    |
| `completed_on`  | date, nullable                       | Actual date. Set to the actor's today when the status becomes `done`, cleared when it leaves `done`. |
| `timestamps`    |                                      |                                                                                                      |

Indexes: **unique `(wave_id, step_key)`** (nulls never collide, so custom steps are free; it is the belt for provisioning, §3.1); **unique `(wave_id, stream, position)`** (two concurrent adds cannot take the same position); `(wave_id)`; `(agency_id)`. No soft delete: removing a step deletes the row and writes an audit row (P9 keeps wave deletion out).

### 1.2 Streams are an enum, not a table and not a free column

Frame 01b fixes three streams, each with a colour and an order: **UGC video** (`#3ecf9e`), **Statics** (`#8b6cff`), **Stills** (`#f472b6`). A table would add stream administration nobody asked for and a join on every read; a free-text column would let a typo create a fourth stream. So `stream` is a string column whose allowed values are the PHP enum `WaveStream` (the `WaveStatus` precedent: a plain column, the enum is the constraint, a new case needs no migration). Display name and colour come from the enum and from i18n. All three streams exist on every wave. A stream with no steps (all removed) shows its card with "No steps".

### 1.3 Step identity, position, and how a later sprint finds "its" step (T4)

- **`step_key`** is a stable machine key of the form `<stream>.<step>`, for example `ugc.client_approval` or `statics.design_v1` (full list in §1.4). It is written when the default list is provisioned, is never shown, and **never changes when a person renames the step**. A later sprint that wants to tick the step "footage received" looks up `WaveStep` by `(wave_id, 'ugc.footage_received')`. If a person has deleted that step, the lookup finds nothing and the automation does nothing (it must not recreate it). A step a person added has a null key and is never touched automatically.
- **`WaveStepKey`** (a small class of constants, beside `WaveStepDefaults`) is the only place keys are spelled, so a later sprint imports a constant, never a string literal.
- **`position`** orders steps inside one stream. Defaults take 10, 20, 30, … (gaps), so a later insert needs no renumber. A step added by hand is appended after the last position of its stream (the frame's "Add step to a stream" is a footer row). **Reordering is not in this chunk** (question Q10); the order is `position`, then `id`.

### 1.4 The default step list (T1)

Lives in code as `WaveStepDefaults::steps()` (Waves module, a pure list, like `BoardDefaults::columns()`), pinned by a test that compares the list with this table. **Names marked PROPOSED are not in frame 01b**: the frame draws 16 of the 24 steps and gives the other 8 only as the two ends of a collapsed line (inventory §0.3). They are my proposals, built from the strategy workflow of frames 02 to 08, for Pedram to correct (question Q2).

| Stream    | Order | Step key                     | Step name                  | Source                                                                                     |
| --------- | ----- | ---------------------------- | -------------------------- | ------------------------------------------------------------------------------------------ |
| UGC video | 1     | `ugc.kick_off_call`          | Kick-off call              | Frame (first of the 9 completed)                                                           |
| UGC video | 2     | `ugc.usps_personas_approved` | USPs and personas approved | **PROPOSED**                                                                               |
| UGC video | 3     | `ugc.matrix_approved`        | Messaging matrix approved  | **PROPOSED**                                                                               |
| UGC video | 4     | `ugc.concepts_approved`      | Concepts approved          | **PROPOSED**                                                                               |
| UGC video | 5     | `ugc.creators_confirmed`     | Creators confirmed         | **PROPOSED**                                                                               |
| UGC video | 6     | `ugc.scripts_approved`       | Scripts approved           | **PROPOSED**                                                                               |
| UGC video | 7     | `ugc.footage_received`       | Footage received           | **PROPOSED**                                                                               |
| UGC video | 8     | `ugc.footage_review`         | Footage review             | **PROPOSED**                                                                               |
| UGC video | 9     | `ugc.editing`                | Editing                    | Frame (last of the 9 completed)                                                            |
| UGC video | 10    | `ugc.internal_review`        | Internal review            | Frame                                                                                      |
| UGC video | 11    | `ugc.editing_feedback_1`     | 1st editing feedback       | Frame                                                                                      |
| UGC video | 12    | `ugc.editing_feedback_2`     | 2nd editing feedback       | Frame                                                                                      |
| UGC video | 13    | `ugc.client_approval`        | Client approval            | Frame                                                                                      |
| Statics   | 1     | `statics.brief_references`   | Static brief & references  | Frame                                                                                      |
| Statics   | 2     | `statics.design_v1`          | Design v1                  | Frame ("Design v1 — 8 statics"; the count is wave data, so it is not in the standard name) |
| Statics   | 3     | `statics.internal_review`    | Internal review            | Frame                                                                                      |
| Statics   | 4     | `statics.client_feedback`    | Client feedback round      | Frame                                                                                      |
| Statics   | 5     | `statics.final_export`       | Final export & resizes     | Frame                                                                                      |
| Stills    | 1     | `stills.shot_list`           | Shot list                  | Frame (first of the 3 completed)                                                           |
| Stills    | 2     | `stills.shoot_prep`          | Shoot prep                 | **PROPOSED**                                                                               |
| Stills    | 3     | `stills.shoot_day`           | Shoot day                  | Frame (last of the 3 completed)                                                            |
| Stills    | 4     | `stills.retouching_selects`  | Retouching & selects       | Frame                                                                                      |
| Stills    | 5     | `stills.internal_review`     | Internal review            | Frame                                                                                      |
| Stills    | 6     | `stills.client_approval`     | Client approval            | Frame                                                                                      |

That is 13 + 5 + 6 = **24 steps**, all `not_started`, no owner, no dates. The frame's mock data (owners, dates, "9 completed") is demo content, not a default.

**Default step names are stored as English text** (they are data the strategist edits, like a wave name). A reader in another language sees English step names until the step is renamed. Translating default names at read time by key is possible but costs 24 names × 24 locales; recommended later, not here (question Q2).

### 1.5 "Delayed": derived from the due date, not set by hand (recommended)

**Recommendation: derived only.** A step is **delayed** when it is not done and `due_on` is before today. No "delayed" status is stored. Reasons:

- A hand-set flag goes stale: move the due date and the flag still says delayed, and a later sprint that ticks the step must clear it too.
- Every figure frame 01b shows for delay is arithmetic on dates ("2 days", "Overdue since Oct 13", "Due today").
- It cannot disagree with the stream card, because the card is computed from the same dates.

Cost, stated: a person cannot mark a step "at risk" before its date passes, and the frame's "Flagged by Lena" has no equivalent (the fix card is left out, §9, question Q7).

**"Today"** is the date in the **requesting user's time zone** (`users.timezone`, default `UTC`; the agency has no time zone column, inventory §9). Two users in different zones can see a step flip at different hours around midnight; accepted.

**Display status** (computed, sent by the API, never stored), in this order of precedence: `done`; `delayed` (open and `due_on` < today); `due_today` (open and `due_on` = today); `in_progress`; `up_next` (stored `not_started`). So an in-progress step due today shows "Due today". Colours: the five pill tones of the frame (green, red, amber, blue, grey), added as a `wave_step` kind in `agencyStatusTones.ts` (a frozen file: re-pinned in the same commit with the reason, as PL-1.1 and PL-1.2 did).

**Stream roll-up.** A stream is **Complete** if it has steps and all are done; **Delayed · N days** if any open step is delayed, with **N = the largest number of days overdue** among its open steps (frame: Design v1 due Oct 13, today Oct 15, so "2 days"); otherwise **On track**; with no steps it shows "No steps". **Wave health pill:** "At risk · N streams delayed" (N ≥ 1, "1 stream delayed" in the singular) or "On track".

### 1.6 Progress: the rule that turns steps into segments and into each stream's figure

**Stream card figure.** One segment per step of that stream, left to right: the first `done` segments are filled (green; red on a delayed stream), the rest are empty. The text is "`done` of `total` steps". This is exactly frame 01b (13, 5 and 6 segments). Adding or removing a step changes the number of segments and nothing else.

**Brand page and wave page Progress (six segments and a word).** Over **all steps of the wave** (`done` and `total`):

- `total` = 0 (an unprovisioned wave, or every step removed): zero segments filled, label "Not started".
- `done` = `total` (> 0): **6 segments filled**, label "Complete".
- otherwise filled = `min(5, ceil(6 × done ÷ total))`: any progress shows at least one segment, and six is reserved for "Complete". (13 steps, 9 done: `ceil(4.15)` = 5.)
- **Label** when not complete: if `done` = 0 and no step is in progress, "Not started" (the word PL-1.1 shows today stays). Otherwise the **name of the current step**: the open step with the earliest `due_on` (steps with no date last), ties broken by stream order and then `position`.

The rule uses ratios and names only, so adding or removing a step never breaks it (a test removes and adds steps and asserts the segments). Both the Brand page list and the wave page use one server-side `progress` object (`segments`, `done`, `total`, `label_kind` `not_started | step | complete`, `step_name`); the web draws it and holds no copy of the rule.

**Important mismatch, for Pedram (question Q1).** In frame 01 the six segments and the word are the **six Strategy & Scripts steps** (USPs, Personas, Messaging matrix, Concepts, Creators, Scripts; inventory §1), not the tracker. You asked for the Progress column to be fed from the tracker, so the rule above does that. The six strategy steps do not exist as data until PL-2; at that point the column's meaning should be decided again.

### 1.7 The owner reference (T2)

`owner_user_id` points at a `users` row and must be an **active member of the same agency**: an `agency_users` row with `agency_id` of the wave, `accepted_at` not null and `deleted_at` null (the AH-119 check, `owner.not_a_member`, reused; here the code is `step.owner_not_a_member`, 422). The wire carries the user's **ulid**. Null clears the owner. No client owner exists (T2, PL-4).

**A member removed later.** Memberships are soft deleted and the pointer is kept, so at read time an owner who is no longer an active member is sent as `owner: null` with `owner_is_former: true`, and the web shows "Former member" (muted). The step counts as unassigned for the "Mine" filter. Nothing is written when a member leaves (no cascade, no job). **Not read:** the code that removes a member, if any exists outside the routes I read (inventory §4); the rule above does not depend on it.

---

## 2. Migrations

| File                                                      | Table        | Change    |
| --------------------------------------------------------- | ------------ | --------- |
| `2026_11_19_100000_create_wave_steps_table.php`           | `wave_steps` | M1 above. |
| `2026_11_19_100001_add_steps_provisioned_at_to_waves.php` | `waves`      | M2 above. |

- Both are **schema-only**: no `DB::table()`, no data write, no backfill.
- Both start with `SET LOCAL lock_timeout = '5s'` (PostgreSQL only, guarded so SQLite runs).
- Run locally and on test databases only. If a backfill is ever needed it is a separate, dry-runnable artisan command, not a migration. None is needed: first open provisions (§3.1).
- Erasure decisions (§6 of the inventory): `wave_steps` is `NotApplicable` ("Agency-authored (§3.7)"), `refs ['owner_user_id']`, `classified ['name' => 'A step title, not creator data.']`; `waves.steps_provisioned_at` is a date, not personal. They go in `ErasureRegistry` in the same commit.

---

## 3. API

All routes sit in the existing Waves route group (the standard tenant stack, under `agencies/{agency}/brands/{brand}/waves/{wave}`) and **every action starts with the `waves_enabled` check (404 `not_found`)**, then `assertBrand`, then the wave lookup (a soft-deleted wave or another agency's wave is a 404).

### 3.1 Provisioning (T1): idempotent, once, safe under two requests

One service, `WaveStepProvisioner::ensure(Wave)`, used in two places: **at wave creation** (inside the existing creation transaction in `WaveService`, so a new wave starts with its 24 steps) and **on first open** of the tracker (`GET …/tracker`) for a wave whose `steps_provisioned_at` is null. It is the `BoardService::ensureBoard` precedent (a write on the GET, idempotent; inventory §5).

1. Open a transaction and re-read the wave with `lockForUpdate()`.
2. If `steps_provisioned_at` is not null, return (nothing written). **This marker is what makes it once**: if a strategist later deletes every step, the page does not bring them back.
3. Otherwise insert the 24 default steps and set `steps_provisioned_at` to now, in the same transaction; write one audit row (`WaveStepsProvisioned`).
4. Belt: the unique `(wave_id, step_key)` index. If two requests both pass step 2 (SQLite ignores row locks; PostgreSQL does not), the loser catches `UniqueConstraintViolationException`, re-reads and returns.

A wave whose flag is off is never provisioned: no route is reachable.

### 3.2 Endpoints

| Route                                               | Purpose                                                                                                                                                                                               |
| --------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET …/waves/{wave}/tracker`                        | Provisions if needed (§3.1), then returns `streams` (key, colour token, status, days delayed, done, total, next step, final delivery, distinct owners), `steps` (all, ordered), `health`, `progress`. |
| `POST …/waves/{wave}/steps`                         | Add a step: `stream`, `name`, optional `owner` (ulid), `starts_on`, `due_on`. Appended after the last position of the stream. Admin and manager.                                                      |
| `PATCH …/waves/{wave}/steps/{step}`                 | Change `status`, `owner`, `starts_on`, `due_on` (any member role). `name` and `stream`: admin and manager only (§3.3).                                                                                |
| `DELETE …/waves/{wave}/steps/{step}`                | Remove a step. Admin and manager.                                                                                                                                                                     |
| existing `GET/…brands/{brand}/waves` and `…/{wave}` | Add the `progress` object (§1.6) to each wave. The list computes it with grouped queries over the page's wave ids, no N+1, and **never writes**: an unprovisioned wave reads as "Not started".        |

Step shape (JSON:API-like, as the rest): `id` (ulid), `type: 'wave_steps'`, attributes `stream`, `key` (nullable), `name`, `position`, `status`, `display_status`, `days_overdue`, `starts_on`, `due_on`, `completed_on`, `owner` (`{id, name}` or null), `owner_is_former`.

Validation: `name` 1 to 160; dates `Y-m-d`; `due_on` not before `starts_on` (422 `step.dates_invalid`) when both are set; `stream` must be a `WaveStream`; `status` a `WaveStepStatus`; a wrong `owner` is 422 `step.owner_not_a_member`.

### 3.3 Roles per action (T3), each a gate

A new `WaveStepPolicy` (reads the role of the **current request's agency**, like `WavePolicy`):

| Action                                  | agency_admin | agency_manager | agency_staff | Gate id |
| --------------------------------------- | ------------ | -------------- | ------------ | ------- |
| View the tracker                        | yes          | yes            | yes          | R1      |
| Add a step                              | yes          | yes            | **no**       | R2      |
| Remove a step                           | yes          | yes            | **no**       | R3      |
| Change status, owner, due or start date | yes          | yes            | yes          | R4      |
| Rename a step or change its stream      | yes          | yes            | **no**       | R5      |

A PATCH that mixes allowed and forbidden fields from a staff user is refused as a whole (403), never half-applied. Renaming is not named in T3; I treated it as editing structure (question Q8).

### 3.4 Concurrency: two people on one step, or a removal while someone edits

- **Two people edit the same step.** The PATCH sends only the fields that changed and updates only those, inside a transaction that takes `lockForUpdate()` on the step row and re-reads it. Two people changing **different** fields (one the owner, one the date) both keep their change. Two people changing the **same** field: the later write wins, and the earlier person sees the new value when the page reloads the row (every save returns the full step and the tracker is reloaded on focus). No version check (`If-Match` and a 409) is added; it would need a "your copy is stale" screen that has no design, and the cost of a lost overwrite here is one field re-entered (question Q11 asks whether Pedram wants it).
- **One person removes a step while another edits it.** The PATCH finds no row after the lock and answers **404 `not_found`**; the web shows "This step was removed" and reloads the list. A DELETE of a missing step is also 404, not an error page.
- **Two adds at once.** The wave row is locked (`lockForUpdate()`) while the next position is read and the row inserted, and the unique `(wave_id, stream, position)` is the belt; the loser retries once.
- SQLite ignores row locks (as in PL-1.2): the lock tests assert the `for update` SQL and the unique-index tests run on PostgreSQL as well (§6). I do not claim a real two-client race was run.

### 3.5 Audit

New actions: `WaveStepsProvisioned`, `WaveStepAdded`, `WaveStepUpdated`, `WaveStepRemoved`. Payload keys are **literals** (for `AuditPayloadKeysGuardTest`): `stream`, `step_key`, `name`, `status`, `owner_id` (the user's ulid, an id only), `starts_on`, `due_on`, `completed_on`; each key gets an Erasure decision in `AuditPayloadKeys::notPersonalKeys()` in the same commit (PL-1.2 found five undecided keys that way). One audit row per request, with `before` and `after` for an update.

### 3.6 Erasure and tenancy

See §2 and the inventory §6. The Erasure folder runs locally before the push (§6). Every query on `wave_steps` carries the agency scope; the step must belong to the wave in the route, and the wave to the agency (a mismatch is 404).

---

## 4. Web

### 4.1 What is built

The wave page (`WaveDetailPage.vue`) keeps its header, edit dialog and **details card** (it holds the campaign link and its lock, which frame 01b does not draw; question Q9). Below the card, in this order:

1. **Health pill** next to the title ("At risk · 1 stream delayed" or "On track").
2. **Stream cards**, three across at desktop width (§4.3): swatch and name, tracking pill, progress segments and "9 of 13 steps", NEXT STEP, FINAL DELIVERY, and the distinct owners as avatars with first names. A delayed card has the red border and red segments.
3. **Steps panel** "Deliverable steps": filter chips **All, UGC, Statics, Stills, Mine**; columns **STEP, OWNER, DUE, STATUS**; one group per stream (swatch, name, "9 of 13 done", pill); completed steps collapsed into "9 completed steps · Kick-off call → Editing (Sep 25 – Oct 12) Show" with the dates taken from `completed_on`; open step rows with the round check (click to mark done and back), owner, due, status pill and a row menu; footer "+ Add step to a stream".
4. **Brand page and wave page Progress** use the server `progress` object; `WaveProgressSegments.vue` takes it as a prop (flag-off path unchanged: it is only rendered while `features.waves` is on).

Due format as the frame: "Today", "Tomorrow", "Mon, Oct 19", "Oct 22", a range "Oct 16 → 19" when both dates exist; a year is added when the date is not in the current year (a decision beyond the frame, which shows none).

### 4.2 Screens with no design (T6), built from the design system

Each is named, with the parts it uses:

| Screen or state                                    | Built from                                                                                                                 |
| -------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| **Edit step** (name, stream, owner, dates, status) | `v-dialog` + `v-card` + `v-text-field`, `type="date"` fields (as the campaign form), `v-select`                            |
| **Owner picker**                                   | `v-select` over `GET /members` (active members only), with a "Unassigned" item. No ready-made picker exists (inventory §5) |
| **Add step**                                       | The same dialog in create mode (stream preselected from the footer row)                                                    |
| **Remove step**                                    | `v-dialog` confirmation (name and a "Remove" button)                                                                       |
| **Row menu**                                       | `v-menu` + `v-list`: Edit, Remove (Remove hidden for staff)                                                                |
| **Empty** (a stream with no steps)                 | Stream card "No steps", and an empty group line with the add-step link                                                     |
| **Loading**                                        | `v-skeleton-loader` (as the wave page)                                                                                     |
| **Error**                                          | `v-alert type="error"` with a Retry button (as the wave page)                                                              |
| **Step removed by someone else**                   | `v-snackbar` "This step was removed" and a reload                                                                          |
| **Narrow widths** (no design at all)               | §4.3                                                                                                                       |

Inline status change follows the CampaignListPage pattern: the check toggles `done` optimistically, a failure reverts and shows an inline error; owner and date edits save from the dialog, and the owner and due cells are also click-to-edit through the same dialog (no separate inline editors).

### 4.3 Widths

Frame 01b exists only at 1600 px. Decisions, flagged as not designed: **≥ 1280** three cards in a row and the full table; **768 to 1279** cards wrap to one per row; **below 768** the table becomes stacked rows (name, then owner, due and status under it) and the filter chips scroll sideways. The tracker is opened in a real browser at **1440 and 390** before a walk is offered (§6, `WORKING-PROCESS.md` §4.2).

### 4.4 Frozen files

`agencyStatusTones.ts` gains a `wave_step` kind (and `agency.css` is not edited if the tracker can be styled from tokens already there; if it must change, hashes are re-pinned in the same commit with the reason, no at-rules).

---

## 5. Flag

`waves_enabled`, OFF by default, never flipped by the build. With the flag OFF:

- **Every new route answers 404** from the first line (the controller check, before any lookup). Nothing is provisioned, nothing is read.
- **The web:** the wave route already redirects to the brand page; the brand page's Waves panel is not rendered (`features.waves`); the tracker components exist only inside `WaveDetailPage`. `BrandWavesPanel` rendering is unchanged while off.
- **Campaign and board pages are untouched:** no scoped `<style>` block, no false `v-if` or new element on a campaign page, no change to `BoardDefaults::columns()` or `automations()`.
- **The status-tone table** gains a kind that nothing renders while off.
- The agency walk's **12 campaign** and **16 brand** targets must stay identical to `ah-115-agency-baseline/baseline.json` (the wave page is not a walk target, inventory §7).
- A wave created before the flag was turned off keeps its steps; nothing reads them while off.

Production note (carried from PL-1.2, Q15): `waves_enabled` must not be turned on in production for real creators before PL-6 decides the creator's "Submit your draft" form.

---

## 6. Tests (each gate has a break-revert)

Every gate is run on the real code first, then broken, then reverted, and recorded in `pl-1-3-break-reverts.md`.

### Backend gates (Pest)

| Id    | What it proves                                                                                                                                                                                                     | Break-revert (what to break)                                |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------- |
| F1    | **Flag:** every tracker and step route (and the new `progress` on the list) answers 404 with the flag off, with no row written                                                                                     | remove the flag check on one route                          |
| TN1   | **Tenancy:** another agency's wave and step are 404 on every route; a step cannot be reached through a wave of its own agency but another brand/wave id                                                            | drop the agency scope on the wave or step lookup            |
| R1–R5 | **Roles**, one test per row of §3.3 for each of the three roles (staff 403 on add, remove, rename; allowed on status, owner and dates; staff PATCH mixing fields is refused whole)                                 | let staff add or remove; forbid staff status change         |
| O1    | **Owner must be a member of the same agency:** a member of another agency, a user with a pending or removed membership, and a nonexistent ulid are 422 `step.owner_not_a_member`; null clears                      | skip the membership check                                   |
| O2    | A removed member shows as `owner: null`, `owner_is_former: true`, and does not match "Mine" (the web) / the API field                                                                                              | return the removed member as the owner                      |
| P1    | **Provisioning runs once:** a second call writes nothing; a wave whose steps were all deleted stays empty; existing waves (rows created without a marker) get the 24 steps on first open; the list is exactly §1.4 | ignore `steps_provisioned_at`                               |
| P2    | Provisioning at wave creation is in the creation transaction (a failure rolls back the wave and the steps)                                                                                                         | provision after the transaction                             |
| P3    | **PostgreSQL:** the provisioning `for update` SQL is asserted, and the unique `(wave_id, step_key)` index refuses a duplicate; the loser path returns without error                                                | drop the unique index in the test schema / remove the catch |
| PR1   | **Progress rule:** the table of cases in §1.6 (0 steps, none done, some done, all done, a step added or removed, the `ceil` and `min(5, …)` edges, label choice by due date then stream then position)             | change `ceil` to `floor`; drop the `min(5, …)`              |
| D1    | **Delayed is derived:** a done step is never delayed; an open step due yesterday is delayed with the right `days_overdue` in the user's time zone; due today is `due_today`; a stream's "N days" is the maximum    | count a done step as delayed                                |
| S1    | **Concurrency:** PATCH after the step was deleted is 404; two PATCHes of different fields both persist; two adds take different positions (PostgreSQL runs the unique `(wave_id, stream, position)`)               | drop the lock / the unique index                            |
| A1    | **Audit:** each action writes one row with the literal keys; `AuditPayloadKeysGuardTest` stays green                                                                                                               | drop an Erasure decision for a new key                      |
| E1    | **Erasure folder** green with the new table, column and audit keys (run locally; a table-level decision is a real gate, a column one was not in PL-1.2)                                                            | remove the `wave_steps` registry entry                      |
| K1    | The default list is pinned: 24 rows, the keys and names of §1.4, positions 10, 20, …; keys are unique                                                                                                              | rename one key                                              |
| M1    | Both migrations run up and down on SQLite and PostgreSQL; M2 leaves existing rows with a null marker                                                                                                               | (schema test)                                               |

**PostgreSQL rule (carried from PL-1.1 run 449 and PL-1.2):** every test that depends on a unique violation, a lock or a transaction (P2, P3, S1, M1) also runs on PostgreSQL through `phpunit.pgsql.xml` with the pgsql env set, then unset. The Erasure folder runs locally. Folders run **one at a time** with `memory_limit` raised (one process for all folders ran out of the 128 MB default in PL-1.2). Local gates: Waves, Boards, Campaigns, Brands, Erasure, TestHelpers on SQLite and PostgreSQL, Pint, Larastan, `vue-tsc`, ESLint, the touched Vitest set, and the flag-off walk (12 and 16).

### Web gates (Vitest and Playwright)

| Id  | What it proves                                                                                                                                                                                                                                                                    | Break-revert                               |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------ |
| W1  | Stream card renders each state (on track, delayed with days, complete, no steps) and one segment per step                                                                                                                                                                         | draw a fixed six segments                  |
| W2  | Steps table: grouping, collapsed completed line with the first and last name and date range, filters All/UGC/Statics/Stills/Mine                                                                                                                                                  | break the Mine filter                      |
| W3  | Display status precedence and the five pill tones                                                                                                                                                                                                                                 | swap delayed and due today                 |
| W4  | Role gates in the UI: staff see no Add, Remove or rename; admin and manager do                                                                                                                                                                                                    | show Remove to staff                       |
| W5  | Optimistic done toggle reverts on a failed save; "This step was removed" on a 404                                                                                                                                                                                                 | skip the revert                            |
| W6  | Brand page progress renders the server object (empty, partial, complete, label) and the flag-off render is unchanged                                                                                                                                                              | ignore `label_kind`                        |
| W7  | Arch: `agency-frozen` re-pin (if touched), status tones, i18n key coverage in 24 locales (`waveKeys.spec.ts`)                                                                                                                                                                     | add a key to one locale only               |
| E2E | **`wave-tracker.spec.ts` through `playwright.config.ts`** at 1440 and 390 widths: opens a wave, stream cards visible, no horizontal page overflow, the owner menu and the edit dialog open **above** each other and a quick close and reopen leaves no stale menu (the D1 lesson) | remove the per-open key on the dialog card |

**`WORKING-PROCESS.md` §4.2 applies:** the tracker is opened in a real browser, at a desktop width and a narrow one, including a menu left open across a fast dialog reopen, **before** a walk is offered to Pedram. Stacking, width and overlay defects are invisible to unit tests.

---

## 7. Strings

All new strings in the **24 locales** (the verifier `scripts/i18n/verify-locale.mjs` and `waveKeys.spec.ts`), under `app.waves.tracker`. About 70 keys: title and subtitle; health pill (on track, at risk with a count, singular and plural); stream names (UGC video, Statics, Stills); stream status (on track, delayed with days, complete, no steps); "{done} of {total} steps"; NEXT STEP, FINAL DELIVERY, "No date"; filter chips; column headers; display statuses (up next, in progress, due today, delayed, done); group line ("{done} of {total} done"); collapsed line and Show/Hide; due words (Today, Tomorrow); owner labels (Unassigned, Former member); add, edit and remove dialogs (titles, fields, buttons, errors for the 422 codes and the 403 and 404 cases); empty, loading, error, retry; "This step was removed"; row menu items; the Brand page progress labels (the existing "Not started" is kept, "Complete" is added). Default step names are **data** (§1.4), not locale keys. Names of people are never translated.

---

## 9. Left out of this chunk

- **Calendar or timeline view** (T5): frame 01b has none; the day-grouped "Due this week" list is left out pending a ruling (Q6).
- **Notifications, reminders, e-mail or push to owners** (T5), any automatic ticking of steps (T4: a later sprint), real-time or live updates.
- **Right column:** Needs attention, Due this week, Team on this wave and capacity. Roles ("Strategist", "Editor") and capacity ("3 waves this week") are not data the system has.
- **Header buttons "Share status update" and "Add task"** (adding a step is the table footer and the dialog), **wave tabs** other than the tracker (Strategy & Scripts, Editing, Statics, Stills, Files), the **tooltip** on the stream pill.
- **"What needs fixing" card** (Blocked, Impact, To recover; the buttons Chase client, Use current screens, Move due date, Reassign) and the **projected delivery** ("Oct 22 → Oct 24"). Move due date and Reassign are the normal edit. Question Q7.
- **"Open in" column** and the per-stream footer link (Strategy & Scripts, Figma file, Shoot folder): their targets do not exist (footage review is PL-6, Figma and the shoot folder are not in the system).
- **The stream card's second line** ("12 videos · 4 creators · SK, RO"): it needs creator and deliverable counts that the campaign or PL-3 supply. Markets could be shown from the wave; left out so the card matches one rule.
- **Client owners** and the "Client" owner on client steps (T2, PL-4): such steps are unassigned here.
- **Step reordering**, step dependencies, templates other than the one default list, deleting a wave (P9), per-step comments, attachments.
- **PL-1.1's Draft-only wave status** is unchanged (statuses beyond Draft need PL-2).

---

## 10. Walk script for Pedram (local)

**Setup (named seed data).** A new seeder pair, **`Pl13WalkSeeder`** and **`Pl13WalkCleanupSeeder`** (local and testing only, idempotent, additive, never touches the flag; Pedram turns the flag on and off by hand, as in PL-1.2; password printed by the seeder, nothing secret in the repo):

- **Agency A "PL-1.3 Walk Agency A":** members `admin@`, `manager@`, `staff@`, `designer@pl13-walk.local` (an extra staff member to pick as owner), and `former@pl13-walk.local` (an owner on a seeded step whose membership is removed). Brand **Bolt Food**.
- **Waves (Bolt Food):** **Wave 6** (existing, **no steps and no marker**: the first-open proof); **Wave 7** (empty to be created in the walk); **Wave 8** (provisioned, 3 steps done, 2 in progress, one with a past due date and one due today, one owned by `former@`: the delayed and former-member proof); **Wave 5** (provisioned, all 24 done: "Complete" on the Brand page).
- **Agency B "PL-1.3 Walk Agency B":** `admin@pl13-walk-b.local`, brand **Glovo**, wave **Glovo Wave 1** (with steps).

**Steps:**

1. **Flag OFF, unchanged.** As `admin@`, open Bolt Food, a campaign and the campaigns list: identical to before. Open `/brands/<id>/waves/<wave>`: you are sent back to the brand page; the Waves panel is absent.
2. **Flag ON.** Open Bolt Food. Waves show Wave 8, 7, 6, 5 with Progress: Wave 5 "Complete" (six filled), Wave 8 partly filled with a step name, Wave 6 "Not started".
3. **Open Wave 6 (first open).** The page shows three stream cards and 24 steps. Open it again and reload: still 24 (no duplicates).
4. **Open Wave 8.** UGC "On track"/"Delayed · N days" as the data says; the delayed step has the red pill and the red date; the health pill says "At risk · 1 stream delayed". The step owned by `former@` shows "Former member".
5. **Set an owner and dates** on a step (owner `designer@`, start and due). The row and the stream card's owners update.
6. **Mark a step done** (click its check). The stream card figure ("x of y steps"), the segments, the NEXT STEP and the collapsed "completed steps" line move, and so does the **Brand page progress** after you go back.
7. **Make a step delayed:** set its due date to yesterday. Red pill, stream "Delayed · 1 day", card border red, health pill "At risk". Set the date to today: "Due today" (amber). Set it to tomorrow: it clears.
8. **Add a step** to Statics (name "Resize for Stories", owner and due date), then **remove** it. Remove a default step too; reload: it stays removed and the list does not refill.
9. **Filters:** All, UGC, Statics, Stills, Mine (Mine shows only your own steps).
10. **Staff:** sign in as `staff@`. Open a wave: no Add step, no Remove, no rename; you can change status, owner and dates.
11. **Manager:** adds and removes like admin.
12. **Two tabs:** in tab 1 remove a step; in tab 2 (opened before) edit that step: "This step was removed".
13. **Second agency:** as `admin@pl13-walk-b.local` open Glovo Wave 1 (works); paste Wave 8's address: not found; the Brand pages of the two agencies show only their own waves.
14. **Narrow width** (a 390 px window): cards stack, the table becomes stacked rows, nothing runs off the page.
15. **Cleanup:** `Pl13WalkCleanupSeeder`; the flag state is reported, not changed.

---

## 11. Questions and rulings (Pedram)

Each has my recommendation. The build cannot start until Q1, Q2, Q3 and Q9 are answered (the rest are low-cost defaults I will use unless told otherwise).

| #       | Question                                                                                                                                                                                                                                                                                                    | Recommendation                                                                                                                                                                                                                                                                                                                                                                  |
| ------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Q1**  | **What are the six Progress segments on the Brand page?** In frame 01 they are the six Strategy & Scripts steps (USPs, Personas, Messaging matrix, Concepts, Creators, Scripts), with the current step's name. Frame 01b has three streams and one segment per step. The tracker has no six-part structure. | Feed the six segments from the tracker with the ratio rule of §1.6 (six filled only when every step is done; the word is the current step's name; "Not started" stays when nothing has started). Revisit the meaning when PL-2 builds the six strategy steps. The alternative (six fixed phases of steps) needs every step assigned to a phase and breaks when a step is added. |
| **Q2**  | **The 8 step names frame 01b does not show** (UGC steps 2 to 8, Stills step 2), and **English-only default names** (stored text).                                                                                                                                                                           | Use the proposed names in §1.4 (marked PROPOSED) unless Pedram sends the real list; store them in English; translate by key later if wanted.                                                                                                                                                                                                                                    |
| **Q3**  | **Is "Delayed" set by hand, derived from the due date, or both?**                                                                                                                                                                                                                                           | **Derived only** (§1.5). It cannot go stale and needs no later sprint to clear it. The cost is no "at risk" flag before the date passes.                                                                                                                                                                                                                                        |
| **Q4**  | **Client steps** (Client feedback round, Client approval) show an owner "Client" in the frame. T2 says no client owners.                                                                                                                                                                                    | Leave them unassigned ("Unassigned") until PL-4 adds client owners.                                                                                                                                                                                                                                                                                                             |
| **Q5**  | **"Open in" column** and the per-stream footer link.                                                                                                                                                                                                                                                        | Leave both out (targets do not exist); add them with footage review (PL-6), the Figma link and the shoot folder.                                                                                                                                                                                                                                                                |
| **Q6**  | **Right column** (Needs attention, "Due this week" day list, Team on this wave and capacity), the header buttons and the other wave tabs. T5 says report a calendar view and leave it out.                                                                                                                  | Leave all out. "Due this week" is a list, not a calendar, so it is not forbidden, but it needs notifications-like logic and roles and capacity data that do not exist. Build it in a later chunk if wanted.                                                                                                                                                                     |
| **Q7**  | **The "What needs fixing" card** and the projected delivery date.                                                                                                                                                                                                                                           | Leave out. If wanted, add one optional `note` column (free text, up to 500 characters, shown under a delayed step), without Chase client, Use current screens or the projection.                                                                                                                                                                                                |
| **Q8**  | **Who may rename a step or move it to another stream?** T3 names add, remove, status, owner and dates only.                                                                                                                                                                                                 | Admin and manager only (renaming edits the structure).                                                                                                                                                                                                                                                                                                                          |
| **Q9**  | **Page layout:** keep the PL-1.1/1.2 details card (campaign link, lock, created by) above the tracker, or fold it into the header as frame 01b draws it?                                                                                                                                                    | Keep the card above the tracker: it carries the campaign link and lock (P4, P5) that the frame does not draw, and moving it risks the flag-off page and the PL-1.2 tests.                                                                                                                                                                                                       |
| **Q10** | **Reorder steps** (drag or up and down)?                                                                                                                                                                                                                                                                    | Not in this chunk; order is position, then id. The gaps in `position` leave room for it.                                                                                                                                                                                                                                                                                        |
| **Q11** | **Concurrency strength:** last write wins per field (recommended), or a version check with a "stale copy" screen (409)?                                                                                                                                                                                     | Last write wins per field, plus the 404 handling for a removed step (§3.4). A version check needs a screen with no design.                                                                                                                                                                                                                                                      |
| **Q12** | **Time zone of "today":** the requesting user's `users.timezone` (recommended), or the server's `APP_TIMEZONE` (default UTC)?                                                                                                                                                                               | The user's time zone; the agency has no time zone setting (inventory §9).                                                                                                                                                                                                                                                                                                       |

---

## 12. Size estimate and build order

**Size: M to L** (a new table, a derived-status and progress engine, a new interactive web surface with no narrow design, a 24-locale string set, and a PostgreSQL gate set). Estimates, not commitments:

- Backend: about 16 new files (two migrations, `WaveStream`, `WaveStepStatus`, `WaveStepDefaults`, `WaveStepKey`, `WaveStep` model and factory, `WaveStepProvisioner`, `WaveProgress` and `WaveTrackerView` calculators, `WaveStepController`, `WaveTrackerController`, `WaveStepPolicy`, requests, resources) and about 8 edited (`WaveService`, `WaveController`, `WaveResource`, routes, `AuditAction`, `AuditPayloadKeys`, `ErasureRegistry`, `WavesServiceProvider`).
- Web: about 10 new files (stream card, steps panel, step row, step dialog, owner select, remove dialog, tracker api, tracker store or composable, date and progress helpers) and 6 edited; 24 locale files; api-client types.
- Tests: about 12 new backend test files and 8 web specs; one Playwright spec; the walk seeder pair (2 files).
- Rough effort: 7 sub-steps; about 5 working days of build plus one for translations, the PostgreSQL runs, the real-browser pass and the walk.

**Build order** (each sub-step independently green on SQLite and, where it touches a lock or a unique index, on PostgreSQL):

1. **Schema and defaults.** M1, M2, enums, `WaveStepDefaults`, `WaveStepKey`, model, registry and audit key decisions. Tests K1, M1, E1.
2. **Provisioning.** `WaveStepProvisioner`, wave creation and first open. Tests P1 to P3. **PostgreSQL run.**
3. **Derived values.** Display status, stream roll-up, health and the progress rule, `progress` on the wave list and show. Tests D1, PR1.
4. **Step endpoints.** Add, update, remove, owner rule, roles, concurrency, audit. Tests F1, TN1, R1 to R5, O1, O2, S1, A1. **PostgreSQL run.**
5. **Web: the tracker.** Stream cards, steps panel, filters, dialogs, optimistic toggle, strings in 24 locales, status-tone kind and re-pin. Tests W1 to W5, W7.
6. **Web: progress and brand page.** Server `progress` in `WaveProgressSegments`, Brand page and wave page. Test W6.
7. **Close-out.** Walk seeder pair, **the tracker opened in a real browser at 1440 and 390** (§4.2 lesson), `wave-tracker.spec.ts` through the config, full local gate set (including Erasure and PostgreSQL), the flag-off agency walk (12 and 16), the review file with a Production posture section and the migration rehearsal timing.

Commits: feature commit and docs commit as a pair (`WORKING-PROCESS.md` §2). **No push until Pedram has walked.**

---

## Read and not read

**Read:** Figma frame 01b (tree, header, stream cards, the whole steps panel through `get_design_context`), frame 01 (tree and table header); the Waves module (routes, model, policy, controller `index`, `store`, `campaignOptions`, flag class, create migration, audit calls); the Agencies members controller and the AH-119 owner controller; `CurrentAgencyRole`; the Erasure registry entries for waves, agency tables and `owner_user_id`; `BoardService::ensureBoard`; `WaveDetailPage.vue`, `WaveProgressSegments.vue`; the PL-1.1 notes on frame 01; `production-line-sprints.md` §6; the Playwright config.

**Not read:** frame 01b's right column, tooltip and wave tabs through `get_design_context`; every undrawn state; any narrow design (none exists in what I read); `WaveController::show` and `update` bodies, `WaveResource`, `WaveService::update`, `BrandWavesPanel.vue` beyond the progress cell, `WaveDetailPage.spec.ts`; the code that soft-deletes a membership, if any; any agency-level time zone; `RegistryVerifier` internals (only that it checks tables and user references); the production database and deploy runbooks (nothing was run against any deployed environment). **Not verified:** that two concurrent first-open requests behave as §3.1 describes on PostgreSQL (the build proves it with the lock SQL and the unique index, not with a real two-client race).
