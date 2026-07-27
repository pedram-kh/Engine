# Jobs Board arc — chunk 3 (creator job board + apply) — read-only inventory

- **Status:** Inventory only. No edits, no plan, no code. Kickoff follows after review.
- **Date:** 2026-07-27
- **Author:** Cursor (read-only pass), for Claude's chunk-3 kickoff.
- **HEAD:** `aa8d4106b87f508c4dd5c4f87ff53fae000758f1` (`aa8d410`), **= `origin/main`**, working tree
  clean (`git status --porcelain` empty before this file was written).
- **Orientation read before writing:** `docs/WORKING-PROCESS.md`, `docs/PROJECT-WORKFLOW.md` §5
  (5.1–5.40), `docs/reviews/adhoc-changes-log.md` (AH-054 → AH-034 range, incl. AH-053/054, AH-048,
  AH-035, AH-034), `docs/reviews/jobs-board-brand-amends-review.md` (closed chunk 1+2 review),
  `docs/reviews/RESUMPTION-TEMPLATE.md` Part 2, `docs/security/tenancy.md` §4, `docs/feature-flags.md`.

**§5.40 line for this document:** `PROD-DATA RISK: NONE` — this pass read files and ran `git`
read commands only; nothing was executed against any database. The eventual **build** line is
re-derived at plan-pause and is expected to be **⚠️ LOW-MEDIUM** for the reasons named in the
kickoff brief: chunk 3 is the arc's first creator-visible surface AND its first mail fan-out to
live creators (~279 creators on prod per `RESUMPTION-TEMPLATE.md:207`).

> **Provenance note on the c1+2 inventory.** No inventory file has ever been committed to this repo
> (`docs/reviews/` contains review files only; `git log --all -- 'docs/reviews/*inventory*'` is
> empty). The chunk-1+2 inventory's I6 answer therefore cannot be re-cited from the repo — §I5 below
> **re-derives** the rostered-creators query from code and says exactly which code it is, so the
> kickoff binds to source rather than to a chat artefact.

---

## I1 — Where the board lives, creator-side

### The route table and the layout switch

There is **one** route table for the whole main SPA: `apps/main/src/modules/auth/routes.ts`, whose
`routes` export concatenates five arrays (`apps/main/src/modules/auth/routes.ts:438-445`):
`authRoutes`, `appRoutes` (the agency shell), `onboardingRoutes`, `creatorsRoutes`,
`impersonationRoutes`, `errorRoutes`. The router instance and its single `beforeEach` guard
dispatcher live in `apps/main/src/core/router/index.ts:40-83`; `meta.guards` is a list of **symbolic
names** resolved against the registry in `apps/main/src/core/router/guards.ts:278-293`.

Layout is chosen purely by `meta.layout`, declared in the module augmentation at
`apps/main/src/modules/auth/routes.ts:47-66` — the six values are `auth | agency | onboarding |
creator | app | error`. A creator section is therefore a **data change** in
`apps/main/src/modules/creators/routes.ts`, not a wiring change.

**The creator route set today** (`apps/main/src/modules/creators/routes.ts`), every one of them
`layout: 'creator'` + `guards: ['requireAuth']` and nothing else:

| Path                                        | Name                                | Line       |
| ------------------------------------------- | ----------------------------------- | ---------- |
| `/creator/dashboard`                        | `creator.dashboard`                 | `:20-21`   |
| `/creator/profile`                          | `creator.profile`                   | `:33-34`   |
| `/creator/availability`                     | `creator.availability`              | `:43-44`   |
| `/creator/assignments`                      | `creator.assignments`               | `:54-55`   |
| `/creator/assignments/:ulid`                | `creator.assignment.detail`         | `:66-68`   |
| `/creator/notifications`                    | `creator.notifications`             | `:81-82`   |
| `/creator/notifications/preferences`        | `creator.notifications.preferences` | `:94-95`   |
| `/creator/messages` (+ `:agencyUlid` child) | `creator.messages` / `.thread`      | `:108-130` |

The `/creator/*` **path prefix is load-bearing**, not cosmetic: the comment at
`apps/main/src/modules/creators/routes.ts:75-80` records that a shared path would be caught by
`requireAgencyUser` and 302'd, which is why the creator notifications page has its own path while
rendering the _same_ component as the agency route (`apps/main/src/modules/auth/routes.ts:383-388`).
Two routes already render one shell-agnostic page this way.

### The nav registry

There is **no separate nav-registry file.** The creator nav is a `computed` array inside the layout:
`apps/main/src/modules/creators/layouts/CreatorDashboardLayout.vue:85-104`. It is consumed **twice**
off the same array — the desktop topbar `<nav>` (`:143-159`, testid `creator-nav-<key>`) and the
AH-007 mobile bottom bar (`:246-256`, testid `creator-bottom-nav-<key>`). Labels come from
`t('availability.creatorNav.<key>')`, i.e. the **availability bundle**, not `creator.json` — see
`apps/main/src/core/i18n/locales/en/availability.json:11-17`, which today holds exactly five keys
(`profile`, `dashboard`, `assignments`, `availability`, `messages`).

The one existing conditional in that array is the AH-009 Profile item, gated on
`applicationStatus !== null && applicationStatus !== 'incomplete'`
(`CreatorDashboardLayout.vue:89-95`). `applicationStatus` is bootstrapped **at shell level**
(`:120-124`) precisely so a deep-link/refresh onto any creator page resolves it — a fix recorded in
the comment at `:111-119`. That is the existing mechanism a nav item conditional on creator state
would reuse.

The layout is also where the "wide" opt-out lives (`meta.wide`, `:63`, `:234-242`) — the messaging
two-pane is the only current consumer; a board would sit inside the default 960px reading column.

### The creator dashboard's structure — is a jobs teaser v1 or nav-only?

`apps/main/src/modules/creators/pages/CreatorDashboardPage.vue` is a single flex column
(`:391-396`) with, in order: a header with the aurora accent rule (`:198-205`, `:398-407`), one of
four mutually-exclusive status banners (`pending` `:208`, `approved` `:221`, `rejected` `:231`,
else-incomplete `:263`), a `CompletenessBar` (`:268-272`), and then **two approved-only sections**:

1. **The campaign-invitation teaser** (Sprint 8 Ch2 D-10) — `v-if="status === 'approved'"` at
   `:278`, testid `dashboard-assignments-teaser` (`:282`), counting `invited` assignments via
   `loadInvitedCount()` (`:126-133`) and linking to `creator.assignments` (`:299`).
2. **The connection-requests inbox** (Sprint 6.6c D-d1) — `v-if="status === 'approved'"` at `:312`,
   testid `dashboard-requests` (`:314`), with skeleton / list / `CEmptyState` branches.

