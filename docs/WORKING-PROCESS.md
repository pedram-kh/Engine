# WORKING-PROCESS.md — How we build Catalyst Engine

> The canonical description of the three-party workflow, its rituals, and its
> non-negotiables. If this file and `PROJECT-WORKFLOW.md` §5 disagree, the repo
> wins — but flag the divergence and reconcile it.
>
> **Last updated:** 2026-07-16 (post-AH-048).

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

---

## 5. Production-data safety (we are live)

Mirrors the `PROJECT-WORKFLOW.md` §5 standard:

- **Migrations are additive-first.** Nullable/defaulted new columns; no
  destructive DDL on populated tables without a separately-reviewed plan;
  renames go expand→migrate→contract.
- **Data mutations ship as guarded, idempotent, dry-runnable commands**, never
  migration side effects — except narrow backfills that are idempotent,
  predicate-guarded, and test-pinned including the leaves-everything-else-
  alone case (AH-041 and AH-048 are the reference shapes).
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
  go. After push: confirm `origin/main = HEAD`
  (`git rev-list --left-right --count origin/main...HEAD` → `0 0`).

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
