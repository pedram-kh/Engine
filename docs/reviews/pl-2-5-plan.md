# PL-2.5 plan: Connecting it up (step states, tracker, landing, internal notes)

Status: plan only. No code, no migration, no push. Read with `pl-2-5-inventory.md` (section numbers below like "inventory 4" point there). Branch `pl`, from tip `b2b4d113`.

**Goal.** Strategy steps get a real state; the wave tracker and the Brand page bar follow it; "Strategy & Scripts" opens where the work is; every step has internal notes. All of it behind `strategy_enabled` (which needs `waves_enabled`). With strategy OFF the tracker is hand-set exactly as PL-1 left it.

## Rulings for this chunk (K1 to K4, F1, N1 to N4) and where each lands

| Ruling | Text (short)                                                                                                                                                                                                   | Lands in                  |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------- |
| **K1** | USPs, Personas, Matrix have "Mark as complete" (strategist) and "Reopen"; it warns, never blocks. Concepts is automatic. PL-5 takes over later.                                                                | 1.1, 1.4, 3.2, 4.1        |
| **K2** | Personas, Matrix, Concepts per market; done for the wave only when done in every market, else in progress "n of m markets". USPs per wave.                                                                     | 1.1, 1.3, 3.3             |
| **K3** | The wave page stays the tracker. "Strategy & Scripts" opens the first step not done (and the first market not done). A direct address wins.                                                                    | 1.6, 3.4, 4.2             |
| **K4** | Internal notes: all three roles write; each edits and deletes their own; admin and manager delete anyone's. Never shown to clients or creators.                                                                | 1.7, 3.5, 4.4             |
| **F1** | The six rows are set by the system from the real steps; a person can set one by hand; beyond-real hand-set warns and confirms and shows "set by hand". `ugc.creators`, `ugc.scripts` stay hand-set until PL-3. | 1.5 (the table), 3.3, 4.3 |
| **N1** | States: not started (no data), in progress, done; done steps show the check icon.                                                                                                                              | 1.3, 4.1                  |
| **N2** | Brand page bar and wave page progress follow the same rows; the PL-1.3 rule is unchanged.                                                                                                                      | 1.5, 3.3                  |
| **N3** | One notes thread per step per market, one per wave for USPs; the amber panel; card grids return to the frame widths.                                                                                           | 1.7, 4.4, 4.5             |
| **N4** | Not built: client comments and counts, "Preview as client", "Share with client", the approval dropdown.                                                                                                        | 9                         |

## 8. PROD-DATA RISK (stated first, before any code)

**PROD-DATA RISK: NONE for existing data, and no existing production table is altered.** No PL migration is on `main` or in production (inventory 7, fact 1: `origin/main` = `09169b5a`, no wave file). The first merge of `pl` creates every PL table from nothing. PL-2.5 adds **two new tables**, created empty, and **edits no existing migration** (section 2). No migration reads, writes, copies or backfills anything: the six tracker rows of an existing wave keep what they hold, and the system takes them over on the first open of the tracker with strategy ON (section 1.5, catch-up).

| Table (new)                 | Parents it references (foreign keys, all RESTRICT except the author)                                        | Lock taken while it is created (on live parent tables)                                                           |
| --------------------------- | ----------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `wave_strategy_completions` | `agencies`, `waves`, `wave_markets` (composite `(wave_id, market_id)`), `users` (completer, `nullOnDelete`) | SHARE ROW EXCLUSIVE on each of the four parents for the length of the migration (milliseconds on an empty child) |
| `wave_strategy_notes`       | `agencies`, `waves`, `wave_markets` (composite), `users` (author, `nullOnDelete`)                           | the same four                                                                                                    |

Locks are **named here and measured, not asserted**: the build runs `migrate` on a throwaway PostgreSQL with `lock_timeout` 5 s and reads `pg_locks` before commit, as the PL-2.1 M2 rehearsal did (`pl-2-1-migration-rehearsal.md`); the result goes in the review ("not run" until then). `users` is the one parent that is a busy live table in production, which is why its lock is called out; the migration sets a 5 s `lock_timeout` so a blocked deploy fails fast instead of queueing every login behind it. Production runs PostgreSQL 17.9; the local rehearsal runs 16.

Stale databases: new files are picked up by `migrate`, so no developer database needs a rebuild this time (unlike PL-2.4).

## 1. Data model and rules

### 1.1 Where completion is stored

**One new table, `wave_strategy_completions`** (Strategy module, `BelongsToAgency`, factory, `ulid`). A row **is** the strategist's mark; Reopen deletes the row (the audit log keeps the history: who marked, who reopened, when).

| Column                   | Type                                                 | Notes                                                                                                                       |
| ------------------------ | ---------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `id`, `ulid`, timestamps | standard                                             |                                                                                                                             |
| `agency_id`              | FK `agencies`, RESTRICT                              | tenancy                                                                                                                     |
| `wave_id`                | FK `waves`, RESTRICT                                 |                                                                                                                             |
| `step`                   | varchar(16), CHECK in (`usps`, `personas`, `matrix`) | concepts is never stored (K1)                                                                                               |
| `market_id`              | bigint **nullable**                                  | null **only** for `usps` (per wave); a market id for `personas` and `matrix`; CHECK `(step = 'usps') = (market_id is null)` |
| `completed_by_user_id`   | FK `users`, `nullOnDelete`                           | who pressed it; shows "Former member" when null or no longer a member                                                       |
| `completed_at`           | timestamp                                            |                                                                                                                             |

Keys: composite FK `(wave_id, market_id)` to `wave_markets (wave_id, id)` (the parent key `unique_wave_markets_wave_id_id`, inventory 7 fact 2; with a null `market_id` the FK is not checked, which is the intent). **Unique expression index** on `(wave_id, step, coalesce(market_id, 0))`: one mark per wave and step for USPs, one per market otherwise (an expression index, as `wave_markets` already uses for `lower(name)`; PostgreSQL and SQLite both run it; verified on both in the build). Index on `agency_id`.

