# Deploy log — what production runs, and when

**This file is the authoritative record of what is deployed to production.** One entry per
production deploy, newest first. If you want to know what code production is running, or what
shipped on a given day, this is the file — not the push history, not a chat thread, not an AH
entry.

**Why it exists.** Push state and deploy state are different facts, and they drifted for weeks
because deploy state was carried inline in `RESUMPTION-TEMPLATE.md` Part 2 while deploys are
**colleague-managed and advance without notice**. A fact maintained in two places goes stale in
one of them. So deploy state now lives here and **only** here: the resumption template points at
this file rather than restating it, exactly as push state was collapsed to `origin/main` being the
single authority.

**The standing convention this file exists to serve: pushed ≠ deployed.** Never infer a deploy
from a push. Deploy state is **read from the server** — `php artisan migrate:status` for schema,
`supervisorctl status` / `crontab -l` for the worker and scheduler — and then written down here.

**How to use it.**

- **Before a deploy:** open a new entry at the top with the template below, filled in as far as
  you can (range, AH entries, migrations, obligations), marked `PENDING`.
- **During:** fill the blanks as each step of the runbook checklist completes
  ([`production-queue-worker.md` §8](production-queue-worker.md)). The snapshot ID goes in
  **before** the migration runs, not after.
- **After:** flip the status to `DEPLOYED`, record the verification results, and record anything
  that surprised you — that last field is the one that pays for the file.

**Related.** Deploy **procedure** is `production-queue-worker.md` §8 (the checklist), §8.3 (the
Jobs Board arc's single deploy), §7.3–7.4 (flag first-enable rituals). Deploy **obligations** per
change are in the AH entries (`../reviews/adhoc-changes-log.md`). This file records what actually
happened.

---

## Entry template (copy this)

```
### YYYY-MM-DD · <short description> — STATUS

- **Status:** PENDING | DEPLOYED | FAILED / ROLLED BACK
- **Range:** `<from-SHA>` → `<to-SHA>` (from-SHA exclusive: the previous deployed tip)
- **AH entries carried:** AH-NNN → AH-NNN (list them, or name the arc)
- **Migrations run:** <count> — every name, or "none"
- **Pre-deploy reads:** command + the number it returned, or "none"
- **Snapshot ID:** <identifier> · confirmed `available` before migrating? yes/no
- **Infra:** worker restart (required? done?), cron/scheduler changes, env changes
- **One-shot commands:** each command + its dry-run counts + its real-run counts, or "none"
- **Post-deploy verification:** `/up` status, the authenticated request, any change-specific reads
- **Flags armed:** which, or "none — <why>"
- **Operator:** who ran it
- **Anything unexpected:** the honest field. "Nothing" is a valid answer; silence is not.
```

---

## 2026-07-31 · The Jobs Board arc + the post-arc UI batch — PENDING

- **Status:** **PENDING** — Pedram is deploying today. Fields marked _TBD_ are filled after the
  run. Procedure: [`production-queue-worker.md` §8.3](production-queue-worker.md), which governs
  this deploy.
- **Range:** `f5be920` → `6601ba0` — **60 commits.** This is the **entire undeployed backlog**: every
  commit since the 2026-07-26 deploy, in one operation. `6601ba0` is the last **code** commit in the
  range; the docs commit that adds this log sits on top of it and carries no runtime change, so the
  next deploy's from-SHA is whatever tip is actually checked out, not necessarily `6601ba0`.
- **AH entries carried:** **AH-053 → AH-064.** The five-chunk Jobs Board arc (AH-053, AH-054,
  AH-056, AH-058, AH-059), the AH-055 brand-detail fix, the AH-057 eyes-on fix pass, and the
  AH-060→AH-064 UI batch. **The arc must not ship as a subset** — a partial deploy produces
  half-states nobody designed (a listable campaign with no board to list it on, or a board whose
  applications no agency can answer). §8.3 is explicit on this.
- **Migrations run:** **4**, all additive, all from arc chunks 1–3 (chunks 4 and 5 add none, and
  the whole AH-060→064 batch adds none — it has **zero `apps/api/**` diff\*\*):
  1. `2026_07_27_100000_add_jobs_board_listing_to_campaigns` (AH-054) — six nullable columns + one
     boolean defaulting `false`. No backfill.
  2. `2026_07_27_110000_create_campaign_applications_table` (AH-056) — new table. **`down()` is
     lossy.**
  3. `2026_07_27_110001_create_campaign_job_notifications_table` (AH-056) — new table, the
     once-per-pair fan-out stamp. **`down()` is lossy.**
  4. `2026_07_27_110002_add_listed_at_to_campaigns` (AH-056) — one nullable timestamp. No backfill.

  No existing row is read or rewritten by any of the four. **Rollback is not a revert:** dropping
  the two new tables destroys every application and every notification stamp. After creators have
  applied, restore from the snapshot instead.

- **Pre-deploy reads:** `php artisan brands:audit-floor` (AH-053) — a pure read, **run before
  shipping**, reporting how many brands each completeness-floor field blocks and the lifecycle
  split of the blocked population. It matters because AH-053 makes an incomplete brand's **next
  edit** return 422 — a behaviour change for existing data — and this is how the size of the
  affected population becomes knowable in advance rather than through support tickets.
  **Result: _TBD_.**
- **Snapshot ID:** _TBD_ · confirmed `available` before migrating? _TBD_ — **non-negotiable here**,
  because step 2 carries two lossy-`down()` `CREATE TABLE`s. Note the standing caveat: the
  restore path has **never been rehearsed** (§8.2), so lean conservatively.
- **Infra:** **the queue-worker restart is MANDATORY on this deploy**, not optional. The arc carries
  four new mailable classes and new `lang/**` copy across 24 locales, and a long-running worker
  caches translations in memory — it will keep sending missing-key bodies until bounced. No
  cron/scheduler change: nothing in this range is scheduled. **Done: _TBD_.**
- **One-shot commands:** **none.** The arc has none, and the UI batch has none.
- **Post-deploy verification:** _TBD_ — `/up` 200, one authenticated request, plus the two
  arc-specific reads from §8.3 step 5: a creator's **Jobs** page loads and is **empty** (the
  correct result at T+0, not a failure), and a campaign's **Settings** tab plus the campaigns
  **list** page both render their listing toggle. **Do not flip either during smoke** — the first
  real listing is a product decision.
