# PL-1.3 plan: Wave project tracker

**Date:** 2026-11-18. **Branch:** `pl`, from tip `47572aad`. **Status:** plan, **amended 2026-11-18 (evening) with Pedram's rulings Q1 to Q12, the new stream-removal ruling and the PL-2 rulings F1 to F3** (sections 1.2 to 1.7, 3, 4, 6, 10 and 11). Docs only: no code, no migration run. No Cursor build prompt is issued until Pedram has said go (`production-line-sprints.md`).
**Inputs:** `pl-1-3-inventory.md` (read it first; "inventory §n" points into it), `production-line-sprints.md` §6 (R1 to R10 and P1 to P9, binding), `pl-1-2-review.md`.
**Standing rules applied:** schema-only migrations, the alarm rule, break-revert on every gate, the 24-locale gate, `WORKING-PROCESS.md` §4.1 local-run rules and §4.2 (real browser), the Erasure folder before every push, and the PostgreSQL rule in section 6.

**Source note.** The developer brief ("Strategy & Script Writing Developer Brief v2", relayed by Pedram, not read by Cursor) describes the six-step workspace and the "6-segment progress". **It does not mention the project tracker, streams, Statics or Stills. Those come from Figma frame 01b only.**

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

Frame 01b has **no calendar or timeline**. It has a day-grouped "Due this week" list (right column) which is reported in the inventory (§0.2) and left out (§9, Q6 agreed).

---

## 8. PROD-DATA RISK (stated first, before any code)

**PROD-DATA RISK: NONE for existing data, but brief write locks on live tables (corrected 2026-11-18).** Both migrations are additive. Neither reads, writes or backfills a row. The earlier wording of M1, "creates a new, empty table; no existing table is altered", was incomplete: no existing table is _altered_, but it is _locked_.