Both fetch **only** in the approved branch: `onMounted` at `:183-193` calls `loadRequests()` +
`loadInvitedCount()` behind `if (status.value === 'approved')`, with the comment at `:187-188`
stating the rule explicitly ("the first creator-side fetch stays scoped to the surface that needs
it"). So the dashboard already carries a **direct precedent for a count-plus-CTA teaser** to a
dedicated creator surface, gated on approved, fetching nothing for other states. Whether a jobs
teaser ships v1 is a product call, not a structural one — the slot, the pattern and the fetch
discipline all exist.

### The approved-creator gate — what actually exists

**There is no `requireApprovedCreator` route guard, and no creator route is gated on application
status at all.** The guard registry has exactly six entries
(`apps/main/src/core/router/guards.ts:278-293`): `requireAuth`, `requireGuest`,
`requireMfaEnrolled`, `requireAgencyAdmin`, `requireOnboardingAccess`, `requireAgencyUser`. The only
status-aware guard is `requireOnboardingAccess` (`:181-211`) and it works the **other** way — it
bounces _non_-`incomplete` creators OUT of the wizard, to `creator.dashboard` (`:206-208`). Every
`/creator/*` route carries `requireAuth` alone.

The approved gate is therefore enforced in **two other places**, and this is the pattern a new
creator section would join:

- **Client-side, per-page:** the `status === 'approved'` template branches above — a UX gate that
  also prevents the fetch, never a security boundary.
- **Server-side, per-endpoint:** an explicit `application_status !== Approved` check at each call
  site. Every current instance:
  `CreatorConnectionRequestController.php:130`;
  `CreatorPolicy.php:217` (the messaging single-pair gate);
  `MessageableContactsFinder.php:56` (agency→creators set) and `:90` (creator→agencies set);
  `RelationshipSendState.php:112`;
  `AgencyCreatorDiscoveryController.php:133` + `:166`;
  `AgencyConnectionRequestController.php:74`;
  `CampaignAssignmentController.php:127` (invite targets approved + discoverable only).

  ⚠ **Notably absent:** `CreatorAssignmentController` — the creator's own assignments list and
  accept/decline/counter endpoints — has **no** approved check
  (`apps/api/app/Modules/Creators/Http/Controllers/CreatorAssignmentController.php:44-58`,
  `:108-167`). Ownership is structural (`creator_id`), and a non-approved creator cannot have been
  invited in the first place because the invite path gates on approved, so it is safe today by
  construction rather than by assertion. A jobs board would **not** inherit that safety: the board
  is a _browse_ surface, so its predicate has to carry the approved leg itself.

- **Route-level allowlist:** `/creators/me/*` sits under `['auth:web', 'tenancy.set', 'verified']`
  (`apps/api/app/Modules/Creators/Routes/api.php:79-81`) — note `verified` (email) is enforced at
  the group, `approved` is not.

There **is** an architecture test that walks the route table
(`apps/main/tests/unit/architecture/agency-routes-agency-user-guard.spec.ts:44-57` parses
`routes.ts` with a regex and asserts every `layout: 'agency'` route carries
`requireAuth → requireAgencyUser` in that order, `:110-127`). **No equivalent spec exists for
`layout: 'creator'` routes** — a creator-shell guard invariant is currently unpinned.

---

## I2 — The application record: does any candidate row exist?

### Short answer

**No table, and no state.** There is no `campaign_applications` table, no `applications` table, and
no application-shaped column anywhere. The full migration list contains three `job`-matching files
and none is relevant (`0001_01_01_000002_create_jobs_table.php` is Laravel's queue table;
`2026_05_14_100008_create_tracked_jobs_table.php` is the async-job tracker;
`2026_07_27_100000_add_jobs_board_listing_to_campaigns.php` is AH-054's own). The assignment state
machine has **no pre-`invited` state**: `invited` is the graph's entry point and is created by an
INSERT, not a transition.

### The state machine's shape around `invited`

`AssignmentStatus` (`apps/api/app/Modules/Campaigns/Enums/AssignmentStatus.php:49-66`) has 16 cases.
`Invited = 'invited'` is `:51`. Terminality is a method, not a column
(`:72-81`: `Declined`, `Rejected`, `PaymentReleased`, `Cancelled`).

`CampaignAssignmentStateMachine` is the **sole** status authority — the class docblock states no
controller flips the column (`apps/api/app/Modules/Campaigns/Services/CampaignAssignmentStateMachine.php:22-24`),
and every method opens with `assertSource()` (`:590-596`), which throws
`AssignmentTransitionException::illegal()` on any source outside its allowlist. Edges touching
`invited`:

| Edge                  | Method                         | `assertSource` line | Notes                                                                                             |
| --------------------- | ------------------------------ | ------------------- | ------------------------------------------------------------------------------------------------- |
| `invited → declined`  | `decline()` `:79`              | `:81`               | stamps `responded_at`                                                                             |
| `invited → countered` | `counter()` `:94`              | `:100`              | records `countered_fee_*` distinctly, never overwrites `agreed_fee_*` (`:108-113`)                |
| `invited → accepted`  | `accept()` `:209`              | `:211`              | stamps `responded_at` + `accepted_at` (`:218-222`)                                                |
| `countered → invited` | `reinvite()` `:129`            | `:135`              | fee-only re-offer; clears `countered_fee_*` + `responded_at` (`:143-148`)                         |
| `declined → invited`  | `reofferAfterDecline()` `:173` | `:182`              | **the AH-035 edge** — overwrites the whole offer and raises `previously_declined = true` (`:199`) |

**The entry into `invited` is a plain create, not an edge.** `CampaignAssignmentController::store()`
inserts the row at `apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentController.php:203-225`
with `'status' => AssignmentStatus::Invited`, then **hand-writes** the `assignment.invited` audit row
(`:232-241`) and hand-dispatches `AssignmentTransitioned` with `from = to = invited` (`:243-249`) —
the comment at `:227-231` says why: "invite is a CREATE, not a machine transition, so the ENDPOINT
hand-writes … so the future board listener can create the card off `eventKey()`". **This is the
precedent chunk 4's `accept → invited` would follow or diverge from**, and it matters: a create-path
entry means the machine's `assertSource` discipline does not cover the row's birth.

The `store()` method is also already **idempotent on the unique pair** with a two-outcome branch at
`:153-179`: an existing `declined` row is re-offered via the machine (200); any other existing status
is returned as-is, no second row, no duplicate audit/event.

### The AH-035 `previously_declined` precedent for state-adjacent flags

Migration `2026_07_12_110000_add_previously_declined_to_campaign_assignments.php:26-29`: a boolean,
`default(false)`, `after('responded_at')`, **no index** — the docblock at `:18-20` states the reason
verbatim ("read per already-indexed row, never filtered on"). Model: `$fillable` `:115`, cast
`'boolean'` `:257`, property docblock `:48`. Written only inside the machine's `mutate` closure
(`CampaignAssignmentStateMachine.php:199`). Per `RESUMPTION-TEMPLATE.md:310-311`, it is
**agency-side only, never creator-visible** — and indeed `CreatorAssignmentController::toRow()`
(`:172-209`) does not emit it.

The precedent is precise: _a durable fact about the row's history that the status column cannot hold,
carried as an additive-nullable/defaulted boolean written only by the state authority, unindexed
until something filters on it, and audience-scoped at the resource layer._

### Table-vs-state at the data level — what each costs

Reported, **not decided**. Both options are stated against the same four consumers the brief names.

#### Option A — a new `campaign_applications` table

- **Uniqueness per creator+campaign.** Free and explicit: a `unique(['campaign_id','creator_id'])`
  mirroring `unique_assignment_campaign_creator`
  (`2026_06_05_100001_create_campaign_assignments_table.php:129`). Independent of the assignment
  table, so it does not collide with an assignment the agency creates for the same pair.
- **The no-re-apply-after-rejection rule.** Naturally expressed: a retained row with a terminal
  status is the record, exactly the shape `RelationshipStatus::Declined` uses
  (`apps/api/app/Modules/Creators/Enums/RelationshipStatus.php` docblock: the row is RETAINED so the
  unique pair stays occupied). No interaction with the assignment lifecycle.
- **Chunk 4's accept path.** Costs a **cross-table transaction**: the agency's accept must create (or
  re-offer) a `campaign_assignments` row AND close the application row atomically. It also has to
  decide what happens when an assignment for that pair already exists — `store()`'s existing
  idempotency branch (`CampaignAssignmentController.php:153-179`) becomes a three-way branch. The
  offer fields (`agreed_fee_*`, `fee_per`, `offer_description`, attachment) have no home on an
  application row, so accept is a genuine hand-off, not a status flip.
- **Board-card queries.** Applicant count is a clean `withCount('applications')` on `Campaign` (the
  pattern is everywhere — `CampaignController.php:47`, `TalentPoolController.php:49`,
  `BoardController.php:49`), and "have I applied?" is a correlated subquery in the style of
  `AgencyCreatorDiscoveryController::connectionSubquery()` (`:180-188`). Both are one query, no N+1.
- **Cost side.** A new table = a new migration, a new model, a new factory, a new policy surface, a
  new tenancy.md §4 row, and a second lifecycle to keep in sync with the assignment lifecycle
  forever. It is also the first table in the domain whose rows an agency can see but not create.

#### Option B — a pre-`invited` assignment state (e.g. `applied`)

- **Uniqueness per creator+campaign.** Free — the constraint already exists
  (`create_campaign_assignments_table.php:129`). But it becomes a **shared** namespace: an agency
  invite and a creator application now compete for the same row, and `store()`'s idempotent
  no-op branch (`:174-178`) would silently swallow an agency invite to a creator who had already
  applied, returning the `applied` row as-is with the offer never persisted. That is a real,
  currently-live code path, not a hypothetical.
- **The no-re-apply-after-rejection rule.** Needs a rejection state that is terminal for the creator
  but does not poison the agency's ability to invite later — i.e. the AH-035 problem again. AH-035
  solved `declined` by adding an edge OUT of a terminal state plus `previously_declined`; the
  mirror-image here would be a `rejected`-for-application state whose only edge back is an agency
  invite, which is a state-machine widening on the platform's most load-bearing enum.
- **Chunk 4's accept path.** Cheapest of all four axes: one new machine edge
  (`applied → invited`), inheriting `assertSource` fail-closure, the transactional audit
  (`commit()` at `:601-638`), and the `AssignmentTransitioned` dispatch that the board's
  `eventKey()`-keyed listener already consumes (§5.38). No cross-table transaction.
- **Board-card queries.** Applicant count becomes `withCount(['assignments' => fn($q) =>
$q->where('status','applied')])` — still one query, but the count is now **status-filtered on a
  column shared with 15 other states**, and the existing `assignment_count` emitted at
  `CampaignResource.php:64` (unfiltered `withCount('assignments')`) would start including applicants
  in an agency-facing number that today means "creators engaged". That is a silent semantic change
  to a shipped field.
- **Ripple the state adds.** `AssignmentStatus` is consumed far beyond the machine:
  `isTerminal()`/`isPaymentEligible()` (`:72-100`), the FE union
  (`packages/api-client/src/types/campaign.ts:28`), the board column catalogue and its automations,
  `NotificationType`'s one-vocabulary tie, and `NotificationTypeEnumTest` /
  `AuditActionEnumTest` catalogue tripwires
  (`apps/api/tests/Feature/Modules/Notifications/NotificationTypeEnumTest.php`,
  `apps/api/tests/Feature/Modules/Audit/AuditActionEnumTest.php`). Adding a case is deliberately
  expensive by design.
- **Column headroom.** `status` is `varchar(32)` (`create_campaign_assignments_table.php:70`) with no
  DB CHECK, so no migration is needed for the value itself — only for any new timestamp column.

#### The asymmetry worth naming

The four axes do not point the same way. **Uniqueness and the no-re-apply rule favour a table**
(clean namespace, retained-terminal-row precedent). **Chunk 4's accept favours a state** (one guarded
edge vs. a cross-table hand-off). **Board-card queries are a wash on cost but not on risk**: the
state option mutates the meaning of an already-emitted `assignment_count`. The single sharpest
finding for the kickoff is the **`store()` collision**: with a pre-`invited` state, the live
idempotency branch at `CampaignAssignmentController.php:153-179` would need a deliberate new
outcome, and getting it wrong loses an agency's offer silently.

---

## I3 — The visibility query, precisely

### The shipped pieces

**Leg 1 — the listing predicate.** `Campaign::scopeListedOnJobsBoard()`
(`apps/api/app/Modules/Campaigns/Models/Campaign.php:194-199`) is two conditions:
`listed_on_jobs_board = true` (`:197`) AND `status IN LISTABLE_STATUSES` (`:198`), where
`LISTABLE_STATUSES = ['draft','active','paused']` (`:84`) — pinned as exactly the complement of the
terminal statuses (`CampaignJobsBoardListingTest.php:378-381`). Its docblock (`:174-193`) explicitly
anticipates chunk 3: "enforced HERE, at read time, exactly like the `ends_at` auto-delist the arc
adds in chunk 3 (one mechanism for both, so the two cannot drift)". It has a disjoint §5.34 negative
set (`CampaignJobsBoardListingTest.php:352-365`) and a complete positive partition (`:367-376`).

⚠ **The scope is not agency-scoped by itself.** `Campaign` uses `BelongsToAgency`
(`Campaign.php:68`), so the global scope narrows to the _ambient_ tenant. A creator caller has no
ambient tenant that means anything — the creator route group applies `tenancy.set` but not `tenancy`
(`Creators/Routes/api.php:81`), and every existing creator-side cross-agency read drops the scope
explicitly (`CreatorAssignmentController.php:48`, `:77`, `:123`;
`MessageableContactsFinder.php:98`). Chunk 3 inherits that requirement.

**Leg 2 — the rostered-agencies relation.** `AgencyCreatorRelation::scopePermitsMessaging()`
(`apps/api/app/Modules/Agencies/Models/AgencyCreatorRelation.php:150-158`) is
`relationship_status = 'roster'` (`:153`) AND (`is_blacklisted = false` OR `IS NULL`) (`:155-156`).
Its docblock (`:135-149`) is emphatic that this is **the relation leg only** — the creator-`approved`
leg "lives at each call site (it is a `creators`-table fact, not a relation column)", and names the
break-revert that pins it. It is the shared primitive behind both the single-pair gate
(`CreatorPolicy.php:143`, `:230`) and the set-valued finder
(`MessageableContactsFinder.php:52`, `:100`), pinned by
`apps/api/tests/Feature/Modules/Messaging/MessageableContactsAgreementTest.php:29-34`.

**Leg 3 — the approved leg, and the AH-051-era machinery.** The creator-as-viewer form is
`MessageableContactsFinder::agenciesForCreator()`
(`apps/api/app/Modules/Messaging/Services/MessageableContactsFinder.php:88-107`), and it is worth
reading as a whole because it is _exactly_ the shape leg 2+3 need:

- `:90` — early return `collect()` when `$creator->application_status !== Approved`. The comment at
  `:81-85` explains the semantics: an unapproved creator can message no one, so the set is empty,
  keeping exact agreement with the single-pair gate.
- `:98` — `withoutGlobalScope(BelongsToAgencyScope::class)` with the comment at `:95-97` ("the
  caller is a CREATOR who may relate to many agencies — the ambient tenant context must not narrow
  the set").
- `:99` — `where('creator_id', $creator->id)` (structural ownership).
- `:100` — `->permitsMessaging()`.
- `:101` — `->with('agency:id,ulid,name,logo_path')`.

The AH-051 agreement machinery around it: `RelationshipStatus` gained `Ended`
(`apps/api/app/Modules/Creators/Enums/RelationshipStatus.php`, the `ended` case + its docblock),
which is _not_ `roster` and therefore already falls out of `scopePermitsMessaging()` for free — the
`ended` relation is "never messageable, never contact-visible". `CreatorPolicy::canSeeContactDetails`
(`CreatorPolicy.php:108`, `:143`) was tightened to require the same roster leg. The load-bearing
invariants block at `RESUMPTION-TEMPLATE.md:454-462` names all of this as do-not-regress.

**Leg 4 — `ends_at`.** `campaigns.ends_at` is `timestamp('ends_at')->nullable()`
(`2026_06_05_..._create_campaigns_table.php:65`), cast `'datetime'` (`Campaign.php:211`), indexed as
a **pair** with `starts_at` (`idx_campaigns_dates`, `create_campaigns_table.php:98`) — so a lone
`ends_at >= today` predicate does not use that index leading-edge. `ends_at` is fully optional at
create and update: `CampaignController::store()` writes `$validated['ends_at'] ?? null` (`:96`),
and `AssignmentInviteGate::availabilityConflict()` already treats a null window as "nothing to warn
about" (`AssignmentInviteGate.php:76-81`) — a precedent for _null means unbounded_, not _null means
excluded_.

⚠ **`ends_at IS NULL` is genuinely undecided and cannot be inferred from code.** Both readings are
defensible against the shipped rules: "never expires" is consistent with the invite gate's
null-window handling and with D3 not listing `ends_at` in `LISTING_FLOOR_FIELDS`
(`ValidatesJobsBoardListing.php:51-57` — the floor is `description`, `listing_duration`,
`listing_fee`, `listing_languages`, `listing_regions`; **`ends_at` is deliberately not a floor
field**, so a listable campaign can legitimately have none). "Excluded" is consistent with D5's
read-time-decides posture. **Flagged for a kickoff decision.** Note the knock-on: if null means
"never expires", the ends_at leg is `(ends_at IS NULL OR ends_at >= today)`, which is a
non-sargable OR and interacts with the index question below.

### The composed predicate (descriptive, not prescriptive)

Reading the four legs together, a creator-side board query has to satisfy, per row:

1. `withoutGlobalScope(BelongsToAgencyScope::class)` on `Campaign` — else the ambient tenant hides
   or wrongly narrows the set.
2. `application_status = 'approved'` on the **calling creator** (the leg 3 early-return shape, not a
   join).
3. `campaigns.agency_id IN (` the creator's `permitsMessaging()` relation agency ids `)` — leg 2.
4. `Campaign::scopeListedOnJobsBoard()` — leg 1, unchanged and already tested.
5. The `ends_at` read-time gate — leg 4, semantics pending.

Legs 3+5 are net-new predicate surface; legs 1, 2 and 4 are all shipped, tested primitives. Whether
leg 3 is expressed as `whereIn` over a sub-select or a `whereExists` correlated to
`agency_creator_relations` is a mechanism question — both shapes exist in the codebase
(`AgencyCreatorDiscoveryController::connectionSubquery()` `:180-188` for correlated select,
`::excludeHardBlacklisted()` `:212-220` for `whereNotExists`).

**Index posture.** `campaigns` has `idx_campaigns_agency_brand`, `idx_campaigns_status`,
`idx_campaigns_dates` (`create_campaigns_table.php:96-98`) and **no index on
`listed_on_jobs_board`** — the AH-054 migration says so explicitly and records the partial index as
a volume-triggered follow-up in tech-debt
(`2026_07_27_100000_add_jobs_board_listing_to_campaigns.php:33-37`). That reasoning assumed an
agency-scoped read; a creator-side read is scoped by _a set of_ agencies, which is a different
access pattern. Worth re-examining at kickoff, though at current scale it is almost certainly still
a non-issue.

### Which controller/resource pattern fits

Two live candidates, and they are meaningfully different:

**(a) The discover-feed pattern** — `AgencyCreatorDiscoveryController`
(`apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorDiscoveryController.php`). Structurally
the closest analogue: it is the codebase's only "browse a pool you have no row in" surface. What it
demonstrates:

- A **fail-closed whitelist** base query in one private method (`discoverableCreators()` `:164-168`)
  with the docblock naming it as a whitelist (`:34-42`), and the **same gate re-applied on the
  detail endpoint** (`:133-135`) so a non-qualifying subject is not probeable by ULID. That is
  directly transferable: a job details page must re-apply the full board predicate, not just resolve
  the campaign.
- **Slim card projection** — an explicit `->select([...])` of card columns only, with the comment
  "no heavy/leaky columns" (`:74-87`).
- **Per-caller annotation via correlated subquery, no N+1** (`:88`, `:180-188`), scoped to the
  caller by an explicit id filter, with the privacy break-revert named in the docblock (`:44-51`).
  This is the shape "have I already applied?" would take.
- Paginated envelope with `meta.{total,page,per_page,last_page}` (`:99-116`).
- Its resource is a **dedicated** `CreatorDiscoveryResource`, not the general-purpose one.

**(b) The creator-assignment-list pattern** — `CreatorAssignmentController::index()`
(`apps/api/app/Modules/Creators/Http/Controllers/CreatorAssignmentController.php:44-58`). What it
demonstrates: the creator-side scope bypass (`:48`), eager loads narrowed to named columns
(`:50`), stable ordering (`:51-52`), a **flat unpaginated `data[]`** (`:55-57`), and — importantly —
a hand-rolled `toRow()` array (`:172-209`) rather than a `JsonResource`. The whole
`/creators/me/assignments` surface emits hand-built arrays.

The honest read: **(a) is the right structural model** (browse, whitelist, re-applied on detail,
per-caller annotation, paginated), **(b) is the right module and auth model** (`creators/me/*`,
structural ownership, scope bypass, tenancy.md §4 row). They are not in conflict — a chunk-3
controller would live where (b) lives and be built like (a). One thing to settle at kickoff: (b)'s
surface is unpaginated and returns hand-built arrays; (a) paginates and uses a Resource class. A
board with a growing campaign population argues for (a)'s posture.

### ⚠ The card payload and the AH-005-class boundary crossing

The brief calls this out and the code confirms it is real. `BrandResource`
(`apps/api/app/Modules/Brands/Http/Resources/BrandResource.php:33-63`) emits the **whole brand**:
`name`, `slug`, `description`, `industry`, `website_url`, `logo_path` (`:54`), `logo_url` (`:55`,
a short-lived signed GET minted inside the emission), `default_currency`, `default_language`,
`status`, `brand_safety_rules`, `client_portal_enabled`, timestamps, plus the agency relationship.
The docblock at `:43-53` states `logo_url` is minted "inside an emission that is already behind the
brand policy". **A creator is not behind the brand policy.** `BrandResource` has, to date, only ever
been served to agency members.

**What a creator can see of a brand today:** exactly one field — `brand_name`, hand-copied into the
assignment row at `CreatorAssignmentController.php:205` (`'brand_name' => $brand?->name`), on
assignments they are already party to. That is the entire current brand-to-creator surface. There is
no brand logo, no description, no industry, no website, no slug, no status.

**What a creator can see of an agency today:** `id, ulid, name, logo_path` via
`MessageableContactsFinder::agenciesForCreator()` (`:101`) — so _agency_ logo already crosses the
boundary, _brand_ logo has not. The two are different entities and the precedent does not transfer
automatically.

The question the kickoff has to answer, in AH-005 terms (contact-detail withholding is server-gated
**by omission** — `RESUMPTION-TEMPLATE.md:460-462`), is: **what subset of brand data may a rostered
creator see, and is it emitted by a dedicated narrow resource or by re-using `BrandResource`?** The
AH-053 review's own framing (`jobs-board-brand-amends-review.md:129-132` in the AH log: "a creator
browsing the Jobs Board sees the brand before the job") means the answer cannot be "none" — the
whole completeness floor was built so the card has something to show. The §5.22 `withAdmin()`
precedent (`PROJECT-WORKFLOW.md:369-373`) is the house pattern for one resource serving two
audiences with a conditionally-appended block; the discover module's dedicated
`CreatorDiscoveryResource` is the house pattern for the opposite choice. Both are live.

Two adjacent facts for the same decision:

- **Signed-URL mechanics already work cross-audience.** `logo_url` is minted per-emission on a
  private disk (AH-053 D7, `jobs-board-brand-amends-review.md:69-75`), and the E2E media disk
  stand-in is recorded as tech-debt (`RESUMPTION-TEMPLATE.md:567-569`). Nothing about the mechanism
  needs to change; only the authorization question is open.
- **Brand soft-delete.** `Campaign::brand()` uses `->withTrashed()` (`Campaign.php:144`) because of a
  production incident (`:135-139`). A listed campaign whose brand was archived would render an
  archived brand's name and logo on a creator-facing card. Worth a line in the kickoff.

---

## I4 — Applicant count + "Active today"

### Applicant count

The `withCount` pattern is uniform across the codebase and cheap:
`CampaignController::index()` `:47` (`withCount('assignments')`) and `::show()`/`::update()`
`:133`, `:163` (`loadCount('assignments')`); `TalentPoolController` `:49`, `:84`, `:100`, `:134`;
`BoardController` `:49`, `:74`; `BoardColumnController` `:135`. `TalentPoolResource.php:16` documents
the idiom ("cheap `withCount('creators')`"), and `CampaignResource.php:64` emits it as
`'assignment_count' => $campaign->assignments_count ?? null` — the `?? null` making it
present-only-when-counted.

So an applicant count is a solved problem _once §I2 decides what is being counted_:

- **If Option A (table):** `withCount('applications')` on a new relation. Independent of
  `assignment_count`, no semantic collision.
- **If Option B (state):** a filtered `withCount(['assignments' => fn ($q) => $q->where('status',
'applied')])`, **and** a decision about whether the existing unfiltered `assignment_count`
  (`CampaignResource.php:64`, agency-facing) should start or stop including applicants. Today that
  number means "creators engaged on this campaign"; a pre-invited state silently changes it.

One thing the count needs regardless: the board card is **creator-facing**, and an applicant count is
an aggregate about _other creators_. It leaks nothing identifying, but it is the first
creator-visible aggregate over other creators' behaviour, so it deserves a sentence in the review's
production-posture section rather than passing as obviously fine.

### "Active today" / recency — the honest options

**Confirmed: there is no timestamp for listing.** `listed_on_jobs_board` is a bare
`boolean(...)->default(false)` (`2026_07_27_100000_add_jobs_board_listing_to_campaigns.php:46`) with
no companion column; the migration adds `listing_duration`, `listing_fee`, `listing_languages`,
`listing_regions`, `listing_examples_url` (`:47-51`) and nothing time-shaped. The campaign's own
timestamps are `created_at`, `updated_at`, `published_at`, `completed_at`, `starts_at`, `ends_at`,
`posting_window_*` (`Campaign.php:43-51`, `:62-64`) — none of which means "listed at".

The three options, reported with what each actually costs:

1. **Mine the audit snapshot.** F3 does put the flag in the audit trail:
   `CampaignController::auditableSnapshot()` includes `'listed_on_jobs_board'` (`:247`) with the
   rationale at `:227-232` ("a visibility flip is exactly the kind of state change an audit trail
   exists to explain"), it is written on both create (`:113-117`) and update (`:155-160`), and
   `CampaignJobsBoardListingTest.php:336-337` asserts `before` false → `after` true. So the
   information _exists_. **But** reading it for a per-card display value means a creator-facing feed
   querying `audit_logs` on every render, JSON-digging `before`/`after`, per campaign — the audit
   log becoming a read model for a UI chip. The brief already rules this out and the code agrees:
   it is a correctness-fragile, performance-hostile use of a forensic table.

2. **A `listed_at` timestamp column.** Additive-nullable, the AH-048 `incomplete_nudge_sent_at`
   shape (`2026_07_16_100000_add_incomplete_nudge_sent_at_to_creators_table.php`, described at
   `PROJECT-WORKFLOW.md:485` as the reference §5.40 posture: "additive-nullable column,
   single-timestamp write, `--dry-run` mutates nothing"). Costs: one migration; a write on the
   false→true edge only (which means the write path now owns a second fact about listing, and D5's
   whole argument was that the read scope alone decides visibility — worth checking the intent
   survives); and a **backfill question** the kickoff must answer, because zero campaigns are listed
   at deploy so there is nothing to backfill _yet_ — but if the column ships after any campaign is
   listed, existing listed rows have a null `listed_at` and the chip has to degrade gracefully.
   Deploying this column in the same release as the board itself avoids the problem entirely.

3. **Drop the recency chip v1.** Zero cost, zero risk, and no dishonest display. The cost is
   product, not engineering: a job board without recency reads static.

⚠ **The honesty constraint worth stating plainly:** with no `listed_at`, _any_ recency chip rendered
from `updated_at` would be actively misleading — `updated_at` moves on every unrelated Settings save
(`CampaignController::update()` `:153` fills and saves the whole validated payload), so a campaign
listed in March and typo-fixed today would say "Active today". That is the failure mode to name.
`created_at` is worse (it predates listing entirely). Reported, not decided.

---

## I5 — The fan-out

### The rostered-creators-of-agency query (re-derived from code)

The agency→creators direction is `MessageableContactsFinder::creatorsForAgency()`
(`apps/api/app/Modules/Messaging/Services/MessageableContactsFinder.php:45-75`):

- `:50` — belt-and-suspenders `where('agency_creator_relations.agency_id', $agency->id)` on top of
  the global scope (the comment calls it "the roster-index precedent — never another agency's
  relations").
- `:52` — `->permitsMessaging()` (the shared relation leg: roster + non-blacklisted).
- `:55-63` — `whereHas('creator', …)` applying `application_status = 'approved'` (`:56`) plus the
  optional name search; `whereHas` also enforces a non-soft-deleted creator exists.
- `:64` — `->with(['creator:id,ulid,display_name,avatar_path'])`.
- `:67-72` — display-name ASC via correlated subquery with an id tiebreaker, then `paginate()`.

That is the "every creator this agency may currently reach" set, in a form already pinned by
`MessageableContactsAgreementTest`. It carries the search + pagination a picker needs, which a
fan-out does not; the reusable core is `:50` + `:52` + `:56`.

**Caveat worth flagging:** the method is named and documented for _messaging_
(`MessageableContactsFinder.php:17-33`). Reusing it verbatim for a job-notification fan-out couples
"who can be messaged" to "who gets job mail" — which is probably correct (both mean "an active,
approved roster relationship") but is a semantic claim the kickoff should make deliberately rather
than by import. The alternative is a second consumer of `scopePermitsMessaging()` in the campaigns
module, which is what the scope's docblock invites (`AgencyCreatorRelation.php:135-149`: it exists so
consumers "share ONE source of truth and cannot drift").

### The per-job-per-creator stamp — checked against the actual notification machinery

The c1+2 recommendation was `campaign_id + creator_id + notified_at` unique composite. Checked
against what exists:

- **`notifications` table.** `NotificationService::notify()`
  (`apps/api/app/Modules/Notifications/Services/NotificationService.php:41-60`) reads the
  recipient's in-app preference (`:48`) and creates a row (`:52-59`) with
  `recipient_user_id`, `actor_user_id`, `subject_type`/`subject_id` (polymorphic), `type`, `data`.
  **There is no uniqueness anywhere on it** — it is an append-only feed, by design. A "have we
  notified this creator about this job?" check could in principle be a `whereExists` on
  `(recipient_user_id, subject_type='campaign', subject_id, type)`, but that (a) makes the feed
  table a deduplication authority it was never built to be, and (b) breaks the moment the creator
  clears or the feed is pruned. **The notification machinery does not supply once-only semantics.**
- **`notification_preferences`.** `NotificationService::setPreference()` (`:79-100`) relies on a
  `(user_id, type, channel)` unique constraint for `updateOrCreate` race-safety (`:74-77`), which is
  the only unique-composite precedent in the module — a shape precedent, not a mechanism to reuse.
- **The house once-only mechanism is a stamp column, not a table.** AH-048:
  `creators.incomplete_nudge_sent_at`, additive-nullable, written via `updateQuietly` to bypass the
  Audited observer (`IncompleteCreatorNudgeService.php:146-152`), with the eligibility query
  excluding anyone already stamped (`:31-32`). AH-035 uses the same instinct with a boolean
  (`previously_declined`). But both stamp a **single-axis** fact on an existing row.
- **A per-job-per-creator stamp is two-axis** — it belongs to the (campaign, creator) pair, which is
  exactly the pair `campaign_assignments` already owns uniquely
  (`create_campaign_assignments_table.php:129`). ⚠ **This couples I5 to I2.** If §I2 lands on a
  table (Option A), the stamp is a natural nullable column on that table — one row per pair already
  exists or is created on apply, though note the stamp must exist _before_ the creator applies, so
  the row would have to be created at notify-time with no application on it, which muddies the
  table's meaning. If §I2 lands on a state (Option B), there is no row until the creator applies, so
  the stamp needs its own table regardless. **Neither option makes the stamp free**, and the c1+2
  recommendation (its own small table, `campaign_id + creator_id` unique, `notified_at`) survives
  contact with the code as the cleanest of the three — but the kickoff should note it is a _third_
  table in the arc, not a reuse.

### The localized-mailable pattern + queued locale

Uniform across all 19 mailables (`grep -rln "extends Mailable" apps/api/app` — 19 files). The
canonical shape, taking `IncompleteCreatorNudgeMail` and `AdminConnectedMail`:

- `final class X extends Mailable implements ShouldQueue`, `use Queueable`
  (`IncompleteCreatorNudgeMail.php:35-38`; `AdminConnectedMail.php:31-33`).
- `envelope()` returns a `trans()`/`__()` subject
  (`IncompleteCreatorNudgeMail.php:48-53`; `AdminConnectedMail.php:40-45`), optionally with `tags:`
  for stream separation (`IncompleteCreatorNudgeMail.php:51`, `['creators','onboarding-nudge']`,
  the GDPR-transactional posture recorded in the AH-048 log entry).
- `content()` returns `markdown: 'mail.<module>.<view>'` through the shared `catalyst` theme, with
  a `with:` array of render params (`IncompleteCreatorNudgeMail.php:56-66`;
  `AdminConnectedMail.php:48-57`).
- **Queued-locale is set at the call site, never inside the mailable:**
  `Mail::to($user->email)->locale($user->preferred_language ?: 'en')->queue(new …)` —
  `IncompleteCreatorNudgeService.php:122-131` and `:133-142`;
  `AdminCreatorConnectionController.php:412-421`; `SendAssignmentNotifications.php:97-103`,
  `:133-141`, `:189-197`, `:260-268`. The `?: 'en'` fallback is byte-identical at every site.
- Frontend URLs are built from `config('app.frontend_main_url')` with `rtrim`
  (`AdminConnectedMail.php:59-64`; `IncompleteCreatorNudgeService.php:168-171`).
- §5.3 requires a real-rendering test per locale plus the queued-locale assertion
  (`PROJECT-WORKFLOW.md:203-207`, `WORKING-PROCESS.md:130-132`).
- ⚠ **Standing deploy rule:** the long-running queue worker caches translations in memory, so any
  `lang/**` copy change requires a worker restart or the OLD body keeps sending
  (`RESUMPTION-TEMPLATE.md:177-182` — "not AH-051-specific; it applies to every mail-copy change the
  platform ever ships").

**The dual-emit shape** (in-app + mail to the same recipient, which is what "in-app + mail" means
here) is AH-051's `notifyDirectConnect()`
(`apps/api/app/Modules/Creators/Http/Controllers/Admin/AdminCreatorConnectionController.php:397-422`):
`$this->notifications->notify(...)` first (`:404-410`), then the guarded
`if ($user->email !== '')` queue (`:412-421`). The guard-independently discipline is stated at
`SendAssignmentNotifications.php:129-130` ("Guarded independently so a missing/empty inviter never
blocks the in-app fan-out below").

**The fan-out shape** is `SendAssignmentNotifications::notifyAgencyMembers()` (`:295-315`) over
`Agency::notifiableMembers()` (`apps/api/app/Modules/Agencies/Models/Agency.php:114-127`) — one
`notify()` per recipient in a plain `foreach`, with the docblock at `:285-294` noting the membership
query hits a non-`BelongsToAgency` pivot so it is safe in a queued listener with no `runAs`. **Note
the deliberate asymmetry it records:** N in-app rows, 1 email (D-6). Chunk 3's fan-out is the
opposite — N in-app **and** N emails — which is a different volume profile and the reason the cap
matters.

### AH-048 operational furniture — and whether this fan-out needs its own flag

The AH-048 furniture, verbatim:

- **Flag checked in the service, not the command**, so console and admin toggle agree:
  `IncompleteCreatorNudgeService::send()` `:92-96` (`Feature::active(...)` → early
  `IncompleteNudgeReport::disabled()`); the command's docblock states the split
  (`SendIncompleteCreatorNudges.php:15-19`).
- **`--dry-run` ignores the flag and mutates nothing** (`SendIncompleteCreatorNudges.php:42-53`,
  `IncompleteCreatorNudgeService::preview()` `:72-84`) and prints per-variant would-send counts.
- **`--limit=N` cap, default 50, oldest-first**, failing loudly on a non-numeric or ≤0 value
  (`SendIncompleteCreatorNudges.php:28-29`, `:74-92`; `DEFAULT_LIMIT` at
  `IncompleteCreatorNudgeService.php:43` with the "we are LIVE" rationale at `:36-42`). Only the
  capped set is stamped — no over-stamping (`:88-90`).
- **Flag registry:** `IncompleteCreatorNudgeEnabled` in
  `apps/api/app/Modules/Creators/Features/` (six flag classes total, all in that directory), listed
  in `AdminFeatureFlagController::FLAGS` (`:53`) and documented in `docs/feature-flags.md:50`,
  whose "Manual steps to enable" column records the ritual: dry-run → read counts → flip from the
  admin page (reason mandatory → `feature_flag.toggled` audit row).

**Argument, both directions, on whether chunk 3's fan-out needs its own Pennant flag:**

- _For "it ships dark anyway, no flag needed."_ At deploy, `listed_on_jobs_board` is `false` on
  every row — the AH-054 migration is `default(false)` with nothing backfilled
  (`2026_07_27_100000_...php:46`, and the docblock at `:38-39`: "Nothing reads
  `listed_on_jobs_board` in this chunk — it ships dark, default false, so no existing campaign
  becomes visible anywhere"). Create _cannot_ list (D4 — `listed_on_jobs_board` is absent from
  `CreateCampaignRequest`'s rules, and the store whitelist omits it,
  `CampaignController.php:103-111`). So the first mail can only fire after an agency deliberately
  flips a toggle on a floor-complete campaign. The population of possible sends at T+0 is provably
  zero. A flag adds a second switch in front of a switch.
- _Against — three concrete reasons the flag still earns its keep._ (1) **`WORKING-PROCESS.md:163-164`
  is unconditional**: "New emails/notifications to real users ship flag-gated (default OFF), with
  dry-run previews and per-run caps where volume is possible." Same in
  `PROJECT-WORKFLOW.md` §5.40's spirit and `RESUMPTION-TEMPLATE.md:483-485`. Shipping without one is
  a documented-standard exception that must be argued in the kickoff, not assumed.
  (2) **The kill switch is the point, not the initial state.** Dark-at-deploy protects T+0; a flag
  protects T+3 when an agency lists a campaign against a 900-creator roster and the copy turns out
  to be wrong. Nothing else in the design can stop mail once a toggle is flipped by a user we do not
  control. (3) **The flag is the natural home for the cap.** AH-048's cap lives with the flag in one
  service; a fan-out with a `--limit` but no flag has an operator surface with no off switch.
- _The dry-run question is separate and harder._ AH-048's dry-run works because the command is
  operator-invoked. If chunk 3's fan-out is **event-driven** (fires on toggle-flip), there is no
  command to `--dry-run` — the operator-preview affordance has no natural place. That is a real
  argument in favour of a command-shaped or hybrid design, and it connects directly to the next
  section.

### ⚠ WHERE the fan-out fires — and the scheduler blocker

**The blocker, stated explicitly.** `RESUMPTION-TEMPLATE.md:497-506`:

> **🚫 BLOCKER — scheduler existence UNVERIFIED.** Until `supervisorctl status` / `crontab -l` from
> prod confirms a scheduler, **assume NO scheduled command runs in production.**

Consequences already recorded there: AH-048's nudge enablement is blocked on it, and
`messages:send-digest` + `boards:scan-overdue` are "likewise assumed not firing". Three commands are
registered `->daily()` in `apps/api/bootstrap/app.php:34-49` (`SendMessageDigests` `:37`,
`ScanOverdueAssignments` `:42`, `SendIncompleteCreatorNudges` `:48`) and **none of them is known to
have ever run in production.** Unblocking is two commands on the prod box
(`RESUMPTION-TEMPLATE.md:504-506`).

**What this means for chunk 3, plainly: a scheduled-scan design is not shippable.** A fan-out that
depends on `schedule:run` would deploy into an environment where it provably may never execute, and
the failure is silent — the agency lists a job, no creator hears about it, and nothing errors.
That rules out the "nightly scan for newly-listed campaigns" shape unless the scheduler is verified
first as a prerequisite of this chunk.

**The fire-on-flip option — what wiring exists.** The campaign update path is _not_ event-driven
today. `CampaignController::update()` (`:142-165`) does `fill()->save()` (`:153`) and then
`Audit::log()` with before/after snapshots (`:155-160`) — a **hand-written audit, no event
dispatched**. There is no `CampaignUpdated` domain event; `AuditAction::CampaignUpdated` (`:229`) is
an audit verb only, and the `Campaign` model deliberately does not use the `Audited` trait
(`Campaign.php:29-31`). So "the audit-snapshot F3 wiring" is a _record_, not a _hook_ — nothing
listens to it.

The house event-consumer pattern that _does_ exist is §5.38
(`PROJECT-WORKFLOW.md:465-469`): a domain already emitting one rich event implementing a small
contract, consumed by a listener switching on `eventKey()`. `AssignmentTransitioned`
(`CampaignAssignmentStateMachine.php:627-634`) is that event for **assignments**. Campaigns have no
equivalent. A fire-on-flip design therefore needs either a net-new campaign event (and §5.38 argues
for one contract-shaped event rather than a per-change class) or a direct call from the controller —
and a direct call means mail dispatch inside an HTTP request that also holds the update, which the
codebase avoids everywhere (every mail is `->queue()`d).

**The three shapes, reported:**

| Shape                                                         | Fires                             | Scheduler dependency               | Dry-run affordance  | Cap enforcement                   |
| ------------------------------------------------------------- | --------------------------------- | ---------------------------------- | ------------------- | --------------------------------- |
| Event/listener on the false→true flip                         | Immediately, in-request or queued | **None**                           | No natural one      | Must live in the listener/service |
| Scheduled scan for newly-listed campaigns                     | Daily                             | **BLOCKED — assume it never runs** | `--dry-run` free    | `--limit` free (AH-048 shape)     |
| Hybrid: flip enqueues a job; a command can also drain/preview | Immediately                       | None for the primary path          | Command supplies it | Shared service, one cap           |

The hybrid is the only shape that gets AH-048's operator furniture without depending on the
scheduler. Reported as an observation about the constraint set, not a recommendation.

---

## I6 — Notification + audit vocabulary

### The one-vocabulary rule

`NotificationType`'s string values must be exact `AuditAction` values — stated at
`apps/api/app/Modules/Notifications/Enums/NotificationType.php:10-19` and **proved at runtime** by
`auditAction()` (`:100-103`), which is `AuditAction::from($this->value)` and throws on a mismatch.
`NotificationTypeEnumTest` is the catalogue tripwire
(`apps/api/tests/Feature/Modules/Notifications/NotificationTypeEnumTest.php`), paired with
`AuditActionEnumTest`. **So every new notification type costs an `AuditAction` case, even when no
audit row is ever written for it** — §5.37 says this explicitly
(`PROJECT-WORKFLOW.md:463`: "one `AuditAction` verb (the `NotificationType` one-vocabulary tie, even
when no audit row is written on the event)").

### §5.37's direction-split, applied

§5.37 (`PROJECT-WORKFLOW.md:459-463`) rules that a type notifying _either_ party depending on who
triggered it must be **two types**, one per recipient direction — because `LIVE_TYPES` carries one
static `recipient` per type, so a single type leaves the other party with a row and no toggle.
Live examples: `message.received_by_creator` / `_by_agency`
(`NotificationType.php:71-72`), `message.relationship_received_by_creator` / `_by_agency` (`:79-80`).

The AH-051 **counter-ruling** matters just as much and is in the same enum: `RelationDisconnected`
is deliberately **one** type for both parties (`NotificationType.php:86-89`) — "directional splits
earn their keep at messaging frequency, not rare-admin-action frequency (D-7 ruling)" — and the FE
registry expresses it as `recipient: 'both'` with a direction-agnostic `counterparty_name`
(`templates.ts:184-188`, pinned at `templates.spec.ts:135-142`).

Applied to chunk 3's two candidate verbs:

- **`job_posted` → creator.** Single-direction by construction (only creators receive it; the agency
  is the actor). §5.37's split does not apply. Needs one `AuditAction` case + one `NotificationType`
  case + one `LIVE_TYPES` entry with `recipient: 'creator'`.
- **`application_submitted` → agency.** Also single-direction (only agency users receive it). But
  ⚠ **chunk 4 consumes it, and chunk 3 emits it** — which is precisely the §5.11 cross-chunk handoff
  contract case (`PROJECT-WORKFLOW.md:265-277`): the consuming chunk's read pass must verify the
  full shape, and here the _providing_ chunk is deciding the vocabulary. If chunk 3 ships the emit
  site, the registry entry must ship with it or AH-051's exact failure repeats (live emit site, no
  registry row, generic "You have a new notification.", green suite —
  `templates.ts:26-32`). If chunk 3 does **not** emit it, the verb belongs in chunk 4 and must not be
  added to the enum early — an unregistered enum case would land in the `DEFERRED_WITHOUT_EMITTER`
  allowlist (`templates.spec.ts:35-43`), which the spec's own comment calls "an ALLOWLIST, not a
  dumping ground". **The kickoff should say which chunk owns `application_submitted`.**

Naming note: `AuditAction` values are `<subject>.<verb>` lowercase-dotted
(`AuditAction.php:23-24`), so the shapes would be along the lines of `campaign.job_posted` /
`campaign.application_submitted` (or an `application.*` subject if §I2 lands on a table — the enum
already has subject nouns keyed to tables, e.g. `talent_pool.*`, `brand_creator_blacklist.*`). The
subject noun follows §I2's answer.

### Does the creator prefs page auto-include new types?

**Yes — derived, not hand-listed, as long as the registry row is added.**
`preferenceGroupsForRole()` (`apps/main/src/modules/notifications/templates.ts:238-263`) iterates
`LIVE_TYPES` (`:98`), skips `preference: null` always-on types (`:248-250`), skips types whose
`recipient` is neither the role nor `'both'` (`:251-253`), and buckets by group, ordered by
`PREFERENCE_GROUP_ORDER` (`:225-229`, today `['assignment','creator','messaging']`). The page
(`apps/main/src/modules/notifications/pages/NotificationPreferencesPage.vue`) consumes that, and
`recipientRoleForUserType()` (`:208-210`) maps `user_type === 'creator'` → `'creator'`.

So a new creator-facing type appears on the creator prefs page **automatically** once it has a
`LIVE_TYPES` entry with `recipient: 'creator'` and a non-null `preference`. What is **not**
automatic:

- The **registry row itself** — `templates.ts:26-32` records that AH-051 shipped two types with real
  emit sites and no rows.
- The **group**. `NotificationPreferenceGroup` is a closed union of three values
  (`templates.ts:57`) and `PREFERENCE_GROUP_ORDER` (`:225-229`) is a hand-ordered list. A jobs group
  would be a fourth value in both. Reusing `'assignment'` avoids that but files job posts under a
  label that today means campaign-assignment lifecycle.
- The **channels**. `NotificationPreferenceDefinition.channels` must only list channels a consumer
  actually delivers (`:60-63`: "never ship a toggle that gates nothing (dead control)"). Today
  `IN_APP_ONLY` (`:92`) is the common case and only messaging exposes `digest`
  (`:150`, `:155`). ⚠ **`email` is a declared channel** (`NotificationChannel` union at
  `packages/api-client/src/types/notification.ts:157` = `'in_app' | 'email' | 'digest'`) that **no
  live type currently exposes as a toggle** — every mailable is dispatched directly via `Mail::…
->queue()`, bypassing `NotificationService` entirely (its docblock says so:
  `NotificationService.php:18-22`, "IN-APP ONLY this chunk: it does NOT touch email"). Chunk 3's
  job-posted mail therefore has **no opt-out toggle available under the current architecture**
  unless the chunk also wires the email channel through the preference read — which would be the
  first time. For a fan-out to live creators, "can they turn it off?" is a question the kickoff has
  to answer, and the honest current answer is "not through the prefs page".

### The `templates.spec.ts` tripwire from the AH-051 fixes

`apps/main/src/modules/notifications/templates.spec.ts` is the derived guard. It reads the **backend
enum from source** (`:49-58`, resolving
`apps/api/app/Modules/Notifications/Enums/NotificationType.php` and regexing `case … = '…'`) and
asserts:

1. the enum parse is non-vacuous (`:76-80`);
2. **every backend type is either live or explicitly deferred** (`:82-91`) — this is the assertion
   that reds on a new enum case with no registry entry;
3. no phantom registrations (`:93-98`);
4. every deferred verb really is absent from the registry (`:100-105`) — so a verb going live must
   _leave_ `DEFERRED_WITHOUT_EMITTER`;
5. every live template key resolves to a real non-empty translation in `en/notifications.json`
   (`:107-116`);
6. no live type silently resolves to the fallback (`:118-124`).

The companion is `apps/main/tests/unit/architecture/i18n-notifications-parity.spec.ts`, which pins
**locale parity** for the same set across `UI_LOCALES` (`:38-40`) and carries a hand-restated
`LIVE_TYPES` list (`:58-`) whose own docblock (`:41-57`) admits the restatement went stale during
AH-051 and explains the division of labour: "this one catches a missing TRANSLATION, that one
catches a missing REGISTRATION". **Both must be updated for a new type** — the derived spec reds
automatically; the parity spec's hand-list must be edited or it will not ask about the new key.

Current template surface: 14 live types in `notifications.types.*`
(`apps/main/src/core/i18n/locales/en/notifications.json`) plus `fallback`; the whole
`notifications.json` bundle is 51 leaves.

---

## I7 — Ripple

### Creator SPA spec files that gain coverage

Existing creator-side unit specs (all Vitest, colocated):
`apps/main/src/modules/creators/layouts/CreatorDashboardLayout.spec.ts`,
`pages/CreatorDashboardPage.spec.ts`, `pages/CreatorAssignmentsPage.spec.ts`,
`pages/CreatorAssignmentDetailPage.spec.ts`, `pages/CreatorProfilePage.spec.ts`,
`assignments.api.spec.ts`, `connectionRequests.api.spec.ts`, plus seven under
`creators/availability/`.

Directly implicated by chunk 3:

- **`CreatorDashboardLayout.spec.ts`** — the nav array grows; if the new item is state-conditional
  it needs the AH-009 Profile-item treatment (shown/hidden per `applicationStatus`), and both the
  desktop `creator-nav-<key>` and mobile `creator-bottom-nav-<key>` renderings assert off the same
  array.
- **`CreatorDashboardPage.spec.ts`** — only if a teaser ships; it would follow the
  `dashboard-assignments-teaser` precedent (count + CTA, approved-only, no fetch otherwise).
- **New:** a board list page spec, a job detail page spec, an apply-action spec, and a
  `jobs.api.spec.ts` (the module-scoped API file is mandatory per §5.13,
  `PROJECT-WORKFLOW.md:287-291` — one `<module>.api.ts` exporting a single named object, no
  cross-module API calls).
- **`templates.spec.ts`** reds automatically on a new `NotificationType` case; the
  `i18n-notifications-parity.spec.ts` hand-list must be edited.
- **Unpinned today, worth noting:** no architecture spec asserts anything about `layout: 'creator'`
  routes (§I1). The agency equivalent exists
  (`agency-routes-agency-user-guard.spec.ts`, `agency-routes-mfa-guard.spec.ts`).

### api-client type surface

`packages/api-client/src/types/` has nine modules re-exported by `index.ts:1-9`
(`agency, auth, availability, board, campaign, creator, messaging, notification, user`). The
creator-assignment types already live in **`campaign.ts`**, not `creator.ts`
(`CreatorAssignmentResource` `:347`, `CreatorAssignmentListResponse` `:373`,
`CreatorAssignmentActionResponse` `:378`, `CreatorAssignmentDetailResource` `:454`) — so a jobs-board
resource type has a precedent for living in `campaign.ts` alongside the AH-054 listing fields already
there (`CampaignAttributes.listing_*` at `:89-96`, `CreateCampaignPayload.listing_*` at `:183-187`).
Whether chunk 3 adds a tenth module (`jobs.ts`) or extends `campaign.ts` is a kickoff call; both
patterns exist.

New type surface, minimally: a job-card resource, a job-detail resource, an apply payload
(`{ note?: string }`), an apply response, and — if §I2 lands on a state — an extension of the
`AssignmentStatus` union at `campaign.ts:28`, which is a **breaking-shaped change to a shipped
union** consumed across both SPAs.

### i18n scope estimate

Bundle sizes measured at HEAD (leaf-string count, `en`):
`app.json` 733, `creator.json` 365 (of which `creator.ui` is 354), `availability.json` 54,
`notifications.json` 51, `dashboard.json` 21. 24 locale directories
(`apps/main/src/core/i18n/locales/`: bg cs da de el en es et fi fr ga hr hu it lt lv mt nl pl pt ro
sk sl sv).

Where chunk-3 keys land:

- **`creator.json`** (under `creator.ui.*`) — the board list, the empty state, the card labels, the
  details page, the apply dialog + its optional note, the applied/already-applied states, error
  copy. This is the bulk.
- **`availability.json`** — ⚠ **the nav label**, because `creatorNav` lives there
  (`availability.json:11-17`), not in `creator.json`. Easy to miss; worth one line in the kickoff.
- **`notifications.json`** — one `notifications.types.<key>` template per new notification type
  (plus, if a fourth prefs group is introduced, its group label under `notifications.preferences.*`).
- **`apps/api/lang/*/`** — the mailable subject + body strings. Seven backend bundles exist
  (`app.php`, `auth.php`, `campaigns.php`, `creators.php`, `invitations.php`, `messages.php`,
  `mock-vendor.php`) × 24 locale dirs. AH-048 put its nudge strings in `creators.php`; a job-posted
  mail to a creator would most naturally join `campaigns.php` or `creators.php` — pick one and say
  so, since the mailable's `trans()` key prefix follows.

**A rough order-of-magnitude, stated as an estimate not a count:** the AH-053/054 pass shipped
**38 keys per locale** for two agency-side forms plus one relabel
(`jobs-board-brand-amends-review.md:278`). Chunk 3 is a list + a detail page + an apply flow + a
notification template + a mailable — comfortably larger. **40–70 SPA keys per locale × 24, plus
roughly 5–12 backend `lang/**` keys × 24\*\* is the honest bracket; the real number is only knowable
once the copy is written.

⚠ **The flaky-10 ruling applies in full.** `WORKING-PROCESS.md:135-138` and the AH-046/047 ruling at
`RESUMPTION-TEMPLATE.md:441-445`: new creator-facing copy gets a **real MT baseline in all 24
locales at merge time**, including `bg, el, et, fi, ga, hu, lt, lv, mt, ro`; "match the surrounding
English" is explicitly rejected. This chunk is _entirely_ new creator-facing copy, so the ruling
binds every key. Context on why it bites here: AH-053 measured the flaky-10 locales at **759–787 of
1351 `app.json` leaves byte-identical to English (~57%), ~320 of them multi-word sentences**, vs
26–68 in the other thirteen (`RESUMPTION-TEMPLATE.md:557-562`). The surroundings this copy lands in
are more than half untranslated, which is exactly the debt the ruling exists to stop inheriting.

Parity is enforced by `apps/main/tests/unit/architecture/i18n-locale-parity.spec.ts` and, for
notifications specifically, `i18n-notifications-parity.spec.ts` — both key/placeholder/plural-shape
only. `RESUMPTION-TEMPLATE.md:61-65` states the blind spot plainly: parity "can **never** prove a
value isn't still English".

### Playwright exposure

14 specs in `apps/main/playwright/specs/`. Creator-traversing ones:
`creator-dashboard.spec.ts` (86 lines; its one test signs in and `page.goto('/creator/dashboard')` at
`:74`, asserting the incomplete banner), `creator-wizard-happy-path.spec.ts`,
`creator-connection-requests.spec.ts`. Nothing currently traverses the creator **nav** as a
navigation surface, and no spec exercises `/creator/assignments`.

- **Nav/dashboard risk:** adding a nav item does not break the existing creator specs (none asserts
  the nav's shape), but a dashboard teaser inserts a block into a page one spec renders.
- **Whether a job-board E2E leg belongs to chunk 3 or chunk 5:** the arc's full loop is
  list → apply → agency sees → agency accepts → assignment exists, and chunks 4 and 5 own the back
  half. A chunk-3-only leg can honestly assert _browse and apply_, which is a real surface with real
  provisioning needs (a rostered approved creator + a listed floor-complete campaign + a brand with
  a logo). The counter-argument is the recorded tech-debt position at
  `RESUMPTION-TEMPLATE.md:595-599`: the resolution on the E2E-coverage-gap entry was updated to
  "recommend a dedicated assignment-lifecycle Playwright pass rather than further one-off specs per
  chunk". Reported both ways; the kickoff decides.
- **Provisioning:** §5.12 (`PROJECT-WORKFLOW.md:279-285`) requires a single-call test-helper.
  Existing helpers include `CreateRosterCreatorsController` (which stamps
  `application_status => Approved`, `:75`) and `CreatePendingConnectionRequestController` (`:78`) —
  a roster+listed-campaign helper would extend that family, and §5.26
  (`PROJECT-WORKFLOW.md:397-399`) is the precedent for adding a flag to an existing helper rather
  than a new endpoint.
- **Standing operational rules:** Playwright needs the dev stack down (port-8000 guard) and its own
  E2E DB (`WORKING-PROCESS.md:126-129`, `:248-249`); E2E output must go to `playwright-report/`, not
  `/tmp` (`RESUMPTION-TEMPLATE.md:66-71`); the E2E media disk is a local-driver stand-in
  (`MEDIA_DISK_DRIVER=local`) — relevant the moment a brand logo has to render in a spec
  (`RESUMPTION-TEMPLATE.md:567-569`, and the `/e2e-media` route-shadowing trap recorded at
  `jobs-board-brand-amends-review.md:243-248`).

### `tenancy.md` §4 surface

`docs/security/tenancy.md` §4 begins at `:89` ("Cross-tenant routes — the explicit allowlist"). The
`creators/me/*` rows run from `:120` (`GET /api/v1/creators/me`) through `:169`
(`…/assignments/{assignment}/decline`), each with a Verb column, a justification, and a
sprint/chunk provenance column. The three assignment rows (`:167-169`) are the closest template:
they state creator-scoped ownership, the cross-module read of a Campaigns model, the deliberate
`BelongsToAgencyScope` drop with its rationale, and the middleware group
(`auth:web` + `tenancy.set` + `verified`).

Every new `/creators/me/*` route in chunk 3 needs a row. §5.21
(`PROJECT-WORKFLOW.md:365-367`) additionally requires the **category** to be named explicitly in the
justification (cross-tenant admin tooling / tenant-less / path-scoped tenant) — the structural
`Category` column is still open tech-debt. AH-034's precedent is that the tenancy.md update lands in
the **closure docs commit** (`RESUMPTION-TEMPLATE.md:305`: "`tenancy.md §4` updated in the closure
commit"), and AH-053 touched it too (AH log `:162`).

Route-group note: the existing `creators/me` group is declared once at
`apps/api/app/Modules/Creators/Routes/api.php:79-82` with the header comment block at `:25-76`
listing every allowlisted path — **that comment block is a second place the route list is
duplicated**, and it is maintained by hand.

---

## The three forks, restated for the kickoff

1. **I2 — table vs. state.** The four axes disagree. Uniqueness and the no-re-apply rule favour a
   separate table; chunk 4's accept path favours a machine state; board-card queries are a cost wash
   but the state option silently changes the meaning of the already-shipped `assignment_count`
   (`CampaignResource.php:64`). The sharpest concrete finding: with a pre-`invited` state, the live
   idempotency branch in `CampaignAssignmentController::store()` (`:153-179`) would swallow an agency
   invite to a creator who had already applied, returning the existing row and persisting no offer.

2. **I5 — fire-on-flip vs. scheduled scan.** Not a free choice. The scheduler is unverified in
   production (`RESUMPTION-TEMPLATE.md:497-506`) and three existing `->daily()` commands
   (`bootstrap/app.php:37`, `:42`, `:48`) are assumed never to have fired. A scheduled scan would
   deploy into an environment where it may never run, failing silently. Fire-on-flip has no
   scheduler dependency but no natural `--dry-run` affordance and no existing campaign-domain event
   to bind to (`CampaignController::update()` writes an audit row and dispatches nothing, `:142-165`).

3. **I3 — brand data to creators.** The first AH-005-class crossing in the arc. Today a creator sees
   exactly one brand field — `brand_name`, hand-copied at `CreatorAssignmentController.php:205` — on
   assignments they are already party to. `BrandResource` (`:33-63`) emits the full brand including
   `logo_url`, `slug`, `industry`, `website_url`, `brand_safety_rules`, `client_portal_enabled`, and
   has only ever been served behind the brand policy. The agency logo already crosses
   (`MessageableContactsFinder.php:101`); the brand logo has not. The kickoff must name the
   creator-visible subset and whether it is a narrow dedicated resource (the
   `CreatorDiscoveryResource` precedent) or a §5.22-style conditional block on `BrandResource`.

**One additional flag not in the question set, surfaced by the pass:** the notification
**email channel has no preference toggle anywhere** — `NotificationService` is in-app only by design
(`:18-22`), every mailable is dispatched directly, and no `LIVE_TYPES` entry exposes `'email'`
(`templates.ts:98-189`). Chunk 3 is the arc's first mail fan-out to live creators, so "can a creator
turn job-posted email off?" currently has no mechanism behind it.
