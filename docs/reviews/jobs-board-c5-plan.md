# Jobs Board arc — chunk 5 (eyes-on fixes, board Applications column, lifecycle reflection, full-loop E2E, arc close-out) — PLAN (plan-pause)

- **Status:** Plan-pause. **No code written.** Awaiting Claude's clearance before sub-step 1.
- **Date:** 2026-07-29
- **Author:** Cursor, against Claude's chunk-5 kickoff (D1–D7), entry **AH-059**.
- **HEAD:** `5cc382cbd3872f2e32eda85d36aa0e9382d65a07` (`5cc382c`) — `docs(jobs-board): close AH-058 —
chunk 4 review approved`. **Working tree clean** (`git status --porcelain` empty).
- **HEAD = `origin/main`**, verified live with `git ls-remote origin main` →
  `5cc382cbd3872f2e32eda85d36aa0e9382d65a07`. `git rev-list --left-right --count origin/main...HEAD`
  → `0 0`. Nothing held. Contrast c4, which planned from one commit ahead.
- **AH id:** **AH-059** is free — the log's newest entry is AH-058 (`adhoc-changes-log.md:73`), and
  `rg AH-059 docs/` returns nothing.
- **Orientation re-read at plan time:** `docs/PROJECT-WORKFLOW.md` (all of §5, 5.1–5.40),
  `docs/WORKING-PROCESS.md` (all 9 sections), `docs/reviews/jobs-board-c3-review.md` **including its
  dated post-close addendum**, `docs/reviews/jobs-board-c3-eyeson-fixes.md` **including its closing
  note**, `docs/reviews/jobs-board-c4-plan.md` (the plan format and its Q/C discipline),
  `docs/reviews/jobs-board-c4-review.md` (closed — approved), `docs/reviews/adhoc-changes-log.md`
  (AH-058 → AH-053), `docs/reviews/RESUMPTION-TEMPLATE.md` Part 2, `docs/feature-flags.md`,
  `docs/runbooks/production-queue-worker.md`, `docs/tech-debt.md`.

---

## 0. The §5.40 line, re-derived

> **⚠️ PROD-DATA RISK: LOW-MEDIUM.** Same grade Claude declared. **No migration** — nothing in D1–D7
> alters a table, so `down()` honesty is trivially satisfied: there is nothing to reverse. Four of the
> five eyes-on decisions are **read-only or display-layer**. One is not, and it is the whole reason
> this is not `NONE`.
>
> **D3 is the risk, and it is a REACHABILITY escalation of an existing irreversible write.** The
> list-page toggle adds **no backend code at all** — it drives the same
> `PATCH /api/v1/agencies/{agency}/campaigns/{campaign}` the Settings tab drives today
> (`Campaigns/Routes/api.php:40-41`). What changes is how many clicks stand between an operator and
> that endpoint. Today: open a campaign → Settings tab → flip a switch → **Save** a full form.
> Tomorrow: **one click on a table row.** And the false→true flip is not undoable in the way a
> boolean suggests:
>
> - it stamps `listed_at` **once, on the flip only** (`CampaignController.php:177-187`);
> - it dispatches `SendJobPostedNotificationsJob` (`:198-206`), which — with
>   `job_posted_notifications_enabled` **ON**, and it **is** ON in this repo's dev database and is the
>   flag Pedram will arm first in production — **mails up to 50 rostered creators** per flip
>   (`JobPostedFanOutService::DEFAULT_LIMIT = 50`).
>
> Delisting afterwards un-lists the campaign. It does not un-send the mail. So a mis-click on a row in
> a table is now one round-trip away from an outbound fan-out to real creators, and that is a genuinely
> new exposure even though the code that does it is byte-identical. **This is why Q6 asks for a
> confirmation step on the ON direction** rather than treating the toggle as symmetric.
>
> **The containment is real and worth stating in the same breath.** The once-only `(campaign, creator)`
> stamps in `campaign_job_notifications` mean a _second_ flip of the same campaign notifies nobody
> (`JobPostedFanOutTest.php:550-569`), so repeated flips are safe — that is the kickoff's "assert,
> don't rebuild", and it is already pinned. The fan-out is also flag-gated, capped, and roster-scoped
> (approved + rostered + not brand-hard-blacklisted).
>
> **D4 puts accept and reject on a second surface, and creates nothing new.** The board's Applications
> column reads `campaign_applications` through the existing list endpoint and calls the existing
> accept/reject endpoints — the ones reviewed at MEDIUM in c4, with their transaction, their gate list
> and their ten mutations intact. No `board_cards` write, no automation row, no new endpoint. The
> §5.40 delta over c4 is **zero**; the review priority is proving that zero (a `git diff --stat` on the
> Boards module and on `database/migrations` that comes back empty).
>
> **D1 and D5 are read-time derivations.** Two additive resource fields on creator-facing payloads and
> one mapping function. No column, no event, no sync, no write of any kind. The blast radius of getting
> the mapping wrong is a **wrong label on a card** — which is exactly the class of bug D1 exists to
> fix, so shipping it wrong would be ironic rather than dangerous.
>
> **D2 writes nothing.** The investigation is complete (§2) and its outcome is **no mail-path defect**.
> The remedial work it does justify is a corrected docblock, one log line, and one test.
>
> **D6 touches no production data but carries a known dev-data landmine, restated because I am about to
> run it.** `playwright/global-setup.ts:53-63` runs an **unconditional `migrate:fresh`** against
> `DB_DATABASE=catalyst_e2e`; `reuseExistingServer: false` on the API webServer is the only thing
> standing between that and Pedram's `catalyst` dev database. The dev stack must be **down** for the
> run. New this session (§1, C5): the **Redis queue is shared** between the two databases, which is a
> second, previously unrecorded way the two environments can contaminate each other.
>
> **Blast radius of a bug, worst first:** (1) an accidental listing flip from the table fanning mail to
> up to 50 real creators — mitigated by Q6's confirm and by the fact that the gate refuses an
> incomplete or terminal campaign before anything is written; (2) a D4 action wired to the wrong
> application ULID — accepting or rejecting the wrong applicant, guarded by the same server-side
> pending-only and tenancy checks as the tab and pinned by a spec that asserts the ULID on the wire;
> (3) a D5 mapping that swallows a future enum case and renders a live engagement as "Ended" —
> defended by an exhaustive `match` with no `default` arm plus a catalogue test over
> `AssignmentStatus::cases()`; (4) a D1 branch order that keeps the contradiction alive in a case
> nobody enumerated (Q2); (5) the E2E suite pointed at the wrong database.
>
> **At T+0 the reachable population is still provably zero for the applications half.** The whole arc
> is undeployed and deploys as one unit, so at deploy no campaign is listed and no application exists.
> D3's escalation becomes real the moment the first campaign is listed, not at deploy.
>
> **Deploy stays held.** This chunk ends the arc and produces the single-deploy runbook (D7); it does
> not deploy anything.