- **Flags armed:** **NONE — deliberately deferred.** Both jobs-board mail flags
  (`job_posted_notifications_enabled`, `application_notifications_enabled`) ship and stay **OFF**.
  Arming them is the combined ritual in §7.4, done when Pedram chooses, and **this deploy is
  complete and correct without it**. Also still OFF and unrelated:
  `incomplete_creator_nudge_enabled` (blocked on the scheduler, §7.3).
- **Operator:** Pedram.
- **Anything unexpected:** _TBD_.
- **Operator notes carried by this range** — nothing to do at deploy, but worth knowing if
  something looks odd afterwards:
  - **Assignment audit trails gain a row that has no tab behind it** (AH-058 D3b). `POST …/assignments`
    now settles a pending application for the pair it invites, in the same transaction, so an
    operator reading audit history will sometimes see a `campaign_application.accepted` row beside
    an invite nobody accepted from the Applications tab. A pair with **no** application is
    byte-identical to before, pinned field by field.
  - **The campaigns-list listing toggle shortens the path to a real fan-out** (AH-059 D3). It drives
    the same PATCH the Settings tab drives, so once the mail flags are armed a mis-click on a table
    row is one round-trip from notifying real creators. The ON direction sits behind a confirmation
    dialog for exactly that reason; OFF stays immediate. Inert at T+0 and while the flags are OFF.
  - **A 5.6 MB PDF joins the deploy artefact** (AH-063, `apps/main/public/creator-guide.pdf`).
    Harmless, but every build and deploy now carries it; logged as tech-debt with
    `catalyst-engine-public-prod` as the CDN target.
  - **The worker restart rule is the arc's, not the UI batch's.** AH-060→064 ships new copy only in
    the SPA's own `locales/**`, not `apps/api/lang/**`, and the worker renders mail from the latter.
    The restart is mandatory here on the **arc's** grounds (four new mailables, 24 locales of new
    backend copy).

