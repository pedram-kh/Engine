# Jobs Board arc — chunk 4 (agency applications: tab, accept, reject, terminal auto-reject) — PLAN (plan-pause)

- **Status:** Plan-pause. **No code written.** Awaiting Claude's clearance before sub-step 1.
- **Date:** 2026-07-28
- **Author:** Cursor, against Claude's chunk-4 kickoff (D1–D8), entry **AH-058**.
- **HEAD:** `895f28a9060f2a86e24a3dce63d7fd7782e053d4` (`895f28a`) —
  `docs(jobs-board): chunk 4 inventory`. Working tree clean.
- **⚠ HEAD ≠ `origin/main`.** `origin/main` is `d4b2de5765f854811d155a06028af0dcfec86026`
  (`d4b2de5`), verified live with `git ls-remote origin main`. Local `main` is **1 commit ahead**
  (`git rev-list --left-right --count origin/main...HEAD` → `0 1`): `895f28a`, this chunk's own
  inventory, held per push discipline. Expected state, not drift — stated because the kickoff was
  written against the pre-inventory baseline.
- **AH id:** **AH-058** is free — the log's newest entry is AH-057
  (`adhoc-changes-log.md:73`). No collision this time (contrast c3's C1).
- **Orientation re-read at plan time:** `docs/WORKING-PROCESS.md` (all 9 sections),
  `docs/PROJECT-WORKFLOW.md` §5 (5.1–5.40), `docs/reviews/adhoc-changes-log.md` (AH-057 → AH-051),
  `docs/reviews/jobs-board-c3-review.md` + its addendum, `docs/reviews/jobs-board-c4-inventory.md`
  (this chunk's own inventory), `docs/reviews/jobs-board-c3-plan.md` (the plan format + its Q/C
  discipline), `docs/reviews/RESUMPTION-TEMPLATE.md` Part 2, `docs/feature-flags.md`,
  `docs/security/tenancy.md` §4, `docs/tech-debt.md`.

---

## 0. The §5.40 line, re-derived

> **⚠️ PROD-DATA RISK: MEDIUM.** Same grade Claude declared, for the same three reasons, plus two
> the kickoff did not name (items 4 and 5 below).
>
> **No migration. At all.** `campaign_applications.responded_at` already ships
> (`2026_07_27_110000_create_campaign_applications_table.php:89-91`) and
> `idx_applications_agency_status` was added for this chunk (`:97-99`). D4 refuses a reject-reason
> column, so nothing in chunk 4 alters a table. **`down()` honesty is therefore trivially satisfied:
> there is nothing to reverse.** That removes the single largest §5.40 surface a chunk can have and is
> the main reason this stays MEDIUM rather than higher.
>
> **What the feature writes to rows that already exist — the arc's first UPDATE.** Chunks 1–3 only
> ever inserted rows or stamped one nullable timestamp on a campaign the operator had just edited.
> Chunk 4 **updates `campaign_applications.status`** — a state flip on a row a creator created — plus
> `responded_at`. Both are guarded by a hand-written pending-only source check (D4, and the enum's own
> docblock says the guard is the call site's job: `CampaignApplicationStatus.php:23-40`).
>
> **What it creates on the platform's most load-bearing table.** Accept inserts a
> `campaign_assignments` row at `invited` through a **plain `create()`**, not a guarded machine edge
> (`CampaignAssignmentController.php:203-225`), and that one insert fans out to seven registered
> `AssignmentTransitioned` listeners (`CampaignsServiceProvider.php:47-75`), three of which act on
> `invited`: message thread, board card, board automation.
>
> **What it changes on a live path (D3b).** `store()` is the invite endpoint the agency uses every
> day, and the bulk-invite dialog calls it **once per selected creator** in a loop
> (`InviteCreatorsDialog.vue:184`, `:217`). Chunk 4 wraps it in a transaction and adds one guarded
> hook. This is the chunk's highest-regression-probability change and it is why the §5.34
> byte-identity pin (a pair with NO pending application behaves exactly as today) is a review
> priority rather than a nicety.
>
> **The outbound-mail exposure.** Three new mailables to live users: two to creators (the ~279-creator
> base, `RESUMPTION-TEMPLATE.md:254`), and — new for this arc — **one to agency users**, fanned to
> every admin+manager of the agency (`Agency::notifiableMembers():114-127`). Containment is one
> Pennant flag, `application_notifications_enabled`, default OFF, gating the **mail** legs only (D6).
> Two honest limits on that containment, both stated here rather than discovered at review: (a) it is
> a per-emission gate, not a cap — unlike AH-056's fan-out there is no `--limit`, because the volume
> is bounded by human action (one application, one accept, one reject at a time) with exactly one
> exception, D5's auto-reject loop, whose bound is the roster (§I3 of the inventory); (b) in-app rows
> still write with the flag OFF, by design, and still honour each recipient's own `in_app` preference
> (C2).
>
> **At T+0 the reachable population is provably zero.** `campaign_applications` does not exist in
> production — chunk 3 is undeployed and the whole arc deploys as one unit
> (`RESUMPTION-TEMPLATE.md:126-133`). So at deploy there are no applications to accept, reject or
> auto-reject, and every path in this chunk is inert until a creator applies.
>
> **Blast radius of a bug, worst first:** (1) a cross-tenant accept — creating an assignment on
> another agency's campaign, or accepting another agency's application; guarded by
> `assertBelongsToAgency()` + `Gate::authorize()` before anything and pinned with negative cases;
> (2) **a torn transaction** — an application marked accepted with no assignment, or an assignment
> created while the application stays pending; this is review priority 1 and it is proven by forcing a
> failure, not by reading the code; (3) a D3b regression silently changing invite behaviour for
> ordinary invites; (4) an accept/reject notification sent for a write that then rolled back (C1 —
> ordering, not luck); (5) an auto-reject loop that re-sends on a second `cancelled` flip (D5's
> in-transaction re-filter).
>
> **Deploy stays held to end-of-arc.** Nothing here reaches production until chunk 5 exists. The new
> flag joins the arc's first-enable ritual alongside `job_posted_notifications_enabled`.

---

## 1. Read-pass findings that affect the kickoff

Seven items. **C1 and C4 change what gets built**; C2, C3, C6 change what gets claimed or how a
sub-step is shaped; C5 and C7 are ripple the kickoff's "expected ripple" list did not enumerate.

### C1 — ⚠ Emissions must sit **after** the transaction commits, not inside it

`config/queue.php` sets **`'after_commit' => false`** on all four connections (`:48`, `:57`, `:68`,
`:77`). So a `Mail::queue()` issued inside `DB::transaction()` is visible to a worker **immediately**,
before the commit — and if the transaction then rolls back, the creator has already been told they
were accepted for an invitation that does not exist. D2's atomicity requirement and D6's dual-emit
requirement therefore have an ordering constraint between them that the kickoff did not state.

**The house precedent is exact and it is in the same file D5 touches.**
`CampaignController::update()` dispatches its fan-out **after** `save()`, with the reason written
down: "Plain dispatch AFTER the save, so the worker can never read a campaign that is not yet listed —
and not `dispatchAfterResponse()`, which would run a mail loop inside the web process"
(`CampaignController.php:190-197`).

**Ruling I will implement, absent an objection:** one `DB::transaction()` containing every DB write
(application flip + `responded_at` + assignment create + audit rows + `AssignmentTransitioned`), and
the **dual-emit called after the transaction returns**, in the same controller action. Consequences,
stated plainly:

- The in-app row is written after a _successful_ commit, so it can never describe a rolled-back
  accept. The residual failure mode inverts to the strictly better one: a committed accept whose
  in-app row failed to write.
- AH-051's "both legs fire from one place" is preserved — the place moves, the pairing does not.
- **Review priority 1 grows one assertion:** the forced-failure test must also assert
  `Mail::assertNothingQueued()` and zero `notifications` rows, not just the two DB negatives. A
  rollback that still mails is the failure this ordering exists to prevent, and asserting only the
  table state would pass while it happened.
- ⚠ The pre-existing counter-pattern is recorded, not fixed: `SendAssignmentNotifications` runs as a
  synchronous listener **inside** `CampaignAssignmentStateMachine::commit()`'s transaction
  (`:601-640`, dispatch at `:627`), so today's draft-review mails already queue pre-commit. Chunk 4
  does not copy that shape and does not undertake to fix it.

### C2 — "in-app rows always write" needs one qualifier before it goes in the review

`NotificationService::notify()` returns `null` without writing when the recipient has disabled the
`in_app` channel for that type (`:48-50`). D6's "in-app rows always write" is true of the **flag** —
the flag gates the mail leg only — and must not be restated as bypassing preferences. The three new
types will expose `in_app` toggles (§I4 of the inventory), so a creator who switches
`campaign_application.rejected` off gets no in-app row, flag or no flag. I will phrase the review
claim as "the flag gates mail; in-app still honours the recipient's own preference".

### C3 — the c3 E2E helper cannot be extended; the c4 leg needs a sibling

`CreateListedJobController` is **creator-keyed** (it takes the signed-in creator's email) and
provisions a **fresh agency** via `AgencyFactory` (`:100-102`) with no agency user attached. The c4 leg
is agency-side: it needs an agency the spec can _sign into_, which is what
`seedAgencyAdmin` + `POST /_test/agencies/{agency}/roster-creators`
(`CreateRosterCreatorsController`, `TestHelpers/Routes/api.php:101-102`) already provide for the
roster and bulk-invite specs.

**Ruling (the kickoff left this to me):** a **sibling**, `POST /_test/agencies/{agency}/pending-applications`,
agency-keyed, following `CreateRosterCreatorsController`'s shape verbatim: roster N creators on the
given agency, create a listed campaign under a brand of that agency, and insert N `pending`
`campaign_applications`. Extending the creator-keyed helper would mean giving it a second, mutually
exclusive mode — the §5.26 smell.

### C4 — ⚠ D3b's seam is wider than the create path, and `store()` has no transaction today

Two facts change the shape of the D3b sub-step:

1. **`store()`'s declined branch never reaches the create.** An existing `declined` assignment is
   re-offered through the machine and the method **returns** (`:153-179`). So a hook that lives only
   inside the extracted create-service would silently miss the case "creator applied, and the agency
   re-invites them on a previously declined pair" — which is the same pair-state D3 explicitly handles
   in the accept direction. The hook must therefore be a **named private step called from both
   branches** (`settlePendingApplication(...)`), not a side effect of the create.
2. **`store()` is not transactional.** The create, the audit row and the event dispatch run
   unwrapped today; the machine's `commit()` supplies a transaction only on the declined path
   (`CampaignAssignmentStateMachine.php:601-640`). D3b makes the invite path write to **two** tables,
   so `store()` must gain a `DB::transaction()`. That is a real behavioural delta on the platform's
   busiest agency write path — a mid-flight failure that previously left an assignment row now leaves
   none — and it is a strict improvement, but it must be named, not slipped in.

Both feed the §5.34 pin the kickoff asked for: **a pair with no pending application is byte-identical
to today** (same row, same audit row, same event, same status code), asserted positively, with a
break-revert on the hook proving the assertion is not vacuous.

### C5 — the auto-reject job cannot rely on ambient tenancy (three concrete consequences)

`AuditLogger::log()` resolves `agency_id` from the ambient tenancy context when the caller does not
pass one (`:87-92`), and every read of `Campaign` / `CampaignApplication` passes through
`BelongsToAgencyScope`. A queued job has no tenant. `SendJobPostedNotificationsJob`'s docblock is the
precedent and states the trap in full ("a serialized model would be re-resolved through the
`BelongsToAgencyScope` global scope inside a worker that has no tenant context, and the campaign would
come back null", `:43-48`). So D5's job will: carry the **campaign's integer key**, not the model;
drop the scope explicitly on re-read; pass `agencyId` explicitly to every `Audit::log()` call (taken
from the application row, which carries a denormalized `agency_id` for exactly this,
`create_campaign_applications_table.php:24-28`); and **re-check the flag inside `handle()`** (the
`VerifyPostedContentJob` defence-in-depth precedent) so a job enqueued before a flag-OFF flip never
mails.

### C6 — D7's new field must be unconditional, and it must derive from the assignment, not the status

`CreatorJobDetailTest` pins the detail payload with an **exact ordered keyset** —
`expect(array_keys($attributes))->toBe(DETAIL_KEYS)` (`:154-160`), the D3 accretion guard. A key added
only when the caller's application is accepted would make the asserted keyset data-dependent. So
`assignment_ulid` ships **always present, `null` unless there is an assignment for the pair**, and
`DETAIL_KEYS` gains one entry (that red is the tripwire working).

Second half: it must be resolved by a correlated subquery over `campaign_assignments`, **not** implied
by `application_status === 'accepted'`. An accepted application whose assignment was later cancelled
is reachable, and the page must degrade to the plain applied/accepted notice rather than link into a 404. The `acceptedNotice` branch therefore renders with or without the link.

### C7 — ripple the kickoff's list did not enumerate

- **Hard-coded preference counts.** `NotificationPreferencesPage.spec.ts` asserts "a CREATOR sees 9
  types / 10 toggles" (`:93-98`) and "an AGENCY user sees 4 types / 5 toggles" (`:124`). Two new
  creator types and one new agency type move both numbers (creator 11/12, agency 5/6, if all three
  carry prefs rows — see Q5).
- **The parity spec's hand-restated list.** `i18n-notifications-parity.spec.ts` keeps a
  hand-maintained `LIVE_TYPES` const (`:58-76`) whose own docblock records that it went stale during
  AH-051 and let users get the fallback (`:48-56`). It must be edited by hand 15 → 18; the derived
  guard (`templates.spec.ts`) catches a missing registration, this one catches a missing translation.
  Both are required.
- **`CampaignApplicationSchemaTest`** (`tests/Feature/Modules/Campaigns/`) is the c3 tripwire on the
  table and the unique pair; chunk 4 is its first writer and must leave it green untouched.

---

## 2. Cross-chunk handoff contracts verified (§5.11)

Chunk 4 consumes chunk 3 (AH-056). Every contract re-read at HEAD:

| Contract consumed                 | Verified shape                                                                                                                         | Where                                                                      |
| --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------- |
| `responded_at`                    | `timestamp` nullable, unwritten, on `$fillable` + cast `datetime`                                                                      | migration `:89-91`; `CampaignApplication.php:77-83`, `:113-117`            |
| `idx_applications_agency_status`  | `(agency_id, status)`, added for this chunk                                                                                            | migration `:98`, docblock `:46-50`                                         |
| `unique(campaign_id, creator_id)` | `unique_application_campaign_creator`; **no SoftDeletes** — the retained row IS the no-re-apply rule                                   | migration `:97`, docblock `:36-40`                                         |
| `CampaignApplicationStatus`       | `pending / accepted / rejected`; `isTerminal()` `:53-59`; `reapplyBlockCode()` `:80-85`; **no state machine — guard is the call site** | enum `:23-45`                                                              |
| Re-apply refusal                  | `job.already_applied` (pending/accepted) vs `job.application_rejected` (terminal), both 409                                            | `CreatorJobBoardController.php:190-207`                                    |
| Applicant count                   | `withCount('applications')`, **unfiltered by status** — interest semantics, NOT a pending count                                        | `Campaign.php:169-184`; `CreatorJobCardResource.php:91`                    |
| Visibility predicate              | six legs; leg 3 = `permitsMessaging()` roster ⟹ **every applicant is rostered by definition**                                          | `JobsBoardVisibility.php:88-131`; `AgencyCreatorRelation.php:150-158`      |
| Contact gate                      | the **same** `permitsMessaging()` primitive backs `canSeeContactDetails()` ⟹ the tab creates **no new exposure**                       | `CreatorPolicy.php:121-146`                                                |
| Board post-accept                 | `CreateBoardCard` on `invited` (`:39-52`) then the `assignment.invited → Invited` automation (`BoardDefaults.php:54`), order locked    | `CampaignsServiceProvider.php:66-74`                                       |
| Flag conventions                  | `NAME` const + `default(): Closure`; one registry; checked **inside** the service                                                      | `JobPostedNotificationsEnabled.php`; `CreatorsServiceProvider.php:252-261` |
| Flag operator control             | `AdminFeatureFlagController::FLAGS` is both the SPA allowlist **and** `toggle()`'s validator — the AH-056 tinker-only lesson           | `:54-83`, `:90-104`; test `AdminFeatureFlagTest.php:70-83`                 |

No gap found. Two contracts are stronger than the kickoff assumed and save work: the board needs
**nothing** (an assertion, per D1), and the contact gate needs **nothing** (the applicant is rostered,
so the tab shows data the agency can already read on the roster page — I will state this rather than
add a gate).

---

## 3. Sub-step plan

Eleven sub-steps in four phases. Ordering rule, same as c3: **no user-reachable surface exists until
its server-side guard is already pinned**, and **no emission site exists until its vocabulary and its
flag do** — so the notification machinery lands before the endpoints that emit, not after.

### Phase A — the shared spine (S1–S2)

**S1 · Extract the invitation service (pure refactor, zero behaviour change).**
One new service in the Campaigns module owning exactly what `store()` hand-writes today: the
`campaign_assignments` create, the hand-written `assignment.invited` audit row, and the
hand-dispatched `AssignmentTransitioned(from=to=invited)` — the comment at
`CampaignAssignmentController.php:227-231` explaining _why_ the endpoint owns them moves with the code
(it is the reason the board and thread listeners fire at all). `store()` becomes its first caller and
its behaviour is unchanged: same fields, same `invited_at` / `invited_by_user_id`, same 201, same
resource.

Green on: the **existing** suites unchanged and green with no edits —
`CampaignAssignmentInviteTest`, `CampaignAssignmentStateMachineTest`, `CreateBoardCardTest`,
`BoardAutomationServiceTest`, plus Pint / PHPStan. A refactor that needs a test rewritten is not a
refactor; if any assertion moves, I stop and report before continuing.

**S2 · Vocabulary, flag, notifier, mailables — the whole emission spine, with one live emitter.**
Two new `AuditAction` cases (`campaign_application.accepted`, `campaign_application.rejected`;
`campaign_application.submitted` already ships at `AuditAction.php:312`), three `NotificationType`
cases on the same strings (the one-vocabulary tie is proved at runtime by `auditAction()`,
`NotificationType.php:115-122`), the three `LIVE_TYPES` rows (group per **Q5**), the three SPA
notification templates + their `preferences.typeLabels` siblings ×24, the three mailables with their
backend `lang/{locale}/campaigns.php` keys ×24, the `application_notifications_enabled` Pennant class
(`NAME` + `default(): Closure`, default **OFF**) registered in `CreatorsServiceProvider` **and** in
`AdminFeatureFlagController::FLAGS`, and one notifier service holding the dual-emit so all three call
sites share it — with the flag checked **inside** it, on the mail leg only (the
`JobPostedNotificationsEnabled` "checked INSIDE the service" rule, so the HTTP path and the queued job
agree).

The one emission wired in this sub-step is **`submitted`**, into the existing creator apply path
(`CreatorJobBoardController::apply()`), fanned to `Agency::notifiableMembers()` — chunk 3's
deliberately deferred D5. Accept/reject call the same notifier when S4/S5 land.

Green on: both enum catalogue tests (`NotificationTypeEnumTest`, `AuditActionEnumTest`),
`templates.spec.ts` (registration), `i18n-notifications-parity.spec.ts` hand-edited 15 → 18
(C7), `i18n-locale-parity.spec.ts`, the updated `NotificationPreferencesPage.spec.ts` counts (C7),
§5.3 real-render mailable tests per locale with the queued-locale assertion (the `JobPostedMailTest`
shape, `:16-45`), §5.2 `Mail::fake` / `Event::fake` split for the submitted emission, the HTTP
arm/disarm flag test (`AdminFeatureFlagTest.php:70-83` shape), and a **flag-OFF break-revert** proving
mail is silent while the in-app row still writes.

### Phase B — the three write paths (S3–S6)

**S3 · The Applications list endpoint.**
`GET /api/v1/agencies/{agency}/campaigns/{campaign}/applications` — `assertBelongsToAgency()` then
`Gate::authorize('view', $campaign)` (any member reads; the execute tier is for the actions), modelled
on `CampaignDraftController::index()` line for line: `per_page` clamp, paginated envelope with
`meta.{total,page,per_page,last_page}`, an optional `status` filter with the `whereRaw('1 = 0')`
unknown-value convention (`:66-80`). Ordering **pending first, then newest** (D1). A narrow
`CampaignApplicationListItemResource`: creator identity at roster level (name, avatar signed URL,
creator ULID for the profile link), the creator's note, applied-at, status, `responded_at`. Plus the
tab's own **pending-only count** in `meta` — never `applications_count` (the I6.2 conflation warning).

Green on: a §5.34 negative set — another agency's campaign 404s, a `view`-less caller 403s, an
application from another campaign is absent from the payload — an **exact-keyset** assertion on the
list item (the D3 accretion guard, reused), the pending-only count asserted against a campaign with one
of each status (the conflation pin), and the roster-level-only claim asserted as a keyset rather than
prose.

**S4 · Accept — the cross-table transaction (D2 + D3).**
`POST …/applications/{application}/accept`, `Gate::authorize('invite')` (execute tier), body = the
invite offer subset validated by a request class that **reuses `InviteAssignmentRequest`'s rules**
(fee required `min:1`, currency `size:3` cross-validated against the campaign, the free-text offer
fields, the attachment quad with the same campaign-prefix isolation backstop, deliverables, due dates).
Gate order per the inventory's constraint table: pending-only application guard → hard-blacklist
re-check (422, the blacklist may postdate the application) → availability conflict (409 +
`acknowledged` re-submit) → the D3 pair matrix → the S1 service. **`is_discoverable` is dropped**, with
the AH-051 ruling quoted in the code comment (`Admin/AdminCreatorConnectionController.php:53-55`).

The D3 matrix, one branch each: **no assignment** → create; **declined** → `reofferAfterDecline()` +
application accepted, one transaction; **any other status** → 422 `application.already_engaged` naming
the engagement. One `DB::transaction()` wraps the application flip + `responded_at` + the assignment
write + audit + event; the dual-emit runs **after** it returns (C1).

Green on: **review priority 1** — a forced failure after the application flip asserting the assignment
does not exist, the application is still `pending`, **and** nothing was queued or notified (C1); the
full D3 matrix, one case per pre-existing assignment status; break-reverts on the dropped-discoverable
leg (a hidden-from-discovery applicant accepts successfully) and the kept-blacklist leg (a
newly-hard-blacklisted applicant 422s); a §5.6 idempotency case (double-accept → 422, no second
assignment, `responded_at` unchanged); and the **board assertion** D1 asked for — after accept, a
`board_cards` row exists in the `Invited` column, proven, not rebuilt.

**S5 · Reject (D4).**
`POST …/applications/{application}/reject`, same ability, **no body**. Pending-only guard using
`isTerminal()`; a re-reject is 422 with `responded_at` untouched (§5.6). One transaction (flip +
`responded_at` + audit row carrying the actor), dual-emit after commit with `cause: 'agency_rejected'`
in the notification `data` (D5 reuses the same type with the other cause). No agency-reason field is
accepted, stored or rendered — the audit row plus its actor is the record.

Green on: the pending-only guard per source status, the §5.6 re-reject case asserting no second
timestamp write, the no-re-apply composition (the creator's apply endpoint now 409s with
`job.application_rejected` — asserted end-to-end across the two chunks, since that is the seam the
retained row exists for), and the §5.2 emission split.

**S6 · D3b — invite-path convergence (the live-path touch).**
`store()` gains a `DB::transaction()` and one named step, `settlePendingApplication(...)`, called from
**both** the create branch and the declined re-offer branch (C4): a pending application for the same
pair is marked `accepted` + `responded_at` in the same transaction, and the accepted emission fires
after commit.

Green on: the **§5.34 byte-identity pin** — for a pair with no application, the created row, its audit
row, its event and the 201 are identical to today (asserted field-by-field, not by "it still works"),
with a **break-revert** on the hook proving that assertion is not vacuous; the with-application case
on both branches (create and declined re-offer); and the bulk path — N invites through the loop, one of
which carries a pending application — asserting the other N−1 are untouched.

### Phase C — terminal posture and the surfaces (S7–S9)

**S7 · Terminal auto-reject (D5).**
Three lines in `CampaignController::update()` in the flip-detector's own shape: capture terminality
pre-fill next to `$wasListed` (`:169`), and after the save (`:181`) dispatch a queued job when the
campaign has just become `completed`/`cancelled` — the same post-save placement and the same reasoning
as `SendJobPostedNotificationsJob::dispatch()` (`:190-197`). The job carries the campaign's integer
key, drops `BelongsToAgencyScope` on re-read, re-checks the flag in `handle()`, iterates
`status = pending` with `cursor()`, and per row: one small transaction (flip + `responded_at` + audit
with an explicit `agencyId`, actor `system`) then the emission with `cause: 'campaign_closed'` (C5).
The **`pending` re-filter lives inside the loop**, so `active → cancelled → active → cancelled`
re-runs send nothing twice.

Green on: **review priority 4** — a second terminal flip queues zero mail and writes zero rows; a
non-terminal update (draft → active) dispatches nothing; a mid-loop failure leaves already-rejected
rows rejected and re-runs safely; flag-OFF silences mail while in-app rows still write; and the
scheduler stays entirely out of the design (`RESUMPTION-TEMPLATE.md:197-203`).

**S8 · The Applications tab (D1) + api-client + `campaigns.api`.**
`api-client` types in `campaign.ts` beside c3's creator-side block: the list item, the list response,
the accept payload (the invite offer subset — typed as such so it cannot drift from
`InviteAssignmentPayload`, `campaign.ts:285`), the reject payload (empty), and the two new error codes. Three methods on
`campaigns.api.ts` beside `listDrafts` / `invite`. `CampaignDetailPage.vue` gains an **eighth** tab
(`:425-443`) rendering lazily on `tab === 'applications'` (`:790-792` pattern) with a pending-count
badge fed by S3's scoped count. The panel follows `DraftsTab.vue`: rows, `CEmptyState`, pagination,
per-row actions gated on the execute tier. Accept opens the offer dialog (**Q2**); reject opens a
`v-if`-mounted confirmation dialog per `ReviewDraftDrawer.vue:383-390`. Tab label + panel copy in
`app.json` under `app.campaigns.*` ×24.

Green on: component specs for the tab (pending-first order, badge count, both dialogs, the 409/422
error branches surfaced as messages not silent no-ops), `campaigns.api.spec.ts` additions, api-client
build + its Vitest, `vue-tsc`, ESLint, locale parity, full `apps/main` Vitest.

**S9 · The D7 bridge.**
Backend: `CreatorJobDetailResource` gains `assignment_ulid`, always present, resolved by a correlated
subquery over `campaign_assignments` for the caller's pair (C6); `DETAIL_KEYS` in
`CreatorJobDetailTest` gains one entry. SPA: `CreatorJobDetailPage.vue` gains the missing third
branch — `status === 'accepted'` renders an `acceptedNotice` linking to
`/creator/assignments/{ulid}` when the ULID is present and degrading to a plain notice when it is not
(C6). The list chip already renders "Accepted" in all 24 locales as a c3 side effect — **asserted, not
rebuilt** (`creator.ui.jobs.status.accepted` exists; `CreatorJobsPage.vue:114-123`).

Green on: the detail keyset assertion (updated, still exact), a backend case proving
`assignment_ulid` is null for a pending application and populated for an accepted one, the page spec's
three-state branch table, the existing chip assertion added as a regression pin, `acceptedNotice`
×24 with flaky-10 spot-values, locale parity.

### Phase D — verification and docs (S10–S11)

**S10 · Playwright — one agency-side leg.**
The sibling helper from C3, then one spec on the **desktop project** (the mobile project's `testMatch`
is scoped to `MOBILE_ONLY_SPECS` and does not pick up new specs —
`apps/main/playwright.config.ts:86-91`, `:100-115`): `seedAgencyAdmin` → sign in with the minted TOTP
(the `bulk-invite-creators.spec.ts` preamble) → seed two pending applications → open the campaign's
Applications tab → reject one through the confirm dialog → accept the other through the offer form →
assert both terminal states and the board card landing in the `Invited` column. Anchored on
`data-test` attributes, never on English copy.

Green on: the new leg, then the **full** Playwright board (17 spec files, two projects) with the dev
stack down and its own E2E DB, output to `playwright-report/`.

**S11 · Docs + the full gate board.**
`docs/reviews/jobs-board-c4-review.md` with its mandatory Production-posture section (and the D1
annotation the kickoff asked for: the c3 migration docblock says "board column", the tab is the
recorded §5.32 reinterpretation, and why nothing shipped is wasted); the **AH-058** log entry;
`feature-flags.md` row for `application_notifications_enabled` with its first-enable ritual folded into
the arc's; `RESUMPTION-TEMPLATE.md` Part 2 per §5.39 (the new flag, the queue-worker restart for new
mail copy, the arc's held deploy); a `tech-debt.md` entry only if S2 inherits the unwired email channel
in a new place (it does — the three mailables are not preference-gated either, and the honest move is to
extend AH-056's existing entry rather than open a second one). **No `tenancy.md` §4 rows** — the new
routes are ordinary tenanted agency routes and adding them would dilute the allowlist (the inventory's
ruling, restated).

Green on: the full board — backend Pest serial at 2G, `apps/main` + `apps/admin` Vitest, api-client,
`vue-tsc`, ESLint, `pint --all` (outside the sandbox per §5.18), PHPStan with an explicit memory
limit, locale parity, full Playwright.

---

## 4. Review priorities → where each is discharged

| Priority                                                                                                       | Sub-step(s)                                                    | Notes                                                                                                                                                                                     |
| -------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1 · transaction break-revert (force a failure after the flip; assert no assignment, application still pending) | S4                                                             | **Grown by C1:** also asserts `Mail::assertNothingQueued()` and zero `notifications` rows. A rollback that still mails is the failure the ordering exists to prevent.                     |
| 2 · the D3 matrix per state + D3b §5.34 byte-identity                                                          | S4, S6                                                         | Four matrix branches (none / declined / engaged / already-terminal application); byte-identity asserted field-by-field with a break-revert on the hook; the bulk-loop case included (C4). |
| 3 · dropped-discoverable + kept-blacklist break-reverts                                                        | S4                                                             | Two opposite directions: hidden-from-discovery applicant **succeeds**; newly hard-blacklisted applicant **422s**.                                                                         |
| 4 · auto-reject idempotency + flag-OFF mail silence with in-app still written                                  | S7 (+ S2 for the flag anchor)                                  | Re-cancel sends nothing twice (in-loop `pending` re-filter); flag-OFF is the break-revert anchor, AH-048/AH-056 shape.                                                                    |
| 5 · §5.2 splits per emission ×3                                                                                | S2 (submitted), S4 (accepted), S5 + S7 (rejected, both causes) | Four emission sites, three types — the auto-reject cause is a `data` variant, not a fourth type.                                                                                          |
| 6 · the D7 bridge rendered + accepted-state keyset ×24                                                         | S9                                                             | Plus the null-assignment degradation case (C6) and the c3 chip asserted as a regression pin.                                                                                              |
| 7 · tripwires (`LIVE_TYPES`, both parity specs)                                                                | S2                                                             | `templates.spec.ts` (registration, derived) + `i18n-notifications-parity.spec.ts` (translation, hand-edited 15→18) + both enum catalogues + the prefs-count specs (C7).                   |
| 8 · full Playwright including the c4 leg                                                                       | S10                                                            | Desktop project only — the mobile project's `testMatch` is scoped (C3/S10). Dev stack down, isolated E2E DB, restart + health check after.                                                |

---

## 5. Open questions

**Q2 and Q3 are the two the kickoff named. Q5 is the one I would most like a ruling on** — it is a
decision c3 explicitly deferred to the first chunk that met its condition, and this is that chunk.

**Q1 — AH id + review file name.** **AH-058** (free, `adhoc-changes-log.md:73`) and
`docs/reviews/jobs-board-c4-review.md`? _Assume yes unless corrected._

**Q2 — the accept dialog: reuse or sibling?** The kickoff asked for the argument, so:
`InviteCreatorsDialog.vue` has the right **fields** and the wrong **shape** — it is a roster-sourced
multi-select (docblock `:4`, `selected: Set<string>` `:56`, one fee applied to every selected creator
`:15`, a full roster fetch on open `:104`, N invite calls in a loop `:184`/`:217`). Accept has exactly
one pre-identified creator, so every selection affordance is dead weight and the roster fetch is a
wasted round-trip. `ReinviteDialog.vue` is the right shape — one subject, one purpose, major-unit fee
input → minor on the wire (`:80-81`), campaign currency as a read-only suffix (`:42`), per-field 422
binding via `extractFieldErrors` (`:38`) — and the wrong payload (fee only).
**My recommendation: extract the offer-fields form out of `InviteCreatorsDialog` into a shared child
component, and build `AcceptApplicationDialog` on the `ReinviteDialog` shape around it.** That is the
only option where the two dialogs cannot drift on fee validation, currency handling or the
campaign-keyed attachment presigned pair, and it leaves `InviteCreatorsDialog`'s bulk behaviour
untouched. Cost: one refactor of a live dialog, covered by its existing spec. The cheaper option
(duplicate the fields) is the one that will diverge.

**Q3 — D3b's exact seam (C4).** I plan: the hook is `settlePendingApplication(...)`, called from
**both** `store()` branches (create **and** the declined re-offer), and `store()` gains a
`DB::transaction()` it does not have today. Confirm — specifically the declined branch, because the
kickoff's wording ("creating an assignment for a pair with a pending application") reads as
create-only, and create-only would leave an application pending forever on exactly the pair AH-035
exists to serve.

**Q4 — the reject ability.** Accept is locked to `Gate::authorize('invite')` (D2). The house
convention is a **named ability per action** mirroring `invite` — `review`, `message`,
`attachContract` are all admin+manager+staff clones (`CampaignPolicy.php:60-110`). Reject could take
`invite` (no new ability, same tier) or a new `reviewApplications` (house-consistent naming, fifth
clone of the same three roles). _My lean: `invite` for both accept and reject, `view` for the list_ —
a fifth identical clone buys a better name and nothing else, and the tab's two actions being one
ability is honest. Say if you would rather it read `reviewApplications`.

**Q5 — the preferences group: does the deferred split happen now?** c3 put `campaign.job_posted` under
`assignment` and wrote the condition for revisiting into the enum: "A new group has to earn its keep,
and one type does not… the group splits when a SECOND jobs-board type exists to split with"
(`NotificationType.php:100-107`). Chunk 4 adds **three**, so the condition is met.

- _Split_ → a new `jobs_board` member of `NotificationPreferenceGroup` (`templates.ts:57`), a
  `PREFERENCE_GROUP_ORDER` entry (`:245`), one group heading key ×24, and the prefs page spec's group
  counts change from 3 to 4 groups (creator side).
- _Keep stacking under `assignment`_ → zero new machinery, and an "Assignments" heading over four
  jobs-board toggles, one of which is agency-facing.
  _My lean: split_, because c3 wrote the trigger down and honouring a recorded condition is cheaper than
  re-arguing it later. But it is a UX call with a real cost, and I will not guess.

**Q6 — one point about the `submitted` mail I want on the record before building it.** D6 locks all
three as dual-emit. `submitted` is the only one whose volume is driven by _creators_ rather than by an
agency action: N applications to one campaign = N mails to **every** admin+manager. At the arc's scale
that is fine; at a popular listing it is an inbox pattern that invites a mute rather than a read. The
flag contains the risk and I will build it as specified — flagging it so the review can state the
trade rather than discover it, and so a per-campaign digest can be a named future trigger rather than
a surprise.

**Q7 — `assignment_ulid` on the card too, or detail only?** D7 says the detail resource. The card's
keyset is separately pinned (`CreatorJobBoardTest`), and the list chip already renders "Accepted"
without needing a link. _My lean: detail only_ — one surface, one link, no second keyset change.

**Q8 — the auto-reject `cause` key.** One type, cause-parameterized copy (D5). I plan `data.cause` with
`'agency_rejected' | 'campaign_closed'`, chosen in the mailable to pick a body variant, with the
subject shared. Confirm the shape (or the key name) since it lands in the `data` bag that the SPA
template interpolates and is therefore part of the notification contract.

**No blocking question came out of the read pass that would change a locked decision.** C1 and C4 are
mechanism findings inside D2/D3b, not challenges to them.

---

## 6. Standards this chunk will apply

Named up front so the completion package can be checked against a list rather than a memory:
§5.2 (Event/Mail fake splits — four emission sites); §5.3 (real-render mailable tests per locale +
the queued-locale assertion, three mailables); §5.6 (idempotency: double-accept, re-reject, re-cancel,
and the concurrent-accept race against the unique pair); §5.11 (§2 above); §5.12 + §5.26 (the sibling
E2E helper, C3); §5.13 (api-client + `campaigns.api` method placement); §5.18 (Pint from outside the
sandbox); §5.21 (no `tenancy.md` §4 row, with the category reasoning recorded in the review rather
than a row); §5.32 (D1's tab-vs-column reinterpretation, annotated against the c3 docblock, plus C1's
after-commit ordering recorded as a mechanism choice); §5.34 (the D3b byte-identity pin, the list
endpoint's negative set, the exact keysets); §5.35 (break-reverts on: the transaction rollback, the
D3b hook, the dropped-discoverable leg, the kept-blacklist leg, the flag-OFF mail silence);
§5.37 (checked — **not applicable**: each of the three verbs is single-direction, and the auto-reject
variant reuses one type with a `data` cause rather than splitting recipients); §5.38 (checked — no new
event class; D5 uses the recorded flip-detector shape instead, and the board consumers keep binding to
`AssignmentEventContract`); §5.39 (resumption template in the closing docs commit); §5.40 (the line in
§0, no migration, the Production-posture section, the arc's held deploy).

Two standing operational rules bind this chunk specifically: **restart the queue worker on deploy** —
it caches translations in memory and this chunk ships new mail copy
(`RESUMPTION-TEMPLATE.md:201-203`); and **the scheduler stays out of the design entirely** — D5's
trigger is the status flip, never a cron (`:197-203`).

---

## 7. What this chunk deliberately does not build

A real board column and any change to `board_cards` (D1 — the board is asserted, not extended); a
reject-reason column, field or rendering (D4 — the three-way argument was heard; none is the cheapest
honest option, and it is the only choice that keeps the chunk migration-free); a `withdraw`
application path (still no withdraw in the arc); an application-withdrawn or accept-declined
vocabulary (the applicant declining the offer is the existing `assignment.declined`, unchanged);
a `CampaignStatusChanged` domain event (D5 — reversing the recorded AH-056 ruling without new
evidence); an email-channel preference toggle for the three new types (the AH-056 tech-debt entry is
extended, not resolved — the channel has never been preference-gated platform-wide); a per-campaign
application digest (Q6's named future trigger); a cap or `--limit` on the auto-reject loop (bounded by
roster; the in-loop re-filter is the containment); the cross-role full-lifecycle Playwright spec
(chunk 5); and the creator-side dashboard applications teaser (not in the arc plan).

---

**No code will be written until this plan is cleared.**
