# Session Resumption — Catalyst Engine

> **How to use this file**
>
> - At each session close, **Cursor refreshes Part 2** (the CURRENT STATE block) — a one-prompt job.
> - **Pedram copies this whole file** as the first message of the next Claude (or Cursor) thread.
> - If the Claude **knowledge-base** doc copies are older than the latest push, **upload fresh ones**
>   before starting — the repo is the source of truth (see Part 1 → _Where the truth lives_).
> - **Part 1** is the stable preamble (rarely changes). **Part 2** is the only section that changes
>   per session.
>
> This template is the thing `PROJECT-WORKFLOW.md` §10 (_Switching chat sessions_) points at; the
> doc-access posture it assumes (Claude has no repo access; reads uploads / repo links / pastes) is
> defined in `PROJECT-WORKFLOW.md` §1 (_Roles_) — this file cross-references those, it does not
> restate them.

---

## Part 1 — Stable preamble (rarely changes)

### What we're building

**Catalyst Engine ("Engine C")** — a **two-sided influencer-marketing platform** for the **EU/UK**
market. It connects **agencies** and **creators**; the two sides plus the admin console are the
**three _sides_ of the platform** — never "three platforms" (one product, one data model, three
surfaces).

**Stack:**

- **Backend:** Laravel 11 (PHP), tested with **Pest**.
- **Frontend:** two Vue SPAs in a **pnpm monorepo** — `apps/main` (the **agency + creator** app,
  split by **role routing**, not two builds) and `apps/admin` (the internal admin console). Shared
  code in `packages/` (`@catalyst/ui`, `@catalyst/api-client`).
- **Data / infra:** **Postgres 16**, **Redis**, **AWS `eu-central-1`** (S3 for private media via
  presigned URLs).

### The three roles

- **Pedram** — solo dev, decision-maker, and the **relay** between the two AIs. Gives **terse
  direction** in **single consolidated Cursor prompts**. Delegates genuinely ("your call") — when he
  does, **decide with stated reasoning**, and he retains the override.
- **Cursor** — the **implementing agent**, with **full repo access**. Plans, builds, tests, commits.
- **Claude** — the **independent reviewer / architect**, with **NO repo access**. Reviews via
  uploaded files, repo links, and pasted snippets; provides architectural + security counsel.

### The chunk loop

`inventory (cite path:line)` → `kickoff (locked decisions)` → `plan-pause (no code until approved)`
→ `build` → `spot-check` → `two-commit pair (feat + docs)` → **held push** (push only on Pedram's
explicit call).

Full lifecycle in `PROJECT-WORKFLOW.md` §3; the "Q-and-A before code" shape in §6; spot-check
discipline in §7.

### Standing disciplines

- **Break-revert on every gate.** For any security/authz gate or architecture-test claim, prove it
  bites: break it, watch the test go red, revert. A gate with no failing-case proof isn't trusted.
- **Docs move with the build.** Every landed change updates its review file / `adhoc-changes-log.md`
  / `tech-debt.md` in the same pair. No silent changes.
- **24-locale i18n done-gate.** New user-facing strings → `en` → regenerate all 24 locales → parity
  green **before** the change is done. Know the **blind spots**: parity proves _keys + placeholders +
  plural shape_ exist in every locale — it can **never** prove a value isn't still English (see the
  AH-001 i18n-completeness tech-debt entry).
- **Read-first.** Read the relevant docs/code before proposing or building. Cite `path:line`.
- **E2E output goes somewhere durable, never `/tmp`.** Pipe Playwright runs into the gitignored
  `playwright-report/` (or `test-results/`) inside the app, not `/tmp` — macOS clears it, and the
  `list` reporter leaves no artifact behind, so a green run becomes unciteable within hours. Learned
  the hard way on the AH-051/052 pre-push run: the 24/24 result could not be re-pasted per-spec for
  retroactive verification because both logs were gone (only the lines quoted in-session survived).

### Where the truth lives

- **Claude knowledge base** — the uploaded doc set a review thread reads from. **Can go stale**;
  re-upload when older than the latest push.
- **`PROJECT-WORKFLOW.md`** — the master process doc. §5.x is the running list of **team standards
  established through prior chunks** (source-inspection regressions, event-fake split, dual-recipient
  notifications, allowlist discipline, etc.) — consult it before reinventing a pattern.
- **`docs/reviews/adhoc-changes-log.md`** — the **authoritative index of all ad-hoc (AH-NNN) work**.
- **`docs/tech-debt.md`** — deferred items with triggers/owners.
- **`docs/reviews/sprint-{N}-chunk-{M}-review.md`** — per-chunk decisions; **review files are
  authoritative for counts** (test totals, etc.).
- **Rule:** when the knowledge base and the repo disagree, **the repo wins** — the stale KB copy is
  the thing to fix.

### Mode guidance — how much process a change needs

- **Pure-UI / copy / polish** may **skip the full loop** (the **AH-007 pattern**) — build it, log it,
  done.
- **Schema / auth-gate / API-response-shape / i18n** changes get the **full loop** — these are the
  "not minor even when small" categories; a field added or dropped, a gate loosened, or locale parity
  left red is never minor.
- **Watch batch sprawl.** The **48-files / five-themes** lesson (the AH-013→AH-017 working tree): when
  a working tree grows into **several unrelated themes**, don't log it as one blob — split it into
  **separate AH entries** so each surface stays findable, even under a single feat+docs commit pair.

### Read-first (a fresh thread reads these, in order)

1. `docs/PROJECT-WORKFLOW.md` — master process doc (roles, chunk loop, §5.x standards). **First.**
2. `docs/WORKING-PROCESS.md` — the field manual for the three-party workflow (modes, stop-gate,
   close-out checklist, testing disciplines, production-data safety, the alarm rule, review
   integrity, session rituals). Summarizes §5 rather than duplicating it — the repo wins on any
   divergence.
3. `docs/ACTIVE-PHASE.md` — which phase is live.
4. `docs/reviews/adhoc-changes-log.md` — the ad-hoc index (**Part 2 below points into it**).
5. `docs/tech-debt.md` — deferred items + triggers.
6. `docs/security/tenancy.md` — tenancy contract. `docs/feature-flags.md` — flag registry.
7. Phase spec (`docs/20-PHASE-1-SPEC.md` or the active one) + the specs in `PROJECT-WORKFLOW.md` §11
   (architecture / conventions / API / security / testing) **as the task requires**.
8. The relevant `docs/reviews/sprint-*` / AH log entries for the surface being touched.

---

## Part 2 — CURRENT STATE ⟵ refresh this block at each session close

**Last updated:** 2026-07-29 · **Through:** AH-059 · **Nothing is held.** The push on 2026-07-29 moved
`origin/main` from `5cc382c` (the AH-058 close) by **thirteen commits**: the eleven **AH-059** code
commits (`a70c548`, `c27926a`, `b2ca89b`, `bb825a8`, `75076ef`, `ce841e2`, `e2501b3`, `a4897b5`,
`df99cac`, `0c7ea82`, `26a127a`), the docs commit `b1ed331` carrying
[`jobs-board-c5-review.md`](jobs-board-c5-review.md), the AH-059 log entry and the D7 close-out
documents, and the **close commit at the tip** flipping that review to **Closed — approved** and
writing this refresh (a commit cannot record its own hash).
**All of it is code, and undeployed** — see the deploy notes below.
`git rev-parse --short HEAD` and `git rev-parse --short origin/main` are the authority for where the
two tips actually sit — re-derive them, do not carry this session's numbers forward.