PL-5 stays additive: the table holds the **agency's** mark (who, when). A client approval is a different fact by a different person, so PL-5 adds its own table (`wave_strategy_approvals`: wave, step, market, client contact, when, and per step "approved" overrides nothing here). The step's "done" then becomes `marked AND approved` in the one function that derives state (1.3); no column here changes and no row here is rewritten. The per-concept client decisions already exist (`wave_concept_decisions`, recorded by an agency user on the client's behalf, PL-2.3); PL-5 keeps them and adds the client as a real actor.

### 1.2 Cleanup tied to the market

A market can be removed only when it has no personas (PL-2.4, M6). A market without personas can still have a completion row (Personas marked complete on an empty market is refused by 1.4, but a market that lost its last persona drops its marks by 1.4 rule E) and notes. Removing it **deletes its completions and its notes in the same transaction**, through the existing `WaveMarketGuard` contract (a new method `forgetMarket(Wave $locked, array $removedIds): array{completions: int, notes: int}`, implemented by `StrategyWaveMarketGuard`). The counts go into the existing market-removal audit event as new literal keys `completions_removed` and `notes_removed`. The foreign keys stay RESTRICT as the belt: a service that forgot would be refused by the database, not cascade silently. Refusing the removal because of notes was considered and rejected: the notes of an empty market are scratch, and a refusal would block a legitimate edit (question Q8).

### 1.3 The real state of a step, in one place

A pure class `StrategyStepState` (Strategy module) is the **only** place that decides a state. Order: `not_started` < `in_progress` < `done`.

| Step     | Scope      | not started               | in progress                                      | done                                                        |
| -------- | ---------- | ------------------------- | ------------------------------------------------ | ----------------------------------------------------------- |
| USPs     | wave       | no USP                    | at least one USP, not marked                     | marked                                                      |
| Personas | per market | no persona in the market  | at least one persona, not marked for the market  | marked for the market                                       |
| Matrix   | per market | no cell set in the market | at least one cell set, not marked for the market | marked for the market                                       |
| Concepts | per market | no concept in the market  | at least one concept and not all approved        | at least one concept and every concept approved (automatic) |

**For the wave** (K2) a per-market step is: `done` when it is done in **every** market of the wave; `not_started` when it is not started in every market and nothing is marked anywhere; otherwise `in_progress` with `markets_done` of `markets_total` ("1 of 3 markets"). USPs is the wave's own state. A wave with zero markets cannot exist (PL-2.4), but the function returns `not_started` for it rather than dividing by zero.

Inputs are the five grouped queries of inventory 3 (USP count, completions of the wave, personas per market, cells per market, concept statuses per market). The matrix is "not started" when the market has no persona or no USP even if a mark exists (the mark is dropped by 1.4 in that case, so this is a belt).

### 1.4 What "Mark as complete" does when data changes afterwards

**Principle:** a mark is a statement by a person at a time. Editing never silently takes it back, because that would be a second, hidden way for a step to change; the **warnings are live**, computed from the current data every time the step is shown. The one exception keeps N1 true (not started means no data).

| Rule | Event after the mark                                                                 | What happens to the mark                                                                | What the person sees                                                                                                                                             |
| ---- | ------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| A    | A USP is deleted and the wave drops under four                                       | Stays (USPs still done)                                                                 | The step shows the live warning "3 USPs, fewer than the 4 recommended"; the tracker stays done                                                                   |
| B    | A persona is added to a market marked complete                                       | Personas mark stays                                                                     | Live warning counts personas against the minimum; the **matrix** of that market has new cells not set, so its warning reads "n cells not set" and its mark stays |
| C    | A new market is added to a wave                                                      | Nothing to take back: the new market has no mark                                        | The wave step is no longer done: "m-1 of m markets"; if it was done it goes back to in progress (derived)                                                        |
| D    | A market with a mark is removed                                                      | Its row is deleted with the market (1.2)                                                | The wave step may become done if the rest are                                                                                                                    |
| E    | The **last** record goes (last USP; last persona of a market; last cell of a market) | **Dropped**, same transaction, audited `wave_strategy.reopened` with `reason = emptied` | The step reads not started; the button reads "Mark as complete" again                                                                                            |
| F    | An approved concept is edited or loses a chip/image/link, or a USP in use is deleted | Not stored: concepts is derived                                                         | The market goes back to in progress by itself (existing `resetIfApproved`)                                                                                       |
| G    | A person presses Reopen                                                              | Row deleted, audited                                                                    | In progress (if data exists)                                                                                                                                     |

**Marking with no data at all** (zero USPs; a market with zero personas; a market with zero cells) is **refused** with 422 `strategy.nothing_to_complete`: "done" with nothing contradicts N1 and rule E. K1 says the mark does not block; the recommendation is that K1 means the **warnings** (under four USPs, under two personas, cells not set) do not block, and that an empty step is not a warning case but nothing-to-mark (question Q1; if overruled the rule E invariant is dropped and an empty step may be marked).

**Warnings the mark shows** (a confirm dialog, not a refusal; the person presses "Mark as complete anyway"): fewer than 4 USPs (`StrategyLimits::USP_WARN_BELOW`); a market with fewer than 2 personas (`PERSONA_MIN`); matrix cells not set (set versus personas times USPs, from `MatrixService::progress`). The server does not require a confirm flag for the mark (it is not a block); the dialog is the warning. The server returns the same warnings in the response so a script sees them.

### 1.5 Written or computed? Recommendation: **written on change, with a catch-up on open**

**Recommended: the six tracker rows stay stored, and the system writes them in the same transaction as the change that moves a step.** Not computed on read.

Why:

1. **The Brand page and the wave list stay one grouped query on `wave_steps`** (`progressForWaves`). Computing on read would add about five grouped queries to every list page (inventory 3). Flat in N, but a regression on the busiest page, and `WaveTrackerCalculator` (shared code, PL-1.3) would have to learn Strategy tables.
2. **F1 needs a stored fact**: whether a row is hand-set, by whom and since when, and "the system takes a row back" is an event with a before and an after. A pure compute has no memory: a hand-set `done` would reappear after the real step fell back below it. A stored row with `status_source` and the audit log is the model PL-1.3 already built (`status`, `status_source`, `completed_on`, `wave_step.updated`).
3. No scheduled job is needed: rows move in the transaction of the change.

How, so it is correct:

- **One hook, `StrategyTrackerSync::around(Wave $locked, array $steps, Closure $change)`**, called by every writer in inventory 4 **after the wave lock it already holds**. It reads the real state of the touched steps (only those: a matrix save reads the matrix inputs, not all five queries), runs `$change`, reads them again, and applies the F1 table to each row whose real state **changed** (`before` to `after`). It writes through a Waves service `WaveStrategyRowService` (the only new writer of a tracker row), which sets `status`, `status_source`, `completed_on`, audits `wave_step.updated` (with the actor of the change that caused it) and is a no-op when nothing differs. Strategy already imports Waves models, so the dependency direction exists; the tracker reads the real state through a new Waves contract `WaveStrategyState` implemented in Strategy (the `WaveMarketGuard` pattern, bound only in the Strategy provider).
- **Same transaction**: a failure after the hook rolls the row back with the data (test as the A1-9 rollback test in PL-2.4: force a failure after the hook, assert both unchanged).
- **Two people at once**: every strategy write takes `StrategyGuard::lockWave` first (inventory 7, fact 3); a tracker status change on a strategy row takes the **same wave lock first** (new; today it takes only the step lock). The hook recomputes inside the lock from committed data, so the last writer's view wins and no interleaving can store a state that no data supports. On PostgreSQL this is a row lock; on SQLite the single writer serialises. Proof is a query-log test that the wave `FOR UPDATE` is the first statement and a sequential two-writer test; **a true two-connection race is not planned** (as PL-2.4's L6; named so it is not read as covered).
- **Catch-up on open**: `GET tracker` and the strategy summary call `StrategyTrackerSync::catchUp($wave)` when strategy is ON: it recomputes all four real states under the wave lock and applies the F1 table (system-source rows follow; hand-set rows are left or taken back by the table). It is idempotent, writes only when something differs, audits only what it wrote, and is a write on a GET (the `WaveStepProvisioner` precedent). This covers what no hook sees: **strategy turned OFF then ON** and **markets edited through the wave dialog while strategy was OFF**.
- **Strategy OFF**: no strategy service is reachable (the flag check is the first line of every controller), `WaveMarketService` calls the sync only when strategy is ON for the agency, the tracker payload has no `strategy` key, and `applyStatus` behaves exactly as PL-1.3 (always `manual`, no confirm, no warning).
- **What stays stale and for how long**: with strategy OFF the six rows are PL-1 hand-set rows, which is correct. After a toggle ON, the Brand page shows those rows until a wave's tracker (or its workspace) is opened once, which refreshes that wave. The Brand page and the wave list do **not** run the catch-up (it would be a write per row of a list). The staleness is bounded to "what PL-1 hand-set, until the wave is opened" and is stated in the walk (step 13) and the review.