---

## 1. Read-pass findings that affect the kickoff

Six items. **C1 and C2 change what gets built.** C3 and C4 change the shape of a sub-step. C5 and C6
are findings the kickoff could not have known, one of which belongs in `tech-debt.md`.

### C1 — ⚠ D1's premise is half true: the CARD does not carry `assignment_ulid`, and c4 has a test that says it must not

The kickoff states "the D7 subquery already delivers `assignment_ulid` unconditionally — the surfaces
just don't consult it on the rejected branch." That is **true of the detail and false of the card.**

- The detail resource emits it (`CreatorJobDetailResource.php:79-84`), fed by
  `callerAssignmentUlidSubquery()` (`CreatorJobBoardController.php:358-366`), which is added **only**
  in `show()` (`:170-172`), not in `index()`.
- The card resource has no such key (`CreatorJobCardResource::cardAttributes():85-98`), and
  `CreatorJobDetailTest` asserts positively that **the card must not include it**
  (`CreatorJobDetailTest.php:342-355`) — that assertion exists because c4's **Q7 ruled "detail only"**.

So "display-layer fix, no resource change" cannot hold for the card. The card's rejected chip renders
from `application_status` alone (`CreatorJobsPage.vue:114-122`), and no client-side change can teach it
about an assignment the server never sent it.

**Ruling I propose (§5.32 reinterpretation, and it makes the chunk cheaper rather than more
expensive).** D1's structural intent is _"never render 'Not selected' beside a live invitation for the
same campaign."_ Its named mechanism was "consult `assignment_ulid`". **D5 independently requires a
coarse assignment state on both the card and the detail.** So one new derived field serves both
decisions:

- both resources gain **`assignment_state`** — `'in_progress' | 'completed' | 'ended' | null`,
  unconditional, `null` when the pair has no assignment;
- **`assignment_ulid` stays detail-only**, so **Q7's ruling is preserved, not reversed** — Q7 was about
  the _link_, and the card still has no link to give. The `:342-355` assertion stays **green
  untouched**, which is the tell that this is a reinterpretation rather than a reversal;
- D1's fix becomes _branch ordering_: the reflection branch precedes the rejected branch. The
  contradiction dies as a **structural consequence** of D5 rather than as a second special case.

`CARD_KEYS` gains one entry and `DETAIL_KEYS` gains one (it spreads `CARD_KEYS`, so the diff is one
line — `CreatorJobDetailTest.php:44-65`). Both reds are the accretion tripwire working, exactly as
c4's C6 described for `assignment_ulid` itself.

**And the subquery this needs is safe to write as a scalar.** `callerAssignmentUlidSubquery()` uses
`->limit(1)` with no ordering, which is only honest if the pair is unique — and it is:
`unique_assignment_campaign_creator` on `(campaign_id, creator_id)`
(`2026_..._create_campaign_assignments_table.php:129`). The sibling status subquery inherits that
guarantee. Stating it because a correlated `limit(1)` over a non-unique pair is a silent
wrong-row bug, and c4's mutation 9 already showed what an incomplete correlation on this exact
subquery costs.

### C2 — ⚠ D2 is NOT a bug. The flag was never armed, and the evidence is unambiguous

Full forensics in **§2**. Headline: `application_notifications_enabled` has been `false` in the dev
database since the row was created, was **never toggled**, and Mailhog contains **zero** application
mails of any kind — including the manual reject the kickoff cites as the working half of the
asymmetry. There is no `campaign_closed` variant defect to fix. What the investigation _did_ find is
an observability hole and a docblock that contradicts its own code, and those are what S2 addresses.