> **🏁 THE JOBS BOARD ARC IS COMPLETE (AH-053 → AH-059).** Five chunks, one feature, **one deploy**,
> still held. **AH-059 closes it**: the five eyes-on fixes (the rejected-chip contradiction, the
> mail-flag investigation, the list-page listing toggle, the board's Applications column, the coarse
> lifecycle reflection), the cross-role full-lifecycle Playwright spec owed since chunk 3, and the
> close-out documents. **It adds no migration, no production route, no flag and no notification type**,
> and it has **zero diff** in the Boards module, the migrations, the automation seeds and the whole
> application-mail path. **The arc's deploy procedure is now written down in one place:**
> [`docs/runbooks/production-queue-worker.md` §8.3](../runbooks/production-queue-worker.md) — snapshot,
> the four additive migrations enumerated, the **mandatory** queue-worker restart, no one-shots, smoke.
> **The two mail flags stay OFF at deploy**; arming them is a separate later act with its own ritual
> (§7.4 there, and `feature-flags.md`).

**Prior state, for the record.** The push on 2026-07-28
moved `origin/main` from `d4b2de5` (the AH-057 docs commit) by **thirteen commits**: the two chunk-4
**docs** commits held since plan-pause (`895f28a` inventory, `685991c` plan), the nine **AH-058**
build commits (`0abba72`, `78c2dd8`, `86a44a4`, `5f6486c`, `af0f343`, `d33df9e`, `ef195f8`, `8ff9985`,
`a1c66ab`), the S11 docs commit `9a330d6` carrying
[`jobs-board-c4-review.md`](jobs-board-c4-review.md) and the AH-058 log entry, and the **close commit
at the tip** flipping that review to **Closed — approved** and writing this refresh (a commit cannot
record its own hash).
**All of it is code, and undeployed** — see the deploy notes below.
`git rev-parse --short HEAD` and `git rev-parse --short origin/main` are the authority for those two
numbers, not this sentence; re-derive them rather than carrying this session's forward.

**Baseline (the previous pushed tip):** the AH-057 docs commit. It sits atop the three AH-057 commits
(`fc88347`, `bbafc9c`, `d147baf`), which sit on the AH-056 close commit (`978bce5`), itself atop the
six AH-056 commits (`81df0b5`, `928ccce`, `0cf6275`, `4e527e7`, `d37d43c`, `ddf875c`) and the AH-055
docs commit, all on `aa8d410` (the AH-053/AH-054 close-out). **Nothing is held — AH-055, AH-056 and
AH-057 were all pushed on 2026-07-27** with the review closed and approved. (A commit cannot record
its own hash, so the tip is named descriptively; `git rev-parse --short HEAD` gives it, and
`git rev-parse --short origin/main` gives the pushed tip. Those two are the authority, not this
sentence.)

> **⚠ The Jobs Board arc deploys as ONE unit — chunk 4 of 5 is done, and the deploy stays held.**
> **AH-058 closes the gap chunk 3 left open**: the agency now reads applications on a
> campaign-detail Applications tab and answers them (accept creates a standard invitation the
> applicant can still decline; reject is terminal and no-re-apply), a cancelled or completed campaign
> auto-rejects whatever was still pending, and an accepted creator is carried from the job page to
> the offer waiting for them. **It adds no migration** — chunk 3's `campaign_applications` table is
> the whole storage — and one new flag (below). Chunk 5 is the arc's last.
> AH-056 shipped the creator board, apply, and the job-posted fan-out. Holding the
> deploy to end-of-arc is what let the arc be built incrementally; do not deploy
> AH-053/054/056/058 piecemeal. **AH-057 carries nothing of its own** — it is
> the eyes-on fix pass over AH-056's UI (mobile bottom bar, job detail framing, a campaigns-table
> listing column) with a **zero backend diff**: no migration, no endpoint, no enum, no lang file. It
> inherits AH-056's deploy obligations and adds none.

