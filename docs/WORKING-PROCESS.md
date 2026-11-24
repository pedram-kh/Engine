# WORKING-PROCESS.md — How we build Catalyst Engine

> The canonical description of the three-party workflow, its rituals, and its
> non-negotiables. If this file and `PROJECT-WORKFLOW.md` §5 disagree, the repo
> wins — but flag the divergence and reconcile it.
>
> **Last updated:** 2026-07-16 (post-AH-048); §8 two-branch flow added 2026-11-17.

---

## 0. The one rule above all rules

**We are LIVE in production with real users. Live data is the platform's most
important asset.** Every line of code, every migration, every command is written
under the assumption that a mistake can destroy irreplaceable production data.
This is codified as the production-data safety standard in `PROJECT-WORKFLOW.md`
§5 and enforced by the **alarm rule** (§6 below). It overrides speed, scope,
and convenience — always.

---

## 1. The three parties and what each owns

| Party      | Role                                              | Owns                                                                                                                                        |
| ---------- | ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| **Pedram** | Founder, solo developer, sole decision-maker      | Product calls, final overrides, eyes-on QA, relaying between Claude and Cursor, deploys, the push trigger                                   |
| **Cursor** | Implementing agent (has repo access)              | Read-only inventories with `path:line` citations, builds, tests, draft review files, draft log entries — from what actually shipped         |
| **Claude** | Independent reviewer & architect (NO repo access) | Pre-kickoff inventory prompts, kickoffs with locked decisions, plan audits, spot-checks, Cursor prompts Pedram forwards, the push clearance |

Standing obligations for Claude: surface disagreements as explicit decisions
(never concede scope silently), reframe asks to expose hidden security/scope
implications, verify load-bearing claims against actual code/tests — **chat
summaries are orientation only; review files, log entries, and pasted code are
authoritative.**

---

## 2. The two work modes

### Mode A — Full loop (the default for anything real)

Triggered by ANY of: a schema/migration touch, an API field or resource-shape
change, a validation rule, a policy/gate/guard, a route-table change, a new or
changed i18n key, a state-machine edge, a scheduled job, or anything
security-relevant.

The loop, per chunk:

1. **Read-only inventory** — Cursor answers a Claude-written question set,
   citing `path:line` for every claim. No edits, no plan, no code.
   Inventories have repeatedly overturned premises — never skip.
2. **Kickoff with locked decisions** — Claude writes D1..Dn with the
   _structural intent_ named per §5.32, so mechanism can adapt to code reality
   while intent survives. Opens with the PROD-DATA RISK line.
3. **Plan-pause** — Cursor produces a sub-step plan (each sub-step
   independently green) + clarifying questions + the standards it will apply.
   **NO code until Claude clears the plan.** This is where cheap catches
   happen (wrong premises, missed consumers, notification side effects).
4. **Build** — sub-steps in order, each ending green (tests + Pint + PHPStan/
   Larastan + typecheck + lint on touched packages).
5. **Completion package → independent review** — per-decision evidence,
   break-revert outputs verbatim, gate table, draft review file. Claude
   reviews substance, then **reads the log entries and review file verbatim**
   (§7) before anything pushes.
6. **Two-commit pair** (feat + docs) — docs move with the build. **Push held
   until Claude clears it; Pedram triggers it.**

### Mode B — Direct-iteration batch (the AH-007 pattern)

For genuinely lightweight UI/polish work. Pedram iterates directly with Cursor
in a fresh thread; no per-item kickoff. Rules:

- **Hard stop-gate, checked BEFORE building each item:** if an item needs any
  full-loop trigger (see Mode A list), Cursor STOPS, says "this exceeds the
  fast batch — full loop or explicit exception," and waits for Pedram's call.
  Exceptions are allowed at any volume, but each is flagged **in the moment**
  — never shipped-and-confessed.
- **Commit as you go** — small conventional commits per theme. Uncommitted
  work at close-out is itself a stop-gate finding.
- Gates green per commit on touched packages.
- `packages/ui` changes: name the consumer surfaces in both SPAs before
  committing.
- Playwright awareness: say when an item touches an E2E-traversed surface.
- **Deploy-log disposition is part of the docs pass:** every batch's docs pass
  updates `docs/runbooks/deploy-log.md` — stack onto the open `PENDING` entry
  if one exists, else open one — before the push. A push with no deploy-log
  disposition is incomplete, same standing as the CI-green rule (§5.41).
