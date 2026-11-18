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
| PL-2   | Strategy              | L    | 5      | USPs; personas; messaging matrix (by hand); concepts; versions, gate 1 and approval recorded on the client's behalf                                                                                                                                                                                                   | Strategy 02 to 05              |
| PL-3   | Creators and scripts  | L    | 5      | Shortlist model; creator picker; script model; script editor (hooks, lines, shots); two languages and versions                                                                                                                                                                                                        | Strategy 06 to 08              |
| PL-4   | Portal access         | XL   | 6      | Client sign-in backend; tenancy, audit and rate limits; client deletion path; portal shell and joining pages; questionnaire; Home, Files and Team                                                                                                                                                                     | Portal P01 to P05, P07 to P09  |
| PL-5   | Client approvals      | L    | 5      | Approval records and notifications; comments; USPs, personas and matrix screens; concepts, creators and scripts screens; portal wave tracker                                                                                                                                                                          | Strategy 10, 11; Portal P06    |
| PL-6   | Hand-off and footage  | XL   | 7      | Gate 2 and automatic brief; deadlines and reminders; large resumable upload; thumbnails and video details; "My scripts" with clip slots; phone upload plugin and app build; footage review per clip                                                                                                                   | Strategy 12a to 12j, 13        |
| PL-7   | Video editing         | L    | 6      | Editor role and guards; editor shell; jobs, brief and open pool; upload final ads; monthly deliverables and batch; revision round                                                                                                                                                                                     | Video 01 to 03, 05 to 07       |
| PL-8   | Ad review and library | XL   | 6      | Streaming player; timed comments; drawing; V1 to V2 loop; downloads and export; Ads library                                                                                                                                                                                                                           | Video 12, 14 to 17; Portal P10 |
| PL-9   | AI                    | L    | 5      | Provider, cost limit and run log; USP drafts and matrix; concept and script drafts; translation; subtitle check                                                                                                                                                                                                       | Strategy 04b; Video 04         |

Total: 48 chunks (PL-1 is 3 chunks since 2026-11-17).

**Flags.** Each sprint ships behind its own flag (strategy, portal, editing, AI), default OFF. **Standing rule (2026-11-17):** a flag belongs to one sprint and is never reused by a later one, so a deploy never switches new work on. PL-1 uses `waves_enabled`; PL-2 and every later sprint get their own flag, named when that sprint is planned.

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

## 3. What follows from the rulings

- **CI** runs on pushes to `main` and `pl`, and on pull requests into either, all four jobs (Backend, Frontend, E2E main, E2E admin). Commitlint runs on pull requests into either.
- **One flag per sprint, never reused (2026-11-17).** Each Production Line sprint ships behind its own flag, default OFF, and a later sprint never reuses an earlier sprint's flag. PL-1 is `waves_enabled`. A new flag must be listed in `AdminFeatureFlagController::FLAGS` (a test fails otherwise) and in `docs/feature-flags.md`.
- **No push without Pedram's word**, on either branch.
- **Deploys** still come only from `main` (via the mirror and the colleague). A push to `pl` deploys nothing; staging is the only environment that may run `pl`, and only once it exists.
- **Production-data standard (§5.40) applies on `pl` unchanged:** schema-only migrations, data changes as separate dry-run-able commands, a "Production posture" section in every chunk review, and the alarm line before code. A migration on `pl` reaches production only when `pl` is merged into `main` and deployed, so the sprint-boundary merge carries the snapshot and one-shot-command obligations of every migration in the merged sprints.
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

**Status (2026-11-18): PL-1.3 inventory and plan written, not built.** Open questions Q1 to Q12 are in `pl-1-3-plan.md` §11; the build waits for Q1, Q2, Q3 and Q9.

### PL-1 chunks (three)

- **PL-1.1 Brand and new wave.** The wave object (brand, name, go-live date, markets, optional campaign link), the Brand page (details, client files, waves table) and the New wave dialog. Frames 01 and 09. **Status (2026-11-17): built and walked (Pedram, local, 14 of 14 steps, including the first real-storage upload). Not merged, not deployed.** Review: `pl-1-1-review.md`.
- **PL-1.2 Campaign for a wave.** The create option, the link shown on both sides, the new board. Design: none (built from the design system and existing board components, Q10). **Status (2026-11-18): built and walked (Pedram, local, steps 1 to 16, after three fixes D1 to D3); not merged, not deployed.** No deploy-log entry.
- **PL-1.3 Wave project tracker.** Streams, steps table, owners and dates. Frame 01b, read from Figma in pieces. **Status (2026-11-18): inventory and plan written (`pl-1-3-inventory.md`, `pl-1-3-plan.md`), not built.**

### Open items from these rulings

- **PL-3 (2026-11-18):** the origin of a wave-board card (application or invite) is derived from accepted application rows, not stored. The one misread case, an applicant later invited separately, has no path today. The creator picker reads the same data, so PL-3 must decide whether the origin is stored once a second path to a card exists.
- **PL-6 (Q15, 2026-11-18):** the creator's "Submit your draft" form still appears on a for_wave campaign (left as is in PL-1, Q9). PL-6 replaces it with the footage hand-in. `waves_enabled` must not be turned on in production for real creators before that is decided.
- R8: the "release creator" action; PL-3 or PL-5 not decided.
- Docs that still say a wave is a campaign kind. Dated addenda of 2026-11-17 now sit in `production-line-inventory.md` §A5 and §N, and in `new-sections-design-review.md` §9 ruling 4. Closed review files stay verbatim (`WORKING-PROCESS.md` §8); the correction is a dated addendum.