- **M1** creates a **new, empty** table `wave_steps`. Adding its three foreign keys takes a **ShareRowExclusiveLock on `waves`, `agencies` and `users`** (AccessExclusive only on the new table): **writes to those tables wait, reads do not**, for the length of the migration transaction. Measured on a synthetic PostgreSQL database: 3.2 ms for create plus foreign keys (11.67 ms in the six-migration rehearsal), 18.42 ms as an artisan run. `agencies` and `users` are live in production. It starts with `SET LOCAL lock_timeout = '5s'`.
- **M2** adds one **nullable** column, `steps_provisioned_at`, with no default, to `waves`. A nullable column with no default is a catalogue-only change in every PostgreSQL version (no rewrite) but takes an **AccessExclusiveLock on `waves`** for 0.14 ms (1.88 ms as an artisan run), and production is PostgreSQL 17.9 (Q11, confirmed 2026-11-18, source: the colleague's read-back relayed by Pedram, not read by Cursor). It starts with `SET LOCAL lock_timeout = '5s'`.
- **The `waves` table does not exist in production yet.** PL-1.1 is built and walked, not merged, not deployed (`production-line-sprints.md` §6). So M2 will run in the same deploy that creates `waves`, on an empty table, once `pl` is merged. That is stated from the sprint file, not from any server.
- **No data-writing migration (T1).** Existing waves get their steps on first open, by application code behind the flag (§3.1). Nothing writes a step until `waves_enabled` is on and someone opens a wave or creates one.
- `down()` for M1 is `dropIfExists` (lossy, a rollback tool only before the flag is armed, as `create_waves_table`). `down()` for M2 drops the column.
- **Rehearsal** is part of the build: both migrations run on a local throwaway database with synthetic waves (the PL-1.2 C1 rule: synthetic rows, nothing from production), timing recorded in the review file. Local and test databases only.

---

## 1. Data model

### 1.1 `wave_steps` (new, Waves module)

| Column          | Type                                 | Notes                                                                                                                         |
| --------------- | ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------- |
| `id`, `ulid`    | bigint, ulid unique                  | `HasUlid`; the wire id is the ulid.                                                                                           |
| `agency_id`     | FK `agencies`, restrict              | `BelongsToAgency` (tenancy scope).                                                                                            |
| `wave_id`       | FK `waves`, restrict                 | The wave must be in the same agency (service rule and test).                                                                  |
| `stream`        | string(16)                           | The stream (§1.2): `ugc_video`, `statics`, `stills`. The PHP enum `WaveStream` is the constraint.                             |
| `step_key`      | string(64), **nullable**             | The stable identity (§1.3). Null for a step a person added.                                                                   |
| `name`          | string(160)                          | Shown text. Free, editable, never used to find a step.                                                                        |
| `position`      | unsigned int                         | Order inside the wave and stream (§1.3).                                                                                      |
| `status`        | string(16), default `not_started`    | `not_started`, `in_progress`, `done` (enum `WaveStepStatus`; no migration to add a case).                                     |
| `owner_user_id` | FK `users`, nullable, `nullOnDelete` | An agency member (§1.7).                                                                                                      |
| `owner_client`  | boolean, default false               | The word "Client" as the owner (Q4). Never true together with `owner_user_id` (§1.7).                                         |
| `status_source` | string(8), default `system`          | `system` or `manual`: who last set `status` (F1, §1.8). Cheap and additive, so PL-2 needs no migration to tell the two apart. |
| `starts_on`     | date, nullable                       | Planned start.                                                                                                                |
| `due_on`        | date, nullable                       | Planned due date.                                                                                                             |
| `completed_on`  | date, nullable                       | Actual date. Set to the actor's today when the status becomes `done`, cleared when it leaves `done`.                          |
| `timestamps`    |                                      |                                                                                                                               |

Whether a step is a **strategy step** is not a column: it is decided by its key (`WaveStepKey::STRATEGY`, §1.3), so it cannot drift.

Indexes: **unique `(wave_id, step_key)`** (nulls never collide, so custom steps are free; it is the belt for provisioning, §3.1); **unique `(wave_id, stream, position)`** (two concurrent adds cannot take the same position); `(wave_id)`; `(agency_id)`. No soft delete: removing a step deletes the row and writes an audit row (P9 keeps wave deletion out).

### 1.2 Streams are an enum, not a table and not a free column

Frame 01b fixes three streams, each with a colour and an order: **UGC video** (`#3ecf9e`), **Statics** (`#8b6cff`), **Stills** (`#f472b6`). A table would add stream administration nobody asked for; a free-text column would let a typo create a fourth stream. So `stream` is a string column whose allowed values are the PHP enum `WaveStream` (the `WaveStatus` precedent: a new case needs no migration). Display name and colour come from the enum and from i18n.

**A stream is present when it has at least one step.** No empty stream is stored. Consequences:

- **Remove a whole stream (ruling, admin and manager, with a confirm).** `DELETE …/streams/{stream}` deletes every step of that stream in one transaction and writes one audit row. **The UGC video stream cannot be removed** (422 `stream.not_removable`), because the Brand page bar depends on its six strategy steps. Statics and Stills can. Removing the last step of Statics or Stills one by one has the same effect: the stream is gone.
- **Can a removed stream come back? Yes, by an explicit action, never by accident.** `POST …/streams/{stream}` ("Add stream", admin and manager) inserts that stream's default steps (§1.4) **only if the stream has no steps**; if it has steps it does nothing (200, nothing written). It uses the unique `(wave_id, step_key)` index as its belt. It **does not look at or change `steps_provisioned_at`**.
- **Why first open cannot re-create a removed stream.** The marker `steps_provisioned_at` is set once, when the 24 defaults are first written (§3.1). Provisioning is guarded only by that marker, so once it is set no later open can add steps back, whatever has been removed. The only way to restore a stream is the explicit Add stream action. A wave created before this build (marker null) is provisioned in full on first open, as before (T1).
- Restoring brings back the stream's **default steps only**; custom steps that were removed with the stream stay removed. The "Add step to a stream" dialog lists present streams; absent ones appear under an "Add stream" control.

### 1.3 Step identity, position, and how a later sprint finds "its" step (T4)

- **`step_key`** is a stable machine key of the form `<stream>.<step>`, for example `ugc.client_approval` or `statics.design_v1` (full list in §1.4). It is written when the default list is provisioned, is never shown, and **never changes when a person renames the step**. A later sprint that wants to tick the step "footage received" looks up `WaveStep` by `(wave_id, 'ugc.footage_received')`. If a person has deleted that step, the lookup finds nothing and the automation does nothing (it must not recreate it). A step a person added has a null key and is never touched automatically.
- **The six strategy steps are fixed (Q2).** `WaveStepKey::STRATEGY` is the ordered list `ugc.usps`, `ugc.personas`, `ugc.matrix`, `ugc.concepts`, `ugc.creators`, `ugc.scripts`. They **can be renamed but not removed, and not moved to another stream** (422 `step.strategy_locked`, on DELETE, and on a PATCH that changes `stream`). Every other step can be removed. They always exist once a wave is provisioned, which is what lets the Brand page bar (§1.6) rely on them.
- **`WaveStepKey`** (a small class of constants, beside `WaveStepDefaults`) is the only place keys are spelled, so a later sprint imports a constant, never a string literal.
- **`position`** orders steps inside one stream. Defaults take 10, 20, 30, … (gaps), so a later insert needs no renumber. A step added by hand is appended after the last position of its stream (the frame's "Add step to a stream" is a footer row). **Reordering is not in this chunk** (Q10 agreed); the order is `position`, then `id`.

### 1.4 The default step list (T1, Q2)

Lives in code as `WaveStepDefaults::steps()` (Waves module, a pure list, like `BoardDefaults::columns()`), pinned by a test that compares the list with this table. All 24 names are now ruled (Q2): no name is proposed any more. Frame 01b draws 16 of the steps; the other 8 are Pedram's ruling (the six strategy steps, "Footage received" and "Shoot prep"). Default names are **stored in English** (Q2).

| Stream    | Order | Step key                    | Step name                 | Strategy step | Default owner | Source                                                |
| --------- | ----- | --------------------------- | ------------------------- | ------------- | ------------- | ----------------------------------------------------- |
| UGC video | 1     | `ugc.kick_off_call`         | Kick-off call             |               |               | Frame                                                 |
| UGC video | 2     | `ugc.usps`                  | USPs                      | **1**         |               | Ruling Q2                                             |
| UGC video | 3     | `ugc.personas`              | Personas                  | **2**         |               | Ruling Q2                                             |
| UGC video | 4     | `ugc.matrix`                | Messaging matrix          | **3**         |               | Ruling Q2                                             |
| UGC video | 5     | `ugc.concepts`              | Concepts                  | **4**         |               | Ruling Q2                                             |
| UGC video | 6     | `ugc.creators`              | Creators                  | **5**         |               | Ruling Q2                                             |
| UGC video | 7     | `ugc.scripts`               | Scripts                   | **6**         |               | Ruling Q2                                             |
| UGC video | 8     | `ugc.footage_received`      | Footage received          |               |               | Ruling Q2                                             |
| UGC video | 9     | `ugc.editing`               | Editing                   |               |               | Frame (last of the 9 completed)                       |
| UGC video | 10    | `ugc.internal_review`       | Internal review           |               |               | Frame                                                 |
| UGC video | 11    | `ugc.editing_feedback_1`    | 1st editing feedback      |               |               | Frame                                                 |
| UGC video | 12    | `ugc.editing_feedback_2`    | 2nd editing feedback      |               |               | Frame                                                 |
| UGC video | 13    | `ugc.client_approval`       | Client approval           |               | **Client**    | Frame (owner "Client")                                |
| Statics   | 1     | `statics.brief_references`  | Static brief & references |               |               | Frame                                                 |
| Statics   | 2     | `statics.design_v1`         | Design v1                 |               |               | Frame (the "8 statics" is wave data, not in the name) |
| Statics   | 3     | `statics.internal_review`   | Internal review           |               |               | Frame                                                 |
| Statics   | 4     | `statics.client_feedback`   | Client feedback round     |               | **Client**    | Frame (owner "Client")                                |
| Statics   | 5     | `statics.final_export`      | Final export & resizes    |               |               | Frame                                                 |
| Stills    | 1     | `stills.shot_list`          | Shot list                 |               |               | Frame (first of the 3 completed)                      |
| Stills    | 2     | `stills.shoot_prep`         | Shoot prep                |               |               | Ruling Q2                                             |
| Stills    | 3     | `stills.shoot_day`          | Shoot day                 |               |               | Frame (last of the 3 completed)                       |
| Stills    | 4     | `stills.retouching_selects` | Retouching & selects      |               |               | Frame                                                 |
| Stills    | 5     | `stills.internal_review`    | Internal review           |               |               | Frame                                                 |
| Stills    | 6     | `stills.client_approval`    | Client approval           |               | **Client**    | Frame (owner "Client")                                |

That is 13 + 5 + 6 = **24 steps**, all `not_started`, `status_source = system`, no dates. Only the three steps the frame shows as client-owned default to owner "Client"; every other step has no owner. The frame's mock data (members, dates, "9 completed") is demo content, not a default. Positions are 10, 20, 30 and so on.

### 1.5 "Delayed": derived from the due date, not set by hand (Q3, ruled 2026-11-18)

**Ruled: derived only.** A step is **delayed** when it is not done and `due_on` is before today. No "delayed" status is stored. Reasons:

- A hand-set flag goes stale: move the due date and the flag still says delayed, and a later sprint that ticks the step must clear it too.
- Every figure frame 01b shows for delay is arithmetic on dates ("2 days", "Overdue since Oct 13", "Due today").
- It cannot disagree with the stream card, because the card is computed from the same dates.

Cost, stated: a person cannot mark a step "at risk" before its date passes, and the frame's "Flagged by Lena" has no equivalent (the fix card is left out, §9, Q7 agreed).

**"Today"** is the date in the **requesting user's time zone** (`users.timezone`, default `UTC`; the agency has no time zone column, inventory §9). Two users in different zones can see a step flip at different hours around midnight; accepted.

**Display status** (computed, sent by the API, never stored), in this order of precedence: `done`; `delayed` (open and `due_on` < today); `due_today` (open and `due_on` = today); `in_progress`; `up_next` (stored `not_started`). So an in-progress step due today shows "Due today". Colours: the five pill tones of the frame (green, red, amber, blue, grey), added as a `wave_step` kind in `agencyStatusTones.ts` (a frozen file: re-pinned in the same commit with the reason, as PL-1.1 and PL-1.2 did).

**Stream roll-up.** A stream is **Complete** if it has steps and all are done; **Delayed · N days** if any open step is delayed, with **N = the largest number of days overdue** among its open steps (frame: Design v1 due Oct 13, today Oct 15, so "2 days"); otherwise **On track**; with no steps it shows "No steps". **Wave health pill:** "At risk · N streams delayed" (N ≥ 1, "1 stream delayed" in the singular) or "On track".

### 1.6 Progress: the stream card figure and the Brand page bar (Q1, ruled 2026-11-18)

**Stream card figure.** One segment per step of that stream, left to right: the first `done` segments are filled (green; red on a delayed stream), the rest are empty. The text is "`done` of `total` steps". This is exactly frame 01b (13, 5 and 6 segments). Adding or removing a step changes the number of segments and nothing else.

**Brand page and wave page bar: the six strategy steps, not a ratio.** The six segments are fed by the six strategy steps of the UGC video stream, in order (USPs, Personas, Messaging matrix, Concepts, Creators, Scripts), as the developer brief's "6-segment progress" says. **The earlier `min(5, ceil(6 × done ÷ total))` rule is dropped.**

- **Segment n** is **filled** when the nth strategy step is `done`.
- **A step in progress** shows its segment in the blue of the "In progress" pill (the frame's tone), so a started step is visible without being counted as done. A step that is not started is empty. A delayed strategy step is not drawn differently on the bar (delay shows on the stream card).
- **The label** is the **name of the first strategy step that is not done** (in the strategy order, using the step's current name, so a renamed step shows its new name); **"Complete"** when all six are done; **"Not started"** when none of the six has started (none is `in_progress` or `done`, which includes a wave that has no steps yet).
- Steps are read in strategy order, not completion order: if Concepts is done and Personas is not, segment 4 is filled and the label is "Personas".
- The other 18 steps never affect the bar. Because the six steps cannot be removed (§1.3), the rule cannot be broken by removing or adding steps; adding a custom step changes nothing on the bar. A test adds and removes other steps and asserts the bar is unchanged.

Both lists and the wave page use one server-side `progress` object (`segments`: six entries each `done | in_progress | empty`, `label_kind` `not_started | step | complete`, `step_name`); the web draws it and holds no copy of the rule. An unprovisioned wave reads as six empty segments and "Not started" (the list never writes).

**What "in progress" looks like in the label.** The label names the first not-done step whether it is started or not: "Personas" while Personas is in progress, and also while it is merely next. The segment colour (blue or empty) tells them apart.

### 1.7 The owner reference (T2, Q4)

A step's owner is **an agency team member, or the word "Client"**, or nobody.

- **Member:** `owner_user_id` points at a `users` row and must be an **active member of the same agency**: an `agency_users` row with the wave's `agency_id`, `accepted_at` not null and `deleted_at` null (the AH-119 check, `owner.not_a_member`; here 422 `step.owner_not_a_member`). The wire carries the user's **ulid**.
- **"Client":** `owner_client = true`. It is **a word on the step: no account, no access, no notification, no row anywhere else.** PL-4 may replace the label with a real person. The picker lists the members plus a "Client" item; the three steps the frame shows as client-owned default to it (§1.4).
- **Exclusive:** a step has at most one of the two. Setting a member clears `owner_client`; setting Client clears `owner_user_id`; clearing sets both empty. **"Client" is the only non-member owner accepted**: any other string, a ulid of another agency's member, a pending or removed member, or a nonexistent ulid is refused (422 `step.owner_not_a_member`).
- **Wire:** `owner` (a member ulid or null) and `owner_client` (boolean) in the PATCH and POST; the response carries `owner` (`{id, name}` or null), `owner_client` and `owner_is_former`.
- **A member removed later.** Memberships are soft deleted and the pointer is kept, so at read time an owner who is no longer an active member is sent as `owner: null` with `owner_is_former: true`, and the web shows "Former member" (muted); the step counts as unassigned for the "Mine" filter. Nothing is written when a member leaves. **Not read:** the code that removes a member, if any exists outside the routes I read (inventory §4); the rule does not depend on it.
- "Mine" matches only steps whose owner is the signed-in member.

### 1.8 What the step row stores so PL-2 can tell "set by hand" from "set by the system" (F1)

`status_source` (§1.1): `system` or `manual`, with `status`. In this chunk: provisioned defaults are `system` (nothing has been set); **any status change through the API sets `manual`**; nothing here writes `system` after provisioning. PL-2 writes `system` when a real strategy screen sets a strategy row. The warning PL-2 needs ("marked done while concepts are not all approved") compares the stored `status` with what the real screen says **live**, so nothing more has to be stored: a row with `status_source = manual` whose status goes beyond the screen is the "set by hand" row. Who and when are in the audit rows. This costs one small column on a table that does not exist yet, so it adds no risk. The data model does not otherwise block F1.

**F2 and F3 are PL-2's to rule** (recorded in `production-line-sprints.md` under PL-2): how several markets roll up into one tracker row (steps 2 to 6 of the workspace are per market), and how a wave that opens on its first incomplete strategy step shares the wave page with the tracker. The data model carries one row per strategy step per wave, so a per-market roll-up is a PL-2 computation feeding that row; it does not need a column here.

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
3. Otherwise insert the 24 default steps (the three client steps with `owner_client = true`, every row `status_source = system`) and set `steps_provisioned_at` to now, in the same transaction; write one audit row (`WaveStepsProvisioned`).
4. Belt: the unique `(wave_id, step_key)` index. If two requests both pass step 2 (SQLite ignores row locks; PostgreSQL does not), the loser catches `UniqueConstraintViolationException`, re-reads and returns.

A wave whose flag is off is never provisioned: no route is reachable. **The marker never records which streams exist**: a removed stream (§1.2) is not re-created by any later open; only the explicit Add stream action restores it.

### 3.2 Endpoints

| Route                                               | Purpose                                                                                                                                                                                               |
| --------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET …/waves/{wave}/tracker`                        | Provisions if needed (§3.1), then returns `streams` (key, colour token, status, days delayed, done, total, next step, final delivery, distinct owners), `steps` (all, ordered), `health`, `progress`. |
| `POST …/waves/{wave}/steps`                         | Add a step: `stream`, `name`, optional `owner` (ulid), `starts_on`, `due_on`. Appended after the last position of the stream. Admin and manager.                                                      |
| `PATCH …/waves/{wave}/steps/{step}`                 | Change `status`, `owner`, `starts_on`, `due_on` (any member role). `name` and `stream`: admin and manager only (§3.3).                                                                                |
| `DELETE …/waves/{wave}/steps/{step}`                | Remove a step. Admin and manager. A strategy step is refused: 422 `step.strategy_locked`.                                                                                                             |
| `DELETE …/waves/{wave}/streams/{stream}`            | Remove a whole stream (all its steps, one transaction, one audit row). Admin and manager. `ugc_video` is refused: 422 `stream.not_removable`. A stream with no steps: 404.                            |
| `POST …/waves/{wave}/streams/{stream}`              | Add a removed stream back with its default steps (§1.2). Admin and manager. A stream that already has steps: 200, nothing written. Does not touch `steps_provisioned_at`.                             |
| existing `GET/…brands/{brand}/waves` and `…/{wave}` | Add the `progress` object (§1.6) to each wave. The list computes it with grouped queries over the page's wave ids, no N+1, and **never writes**: an unprovisioned wave reads as "Not started".        |

Step shape (JSON:API-like, as the rest): `id` (ulid), `type: 'wave_steps'`, attributes `stream`, `key` (nullable), `is_strategy`, `name`, `position`, `status`, `status_source`, `display_status`, `days_overdue`, `starts_on`, `due_on`, `completed_on`, `owner` (`{id, name}` or null), `owner_client`, `owner_is_former`.

Validation: `name` 1 to 160; dates `Y-m-d`; `due_on` not before `starts_on` (422 `step.dates_invalid`) when both are set; `stream` must be a `WaveStream`; `status` a `WaveStepStatus`; a wrong `owner` is 422 `step.owner_not_a_member`; a PATCH that changes `stream` on a strategy step is 422 `step.strategy_locked`; `owner` and `owner_client: true` together are 422 `step.owner_conflict`.

### 3.3 Roles per action (T3, Q8), each a gate

A new `WaveStepPolicy` (reads the role of the **current request's agency**, like `WavePolicy`):

| Action                                                            | agency_admin | agency_manager | agency_staff | Gate id |
| ----------------------------------------------------------------- | ------------ | -------------- | ------------ | ------- |
| View the tracker                                                  | yes          | yes            | yes          | R1      |
| Add a step                                                        | yes          | yes            | **no**       | R2      |
| Remove a step (not a strategy step)                               | yes          | yes            | **no**       | R3      |
| Change status, owner (member or Client), due or start date        | yes          | yes            | yes          | R4      |
| Rename a step, or move it to another stream (not a strategy step) | yes          | yes            | **no**       | R5      |
| Remove a whole stream (not UGC video), Add stream                 | yes          | yes            | **no**       | R6      |

A PATCH that mixes allowed and forbidden fields from a staff user is refused as a whole (403), never half-applied. Renaming a strategy step is allowed to admin and manager; moving or removing one is refused to everyone.

### 3.4 Concurrency: two people on one step, or a removal while someone edits

- **Two people edit the same step.** The PATCH sends only the fields that changed and updates only those, inside a transaction that takes `lockForUpdate()` on the step row and re-reads it. Two people changing **different** fields (one the owner, one the date) both keep their change. Two people changing the **same** field: the later write wins, and the earlier person sees the new value when the page reloads the row (every save returns the full step and the tracker is reloaded on focus). No version check (`If-Match` and a 409) is added (Q11 agreed); it would need a "your copy is stale" screen that has no design, and the cost of a lost overwrite here is one field re-entered.
- **One person removes a step while another edits it.** The PATCH finds no row after the lock and answers **404 `not_found`**; the web shows "This step was removed" and reloads the list. A DELETE of a missing step is also 404, not an error page.
- **A stream removed while someone edits one of its steps:** the PATCH finds no row and answers 404 (the same handling). Remove stream and Add stream take the wave row lock (`lockForUpdate()`), so two of them cannot interleave.
- **Two adds at once.** The wave row is locked (`lockForUpdate()`) while the next position is read and the row inserted, and the unique `(wave_id, stream, position)` is the belt; the loser retries once.
- SQLite ignores row locks (as in PL-1.2): the lock tests assert the `for update` SQL and the unique-index tests run on PostgreSQL as well (§6). I do not claim a real two-client race was run.

### 3.5 Audit

New actions: `WaveStepsProvisioned`, `WaveStepAdded`, `WaveStepUpdated`, `WaveStepRemoved`, `WaveStreamRemoved`, `WaveStreamAdded`. Payload keys are **literals** (for `AuditPayloadKeysGuardTest`): `stream`, `step_key`, `name`, `status`, `from_user` and `to_user` (a user id on each side of an owner change, the AH-119 A3 precedent; see §13 C1), `owner_client`, `status_source`, `starts_on`, `due_on`, `completed_on`; each key gets an Erasure decision in `AuditPayloadKeys::notPersonalKeys()` in the same commit (PL-1.2 found five undecided keys that way). One audit row per request, with `before` and `after` for an update.

### 3.6 Erasure and tenancy

See §2 and the inventory §6. The Erasure folder runs locally before the push (§6). Every query on `wave_steps` carries the agency scope; the step must belong to the wave in the route, and the wave to the agency (a mismatch is 404).

---

## 4. Web

### 4.1 What is built

The wave page (`WaveDetailPage.vue`) keeps its header, edit dialog and **details card** (it holds the campaign link and its lock, which frame 01b does not draw; Q9 agreed). Below the card, in this order:

1. **Health pill** next to the title ("At risk · 1 stream delayed" or "On track").
2. **Stream cards**, three across at desktop width (§4.3): swatch and name, tracking pill, progress segments and "9 of 13 steps", NEXT STEP, FINAL DELIVERY, and the distinct owners as avatars with first names. A delayed card has the red border and red segments.
3. **Steps panel** "Deliverable steps": filter chips **All, UGC, Statics, Stills, Mine**; columns **STEP, OWNER, DUE, STATUS**; one group per stream (swatch, name, "9 of 13 done", pill); completed steps collapsed into "9 completed steps · Kick-off call → Editing (Sep 25 – Oct 12) Show" with the dates taken from `completed_on`; open step rows with the round check (click to mark done and back), owner, due, status pill and a row menu; footer "+ Add step to a stream".
4. **Brand page and wave page Progress** use the server `progress` object (six segments each done, in progress or empty, and the label); `WaveProgressSegments.vue` takes it as a prop (flag-off path unchanged: it is only rendered while `features.waves` is on).
5. **Stream controls:** each stream's group header has a menu with **Remove stream** (admin and manager, with a confirm that names the stream and the number of steps; absent for UGC video); an **Add stream** control below the table lists the absent streams (admin and manager). Strategy steps show no Remove and no stream change in their menu.

Due format as the frame: "Today", "Tomorrow", "Mon, Oct 19", "Oct 22", a range "Oct 16 → 19" when both dates exist; a year is added when the date is not in the current year (a decision beyond the frame, which shows none).

### 4.2 Screens with no design (T6), built from the design system

Each is named, with the parts it uses:

| Screen or state                                    | Built from                                                                                                                                         |
| -------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Edit step** (name, stream, owner, dates, status) | `v-dialog` + `v-card` + `v-text-field`, `type="date"` fields (as the campaign form), `v-select`                                                    |
| **Owner picker**                                   | `v-select` over `GET /members` (active members only) plus a **"Client"** item and an "Unassigned" item. No ready-made picker exists (inventory §5) |
| **Add step**                                       | The same dialog in create mode (stream preselected from the footer row)                                                                            |
| **Remove step**                                    | `v-dialog` confirmation (name and a "Remove" button)                                                                                               |
| **Remove stream** and **Add stream**               | `v-dialog` confirmation (stream name and step count); a `v-menu` list of absent streams                                                            |
| **Row menu**                                       | `v-menu` + `v-list`: Edit, Remove (Remove hidden for staff)                                                                                        |
| **Empty** (a stream with no steps)                 | Stream card "No steps", and an empty group line with the add-step link                                                                             |
| **Loading**                                        | `v-skeleton-loader` (as the wave page)                                                                                                             |
| **Error**                                          | `v-alert type="error"` with a Retry button (as the wave page)                                                                                      |
| **Step removed by someone else**                   | `v-snackbar` "This step was removed" and a reload                                                                                                  |
| **Narrow widths** (no design at all)               | §4.3                                                                                                                                               |

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

| Id    | What it proves                                                                                                                                                                                                                                                                                                                                                          | Break-revert (what to break)                                |
| ----- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| F1    | **Flag:** every tracker and step route (and the new `progress` on the list) answers 404 with the flag off, with no row written                                                                                                                                                                                                                                          | remove the flag check on one route                          |
| TN1   | **Tenancy:** another agency's wave and step are 404 on every route; a step cannot be reached through a wave of its own agency but another brand/wave id                                                                                                                                                                                                                 | drop the agency scope on the wave or step lookup            |
| R1–R6 | **Roles**, one test per row of §3.3 for each of the three roles (staff 403 on add, remove, rename; allowed on status, owner and dates; staff PATCH mixing fields is refused whole)                                                                                                                                                                                      | let staff add or remove; forbid staff status change         |
| O1    | **A member owner must be a member of the same agency:** a member of another agency, a user with a pending or removed membership, and a nonexistent ulid are 422 `step.owner_not_a_member`; null clears                                                                                                                                                                  | skip the membership check                                   |
| O2    | A removed member shows as `owner: null`, `owner_is_former: true`, and does not match "Mine" (the web) / the API field                                                                                                                                                                                                                                                   | return the removed member as the owner                      |
| SS1   | **A strategy step cannot be removed or moved:** DELETE and a stream-changing PATCH on each of the six keys are 422 `step.strategy_locked` for admin, manager and staff; a rename by admin and manager works; every other step can be removed                                                                                                                            | allow the delete of one strategy key                        |
| ST1   | **The UGC stream cannot be removed:** `DELETE …/streams/ugc_video` is 422 `stream.not_removable`; Statics and Stills can be removed in one action with one audit row                                                                                                                                                                                                    | drop the UGC check                                          |
| ST2   | **A removed stream stays removed on later opens and returns only by Add stream:** remove Statics, reopen twice (nothing reappears, marker unchanged); Add stream restores its 5 default steps once; a second Add stream writes nothing                                                                                                                                  | make provisioning ignore the marker                         |
| OC1   | **"Client" is the only non-member owner accepted:** `owner_client: true` works and clears the member; a member clears Client; any other non-member value is 422; the three default client steps are Client                                                                                                                                                              | accept any string as an owner                               |
| BAR1  | **The Brand page bar rule:** segment n is done when the nth strategy step is done (also when out of order); an in-progress step shows `in_progress`; the label is the first not-done strategy step, "Complete" when six are done, "Not started" when none has started or there are no steps; adding or removing other steps changes nothing; a rename changes the label | fill by a ratio of all steps                                |
| SRC1  | **`status_source`:** a status change by the API sets `manual`; provisioning sets `system`                                                                                                                                                                                                                                                                               | forget to set `manual`                                      |
| P1    | **Provisioning runs once:** a second call writes nothing; a wave whose steps were all deleted stays empty; existing waves (rows created without a marker) get the 24 steps on first open; the list is exactly §1.4                                                                                                                                                      | ignore `steps_provisioned_at`                               |
| P2    | Provisioning at wave creation is in the creation transaction (a failure rolls back the wave and the steps)                                                                                                                                                                                                                                                              | provision after the transaction                             |
| P3    | **PostgreSQL:** the provisioning `for update` SQL is asserted, and the unique `(wave_id, step_key)` index refuses a duplicate; the loser path returns without error                                                                                                                                                                                                     | drop the unique index in the test schema / remove the catch |
| PR1   | **Stream card figure:** one segment per step, `done` filled, adding or removing a step changes the count only                                                                                                                                                                                                                                                           | draw a fixed six segments                                   |
| D1    | **Delayed is derived:** a done step is never delayed; an open step due yesterday is delayed with the right `days_overdue` in the user's time zone; due today is `due_today`; a stream's "N days" is the maximum                                                                                                                                                         | count a done step as delayed                                |
| S1    | **Concurrency:** PATCH after the step was deleted is 404; two PATCHes of different fields both persist; two adds take different positions (PostgreSQL runs the unique `(wave_id, stream, position)`)                                                                                                                                                                    | drop the lock / the unique index                            |
| A1    | **Audit:** each action writes one row with the literal keys; `AuditPayloadKeysGuardTest` stays green                                                                                                                                                                                                                                                                    | drop an Erasure decision for a new key                      |
| E1    | **Erasure folder** green with the new table, column and audit keys (run locally; a table-level decision is a real gate, a column one was not in PL-1.2)                                                                                                                                                                                                                 | remove the `wave_steps` registry entry                      |
| K1    | The default list is pinned: 24 rows, the keys, names and default owners of §1.4, the six strategy keys in order, positions 10, 20, …; keys are unique                                                                                                                                                                                                                   | rename one key                                              |
| M1    | Both migrations run up and down on SQLite and PostgreSQL; M2 leaves existing rows with a null marker                                                                                                                                                                                                                                                                    | (schema test)                                               |

**PostgreSQL rule (carried from PL-1.1 run 449 and PL-1.2):** every test that depends on a unique violation, a lock or a transaction (P2, P3, S1, M1) also runs on PostgreSQL through `phpunit.pgsql.xml` with the pgsql env set, then unset. The Audit, Erasure and Tenancy folders run locally on SQLite **and** PostgreSQL before every push (WORKING-PROCESS §4.1 item 8; the audit catalogue was the third registration miss). Folders run **one at a time** with `memory_limit` raised (one process for all folders ran out of the 128 MB default in PL-1.2). Local gates: Waves, Boards, Campaigns, Brands, Erasure, TestHelpers on SQLite and PostgreSQL, Pint, Larastan, `vue-tsc`, ESLint, the touched Vitest set, and the flag-off walk (12 and 16).

### Web gates (Vitest and Playwright)

| Id  | What it proves                                                                                                                                                                                                                                                                    | Break-revert                               |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------ |
| W1  | Stream card renders each state (on track, delayed with days, complete, no steps) and one segment per step                                                                                                                                                                         | draw a fixed six segments                  |
| W2  | Steps table: grouping, collapsed completed line with the first and last name and date range, filters All/UGC/Statics/Stills/Mine                                                                                                                                                  | break the Mine filter                      |
| W3  | Display status precedence and the five pill tones                                                                                                                                                                                                                                 | swap delayed and due today                 |
| W4  | Role gates in the UI: staff see no Add, Remove, rename, Remove stream or Add stream; admin and manager do                                                                                                                                                                         | show Remove to staff                       |
| W5  | Optimistic done toggle reverts on a failed save; "This step was removed" on a 404                                                                                                                                                                                                 | skip the revert                            |
| W6  | Brand page bar renders the server object (six segments done, in progress, empty; the label states) and the flag-off render is unchanged                                                                                                                                           | ignore `label_kind`                        |
| W8  | Strategy steps show no Remove or stream change; the UGC group has no Remove stream; the Client item is in the owner picker; Remove stream asks for a confirm                                                                                                                      | show Remove on a strategy step             |
| W7  | Arch: `agency-frozen` re-pin (if touched), status tones, i18n key coverage in 24 locales (`waveKeys.spec.ts`)                                                                                                                                                                     | add a key to one locale only               |
| E2E | **`wave-tracker.spec.ts` through `playwright.config.ts`** at 1440 and 390 widths: opens a wave, stream cards visible, no horizontal page overflow, the owner menu and the edit dialog open **above** each other and a quick close and reopen leaves no stale menu (the D1 lesson) | remove the per-open key on the dialog card |

**`WORKING-PROCESS.md` §4.2 applies:** the tracker is opened in a real browser, at a desktop width and a narrow one, including a menu left open across a fast dialog reopen, **before** a walk is offered to Pedram. Stacking, width and overlay defects are invisible to unit tests.

---

## 7. Strings

All new strings in the **24 locales** (the verifier `scripts/i18n/verify-locale.mjs` and `waveKeys.spec.ts`), under `app.waves.tracker`. About 70 keys: title and subtitle; health pill (on track, at risk with a count, singular and plural); stream names (UGC video, Statics, Stills); stream status (on track, delayed with days, complete, no steps); "{done} of {total} steps"; NEXT STEP, FINAL DELIVERY, "No date"; filter chips; column headers; display statuses (up next, in progress, due today, delayed, done); group line ("{done} of {total} done"); collapsed line and Show/Hide; due words (Today, Tomorrow); owner labels (Unassigned, Former member); add, edit and remove dialogs (titles, fields, buttons, errors for the 422 codes and the 403 and 404 cases); empty, loading, error, retry; "This step was removed"; row menu items; the Brand page progress labels (the existing "Not started" is kept, "Complete" is added); owner labels "Client"; Remove stream and Add stream (menu, confirm, errors for `stream.not_removable` and `step.strategy_locked`). About 80 keys in all. Default step names are **data** (§1.4), not locale keys. Names of people are never translated.

---

## 9. Left out of this chunk

- **Calendar or timeline view** (T5): frame 01b has none; the day-grouped "Due this week" list is left out pending a ruling (Q6).
- **Notifications, reminders, e-mail or push to owners** (T5), any automatic ticking of steps (T4: a later sprint), real-time or live updates.
- **Right column:** Needs attention, Due this week, Team on this wave and capacity. Roles ("Strategist", "Editor") and capacity ("3 waves this week") are not data the system has.
- **Header buttons "Share status update" and "Add task"** (adding a step is the table footer and the dialog), **wave tabs** other than the tracker (Strategy & Scripts, Editing, Statics, Stills, Files), the **tooltip** on the stream pill.
- **"What needs fixing" card** (Blocked, Impact, To recover; the buttons Chase client, Use current screens, Move due date, Reassign) and the **projected delivery** ("Oct 22 → Oct 24"). Move due date and Reassign are the normal edit. Question Q7.
- **"Open in" column** and the per-stream footer link (Strategy & Scripts, Figma file, Shoot folder): their targets do not exist (footage review is PL-6, Figma and the shoot folder are not in the system).
- **The stream card's second line** ("12 videos · 4 creators · SK, RO"): it needs creator and deliverable counts that the campaign or PL-3 supply. Markets could be shown from the wave; left out so the card matches one rule.
- **Client accounts as owners** (T2, PL-4): "Client" is a word on the step only (Q4); no account, access or notification.
- **Q5, Q6, Q7 agreed (2026-11-18):** "Open in", the stream footer links, the right column, the header buttons, the other tabs, "What needs fixing" and "Due this week" are out.
- **Automatic setting of the six strategy rows, the warning for a hand-set status that goes beyond the real screen, per-market roll-up, and the wave opening on the first incomplete strategy step** (F1 to F3): PL-2.
- **Step reordering**, step dependencies, templates other than the one default list, deleting a wave (P9), per-step comments, attachments.
- **PL-1.1's Draft-only wave status** is unchanged (statuses beyond Draft need PL-2).

---

## 10. Walk script for Pedram (local)

**Setup (named seed data).** A new seeder pair, **`Pl13WalkSeeder`** and **`Pl13WalkCleanupSeeder`** (local and testing only, idempotent, additive, never touches the flag; Pedram turns the flag on and off by hand, as in PL-1.2; password printed by the seeder, nothing secret in the repo):

- **Agency A "PL-1.3 Walk Agency A":** members `admin@`, `manager@`, `staff@`, `designer@pl13-walk.local` (an extra staff member to pick as owner), and `former@pl13-walk.local` (an owner on a seeded step whose membership is removed). Brand **Bolt Food**.
- **Waves (Bolt Food):**
  - **Wave 6**: existing, **no steps and no marker** (the first-open proof).
  - **Wave 7**: created in the walk.
  - **Wave 8**: provisioned; USPs done, Personas in progress, one step with a past due date, one due today, one owned by `former@`, one owned by "Client" (the bar, delayed and former-member proofs).
  - **Wave 5**: provisioned, all six strategy steps done ("Complete" on the Brand page).
  - **Wave 4**: provisioned, Statics already removed (the removed-stream proof).
- **Agency B "PL-1.3 Walk Agency B":** `admin@pl13-walk-b.local`, brand **Glovo**, wave **Glovo Wave 1** (with steps).

**Steps:**

1. **Flag OFF, unchanged.** As `admin@`, open Bolt Food, a campaign and the campaigns list: identical to before. Open `/brands/<id>/waves/<wave>`: you are sent back to the brand page; the Waves panel is absent.
2. **Flag ON, the Brand page bar.** Open Bolt Food. Wave 5: six filled segments and "Complete". Wave 8: segment 1 filled, segment 2 blue (in progress), label "Personas". Wave 6: six empty segments and "Not started".
3. **Open Wave 6 (first open).** Three stream cards and 24 steps; the three client steps (Client approval in UGC, Client feedback round, Client approval in Stills) are owned by "Client". Reload: still 24 (no duplicates).
4. **Open Wave 8.** The delayed step has the red pill and date, its stream says "Delayed · N days", the health pill says "At risk · 1 stream delayed". The step owned by `former@` shows "Former member".
5. **Owner:** set an owner on a step to `designer@`; set another to "Client"; set a third back to nobody. The stream card's owners update.
6. **Mark a strategy step done** (click its check, for example Personas). Go back to the Brand page: segment 2 is filled and the label moves to "Messaging matrix". Mark Concepts done before Messaging matrix: segment 4 fills, the label stays "Messaging matrix".
7. **Strategy steps are fixed:** open the row menu of "Scripts": there is no Remove and no stream change; Rename works. Rename "USPs" to "Value props": the Brand page label follows the new name.
8. **Delayed is derived:** set a step's due date to yesterday (red, stream "Delayed · 1 day", card border red, health pill "At risk"), today ("Due today", amber), tomorrow (clears).
9. **Add a step** to Statics ("Resize for Stories", owner and due date), then **remove** it. Remove a default step; reload: it stays removed.
10. **Remove a stream:** on UGC video there is no Remove stream. On Statics choose Remove stream: a confirm names the stream and its step count; confirm; the Statics card and group disappear. Reload and open the wave again: Statics does not come back. Choose **Add stream**, Statics: its 5 default steps return once; choose it again (or reload): nothing duplicates.
11. **Filters:** All, UGC, Statics, Stills, Mine.
12. **Staff:** sign in as `staff@`. Open a wave: no Add step, no Remove, no rename, no Remove stream, no Add stream; you can change status, owner (a member or "Client") and dates.
13. **Manager:** adds, removes and removes a stream like admin.
14. **Two tabs:** in tab 1 remove a step; in tab 2 (opened before) edit that step: "This step was removed".
15. **Second agency:** as `admin@pl13-walk-b.local` open Glovo Wave 1 (works); paste Wave 8's address: not found; each Brand page shows only its own waves.
16. **Narrow width** (a 390 px window): cards stack, the table becomes stacked rows, nothing runs off the page; the owner menu and the dialogs open above each other.
17. **Cleanup:** `Pl13WalkCleanupSeeder`; the flag state is reported, not changed.

---

## 11. Rulings on the plan (Pedram, 2026-11-18)

Q1 to Q12 were put to Pedram with a recommendation each. His rulings, and how the plan changed:

| #              | Ruling (2026-11-18)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                   | Where it lands         |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------- |
| **Q1**         | The Brand page's six Progress segments are the six strategy steps ("6-segment progress", developer brief). They are fed by six tracker steps, **not** by a ratio of all steps. The `min(5, ceil(…))` rule is dropped.                                                                                                                                                                                                                                                                                                                                                                 | §1.6                   |
| **Q2**         | UGC video has 13 steps; steps 2 to 7 are the six strategy steps under their own names with keys `ugc.usps`, `ugc.personas`, `ugc.matrix`, `ugc.concepts`, `ugc.creators`, `ugc.scripts`. Stills step 2 is "Shoot prep". Statics as in the frame. Default names stored in English. Bar: segment n filled when the nth strategy step is done; label is the first strategy step not done, "Complete" when all six are done, "Not started" when none has started. The six strategy steps can be renamed but not removed and not moved to another stream; every other step can be removed. | §1.3, §1.4, §1.6, §3.3 |
| **Q3**         | Delayed is derived from the due date only.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            | §1.5                   |
| **Q4**         | Owner is an agency team member **or the label "Client"**. "Client" is a word on the step: no account, no access, no notification. The picker lists the members plus "Client". Steps the frame shows as client-owned default to "Client". PL-4 may replace the label with a real person.                                                                                                                                                                                                                                                                                               | §1.1, §1.4, §1.7       |
| **Q5, Q6, Q7** | Left out: "Open in", stream footer links, the right column, header buttons, other tabs, "What needs fixing", "Due this week".                                                                                                                                                                                                                                                                                                                                                                                                                                                         | §9                     |
| **Q8**         | Rename a step or move it to another stream: admin and manager.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | §3.3                   |
| **Q9**         | The details card stays above the tracker.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             | §4.1                   |
| **Q10**        | No reordering in this chunk.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          | §1.3, §9               |
| **Q11**        | Last write wins per field; 404 handling for a removed step.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           | §3.4                   |
| **Q12**        | "Today" is the requesting user's day.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | §1.5                   |
| **NEW**        | Remove a whole stream in one action (admin and manager), with a confirm. The UGC video stream cannot be removed because the Brand page bar depends on it. A removed stream can be brought back by an explicit Add stream action that never relies on, or changes, the `steps_provisioned_at` marker.                                                                                                                                                                                                                                                                                  | §1.2, §3.2, §3.3, §4.1 |

**Rulings for PL-2 (not built here; the data model does not block them).**

- **F1.** From PL-2 the six strategy rows are set automatically by the real strategy screens. The strategist can still set one by hand. When the hand-set status goes beyond what the real screen says (for example done while concepts are not all approved), the system warns the step is incomplete, lets the user confirm, and shows the row as set by hand, so a wave is never stuck on one step. **This chunk adds `status_source` (`system` or `manual`) to the step row** (§1.8); the warning compares `status` with the screen live.
- **F2.** Steps 2 to 6 of the strategy workspace are per market. PL-2 must rule how several markets roll up into one tracker row.
- **F3.** A wave opens on the first incomplete strategy step (developer brief). PL-2 must rule how the tracker and the six-step workspace share the wave page.

**Source note.** The developer brief ("Strategy & Script Writing Developer Brief v2", relayed by Pedram) describes the six-step workspace and the 6-segment progress. It does not mention the project tracker, streams, Statics or Stills; those come from Figma frame 01b only. Not read by Cursor.

**Questions still open: none.** Remaining unknowns are build details (§Read and not read).

---

## 12. Size estimate and build order

**Size: M to L** (a new table, a derived-status and progress engine, a new interactive web surface with no narrow design, a 24-locale string set, and a PostgreSQL gate set). Estimates, not commitments:

- Backend: about 17 new files (two migrations, `WaveStream`, `WaveStepStatus`, `WaveStepDefaults`, `WaveStepKey`, `WaveStep` model and factory, `WaveStepProvisioner`, `WaveProgress` and `WaveTrackerView` calculators, `WaveStepController`, `WaveStreamController` (remove and add stream), `WaveTrackerController`, `WaveStepPolicy`, requests, resources) and about 8 edited (`WaveService`, `WaveController`, `WaveResource`, routes, `AuditAction`, `AuditPayloadKeys`, `ErasureRegistry`, `WavesServiceProvider`).
- Web: about 10 new files (stream card, steps panel, step row, step dialog, owner select, remove dialog, tracker api, tracker store or composable, date and progress helpers) and 6 edited; 24 locale files; api-client types.
- Tests: about 12 new backend test files and 8 web specs; one Playwright spec; the walk seeder pair (2 files).
- Rough effort: 7 sub-steps; about 5 working days of build plus one for translations, the PostgreSQL runs, the real-browser pass and the walk.

**Build order** (each sub-step independently green on SQLite and, where it touches a lock or a unique index, on PostgreSQL):

1. **Schema and defaults.** M1, M2, enums, `WaveStepDefaults`, `WaveStepKey`, model, registry and audit key decisions. Tests K1, M1, E1.
2. **Provisioning.** `WaveStepProvisioner`, wave creation and first open. Tests P1 to P3. **PostgreSQL run.**
3. **Derived values.** Display status, stream roll-up, health, the stream figure and the six-step bar rule, `progress` on the wave list and show. Tests D1, PR1, BAR1.
4. **Step and stream endpoints.** Add, update, remove, owner rule (member or Client), strategy-step lock, remove and add stream, roles, concurrency, audit, `status_source`. Tests F1, TN1, R1 to R6, O1, O2, OC1, SS1, ST1, ST2, SRC1, S1, A1. **PostgreSQL run.**
5. **Web: the tracker.** Stream cards, steps panel, filters, dialogs, owner picker with Client, Remove stream and Add stream, optimistic toggle, strings in 24 locales, status-tone kind and re-pin. Tests W1 to W5, W7, W8.
6. **Web: progress and brand page.** Server `progress` in `WaveProgressSegments`, Brand page and wave page. Test W6.
7. **Close-out.** Walk seeder pair, **the tracker opened in a real browser at 1440 and 390** (§4.2 lesson), `wave-tracker.spec.ts` through the config, full local gate set (including Erasure and PostgreSQL), the flag-off agency walk (12 and 16), the review file with a Production posture section and the migration rehearsal timing.

Commits: feature commit and docs commit as a pair (`WORKING-PROCESS.md` §2). **No push until Pedram has walked.**

---

## Read and not read

**Read:** Figma frame 01b (tree, header, stream cards, the whole steps panel through `get_design_context`), frame 01 (tree and table header); the Waves module (routes, model, policy, controller `index`, `store`, `campaignOptions`, flag class, create migration, audit calls); the Agencies members controller and the AH-119 owner controller; `CurrentAgencyRole`; the Erasure registry entries for waves, agency tables and `owner_user_id`; `BoardService::ensureBoard`; `WaveDetailPage.vue`, `WaveProgressSegments.vue`; the PL-1.1 notes on frame 01; `production-line-sprints.md` §6; the Playwright config.

**Not read:** the developer brief itself (only Pedram's relay of it); frame 01b's right column, tooltip and wave tabs through `get_design_context`; every undrawn state; any narrow design (none exists in what I read); `WaveController::show` and `update` bodies, `WaveResource`, `WaveService::update`, `BrandWavesPanel.vue` beyond the progress cell, `WaveDetailPage.spec.ts`; the code that soft-deletes a membership, if any; any agency-level time zone; `RegistryVerifier` internals (only that it checks tables and user references); the production database and deploy runbooks (nothing was run against any deployed environment). **Not verified:** that two concurrent first-open requests behave as §3.1 describes on PostgreSQL (the build proves it with the lock SQL and the unique index, not with a real two-client race).

---

## 13. Corrections at build (Pedram, 2026-11-18, recorded before any code)

**C1. The audit key for a user reference.** The plan's `owner_id` is dropped; it is not classified "not personal" by default. What the code does today (read, not assumed):

- **How audit payloads carry a user.** AH-119 A3 (`AgencyCreatorOwnerController`, creator owner reassign) writes `metadata: ['from_user' => $previous, 'to_user' => $newOwnerId]`: **user ids on each side, never a name or an e-mail**, and the `AuditAction` comment says "ids only". Other call sites use `user_id`, `approved_by_user_id`, `invited_by_user_id`, `blacklisted_by_user_id`, `verified_by_user_id`, `impersonated_user_id`.
- **How `AuditPayloadKeys` decides them.** `from_user` and `to_user` are in `notPersonalKeys()` under "A user id on each side of an owner reassign (AH-119 A3): an id, not a name"; the other user-id keys are in the group "An id, ulid or foreign key: points at a row that is anonymised in place, which is the point of pseudonymising". `scrubKeys()` holds only `email`, `name`, `last_name`, `last_login_ip`, `suspended_reason`, `display_name`, `provider_account_id`, `before`, `after` and `welcome_message`: the keys that carry a person's own data.
- **How the erasure of a user treats them.** The users row is anonymised **in place** and never deleted, so a user id in an audit row still points at a valid, pseudonymised row; `AuditRowScrub` removes the scrub keys on the rows the subject owns and keeps every id and ulid.
- **Decision (precedent fits exactly, so no stop).** An owner change in a step's audit row uses **`from_user` and `to_user`**, the existing decided keys, carrying the **integer user id** exactly as AH-119 A3 does. No new key is added for a user. A step's `owner_user_id` is **not** put in any `Audited` allowlist (there is none; audit rows are written by hand with literal keys). `owner_client` is a boolean flag, not personal. The step `name` key is the already decided `name` (a step title typed by an agency user, the PL-1.1 wave `name` decision stands).
- **New keys needing a decision in `notPersonalKeys()`:** `stream`, `step_key`, `owner_client`, `status_source`, `starts_on`, `due_on`, `completed_on` (an agency's planning data, no person is a field of it), each in a group with its reason. `status`, `from_user`, `to_user` and `name` are already decided.

**C2. Real browser before the walk (`WORKING-PROCESS.md` §4.2).** Before a walk is offered, the tracker and the Brand page bar are opened in a real browser at a desktop width and a narrow one, with real walk data, and the review file reports what was looked at. Every dialog and menu in the tracker (edit step, add step, remove step, remove stream, add stream, owner menu, row menu) is opened, closed and reopened quickly (the PL-1.2 D1 failure).

**C3. Frozen file.** `agencyStatusTones.ts` is frozen and is re-pinned in the same commit that touches it (reason stated). With the flag OFF the 12 campaign and 16 brand walk targets must stay identical to `ah-115-agency-baseline/baseline.json`, **including the comment-node check**.

**Break-revert record.** Every gate is recorded in `docs/reviews/pl-1-3-break-reverts.md` as it is built, in the repo.