- Close-out (always): Claude-written **inventory prompt** → evidence-based
  scope verification → **spot-checks** on anything that crossed the stop-gate
  → fixes → docs pass → verbatim read → push clearance.

History says these batches sprawl (one "few fixes" batch = 34 commits, nine
themes, four migrations). That's fine — the stop-gate + close-out absorb it —
but the flagging must happen live.

---

## 3. The close-out inventory (Mode B) — the evidence checklist

Every batch close-out demands **evidence, not assertion**:

1. **Commit list** from the last-push SHA; clean `git status`.
2. **Theme grouping** — one theme = one AH entry (Why / What / Touched /
   commits).
3. **Scope verification:** i18n keyset diff (and VALUE check for English
   fallback in the flaky 10: bg, el, et, fi, ga, hu, lt, lv, mt, ro);
   API/resource-shape diff; gates/policies/guards diff; new migrations; SPA
   route-table diff; `packages/ui` fan-out.
4. **Stop-gate log** — every mid-batch flag + resolution; self-report anything
   that should have been flagged and wasn't.
5. **Playwright exposure** per theme.
6. **Gates at HEAD** + every spec that changed to stay green, with one-line
   why each.
7. **Surprises** — what a reviewer should catch.

---

## 4. Testing disciplines (non-negotiable)

- **Break-revert (§5.35) on every security gate and load-bearing condition:**
  mutate the gate → watch the _right_ spec fail → revert → verify clean
  restore (`git status`/`git diff`) → re-green. An un-broken test proves
  nothing; this has caught real false-greens.
- **§5.34 negative cases:** every gate gets its disjoint-and-complete negative
  set (each case eligible in every respect except one).
- **Full-suite gates before every push** — full backend Pest (serial,
  `-d memory_limit=2G`), full Vitest on touched SPAs, api-client, vue-tsc,
  ESLint, Pint `--all`, PHPStan, locale parity. Scoped runs are for sub-step
  green only; the full board has caught what scoped runs missed (PHPStan
  cross-module, stale fixtures).
- **Full Playwright E2E before every push that touches an E2E-traversed
  surface** — dev stack down, isolated E2E DB, restart + health-check after.
  The "surely it still passes" presumption has been proven wrong twice.
- **Real-rendering mailable tests (§5.3)** for every mailable — subject + body
  per locale, plus the queued-locale assertion. Pin emitted URL shapes so
  links can't silently drift.
- **§5.2 Event::fake split** where events/notifications are involved: the
  event-dispatched leg and the no-side-effect leg are separate tests.
- **24-locale i18n done-gate:** any new/changed en string regenerates across
  all 24 EU locales, parity green. **New keys NEVER ship with English fallback
  in the flaky 10** — real MT baseline at merge time (the AH-028/AH-046/047
  ruling; "match the surrounding English" is rejected — surroundings are debt).

### 4.1 Local-run rules (standing; a full-board run froze the machine, twice)

Incidents: a run with ~16 Vitest workers plus Playwright/Chrome and Docker forced a reboot. On 2026-11-22 an uncapped full
`apps/main` Vitest run (the third in a row) most likely exhausted CPU and memory and Pedram force-quit Cursor (cause inferred, not
measured). Binding for every agent and developer, on Pedram's Mac, from 2026-11-22 (this replaces every earlier "run the full suite
locally" instruction in chunk prompts):

1. **The full Pest, Vitest and Playwright suites are never run locally.** CI runs them on every push to `pl`. A chunk closes only on a
   green run at the pushed tip.
2. **Locally, only** the test files for the code being built, plus the `Audit`, `Erasure` and `Tenancy` folders.
3. **One run at a time. Never start a run in the background.** Before a run, check that no earlier run is alive
   (`ps -axo pid,command | rg 'pest|phpunit|vitest|playwright|walk.mjs|artisan'`); after it, confirm its processes have exited.
4. **Pest serial with `-d memory_limit=1G`.** **Vitest only by file or folder, through pnpm, with `VITEST_MAX_WORKERS=2`**; never
   `npx vitest run` on a whole app. **Playwright serial, one browser, by spec.**
5. **PostgreSQL locally only by file**, for tests that need locks, unique indexes or transactions (see item 8).
6. If a run looks stuck or the machine slows, stop it and report; never start another on top of it.