> **A new Playwright project exists (AH-057).** The suite is now **27 specs across 18 files and two
> projects** (AH-058 added the agency-side `campaign-applications.spec.ts`; **AH-059 added
> `jobs-board-full-lifecycle.spec.ts`, the cross-role spec that walks the whole board loop and, at
> ~30s, the suite's longest**) — 26 desktop `chromium` plus one `mobile` leg on the iPhone 13 profile, scoped to
> `creator-shell-mobile.spec.ts` alone. It runs on the **Chromium engine**, not WebKit: the device
> descriptor's `defaultBrowserType` is `webkit`, and Playwright's WebKit build for macOS 14 is frozen
> and bus-errors on launch on this host. `pnpm test:e2e:install` therefore still fetches chromium
> alone — **do not "fix" it by adding webkit** without first verifying WebKit launches on the host in
> question. Also note spec #20 (failed-login lockout) now carries `test.slow()`: it needs ~27s on a
> quiet host and blows the default 30s budget when the machine is loaded, so a red there is a load
> symptom to re-run in isolation before it is treated as a regression.

> **⚠ UNDEPLOYED CODE EXISTS — and it carries a migration and a pre-deploy read.** The push on
> 2026-07-27 moved `origin/main` `2cb6c11..` with **AH-053 + AH-054** (Jobs Board chunks 1+2
> — brand completeness floor, brand logo pipeline, campaign listing fields): `b7ea3e1` (AH-054 feat),
> `2568a96` (AH-053 feat), then the shared docs commit at the tip. Review closed and approved:
> `docs/reviews/jobs-board-brand-amends-review.md`. **This is the arc's first migration since AH-041,
> and AH-053 needs an operator read run BEFORE it deploys — see the deploy notes below.**

> **Correction (2026-07-27).** This block previously named `d1dc3d2` as the `origin/main` tip. It was
> stale by four commits: `f5be920`, `d83c223`, `710292b` and `2cb6c11` had landed on top of it. All
> four are **docs-only** process syncs, so nothing about the code or deploy posture changes — but the
> baseline number was wrong, and the AH-053/AH-054 push is what surfaced it. Same lesson as the
> 2026-07-26 tracking note below, one level up: **re-derive the tip with `git rev-parse`, do not
> carry the previous session's number forward.**

The AH-001→AH-052 range is pushed in full, including
`c6b6cde` (Step 0 — `fix(identity)` 2-SPA dev-cookie fix), `98defa9` (**AH-051** feat) and
`30116da` (**AH-051** docs, the amended close-out; the pre-amend `ce959cc` is no longer reachable).
The final push on 2026-07-26 moved `origin/main` `30116da..d1dc3d2`, carrying the six **AH-051
eyes-on fix** commits — `4af63b2` (admin dialog: 422 copy, picker name, `approved` gate), `046d26c`
(roster "Active" chip, `ended` chip), `530d7d8` and `bdc957b` (admin-connected mail body trims ×24),
`dd65868` (**AH-052** canonical 403 envelope, closed-thread composer, notification registry),
`d381a77` (an `ended` relation could be re-pooled) — plus the addendum/AH-052 docs commit `d1dc3d2`.

**Deployed through:** **`f5be920` (AH-052), deployed 2026-07-26.** Push and deploy were in sync as of
that date; **they are no longer** — the AH-053/AH-054 push and the AH-055/AH-056 push, both on
2026-07-27, plus the **AH-058** push on 2026-07-28, are **code, and undeployed**. That is the first
undeployed code since AH-052, and it is now the whole first **four** chunks of the Jobs Board arc plus
one UI fix. Deploy state as last read
from the server, not inferred: `php artisan migrate:status` reported **Ran through batch 5**, and the
2026-07-26 deploy correctly reported `Nothing to migrate` (AH-051/052 add none). **AH-054 adds one
migration and AH-056 adds three** (**AH-058 adds none**), so the next deploy will not say that — see
the deploy notes below.

> **Tracking lesson (2026-07-26).** Deploy state must be **read from the server**
> (`migrate:status`, and see the scheduler blocker below), never inferred from push history —
> deploys are colleague-managed and advance without notice. Everything through AH-050
> (`content_companions`) turned out to be already live before today, while this file still listed
> its migrations as pending. Verify at each session close; do not carry forward an assumption.

**Deploy notes — AH-059 (pushed 2026-07-29, NOT deployed).** **No obligations of its own, and no
migration** — the arc's pending-migration count stays **four**, and this chunk is the one that writes
that list down. Three things to know:

1. **The whole arc's deploy is now one runbook section**, `production-queue-worker.md` §8.3: the four
   migrations named with their chunk and their `down()` honesty (**the two `CREATE TABLE`s are lossy on
   rollback** — after creators have applied, a rollback is a data-loss event, restore from the snapshot
   instead), the **mandatory** worker restart, the absence of one-shots, and two arc-specific smoke
   reads. §8.1 is marked superseded and points at it.
2. **The combined first-enable ritual is written and is NOT part of the deploy** — `feature-flags.md`
   ("The jobs-board arc's combined first-enable ritual") and runbook §7.4. Both jobs-board mail flags
   are armed **together**, later, deliberately, and **step 4 is to read both flags back**. That step
   exists because AH-059's own investigation spent an hour on a "broken" mail path that was simply an
   unarmed flag.
3. **D3 changed reachability, not code.** The campaigns-list toggle drives the same PATCH the Settings
   tab drives, so a mis-click on a table row is now one round-trip from an outbound fan-out to real
   creators once the flags are armed. The ON direction is behind a confirmation dialog for exactly that
   reason; OFF stays immediate. At T+0 this is inert — no campaign is listed.

**Deploy notes — AH-058 (pushed 2026-07-28, NOT deployed).** Three obligations, and
**no migration** — the arc's pending-migration count stays **four**.

1. **🔴 `application_notifications_enabled` ships OFF, and the arc's first-enable ritual now arms
   TWO flags together.** It gates the **mail legs only** of the three new
   `campaign_application.*` notification types; the in-app rows write regardless, honouring the
   recipient's own preference. Flip it from the admin Feature-flags page **alongside
   `job_posted_notifications_enabled`** — a board that pushes listings but never acknowledges an
   application is the confusing half-state, and both are the same fan-out risk class. Full row and
   rationale in `docs/feature-flags.md`. At T+0 the population is provably zero: no campaign is
   listed, so no application can exist.
2. **⚠ The queue-worker restart rule binds.** This deploy carries new `lang/**` mail copy (three
   mailables × 24 locales, `campaigns.php`) and three brand-new mailable classes. The long-running
   worker caches translations in memory — **restart it**, or the first accepted creator gets a
   missing-key body. The terminal auto-reject is a **queued job** (`AutoRejectPendingApplicationsJob`,
   dispatched from `CampaignController::update()` on a non-terminal → `cancelled`/`completed` flip),
   so a down worker means pending applications sit pending until the worker comes back; it is
   idempotent by design (it re-filters `status = pending` inside its own transaction), so a
   re-cancel or a late-started worker never double-rejects and never double-mails.
3. **⚠ The live invite path changed behaviour, deliberately and narrowly.** `POST …/assignments`
   now runs inside a transaction and, for a pair that has a **pending application**, marks that
   application `accepted` in the same transaction (D3b). A pair with **no** application is
   byte-identical to before — pinned field-by-field. Nothing to do at deploy; noted because an
   operator reading assignment audit trails will now sometimes see a
   `campaign_application.accepted` row beside an invite nobody accepted from the tab.

**Deploy notes — AH-055/AH-056 (pushed 2026-07-27, NOT deployed).** AH-055 is pure UI and carries
nothing. AH-056 carries three obligations:

1. **Three more migrations**, all additive: `2026_07_27_110000_create_campaign_applications_table`,
   `2026_07_27_110001_create_campaign_job_notifications_table`, and
   `2026_07_27_110002_add_listed_at_to_campaigns`. No existing row is read or rewritten. **The
   `down()` on both new tables is lossy** — dropping them destroys every application and every
   notification stamp. A rollback after creators have applied is a data-loss event, not a revert.
   With AH-054's, the arc's pending-migration count is **four**.
2. **🔴 `job_posted_notifications_enabled` ships OFF and must stay OFF until deliberately flipped.**
   This is the platform's first outbound mail fan-out to the live creator base (~279). The
   first-enable ritual is in `docs/feature-flags.md`: run
   `php artisan campaigns:preview-job-notifications {campaign-ulid} --dry-run`, read the
   would-notify / would-remain counts, then flip from the admin Feature-flags page. Zero campaigns
   are listed at deploy, so the board is empty and the fan-out has nothing to send regardless.
3. **The fan-out needs the queue worker, not the scheduler.** Its trigger is the listing flip
   (`CampaignController::update()`), chosen precisely because the production scheduler is
   unverified — see the standing blocker. If the worker is down, notifications queue and wait; the
   `campaigns:preview-job-notifications` command drains any capped remainder by hand. Note also
   that this deploy carries new `lang/**` mail copy, so the standing **queue-worker restart** rule
   below binds.

**Deploy notes — AH-053/AH-054 (pushed 2026-07-27, NOT deployed).** Two obligations, both new:

1. **One migration** — `2026_07_27_100000_add_jobs_board_listing_to_campaigns`. Purely additive (six
   nullable columns plus one boolean defaulting `false`), honest `down()`, no backfill, no existing
   row read or rewritten. `php artisan migrate` becomes a required deploy step again for the first
   time since AH-041.
2. **📋 PRE-deploy read, not post-deploy** — run `php artisan brands:audit-floor` **before** shipping
   AH-053. It is a pure read (no writes, pinned by a test) and reports, platform-wide, how many
   brands each floor field blocks and the lifecycle split of the blocked population. AH-053 makes an
   incomplete brand's **next edit** return 422 — a behaviour change for existing data, though not a
   data change — and this command is how the size of that population becomes knowable in advance
   rather than through support tickets. Nothing is backfilled; the brands themselves stay readable,
   listable, campaign-carrying, archivable and restorable.

**Deploy notes (prior).** **No migrations** anywhere in AH-051 or AH-052 (`ended` is a plain-varchar enum
value, no CHECK). AH-051 notes, now **post**-deploy: (a) the live contact gate is TIGHTENED as of
2026-07-26 — `php artisan relations:audit-contact-exposure` now reads what changed rather than
what would (see outstanding items); and (b) admin disconnect DELETES pool-membership rows, so
**snapshot-first** stays the standing rule for any deploy carrying it. **Two permanent notes**
from the eyes-on fixes:

1. **⚠ Restart the queue worker on every deploy that changes mail copy.** The long-running worker
   **caches translations in memory** — a `lang/**` copy change will keep sending the OLD body until
   the worker is restarted. Found the hard way while verifying the `530d7d8`/`bdc957b` mail trims:
   the new text did not appear until the dev stack was bounced. This is **not** AH-051-specific; it
   applies to every mail-copy change the platform ever ships.
2. **⚠ The 403 body shape CHANGED in production on 2026-07-26 (client-visible contract).** AH-052
   makes every `authorize()` denial — all 82 call sites, plus every `abort(403)` — return the
   canonical JSON:API error envelope (`auth.forbidden`) instead of Laravel's default
   `{"message": …}`. Both SPAs consume the envelope via `ApiError.fromEnvelope` and were verified;
   the residual exposure is anything **outside this repo** that pattern-matched the old shape. This
   is live now, so it is a thing to check **if 403 handling misbehaves**, not a pre-deploy gate.

**Outstanding operational items.** **Both one-shot post-deploy commands are now CLOSED** (prod
session, 2026-07-26/27). Nothing one-shot remains; AH-050, AH-051 and AH-052 added none.

- ✅ **AH-026 D5 `creators:recompute-completeness` — CLOSED, run on prod 2026-07-26.** Dry-run
  verified first, then executed: **279 creators checked, 1 score updated.** The near-zero delta is
  the expected result, not a failure — it means the persisted scores were already consistent with
  the AH-026 formula (region floor + D4 optional credit) for all but one row.
- ✅ **AH-042 D4 `campaigns:advance-contractless-accepted` — CLOSED as moot.** **0 eligible rows on
  prod**: nothing was ever stuck at `accepted` on a `requires=false` campaign, so the remediation
  had nothing to remediate. The command stays in the codebase as a safety net; no action pending.
- 📋 **D-1 contact-exposure audit — RECORDED, no action.** **2 `pending_request` relations across
  1 agency, of which 1 had contact data populated** — audited **post**-deploy, gate already live.
  This is the realized blast radius of the AH-051 tightening: one agency lost visibility of one
  creator's contact details. Small enough that no remediation or notification is warranted.
- ♻️ **Queue-worker restart on mail-copy changes — standing rule, not a one-shot.** See note 1
  below. Never "closes"; it binds on every future deploy carrying a `lang/**` change.

**Observed production scale (2026-07-26):** **~279 creators**, per the recompute command's own
count. Useful as the blast-radius denominator when sizing any future data migration or backfill.
Not to be confused with the capacity **targets** in `docs/00-MASTER-ARCHITECTURE.md` (500,000+
registered creators, 200+ concurrent admin users), which are design goals, not current state.

> **AH-042 · Toggle-OFF campaigns flow without contract involvement** (full chunk loop). The
> `requires_per_campaign_contract` toggle is now load-bearing end-to-end: the machine permits a
> contract-less advance regardless of the `per_campaign_contract_enabled` flag (D1); OFF campaigns
> auto-advance `accepted → contracted` on accept (D2); the creator copy consults the toggle (D3); a
> one-shot command remediates stuck rows (D4). Also fixes a pre-existing false-fire (the agency
> proceed-without-contract path announced a non-existent contract acceptance). ON path byte-identical.
> **No new migrations.** Full board green: backend 1841 Pest, main 1177 + admin 425 Vitest, 24/24
> Playwright, typecheck/lint/parity clean. Review: `docs/reviews/contract-toggle-off-flow-review.md`
> (Closed, approved). Adds one post-deploy command (below).

**Prior batch (AH-033→AH-041) — PUSHED AND DEPLOYED** (`ed2e0dc` close-out **docs** commit,
sitting atop the **direct-iteration fix batch** `cc86bb8 … fdbec40` (33 code/spec commits + the
Part-A closure commit `fdbec40`), atop the AH-032 baseline **`7051123`**). **✅ Migrations all Ran
— nothing pending.** This paragraph previously carried a next-deploy warning for three schema
migrations plus a data backfill; `migrate:status` on prod (2026-07-26) shows all of them **Ran**,
with `2026_07_13_110000_backfill_cancelled_rejected_board_column` in **batch 3**. AH-042 through
AH-052 add **no migrations at all**, so **the pending-migration list is empty** and
`php artisan migrate` was not a required deploy step for anything on `origin/main` **as of AH-052**.
(That statement expired with the AH-054 push, which adds one — see the deploy notes above.)

### Delivered

- **Sprints 0–13 + 3.5 closed** (the full Phase-1 spine: identity/auth, onboarding wizard,
  integrations seams, roster + discovery + pools, campaigns/boards, notifications subsystem, EU
  locale support). Per-chunk decisions in `docs/reviews/sprint-*`.
- **Ad-hoc run AH-001 → AH-052 — all Landed and all pushed** (`origin/main` at `d1dc3d2`; nothing
  held). One line each (detail and decisions in `docs/reviews/adhoc-changes-log.md`):
  - **AH-001** — EU locale support (24 languages) + persistence.
  - **AH-002** — Digest/invite email locale docblock + English-only decision.
  - **AH-003** — Wizard slim + profile-basics polish.
  - **AH-004** — Portfolio overhaul (schema + async image worker + drawer).
  - **AH-005** — Creator contact details (phone/WhatsApp/address), connected-agency-visible.
  - **AH-006** — Finish the Connect→Add rename (step-3 social copy).
  - **AH-007** — Creator platform mobile-responsive pass (the pure-UI-skips-the-loop precedent).
  - **AH-008** — Portfolio link cards — copy-URL button.
  - **AH-009** — Standalone creator Profile-edit page (reuses wizard steps 2 & 3).
  - **AH-010a** — Relationship messaging: backend spine + gate + attachments + notifications.
  - **AH-010b** — Relationship messaging: WhatsApp-shaped inbox + thread (frontend).
  - **AH-011** — Onboarding architecture-test cleanup (two pre-existing reds).
  - **AH-012** — WhatsApp-style new-conversation flow (symmetric picker) + provisioning fix.
  - **AH-013** — Two-pane (WhatsApp Web) messaging + real contact avatars.
  - **AH-014** — Campaign `ChatPanel` parity with relationship chat.
  - **AH-015** — Portfolio inline collapsible drawer + preview download.
  - **AH-016** — Creator mobile Profile-nav bootstrap fix.
  - **AH-017** — Creator assignments mobile card redesign.
  - **AH-018** — Verify-email `:app` placeholder fix (regression pinned in the §5.3 rendering test).
  - **AH-019** — Category taxonomy 16→28 + chip-grid picker with select-all.
  - **AH-020** — Verify-email pending page: `?email=` carry on the unverified bounce.
  - **AH-021** — Review page numbering + account step surfaced.
  - **AH-022** — Full ISO country/language pickers + creator accent field (three-concept locale split).
  - **AH-023** — Surname at sign-up + account-creation details on three surfaces.
  - **AH-024** — Reset-password route moved to match the emailed link.
  - **AH-025** — Production admin bootstrap command (`admin:create`).
  - **AH-026** — Onboarding floor + score reweight + wizard % display: region joins the six-field
    profile floor (1:1 FE↔BE, source-scan parity spec); profile unit's 25 pts split floor 13 +
    per-optional credit 12 (gate boolean stays floor-only, score numerator partial via
    `profileEarned()`); both wizard chromes + rail show the `%` alongside "Step X of N"; review
    two-signal copy; `creators:recompute-completeness` one-shot command. **Post-deploy: DONE** —
    run on prod 2026-07-26 (279 creators checked, 1 updated).
  - **AH-027** — Creator completeness `%` on the agency discover detail: read-only display of the
    already-on-the-wire `profile_completeness_score` as a `%` bar on `DiscoverProfilePage`; no BE /
    resource / gate / formula change (`app.discover.detail.completeness` × 24 locales, parity green).
    Rode the AH-026 session by go-ahead but logged as a separate entry (separate surface).
  - **AH-028** — Scroll-to-end gate on the click-through master agreement: the acceptance checkbox
    disables until the terms region is scrolled to the bottom (zoom-tolerant, auto-satisfies on
    non-overflowing content — branch spec-pinned); client-side only, backend/accept-endpoint
    unaware. One additive i18n key (`click_through_scroll_hint`) initially shipped with 10 locales
    on English fallback (AH-001 debt class) — fixed with an MT baseline in the closure commit; the
    Playwright happy-path now genuinely scrolls the terms region (the real markdown overflows it).
  - **AH-029** — Master agreement replaced with the real Catalyst Creator T&Cs (new entity, new
    governing law, 10-clause restructure). Version deliberately held at `1.0`; snapshot is the
    authority, not the label — logged as mandatory tech-debt (no re-consent flow for pre-swap
    signees). Engineering reviewed; legal soundness for existing signees is explicitly for counsel.
  - **AH-030** — Contract step: removed the duplicate `<h2>` inside `ClickThroughAccept` (page-level
    title retained as the single heading).
  - **AH-031** — Platform rebrand, Engine C → Catalyst Engine, across emails (`APP_NAME`), both SPA
    titles, 48× `app.json`, 24× `lang/app.php`, API root JSON, seeded admin name, brand-layer
    comments. Value-only swaps, zero keyset change, parity green.
  - **AH-032** — Campaign-creation form simplification: `objective` select removed (server defaults
    `ugc` via `prepareForValidation`; enum/column/Resource/Overview-tab row stay — contract only
    relaxes), `target_creator_count` input removed (storage/emission stay, API-only), and the whole
    brief block removed (form stops sending `brief`; `sometimes` preserves stored blobs by omission).
    Description absorbs the prose role via a new persistent hint. **Wipe-bug fixed by omission** (the
    old form rebuilt the brief jsonb from partial inputs, wiping `dos/donts/mentions/links/attachments`
    on every save) — pinned by a byte-identical preservation test + a tech-debt forward-guard. i18n
    orphan cleanup ×24 (`fields.objective`/`objective.*` kept for the Overview tab); parity green.
    Full loop, review closed (`docs/reviews/campaign-form-simplification-review.md`).
  - **AH-033** — Campaign overview: show name + duration + full description (scoped-style override of
    Vuetify's subtitle truncation), drop the Objective row, add "Requires a per-campaign contract" as
    the last item. Front-end only; no new i18n (icon for the boolean); no BE/shape change.
  - **AH-034** — Invite-offer context: `fee_per` + `offer_description` free-text + a **presigned,
    campaign-keyed offer attachment** (images EXIF-stripped; non-image types stored without content
    sniff — tech-debt) + real roster avatars. Emission-scoped signed URLs (60-min, AH-004);
    cross-campaign prefix isolation pinned; `tenancy.md §4` updated in the closure commit.
  - **AH-035** — Re-offer after decline: `declined → invited` machine edge overwrites the full offer
    - clears `responded_at` + raises `previously_declined`; fail-closed from any non-declined source
      (**break-revert executed** at close-out); idempotent no-op on non-declined rows; audit reuses
      `assignment.re_invited`; creator counter UI removed while the counter API stays fail-closed
      (tech-debt); `previously_declined` is agency-side only, never creator-visible.
  - **AH-036** — Readability fixes: widen the admin sidebar 280→304px, fee/dates on separate lines in
    the creator invitation list, brighten the View-post button. Pure styling.
  - **AH-037** — Board card drawer **Messages** tab (first + default): mounts `ChatPanel` via
    `agencyChatTransport` with **zero new provisioning** (AH-012 lesson held); "no conversation" note
    for assignment-less cards.
  - **AH-038** — Discover card redesign (**Phase A, front-end only**): photo-forward hero, connection
    indicator, icon meta row, ≤3 chips + `+N` overflow, footer; ~30% smaller grid, 5:4 hero, and
    **container-query** content scaling. No BE/i18n change.
  - **AH-039** — Board card facelift + drawer Detail-tab redesign: avatar / bold name / chips / fee /
    aurora accent on the face; identity + offer-terms + deliverables + 5-step timeline in the drawer;
    card face preserved on move. API-resource-shape + i18n stop-gate exceptions.
  - **AH-040** — Draft submissions: hide hashtags/mentions (retained-and-preserved-by-omission,
    AH-032 pattern), chat-style two-icon composer, and real external `links` (jsonb; `url:http,https`
    allowlist, max 10/2048/255; plain anchors with `noopener noreferrer`).
  - **AH-041** — Reject guard + board wiring: confirm dialog on the terminal draft-reject; "Cancelled"
    → "Cancelled / Rejected" + a 10th default automation (`assignment.draft_rejected` auto-moves the
    card) + a data backfill (default-named-only rename, idempotent automation insert, `down()` blunt);
    one-line column name; red closed-conversation notice. New Campaigns→Boards coupling recorded.

  - **AH-043** — Toggle-OFF: `WriteSystemMessage` was a third contract-announcement surface the
    AH-042 review missed — forks the in-thread system-message copy on `contract_id` so a
    contract-less advance never claims a contract was signed (both auto-advance and the agency's
    manual proceed-without-contract). New key across all 24 locales + `messages.php`. Dated
    post-close addendum appended to `contract-toggle-off-flow-review.md`.
  - **AH-044** — Draft submit/resubmit (same endpoint) now accepts **media OR links**, not
    media-mandatory; cross-field `422 draft.empty` when both are absent; empty media persists as
    `null` (the sole downstream reader already null-coalesces, no renderer changed). New
    `emptyHint` i18n key ×24.
  - **AH-045** — Resolve action surfaced on the Board card drawer (Live-verified row) and the
    Drafts tab (next to Review) for a failed post verification — pure UI wiring onto the
    pre-existing `ResolveVerificationDrawer` + its existing endpoints/authorization; no new backend
    surface. Additive, back-compat `verification_status` field on the (agency-only) drafts-list
    resource.
  - **AH-046** — Reworded the creator-facing failed-verification copy to say the agency can review
    and manually verify a post whose link is already correct — closing a "nothing to do, no
    guidance" dead end. All 24 locales carry a real MT-baseline translation (flaky-10 ruling
    below); incidentally fixed the one corrupted `hr`/`sk`/`sl`/`bg` occurrence of this line.
  - **AH-047** — Green "verified by the agency" success banner on the creator assignment-detail
    page for `live_verified`/`manually_verified`, closing the "did anything happen?" gap after a
    successful verification. New `verifiedNotice` key ×24 (same MT-baseline ruling).
  - **AH-048** — Incomplete-creator email nudge (scheduled daily, flag-gated default-OFF, once-only
    via the additive-nullable `creators.incomplete_nudge_sent_at`, per-run cap + `--dry-run`): two
    variants (verify-email / finish-profile) for self-serve creators stuck `incomplete` 48h+.
    Strings ×24 `creators.php`; full loop. **Post-deploy:** enable the flag; the command runs on the
    daily scheduler. Review: `docs/reviews/incomplete-creator-nudge-review.md`.
  - **AH-049** — Master agreement content refresh + version bump `1.0 → 1.1`: swapped in the
    finalized Catalyst T&Cs (adds clause 2.4 revision-rounds + 4.3 30-day payment; expands 7.3
    portfolio-consent), bumped `ContractTermsRenderer::CURRENT_VERSION`. New acceptances snapshot the
    new text + precise `'1.1'`; existing signed rows immutable, keep `'1.0'` (no re-consent — AH-029
    counsel thread stays open). Adopts the AH-029 tech-debt _direction_ (every content change bumps
    the label; the integer column stays `1` — lossy by design). Strengthened content-coupled tests
    (break-revert executed) + §5.34 immutability case; no migration/i18n/schema change. Review:
    `docs/reviews/master-agreement-v1-1-review.md`.
  - **AH-050** — "Who appears in your content?" optional companion multi-select
    (`creators.content_companions`, additive-nullable jsonb; fixed 11-key registry, SOT
    `UpdateProfileRequest::CONTENT_COMPANION_KEYS`). Self-declared; null AND `[]` both =
    undisclosed (no normalization, round-trip pinned); **completeness-inert** (D6,
    break-revert-proven — deliberately NOT the accent +2 weighting); **admin read-only**
    (D7, break-revert-proven; plain row, no pencil); accent visibility class (discover
    card payload + detail, roster list + detail, admin detail); display-only v1 (no
    filter); wizard chip group after accent, NO select-all;
    `profile-companions-chip-<key>` testids; i18n ×24 both apps (flaky-10 real MT). The
    FE `COMPANION_KEYS` copy extends the AH-019 parity debt (one entry, one future fix
    for both fields). GDPR purpose section in the review. Review:
    `docs/reviews/content-companions-review.md`.
  - **AH-051** — Admin-initiated agency↔creator connections + contact-gate fix + first
    termination path (full loop). (1) AH-005 contact gate TIGHTENS to roster-only
    (`CreatorPolicy::canSeeContactDetails` requires non-blacklisted `roster`; a read-only
    `relations:audit-contact-exposure` count command reports the pre-deploy blast radius).
    (2) Sixth `RelationshipStatus` `ended` (severed-after-roster, re-requestable, never
    messageable/contact-visible, excluded from the default roster) reached ONLY via the
    new admin disconnect — the platform's first termination path (roster→ended + pool-
    membership deletion + reason audit in one txn; campaign assignments survive). (3) Admin
    Creator-detail doors: Door 1 send-request, Door 2 direct-connect (mandatory reason +
    creator notification), per-row Disconnect — one mode-switched `POST …/connections` +
    `…/disconnect`, all `runAs`-scoped. Accept re-gated (approved + not hard-blacklisted).
    3 new audit verbs + 2 notification types + 2 mailables; `ended` FE ripple (api-client
    union + `deriveConnectionState` "Previously connected"). Break-reverts executed (D1
    gate, D2 blacklist, D6 pool-scope). **No migration** (`ended` = plain varchar, no CHECK).
    i18n ×24 both apps + backend. Review: `docs/reviews/admin-connections-review.md`.
    **Post-close eyes-on fixes (6, pushed 2026-07-26):** `4af63b2` admin dialog (raw 422 code rendered as
    copy, agency ULID replacing the name in the picker, no upfront `approved` gate),
    `046d26c` the roster "All" chip promised a total the backend never returns, renamed
    "Active" with an `ended` chip added, `530d7d8`/`bdc957b` admin-connected mail body trims
    ×24, `dd65868` (**AH-052** below, plus the closed-thread composer and the 2 relation types
    registered in the FE `LIVE_TYPES`), `d381a77` an `ended` relation could be re-added to a
    talent pool, undoing D-6's membership deletion — the one genuine gap in D-3's status
    sweep. **Zero diffs on all three break-revert subjects** (verified over `30116da..HEAD`);
    3 fresh break-reverts on the new guards. Detail: the review's **Post-close addendum**.
  - **AH-052** — Canonical 403 envelope. Every `authorize()` denial (all 82 call sites, plus
    every `abort(403)`) now returns the JSON:API error envelope with code `auth.forbidden`,
    the same shape 422 has always produced; previously they fell through to Laravel's default
    `{"message": …}` and the SPA rendered "Unrecognized error response." Registered against
    `HttpExceptionInterface` filtered to `HTTP_FORBIDDEN`, **not** `AuthorizationException`
    (Laravel converts it to `AccessDeniedHttpException` before render callbacks run). Root
    cause: a renderer existed for 422 and never for 403, and **no test asserted a 403 body**,
    only status codes. Pinned by `ForbiddenExceptionRendererTest`, including a case proving
    the output parses under the SPA's `ApiError.fromEnvelope` contract. **⚠ client-visible
    contract change** (see deploy notes). Surfaced by AH-051 eyes-on; commit `dd65868` is
    shared with the AH-051 addendum items.

  - **AH-055** — Brand detail page stops showing `default_currency` / `default_language`, the two
    fields AH-053's D8 made unsettable. Found by Pedram in eyes-on minutes after the push: D8 was
    form-scoped, so the detail page still rendered both rows. Pure-UI (AH-007 pattern) — two
    `v-list-item`s removed plus the orphaned `app.brands.fields.*` keys ×24; `app.settings.fields.*`
    untouched, since the agency-level pair IS settable. **The contract does not narrow** — columns,
    defaults, validation, resource emission and the FE type all stay as D8 left them. The AH-032
    precedent (removed from form, Overview row kept) was deliberately NOT followed: `objective` is
    consumed, whereas the brand defaults are inert — nothing in `apps/api/app` reads either one.
    Deprecating the columns outright is left open as a full-loop question.
  - **AH-053** — Jobs Board chunk 1: brand completeness floor + logo pipeline + form relabel
    (`2568a96`, **pushed, NOT deployed**). Six-field floor (`name`, `slug`, `description`, `industry`,
    `website_url`, `logo_path`) required at create — logo excepted, it needs a row to attach to —
    and enforced on every later edit via a **merged-state** predicate (payload value where supplied,
    stored value otherwise), so PATCH stays PATCH; full-payload-required was rejected on the AH-032
    wipe-bug evidence. `Brand::floorMissingFields()` is the single source consumed by the request,
    the `brands:audit-floor` command and the FE mirror under a source-scan parity spec.
    Read/list/campaign-carry/**archive/restore stay ungated** and are pinned there. Logo pipeline on
    the avatar pattern: content-sniffed MIME, re-encode-to-strip-EXIF, agency+brand-scoped key,
    signed-URL-only emission, **replace does not delete** (one destructive path, over-reach negative
    kept). `description` relabelled "Monthly deliverables"; `default_currency`/`default_language`
    selects removed **from the form only** — columns, defaults, validation and API emission all
    unchanged and pinned. Break-reverts: BR-2 (neuter the predicate) reds 8, BR-3 (make it ignore
    the payload) reds exactly the 5 merged-state cases while the blocking cases stay green, BR-4
    (pull restore inside the gate) reds 1. **⚠ Behaviour change for existing data** — see deploy
    notes. Review: `docs/reviews/jobs-board-brand-amends-review.md`.
  - **AH-054** — Jobs Board chunk 2: campaign listing fields + gates + Settings toggle
    (`b7ea3e1`, **pushed, NOT deployed**). Six additive columns; create accepts the five content
    fields and ignores
    `listed_on_jobs_board` entirely. **D3 (completeness) is a resulting-state rule** — if the
    campaign will be listed after this write, every floor field must be filled, which is what makes
    "refuses to gut a live listing" possible. **D5 (terminal status) is a transition rule** — only
    `false → true` is refused for `completed`/`cancelled`. The asymmetry is ruling **A1**: a campaign
    listed when it ended keeps an inert `true` and stays editable, because auto-clearing would put
    the write path and the read scope in charge of the same fact. `scopeListedOnJobsBoard()` ships
    now (Q3 = A) with a **disjoint** negative set, so chunk 3 binds to a tested contract. The flip
    joins the `campaign.updated` audit snapshot; the four free-text/jsonb fields stay out. Regions
    are shape-capped, not registry-validated (tech-debt). One additive migration.
  - **AH-056** — Jobs Board chunk 3: the creator job board, apply, and the job-posted fan-out
    (`81df0b5`, `928ccce`, `0cf6275`, `4e527e7`, `d37d43c` — pushed 2026-07-27, undeployed). Applications
    are a **table**, not an assignment state: a pre-invited state would let `store()`'s idempotency
    branch silently swallow an agency invite, on the platform's most load-bearing machine.
    Visibility is **one predicate object, six legs** — tenancy-scope bypass, approved caller,
    `permitsMessaging()` roster, `scopeListedOnJobsBoard()`, `ends_at` start-of-day-UTC, and (added
    at kickoff, ruling C5) NOT brand-**hard**-blacklisted — composed identically by the list, the
    detail, the apply endpoint and the fan-out's recipient query, with a test walking every selected
    recipient back through the predicate so the two directions cannot drift. Detail 404s rather than
    403s, so an invisible job is not probeable by ULID. Brand-to-creator is **three fields**
    (`name`, `logo_url`, +`website_url` on detail), pinned by **exact-keyset equality** so a fourth
    cannot join by accretion — the arc's first AH-005-class crossing. The fan-out is Pennant-gated
    (default OFF), capped at 50 per run, stamped once per `(campaign, creator)` in its own table so a
    re-list never re-notifies, and **fired by the listing flip, not a scheduler** (the scheduler is
    unverified — a cron-triggered feature could pass every gate and never fire). Two §5.32
    reinterpretations recorded: the flip detector is the **existing** audit-snapshot pair in
    `CampaignController::update()`, and the audit noun is `campaign_application.*`, not
    `application.*` (which would collide with the creator's onboarding application). Six
    break-reverts, all restored. Three additive migrations. `application_submitted` is deliberately
    **left to chunk 4**. Review: `docs/reviews/jobs-board-c3-review.md` (**Closed — approved**).
  - **AH-058** — Jobs Board chunk 4: the agency half of applications — the Applications tab, accept,
    reject, and terminal auto-reject (nine commits, `0abba72`…`a1c66ab` — pushed 2026-07-28,
    undeployed).
    Applications render as a **campaign-detail tab, not a board column** (recorded §5.32
    reinterpretation of the c3 migration docblock): `board_cards.assignment_id` is `NOT NULL` +
    `UNIQUE` + `CASCADE`, so a card **is** an assignment at three layers, and §4.4's
    drag-is-consequence-free invariant cannot express accept/reject. Nothing c3 shipped is wasted —
    the index and the denormalized `agency_id` serve the tab's status-scoped list identically, and
    the board handles post-accept for free (asserted, not rebuilt). **Accept = a real offer form
    creating a standard invitation** through a service extracted from `store()`, so an accepted
    applicant is byte-indistinguishable downstream from a cold invitee **and can still decline**;
    the `is_discoverable` gate leg is **dropped** on the AH-051 ruling (browsing preference ≠
    eligibility), the agency-wide hard-blacklist re-check and the availability 409 are **kept**.
    **Every DB write is one transaction and every emission happens after it returns** — the
    plan-pause's C1 finding, since `after_commit => false` means a mail queued inside an open
    transaction is already visible to a worker; the rollback test asserts no assignment, a still-pending
    application, `Mail::assertNothingQueued()` and zero notification rows. `store()` gains that same
    transaction plus one guarded hook (**D3b**) settling a pending application for the pair it is
    inviting — a named behavioural delta, with a §5.34 field-by-field byte-identity pin for pairs with
    no application. Three notification types (`campaign_application.submitted/accepted/rejected`), a
    new `jobs_board` preference group, and one flag (`application_notifications_enabled`, default OFF)
    gating **mail only**. Terminal auto-reject is the flip-detector precedent plus an idempotent queued
    job. **No migration.** Review: `docs/reviews/jobs-board-c4-review.md`.

  > **Ruling (AH-046/047, flaky-10 MT baseline):** new creator-facing copy gets a real
  > machine-translation baseline in **all 24 locales at merge time**, including the flaky 10
  > (`bg, el, et, fi, ga, hu, lt, lv, mt, ro`) — the same standard AH-028 set. "Match the
  > already-English surrounding strings in that locale" is **rejected** as a rationale; it just
  > inherits pre-existing debt instead of fixing it.

  > **Not an AH entry:** `docs/runbooks/production-queue-worker.md` (`12a7ef5`) landed this session
  > as a docs-only ops runbook (supervisord/systemd config + the `queue:restart` deploy hook,
  > written after the live stuck-at-Processing portfolio incident). It's an operational reference,
  > not an app change — no AH log entry.

### Load-bearing invariants (do not regress)

- **Messaging gate semantics.** A pair is messageable only when **roster + non-blacklisted +
  approved**; **`declined` is blocked** (a declined connection is _not_ messageable). Mirrored FE/BE;
  the backend is the source of truth.
- **One shared predicate.** `AgencyCreatorRelation::scopePermitsMessaging()` is the single leg both
  the single-pair gate and the set-valued `MessageableContactsFinder` route through, pinned by an
  **agreement test** with a both-ways **break-revert** — the picker and the gate cannot drift.
- **Contact-detail withholding.** The AH-005 contact block is server-gated by omission
  (blacklisted-but-rostered agency gets no keys); the **withholding assertions** pin this — don't
  weaken them.
- **Portfolio ready-gate.** Only `ready` items are previewable/downloadable; processing/failed items
  are gated via the **`PortfolioItemPresenter`** — keep the gate server-side.
- **Provisioning is on intent.** A relationship thread persists on **first sent message OR attachment
  upload — never on open** (opening returns a transient, unsaved thread). Inboxes filter to
  ≥1-message threads.
- **Campaign messaging is untouched** by the relationship-messaging work. AH-014 changed only
  `ChatPanel` **presentation** — no campaign data/behavior/gate change. Keep the spine separate.
- **Profile floor is a 1:1 FE↔BE mirror** (AH-026). The six floor fields (`display_name`,
  `country_code`, `region`, `primary_language`, `categories`, `avatar_path`) live in BE
  `isProfileComplete()` and FE `floorMet` and are pinned by a **source-scan parity spec**
  (`floor-mirror-parity.spec.ts`) that lists the tokens once — a one-sided floor edit is a red.
  **Gate/score separation:** the profile gate boolean is floor-only; the score awards partial
  optional credit (`profileEarned()`), so submit-ready-but-<100% is normal. **No gate reads the
  score** — the submit gate is `incompleteSteps.length === 0`.
- **Production-data safety (`PROJECT-WORKFLOW.md` §5.40) — we are live.** Migrations additive-first
  (nullable/defaulted; renames expand→migrate→contract; no destructive `ALTER`/`DROP` on populated
  tables without a separately-reviewed plan); data mutations ship as guarded, idempotent,
  dry-runnable commands, never as migration side effects; honest `down()`; no casual hard deletes.
  **The alarm rule (both agents):** before any code, state a `PROD-DATA RISK:` line — `NONE`
  (affirmatively) or `⚠️` naming every op that modifies/deletes/migrates/backfills existing rows; an
  undeclared risky op mid-build is a stop-the-build event. Every review file gains a "Production
  posture" section (the AH-048 shape). Deploy order: the §8 checklist in
  `docs/runbooks/production-queue-worker.md` (snapshot-first).

### Open threads

- **📋 `brands:audit-floor` before the AH-053 deploy — the one open obligation from this arc.** A
  pure read, but the number it returns should be looked at before agencies meet the new 422, not
  after. Pairs with `php artisan migrate` for AH-054's additive migration. See the deploy notes above.
- ✅ **The Jobs Board arc is CLOSED (AH-059, 2026-07-29).** All five chunks are built; the deploy is
  the one remaining step and it is Pedram's call (runbook §8.3). Nothing about the board is open as
  engineering work — what is open is listed below, and both items are **decisions**, not code.
- **❓ Product call owed — is `is_discoverable` creator-controlled or admin-only?** (AH-059 D7d.) The
  column exists and is honoured where it is read, but **no surface anywhere lets a creator set it**, so
  in production it is a constant and every gate consulting it has never been exercised by a real
  choice. The two answers imply different surfaces, audit posture and copy; building either without the
  decision would be guessing. Recorded in `tech-debt.md`; owner is product.
- **The `?tab=` deep link, recorded as a candidate** (AH-059 Q6). The list-page toggle's refusal dialog
  offers "Open campaign", which lands on the campaign, not on its Settings tab where the missing fields
  are fixed. A `?tab=settings` deep link is the obvious improvement and was deliberately not built in
  this chunk.
- **⚠ `failed_jobs` is not a diagnostic signal on a developer host** (AH-059 C5/Q10). Dev and E2E share
  one Redis queue, so the table holds 158 stale E2E jobs failing with `ModelNotFoundException` against a
  database `migrate:fresh` deleted. A real failure would be one row among look-alikes. Production is
  unaffected. Resolution sketched in `tech-debt.md` (`QUEUE_CONNECTION` override in the Playwright
  `webServer.env`, plus a one-time flush).

- **🚫 BLOCKER — scheduler existence UNVERIFIED.** Until `supervisorctl status` / `crontab -l` from
  prod confirms a scheduler, **assume NO scheduled command runs in production.** Consequences, all
  currently assumed dormant: **AH-048's incomplete-creator nudge enablement is blocked on this**
  (flipping the flag ON achieves nothing without `schedule:run`), and **`messages:send-digest` +
  `boards:scan-overdue` are likewise assumed not firing** — they predate the nudge and have always
  silently assumed a scheduler nobody has confirmed. Production was deployed with a different
  structure (colleague-managed), and runbook §7's scheduler-under-supervisor docs stay reverted
  pending this reality-sync pass. **Unblock with two commands** on the prod box:
  `supervisorctl status` and `crontab -l`; record the output here, then either restore §7 or open a
  chunk to install the cron/timer.
- **Backup/restore posture UNVERIFIED — standing open item, owned by Pedram (blocks completion of
  §5.40).** The production-data-safety standard assumes a working snapshot-and-restore path, and that
  assumption is **not yet confirmed**: RDS automated snapshots (assumed enabled, unconfirmed), PITR
  retention window (unconfirmed), and — critically — a **tested restore** (**never rehearsed**). A
  snapshot you have never restored from is a hope, not a backup. Until a restore is rehearsed once
  end-to-end (snapshot → restore to a scratch instance → verify integrity), §5.40 is **incomplete**
  and every deploy should lean even more conservatively. Full detail:
  `docs/runbooks/production-queue-worker.md` §8.2.
- **Campaign Drafts tab** — merged in code, still **pending an independent review pass** (see the
  Live Status pointer in the ad-hoc log).
- **Sprint 10 (Payments/Escrow)** — **blocked on Stripe Connect production approval**; the
  `payment_released` automation is wired but inert until then. Tracked in `tech-debt.md`.
- **AH-029 counsel check (external dependency)** — the original master-agreement swap held the
  version at `1.0` across an entity + governing-law change, with no re-consent flow for pre-swap
  signees. **AH-049 (2026-07-17)** then refreshed the content again and adopted the version-bump
  _direction_ (`1.0 → 1.1`; every content change now bumps the label) — but **re-consent is still
  not built**, so pre-swap signees remain on their old snapshots un-prompted, and whether that
  posture is legally sound for existing signees is still outside this codebase's review and needs a
  counsel sign-off. Logged as tech-debt (`docs/tech-debt.md` — "Contract version-label ambiguity +
  missing re-consent flow", updated by AH-049) until resolved either way.
- ✅ **Post-deploy operational step (AH-026 D5) — CLOSED 2026-07-26.** Ran
  `php artisan creators:recompute-completeness` on prod (dry-run verified first): **279 creators
  checked, 1 score updated**. Every persisted `profile_completeness_score` is now on the AH-026
  formula (region floor + D4 optional credit). No longer an outstanding obligation; the command
  remains idempotent if it is ever needed again.
  `docs/runbooks/production-queue-worker.md` (`12a7ef5`) still cross-links it from the deploy
  checklist — that cross-link can stay, it now documents a completed step.
- ✅ **Post-deploy operational step (AH-042 D4) — CLOSED as moot, 2026-07-26.** Dry-run on prod
  found **0 eligible rows**: no assignment was ever stuck at `accepted` on a `requires=false`
  campaign, so the remediation had nothing to remediate and was not run. The command
  (`campaigns:advance-contractless-accepted`, idempotent, scoped to accepted-only +
  requires=false-only) stays in the codebase as a safety net. **With AH-026 also closed, no
  one-shot post-deploy obligations remain.**
- **NEW deploy dependency — the scheduler cron (`schedule:run`) — carry forward (incomplete-creator
  nudge chunk, July 16, 2026).** The app now has a **flag-gated daily command**,
  `creators:send-incomplete-nudges`, registered `->daily()` in `bootstrap/app.php` alongside the
  pre-existing `messages:send-digest` + `boards:scan-overdue`. Those earlier two already assumed a
  scheduler; this chunk makes the gap explicit and **documents it for the first time**: production
  must run `* * * * * php artisan schedule:run` (cron) or the equivalent systemd timer, or **none of
  the scheduled commands ever fire**. See the new `docs/runbooks/production-queue-worker.md` **§7**
  (cron/timer setup + first-enable procedure). This is a **standing infra dependency**, distinct from
  the two one-shot post-deploy commands above — the scheduler is always-on, the one-shots run once.
- **Incomplete-creator nudge first-enable — flag flip, NOT a migration/one-shot.** The nudge ships
  **flag-OFF** (`incomplete_creator_nudge_enabled`, default OFF). To enable: run
  `php artisan creators:send-incomplete-nudges --dry-run` (mutates nothing, ignores the flag), read
  the `verify=X, finish=Y` counts, then flip the flag ON from the admin **Feature-flags** page
  (reason mandatory → audit row). Once-only per creator via `creators.incomplete_nudge_sent_at`.
  Review: `docs/reviews/incomplete-creator-nudge-review.md`.
- **Key tech-debt pointers** (full detail in `tech-debt.md`):
  - **AH-001 i18n completeness** — not "fragments": **measured by AH-053**, the flaky-10 locales sit
    at **759–787 of 1351 `app.json` leaves byte-identical to English (≈57%), ~320 of them multi-word
    sentences**, versus 26–68 (none multi-word) in the other thirteen. More than half of each bundle
    was never translated and the untranslated half is prose. Parity is structurally blind to it
    (per-market human QA is a go-live gate, not a merge gate). Second-order effect: term-consistency
    scans can appear to disagree with themselves, because a competing translation may exist _only_
    inside strings that are still English — so glossary rulings must be re-taken after the cleanup.
  - **`Storage::put()` returns `false`, never throws** (AH-053) — every object-storage disk is
    `'throw' => false`. The two known callers (`BrandLogoUploadService`, `AvatarUploadService`) now
    check the return and raise `StorageWriteFailedException`; **the class of bug is not closed** —
    nothing stops the next upload surface answering 200 over a row that points at nothing.
  - **E2E media is a local-disk stand-in** (AH-053) — `MEDIA_DISK_DRIVER=local` gives the Playwright
    logo leg a real store because CI has no MinIO; S3 credentials, bucket policy and the production
    signing path stay unexercised by any gate. Also records the `/storage` route-shadowing trap.
  - **Campaign `listing_regions` shape-validated, not registry-validated** (AH-054) — `ZZ` is
    storable. Harmless while the fields are display-only; becomes a silently-invisible job the moment
    chunk 3 filters by region. Resolve together with the existing "no canonical country list" entry.
  - **Attachment-orphan sweep** — an upload-then-abandon leaves an empty thread row + orphaned S3
    object; D2 hides it from inboxes but does not clean it up. Deferred to an S3-hygiene sweep.
  - **Pending-incomplete-is-intentional** — a recorded decision (needs no work); it exists to prevent
    a future "fix" of intended behavior. AH-026 reinforced it: with per-optional score credit,
    submit-ready-but-<100% is now the normal case, not an edge.
  - **Completeness-formula recompute is manual** (AH-026) — a formula change leaves un-touched rows
    stale until an operator runs `creators:recompute-completeness`. No scheduler; it's a documented
    post-deploy step. Re-run it on the next weights/floor/split change.
  - **Attachment content-verification gap** (AH-034, extends AH-010a) — four upload surfaces
    (portfolio, campaign + relationship messaging, offer attachments) store non-image types without a
    magic-byte sniff and no type without an AV scan. Trigger: a platform-wide AV/content-verification
    workstream → one shared sniff-and-scan-on-complete seam.
  - **Counter flow is API-without-UI** (AH-035) — the counter endpoint + `counter()` machine edge +
    tests stay (fail-closed, `invited`-only), but no client calls them. Trigger: a product decision to
    restore (re-wire a client) or remove (delete route + edge + tests together).
  - **`hr`/`sk`/`sl` `creator.json` systemic mixed-language corruption** (surfaced by AH-046) — the
    one `resubmitInPlace.intro` line AH-046 fixed was a Czech/Slovenian/Slovak grammar-broken mix,
    not a clean translation, and the immediately surrounding keys in the same three files show the
    same pattern. Scope beyond the keys this batch happened to touch is **unknown**. Trigger: a
    dedicated locale-audit pass (native-speaker read-through or a cross-locale token/dictionary
    heuristic).
  - **E2E coverage gap confirmed + extended past the Creators tab** (AH-043→047) — zero of the five
    surfaces this batch touched (Board, Drafts tab, creator assignment-detail, in-thread system
    message, the manual-resolve drawer) has any Playwright coverage; the whole batch is Vitest/Pest-
    pinned only. Extends the existing "No agency-side campaign-detail Playwright E2E" entry; the
    resolution there is updated to recommend a dedicated assignment-lifecycle Playwright pass rather
    than further one-off specs per chunk.