Considered and rejected: compute on read with the hand-set value stored separately (loses the take-back event and costs the list queries); a scheduled reconcile (no job, ruled).

### The F1 table

Order `not_started` (N) < `in_progress` (P) < `done` (D). R = the **real** state of the step for the wave (1.3), H = the status a person set by hand. "Source" is the value of `wave_steps.status_source`; the person who acted is always in the audit event. Rows 1 to 8 apply to `ugc.usps`, `ugc.personas`, `ugc.matrix`, `ugc.concepts`; rows 9 to 11 to the others and to strategy OFF.

| #   | Situation                                                            | Real state   | Hand-set state | What the row shows                                                                                                      | Warning                                                                                                                              | Source recorded                                                              |
| --- | -------------------------------------------------------------------- | ------------ | -------------- | ----------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------- |
| 1   | Never hand-set, or taken back; the real step changes                 | R            | none           | R                                                                                                                       | none                                                                                                                                 | `system`; row follows R on every change                                      |
| 2   | A person sets H **beyond** R (H > R) on the row                      | R            | H              | H, with the chip "Set by hand"                                                                                          | **Before saving**: "Personas is not complete (1 of 3 markets)"; the person confirms; the row then shows the warning line while H > R | `manual` (the person is in the audit)                                        |
| 3   | A person sets H **equal** to R                                       | R            | H = R          | R                                                                                                                       | none (nothing is overstated)                                                                                                         | `system` (the row agrees with the data)                                      |
| 4   | A person sets H **below** R                                          | R            | H              | H, with the chip "Set by hand"                                                                                          | none (nothing is overstated)                                                                                                         | `manual`                                                                     |
| 5   | Row is `manual` with H > R; R changes but stays below H              | R'           | H (left alone) | H, "Set by hand"; the warning line updates live ("2 of 3 markets")                                                      | the live warning line                                                                                                                | `manual` (unchanged)                                                         |
| 6   | Row is `manual`; R changes and now **reaches or passes** H (R' >= H) | R'           | cleared        | R'                                                                                                                      | none                                                                                                                                 | back to `system`; audit `wave_step.updated` with `reason = system_took_back` |
| 7   | Row is `manual` with H > R; R falls (a concept is unapproved)        | R' < R       | H (left alone) | H, "Set by hand", warning                                                                                               | the live warning line                                                                                                                | `manual`                                                                     |
| 8   | Catch-up on open (flag turned ON, or changes made while OFF)         | R, all steps | per row        | system rows follow R; `manual` rows with H <= R are taken back (as row 6); `manual` rows with H > R are left (as row 5) | the live line for H > R                                                                                                              | per row as above                                                             |
| 9   | `ugc.creators`, `ugc.scripts` (no real step until PL-3)              | none         | H              | H                                                                                                                       | none, no confirm, no chip                                                                                                            | `manual` when a person sets it (PL-1.3 behaviour)                            |
| 10  | Strategy OFF                                                         | not read     | H              | H                                                                                                                       | none                                                                                                                                 | `manual` (PL-1.3 behaviour, unchanged)                                       |
| 11  | A person sets H beyond R and **cancels** the confirm                 | R            | not set        | unchanged                                                                                                               | the dialog only                                                                                                                      | unchanged                                                                    |

**When the system takes a row back:** exactly when the real step **reaches or passes** the hand-set status (row 6), or at catch-up when it already has (row 8). **When it leaves a hand-set row alone:** while the hand-set status is beyond the real state (rows 5 and 7), when the hand-set is below it and nothing has changed since (row 4), and always for `ugc.creators` and `ugc.scripts` (row 9) and with strategy OFF (row 10). A wave is never stuck: a person can set any status by hand at any time (the warning needs a confirm, never a refusal), and the system never refuses a hand-set; a taken-back row can be hand-set again.

The warning on a hand-set row (row 2) is an API contract, not only a dialog: `PATCH .../steps/{step}` with a status beyond the real state and no `confirm_incomplete: true` answers **409** `step.incomplete` with `{step, real_state, markets_done, markets_total}`; the same request with `confirm_incomplete: true` saves it. Without strategy ON, or for a step with no real state, the key is ignored and the request behaves as today.

**Brand page bar and wave page progress (N2)** are untouched code: `WaveTrackerCalculator::progress` and `progressForWaves` read the stored rows as before; segment filled when done, blue when in progress, label names the first step not done. The only change is who writes the rows.

### 1.6 Where "Strategy & Scripts" opens (K3)

The wave page stays the tracker. The entry button points at `strategy.index` (today a static redirect to `/usps`; it becomes a route that loads the summary and replaces itself with the landing). The landing is computed **server-side** in the strategy summary (`landing: {step, market}`) from the **real** states (not the stored rows: the work is where the data is, even if someone hand-set a row). Order: USPs, Personas, Matrix, Concepts; markets in tab order.

| #   | Real states                                                    | Opens                                                                                                                      |
| --- | -------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| 1   | Nothing started                                                | USPs                                                                                                                       |
| 2   | USPs in progress                                               | USPs                                                                                                                       |
| 3   | USPs done; Personas not started in every market                | Personas, first market in tab order                                                                                        |
| 4   | USPs done; Personas: SK done, UKI in progress, NOR not started | Personas, **UKI** (first market not done)                                                                                  |
| 5   | USPs and Personas done; Matrix: SK not started                 | Matrix, SK                                                                                                                 |
| 6   | USPs, Personas, Matrix done; Concepts done in SK, not in UKI   | Concepts, UKI                                                                                                              |
| 7   | All four done                                                  | Concepts, first market (the one step where the work can still move, a decision may come back; recommendation, question Q4) |
| 8   | A row hand-set to done while the real step is not              | the **real** step (the work, not the tick)                                                                                 |
| 9   | A direct address `/strategy/matrix?market=NOR`                 | exactly that step and market (**a direct address wins**)                                                                   |
| 10  | A direct address to a step with no `?market=`                  | that step, first market (as today)                                                                                         |
| 11  | Strategy OFF                                                   | no entry button; the strategy addresses redirect as today                                                                  |

## 2. Migrations

**Two new files, no existing migration edited.** The in-place rule (WORKING-PROCESS §5: a migration may be edited in place only while no environment other than local and CI has run it; PL migrations are unmerged) is **not used** in this chunk, because both tables are new and nothing existing changes (the tracker rows already have `status`, `status_source` and `completed_on`). That keeps the rule's reason intact: the less is edited in place the fewer developer databases go stale.

| File                                                       | Creates                                                                                                                                             | `down()`       |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- | -------------- |
| `2026_11_22_100000_create_wave_strategy_completions_table` | the table, keys and indexes of 1.1; CHECKs on PostgreSQL (and SQLite where `CHECK` is supported through the same statement the other PL tables use) | drop the table |
| `2026_11_22_100001_create_wave_strategy_notes_table`       | the table of 1.7                                                                                                                                    | drop the table |

Both set `lock_timeout` 5 s on PostgreSQL before the foreign keys and say in their header comment which locks the foreign keys take (the PL-2.1 M2 pattern). The ErasureRegistry entries and the new `AuditAction` and `AuditPayloadKeys` entries ship in the same commit as the tables (the verifier fails the build otherwise).

### 1.7 Internal notes

**One new table, `wave_strategy_notes`** (Strategy module, `BelongsToAgency`, factory, `ulid`).

| Column                   | Type                                                             | Notes                                                                                                                           |
| ------------------------ | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `id`, `ulid`, timestamps | standard                                                         | `created_at` is the order key                                                                                                   |
| `agency_id`, `wave_id`   | FKs, RESTRICT                                                    |                                                                                                                                 |
| `step`                   | varchar(16), CHECK in (`usps`, `personas`, `matrix`, `concepts`) | the thread's step                                                                                                               |
| `market_id`              | bigint nullable, composite FK `(wave_id, market_id)`             | null only for `usps` (one thread per wave); a market id for the other three (one thread per step per market); same CHECK as 1.1 |
| `author_user_id`         | FK `users`, `nullOnDelete`                                       |                                                                                                                                 |
| `body`                   | text                                                             | plain text, at most **2000** characters after cleaning, at least 1                                                              |
| `edited_at`              | timestamp nullable                                               | set when the author edits; drives the "edited" marker                                                                           |

Index `(wave_id, step, market_id, created_at, id)` for the thread read.

| Aspect                 | Rule                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Limits                 | 2000 characters per note; **100 notes per thread** (the 101st is refused 422 `note.thread_full`); an empty or whitespace-only body is refused 422                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                |
| Plain text only        | Cleaned by the same `StrategyText` cleaner as every strategy text (control characters stripped, line breaks normalised). Rendered with `{{ }}` and `white-space: pre-wrap`; **never `v-html`** (the existing no-v-html spec is extended to the notes components); a hostile string (markup, script) is shown as text                                                                                                                                                                                                                                                                                                                             |
| Ordering               | Oldest first (`created_at`, `id`); the add box is under the list; the panel scrolls inside itself above a maximum height                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| Edited marker          | "edited" next to the date when `edited_at` is not null                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| Author shown           | The member's name and the date. A note whose author is null, or whose author's membership is soft-deleted or not accepted, shows **"Former member"** and **no name** (the `owner_is_former` rule of the tracker; one users query and one memberships query per thread)                                                                                                                                                                                                                                                                                                                                                                           |
| Removed member's notes | Stay; only an admin or manager can delete them; nobody can edit them                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| Who can do what (K4)   | Create: admin, manager, staff. Edit: **the author only**, any role. Delete: the author, or admin or manager for anyone's. Staff cannot delete another's. A user with no agency role (a client, a creator) is refused everything (403)                                                                                                                                                                                                                                                                                                                                                                                                            |
| Audit                  | New actions `wave_strategy_note.created`, `.updated`, `.deleted`; metadata ulid, step, market ulid, body length, never the body                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| Erasure registry       | `wave_strategy_notes`: `Disposition::NotApplicable`, "Agency-authored (§3.7); internal working text that may name a person (a creator, a client contact, a colleague)", user column `author_user_id`, `body` documented as free text about third parties. Precedent: `wave_concept_decisions` (tech-debt 48). **Erasing a creator does not touch notes** (they are the agency's record, not the creator's data) and notes are not scanned for names; a new tech-debt row says so and that the privacy documents need the same sentence as tech-debt 48. A hard-deleted agency user nulls `author_user_id`, shows "Former member", keeps the text |
| Wave deleted           | Notes stay with the soft-deleted wave (reads use `withTrashed`, as every strategy route)                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |

**How "never shown to clients or creators" is guaranteed when client views arrive (PL-5).** Four layers, each with a test, so one mistake does not leak:

1. **Separate presenter.** Notes are serialised only by `StrategyNotePresenter`, called only by the notes controller. No other presenter or resource (summary, concept, tracker, list) includes a note body or a thread; the summary carries only a count, and only in the agency response.
2. **Separate routes and policy.** The notes routes live in the agency strategy group (`auth:web`, `tenancy.agency`, `tenancy`) and the policy requires a non-null `CurrentAgencyRole`. A client or creator has none. PL-5's client routes will be a **different route group with a different guard**; the notes routes are not mounted there.
3. **An architecture test** (Pest `arch`): nothing outside `App\Modules\Strategy\Notes\` (or the notes namespace chosen in the build) may reference the `WaveStrategyNote` model or `StrategyNotePresenter`; and a route-list test asserts no route whose middleware group is a client or creator group has `notes` in its name or path. PL-5 must keep both green; adding a client route that reads a note fails the build.
4. **Naming and registry.** The table is named for what it is (`wave_strategy_notes`), the registry text says "internal", and the PL-5 inventory is told (a line added to the sprint file) to run a "client payload contains no note" test on every client endpoint.

## 3. API

### 3.1 The first lines of every action (unchanged)

`flagOff` (waves and strategy both on, else 404), `assertBrand`, `findWave` (agency, brand), `Gate::authorize`, brand-writable for writes, then `StrategyGuard::lockWave` first inside the transaction.

### 3.2 Completion

| Route                                                                       | Behaviour                                                                                                                                                                                                                                           |
| --------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `PUT brands/{brand}/waves/{wave}/strategy/completions/{step}?market={code}` | Mark. `step` in `usps`, `personas`, `matrix` (any other: 404); `market` required for the last two, refused for `usps` (422). Idempotent. 422 `strategy.nothing_to_complete` (1.4). Returns the step state, the warnings list and the summary slice. |
| `DELETE` the same address                                                   | Reopen. Idempotent (nothing to reopen is a 204). Audited.                                                                                                                                                                                           |

Roles (recommendation, Q5): **all three agency roles** mark and reopen, as every strategy write is open to all three (`WaveStrategyPolicy`). Reasons: the mark is reversible and audited with the actor; a staff member who built the USPs is the person who says they are finished; limiting it to admin and manager would add a handoff the rulings do not ask for. A client or creator is refused (403).

### 3.3 State in the payloads

- **Strategy summary** gains per step: `state` (wave), `markets: [{market, state, marked, warnings}]` for the per-market steps, `completed_by` and `completed_at` for marks, `landing`, and `note_counts` (agency only). No new request: the existing summary is extended, so the stepper needs no second call.
- **Tracker** (only with strategy ON) gains per strategy row `strategy: {state, markets_done, markets_total, hand_set_beyond: bool}` (the live line of F1 rows 2, 5 and 7), read through the `WaveStrategyState` contract. With strategy OFF the key is absent and the payload is byte-for-byte PL-1.3.
- **Status PATCH** gains the optional `confirm_incomplete` (1.5).

### 3.4 Audit (literal keys; Erasure and `AuditPayloadKeys` decision in the same commit)

New actions: `wave_strategy.completed`, `wave_strategy.reopened` (metadata: step, market ulid or null, actor implied, `reason` of `manual`, `emptied`), `wave_strategy_note.created`, `.updated`, `.deleted`. Existing `wave_step.updated` gains the optional literal key `reason` (`system`, `system_took_back`) and the existing market-removal event gains `completions_removed` and `notes_removed`. Metadata is ids, step names, counts and booleans, **never** a note body, a name or a comment.

### 3.5 Notes

| Route (under `.../strategy/`) | Behaviour                                                                                                                     |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `GET notes?step=&market=`     | The thread, oldest first, with author name or "Former member", `edited`, and `can_edit`, `can_delete` per note for the viewer |
| `POST notes`                  | Create (`step`, `market` for the per-market steps, `body`)                                                                    |
| `PATCH notes/{note}`          | Author only; sets `edited_at`; 403 for anyone else (including admin and manager)                                              |
| `DELETE notes/{note}`         | Author, or admin or manager                                                                                                   |

### 3.6 Tenancy

`BelongsToAgency` on both models; `{note}` resolves inside the wave, so a note of another agency or another wave is a 404; `market` is resolved among the wave's markets (an unknown short code is a 404). The hook and the catch-up read and write only rows of the locked wave's agency.

## 4. Web

### 4.1 Stepper

`StrategyStepper.vue` draws the three states from the summary (section 1.1 of the inventory): done = green circle with the check icon, name 14 / 500 #a0a0a8, status 11.5 #34c77b ("Done" and, for per-market steps, "Done · 3 markets"); in progress = amber circle with the number, status 11.5 #f5a524 ("In progress · 1 of 3 markets" or the USP count); not started = number on #232326 with "Not started" #6e6e76. The current step keeps the PL-2.1 raised fill and 60% border. Steps 5 and 6 stay plain, unlocked elements (no lock; ruling C6) and now show their **tracker status** ("Not started", "Set by hand · Done") plus the existing concept warning. Check icon asset: the frame's SVG.

### 4.2 Landing

`strategy.index` becomes a route that renders a skeleton, loads the summary and `router.replace`s to the landing of 1.6. The wave page button points at `strategy.index`. Direct addresses to a step are untouched.

### 4.3 The tracker

Strategy rows show a "Set by hand" chip when `status_source` is `manual` and the wave has strategy ON, an "n of m markets" line when the row is in progress, and the live warning line when `hand_set_beyond`. Setting a status beyond the real one opens a confirm dialog ("Personas is not complete (1 of 3 markets). Set it to Done anyway?") and resends with `confirm_incomplete`. With strategy OFF none of this renders.

### 4.4 Mark as complete, Reopen and the Notes panel

- **Mark as complete** on steps 1 to 3 (per market on 2 and 3, on the market's tab): a button in the step header; if a warning applies, the confirm dialog lists it ("Fewer than 4 USPs (3)", "Market has 1 persona, 2 recommended", "6 of 8 cells not set") and says "Mark as complete anyway". When marked, the button reads "Reopen" with "Marked complete by {name} on {date}" ("Former member" when it applies). Concepts shows no button, a line: "Complete when every concept is approved".
- **Notes panel**: the 300 px right column with the amber card (inventory 1.2), the author line, "edited", add box, and an edit and delete menu per note shown by `can_edit` and `can_delete`. On the matrix the column is absent (the frame draws none): "Notes (n)" in the title row opens the same card in a side sheet (assumption, Q3). The panel loads the thread for the current step and market and **reloads on tab change**.

### 4.5 Widths and the fidelity table (ruling 10; every row is measured in a real browser and recorded in the review)

With the column present the card grids return to the frame widths: USP cards 290 (3 per row), persona cards 448 (2), concept cards 444 (2), at the 1600 frame (main area 912, gap 24, column 300). **This closes** three rows of the earlier reviews: PL-2.1 USP card width (302 to 290), PL-2.1 persona card width (408 to 448), PL-2.3 concept card width (620 to 444), plus the Notes half of the "Notes & comments" column (PL-2.1 and PL-2.3) and the matrix "Notes (n)" action (PL-2.2). **Stays FUNCTIONAL:** the Comments card and count, the "Comments (n)" and approval title-row actions, "Client review" statuses, "Preview as client", "Share with client" (N4, PL-5), and the lock state of steps 5 and 6 (C6).

| Item                                     | Frame value (node)                                                                               | Built value (measured in the build) | Class (to be set)                |
| ---------------------------------------- | ------------------------------------------------------------------------------------------------ | ----------------------------------- | -------------------------------- |
| Done step circle                         | 28 wide, round, fill #3ecf9e, check 14 (13:4533)                                                 | measure                             | VISUAL if different              |
| Done step name and status                | 14 / 500 #a0a0a8; 11.5 / 400 #34c77b (13:4537, 13:4538)                                          | measure                             |                                  |
| In-progress circle                       | 28, fill #f5a524, number 12 / 600 #0b0b0c (13:4554)                                              | measure                             |                                  |
| In-progress status                       | 11.5 / 400 #f5a524 (13:4558)                                                                     | measure                             |                                  |
| Current step box                         | fill #232326, 1px `rgba(62,207,158,0.6)`, radius 8, pad 10 / 12, gap 10 (13:4553)                | PL-2.1 values (pad 10 / 14, gap 12) | VISUAL, kept (already walked)    |
| Not-started step                         | no frame                                                                                         | assumption 1.4 of the inventory     | VISUAL, assumption               |
| Locked step (5 and 6)                    | lock 13 on #232326, opacity 0.7, "Unlocks after concepts" (13:4559)                              | no lock, tracker status             | FUNCTIONAL, ruled (C6)           |
| Notes column                             | 300 wide at x 936, gap 16 between cards (13:5011)                                                | measure                             |                                  |
| Notes card                               | fill `rgba(245,165,36,0.07)`, 1px `rgba(245,165,36,0.35)`, radius 10, pad 18, gap 10 (13:5012)   | measure                             |                                  |
| Notes title                              | note icon 16, gap 8, 15 / 600 #f5a524 (13:5017)                                                  | measure                             |                                  |
| "Internal" chip                          | fill `rgba(160,160,168,0.15)`, pad 2 / 8, radius 999, lock 12, gap 6, 11 / 500 #a0a0a8 (13:5018) | measure                             |                                  |
| Note text                                | 13 / 400, line 19, #ededed (13:5023)                                                             | measure                             |                                  |
| Author line, date, edited, add box, menu | no frame                                                                                         | measured against theme              | VISUAL, assumption               |
| Comments card                            | fill #1b1b1d, 1px #2e2e33, radius 10, pad 18, gap 12; "Comments (3)" 15 / 600 (13:5024)          | absent                              | FUNCTIONAL (PL-5)                |
| USP / persona / concept card             | 290 / 448 / 444 (13:428, 13:828, 13:4412)                                                        | measure at 1600 and 1440            | expected to move to "fixed"      |
| Matrix title-row "Notes (n)"             | drawn, not its sheet (13:1375)                                                                   | measure                             | VISUAL, assumption for the sheet |

### 4.6 Frozen files

Nothing in `WaveTrackerCalculator::progress` and `progressForWaves` changes; the Brand page and the wave list code paths other than the data are untouched.

## 5. Flag

Both flags, unchanged semantics: every new route and UI element needs `waves_enabled` and `strategy_enabled`; the SPA reads `features.waves` and `features.strategy`. No new flag. This plan **never writes a flag in any database Pedram uses**; test and walk databases set flags in the test, or Pedram sets them by hand in the walk database.

## 6. Tests (each gate has a break-revert: mutate, red, restore in `finally`, green)

### Backend gates (Pest, SQLite and PostgreSQL unless noted)

| #   | Gate                                                                                                                                                                                                                                                                                                                        | Break-revert                                                            |
| --- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| B1  | **Both flags**: completions, notes, landing and the tracker `strategy` key answer 404 / absent with waves off, with strategy off, and work with both on                                                                                                                                                                     | drop the flag check in each controller                                  |
| B2  | **Tenancy**: another agency's wave, note or market ulid is a 404; a note of wave A through wave B is a 404; `BelongsToAgency` on both models                                                                                                                                                                                | remove the scope / the wave constraint                                  |
| B3  | **Roles**: staff mark, reopen, write notes, edit own; staff cannot edit or delete another's; admin and manager delete anyone's but edit only their own; client and creator 403 on every route                                                                                                                               | invert each policy line                                                 |
| B4  | **F1 rows 1 to 11**, one test per row, including the audit (`reason`, source, actor) and the 409 / `confirm_incomplete` contract                                                                                                                                                                                            | break each rule of the table in `StrategyTrackerSync` / the row service |
| B5  | **K2 with three markets**: wave step done only when done in all; "n of m"; adding a market reverts a done step; removing the last unfinished market completes it; each per-market step                                                                                                                                      | `any` instead of `all`                                                  |
| B6  | **K1**: warnings returned and never block; nothing-to-complete refused; Reopen; concepts automatic (approve all: done; unapprove one, edit an approved concept, delete a USP in use: not done; delete the only unapproved concept: done)                                                                                    | drop `resetIfApproved`'s effect / flip the derivation                   |
| B7  | **Rules A to G of 1.4** (marks stay on USP delete and persona add; emptied marks dropped; market removal deletes marks and notes with counts in the audit)                                                                                                                                                                  | remove the emptied rule / the market cleanup                            |
| B8  | **K3 landing**: every row of the table in 1.6                                                                                                                                                                                                                                                                               | reverse the step order / pick the last market                           |
| B9  | **Hook coverage**: one test per event row of inventory 4 asserts the stored row after the event, including the wave-level result                                                                                                                                                                                            | remove the hook from one service at a time                              |
| B10 | **Tracker with strategy OFF unchanged**: the PL-1.3 tracker tests pass **unmodified**; plus a new test: payload without `strategy`, `applyStatus` manual with no confirm, a spy proves the sync is never called                                                                                                             | call the sync regardless of the flag                                    |
| B11 | **Off to on again**: edit markets and data with strategy OFF, turn ON, open the tracker: catch-up writes the system rows and leaves the hand-set rows by the table; a second open writes nothing                                                                                                                            | skip the catch-up                                                       |
| B12 | **Rollback**: a failure after the hook rolls the row back with the data (the A1-9 pattern)                                                                                                                                                                                                                                  | run the hook outside the transaction                                    |
| B13 | **Locking**: the wave `FOR UPDATE` is the first statement of a tracker status change on a strategy row and of every hook caller (query log); a sequential two-writer test                                                                                                                                                   | take the step lock first                                                |
| B14 | **Query counts**: the Brand page and wave list stay one grouped query on `wave_steps` and issue no strategy-table query; the tracker and summary have a stated maximum                                                                                                                                                      | compute on read                                                         |
| B15 | **Notes**: ownership (K4), per-thread isolation (market A's thread never shows in market B or in USPs), limits (2000, 100), empty refused, ordering, edited marker, hostile string stored and returned as text, "Former member" for a null author and for a removed membership with no name returned, audit carries no body | each rule                                                               |
| B16 | **Never shown to clients or creators**: the architecture test and the route-list test of 1.7; no other payload contains a note body                                                                                                                                                                                         | reference the model from another presenter                              |
| B17 | **Erasure registry and audit keys**: `RegistryVerifier` and `AuditPayloadKeysGuardTest` green with the two tables and the new keys                                                                                                                                                                                          | leave a table unclassified                                              |
| B18 | **Migrations**: both tables created and dropped on SQLite and PostgreSQL; CHECKs, expression unique and composite FK enforced (a mark with a market of another wave is refused by the database)                                                                                                                             | drop the FK / the unique                                                |

### Web gates (Vitest and Playwright)

Vitest: stepper in each state (check icon, status text, "n of m markets", "Set by hand", no lock on 5 and 6), the entry button and the landing redirect (every row of 1.6 with a mocked summary), the mark dialog warnings and Reopen, the tracker row chip and confirm dialog, the notes panel (add, edit own, delete, menu by `can_*`, "edited", "Former member", hostile string rendered as text, no `v-html` spec extended), the matrix sheet, i18n key parity across the 24 locales. Playwright (short, serial, `catalyst_e2e`): mark USPs complete then see the check in the stepper and the tracker row; a market not done lands the entry button on it; a note added and removed; a hand-set beyond the real step shows the confirm. Each test breaks and restores once.

### Files and tests that change

Backend: new `StrategyStepState`, `StrategyTrackerSync`, `WaveStrategyRowService`, `WaveStrategyState` contract and its Strategy implementation, a completion service and controller, a notes service, controller, policy, presenter and two models and factories; edits to `UspService`, `PersonaService`, `MatrixService`, `ConceptService`, `ConceptDecisionService`, `ConceptImageService`, `ConceptLinkService` (the hook), `WaveMarketService` and `StrategyWaveMarketGuard` (the hook and `forgetMarket`), `WaveStepService` (the wave lock first, the confirm), `WaveTrackerPresenter` (the `strategy` key), `StrategyPresenter` and `StrategyController` (summary, landing), `ErasureRegistry`, `AuditAction`, `AuditPayloadKeys`, routes. Web: `StrategyStepper`, `StrategyPage`, `UspsStep`, `PersonasStep`, `MatrixStep`, `ConceptsStep`, `WaveDetailPage` (entry address), the tracker components, a new notes panel and mark button, `strategy.api.ts`, `packages/api-client` types, `routes.ts` (`strategy.index`), the 24 locale files, `constants.ts`. Seeders `Pl25WalkSeeder` and `Pl25WalkCleanupSeeder`. Playwright and Vitest files above.

### Local gates (before any push, `WORKING-PROCESS.md` §4.1)

**Print the database each run will use before running it** (standing rule). Pint; Larastan **after every test edit too** (the PL-2.4 CI-red lesson); full Pest on **SQLite** (clean environment, no exported `DB_*`) and on **PostgreSQL** (throwaway database ending `_test`, in a subshell, named and printed first), because the tracker is shared code; the touched Vitest set (`src/core/i18n`, `src/modules/strategy`, `src/modules/waves`, `src/modules/brands`); `vue-tsc`; ESLint; the short serial Playwright tests; the walk compare (flag-off check below). Any gate not run is written "not run".

### §4.2 real-browser checks (`WORKING-PROCESS.md`, ruling 10)

Wide (1600 and 1440) and narrow (390), on the walk data: the stepper in all three states and with a hand-set row; the Notes column, and at 390 where it goes (below the step, full width, not a sideways scroll); every dialog and menu (mark with warnings, reopen, hand-set confirm, note delete, the note menu, the matrix sheet) **opened, closed and reopened quickly at three speeds**; the fidelity table of 4.5 filled with measured numbers, not "looks right"; the landing for each row of 1.6; a hostile note string; keyboard use of the panel and the stepper.

### Flag-off check

Agency (126) and creator (146) walk targets identical to `ah-115-agency-baseline` and `ah-115-creator-baseline`, with the comment-node check (`node tools/walk/walk.mjs --set agency|creator --compare ../../docs/reviews/ah-115-{agency,creator}-baseline/baseline.json` from `apps/main`). **Waves ON, strategy OFF:** the wave page and the Brand page progress show the PL-1 tracker exactly, hand-set, no chip, no "n of m", no warning, no entry button, the payload without the `strategy` key; the PL-1 Playwright specs pass unchanged. Reported exactly, as PL-2.4 did.

### Database safety

Never touch Pedram's `catalyst` database. Throwaway databases are named (`pl25_*`, `*_test` for PostgreSQL Pest), the connection is explicit and the name is printed first; no `.env` is printed; no flag is written in a database Pedram uses; every exported `DB_*` is unset afterwards.

## 7. Strings

All new text under `app.strategy.*` and `app.waves.tracker.*` in the 24 locales (English authored, the rest translated as in earlier chunks; the key-parity spec fails on a missing key). Strings: step states, "n of m markets", "Set by hand", mark and reopen texts and the three warnings, the hand-set confirm, "Notes", "Internal", "edited", "Former member", the empty and full states, errors `strategy.nothing_to_complete`, `step.incomplete`, `note.thread_full`.

## 9. Left out of this chunk

Client comments and counts, "Preview as client", "Share with client", the approval dropdown and "Client review" statuses (PL-5, ruling N4); real states for `ugc.creators` and `ugc.scripts` (PL-3); a "changed since marked complete" chip (needs a fingerprint of the data; Q2); notes on a single concept or card; mentions, attachments, search and notification of notes; the locked look of steps 5 and 6; a scheduled reconcile (ruled out); a Brand-page catch-up (1.5); a true two-connection race test.

## 10. Walk script for Pedram (local)

**Setup (named seed data).** A seeder pair **`Pl25WalkSeeder`** and **`Pl25WalkCleanupSeeder`** (local and testing only, refuse production, idempotent, additive, **never touches a flag**). Throwaway database **`pl25_walk`**, created with an explicit connection and named and printed first; never `catalyst`.

- **Wave 1 "Connected Wave":** markets Slovakia (SK), UK & Ireland (UKI), Nordics (NOR). Five USPs; personas: SK 2, UKI 1, NOR 0; some cells in SK; concepts: SK 2 approved, UKI 2 (one approved), NOR 0. Tracker rows as `provisioned` (all not started, system).
- **Wave 2 "Empty Wave":** one market, no data.
- **Wave 3 "Hand-set Wave":** rows set by hand the PL-1 way (USPs done, Personas done, Matrix in progress) with no real data behind them.
- **Wave 4 "Old Wave":** created and edited with strategy OFF in the walk (for the toggle step).
- Agency B "PL-2.5 Walk Agency B": a brand and a wave with a note (isolation).

**Steps.**

1. **Flags.** Turn on both flags in `pl25_walk` by hand (the seeder does not). Open Wave 1: the tracker shows the strategy rows; the Brand page bar shows the PL-1 look.
2. **The entry opens where the work is.** On Wave 1, "Strategy & Scripts" opens USPs (rows 1 and 2 of 1.6). Mark USPs complete: the warning appears only if under four; mark it; the stepper shows the check icon and the tracker row shows Done, not "set by hand"; the Brand page bar's first segment is filled. Press the entry again: Personas, **UK & Ireland** is not first; it opens **Slovakia** (first not done), then complete Slovakia and enter again: **UKI**.
3. **n of m.** Mark Personas complete in Slovakia and UKI (UKI warns "1 persona, 2 recommended"; confirm); the stepper says "In progress · 2 of 3 markets", the tracker row says the same. Nordics has no personas: marking is refused "nothing to complete". Add a persona to Nordics and mark it: the step is Done.
4. **Add and remove a market.** Add a fourth market to Wave 1: Personas goes back to "3 of 4 markets". Remove it: Done again.
5. **Data changes after the mark.** Delete USPs until three remain: USPs stays Done with the live warning "3 USPs". Add a persona to Slovakia: Personas stays Done; the matrix of Slovakia shows "n cells not set". Delete every cell of a market: its Matrix mark is dropped and the step reads not started.
6. **Concepts.** Approve every concept in Slovakia: Slovakia is done, the step says "1 of 3 markets". Edit an approved concept: it goes back and the market stops being done by itself.
7. **Hand-set beyond.** On Wave 1's tracker set Matrix to Done: the dialog warns "Matrix is not complete (… of 3 markets)"; cancel (nothing changes); set again and confirm: the row shows Done with the "Set by hand" chip and the warning line. Now complete the real matrix everywhere: the chip disappears (the system took the row back).
8. **Hand-set below and equal.** Set Concepts by hand lower than its real state: it shows "Set by hand"; change any concept: the system takes it back.
9. **Wave 3 hand-set rows.** Open its tracker with strategy ON: Done rows show "Set by hand" with the warning; the landing opens the real first step (USPs); the Brand page bar still follows the stored rows.
10. **Creators and Scripts** stay hand-set: set one: no confirm, no chip.
11. **Notes.** On USPs add a note: it shows your name and date; edit it ("edited" appears); add one as staff and as manager; staff cannot edit or delete the manager's; the manager can delete the staff note but cannot edit it; a note with `<script>alert(1)</script>` shows as text; each market tab has its **own** thread; the matrix opens the notes from "Notes (n)".
12. **Former member.** Remove a member who wrote a note (membership removed): their note shows "Former member", no name; only an admin or manager can delete it.
13. **Strategy OFF then ON.** On Wave 4 turn strategy OFF (by hand, in `pl25_walk`), change its markets and hand-set a row, turn it ON: the Brand page still shows the PL-1 rows; open the wave: the rows are now brought in line.
14. **Strategy OFF.** The tracker is hand-set exactly as PL-1: no chip, no "n of m", no warning, no entry button.
15. **Second agency.** As agency B, open a ulid of agency A: not found; agency B's note is invisible to A.
16. **Narrow screen (390).** The stepper scrolls inside itself, the Notes card is below the step at full width, dialogs fit, no sideways scroll.
17. **Client and creator.** Signed in as a creator, no notes or completion route answers (403 / 404).
18. **Cleanup:** `Pl25WalkCleanupSeeder`, then drop `pl25_walk`. The flag state is reported, not changed.

---

## 11. Rulings on the plan (Pedram) and the gaps closed

None yet. This section is filled after the plan is read. The points that need a ruling are below, each with the recommendation the plan was written on.

### Questions as asked

| #      | Question                                                                                                                                    | Recommendation (used above)                                                                                                                                                                            |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Q1** | K1 says the mark "does not block". Should marking with **no data at all** (no USP; a market with no persona or no cell) be allowed?         | Refuse (422 `strategy.nothing_to_complete`): it keeps "not started means no data" (N1) and lets a mark be dropped when the data is emptied. Warnings (under four, under two, cells unset) never block. |
| **Q2** | Should a mark show "changed since marked complete"?                                                                                         | No: it needs a fingerprint of the data; live warnings cover the cases in 1.4.                                                                                                                          |
| **Q3** | Where do notes open on the matrix (the frame draws only "Notes (n)")?                                                                       | A side sheet with the same amber card; the other three steps use the 300 px column.                                                                                                                    |
| **Q4** | Where does the entry open when all four steps are done?                                                                                     | Concepts, first market.                                                                                                                                                                                |
| **Q5** | Who may mark complete and reopen?                                                                                                           | All three agency roles (as every strategy write); audited with the actor.                                                                                                                              |
| **Q6** | A hand-set status **below** the real one (F1 row 4): kept as hand-set until the real step next changes, then taken back. Right?             | Yes: the system takes a row back only when the real step reaches or passes it; any change at or above counts.                                                                                          |
| **Q7** | Stepper: keep the PL-2.1 box values (pad 10 / 14, gap 12, status 12) or move to frame 13:4412 (pad 10 / 12, gap 10, 11.5)?                  | Keep the walked PL-2.1 values; list the difference as VISUAL.                                                                                                                                          |
| **Q8** | Removing a market that has notes or marks: delete them with the market (recommended) or refuse?                                             | Delete with the market, counts in the audit.                                                                                                                                                           |
| **Q9** | The Brand page and wave list do not run the catch-up, so after turning strategy ON they show PL-1 rows until a wave is opened once. Accept? | Accept, stated in the walk and review.                                                                                                                                                                 |

## 12. Size estimate and build order

About 3 to 4 days of build, plus a day of walking and fixes. Order: (1) migrations, models, registry and audit keys with their tests; (2) `StrategyStepState` and the F1 table in `StrategyTrackerSync` and `WaveStrategyRowService`, with B4, B5, B10, B12, B13 first (shared tracker code, the risk); (3) the hook in each service and B9; (4) completion endpoints and summary, landing; (5) notes endpoints, policy, arch tests; (6) web: stepper, entry and landing, tracker; (7) mark button, notes panel, widths; (8) i18n, specs, fidelity measurements, walk seeders; (9) local gates and the walk script.

## 13. Corrections and rulings log

| Date       | Entry                                                                                                                                                                                                      |
| ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2026-11-22 | Plan written from rulings K1 to K4, F1, N1 to N4. Frames read: 13:428, 13:828, 13:1375, 13:4412 (stepper states and notes panel read node by node). Code read from `b2b4d113`. Nothing built, nothing run. |