7. **Erasure folder before a push.** Any chunk that adds an audit call
   (`Audit::log`, an `Audited` model), a table or a column runs the whole
   Erasure test folder locally before the push:
   `php -d memory_limit=1G vendor/bin/pest tests/Feature/Modules/Erasure`.
   It holds `AuditPayloadKeysGuardTest` (every audit payload key is decided, and
   payloads are literal arrays) and the registry schema tests (every table and
   column is classified). A new audit payload is written as a literal array with
   literal keys; a variable or `toArray()` is a last resort and needs a
   tech-debt row. PL-1.1 reached CI red by skipping this step.
8. **PostgreSQL before a push, when the chunk relies on the database.** Local
   Pest runs on SQLite, which ignores row locks and reports unique violations
   differently from the production database (PostgreSQL aborts the surrounding
   transaction, SQLite does not). A test that depends on a unique index, a row
   lock (`FOR UPDATE`) or a transaction is also run locally on PostgreSQL before
   the push: create a **throwaway** database in the local container
   (`CREATE DATABASE pl12_test`), run the FILES of the chunk that need it (not whole folders) with `DB_CONNECTION=pgsql` and that
   database name, then drop it. A lock test asserts that the SQL contains
   `for update` (it cannot go red on SQLite; mark it to skip there). **Never the
   dev database**, and unset the `DB_*` variables afterwards so the next SQLite
   run does not point at a dropped database. PL-1.1 reached CI red a second
   time (run 449) because an unique-violation test was only run on SQLite.
9. **Audit, Erasure and Tenancy folders, on SQLite (PostgreSQL only by file, rule 5), before every
   push (added 2026-11-18).** Every chunk, not only chunks that add an audit
   call, runs `tests/Feature/Modules/Audit`, `tests/Feature/Modules/Erasure`
   and `tests/Feature/Tenancy` locally on SQLite **and** on PostgreSQL before a
   push. These three folders hold the registrations a new table, column, audit
   verb or payload key must appear in (`AuditActionEnumTest` catalogue,
   `AuditPayloadKeysGuardTest`, the erasure registry, the tenancy allowlist).
   Missing one of them has reached CI red three times: Erasure payload keys in
   PL-1.1 and PL-1.2, the audit catalogue in PL-1.3.

**Feature flags and throwaway databases (standing rule, Pedram, 2026-11-19, after the PL-2.1 tinker incident).** A `tinker` call meant for a throwaway database wrote to the walk database and switched `strategy_enabled` on (corrected within a minute, disclosed). From now on:

- Never write a feature flag by tinker, SQL or any command in a database Pedram uses. Only Pedram flips flags, in the admin app.
- Any command against a throwaway database must name its connection explicitly and first print the database name it is connected to; if the name is the dev or walk database, stop. (`tinker` ignored `DB_DATABASE` here; `migrate`, `db:seed` and `serve` honour it. Check the printed name, do not trust the variable.)

**Before any test run, print the database the run will use (standing rule, Pedram, 2026-11-22, after the PL-2.4 A1 gates).** A `DB_*` variable exported by an earlier command stayed in the shell, so a "SQLite" Pest run in the PL-2.4 A1 chunk went to a PostgreSQL throwaway database (`pl12_test`) and nobody saw it until the totals matched. From now on, for Pest, Vitest-with-API, Playwright and the walk tool alike:

- The command that starts the run first prints the connection and database name it will use, and the run does not start if the name is not the one meant. For SQLite the answer is `sqlite :memory:` and `env | rg '^DB_'` finds nothing. For PostgreSQL it is the throwaway name, which must end in `_test` or `_e2e` (`tests/TestCase.php` refuses anything else).
- Set `DB_*` for one command only (a subshell, or `VAR=value command`), never `export` them in the session. If one is exported anyway, unset every `DB_*` variable straight after the run.
- Report the database each run used next to its totals.

**Stage 2 of a chunk is not run through CI before the walk (standing rule, Pedram, 2026-11-24, after PL-3.1).** Its first CI run can find failures the local, by-file runs did not: PL-3.1 Stage 2 put a notes-panel template ref inside a `v-for` (a ref there is an array), so closing the notes sheet no longer returned focus, and only the existing Playwright spec for the notes panel (`strategy-completion.spec.ts`) saw it, in CI. When a chunk's web work touches a shared part of the workspace (the stepper, the page header, the notes panel), run the existing Playwright specs for that shared part locally, by spec, before offering the walk. Never put a template `ref` under a `v-for`; use `v-if` where the element's DOM must stay as it was.

### 4.2 Stacking, width and overlay defects (lesson, PL-1.2 walk, 2026-11-18)