**This means review priority 2 changes shape**, and it changes it in the direction the kickoff
explicitly allowed ("fix or explain", "if the queued mail is sitting unprocessed, say so — symptom
explained, no bug"). It also means **no §5.3-style gap-test is owed**, because no gap exists in the
mailable tests: `ApplicationMailTest` real-renders the `campaign_closed` body and asserts it differs
from the `agency_rejected` one (`:132-145`). The test that would have saved the eyes-on hour is not a
mailable test at all — it is a **log line**, and it does not exist. See S2 and **Q9**, which asks
Pedram to confirm the one premise I cannot verify from data.

### C3 — the reject confirm dialog is inline in `ApplicationsTab.vue`, so D4 forces the c4/Q2 choice again

D4 says "actions = the existing Accept (offer dialog) / Reject (confirm dialog)". `AcceptApplicationDialog`
is already a standalone component and drops straight in. The reject confirm is **not** — it is an inline
`v-dialog` inside `ApplicationsTab.vue:330-364`, alongside the tab's own request/error state.

This is the same fork c4 resolved as Q2 (`InviteCreatorsDialog` → extract `OfferFieldsForm`), and the
c4 review recorded why the extraction won: _"there is one implementation of fee/currency/attachment
validation and two consumers of it"_ — an anti-drift argument that is structural rather than a
convention. **I propose the same shape:** extract `RejectApplicationDialog.vue` plus a
`useCampaignApplications` composable holding the list/accept/reject calls and their `ApiError` code
mapping (`application.not_pending`, `application.already_engaged`), consumed by **both**
`ApplicationsTab` and the new board column. Cost: one refactor of a live component, guarded by its
existing 11 specs, which is precisely the guard that held for `InviteCreatorsDialog`. See **Q7**.

### C4 — the board's no-drag requirement is satisfied by ABSENCE, not by a disabled flag, and the seam has two candidates

Card drag is one `<draggable group="board-cards">` per column with **no** `:disabled`, no `put`/`pull`
object and no `move` predicate (`BoardColumn.vue:121-135`); the move fires only on `evt.added` (`:47-51`).
Column reorder is a **separate** `<draggable group="board-columns">` over `localColumns`
(`BoardColumns.vue:61-69`).

So the strongest enforcement is that the Applications column **contains no `<draggable>` at all** and
**is not a member of `localColumns`**. Nothing can be dropped into a list that is not a drop target,
nothing can be dragged out of it, and it cannot be reordered with the real columns. That is better than
`:disabled="true"` because it cannot be re-enabled by a later "consistency" pass, and it is
**assertable as a negative** (§5.34): the applications column renders zero draggable wrappers and zero
`.board-card` elements.

Where it mounts has two honest answers and I want the ruling (**Q7**): as the first child of
`.board-columns-root` **inside** `BoardColumns` (it scrolls with the board, reading as a real first
column — my lean, and what "first column of the board grid" says), or as a sibling in `BoardView`
(it becomes a sticky first column that stays put while the board scrolls — arguably nicer, but no
longer literally _in_ the grid).

Two supporting facts for the build: real columns are `flex: 0 0 300px` (`BoardColumn.vue:139-151`), so
the pseudo-column reuses 300px and the same header grammar (dot, name, count chip); and the board has
**no realtime push** — it is a 30s poll plus a fresh `load()` on mount (`useBoardPoll.ts:17`,
`BoardView.vue:102-108`). So D4's "on accept the application card leaves while the real card appears in
Invited" needs the accept handler to refetch **both** the applications list and `boardStore.refresh()`,
or the operator watches a stale board for up to 30 seconds. That is wiring, not machinery — the
listener and the automation are untouched and asserted.

### C5 — ⚠ the dev and E2E environments share one Redis queue, and it has been silently poisoning `failed_jobs`

Found while investigating D2. `.env` sets `QUEUE_CONNECTION=redis` on `REDIS_PORT=6380`; the Playwright
config overrides only `DB_DATABASE=catalyst_e2e` (`playwright.config.ts:59, 91`) and leaves the queue
connection alone. So **E2E runs enqueue into the same `queues:default` list the dev worker consumes**,
and the dev worker executes them against the `catalyst` database.

The evidence is 158 rows in `failed_jobs`, **every one of them** a stale `VerifyEmailMail` or
`InviteAgencyUserMail` failing with `ModelNotFoundException` on `User` / `Agency` — models that existed
in `catalyst_e2e` until `migrate:fresh` deleted them, in jobs that outlived the database they belonged
to. The most recent batch failed at `17:41:40`, six seconds after the current worker started, draining
a backlog left by an earlier E2E run.

Consequences worth recording rather than fixing here: `failed_jobs` is **not a usable diagnostic
signal** on this host (a real failure would be one row among 158 look-alikes), and an E2E run competing
with a dev worker can process each other's jobs. **Proposed: a `tech-debt.md` entry**, not a fix — the
fix is a `QUEUE_CONNECTION` override in the Playwright webServer env plus a one-time flush, and doing
it inside an arc close-out chunk is the rider this codebase avoids. Flagging it as a candidate for
Pedram to rule in if he wants it (**Q10**).

### C6 — ripple the kickoff's list did not enumerate

- **`CampaignDetailPage.vue` has no `?tab=` deep link.** `tab` is a plain `ref('overview')`
  (`:221`) with no route-query sync. So D3's failure dialog cannot link an operator to the Settings tab
  where the missing fields live — the best it can do without new capability is link to the campaign
  detail, which opens on Overview. Folded into **Q6**.
- **The list page has no role gate.** `CampaignListPage.vue` never reads the current role, while the
  endpoint the toggle calls is `Gate::authorize('update')` — **admin + manager only**
  (`CampaignPolicy.php:49-52`), narrower than the `invite` tier the applications actions use. Staff
  must see the read-only chip, and that is a §5.34 negative case, not a nicety.
- **The i18n for D3's missing-field names already exists** — `app.campaigns.listing.floorFields.*`
  ×24, shipped for the Settings hint. The dialog reuses them, so the field names cost **zero** new
  leaves; only the dialog chrome is new.
- **`ApplicationsTab`'s `canAct` is fed by `canInvite`** (`CampaignDetailPage.vue:867`), which is the
  correct mirror of the server's `Gate::authorize('invite')` and therefore includes **staff** — c4's Q4
  ruling, pinned by three tests naming staff explicitly. The board column must be handed the **same**
  boolean, not `canConfigure` (admin+manager) which `BoardView` already receives. Getting this wrong
  silently removes a capability staff has today.
- **`AssignmentStatus::isTerminal()` is NOT the "Ended" predicate.** It returns true for
  `PaymentReleased` (`AssignmentStatus.php:72-81`) — a _successful_ terminus. Reusing it for D5's Ended
  family would render a fully-paid engagement as "Ended". Named here so the mapping is written from the
  enum, not from the nearest-looking helper.

---

## 2. The D2 investigation — result, with evidence

**Method.** The dev stack was already up and had been up since the eyes-on session
(`php artisan queue:work --memory=768`, pid 14336, started 2026-07-28 17:41), with Mailhog listening on
1025/8025 and `QUEUE_CONNECTION=redis`. So the eyes-on session's own state was still on disk and
nothing had to be reproduced — the forensics are **from the session that produced the symptom**, which
is strictly better evidence than a re-run. All reads; nothing was written, no flag was touched, no row
was modified.

### The mechanism, named

**The mail leg was correctly silent because `application_notifications_enabled` was OFF for the entire
eyes-on session. It was never turned on.** Both reject paths behaved identically and both behaved as
designed. There is no defect in the `campaign_closed` variant, no missing translation key, no swallowed
render failure, and no unprocessed queue backlog.

### Evidence, five independent confirmations

**1 — the flag row says OFF and says it was never edited.** The `features` table holds exactly one row
for the flag: `application_notifications_enabled`, scope `__laravel_null`, value `false`,
`created_at = updated_at = 2026-07-28 17:42:59`. Pennant writes that row when a default resolves; an
`activate()` would move `updated_at`. It has not moved.

**2 — the audit log has no toggle for it.** `audit_logs` contains exactly **two**
`feature_flag.toggled` rows in the database's whole history:
`job_posted_notifications_enabled → true` (2026-07-27 21:42:39, reason recorded) and
`incomplete_creator_nudge_enabled → true` (2026-07-17). There is **no row** for
`application_notifications_enabled`. The admin toggle endpoint writes that row on success, so the
endpoint was never successfully called for this flag. And it is not an allowlist bug of the AH-056
class: the flag **is** in `AdminFeatureFlagController::FLAGS` (`:84-86`), which is both the SPA
allowlist and `toggle()`'s validator.

**3 — Mailhog contains zero application mails, and the gap sits exactly where the emissions were.**
153 messages retained, continuous across the session. Searching the whole store for `application`
returns **0 hits**; for `selected` (the rejected copy's own word), **0 hits**. Every message after
18:30 is a `Catalyst posted a new job` (the c3 fan-out, whose flag _is_ ON):

| time                    | what Mailhog holds                                |
| ----------------------- | ------------------------------------------------- |
| 18:30:06–07             | 7 × job-posted                                    |
| **18:32:38**            | — application **submitted** (nothing)             |
| **18:39:58**            | — application **accepted** (nothing)              |
| **18:51:57**            | — application **rejected, by a human** (nothing)  |
| 19:02:27–28             | 7 × job-posted                                    |
| **19:03:36**            | — application **accepted** (nothing)              |
| **19:05:44 / 19:07:15** | — auto-reject ×3, `actor_type = system` (nothing) |

The manual reject at 18:51:57 sits between two job-posted batches that both arrived. **If it had
mailed, the message would be there.** This is the premise correction the kickoff's D2 needs: the
asymmetry it describes — manual mails, auto-reject does not — **is not in the evidence**. Neither
mailed.

**4 — the DB rows and the log prove the job ran to completion.** `campaign_applications` shows
applications 1–6 with the right statuses and `responded_at` stamps; `audit_logs` carries
`campaign_application.submitted` / `.accepted` / `.rejected` with the right actors, including the two
`actor_type = system` rejects; and `laravel.log` carries the job's own success line twice —
`jobs-board: pending applications auto-rejected on campaign close {"campaign_id":1,...,"rejected":1}`
at 19:05:44 and `{"campaign_id":2,...,"rejected":2}` at 19:07:15. The in-app rows wrote. **That is the
designed asymmetry working exactly as `feature-flags.md` describes it**: the flag gates mail, in-app
does not.

**5 — nothing is queued and nothing relevant failed.** `queues:default`, `:delayed` and `:reserved` are
all empty (`type = none`). `failed_jobs` has 158 rows and **none** of them is a `SendQueuedMailable`
carrying an application mailable — they are the stale E2E backlog of C5, newest at 17:41:40, i.e.
**before** the first application emission. So suspect 1 from the kickoff's ordered list ("the queued
mail is sitting unprocessed") is also excluded: nothing was ever queued to sit.

### What the investigation legitimately found

**F1 — the notifier is silent about its own silence, and the fan-out is not.**
`JobPostedFanOutService` logs `{"enabled":true|false,"notified":N,"remaining":N}` on every run — which
is why the job-posted half of the eyes-on was verifiable at a glance.
`CampaignApplicationNotifier::queue()` (`:221-232`) returns on the flag with **no log line at any of
its four emission sites**. An operator therefore cannot distinguish _"no mail because an operator chose
that"_ from _"no mail because something is broken"_ — and that indistinguishability is what cost this
eyes-on session. It is a real defect in the operational surface, even though it is not a defect in the
mail path.

**F2 — the flag class's docblock contradicts its own code, and the review knows it.**
`ApplicationNotificationsEnabled`'s docblock states _"The queued job additionally re-checks on entry to
`handle()`: a job enqueued before an operator flipped the flag OFF must not mail on the way out."_
`AutoRejectPendingApplicationsJob::handle()` contains **no `Feature::` call** — and that is **correct**,
deliberately: c4's C5 recorded the reinterpretation ("a mail flag must not gate database truth") and
the c4 review ratified it as _"a correct improvement on the ruling."_ The code is right; the docblock is
the stale half, and it is stale in the most misleading possible direction — it describes a defence that
is not there, in the class an operator reads to understand the flag.

**F3 — the applications flag has no preview, unlike its sibling.** `job_posted_notifications_enabled`
has `campaigns:preview-job-notifications --dry-run` and a registry row telling the operator to read its
counts before arming. `application_notifications_enabled` has neither, and no read-back of its own
state. **This lands directly in D7(a)**: a combined first-enable ritual whose first step is _arm both,
then read both back_ would have closed this in ten seconds.

### The disposition I propose

**Explain, do not "fix" — and close the three real holes.** S2 (below) ships: the docblock corrected
(F2), one structured log line per emission decision in the notifier including the flag state (F1), a
test asserting the flag-OFF path logs its reason, and the arm-verification step written into D7's
ritual (F3). **No change to any mail path, mailable, translation key or job.** Inventing a fix for a
defect that does not exist would be worse than the confusion it replaced.

---

## 3. Cross-chunk handoff contracts verified (§5.11)

Chunk 5 consumes chunks 3 and 4. Every contract re-read at HEAD.

| Contract consumed               | Verified shape                                                                                                                                                               | Where                                                                                                     |
| ------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| `assignment_ulid` on the detail | present unconditionally, `null` without an assignment, from a correlated subquery                                                                                            | `CreatorJobDetailResource.php:79-84`; `CreatorJobBoardController.php:358-366`                             |
| the card must NOT carry it      | asserted positively (c4 Q7)                                                                                                                                                  | `CreatorJobDetailTest.php:342-355`                                                                        |
| `CARD_KEYS` / `DETAIL_KEYS`     | exact ordered keysets, detail spreads card                                                                                                                                   | `CreatorJobDetailTest.php:44-65`                                                                          |
| assignment pair uniqueness      | `unique_assignment_campaign_creator (campaign_id, creator_id)`                                                                                                               | `create_campaign_assignments_table.php:129`                                                               |
| `AssignmentStatus`              | **16** cases; `isTerminal()` includes `PaymentReleased` (⚠ C6)                                                                                                               | `AssignmentStatus.php:49-66`, `:72-81`; pinned `CampaignEnumsTest.php:44-110`                             |
| TS mirror                       | 16-member union, already exported                                                                                                                                            | `packages/api-client/src/types/campaign.ts:28-44`                                                         |
| the listing endpoint            | `PATCH …/campaigns/{campaign}`, `Gate::authorize('update')` = admin+manager                                                                                                  | `Routes/api.php:40-41`; `CampaignPolicy.php:49-52`                                                        |
| the listing gate                | terminal refusal on `listed_on_jobs_board` alone, then every missing floor field named; skipped entirely when the result is unlisted                                         | `UpdateCampaignRequest.php:90-127`; floor list `ValidatesJobsBoardListing.php:51-57`                      |
| 422 envelope                    | `code: validation.failed`, `source.pointer`, `meta.field` per field                                                                                                          | `ValidationExceptionRenderer.php:79-87`                                                                   |
| once-only listing effects       | `listed_at` on the flip only; fan-out on `!$wasListed && listed_on_jobs_board`                                                                                               | `CampaignController.php:177-187`, `:198-206`; pinned `JobPostedFanOutTest.php:526-585`                    |
| applications list endpoint      | `meta.pending_total` is pending-only and does **not** follow the page filter                                                                                                 | `CampaignApplicationController::pendingCount():436`; pinned `CampaignApplicationListTest.php:143`, `:162` |
| accept / reject abilities       | both `Gate::authorize('invite')` — **includes staff** (c4 Q4)                                                                                                                | `CampaignApplicationController.php:171`; SPA mirror `CampaignDetailPage.vue:867`                          |
| board post-accept               | `CreateBoardCard` on `assignment.invited`, then `assignment.invited → Invited`                                                                                               | `CampaignsServiceProvider.php:67-75`; `BoardDefaults.php:54`                                              |
| board columns, default order    | **`To Define`** is first, `Invited` second                                                                                                                                   | `BoardDefaults.php:34-42`                                                                                 |
| card DnD                        | one group, no disable, move on `evt.added` only                                                                                                                              | `BoardColumn.vue:121-135`, `:47-51`                                                                       |
| board freshness                 | 30s poll + `load()` on mount; **no push**                                                                                                                                    | `useBoardPoll.ts:17`; `BoardView.vue:102-108`                                                             |
| creator offer accept            | `POST /creators/me/assignments/{assignment}/accept`; 422 `assignment.not_invited` off-`invited`; auto-advances to `contracted` when `requires_per_campaign_contract = false` | `CreatorAssignmentController::accept`                                                                     |
| E2E gating                      | provider gate (`local`/`testing` + token) **and** per-request `X-Test-Helper-Token`                                                                                          | `TestHelpersServiceProvider::gateOpen():76-85`; `VerifyTestHelperToken.php:37-50`                         |

**Two gaps found, both in D6's provisioning, both requiring a new helper — see S8/Q8.** (i) No helper
creates an agency-owned, floor-complete, **unlisted** campaign, so "agency lists it" cannot be driven
through the UI from any existing seed: `CreatePendingApplicationsController` creates a campaign that is
**already listed** and applications that skipped the apply endpoint, and `CreateListedJobController` is
**creator-keyed** and mints its own agency with no user to sign in as (the c4/C3 finding, unchanged).
(ii) No helper rosters an **existing, named** creator account onto an existing agency, which is what a
cross-role spec needs in order to sign in as the applicant.

**Two contracts are stronger than the kickoff assumed and save real work.** D3 needs **zero backend
code** — the gate is not "reused", it is literally the same endpoint, so "same 422s" is not a claim to
test but a tautology, and the break-revert on the shared gate reds both surfaces' specs from one
mutation. And D4's accept-motion is already asserted end-to-end once
(`campaign-applications.spec.ts:116-129`), so the board leg is an extension of a green assertion rather
than a new one.

---

## 4. Sub-step plan

Ten sub-steps in four phases. Ordering rule: **the shared derivation lands before either surface that
reads it**, and **every extraction happens before its second consumer exists** — so no sub-step ever
ships a duplicate it intends to remove later.

### Phase A — the derivation and the D2 outcome (S1–S3)

**S1 · `JobLifecycleState` + the exhaustive mapping (backend, no surface).**
One new enum `JobLifecycleState: string` (`in_progress` / `completed` / `ended`) carrying the mapping as
a static `fromAssignmentStatus(AssignmentStatus $s): self` — **one `match`, no `default` arm**, so a
17th enum case is a PHPStan-level-max failure at the mapping rather than a wrong label in a browser.
Its docblock records **why `isTerminal()` is not the Ended predicate** (C6) so the next reader does not
"simplify" it into a bug. Proposed table in **Q3**.

Green on: a catalogue test iterating `AssignmentStatus::cases()` asserting **every** case maps and that
the three families are disjoint and complete (the `CampaignEnumsTest` shape), plus a **break-revert
proving the exhaustiveness is real** — add a case-less 17th path and watch PHPStan red, not just the
test. Belt and suspenders, because the kickoff asked for "a new case breaks the build, not the UI" and a
test alone is not a build break.

**S2 · The D2 outcome (§2's F1 + F2). No mail path touched.**
Correct `ApplicationNotificationsEnabled`'s docblock to describe the seam that exists — the flag is
read once, inside the notifier, at emission time, and deliberately **not** in `handle()` — citing c4's
C5 ruling so the correction reads as a record rather than a reversal. Add one structured log line in
`CampaignApplicationNotifier` naming the type, the recipient count and **the flag state**, on the
`JobPostedFanOutService` precedent, so a silent flag-OFF is legible in the log.

Green on: a test asserting the flag-OFF emission **logs its reason** (`Log::spy()` on the existing
flag-OFF cases rather than a new fixture), the four existing flag-OFF tests still green, and — stated
as an explicit non-goal — **zero diff** on `AutoRejectPendingApplicationsJob`, `ApplicationRejectedMail`,
its Blade template and every `lang/**/campaigns.php`. That zero diff is the review's evidence that D2
was explained rather than "fixed".

**S3 · The two creator-facing resource fields (D1's server half + D5's server half).**
`callerAssignmentStatusSubquery()` beside the existing ULID sibling, added to **both** `index()` and
`show()`; `assignment_state` emitted from `cardAttributes()` so card and detail share one
implementation; `assignment_ulid` unchanged and still detail-only. `CARD_KEYS` and `DETAIL_KEYS` each
gain one entry. `packages/api-client` gains the `JobLifecycleState` union and the two attribute fields.

Green on: the two updated keysets still **exact**; a case per family (no assignment → `null`; an
`invited` pair → `in_progress`; a `payment_released` pair → `completed`; a `cancelled` pair → `ended`);
the **cross-creator negative re-asserted for the new subquery** (it must never read another creator's
assignment on the same campaign — c4's mutation 9, repeated because the correlation is written twice
now); the card's **absence** of `assignment_ulid` still asserted (C1); api-client build + Vitest.

### Phase B — the three surfaces (S4–S6)

**S4 · D1 + D5 on the creator job surfaces (SPA).**
One branch table, ordered so the contradiction cannot recur:

| #   | condition                           | renders                                                                                          |
| --- | ----------------------------------- | ------------------------------------------------------------------------------------------------ |
| 1   | `assignment_state !== null`         | the reflection state — and on the detail, the `viewOffer` link when `assignment_ulid` is present |
| 2   | `application_status === 'rejected'` | "Not selected"                                                                                   |
| 3   | `application_status !== null`       | "Applied"                                                                                        |
| 4   | else                                | the Apply affordance                                                                             |

Branch 1 preceding branch 2 **is** D1's fix: `§5.34` case A (rejected + no assignment) falls to branch 2
unchanged; case B (rejected + an assignment) is captured by branch 1 and can never reach branch 2. The
card runs the same table for its chip; the detail keeps the assignment link as the "full picture" path.
i18n: three reflection labels ×24 (plus detail-notice variants if Q4 rules that way).

Green on: **review priority 1** — both §5.34 branches asserted on **both** surfaces (four cases), plus a
**break-revert reordering the branches** and watching the "Not selected beside a live invitation" case
red. Without that mutation the ordering is a code-reading claim. Plus the c3 accepted-chip regression
pin still green, locale parity, flaky-10 spot values.

**S5 · D3 — the list-page listing toggle (SPA only, zero backend diff).**
The chip becomes an interactive control for `agency_admin` / `agency_manager` and stays a read-only chip
for everyone else (C6). It calls `campaignsApi.update(agencyId, ulid, { listed_on_jobs_board })` — a
single-key PATCH, which is the one shape no test has ever sent (S6). Failure UX is explicit: an
incomplete listing opens a small dialog naming **every** missing field through the existing
`listing.floorFields.*` keys; a terminal campaign is refused with the status reason in a snackbar (one
line, no list). Success refetches the page. Confirmation on the ON direction per **Q6**.

Green on: **review priority 5** — the incomplete case rendering all five field names from a real 422
fixture, the terminal case rendering the status reason, the **staff negative** (chip, no control), the
success path, and the both-directions round-trip; `campaigns.api.spec.ts` unchanged (no new method);
`vue-tsc`, ESLint, locale parity.

**S6 · D3's one backend test (no backend code).**
`CampaignJobsBoardListingTest` gains the **single-key PATCH** case: `{ listed_on_jobs_board: true }`
alone must hit the same floor gate against stored values, and the same terminal refusal — because the
list page's payload shape is new even though the endpoint is not. Then assert, don't rebuild: the
once-only `listed_at` stamp and the no-re-dispatch cases (`JobPostedFanOutTest.php:526-585`) stay
**green untouched**, and one **break-revert on the shared gate** reds both the Settings-tab specs and
the new list-page specs from one mutation — the kickoff's "break-revert one shared gate", discharged
literally.

### Phase C — the board column (S7)

**S7 · D4 — extraction, then the pseudo-column.** Two commits, in this order, so the extraction is
readable alone:

1. **Extract** `RejectApplicationDialog.vue` out of `ApplicationsTab.vue` plus a
   `useCampaignApplications` composable (list + accept + reject + the `ApiError` code mapping). The gate
   is that **`ApplicationsTab.spec.ts`'s 11 tests do not change** — the c4/Q2 guard, reused. If an
   assertion has to move, I stop and report.
2. **Build** `BoardApplicationsColumn.vue` as the first child of `.board-columns-root`, outside the
   column-reorder draggable and absent from `localColumns` (C4): 300px, the same header grammar with its
   **own pending count** from `meta.pending_total`, visually distinct cards carrying their status and
   their two actions, **no `<draggable>` anywhere inside it**, first page only with a "+N more" footer
   pointing at the Applications tab. Handed `canInvite`, not `canConfigure` (C6). On accept: close the
   dialog, drop the card, refetch applications **and** `boardStore.refresh()` (C4).

Green on: **review priority 3** — `git diff --stat` on `apps/api/app/Modules/Boards`,
`database/migrations` and the automation seeds coming back **empty** (the "assert zero" claim, proven
by a command rather than prose); the **no-drag negatives** (zero draggable wrappers inside the column,
the column absent from the reorder model, real columns' drag behaviour byte-unchanged); the accept
motion asserted in a component spec (card leaves, both refetches fire) and again end-to-end in S9; the
pending count matching the tab's; the staff-can-act case. Plus `BoardColumns.spec.ts` /
`BoardView.spec.ts` / `useBoardStore.spec.ts` green untouched.

### Phase D — verification and close-out (S8–S10)

**S8 · The E2E helper D6 needs.**
One new agency-keyed sibling, `POST /_test/agencies/{agency}/listable-campaign`, following
`CreateRosterCreatorsController`'s shape verbatim: create a brand plus a campaign that is
**floor-complete and NOT listed**, with `requires_per_campaign_contract = false` so the creator's accept
resolves in one step, and roster the creator named by `creator_email` (an account the spec already
signed up and verified). Two gaps, one helper — and a **sibling, not a second mode** on either existing
controller (§5.26, the c4/C3 reasoning restated).

Green on: the helper's own feature test (it creates what it says, on the agency it was given, unlisted),
and the provider/token gate unchanged.

**S9 · D6 — the full-lifecycle cross-role spec.**
One spec on the **desktop** project (the mobile project's `testMatch` is scoped and would not pick it
up). Four actor switches through `signOutViaApi` + a fresh SPA sign-in in one context — the house
mechanism, no `storageState`, no second browser context. Proposed stopping point and its honest
justification in **Q8**. `test.describe.configure({ timeout: 180_000 })` on the
`creator-wizard-happy-path` precedent; this spec is allowed to be the suite's longest. Anchored on
`data-test` / `data-testid`, never on English copy.

Green on: the new leg, then the **full** board (18 spec files, two projects, 27 tests) with the dev
stack **down**, its own E2E database, restart + health-check after.

**S10 · D7 — the arc close-out docs, and the full gate board.**
(a) The **combined first-enable ritual** in `feature-flags.md` and the runbook: dry-run/preview reads →
arm order → **read both flags back** (§2's F3) → what to watch. (b) The arc's **single-deploy runbook
entry**: snapshot → deploy the full undeployed range (AH-053 → AH-059, i.e. everything since
`f5be920`) → migrate, enumerating the arc's **four** migrations (AH-054's one, AH-056's three; AH-058
and AH-059 add none) and naming the **two with lossy `down()`** → **mandatory queue-worker restart**
(the range carries new mail copy and three new mailable classes) → smoke → **the flag ritual, when
Pedram chooses, explicitly separable from the deploy**. Also carried forward: AH-053's **pre**-deploy
`brands:audit-floor` read. (c) `RESUMPTION-TEMPLATE.md` Part 2 per §5.39: arc complete, deploy checklist
final, open threads updated. (d) The **discoverability-toggle product gap** recorded — no creator-facing
`is_discoverable` control exists, the flag never varies in production, and the creator-controlled vs
admin-only call is left open for Pedram. (e) The **AH-059** entry and
`docs/reviews/jobs-board-c5-review.md` with its mandatory Production-posture section, the §5.32
reinterpretation record for C1, the D4 keep-both-surfaces decision plus its revisit note, and D2's
finding written as a finding. Plus C5's tech-debt entry if Q10 rules it in.

Green on: the full board — backend Pest serial at 2G, `apps/main` + `apps/admin` Vitest, api-client,
three typechecks, ESLint, `pint --all` from **outside** the sandbox (§5.18), PHPStan with an explicit
memory limit, both parity specs, full Playwright.

---

## 5. Review priorities → where each is discharged

| #   | Priority                                                                         | Sub-step(s) | Notes                                                                                                                                                                                                                              |
| --- | -------------------------------------------------------------------------------- | ----------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | D1 both §5.34 branches + the contradiction dead                                  | S3, S4      | Four cases (two branches × card and detail). **Mechanism reinterpreted per C1** — the fix is branch ordering over D5's field, and the non-vacuity mutation is a branch **reorder**, not a field removal.                           |
| 2   | D2's mechanism named with evidence, fixed-or-explained, gap-test if bug          | §2, S2      | **Explained, not fixed** — five independent confirmations, including a premise correction (**Q9**). No gap-test owed: no gap exists in the mailable tests. Three real holes closed instead (docblock, log line, ritual read-back). |
| 3   | D4: zero board_cards/automation diff, no-drag enforced, accept motion end-to-end | S7, S9      | Zero proven by `git diff --stat`, not prose. No-drag by **absence** of a drop target (C4), asserted as a negative. Motion asserted twice: component spec and E2E.                                                                  |
| 4   | D5 mapping exhaustive over the enum                                              | S1          | `match` with no `default` (PHPStan build break) **plus** a `cases()` catalogue test, **plus** a break-revert proving the build actually breaks. The `isTerminal()` trap is recorded (C6).                                          |
| 5   | D3 gating parity with Settings, break-revert one shared gate                     | S5, S6      | Parity is **structural** — zero backend diff, literally the same endpoint. One mutation reds both surfaces. The new backend test covers the one genuinely new thing: the single-key PATCH shape.                                   |
| 6   | the full-lifecycle spec green in the full board                                  | S9          | Desktop project; 26 → 27 tests. Needs the S8 helper. Stopping point ratified at **Q8**.                                                                                                                                            |
| 7   | locale parity + flaky-10                                                         | S4, S5, S7  | ~15–18 new leaves ×24 ≈ 360–430, exact count at build. D3's field names cost **zero** — they already exist (C6). MT baseline in all 24 including the flaky 10, spot values in the review.                                          |
| 8   | the close-out docs complete per D7's list                                        | S10         | All five items (a)–(e), plus AH-053's pre-deploy read and the two lossy `down()`s named.                                                                                                                                           |

---

## 6. Open questions

**Q9 is the one I would most like answered first** — it is the only claim in §2 I cannot settle from
data, and it is the difference between "premise corrected" and "I am reading the wrong evidence".

**Q1 — AH id + review file name.** **AH-059** (free; newest entry is AH-058, `adhoc-changes-log.md:73`)
and `docs/reviews/jobs-board-c5-review.md`? _Assume yes unless corrected._

**Q2 — D1's third case, which the kickoff did not enumerate.** §5.34 names two branches: rejected + no
assignment → "Not selected"; rejected + a **live** assignment → the assignment state. The third case is
reachable: rejected application, then invited, then the assignment **cancelled or declined** — a
rejected application beside an **Ended** assignment. Two readings: (a) the assignment wins whenever one
**exists**, so this renders "Ended"; (b) the assignment wins only while it is **live**, so this falls
back to "Not selected". _My lean: (a)._ One rule, no liveness predicate to get wrong, and it cannot
resurrect the contradiction — whereas (b) needs its own definition of "live" and would put a fallback
branch back in front of the one case D1 exists to kill. The cost of (a) is that "Not selected" becomes
unreachable for a pair that was ever invited, which I think is honest: the agency's last act on that
pair was an invitation, not a refusal.

**Q3 — the D5 mapping, against the real 16-case enum.** The enum is **`AssignmentStatus`**, not
`CampaignAssignmentStatus` (`AssignmentStatus.php:49-66`). Proposed, exhaustive by construction:

| state           | cases                                                                                                            | count |
| --------------- | ---------------------------------------------------------------------------------------------------------------- | ----- |
| **In progress** | `Invited`, `Countered`, `Accepted`, `Contracted`, `Producing`, `DraftSubmitted`, `RevisionRequested`, `Approved` | 8     |
| **Completed**   | `Posted`, `LiveVerified`, `ManuallyVerified`, `PaymentHeld`, `PaymentReleased`                                   | 5     |
| **Ended**       | `Declined`, `Rejected`, `Cancelled`                                                                              | 3     |

Three calls worth confirming rather than assuming. **`Countered`** → In progress (a counter-offer is a
live negotiation, not an ending). **`Approved`** → In progress (the draft is approved but nothing is
posted yet; Completed would over-promise to the creator). **The payment pair** → Completed (the work is
done and the money is in motion; with only three states, "paid" has nowhere else to live). And the trap,
restated: **`isTerminal()` must not be reused** — it returns true for `PaymentReleased`, so the
nearest-looking helper maps a fully-paid engagement to "Ended".

**Q4 — the reflection's field name, and does it replace or accompany the existing notices?** I plan
`assignment_state` on the wire and a branch table where branch 1 (reflection) **replaces** the
`acceptedNotice` / `rejectedNotice` branches whenever an assignment exists, keeping the `viewOffer`
link. The alternative is rendering both (reflection chip _and_ the accepted notice), which I think reads
as redundant on a card and cluttered on the detail. Confirm the replacement, since it retires two
strings c4 shipped ×24 (they stay in the bundle for branches 2–3, so no keys are deleted).

**Q5 — does C1's card field reverse c4's Q7?** I say **no**: Q7 ruled "detail only" about
`assignment_ulid` because the card has no link to give, and that stays true — the ULID does not go on
the card and `CreatorJobDetailTest.php:342-355` stays green untouched. What the card gains is a
different, label-only field. Confirm you read it that way too, because if Q7 was really "the card
learns nothing about assignments", then D1's card half cannot be built at all and the rejected chip
stays contradictory on the list while being correct on the detail — which I would rather not ship.

**Q6 — does flipping the listing ON from the table need a confirmation step?** The §5.40 line's core
point: one click on a row now stamps `listed_at` irreversibly and mails up to 50 rostered creators, and
delisting does not un-send. _My lean: yes on the ON direction only_ — a small confirm naming the
campaign and stating that rostered creators may be notified; OFF stays immediate (it is reversible and
sends nothing). Two sub-questions folded in: (i) should the affordance be a **switch** rather than a
clickable chip, so the deliberateness is legible before the click; (ii) the incomplete-listing dialog
wants to send the operator to the Settings tab, but `CampaignDetailPage` has **no `?tab=` deep link**
(`:221`, C6) — I can link to the campaign detail (opens on Overview) with copy that says where to go,
or add query-param tab support (~4 lines, genuinely useful, but it is a new capability inside a
close-out chunk). _My lean: link without the param now, note the deep link as a candidate._

**Q7 — D4's two seams.** (i) **Mount point:** first child of `.board-columns-root` inside
`BoardColumns` so it scrolls with the board and reads as a real first column (_my lean_), or a sibling in
`BoardView` so it becomes a sticky first column the board scrolls under. (ii) **Extract or duplicate the
reject dialog:** it is inline in `ApplicationsTab.vue:330-364`, so a second consumer means either an
extraction plus a shared composable (_my lean_ — the exact c4/Q2 precedent, one implementation and two
consumers, guarded by the tab's existing 11 specs) or a duplicated dialog that will drift the first time
an error code changes.

**Q8 — D6's honest stopping point.** I propose the spec ends at **the creator accepting the offer and
the board reflecting it**, and I want to be blunt about why the last step is not what the kickoff's
wording implies. The steps and their real cost:

- agency lists (via **D3's new toggle** — the spec covers the new surface for free) → creator sees +
  applies → agency accepts through the offer dialog → board card lands in **Invited** (already asserted
  once, `campaign-applications.spec.ts:116-129`) → creator's job page shows the reflection + the bridge
  → creator accepts the offer at `creator-assignment-accept-{ulid}`, which with
  `requires_per_campaign_contract = false` auto-advances to `contracted`. **Four actor switches.**
- **⚠ There is no column motion to assert after the creator accepts.** `BoardDefaults` seeds exactly one
  invitation-side automation, `assignment.invited → Invited` (`:54`). Nothing reacts to
  `assignment.accepted` or `.contracted`. So the honest assertion is _the card stays in Invited and its
  status chip changes_ — and the review must say that, rather than implying a motion the product does
  not have.
- **Drafts and posting are a step too far, and I would rather say so now than discover it at S9.** Each
  is its own multi-step surface (a media-or-links upload, a review drawer, an approval, a posting URL, a
  verification resolution), none has E2E coverage today, and bolting all of it onto the arc's regression
  net would make one spec that fails for five unrelated reasons. It belongs in its own spec, not this
  one.

Confirm the stopping point, and confirm the S8 helper (it is the only new backend surface in the chunk,
and it exists solely because the two existing seeds cannot be composed — §3).

**Q10 — C5's shared-Redis finding: `tech-debt.md` entry, or fix it here?** The dev and E2E environments
share one Redis queue; `failed_jobs` holds 158 stale E2E mail jobs as a result and is useless as a
diagnostic. The fix is a `QUEUE_CONNECTION` override in the Playwright webServer env plus a one-time
flush. _My lean: record it, do not fix it here_ — it is a test-infrastructure change landing in an
arc close-out chunk, and it would alter the environment I am about to run the full suite in. Say the
word if you would rather it be fixed while the finding is fresh.

**No blocking question came out of the read pass that would change a locked decision.** C1 is a
mechanism reinterpretation inside D1 (and it makes D1 cheaper); C2 is D2's answer, which D2 explicitly
asked for.

---

## 7. Standards this chunk will apply

Named up front so the completion package can be checked against a list rather than a memory.

§5.1 (source-inspection: the D5 mapping's exhaustiveness is enforced by the type system **and** a
catalogue test); §5.2 (checked — **no new emission site**, so no new split is owed; S2's log-line test
uses `Log::spy()` and does not fake mail); §5.3 (checked — **no new mailable and no mail-copy change**;
the existing real-render tests stay green untouched, and their untouchedness is D2's evidence);
§5.11 (§3 above, including the two provisioning gaps); §5.12 + §5.26 (the S8 helper is a **sibling**,
one call, all identifiers returned); §5.13 (no new api-client method for D3 — the existing `update()`
carries it; `packages/api-client` gains types only); §5.18 (Pint from outside the sandbox); §5.21
(**no `tenancy.md` §4 rows** — the chunk adds no route except one `_test` helper, which is already
inside the gated group); §5.32 (**two recorded reinterpretations**: C1's card mechanism, and D2's
outcome as "explained" rather than "fixed"); §5.34 (D1's two branches ×2 surfaces, D4's no-drag
negatives, D3's staff negative, D5's disjoint-and-complete families, the cross-creator subquery
negative); §5.35 (break-reverts on: the D1 branch order, the D5 exhaustiveness — **against PHPStan, not
just the test** — the shared listing gate reding both surfaces, the board column's no-drag absence, and
S2's flag-OFF log line); §5.36 (the asymmetry stated plainly: D4's accept **motion** is covered by one
component spec plus one E2E leg, not by a backend test, because there is no backend change to test);
§5.37 (checked — **not applicable**, no new notification type); §5.38 (checked — **not applicable**, no
new event class and no new consumer; D4 binds to nothing); §5.39 (resumption template in the closing
docs commit); §5.40 (§0, no migration, the Production-posture section, the arc's held deploy and its
runbook).

Two standing operational rules bind: **the queue worker must be restarted** on the arc's deploy (the
undeployed range carries new mail copy — restated in D7's runbook entry, not new here); and **the
scheduler stays out of the design entirely** — nothing in this chunk has a cron trigger.

---

## 8. What this chunk deliberately does not build

A fix for a mail bug that does not exist (§2 — the docblock, the log line and the ritual read-back are
the honest remainder); a re-check of the flag inside `AutoRejectPendingApplicationsJob::handle()` (c4's
C5 ruled it out for a good reason and the review ratified it — the docblock gets corrected **to** the
code, not the other way round); any change to `board_cards`, the board's automations, the seeded
columns, or the board's data payload (D4 — asserted zero); a real board column in the data (the §5.32
line from c4's D1 annotation extends: this is a **rendering of applications on the board screen**);
drag into or out of the Applications column, and no reordering of it (C4 — enforced by absence);
removal of the Applications **tab** (D4 — keep-both is the decision, with a revisit note: the tab is the
full history including rejected, the column is a pending-only working surface); pagination inside the
board column (first page + "+N more" → the tab); a fourth or fifth lifecycle state, and any new column,
event or sync to store one (D5 — read-time derivation only); `listed_at` on the agency side (the c3
addendum records this as Pedram's deliberate boolean-only choice, and D3 does not reopen it); a `?tab=`
deep link on the campaign detail unless Q6 rules it in; drafts, posting, verification or payment legs in
the E2E spec (Q8 — the honest stopping point, stated rather than discovered); a creator-facing
`is_discoverable` control (D7(d) — the gap is **recorded**, the product call is Pedram's); a fix for the
shared dev/E2E Redis queue (Q10 — recorded, not fixed); and the staff-notification asymmetry c4
deferred (untouched, still pre-existing).

---

**No code will be written until this plan is cleared.**
