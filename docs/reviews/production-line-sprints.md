# Production Line: sprints PL-1 to PL-9 and how the `pl` branch works

**Date:** 2026-11-17. **Status:** sprint table and rulings recorded. Counts are estimates; each sprint's plan fixes them before any code.
**Scope:** the three new sections: Strategy & script writing, Video editing, Client portal. All three are built together (design review §9, ruling 9).
**Sources:** `new-sections-design-review.md` (§9 holds Pedram's design rulings of 2026-11-13), `production-line-inventory.md` (§N sizing), the sprint table (Claude and Pedram, 2026-11-13) and the rulings below (Pedram, 2026-11-17).

**PROD-DATA RISK: NONE** for this file and the branch setup (docs and CI config only).

---

## 1. The nine sprints

Sizes and chunk counts are estimates. Each sprint's plan fixes them before any code.

| Sprint | Name                  | Size | Chunks | Scope                                                                                                                                                                                               | Frames                         |
| ------ | --------------------- | ---- | ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------ |
| PL-1   | Brand and wave        | M    | 4      | Campaign kind (wave) and markets; wave board columns; Brand page and brand files; New wave and Wave tracker (Statics and Stills tracked only)                                                       | Strategy 01, 01b, 09           |
| PL-2   | Strategy              | L    | 5      | USPs; personas; messaging matrix (by hand); concepts; versions, gate 1 and approval recorded on the client's behalf                                                                                 | Strategy 02 to 05              |
| PL-3   | Creators and scripts  | L    | 5      | Shortlist model; creator picker; script model; script editor (hooks, lines, shots); two languages and versions                                                                                      | Strategy 06 to 08              |
| PL-4   | Portal access         | XL   | 6      | Client sign-in backend; tenancy, audit and rate limits; client deletion path; portal shell and joining pages; questionnaire; Home, Files and Team                                                   | Portal P01 to P05, P07 to P09  |
| PL-5   | Client approvals      | L    | 5      | Approval records and notifications; comments; USPs, personas and matrix screens; concepts, creators and scripts screens; portal wave tracker                                                        | Strategy 10, 11; Portal P06    |
| PL-6   | Hand-off and footage  | XL   | 7      | Gate 2 and automatic brief; deadlines and reminders; large resumable upload; thumbnails and video details; "My scripts" with clip slots; phone upload plugin and app build; footage review per clip | Strategy 12a to 12j, 13        |
| PL-7   | Video editing         | L    | 6      | Editor role and guards; editor shell; jobs, brief and open pool; upload final ads; monthly deliverables and batch; revision round                                                                   | Video 01 to 03, 05 to 07       |
| PL-8   | Ad review and library | XL   | 6      | Streaming player; timed comments; drawing; V1 to V2 loop; downloads and export; Ads library                                                                                                         | Video 12, 14 to 17; Portal P10 |
| PL-9   | AI                    | L    | 5      | Provider, cost limit and run log; USP drafts and matrix; concept and script drafts; translation; subtitle check                                                                                     | Strategy 04b; Video 04         |

Total: 49 chunks.

**Flags.** Each sprint ships behind its own flag (strategy, portal, editing, AI), default OFF.

**Missing designs that block a sprint**

- The client screens for USPs, personas and the matrix (blocks PL-5).
- The frame 13 "Send to client" change (blocks PL-6).
- The editor access path (blocks PL-7).

**Colleague lead-time items:** the AWS work for large uploads, thumbnails and streaming (PL-6, PL-8).

**Earlier decisions carried in**

- A wave is a campaign with a new kind, and the normal assignment is created only at hand-off.
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