Stacking, width and overlay defects are invisible to unit tests: a menu behind a dialog, a pill stretched to its column and a list outside the window all passed Vitest and Pest. Any new dialog, menu or board is opened in a real browser, including a quick close and reopen, before a walk is offered to Pedram.

**Design fidelity (standing rule, Pedram, 2026-11-19, after the PL-2.1 walk).** For every screen that has a design frame, before a walk is offered: read the frame with `get_design_context`, open the built screen in a real browser at the frame's width, and put a difference table (element, frame value, built value, VISUAL or FUNCTIONAL) in the review file. Visual differences are fixed. Functional differences are reported to Pedram and left as they are until he rules. "Matches the design" is never written without that table. Screens with no frame are named as built from the design system.

---

## 5. Production-data safety (we are live)

Mirrors the `PROJECT-WORKFLOW.md` §5 standard:

- **Migrations are additive-first.** Nullable/defaulted new columns; no
  destructive DDL on populated tables without a separately-reviewed plan;
  renames go expand→migrate→contract.
- **A migration may be edited in place only while no environment other than
  local and CI has run it (standing rule, Pedram, 2026-11-21, PL-2.4 Q1).**
  From the first merge of `pl` to `main` onward, every PL migration is
  **frozen** and every change is a NEW migration. Before editing in place,
  prove it: the file is not on `main`, and the deploy log and the production
  migration status show it was never run. An in-place edit leaves local
  databases that already ran the old file silently stale; the chunk's review
  names the exact rebuild commands.
- **Migrations modify SCHEMA ONLY — no data writes in a migration file, ever.**
  No backfills, no default-flips on existing rows, no seeding of business data,
  no matter how narrow or how well guarded. **No exception clause.** A migration
  runs unattended in the same breath as the code deploy: no dry-run, no counts
  read, no decision point between "deploy" and "every row rewritten."
- **Every data change is a separate artisan command** — guarded, idempotent,
  `--dry-run`-able — run deliberately **after** `migrate`, with its dry-run
  counts read first and both runs recorded in the `deploy-log.md` entry.
  AH-048 is the reference shape.
  - This **replaces** the old narrow-backfill allowance. **AH-041**'s
    in-migration board backfill was its last use and is **grandfathered, not
    precedent** — left in the repo, no longer citable as a pattern.
- **`down()` must be honest** — true inverse or an explicit can't-restore
  comment.
- **No casual deletion** of user-generated data; soft-delete/archive; any
  row-removing path needs a §5.34 over-reach negative.
- **Every review file has a "Production posture" section:** what the migration
  does to existing rows, what the feature writes, the blast radius of a bug.
- **Pre-deploy snapshot is mandatory** before any deploy carrying migrations,
  backfills, or one-shot commands. Deploy order: snapshot (verify it
  completed) → migrate → infra → one-shots (`--dry-run` first) → smoke-verify
  → record deploy + snapshot ID.
- New emails/notifications to real users ship flag-gated (default OFF), with
  dry-run previews and per-run caps where volume is possible.
- **Install-time tooling must never mutate secrets or `.env`** — pinned by
  architecture test since the 2026-08-11 `APP_KEY` incident (AH-067).

---

## 6. The alarm rule (mandatory, both agents)

Before ANY code is written — at plan-pause (Mode A) or before building each
item (Mode B) — the implementing agent states a production-data risk line:

- `PROD-DATA RISK: NONE` — pure read/UI/additive work. Must be stated
  affirmatively; silence is never acceptable.
- `⚠️ PROD-DATA RISK: …` — names every operation that modifies, deletes,
  migrates, or backfills existing production rows, in plain language Pedram
  can act on ("this rewrites column Z on all rows").

Claude states the same line at kickoff/scoping. A risky operation discovered
mid-build that wasn't declared is a **stop-the-build event**: pause, declare,
wait for Pedram's explicit go. Either agent staying silent is itself a process
violation Pedram can call out.

---

## 7. Review integrity (Claude's rules, learned the hard way)

- **Read the artifacts, never the summary.** Before every push clearance,
  Claude reads the AH entries and review file **verbatim** (uploaded or
  pasted). This has caught factual inversions in shipped docs twice (the
  AH-041 `down()` description; an AH-047 history inversion) plus a stale
  push-state claim in the template. Cursor drafting docs is delegation, not
  skipped review.
- **Counts, enumerations, and specific claims come from files, not chat.**
- **Errors in unpushed docs commits:** amend if it's Cursor's own-session
  commit AND no log entry cites its hash; otherwise a follow-up commit
  (an entry citing a hash must never cause that hash to be rewritten).
