# Jobs Board arc — chunk 3 (creator job board + apply) — PLAN (plan-pause)

- **Status:** Plan-pause. **No code written.** Awaiting Claude's clearance before sub-step 1.
- **Date:** 2026-07-27
- **Author:** Cursor, against Claude's chunk-3 kickoff (D1–D10).
- **HEAD:** `e3a15943a3fdd537e750cafd196f5735b2aa6260` (`e3a1594`) — `docs(brands): AH-055
change-log entry + resumption template`. Working tree clean.
- **⚠ HEAD ≠ `origin/main`.** `origin/main` is `aa8d4106b87f508c4dd5c4f87ff53fae000758f1`
  (`aa8d410`), verified live with `git ls-remote origin main`. Local `main` is **3 commits ahead**
  (`git rev-list --left-right --count origin/main...HEAD` → `0 3`): `ffde41d` (the chunk-3
  inventory), `478bcda` + `e3a1594` (AH-055). All three are held per push discipline — this is the
  expected state, not drift, but the kickoff's baseline assumption is worth stating out loud.
- **Orientation re-read at plan time:** `docs/WORKING-PROCESS.md` (all 9 sections),
  `docs/PROJECT-WORKFLOW.md` §5 (5.1–5.40) + §6, `docs/reviews/adhoc-changes-log.md` (AH-055 →
  AH-053 range), `docs/reviews/RESUMPTION-TEMPLATE.md` Part 2,
  `docs/reviews/jobs-board-c3-inventory.md` (this chunk's own inventory),
  `docs/feature-flags.md`, `docs/security/tenancy.md` §4.

---

## 0. The §5.40 line, re-derived

> **⚠️ PROD-DATA RISK: LOW-MEDIUM.**
>
> **What touches rows that already exist:** exactly one thing — `ALTER TABLE campaigns ADD COLUMN
listed_at timestamp NULL`. Nullable, no default, no backfill, metadata-only on Postgres 11+. No
> existing campaign row is read or rewritten by any migration in this chunk. The other two
> migrations are `CREATE TABLE` on tables that do not exist (`campaign_applications`,
> `campaign_job_notifications`) and cannot touch anything.
>
> **What the feature writes to live data, once deployed:** (a) `campaigns.listed_at` — a single
> timestamp, written **only** on a `false → true` transition of `listed_on_jobs_board`, i.e. only
> when an agency user deliberately lists one campaign they own; never in bulk, never on any other
> save; (b) new rows only — `campaign_applications` (creator-initiated), `campaign_job_notifications`
> (the once-only stamp), `notifications` (in-app), `audit_logs`.
>
> **The outbound-mail exposure, stated plainly:** this is the arc's first mail to live creators.
> The maximum reachable population is the rostered-and-approved subset of **~279 production
> creators** (`RESUMPTION-TEMPLATE.md:207-208`). Containment is three-layered and all three ship in
> this chunk: a Pennant flag defaulting **OFF** (D6), a per-run cap of **50** (D6), and a
> per-(campaign, creator) once-only stamp that makes a re-list a provable no-op (D7). At T+0 the
> reachable population is provably **zero** regardless, because `listed_on_jobs_board` is
> `default(false)` with nothing backfilled and create cannot list
> (`2026_07_27_100000_add_jobs_board_listing_to_campaigns.php:46`; `CampaignController.php:103-105`).
>
> **`down()` honesty (§5.40):** the two `CREATE TABLE` migrations have true structural inverses but
> **lossy content inverses** — dropping `campaign_applications` discards every creator's application
> and note, dropping `campaign_job_notifications` discards the once-only record (so a re-run after a
> rollback would re-notify). Both `down()` blocks will carry an explicit cannot-restore comment in
> the AH-054 house style (`2026_07_27_100000_...php:55-61`). Dropping `campaigns.listed_at` loses
> only display metadata.
>
> **Blast radius of a bug, worst first:** (1) an over-broad visibility predicate leaks another
> agency's campaign copy, fee and brand identity to a creator — a cross-tenant read, the most
> serious failure mode in the chunk, which is why the four-leg predicate gets a §5.34 disjoint set
> on **both** endpoints plus break-revert on two legs; (2) a too-wide brand emission leaks
> agency-internal brand fields (D3's exact-keyset assertion is the guard); (3) a mail storm —
> bounded above by 50 per run and by the stamp.
>
> **Deploy is held to end-of-arc.** Nothing here reaches production until chunks 4 and 5 exist, so
> the pending-migration count for this chunk is an arc-level obligation, not a chunk-level one.

This is a **downgrade in nothing** relative to Claude's declared line — I derive the same
LOW-MEDIUM, for the same two reasons (first creator-visible surface, first creator mail fan-out),
and I add one item the kickoff did not name: the `campaigns.listed_at` write is the only operation
in the chunk that mutates a pre-existing row, and it is worth calling out separately from the
migration because it is a **runtime** write, not a deploy-time one.

---

## 1. Corrections and read-pass findings that affect the kickoff

These come first because two of them change what gets built.

### C1 — ⚠ The AH id is wrong. This chunk is **AH-056**, not AH-055.

`AH-055` was taken earlier today by the brand-detail-page fix
(`adhoc-changes-log.md:73-109`, commits `478bcda` + `e3a1594`) — an AH-007-pattern pure-UI batch
that landed after the kickoff brief was written. The chunk-3 entry must be **AH-056** or the log
grows two entries with one id. **I will use AH-056 unless told otherwise**; nothing else depends on
the number.

### C2 — §5.32 reinterpretation: the flip detector already exists, and no new event is needed.

D6 says "the false→true flip enqueues a queued job". The inventory flagged that campaigns have no
domain event and `CampaignController::update()` dispatches nothing (`:142-165`). The read pass
found the mechanism is **already sitting in that method**: `update()` computes
`$before = $this->auditableSnapshot($campaign)` at `:147` and `auditableSnapshot()` **includes
`listed_on_jobs_board`** (`:247`, added by AH-054 F3 for exactly this reason — the docblock at
`:227-232` calls a visibility flip "exactly the kind of state change an audit trail exists to
explain"). So the before/after pair the audit row already needs **is** the flip detector: false in
`$before`, true in the post-save snapshot.

Two facts make this airtight as the single detection point:

- **`listed_on_jobs_board` has exactly one write path in the whole application.** A grep across
  `apps/api/app` finds it only in the model (`$attributes`/`$fillable`/casts/scope), the factory,
  `CampaignResource`, `UpdateCampaignRequest` (`:68`, `sometimes|boolean`) and
  `CampaignController::update()`. `store()` deliberately omits it (`:103-105`). There is **no
  dedicated toggle endpoint** — the Settings switch PATCHes the campaign.
- Therefore one guarded branch in `update()` covers every possible listing event.

**Structural intent preserved** (a flip is what triggers the fan-out; the fan-out is asynchronous
and carries no scheduler dependency); **mechanism adapted** (a controller-level before/after
comparison instead of a net-new campaign domain event). This is strictly less machinery than D6
anticipated and avoids inventing a campaigns event spine that §5.38 would then want contract-shaped.
Recorded here for ratification.

### C3 — §5.32 reinterpretation: the creator-routes architecture spec must parse a different file.

D9 asks for "the missing `layout: 'creator'` architecture spec (the agency-routes-guard spec's
sibling)". The sibling cannot be a copy: `agency-routes-agency-user-guard.spec.ts:30` resolves
`src/modules/auth/routes.ts`, and **creator routes are not in that file** — they live in
`src/modules/creators/routes.ts` and are concatenated into the exported table
(`auth/routes.ts:438-445`). A literal copy would parse zero creator routes and pass vacuously — the
exact false-green §5.35 exists to catch.

The creator spec will therefore parse `modules/creators/routes.ts` **and** assert the negative
across `modules/auth/routes.ts`: no `layout: 'creator'` route is declared outside the creators
module. It keeps the agency spec's two-assertion shape (pin the full route-name set; assert the
invariant per route) plus the non-vacuity guard the agency spec uses at `:112`.

### C4 — Naming collision: `ApplicationStatus` is already taken.

`App\Modules\Creators\Enums\ApplicationStatus` is the **creator onboarding** status
(`incomplete | pending | approved | rejected`) and is referenced across the tenancy, messaging,
discovery and test-helper surfaces (e.g. `CreateRosterCreatorsController.php:9`, `:75`). D1's job
application enum shares three of those four literal values, which would make two same-named enums
with overlapping values in one codebase — a genuinely dangerous import ambiguity given the approved
leg of D2's predicate sits three lines from where an application status would be read.

**Proposal:** `App\Modules\Campaigns\Enums\CampaignApplicationStatus` (`pending | accepted |
rejected`). Q6 asks Claude to ratify; I will not proceed on either name without a call.

### C5 — ⚠ The predicate and the recipient set both ignore **brand-scoped** blacklists.

D2's leg set and D6's recipient set both route through `scopePermitsMessaging()`
(`AgencyCreatorRelation.php:150-158`), which excludes `is_blacklisted = true` on the **relation** —
that is the agency-wide blacklist, and it excludes both `hard` and `soft` types (stricter than the
discover feed, which excludes hard only — `AgencyCreatorDiscoveryController.php:212-220`). I read
that extra strictness as correct for a job board and will record it rather than work around it.

What neither leg covers is the **brand-scoped** blacklist (`brand_creator_blacklist`, the
`AssignmentInviteGate::isHardBlacklisted()` surface). A creator hard-blacklisted for _this
campaign's brand_ would, under D2 as written, see the job, be mailed about it, and be able to apply
— and chunk 4's agency side would then have to reject an application from someone the invite gate
would have hard-blocked. That is a product wart with a security-adjacent smell, and it is not in
D2's four legs. **Q9** raises it; I have not assumed an answer.

### C6 — Two smaller schema-shape findings, folded into the plan below.

- `campaign_assignments` carries a **denormalized `agency_id`** with the `BelongsToAgency` trait
  plus a `ulid` (`create_campaign_assignments_table.php:41`, `:44`; `CampaignAssignment.php:83`).
  `campaign_applications` should match on both counts — the `ulid` because chunk 4 will route-bind
  an application, and adding either later is a second migration. Q8 confirms the `agency_id`
  denormalization specifically, since it introduces a drift surface D1 did not name.
- App timezone is **UTC** (`config/app.php:85`), which makes "today" in D2's `ends_at` leg
  unambiguous but still needs the comparison shape pinned — Q4.

### C7 — The email-channel gap the inventory surfaced is confirmed and D8 already absorbs it.

Nothing to decide; recording that I checked. No `LIVE_TYPES` entry exposes `'email'`
(`templates.ts:98-189`; `IN_APP_ONLY` at `:92`), and `NotificationPreferenceDefinition`'s docblock
forbids shipping a toggle a consumer does not deliver (`:60-63`). D8's ruling — no per-creator mail
opt-out v1, contained by flag + cap + rostered-only, with a tech-debt entry and a named trigger — is
the only option consistent with that rule, and I will implement it as written.

---

## 2. Cross-chunk handoff contracts verified (§5.11)

Chunk 3 consumes chunk 1+2 (AH-053/AH-054). Verified at HEAD:

| Contract consumed                    | Verified shape                                                                                                                                                    | Where                                                     |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| `Campaign::scopeListedOnJobsBoard()` | `listed_on_jobs_board = true` AND `status IN ('draft','active','paused')`; unchanged, tested, §5.34-partitioned                                                   | `Campaign.php:194-199`, `:84`                             |
| Listing copy columns                 | `listing_duration` / `listing_fee` `varchar(120)` free text; `listing_languages` / `listing_regions` `jsonb` cast `array`; `listing_examples_url` `varchar(2048)` | migration `:46-51`; casts `Campaign.php:222-224`          |
| Fee semantics                        | `listing_fee` is **display-only** copy; the binding offer stays `agreed_fee_*` per assignment                                                                     | migration docblock `:25-27`                               |
| `ends_at`                            | `timestamp NULL`, cast `datetime`, **not** a listing-floor field                                                                                                  | `Campaign.php:211`; `ValidatesJobsBoardListing.php:51-57` |
| Brand logo signed URL                | minted per-emission on a private disk; mechanism unchanged, only the audience is new                                                                              | `BrandResource.php:55`, docblock `:43-53`                 |
| Brand `website_url`                  | column exists, `varchar(2048)` nullable, on `$fillable`                                                                                                           | `create_brands_table.php:48`; `Brand.php:75`              |
| Archived-brand read                  | `Campaign::brand()` is `->withTrashed()` after a production incident                                                                                              | `Campaign.php:144`, rationale `:135-139`                  |

No gap found. D3's `website_url` ask is satisfiable from a column that already exists and is
already nullable — no schema work for it.

---

## 3. Sub-step plan

Eleven sub-steps in four phases. Each ends green on its own gate set; none leaves a half-wired
surface. The ordering is chosen so that **no user-reachable surface exists until its server-side
predicate is already pinned** — the board endpoint is tested before the page that calls it, and the
mail machinery is built before the trigger that fires it.

### Phase A — the backend spine (S1–S4)

**S1 · Schema, enums, models, factories.** Three additive migrations (`campaign_applications`;
`campaign_job_notifications`; `campaigns.listed_at`), the status enum (C4), two models with their
factories, the `Campaign::applications()` relation. No behaviour, no endpoint, nothing reads any of
it yet — the AH-054 "ships dark" posture. Column plan:

| Table                        | Columns                                                                                                                                                   | Constraints                                                                                                           |
| ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| `campaign_applications`      | `id`, `ulid`, `agency_id` (denormalized, Q8), `campaign_id`, `creator_id`, `status`, `note` (nullable text, ≤1000), `responded_at` (nullable), timestamps | `unique(campaign_id, creator_id)` mirroring `unique_assignment_campaign_creator`; FKs mirroring the assignments table |
| `campaign_job_notifications` | `id`, `campaign_id`, `creator_id`, `notified_at`                                                                                                          | `unique(campaign_id, creator_id)` — the whole point of the table                                                      |
| `campaigns`                  | `+ listed_at` (nullable timestamp)                                                                                                                        | none; no index (nothing filters on it — the AH-035 `previously_declined` posture)                                     |

Green on: migrate + rollback both directions on a scratch DB, factories instantiate, Pint, PHPStan.

**S2 · The board list endpoint.** `GET /creators/me/jobs`. The four-leg predicate composed **once**
in a single named private method so S3 and S4 re-apply the identical object (the
`AgencyCreatorDiscoveryController::discoverableCreators()` whitelist posture, `:157-168`), the
narrow card `JsonResource`, `withCount('applications')`, the caller's own application status via a
correlated subquery (the `connectionSubquery()` shape, `:180-188`), the paginated envelope with
`meta.{total,page,per_page,last_page}` (`:108-116`), route registration, the hand-maintained
route-comment block (`Creators/Routes/api.php:46-76`) and the `tenancy.md` §4 row.

Green on: the §5.34 disjoint negative set (review priority 1) on the list endpoint, break-revert on
the approved leg and the roster leg, Pint, PHPStan, backend Pest for the Campaigns + Creators
modules.

**S3 · The job detail endpoint + the D3 brand subset.** `GET /creators/me/jobs/{ulid}`, re-applying
the **same** predicate object so a delisted / ended / un-rostered / expired job 404s by ULID rather
than being probeable — the discover fail-closed posture (`AgencyCreatorDiscoveryController.php:129-135`,
docblock `:34-42`). The detail resource adds description, languages/regions, examples link and the
brand's `website_url`. Both resources get the **exact-keyset assertion** (review priority 4) so no
brand field can join by accretion.

Green on: the same §5.34 set re-run against `show()`, the two keyset assertions, the archived-brand
case (a listed campaign on a soft-deleted brand still renders, `withTrashed`), Pint, PHPStan, Pest.

**S4 · Apply.** `POST /creators/me/jobs/{ulid}/apply`. Re-validates the full predicate server-side
before writing (D5's delisted-between-render-and-click case, with its own distinct error code),
validates the optional note, inserts on the unique pair, writes the `application.submitted` audit
row, and returns the shape S9's UI renders from. The no-re-apply pin is the retained terminal row.
Duplicate-apply shape per **Q2**.

Green on: review priority 2 in full — apply-time re-validation (one case per predicate leg), the
no-re-apply pin, and the unique-pair race (§5.6 idempotency: two concurrent applies produce one row,
one audit row, no overwritten timestamp).

### Phase B — vocabulary and fan-out (S5–S7)

**S5 · Notification + audit vocabulary.** One pair: `campaign.job_posted` as an `AuditAction` case
and a `NotificationType` case (the one-vocabulary tie is proved at runtime by
`NotificationType::auditAction()`, `:100-103`), the `LIVE_TYPES` entry
(`recipient: 'creator'`, `group: 'assignment'`, `channels: IN_APP_ONLY`), the
`notifications.types.campaign_job_posted` template in `en` plus all 24 locales, and the two
tripwires: `templates.spec.ts` reds automatically on the enum add and greens on the registry entry;
`i18n-notifications-parity.spec.ts`'s hand-restated list (`:58-`) is **edited by hand** or it will
never ask about the new key (its own docblock at `:41-57` records that this restatement went stale
during AH-051). The `NotificationTypeEnumTest` / `AuditActionEnumTest` catalogues are updated.

Per D5, `application_submitted` is **chunk 4's**: no enum case, no registry row, no
`DEFERRED_WITHOUT_EMITTER` entry ships here. That keeps the allowlist honest
(`templates.spec.ts:35-43` calls it "an ALLOWLIST, not a dumping ground") and, since the arc deploys
as one unit, creates no production window where an agency misses applications.

Green on: `templates.spec.ts`, `i18n-notifications-parity.spec.ts`, `i18n-locale-parity.spec.ts`,
both enum catalogue tests, full `apps/main` Vitest.

**S6 · The fan-out.** The Pennant flag class (`job_posted_notifications_enabled`, default OFF, the
`IncompleteCreatorNudgeEnabled` shape verbatim — a `NAME` constant plus a `default(): Closure`,
because Pennant stores a non-Closure second argument as a literal; location per **Q5**), its
registration and its `AdminFeatureFlagController::FLAGS` row; the shared service holding the flag
check, the recipient query, the cap, the stamp and the dual-emit; the queued job; the localized
mailable plus its backend `lang/**` strings ×24; and the flip detector in
`CampaignController::update()` writing `listed_at` and dispatching (C2).

Per-recipient ordering follows AH-048 exactly — queue the mail, then stamp, inside the loop
(`IncompleteCreatorNudgeService.php:101-116`), so a worker retry after a mid-loop throw skips
everyone already stamped and the job is idempotent at recipient granularity. The job re-checks the
flag inside `handle()` (the `VerifyPostedContentJob.php:63-65` defence-in-depth precedent) so a
job enqueued before a flag-OFF flip never sends. Mail tags `['campaigns','job-posted']`
(`IncompleteCreatorNudgeMail.php:51` precedent); locale set at the call site as
`->locale($user->preferred_language ?: 'en')`, byte-identical to the five existing sites. Queue
posture per **Q3**.

Green on: review priority 3 in full — flag-OFF no-op via break-revert, the cap stamping only the
capped set (§5.34: the tail is untouched), the once-only re-list pin, and the §5.2 `Event::fake` /
`Mail::fake` splits per emission; plus §5.3 real-rendering mailable tests per locale with the
queued-locale assertion; plus review priority 5 (`listed_at` written on flip only, and a
break-revert-shaped assertion that `scopeListedOnJobsBoard` never consults it).

**S7 · The operator command.** `campaigns:preview-job-notifications {campaign}` — `--dry-run`
ignoring the flag and mutating nothing, `--limit=N` defaulting 50 with loud failure on non-numeric
or ≤0, oldest-roster-first draining for a capped roster, a count summary. The AH-048 split holds:
the flag lives in the **service**, not the command, so console and admin toggle agree
(`SendIncompleteCreatorNudges.php:15-19`, `IncompleteCreatorNudgeService.php:92-96`). **Not
registered in `bootstrap/app.php`'s schedule** — the scheduler is unverified in production
(`RESUMPTION-TEMPLATE.md:497-506`) and this command is operator-invoked by design.

Green on: dry-run writes nothing (asserted against both tables), the cap, the loud-failure paths.

### Phase C — the creator surface (S8–S9)

**S8 · SPA plumbing.** `api-client` types extended in `campaign.ts` (the precedent: the creator
assignment types already live there, `:347`, `:373`, `:454`, alongside AH-054's `listing_*` fields
at `:89-96`); the two routes in `modules/creators/routes.ts` with `layout: 'creator'` and
`guards: ['requireAuth']`; the nav item in `CreatorDashboardLayout.vue`'s single `navItems` array
(`:85-104`) gated on `applicationStatus === 'approved'` — reusing the AH-009 mechanism at `:89-95`
and the shell-level bootstrap at `:120-124` so a deep-link resolves it — which lights up desktop
(`:143-159`) and the AH-007 mobile bottom bar (`:246-256`) off the one array; the nav **label key in
`availability.json`'s `creatorNav`** (`:11-17`), not `creator.json`; `jobs.api.ts` per §5.13; and
the new creator-routes architecture spec per C3.

Green on: `vue-tsc`, ESLint, `CreatorDashboardLayout.spec.ts` extended for the shown/hidden branch
on both bars, the new architecture spec with a break-revert proving it is not vacuous, api-client
build + its own Vitest.

**S9 · The pages.** List page (cards, `CEmptyState` empty branch, pagination), detail page (external
examples link with `rel="noopener"`), apply dialog with the optional note, and the three rendered
application states (`pending` → "Applied", `rejected` → "Not selected" with Apply dead, `accepted`
→ the chunk-4 placeholder). All SPA i18n keys in `creator.json` under `creator.ui.*`, generated
across all 24 locales with a **real MT baseline** — the flaky-10 ruling binds every key here
because the whole surface is new creator-facing copy (`WORKING-PROCESS.md:135-138`;
`RESUMPTION-TEMPLATE.md:441-445`). No dashboard teaser.

Green on: page/dialog unit specs, `jobs.api.spec.ts`, locale parity, full `apps/main` Vitest,
vue-tsc, ESLint.

### Phase D — verification and docs (S10–S11)

**S10 · Playwright.** One §5.12 helper extending the roster family — `CreateRosterCreatorsController`
already stamps `application_status => Approved` (`:75`), so per §5.26 the cleanest shape is an
optional flag on the existing helper (or a sibling in `app/TestHelpers/Http/Controllers/`) that also
provisions a floor-complete listed campaign and a brand with a logo on the E2E media disk. One leg:
browse → card renders with `naturalWidth > 0` → detail → apply → applied state. The `/e2e-media`
route-shadowing trap (`jobs-board-brand-amends-review.md:244-246`) applies the moment a logo has to
render.

Green on: the new spec, then the **full** 24-spec Playwright board with the dev stack down and its
own E2E DB (`WORKING-PROCESS.md:126-129`), output to `playwright-report/`.

**S11 · Docs + the full board.** The review file (proposed name
`docs/reviews/jobs-board-c3-review.md`) with its mandatory Production-posture section; the AH-056
log entry; `tenancy.md` §4 rows for the three new routes with the §5.21 category named explicitly;
`feature-flags.md` row with the first-enable ritual; the `tech-debt.md` entry for the unwired email
channel with its trigger ("before any second recurring creator mail ships, or on any creator
complaint"); `RESUMPTION-TEMPLATE.md` Part 2 per §5.39 including the three new deploy obligations.

Green on: the **full** gate board — backend Pest serial at 2G, `apps/main` + `apps/admin` Vitest,
api-client, `vue-tsc`, ESLint, `pint --all` (run with `required_permissions: ["all"]` per §5.18,
CI-authoritative), PHPStan with an explicit memory limit, locale parity, full Playwright.

---

## 4. Review priorities → where each is discharged

| Priority                                                       | Sub-step(s) | Notes                                                                                                                                                                           |
| -------------------------------------------------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1 · four-leg predicate §5.34 disjoint set, list **and** detail | S2, S3      | Six cases: unapproved caller / non-rostered agency / unlisted flag / terminal status / expired `ends_at` / ended relation. Break-revert on the approved leg and the roster leg. |
| 2 · apply-time re-validation + no-re-apply + unique-pair race  | S4          | §5.6 idempotency assertion on the race.                                                                                                                                         |
| 3 · fan-out: flag-OFF, cap, once-only, dry-run, §5.2 splits    | S6, S7      | Flag-OFF is the break-revert anchor (AH-048 shape).                                                                                                                             |
| 4 · D3 exact-keyset on the job resources                       | S3          | Two assertions — card keyset and detail keyset — so accretion fails CI.                                                                                                         |
| 5 · `listed_at` on flip only, never consulted by the scope     | S6          | Two-sided: a non-flip save leaves it untouched; mutating it does not change what the scope returns.                                                                             |
| 6 · locale parity + flaky-10 spot-values                       | S5, S9, S11 | Parity specs prove keysets; spot-values are a manual VALUE check on bg/el/et/fi/ga/hu/lt/lv/mt/ro (parity cannot prove non-English).                                            |
| 7 · the new creator-routes architecture spec green             | S8          | Plus a break-revert proving it is not vacuous (C3 is exactly the vacuity risk).                                                                                                 |
| 8 · full Playwright including the new leg                      | S10         | Dev stack down, isolated E2E DB, restart + health-check after.                                                                                                                  |

---

## 5. Open questions

**Q2 and Q3 are the two Claude's brief named; Q1, Q6, Q8 and Q9 came out of the read pass and Q9 is
the one I would most like a ruling on.**

**Q1 — AH id.** Use **AH-056** (AH-055 is taken, C1)? _Assume yes unless corrected._

**Q2 — D5 duplicate-apply shape (the house shape, argued as asked).** The codebase has a clear
majority convention and one counter-example.

- _Majority — 409 plus a typed code_, used everywhere an action is re-run against an already-terminal
  subject: `AdminAgencyController` already-suspended / already-reactivated (`:100-101`, `:141`,
  `:242-246`), `AdminCreatorController` `creator.already_approved` (`:190`, `:200-204`),
  `EnableTwoFactorController` (`:38`), `DisableTwoFactorController` (`:47`),
  `ConfirmTwoFactorController` (`:50`), `VerifyEmailController` `auth.email.already_verified`
  (`:18`), `PortfolioController` (`:376-380`), `WizardCompletionController` (`:138`).
- _Counter-example — idempotent 200 with the existing row_: `CampaignAssignmentController::store()`
  (`:153-179`). But that one is an **agency re-inviting**, where returning the live row is the
  useful answer; and it is the very branch D1's rationale cites as the hazard to stay away from.

**My recommendation: 409, with two distinct codes, not one.** `job.already_applied` for an existing
`pending`/`accepted` row, and a separate code (`job.application_rejected` or similar) for the
retained rejected row. Collapsing them would make "you already applied" indistinguishable from "you
were not selected", and D5 requires the UI to render those as different states. §5.4's
non-fingerprinting rule does not push back here — both facts are the caller's own data about
themselves, so there is nothing to leak. Note this path is a **stale-tab / race** path in practice,
since the card renders the state and disables Apply.

**Q3 — the fan-out job's queue posture.** Four sub-questions, with what the code says:

1. **Connection / queue name.** All eight existing `ShouldQueue` jobs use the default connection and
   default queue; none calls `onQueue()`. Follow that, or give the fan-out its own queue so a large
   roster cannot starve `ProcessPortfolioImageJob` / `VerifyPostedContentJob`? _My lean: default, with
   a tech-debt note — at 279 creators and a cap of 50, starvation is not real yet._
2. **Retries / backoff.** No existing job sets `$tries`, `$backoff` or `$maxExceptions`. Given the
   queue-then-stamp ordering (S6), a retry is safe. _My lean: leave the defaults; state the reasoning
   in the review rather than adding knobs._
3. **Dispatch timing.** `CampaignController::update()` is **not** wrapped in a transaction
   (`:153-160`), so a plain `dispatch()` after `save()` has no in-transaction-visibility problem. Plain
   dispatch, or `dispatchAfterResponse()`? _My lean: plain dispatch — `afterResponse` runs in the web
   process and is the wrong home for a mail loop._
4. **The stamp-vs-send ordering itself.** I plan queue-then-stamp per recipient (AH-048's exact
   ordering). The residual failure mode is a creator stamped whose mail then fails at the transport
   layer — they are never re-notified for that job. The alternative (send-then-stamp with no retry
   protection) risks double-sending. _I want this ratified explicitly because it is the one place the
   design accepts a silent miss._

**Q4 — `ends_at` comparison shape.** App timezone is UTC (`config/app.php:85`). Is the leg
`(ends_at IS NULL OR ends_at >= <UTC start of today>)` — i.e. a campaign ending at 09:00 today stays
visible all day — or a strict `>= now()`? D2 says "`ends_at >= today`", which reads as start-of-day;
I will implement start-of-day unless corrected. Second half: the `OR IS NULL` makes the leg
non-sargable and `idx_campaigns_dates` is a `(starts_at, ends_at)` pair
(`create_campaigns_table.php:98`) that a lone `ends_at` predicate cannot lead. At current volume this
is a non-issue; confirm it goes to `tech-debt.md` as volume-triggered rather than being solved now
(the AH-054 partial-index entry set that precedent, migration `:33-37`).

**Q5 — where the flag class lives.** All six flag classes sit in
`app/Modules/Creators/Features/` and are registered in one place
(`CreatorsServiceProvider.php:251-259`) — including `SocialVerificationEnabled`, which gates a
**Campaigns** job, and `PerCampaignContractEnabled`, which gates campaign contracts. So the house
convention is one registry, regardless of the gated domain. Starting `Campaigns/Features/` would be
the first split. _My lean: join the existing registry_ (it also keeps every flag next to
`configurePennantScope()`, the app-global null-scope pin at `:166-169`). Confirm.

**Q6 — enum name.** `CampaignApplicationStatus` per C4? And the related naming knock-on: D1 locks the
audit noun as `application.*`, while `AuditAction`'s convention is `<subject>.<verb>` keyed to the
table (`AuditAction.php:23-24`), which would give `campaign_application.*`. I will follow D1
literally (`application.submitted`) and record the deviation; say if you would rather it match the
table.

**Q7 — module homes.** Model `CampaignApplication` in the **Campaigns** module (it is a
campaign-domain table); controller in the **Creators** module under `/creators/me/jobs` per D2, reading
Campaigns models cross-module exactly as `CreatorAssignmentController` already does. Confirm — this
is the shape I will build absent an objection.

**Q8 — denormalized `agency_id` on `campaign_applications`?** `campaign_assignments` has one plus
`BelongsToAgency` (C6), which is what makes chunk 4's agency-side scoping cheap. Mirroring it means
the creator-side read must explicitly `withoutGlobalScope(BelongsToAgencyScope::class)` — the same
discipline `CreatorAssignmentController` already applies at `:48`, `:77`, `:123`. The cost is a
denormalized column that can drift from `campaigns.agency_id`. _My lean: mirror the assignments
table_ — consistency inside one domain beats avoiding one denormalized FK, and chunk 4 will want it.

**Q9 — brand-scoped blacklist (C5).** Should D2's predicate and D6's recipient set both exclude
creators hard-blacklisted for the campaign's **brand**? This is the read pass's most substantive
finding: as D2 stands, a brand-blacklisted creator sees the job, gets the mail, and can apply — and
chunk 4 then has to reject them. Adding the leg is a fifth `whereNotExists` in the shared predicate
(cheap, and the `excludeHardBlacklisted()` shape at `:212-220` is right there). Not adding it is
defensible if the intent is that the board is roster-level and blacklists bite at invite time. **I
will not guess.**

**Q10 — backend `lang/**`bundle for the mailable.**`campaigns.php`(the mail is about a campaign,
and the sending domain is Campaigns) or`creators.php`(AH-048 put its creator-facing nudge there)?
The mailable's whole`trans()`key prefix follows this. _My lean:`campaigns.php`.\_

**Q11 — review file name.** `docs/reviews/jobs-board-c3-review.md`?

---

## 6. Standards this chunk will apply

Named up front so the completion package can be checked against a list rather than a memory: §5.1
and §5.34 (the new creator-routes architecture spec, with the negative case); §5.2 (Event/Mail fake
splits per emission); §5.3 (real-rendering mailable tests per locale + the queued-locale assertion);
§5.6 (idempotency on the unique-pair apply); §5.11 (§2 above); §5.12 and §5.26 (the E2E helper);
§5.13 (`jobs.api.ts`); §5.18 (Pint from outside the sandbox); §5.21 (tenancy category named in the
row justification); §5.32 (C2 and C3, recorded); §5.34 (the predicate's disjoint negative set);
§5.35 (break-revert on the approved leg, the roster leg, the flag-OFF no-op, the `listed_at`
non-consultation, and the new architecture spec); §5.37 (checked and found not to apply — job-posted
is single-direction); §5.39 (resumption template in the closing docs commit); §5.40 (the line in §0,
additive-first migrations, honest lossy `down()`, the Production-posture section, and the
snapshot-before-deploy obligation recorded for the arc's end).

Two standing operational rules that bind this chunk specifically: **restart the queue worker on
deploy** because it caches translations in memory and this chunk ships new mail copy
(`RESUMPTION-TEMPLATE.md:177-183`), and **the scheduler stays out of the design entirely** — no
`->daily()` registration, no scheduled scan (`:497-506`).

---

## 7. What this chunk deliberately does not build

The dashboard jobs teaser (D9, deferred to chunk 5 — the slot, the approved-only fetch discipline
and the count-plus-CTA pattern are documented at `CreatorDashboardPage.vue:278-299`); the
`application_submitted` notification vocabulary (D5 — chunk 4 owns it); the agency-side board column
that consumes applications (chunk 4); the cross-table accept transaction (chunk 4, named in D1); the
full-lifecycle Playwright spec (chunk 5); an email-channel preference toggle (D8, tech-debt with a
named trigger); a partial index on `listed_on_jobs_board` (volume-triggered, already logged by
AH-054); and any withdraw-application path (D1 — no withdraw v1).

---

**No code will be written until this plan is cleared.**