> **⚠ One thing in this range goes live immediately and has no flag: the Meta Pixel (AH-064).**
> From the moment this deploys, every visitor to `/sign-in` — the platform's front door — is
> tracked without consent and `_fbp` is set on their browser. That is Pedram's recorded decision,
> taken with the §2.1/§2.7 consent conflict and the UK PECR exposure on the table, and it is logged
> in `../tech-debt.md` with a Sprint-14 resolve-by against the CMP. It is called out here because
> it is the only externally-visible consequence in this deploy that is **not** dormant behind a
> flag: there is no switch to turn it off, only a code change. Everything else the arc adds is inert
> at T+0.

> **Why T+0 is quiet otherwise.** At deploy, **zero campaigns are listed** —
> `listed_on_jobs_board` is a new column defaulting `false`, and the surfaces that flip it are in
> this same deploy. So the board is empty, no fan-out has a recipient, no application exists to
> answer, and every path the arc adds is inert until an operator lists a campaign. That is the
> arc's primary containment, and it is a fact about the data rather than a hope.

---

## 2026-07-26 · AH-051 / AH-052 — canonical 403 envelope + admin-initiated connections — DEPLOYED

- **Status:** DEPLOYED. This is the tip production ran until the 2026-07-31 deploy above.
- **Range:** → `f5be920`
- **AH entries carried:** AH-051 (admin-initiated agency↔creator connections, the AH-005 contact-gate
  tightening, the first termination path via `ended`) and AH-052 (canonical 403 error envelope),
  plus the AH-051 post-close eyes-on fixes.
- **Migrations run:** **none.** `php artisan migrate` correctly reported **`Nothing to migrate`** —
  `ended` is a plain-varchar enum value with no CHECK constraint, so the sixth `RelationshipStatus`
  needed no schema change.
- **Pre-deploy reads:** none as such — the contact-exposure audit was run **post**-deploy (see
  below), because the gate was already live by the time it was read.
- **Snapshot ID:** not recorded. (Reconstructed entry — the snapshot step predates this log, so
  whether one was taken is not on record. With no migration in the range the exposure was low, but
  the ID is missing, and that gap is part of why this file exists.)
- **Infra:** the queue-worker restart **was** required and **was** performed — the range carries the
  `530d7d8` / `bdc957b` admin-connected mail-body trims across 24 locales. This is where the
  standing rule came from: the new copy did not appear until the worker was bounced, because the
  long-running process caches translations in memory.
- **One-shot commands:** both of the then-outstanding one-shots were run, each dry-run first, and
  **both are now closed**:
  - `php artisan creators:recompute-completeness` (AH-026 D5) — **279 creators checked, 1 score
    updated.** The near-zero delta is the expected result, not a failure: it means the persisted
    scores were already consistent with the AH-026 formula for all but one row.
  - `php artisan campaigns:advance-contractless-accepted` (AH-042 D4) — **0 eligible rows**, so it
    was closed as moot. Nothing was ever stuck at `accepted` on a `requires=false` campaign. The
    command stays in the codebase as a safety net.
- **Post-deploy verification:** `/up` 200 + an authenticated request. Additionally
  `php artisan migrate:status` was read on this date and reported **Ran through batch 5** — the read
  that established the pre-history below, and which incidentally revealed that everything through
  AH-050 was **already live** while the resumption template still listed its migrations as pending.
- **Post-deploy read, recorded with no action taken:** `php artisan relations:audit-contact-exposure`
  (AH-051 D-1) — **2 `pending_request` relations across 1 agency, of which 1 had contact data
  populated.** That is the realized blast radius of the contact-gate tightening: one agency lost
  visibility of one creator's contact details. Judged small enough that no remediation or
  notification was warranted.