- **Spot-checks before docs** whenever a batch crossed the stop-gate: paste
  code/test excerpts verbatim, cover the deepest security surface first
  (uploads, state-machine edges, tenancy scoping, data migrations).
- **Decision-reinterpretation (§5.32):** kickoff decisions name structural
  intent; when code reality diverges, reinterpret the mechanism to preserve
  the intent, and record it. Plausible-looking shortcuts that violate intent
  (e.g. sending `brief: null` instead of omitting the key) are exactly what
  the review exists to catch.
- **Honest limits are stated:** engineering review ≠ legal review (AH-029),
  and unverifiable claims are labeled as such.

---

## 8. Docs & session rituals

- **Docs move with the build:** the AH log entry lands in the same push,
  Proposed → Landed with real SHAs. One theme = one entry; multi-theme
  batches are never one blob.
- **`docs/reviews/adhoc-changes-log.md`** is THE authoritative index; review
  files are authoritative for counts/enumerations.
- **Closed review files are historical record.** Post-close findings go in a
  dated `## Post-close addendum` section; the original text stays verbatim.
- **Session close (§5.39):** Cursor refreshes `RESUMPTION-TEMPLATE.md` Part 2
  (new AH entries, HEAD SHA, open threads, pending deploy obligations) in the
  closing docs commit — the next resumption is copy-paste from the repo, not
  reconstruction from chat.
- **Fresh threads** at major boundaries (both Claude and Cursor); a fresh
  Cursor session orients by reading `PROJECT-WORKFLOW.md`, the AH log, and
  the resumption template, then states the HEAD SHA before starting.
- **KB refresh:** after pushes that move docs, Pedram re-uploads the changed
  files to the Claude Project (delete stale, upload current). The repo always
  wins over stale KB copies — divergences get flagged, not papered over.
- **Push discipline:** nothing pushes until Claude clears it and Pedram says
  go. After push: confirm the remote branch equals HEAD
  (`git rev-list --left-right --count origin/<branch>...HEAD` → `0 0`, with
  `<branch>` = `main` or `pl`).
- **Two branches (`PROJECT-WORKFLOW.md` §12).** `main` is production: fixes to
  the live product and AH work go there. `pl` holds the Production Line
  sprints (PL-1 to PL-9); chunks are `PL-<sprint>.<n>`. Check `git branch
--show-current` before every commit and every push; the HEAD SHA you state at
  the start of a thread is the SHA of the branch you are on, with the branch
  named. `main` is merged into `pl` at least weekly; `pl` goes into `main` only
  at a sprint boundary, on Pedram's word, with every new flag OFF. The
  per-chunk, sprint-end and staging rules are in
  `reviews/production-line-sprints.md`.
- **A push is not complete until CI is green at the pushed tip, cited
  (`PROJECT-WORKFLOW.md` §5.41),** on whichever branch was pushed (CI runs on
  `main` and `pl`). The local gate board is pre-push evidence;
  CI is the post-push confirmation that the same claim holds off the
  developer's own machine — and it is not redundant with the local run: two
  chunks (AH-068, AH-069) shipped with a green local board while CI had been
  red for days, because the boards were laptop-local and nothing in the
  close-out looked at GitHub (AH-070). Cite the run URL in the close-out. A
  red CI at the tip reopens the chunk — it does not ship because the feature
  commit already did.

---

## 9. Standing reference points (as of AH-048)

- **Stop-gate triggers** (= full-loop territory): schema/migration, API field
  or resource shape, validation rule, policy/gate/guard, route table, i18n
  key, state-machine edge, scheduled job, anything security-relevant.
- **Command pattern** for data operations: idempotent, `--dry-run`,
  `--limit=N` where volume is possible, oldest-first draining, count summary,
  loud failure on bad input, flag-gated where user-facing.
- **Deploy obligations tracker** lives in `RESUMPTION-TEMPLATE.md` Part 2
  (one-shot commands, cron lines, first-enable rituals).
- **Known environmental quirks:** composer's 300s timeout (run Pest
  directly), Pest parallel OOM (serial + 2G), PHPStan needs `--memory-limit`,
  three untracked vitest timestamp artifacts are harmless, Playwright needs
  the dev stack down (port 8000 guard) and its own E2E DB.
- **Open standing item:** backup/restore posture (RDS snapshots, PITR,
  a **rehearsed** restore) is unverified — owned by Pedram; the
  production-data standard is incomplete until one restore has been tested.
