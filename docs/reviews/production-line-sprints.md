# Production Line: sprints PL-1 to PL-9 and how the `pl` branch works

**Date:** 2026-11-17. **Status:** sprint table and rulings recorded. Counts are estimates; each sprint's plan fixes them before any code.
**Scope:** the three new sections: Strategy & script writing, Video editing, Client portal. All three are built together (design review §9, ruling 9).
**Sources:** `new-sections-design-review.md` (§9 holds Pedram's design rulings of 2026-11-13), `production-line-inventory.md` (§N sizing), the sprint table (Claude and Pedram, 2026-11-13) and the rulings below (Pedram, 2026-11-17).

**PROD-DATA RISK: NONE** for this file and the branch setup (docs and CI config only).

---

## 1. The nine sprints

Sizes and chunk counts are estimates. Each sprint's plan fixes them before any code.

| Sprint | Name                  | Size | Chunks | Scope                                                                                                                                                                                                                                                                                                                 | Frames                         |
| ------ | --------------------- | ---- | ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------ |
| PL-1   | Brand and wave        | M    | 3      | Wave object (brand, name, go-live date, markets, optional campaign link); Brand page, client files and waves table; New wave dialog; campaign for a wave, link on both sides and the new board; Wave project tracker (Statics and Stills tracked only). Chunks PL-1.1 to PL-1.3, see the design rulings of 2026-11-17 | Strategy 01, 01b, 09           |
| PL-2   | Strategy              | L    | 4      | USPs and personas by hand; messaging matrix by hand; concepts with approval recorded on the client's behalf; connecting it up (see below)                                                                                                                                                                             | Strategy 02 to 05              |
| PL-3   | Creators and scripts  | L    | 5      | Shortlist model; creator picker; script model; script editor (hooks, lines, shots); two languages and versions                                                                                                                                                                                                        | Strategy 06 to 08              |
| PL-4   | Portal access         | XL   | 6      | Client sign-in backend; tenancy, audit and rate limits; client deletion path; portal shell and joining pages; questionnaire; Home, Files and Team                                                                                                                                                                     | Portal P01 to P05, P07 to P09  |
| PL-5   | Client approvals      | L    | 5      | Approval records and notifications; comments; USPs, personas and matrix screens; concepts, creators and scripts screens; portal wave tracker                                                                                                                                                                          | Strategy 10, 11; Portal P06    |
| PL-6   | Hand-off and footage  | XL   | 7      | Gate 2 and automatic brief; deadlines and reminders; large resumable upload; thumbnails and video details; "My scripts" with clip slots; phone upload plugin and app build; footage review per clip                                                                                                                   | Strategy 12a to 12j, 13        |
| PL-7   | Video editing         | L    | 6      | Editor role and guards; editor shell; jobs, brief and open pool; upload final ads; monthly deliverables and batch; revision round                                                                                                                                                                                     | Video 01 to 03, 05 to 07       |
| PL-8   | Ad review and library | XL   | 6      | Streaming player; timed comments; drawing; V1 to V2 loop; downloads and export; Ads library                                                                                                                                                                                                                           | Video 12, 14 to 17; Portal P10 |
| PL-9   | AI                    | L    | 5      | Provider, cost limit and run log; USP drafts and matrix; concept and script drafts; translation; subtitle check                                                                                                                                                                                                       | Strategy 04b; Video 04         |

Total: 47 chunks (PL-1 is 3 chunks since 2026-11-17; **PL-2 is 4 chunks since 2026-11-19**, was 5).

**PL-2 chunks (Pedram, 2026-11-19; the plan of PL-2.1 is `pl-2-1-plan.md`):**

| Chunk  | Name                         | Scope                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| ------ | ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PL-2.1 | Workspace, USPs and Personas | The Strategy & Scripts workspace (header, six-step stepper), step 1 USPs (one set per wave, drag order, six fixed colours), step 2 Personas (per market, 2 to 8, angles linked to USPs by hand, copy from another market). All by hand. **Built and walked locally by Pedram (2026-11-19, fidelity fixes accepted). Not merged into `main`, not deployed; no deploy-log entry.**                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| PL-2.2 | Messaging matrix by hand     | The matrix (rows are the USPs in order, per market and persona), typed by hand. **Ruling change 2026-11-20 (Pedram, after the local walk; amends X7):** a manual angle and a cell for the same persona and USP may not sit with two different texts unseen. R1 an empty message line is prefilled from the manual angle when a cell is opened (never overwrites; the angle is not changed). R2 "Use the matrix line" on a Priority or Support cell whose text differs: confirmation showing both texts, then the angle becomes the synced one, one transaction under the wave lock, audited, all three roles; equal texts show nothing. R3 the persona card marks a differing manual angle with a link to the cell. R4 a cell save alone never changes a manual angle. Case table rows 25 to 32 in `pl-2-2-plan.md`; tech-debt row 45 (matrix colours into `@catalyst/design-tokens`). **Built and walked locally by Pedram (2026-11-20 and 2026-11-21; fixes accepted, the hint colour changed to red). Not merged into `main`, not deployed; no deploy-log entry.** |
| PL-2.3 | Concepts                     | Concepts with approval recorded on the client's behalf; Creators and Scripts stay locked until all concepts are approved.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                             |
| PL-2.4 | Connecting it up             | F1: the tracker rows are set by the real steps, with a warned manual override. F2: the markets roll-up. F3: a wave opens on the first incomplete step. Internal notes per step.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |

**Flags.** Each sprint ships behind its own flag (strategy, portal, editing, AI), default OFF. **Standing rule (2026-11-17):** a flag belongs to one sprint and is never reused by a later one, so a deploy never switches new work on. PL-1 uses `waves_enabled`; **PL-2 uses `strategy_enabled` (named in `pl-2-1-plan.md` §5, all four PL-2 chunks ship behind it; it needs `waves_enabled`)**; every later sprint gets its own flag, named when that sprint is planned.

**Missing designs that block a sprint**

- The client screens for USPs, personas and the matrix (blocks PL-5).
- The frame 13 "Send to client" change (blocks PL-6).
- The editor access path (blocks PL-7).

**Colleague lead-time items:** the AWS work for large uploads, thumbnails and streaming (PL-6, PL-8).

**Earlier decisions carried in**

- ~~A wave is a campaign with a new kind~~ **superseded 2026-11-17**: a wave is its own object linked to a campaign (R1 below). The normal assignment is still created only at hand-off.
- The strategist picks the month when sending a batch.
- Every step works by hand before AI is added.

**Heavy pieces (inventory §N2):** the video pipeline; the third identity (client portal member); the strategy data model and approval engine; the AI layer; frame-accurate review and the monthly batch.

**Design rulings in force (design review §9, Pedram, 2026-11-13):** the design is the source of truth over the brief; the client approves USPs, personas and the matrix; the client reviews finished ads only; a wave is a campaign, probably (to confirm by inventory); a month can hold two waves (the monthly batch is its own object); Statics and Stills are tracked only; editors are agency staff; more than 3 scripts per creator allowed; all three sections together; no portal on phones for the first release; one client portal only (share-link sign-in screens are not built).

---

## 2. Rulings (Pedram, 2026-11-17)

1. **Branches.** `main` is production; `pl` holds PL-1 to PL-9. Fixes to the live product go on `main` and are merged into `pl` **at least weekly**.
2. **Per chunk (local).** Tests for the code being built, plus Pint, Larastan, vue-tsc and lint, run locally. **The full suite runs on CI at every push to `pl` and must be green at the pushed tip** (`PROJECT-WORKFLOW.md` §5.41).
3. **Pedram walks every chunk locally on his Mac.**
4. **At each sprint end:** the full local suite; the agency and creator walk baselines (**126** and **146**); a walk on staging; and the phone walk. The baselines also run **earlier** if a chunk changes a shared screen.
5. **`pl` into `main`.** Only at a sprint boundary, on Pedram's word, after one or more complete sprints, **with every new flag OFF**.
6. **Staging.** Set up by the colleague when Pedram asks, **by the end of the first sprint at the latest**. **Test data only, never a copy of production.** Sentry on staging reports with environment **`staging`**.
7. **Naming.** Chunks are named **`PL-<sprint>.<n>`**. AH numbers stay for ad-hoc work on `main`.
8. **Approval on the client's behalf.** The strategist may record a client approval, with **their name and a note saved on the record**.
9. **Plain-words brief before every sprint and chunk.** Before each sprint and each chunk, Claude gives Pedram a plain-words brief of what is being built: who uses it, what they see, what changes behind the scenes, what he will check on the walk, and what is left out. **No Cursor prompt for the chunk is issued until Pedram has read the brief and said go.**
10. **Design fidelity (Pedram, 2026-11-19).** For every screen that has a design frame, before a walk is offered: read the frame with `get_design_context`, open the built screen in a real browser at the frame's width, and put a difference table (element, frame value, built value, VISUAL or FUNCTIONAL) in the review file. Visual differences are fixed. Functional differences are reported to Pedram and left as they are until he rules. "Matches the design" is never written without that table. Screens with no frame are named as built from the design system.

## 3. What follows from the rulings

- **CI** runs on pushes to `main` and `pl`, and on pull requests into either, all four jobs (Backend, Frontend, E2E main, E2E admin). Commitlint runs on pull requests into either.
- **One flag per sprint, never reused (2026-11-17).** Each Production Line sprint ships behind its own flag, default OFF, and a later sprint never reuses an earlier sprint's flag. PL-1 is `waves_enabled`. A new flag must be listed in `AdminFeatureFlagController::FLAGS` (a test fails otherwise) and in `docs/feature-flags.md`.
- **No push without Pedram's word**, on either branch.
- **Deploys** still come only from `main` (via the mirror and the colleague). A push to `pl` deploys nothing; staging is the only environment that may run `pl`, and only once it exists.
- **Production-data standard (§5.40) applies on `pl` unchanged:** schema-only migrations, data changes as separate dry-run-able commands, a "Production posture" section in every chunk review, and the alarm line before code. A migration on `pl` reaches production only when `pl` is merged into `main` and deployed, so the sprint-boundary merge carries the snapshot and one-shot-command obligations of every migration in the merged sprints.
- **PL-1 migrations at the sprint-boundary merge (added 2026-11-18).** Six migrations, in order. All are
  schema-only and move no row. Locks measured on a synthetic PostgreSQL database with rows in the live tables
  (the Ms are the transaction time of `up()`); each sets `SET LOCAL lock_timeout = '5s'` on PostgreSQL.
  Creating a table with foreign keys takes a ShareRowExclusiveLock on each referenced table: writes wait, reads
  do not. `ADD COLUMN` takes an AccessExclusiveLock: reads and writes wait, for well under 3 ms here.

  | Migration                                             | Lock on a live table                                            | Time     |
  | ----------------------------------------------------- | --------------------------------------------------------------- | -------- |
  | `2026_11_17_100000_create_waves_table`                | ShareRowExclusive on `agencies`, `brands`, `campaigns`, `users` | 29.19 ms |
  | `2026_11_17_100001_create_brand_files_table`          | ShareRowExclusive on `agencies`, `brands`, `users`              | 9.84 ms  |
  | `2026_11_18_100000_add_campaign_type_to_campaigns`    | AccessExclusive on `campaigns` (default, no rewrite)            | 2.30 ms  |
  | `2026_11_18_100001_add_stage_key_to_board_columns`    | AccessExclusive on `board_columns`                              | 2.02 ms  |
  | `2026_11_19_100000_create_wave_steps_table`           | ShareRowExclusive on `agencies`, `users`, `waves`               | 11.67 ms |
  | `2026_11_19_100001_add_steps_provisioned_at_to_waves` | AccessExclusive on `waves`                                      | 2.04 ms  |

  Run them at a quiet moment, after a fresh snapshot. If a `lock_timeout` fires, the migration aborts cleanly
  (each runs in one transaction, so nothing is half-applied): wait for the blocking transaction to finish and
  re-run `php artisan migrate`. Never raise the timeout to push through.

- **Merging `main` into `pl`** is routine; each merge is a normal commit on `pl`, CI must be green at its tip, and conflicts in the tenancy allowlist, `AuditAction` and its test list, and the i18n files are expected hot spots.
- **Client-approval records** carry the approver's name and, when recorded by the strategist for the client, the strategist's name and a note. This touches the erasure registry and audit design (inventory §N3); it needs a disposition per table before the first portal chunk is planned.

---

## 4. Staging setup list (for the colleague, when Pedram asks)

Nothing here is started. It is written down now so the first-sprint deadline (ruling 6) has a list to work from. None of it edits a runbook or the deploy-log template yet.

**Environment**

- Runs `pl` only. **Test data only, never a copy of production.** Its own database, queue worker, scheduler and erasure worker, supervisor config, and S3 buckets.
- Its own `APP_KEY` and `TEST_HELPERS_TOKEN`.
- APNs and push set to sandbox or off; mail sent to a catcher, not to real addresses.
- Both SPAs on their own Netlify sites with their own env sets.

**Sentry**

- API: `SENTRY_ENVIRONMENT=staging` in the box `.env`. Its own DSN or project, or at least an environment filter.
- Web: the SPA environment is Vite's `MODE`, so a plain `vite build` reports `production`. The staging sites must build with `--mode staging`, or the code needs an env override (a code change, to be planned). The native build maps only `native-production` to `production`.
- Release names derive from tree hashes and carry no branch name, so they need no change.
- The existing issue alert and uptime monitor are production-only; staging errors notify nobody until a staging rule is added.

**The mirror (`production-queue-worker.md` ~L471-481)**

- Decide whether `pl` is mirrored to Bitbucket and who deploys it.
- Whether the mirror rewrites SHAs is still unverified (tech-debt row 39).
- The mirror must never fast-forward production from `pl`.

## 5. Open items: docs that still assume one branch

Recorded, not edited now (Pedram, 2026-11-17).

- **`docs/reviews/RESUMPTION-TEMPLATE.md`, Part 2:** carries a single HEAD SHA and `origin/main` as the authority. It needs a per-branch line for `main` and `pl` (tip, pushed or not, CI run at the tip).
- **`docs/07-TESTING.md` §9:** "On merge to main: same as PR + deploy to staging" and the staging E2E wording. Rewrite to "staging runs `pl`, test data only; production deploys come from `main`".
- **`docs/runbooks/local-dev.md` (the environments table, ~L53):** the staging row. Rewrite when staging exists.
- **Runbooks** (`deploy-ah*.md`) hard-code `origin/main` in the pull and fast-forward steps. The next runbook should name the branch; deploys stay `main` only.
- **Deploy-log template:** says production is the only environment. Staging needs its own log or an `Environment:` field; pushes to `pl` are never recorded as deploys.

---

## 6. Design rulings 2026-11-17

Pedram, 2026-11-17. These **supersede any earlier note that "a wave is a kind of campaign"** (design review §9 ruling 4, inventory §A5 and §N, and the sprint table of 2026-11-13). Record only; no code.

1. **R1. A wave is its own object.** It links one-to-one to a campaign of the same brand. A campaign has at most one wave; a wave has at most one campaign.
2. **R2. Split of work.** The campaign does recruiting: job post, applications, invites, offer, contract, chat, board. The wave owns strategy, scripts, the project tracker and client approvals.
3. **R3.** A campaign can be created "for a wave". In such a campaign the creator does not post content.
4. **R4.** The New wave dialog offers an existing unlinked campaign of that brand, or "create a campaign for this wave now". The link is optional when the wave is created. It is **required by the creators step**. **Hand-off is blocked without it.**
5. **R5.** The wave's creator picker shows only creators who applied to the linked campaign's job post or were invited to that campaign.
6. **R6.** Creator status in the wave is derived from the campaign: Shortlisted, Contacted (invited), Confirmed (accepted), Declined.
7. **R7.** Hand-off sends approved scripts to a creator who has already accepted.
8. **R8. OPEN.** New need: a "release creator" action. Sprint not decided (PL-3 or PL-5).
9. **R9. Board for a campaign linked to a wave.** Columns, in order: **Applied, Invited, Selected, Filming, Submitted / in review, In edits, Client review, Approved, Dropped.** All columns exist from the start.
   - Invited cards show a status: Invited, Accepted or Declined. An Accepted card stays in Invited until the strategist selects the creator.
   - One card per creator. The card carries a per-script tracker.
   - The card's column follows the slowest script.
   - Paid and Payment due are left off until payments exist.
   - Existing campaigns keep their current columns.
   - A creator who declines stays in Invited with the Declined label. The card does not move to Dropped. Dropped is for creators the team removes or rejects.
10. **R10.** Where a screen has no design, build from the existing design system and name that in the plan and the report.

### PL-1.2 rulings (Pedram, 2026-11-17)

Recorded with the PL-1.2 plan (`pl-1-2-plan.md`). They sharpen R3, R4 and R9; **P2 amends PL-1.1** (see the note at the end).

- **P1.** A campaign has a type: normal, or for a wave. In a for-a-wave campaign the creator does not post content (R3).
- **P2.** Only for-a-wave campaigns can be linked to a wave. The campaign dropdown and the server rule refuse a normal campaign, with the same 422 `wave.campaign_not_available` and the same body.
- **P3.** The New wave dialog gains "Create a campaign for this wave now": the user gives a campaign name; the wave and a for-a-wave campaign are created together, linked, in one transaction. The rest of the campaign is filled in later on the campaign page.
- **P4.** The link is shown on both sides: the wave page shows its campaign, the campaign page shows its wave.
- **P5.** Changing or removing the link is allowed only while no creator of the campaign is Selected or further. After that the link is locked.
- **P6.** Board for a for-a-wave campaign, columns in order: Applied, Invited, Selected, Filming, Submitted / in review, In edits, Client review, Approved, Dropped. All nine exist from the start. One card per creator. Invited cards carry a label: Invited, Accepted or Declined; Accepted and Declined cards stay in Invited; Declined never moves to Dropped by itself; Dropped is for creators the team removes or rejects. The card has a place for the per-script tracker, empty until scripts exist (PL-3), with no dead UI. Filming to Approved: the strategist moves cards by hand in this chunk; later sprints automate each move. No Paid or Payment due columns.
- **P7.** Existing campaigns keep their type (normal) and their boards exactly as they are.
- **P8.** A model-level guard on `Campaign` refuses a change of `brand_id` (deferred from PL-1.1). This chunk may edit the Campaigns module.
- **P9.** Deleting a wave stays out.

**Plan rulings Q1 to Q12 (Pedram, 2026-11-17)** are recorded in `pl-1-2-plan.md` §11. Three matter beyond PL-1.2: **Q3** the P5 lock reads where cards are now, not history (locked while a card sits in Selected, Filming, Submitted / in review, In edits, Client review or Approved); **Q4** only an Accepted creator's card can enter a working column, any card can be moved to Dropped, moves back are free; **Q10** the board is built from the design system with no screenshot, like a ClickUp board, and the card face carries only the creator name, the invite label and the reserved script-tracker place.

**Q11 CONFIRMED (2026-11-18):** production is PostgreSQL 17.9 on aarch64 (Aurora). PL-1.2's `ADD COLUMN … DEFAULT <constant>` on `campaigns` uses the fast default and does not rewrite the table. Source: the colleague's read-back, relayed by Pedram; not read by Cursor.

**Amendment to PL-1.1 (by P2 and P5):** PL-1.1 let a wave link to any campaign of the brand and locked the link once set. Under P2 the link accepts only a for-a-wave campaign; under P5 and Q3 the link is locked only while a card sits in a working column (Selected or later), not once a link is set. PL-1.1's tests, seeder and dialog change in PL-1.2 (list in `pl-1-2-plan.md` §6). PL-1.1 as built and walked is not edited retroactively.

### PL-1.3 rulings (Pedram, 2026-11-18)

Recorded with the PL-1.3 plan (`pl-1-3-plan.md`). Binding with R1 to R10 and P1 to P9.

- **T1.** A new wave starts with a ready-made list of steps: the standard production-line steps of frame 01b. The strategist can edit, add and remove steps. Waves that already exist get the same list on first open, not by a data-writing migration.
- **T2.** Owners are agency team members only. No client owners (PL-4).
- **T3.** Admin and manager add and remove steps. Admin, manager and staff update a step's status, owner and dates.
- **T4.** Everything is updated by hand in PL-1.3. Later sprints tick steps automatically; a step is found by a stable key, never by its name.
- **T5.** No notifications or reminders to owners. No calendar or timeline view unless frame 01b has one; if it has, report it and leave it out pending a ruling. (Frame 01b has none; it has a day-grouped "Due this week" list, left out.)
- **T6.** Screens with no design (edit a step, add, remove, empty, loading, error) are built from the existing design system and named in the plan.

**Plan rulings Q1 to Q12 and the stream ruling (Pedram, 2026-11-18)**, full table in `pl-1-3-plan.md` §11:

- **Q1.** The Brand page's six Progress segments are the six strategy steps ("6-segment progress", developer brief), fed by six tracker steps, **not** by a ratio of all steps.
- **Q2.** UGC video has 13 steps; steps 2 to 7 are the six strategy steps under their own names (USPs, Personas, Messaging matrix, Concepts, Creators, Scripts; keys `ugc.usps` … `ugc.scripts`). Stills step 2 is "Shoot prep". Default names are stored in English. Segment n is filled when the nth strategy step is done; the label is the first strategy step not done, "Complete" when all six are done, "Not started" when none has started. The six can be renamed but not removed and not moved to another stream; every other step can be removed.
- **Q3.** Delayed is derived from the due date only.
- **Q4.** Owner is an agency team member or the label "Client" (a word on the step: no account, no access, no notification); the picker lists the members plus "Client"; client-owned steps in the frame default to "Client"; PL-4 may replace the label with a real person.
- **Q5, Q6, Q7.** Left out: "Open in", stream footer links, the right column, header buttons, other tabs, "What needs fixing", "Due this week".
- **Q8.** Rename a step or move it to another stream: admin and manager. **Q9.** The details card stays above the tracker. **Q10.** No reordering. **Q11.** Last write wins per field; 404 for a removed step. **Q12.** "Today" is the requesting user's day.
- **New.** Remove a whole stream in one action (admin and manager), with a confirm. The UGC video stream cannot be removed (the Brand page bar depends on it). A removed stream returns only through an explicit Add stream action, never through the first-open marker.

**Source note.** The developer brief ("Strategy & Script Writing Developer Brief v2", relayed by Pedram, not read by Cursor) describes the six-step workspace and the 6-segment progress. It does not mention the project tracker, streams, Statics or Stills; those come from Figma frame 01b only.

**Status (2026-11-19): PL-1.3 built and walked (Pedram, local, steps 1 to 16, then two fixes D1 and D2); not merged, not deployed.** PL-1 has all three chunks built and walked. **The sprint close-out (full local suite, full agency and creator baselines) has NOT been run.** No open questions remain.

### PL-1 chunks (three)

- **PL-1.1 Brand and new wave.** The wave object (brand, name, go-live date, markets, optional campaign link), the Brand page (details, client files, waves table) and the New wave dialog. Frames 01 and 09. **Status (2026-11-17): built and walked (Pedram, local, 14 of 14 steps, including the first real-storage upload). Not merged, not deployed.** Review: `pl-1-1-review.md`.
- **PL-1.2 Campaign for a wave.** The create option, the link shown on both sides, the new board. Design: none (built from the design system and existing board components, Q10). **Status (2026-11-18): built and walked (Pedram, local, steps 1 to 16, after three fixes D1 to D3); not merged, not deployed.** No deploy-log entry.
- **PL-1.3 Wave project tracker.** Streams, steps table, owners and dates. Frame 01b, read from Figma in pieces. **Status (2026-11-19): built and walked (Pedram, local, steps 1 to 16, after two fixes D1 and D2); not merged, not deployed.** No deploy-log entry. Review: `pl-1-3-review.md`.

### Open items from these rulings

- **PL-2 rulings (Pedram, 2026-11-18; not built in PL-1.3, the data model does not block them):**
  - **F1.** From PL-2 the six strategy rows are set automatically by the real strategy screens. The strategist can still set one by hand. When the hand-set status goes beyond what the real screen says (for example marked done while concepts are not all approved), the system warns that the step is incomplete, lets the user confirm, and shows the row as set by hand, so a wave is never stuck on one step. PL-1.3 stores `status_source` (`system` or `manual`) on each step row for this.
  - **F2.** Steps 2 to 6 of the strategy workspace are per market. PL-2 must rule how several markets roll up into one tracker row.
  - **F3.** A wave opens on the first incomplete strategy step (developer brief). PL-2 must rule how the tracker and the six-step workspace share the wave page.
- **PL-3 (2026-11-18):** the origin of a wave-board card (application or invite) is derived from accepted application rows, not stored. The one misread case, an applicant later invited separately, has no path today. The creator picker reads the same data, so PL-3 must decide whether the origin is stored once a second path to a card exists.
- **PL-6 (Q15, 2026-11-18):** the creator's "Submit your draft" form still appears on a for_wave campaign (left as is in PL-1, Q9). PL-6 replaces it with the footage hand-in. `waves_enabled` must not be turned on in production for real creators before that is decided.
- R8: the "release creator" action; PL-3 or PL-5 not decided.
- Docs that still say a wave is a campaign kind. Dated addenda of 2026-11-17 now sit in `production-line-inventory.md` §A5 and §N, and in `new-sections-design-review.md` §9 ruling 4. Closed review files stay verbatim (`WORKING-PROCESS.md` §8); the correction is a dated addendum.

## PL-1 close-out (local), 2026-11-19

**Not merged. Not deployed. No deploy-log entry.** Tree: `pl` at `acaa929c` (all three chunks built and walked by Pedram). `main` is at `09169b5a`, the commit `pl` branched from: **no commits on `main` are missing from `pl`**, so there was nothing to merge.

| Check                                                                         | Result                                                                                                                                                                                                                                                                                                                                                                          |
| ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Full Pest, SQLite (`-d memory_limit=2G`, serial)                              | 4,970 passed, 39 skipped (26,861 assertions)                                                                                                                                                                                                                                                                                                                                    |
| Pest on PostgreSQL: Waves, Brands, Boards, Campaigns, Audit, Erasure, Tenancy | 1,564 passed (7,938 assertions)                                                                                                                                                                                                                                                                                                                                                 |
| Full Vitest `apps/main`                                                       | **5,260 passed, 2 failed locally**: `tests/unit/native/appIconAssets.spec.ts`, "are reproduced pixel-for-pixel by the committed script" and "is deterministic: the placement function reproduces the committed iOS splash". **Failed locally, known environment difference, green on CI** (the same two fail on `main` at `09169b5a`; tech-debt row 43). Not counted as passed. |
| Full Vitest `apps/admin`                                                      | 754 passed                                                                                                                                                                                                                                                                                                                                                                      |
| Full Vitest `packages/api-client`                                             | 231 passed                                                                                                                                                                                                                                                                                                                                                                      |
| Playwright `apps/main`, serial                                                | 67 passed                                                                                                                                                                                                                                                                                                                                                                       |
| Playwright `apps/admin`, serial                                               | 2 passed (tech-debt row 32 did **not** reproduce on this run)                                                                                                                                                                                                                                                                                                                   |
| Agency walk, flag off (126 targets, `ah-115-agency-baseline`)                 | COMPARE OK: 126 identical, 0 page errors                                                                                                                                                                                                                                                                                                                                        |
| Creator walk (146 targets, `ah-115-creator-baseline`)                         | COMPARE OK: 146 identical                                                                                                                                                                                                                                                                                                                                                       |
| Pint (whole `apps/api`, `--test`)                                             | passed                                                                                                                                                                                                                                                                                                                                                                          |
| Larastan (whole `apps/api`)                                                   | no errors                                                                                                                                                                                                                                                                                                                                                                       |
| `vue-tsc` / `tsc` (main, admin, api-client, design-tokens, ui)                | clean                                                                                                                                                                                                                                                                                                                                                                           |
| ESLint (main, admin)                                                          | 0 errors; 14 and 2 warnings (`vue/one-component-per-file`)                                                                                                                                                                                                                                                                                                                      |
| Staging walk, phone walk                                                      | not run                                                                                                                                                                                                                                                                                                                                                                         |
| CI on the close-out commit                                                    | see the push report                                                                                                                                                                                                                                                                                                                                                             |

Notes: the walks' mock API carries no `waves_enabled`, so they are flag-off by construction and their DOM hash includes comment nodes. A first creator run used the older `ah-114-creator-baseline` by mistake and differed; the 146-target baseline is `ah-115-creator-baseline` (it contains the AH-120 additions), and against it the run is identical. `waves_enabled` locally: **ON** (global `true` in the local `catalyst` database); not flipped by this work.

### What still stands before a merge to `main`

1. **The staging walk:** not run (there is no staging yet).
2. **The phone walk:** not run.
3. **The PL-1 migration lock notes:** the six PL-1 migrations are measured on a synthetic PostgreSQL database (table above in this file); the quiet moment after a fresh snapshot, and the rule that a fired `lock_timeout` aborts cleanly and is re-run, never raised. Production is PostgreSQL 17.9 (Q11). Nothing is read, written or backfilled, so the PROD-DATA RISK for existing data stays none.
4. **Q15 (creator draft form):** the creator's "Submit your draft" form still appears on a for-a-wave campaign. `waves_enabled` must not be turned on in production for real creators before PL-6 decides it.
5. **Tech-debt rows opened in PL-1:** 40 (no virus scan or content check on uploads, brand client files), 41 (a NORMAL campaign can be set Active or listed with budget 0), 42 (`ErasureClaimGuardTest` memory use), 43 (`appIconAssets.spec.ts` local failure).
6. **Flag:** `waves_enabled` is default OFF everywhere that matters and is never turned on by a deploy.