- **Flags armed:** none.
- **Operator:** Pedram.
- **Anything unexpected:** **two things, both of which became standing rules.** (1) The
  queue-worker translation cache, above — found the hard way while verifying the mail trims, and now
  a permanent deploy obligation for any `lang/**` change. (2) **AH-052 changed a client-visible
  contract in production**: every `authorize()` denial (82 call sites) and every `abort(403)` now
  returns the JSON:API error envelope with code `auth.forbidden` instead of Laravel's default
  `{"message": …}`. Both SPAs consume the envelope and were verified; the residual exposure is
  anything **outside this repo** that pattern-matched the old shape. Live since this date, so it is
  a thing to check **if** 403 handling misbehaves, not a pre-deploy gate.

---

## Pre-history — the colleague-era deploys (RECONSTRUCTED, dates unknown)

> **⚠ Everything in this section is reconstructed, not recorded.** These deploys happened before
> this log existed and were **colleague-managed**, so no operator, snapshot ID, verification result
> or date survives for any of them. What follows is inferred from two sources only: the prod
> `php artisan migrate:status` read on **2026-07-26** (which reported **Ran through batch 5**, with
> `2026_07_13_110000_backfill_cancelled_rejected_board_column` in **batch 3**), and the migration
> filenames mapped onto the AH entries that introduced them. **Treat the batch→AH mapping as a
> hypothesis, not a record.**
>
> Ordered **oldest first** here — the deliberate exception to this file's newest-first rule, because
> these entries are undated and only their relative order is knowable.
>
> **How to close this gap, if it ever matters:** run `php artisan migrate:status` on prod and paste
> the full output into this section. That resolves every batch boundary in one read. Nothing else
> can — the information does not exist anywhere else.

| Batch | Migrations (by date cluster)                                                                                                                                                                                                                                               | AH range / origin                                                                               | Confidence                                                                                                                  |
| ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| 1     | The Phase-1 spine — `0001_01_01_*` through the Sprint 0–13 build (users, agencies, creators, campaigns, boards, notifications, messaging, contracts, talent pools, impersonation). **56 migrations.**                                                                      | Sprints 0–13 + 3.5, pre-AH                                                                      | **Inferred.** The initial production deploy; the batch boundary is not on record. The count is exact (73 total − 17 later). |
| 2     | `2026_06_27` → `2026_07_08`, **7 migrations** — portfolio `processing_status` (`7b62272`, **AH-004**), creator contact details (`5dc1e1f`), the three relationship-messaging tables (`2656e5a`, **AH-010a**), creator `accent` (`7faeff8`), users `last_name` (`ce3bbda`). | AH-004 and AH-010a confirmed from the adding commits; the other three commits name no AH number | **Inferred** from the date cluster. Commits are exact (`git log --diff-filter=A`); the batch grouping is not.               |
| 3     | `2026_07_12` → `2026_07_13` — assignment offer fields, `previously_declined`, draft `links`, and the board-column backfill.                                                                                                                                                | AH-033 → AH-041 (the direct-iteration fix batch), incl. AH-034, AH-035, AH-040, AH-041          | **Anchored** — the AH-041 backfill is the one migration prod explicitly placed in batch 3.                                  |
| 4     | `2026_07_16_100000_add_incomplete_nudge_sent_at_to_creators_table`                                                                                                                                                                                                         | AH-048 (incomplete-creator nudge) — additive-nullable column, no backfill, no index             | **Inferred.** Confirmed `Ran`; the batch number is not on record.                                                           |
| 5     | `2026_07_19_100000_add_content_companions_to_creators_table`                                                                                                                                                                                                               | AH-050 (content companions) — additive-nullable jsonb                                           | **Inferred**, but bounded: batch 5 was the highest on 2026-07-26, and this is the newest migration that was `Ran` by then.  |

**What is solid about the pre-history, regardless of batch numbers:** as of the 2026-07-26 read,
**every migration through `2026_07_19` was `Ran`** and nothing was pending. AH-042 through AH-052
added no migrations at all. So the pending-migration list was **empty** going into the 2026-07-26
deploy, and the next four pending migrations are the arc's — the ones in the 2026-07-31 entry above.

**The lesson this pre-history taught, now a standing convention.** Everything through AH-050 turned
out to be **already live** while the resumption template still listed its migrations as pending.
Deploys advance without notice, so deploy state must be **read from the server and written down**,
never inferred from push history. That is the rule this file exists to enforce.
