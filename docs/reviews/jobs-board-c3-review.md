# Jobs Board arc, chunk 3 — creator job board + apply (AH-056)

- **Status:** **Closed — approved.** Six commits: `81df0b5`, `928ccce`, `0cf6275`, `4e527e7`,
  `d37d43c`, `ddf875c` (the last commit carrying code), plus this docs commit.
- **Verdict:** independent review complete: D1–D10 verified and the C2–C5 dispositions accepted; all
  six break-reverts **re-executed at final HEAD and confirmed**, including the **leg-6 load-bearing
  collateral** — removing the brand-hard-blacklist leg also reds the hard-versus-soft discrimination
  test, which is what distinguishes an enforced leg from one that merely happens to agree with its
  neighbours; the seven-case §5.34 set green on all four surfaces (list, detail, apply, recipient
  query); both exact-keyset pins green, so the D3 brand subset cannot grow by accretion; the
  **flag-registry gap accepted as found-and-fixed**, covered by the HTTP arm/disarm test — the flag's
  default, service check, break-revert and enable ritual were all green while the operator control
  did not exist, and writing the runbook row is what caught it; production posture verified
  **LOW-MEDIUM** with the three containments (default-OFF flag, the 50-per-run cap, rostered-only
  recipients) and the accepted queue-then-stamp silent miss named; full Playwright 24/24 including
  the new browse-detail-apply leg.
- **Date:** 2026-07-27
- **Provenance:** drafted by Cursor, reviewed and closed by Claude.
- **Ratified plan:** [`docs/reviews/jobs-board-c3-plan.md`](jobs-board-c3-plan.md).
  **Inventory:** [`docs/reviews/jobs-board-c3-inventory.md`](jobs-board-c3-inventory.md).
  **Binds to:** [`docs/reviews/jobs-board-brand-amends-review.md`](jobs-board-brand-amends-review.md)
  (the closed chunk-1+2 review — the listing floor, `scopeListedOnJobsBoard`, and the brand
  `logo_url` emission this chunk crosses to a new audience).

---

## What shipped

The creator half of the jobs board. A rostered, approved creator now sees the listed campaigns of
every agency they work with, opens one, and applies with one tap plus an optional note. When an
agency lists a campaign, the creators who can see it are told — in-app and by email, behind a
default-OFF flag and a per-run cap.

Chunk 4 owns the agency side: the applications column, the accept path, and the
`application_submitted` notification the agency will receive. No enum case for that ships here.

### Commit split, and why

Six commits, split by **surface**, not by sub-step:

| Commit    | Contents                                                                                                                            |
| --------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| `81df0b5` | S1–S4 — schema, the predicate object, board list, job detail, apply.                                                                |
| `928ccce` | S5–S6 — the `campaign.job_posted` vocabulary pair, the flag, the fan-out service/job/mailable, ×24 backend lang, the flip detector. |
| `0cf6275` | S7 — the operator command.                                                                                                          |
| `4e527e7` | S8–S9 — api-client types, routes, nav, pages, apply dialog, ×24 SPA i18n, unit specs, the creator-routes architecture spec.         |
| `d37d43c` | S10 — the Playwright leg and its `_test` helper.                                                                                    |
| `ddf875c` | The admin flag-registry gap found in the S11 doc pass (below).                                                                      |

The read/apply backend is one reviewable unit; the outbound fan-out is a different risk profile
(it sends mail) and deserves to be readable on its own; the operator command is small and
self-contained; the SPA is a separate audience. Splitting per sub-step would have produced eleven
commits, several of which do not compile alone (a route without its controller, a page without its
api file). Splitting into two would have put the mail fan-out and the read endpoints in one diff,
which is exactly the pairing a reviewer wants separated.

---

## Per-decision evidence

### D1 — Applications are a TABLE

`campaign_applications`: `ulid`, `agency_id`, `campaign_id`, `creator_id`, `status`, `note`,
`responded_at`, timestamps, `unique(campaign_id, creator_id)`, index `(agency_id, status)`.
Migration `2026_07_27_110000_create_campaign_applications_table.php`.

The lifecycle is `CampaignApplicationStatus`: `pending → accepted | rejected`, both terminal, no
edges out. `CampaignApplicationSchemaTest` (12 tests) pins the unique pair, the ULID, the cast, the
`agency_id` denormalization, the tenancy scoping, and that both outcomes are terminal.

No-re-apply is implemented as the **retained terminal row** — the rejected application keeps
occupying the unique pair, so a second apply cannot insert. Proven in
`CreatorJobApplyTest`: a rejected applicant gets `409 job.application_rejected` forever, and the row
count stays at one.

### D2 — The visibility predicate (+ C5's fifth leg)

One object: `App\Modules\Campaigns\Services\JobsBoardVisibility`. Six legs, documented in its
docblock, composed by **all four** surfaces — list, detail, apply, and the fan-out's recipient
query. Nothing re-derives it.

| Leg | What it is                                                                                                                                   |
| --- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | `withoutGlobalScope(BelongsToAgencyScope::class)` — the caller is a creator across many agencies. `SoftDeletes` is deliberately NOT dropped. |
| 2   | Approved creator, else `whereRaw('1 = 0')` — an empty board, never an error.                                                                 |
| 3   | `AgencyCreatorRelation::scopePermitsMessaging()` — the shared roster + blacklist leg, read as agency ids.                                    |
| 4   | `Campaign::scopeListedOnJobsBoard()` — unchanged from AH-054.                                                                                |
| 5   | `ends_at IS NULL OR ends_at >= <UTC start of today>` (Q4).                                                                                   |
| 6   | NOT brand-HARD-blacklisted (C5).                                                                                                             |

### D3 — Brand-to-creator subset

`CreatorJobCardResource` emits `brand.name` + `brand.logo_url`. `CreatorJobDetailResource` extends
it and adds `brand.website_url`. `BrandResource` is never served to a creator. The controller does
not even LOAD the extra columns on the card path (`->with(['brand:id,name,logo_path'])`), which is
a belt on top of the resource's narrow keyset.

Archived brands: `Campaign::brand()` is `withTrashed()`, so a listed campaign keeps rendering its
brand as stored. Listing state alone decides visibility. Pinned by a test that archives the brand
and asserts the card still renders it.

### D4 — Card + detail content, and `listed_at`

Card: brand logo + name, campaign name, listed fee, duration, applicant count, recency chip.
Detail adds description, languages, regions, examples link, brand website, Apply.

`applicant_count` is `withCount('applications')` — every status counts. It is the first
creator-visible aggregate over other creators' behaviour on this platform: a bare integer, non-
identifying, and deliberate.

`listed_at` is written **only** on the false → true flip, in `CampaignController::update()`, and
is display metadata only. The read scope never consults it — see the break-revert below.

### D5 — Apply

One tap, optional note (≤1000 chars, whitespace-only normalised to null in `ApplyToJobRequest`).
The endpoint re-validates the full predicate, refuses duplicates with two codes, emits a
`campaign_application.submitted` audit row (the note is deliberately excluded — the row records
the fact and the two ids), and relies on the unique index for the race.

No `application_submitted` NotificationType ships. Chunk 4 owns it.

### D6/D7 — Fan-out

`job_posted_notifications_enabled` (default OFF), registered in `CreatorsServiceProvider` alongside
the existing flags (Q5 — one registry). The flag check lives INSIDE
`JobPostedFanOutService::send()`, so the console path and the queued path cannot disagree.

The flip detector is the before/after snapshot pair already in `CampaignController::update()` (C2):
`$wasListed === false && $isListing === true` stamps `listed_at` and dispatches
`SendJobPostedNotificationsJob` after the save. No new event, no scheduler.

Recipient set = "reachable roster": `permitsMessaging()` relation ∩ approved creator ∩ NOT
brand-hard-blacklisted ∩ has a user row ∩ not already stamped. Ordered oldest-roster-first, capped
at 50. The stamp (`campaign_job_notifications`, unique `(campaign_id, creator_id)`) is written after
both emissions, per recipient — see the Production posture for the trade that ordering makes.

**One gap, found in the S11 doc pass and fixed.** D6 asked for the flag to be "registered in the
admin flags page + `feature-flags.md`". Writing the `feature-flags.md` row is what surfaced that the
first half had not been done: `JobPostedNotificationsEnabled` existed and worked, but was absent from
`AdminFeatureFlagController::FLAGS` — the allowlist the admin SPA reads AND the allowlist `toggle()`
validates against. The kill switch was therefore reachable only from tinker, which is not a kill
switch. Registered, plus a test that an admin can arm **and disarm** it through the HTTP path, and
the existing registry-listing assertion extended. Worth naming rather than quietly fixing: the flag
had a default, a service check, break-revert evidence and a documented enable ritual, and every one
of those passed while the operator control did not exist. Writing the runbook row is what caught it.

### D8 — Vocabulary + prefs

One new pair: `AuditAction::CampaignJobPosted` and `NotificationType::CampaignJobPosted`, both
`campaign.job_posted`, single-direction → creator. `LIVE_TYPES` gains `recipient: 'creator'`,
group `assignment` (reused; the label tension is accepted), `IN_APP_ONLY`. Both enum catalogue
tests updated; `i18n-notifications-parity.spec.ts`'s hand-list edited to 15 types; the creator
preferences page now renders 9 types / 10 toggles, and its spec says so.

### D9 — Creator SPA

Nav item "Job Posts" in the single `navItems` array (desktop + mobile bottom bar off the same
array), conditional on `applicationStatus === 'approved'`. Routes `/creator/jobs` and
`/creator/jobs/:ulid`, both `layout: 'creator'`, `guards: ['requireAuth']` — the server carries the
approved leg. No dashboard teaser (deferred to chunk 5).

The in-scope hardening shipped: `creator-routes-guard.spec.ts`, the sibling the agency shell has
had since Sprint 6.

### D10 — E2E

`creator-jobs-board.spec.ts`: sign up → verify → seed a listed job → sign in → nav item → board →
card contents (brand, fee, applicant count, recency) → detail (description, brand website) → apply
with a note → applied notice + the applicant count moving to 1 → the board's Applied chip → a
direct POST past the UI earning `409 job.already_applied`.

---

## Kickoff dispositions

### C2 — the flip detector, reinterpreted

The plan proposed a new campaign event. The ruling: the before/after audit-snapshot pair already in
`CampaignController::update()` IS the flip detector.

**Intent line:** the intent was "the fan-out fires exactly once, when an agency lists a campaign,
and never on a scheduler". The mechanism that satisfies it is whatever already sees the transition
on the single write path. `CampaignController::update()` is that path — verified by grep: it is the
only place `listed_on_jobs_board` is written outside migrations, factories and tests. A new event
would have added an emitter, a listener, and a second thing to keep in sync, in exchange for
nothing the existing snapshot pair does not already give.

### C3 — the creator-routes architecture spec

Shipped as ruled: parses `modules/creators/routes.ts` for the positive (every `layout: 'creator'`
route declares `requireAuth`) AND `modules/auth/routes.ts` for the negative (no creator-shell route
declared outside the creators module), with a non-vacuity guard under both. Both assertions have a
break-revert below.

### C4 — the audit noun, reinterpreted

D1 said `application.*`; the ruling is `campaign_application.*`.

**Intent line:** the intent was "the audit row names the thing it describes". The house convention
is `<subject-keyed-to-table>.<verb>`, and the table is `campaign_applications`. `application.*`
would also have collided conceptually with `ApplicationStatus` (the creator's ONBOARDING
application), which is a different noun entirely on this platform. Shipped as
`AuditAction::CampaignApplicationSubmitted => 'campaign_application.submitted'`.

### C5 — the brand-blacklist leg

Added to the predicate and the recipient set, HARD only, as leg 6. The board must never solicit an
application the invite gate would hard-block.

**The two postures, side by side, both deliberate:**

| Level                                            | What is excluded  | Why                                                                                                                                            |
| ------------------------------------------------ | ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Relation (agency-wide), via `permitsMessaging()` | hard **and** soft | Stricter than the discover feed on purpose: an agency that has soft-blacklisted a creator should not be soliciting applications from them.     |
| Brand-scoped, leg 6                              | **hard only**     | Mirrors `AssignmentInviteGate` exactly. Soft is warn-at-invite semantics — a caution to the agency, not a reason to hide a job from a creator. |

A test pins both directions: a SOFT brand blacklist leaves the job visible; a HARD one hides it on
all four surfaces.

---

## Break-reverts (verbatim, with restore checks)

Every mutation below was applied to the working tree, the named suite run, the mutation reverted,
and the suite re-run green. The final restore check is at the bottom.

### 1 — Leg 2, the approved-creator gate

```php
// JobsBoardVisibility::visibleTo()
-        if ($creator->application_status !== ApplicationStatus::Approved) {
+        if (false) {
             return $query->whereRaw('1 = 0');
         }
```

```
=== BREAK-REVERT 1: leg 2 (approved-creator gate) removed ===
  ⨯ it LEG 2 — hides everything from a creator who is not approved with…
  ⨯ it LEG 2 — hides everything from a creator who is not approved with…
  ⨯ it LEG 2 — hides everything from a creator who is not approved with…
  ⨯ it LEG 2 — 404s the detail for a creator who is not approved with (…
  ⨯ it LEG 2 — 404s the detail for a creator who is not approved with (…
  ⨯ it LEG 2 — 404s the detail for a creator who is not approved with (…
  ⨯ it LEG 2 — refuses an apply from a creator who is not approved with…
  ⨯ it LEG 2 — refuses an apply from a creator who is not approved with…
  ⨯ it LEG 2 — refuses an apply from a creator who is not approved with…
  ⨯ it only ever notifies creators who can actually SEE the job
  Tests:    10 failed, 116 passed (373 assertions)
```

Ten reds across all four surfaces (three statuses × three read/write surfaces, plus the
two-directions-agree pin). Reverted.

### 2 — Leg 3, the roster leg

```php
// JobsBoardVisibility::visibleTo()
         $agencyIds = AgencyCreatorRelation::query()
             ->withoutGlobalScope(BelongsToAgencyScope::class)
             ->where('creator_id', $creator->id)
-            ->permitsMessaging()
             ->pluck('agency_id')
             ->all();
```

```
=== BREAK-REVERT 2: leg 3 (permitsMessaging roster leg) removed ===
  ⨯ it LEG 3b — hides a job once the relation is no longer roster with…   (×5 statuses)
  ⨯ it hides a job when the AGENCY-wide relation blacklist is set — har…  (×2, hard + soft)
  ⨯ it LEG 3b — 404s the detail once the relation is no longer roster w…  (×5)
  ⨯ it LEG 3b — refuses an apply once the relation is no longer roster…   (×5)
  ⨯ it refuses a job that was visible at render and stopped qualifying…
  ⨯ it only ever notifies creators who can actually SEE the job
  Tests:    19 failed, 107 passed (374 assertions)
```

Nineteen reds — every non-roster relationship status on every surface, both agency-wide blacklist
tiers, the apply-time re-validation case, and the recipient-set pin. Reverted.

### 3 — Leg 6, the brand HARD-blacklist (C5)

```php
// JobsBoardVisibility::visibleTo()
-        // Leg 6.
-        $query->whereNotExists(function (QueryBuilder $sub) use ($creator): void {
-            $sub->from('brand_creator_blacklists')
-                ->whereColumn('brand_creator_blacklists.brand_id', 'campaigns.brand_id')
-                ->where('brand_creator_blacklists.creator_id', $creator->id)
-                ->where('brand_creator_blacklists.blacklist_type', BlacklistType::Hard->value)
-                ->whereNull('brand_creator_blacklists.deleted_at')
-                ->selectRaw('1');
-        });
-
         return $query;
```

```
=== BREAK-REVERT 3: leg 6 (brand HARD-blacklist, C5) removed ===
  ⨯ it LEG 6 — hides a job whose BRAND has hard-blacklisted the creator
  ⨯ it keeps a job visible when the brand blacklist is SOFT, or hard-bu…
  ⨯ it LEG 6 — 404s the detail when the brand has hard-blacklisted the…
  ⨯ it LEG 6 — refuses an apply when the brand has hard-blacklisted the…
  ⨯ it refuses a job that was visible at render and stopped qualifying…
  ⨯ it only ever notifies creators who can actually SEE the job
  Tests:    6 failed, 120 passed (377 assertions)
```

Six reds. The second is the interesting one: the hard/soft discrimination test reds too, because it
asserts BOTH that soft stays visible and that hard does not — removing the leg breaks the second
half. The leg is load-bearing, as ruled. Reverted.

### 4 — The flag-OFF no-op

```php
// JobPostedFanOutService::send()
-        if (! Feature::active(JobPostedNotificationsEnabled::NAME)) {
+        if (false) {
             return JobPostedFanOutReport::disabled($this->pendingCount($campaign));
         }
```

```
--- mutation: the Pennant flag gate in JobPostedFanOutService::send() forced open ---
  ⨯ it is a complete no-op while the flag is OFF
  ⨯ it is an explicit no-op — not an error — while the flag is OFF
  Tests:    2 failed, 46 passed (162 assertions)
```

Two reds, one per entry point — the queued fan-out and the operator command — which is the point of
putting the flag check INSIDE the service rather than at each caller: neither path can be flipped on
independently. Run against the fan-out file alone the figure is `1 failed, 34 passed (101
assertions)`. Reverted.

### 5 — `listed_at` non-consultation

```php
// Campaign::scopeListedOnJobsBoard()
         return $query
             ->where('listed_on_jobs_board', true)
+            ->whereNotNull('listed_at')
             ->whereIn('status', self::LISTABLE_STATUSES);
```

```
--- mutation: scopeListedOnJobsBoard() gains a whereNotNull('listed_at') leg ---
  ⨯ it emits listed_at for the recency chip and renders happily without…
  ⨯ it NEVER lets listed_at decide visibility — the scope is the sole a…
  Tests:    2 failed, 22 passed (62 assertions)
```

Both reds prove the assertion is real: a listed campaign with a null stamp must stay visible, and a
delisted campaign with a stamp must stay invisible. Reverted.

### 6 — The architecture spec's non-vacuity (C3), both assertions

**6a — the positive:**

```ts
// modules/creators/routes.ts, the creator.jobs record
     meta: {
       layout: 'creator',
-      guards: ['requireAuth'],
+      guards: [],
     },
```

```
--- mutation A: creator.jobs declares guards: [] ---
   × creator-shell routes — requireAuth registration (AH-056, C3) > every creator-shell route declares requireAuth
      Tests  1 failed | 2 passed (3)
```

**6b — the negative:**

```ts
// modules/auth/routes.ts, the settings record
     name: 'settings',
     component: () => import('@/modules/settings/pages/SettingsPage.vue'),
-    meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] },
+    meta: { layout: 'creator', guards: ['requireAuth', 'requireAgencyUser'] },
```

```
--- mutation B: a layout:'creator' route declared in modules/auth/routes.ts ---
   × creator-shell routes — requireAuth registration (AH-056, C3) > declares NO creator-shell route outside the creators module
     → creator-shell routes must be declared in modules/creators/routes.ts, where this spec can see them: expected [ 'settings' ] to deeply equal []
      Tests  1 failed | 2 passed (3)
```

Both reverted. The spec is not vacuous in either direction.

### Restore check

```
$ git diff --stat
(empty)

=== RESTORE CHECK ===
  Tests:    946 passed (3230 assertions)
```

Working tree clean; the full Creators + Campaigns feature suite green after all six mutations were
reverted.

---

## The §5.34 seven-case set, on all four surfaces

One case per predicate leg, disjoint, run identically against the list, the detail, the apply
endpoint, and the fan-out's recipient query.

| #   | Case                                                                    | List   | Detail | Apply | Fan-out         |
| --- | ----------------------------------------------------------------------- | ------ | ------ | ----- | --------------- |
| 1   | Caller is not an approved creator (pending / rejected / incomplete)     | absent | 404    | 404   | not a recipient |
| 2   | Caller has no relation with the campaign's agency                       | absent | 404    | 404   | not a recipient |
| 3   | The relation is not `roster` (invited / pending / declined / ended / …) | absent | 404    | 404   | not a recipient |
| 4   | The campaign is not listed (`listed_on_jobs_board = false`)             | absent | 404    | 404   | nothing sent    |
| 5   | The campaign's status is terminal (completed / cancelled)               | absent | 404    | 404   | nothing sent    |
| 6   | `ends_at` is in the past                                                | absent | 404    | 404   | nothing sent    |
| 7   | The creator is HARD-blacklisted for the campaign's brand (C5)           | absent | 404    | 404   | not a recipient |

Positive partitions run alongside: an agency-wide SOFT blacklist hides (leg 3, deliberately
stricter), a brand-scoped SOFT blacklist does NOT hide (leg 6, hard-only), a hard-but-soft-deleted
brand blacklist does not hide, `ends_at = today` stays visible (start-of-day, not `now()`), and
`ends_at IS NULL` never expires.

**The two directions agree.** `JobPostedFanOutTest` has a test that takes every recipient the
fan-out selects and walks them back through `JobsBoardVisibility::findVisible()`, asserting each one
can actually see the job — and, in the capped case, that every creator the run skipped is either
already stamped or genuinely cannot see it. A fan-out that notifies someone who cannot then see the
job is a broken promise; that test is what stops it.

---

## The two exact-keyset assertions (D3)

Both job resources are pinned with exact key-list **equality**, not `assertJsonStructure` (which
passes on a superset and would let a brand field join by accretion):

```php
const CARD_KEYS = [
    'name', 'listing_fee', 'listing_duration',
    'applicant_count', 'listed_at', 'application_status', 'brand',
];

const DETAIL_KEYS = [
    ...CARD_KEYS,
    'description', 'listing_languages', 'listing_regions', 'listing_examples_url',
];
```

Brand keysets are pinned the same way: `['name', 'logo_url']` on the card,
`['name', 'logo_url', 'website_url']` on the detail. A companion test flattens the whole payload to
a string and asserts none of the withheld brand values appear anywhere in it — slug, description,
industry, status, safety rules, default currency, default language, portal flag. The fixture sets
the brand's slug and website to deliberately distinct values so an accidental substring overlap
cannot make that assertion pass by luck.

---

## Apply: re-validation, no-re-apply, and the §5.6 race

**Re-validation.** `apply()` calls `JobsBoardVisibility::findVisible()` before anything else. A test
renders the board, delists the campaign, then applies — 404, indistinguishable from a job that never
qualified. Repeated for all seven cases.

**No-re-apply.** A rejected application keeps the unique pair occupied. The second apply reads it
and refuses `409 job.application_rejected`; the row count stays 1 and the status stays `rejected`.
The SPA renders the terminal notice and never shows the button, but that is courtesy — the test
posts directly.

**The race (§5.6).** Two applies that both pass the existence check reach the insert; the
`(campaign_id, creator_id)` unique index makes one of them throw
`UniqueConstraintViolationException`, which is translated into the SAME `409 job.already_applied`
rather than a 500. The test drives it by inserting the competing row after the read and before the
write. The check is the friendly path; the constraint is the correctness.

**Q8 — the denormalized `agency_id` cannot diverge.** Set from the campaign at the single insert
site (never from ambient tenancy — the caller is a creator and holds none, so the `BelongsToAgency`
auto-fill would throw rather than guess). A test asserts
`$application->agency_id === $campaign->agency_id` and that the row is invisible to a different
agency's tenant scope.

---

## Fan-out: cap, once-only, dry-run, and the §5.2 splits

**Cap.** A roster of 5 with `--limit=2` notifies 2 and stamps exactly 2 — the assertion is on the
stamp count, because a run that stamped the tail it did not notify would make the remainder
permanently silent. Ordering is oldest-roster-first, asserted by relation `created_at`.

**Drain.** Three runs at `--limit=2` over a roster of 5 produce 5 stamps, 5 distinct creators, and
5 queued mails. A fourth run notifies 0. Nobody hears twice.

**Once-only / re-list.** Delist, re-list, run again: 0 notified, nothing queued. The stamp is what
makes a re-list silent, and it is the reason the stamp is its own table (D7) — it must be able to
exist before any application row does.

**Dry-run.** Flag-agnostic and mutation-free, asserted against every surface a send touches:
`campaign_job_notifications` count 0, `notifications` count 0, `Mail::assertNothingQueued()`. A
separate test reads the preview number, flips the flag, runs for real at the same cap, and asserts
the delivered count matches the promise.

**§5.2 splits per emission.** The in-app notification and the queued mail are asserted separately,
never as one "it notified" claim:

- in-app — a `Notification` row per recipient, correct `type`, correct recipient, `campaign_name`
  and `agency_name` in `data`, no actor (the listing is an agency act; attributing it to the staff
  member who flipped the toggle would put an employee's name in front of the whole roster);
- mail — `Mail::assertQueued(JobPostedMail::class, fn ($m) => $m->hasTo($email))` per recipient, and
  `assertNotQueued` for everyone excluded.

**§5.3 real-render + queued locale.** `JobPostedMailTest` renders the mailable for real (not
`Mail::fake()`) across en/pt/it/fr/de: distinct subjects per locale, the campaign name, agency name
and recipient name in the body, the `/creator/jobs/{ulid}` deep link present and matching the SPA
route, and the tags `['campaigns', 'job-posted']`. One more test asserts the brand's name does NOT
appear in the email — an inbox is forwardable and is not behind the visibility predicate, so the
brand's identity waits until the creator opens the job.

---

## Locale parity + flaky-10 spot-values

**Scope.** SPA: 35 keys × 24 locales in `creator.json` (`creator.ui.jobs.*`), 1 nav key ×24 in
`availability.json` (`creatorNav.jobs`), and 2 ×24 in `notifications.json`
(`notifications.types.campaign_job_posted` and its `preferences.typeLabels` sibling — the
preferences page needs its own label, which is the pairing `templates.spec.ts` exists to enforce).
Backend: 5 keys ×24 in `lang/{locale}/campaigns.php` (`job_posted.*`). 1,032 new leaves in total.

**Parity.** `i18n-locale-parity.spec.ts` green — key parity against the `en` SOT, placeholder-token
parity, and CLDR plural form-count parity (the two pluralised keys, `applicants` and
`listedDaysAgo`, use the two-form shape the house uses elsewhere; `buildPluralRules()` clamps, so
Polish's four categories degrade to `other` rather than rendering `undefined`).
`i18n-notifications-parity.spec.ts` green at 15 live types.

**Flaky-10 spot-values** — real translations, not English shims:

| Key                                         | Locale | Value                                                                                |
| ------------------------------------------- | ------ | ------------------------------------------------------------------------------------ |
| `creator.ui.jobs.title`                     | pl     | Ogłoszenia o pracę                                                                   |
| `creator.ui.jobs.subtitle`                  | fi     | Töitä toimistoilta, joiden kanssa työskentelet. Hae niihin, jotka sopivat sinulle.   |
| `creator.ui.jobs.status.rejected`           | hu     | Nem választottak ki                                                                  |
| `creator.ui.jobs.detail.apply`              | lt     | Kandidatuoti                                                                         |
| `creator.ui.jobs.detail.rejectedNotice`     | el     | Δεν επιλέχθηκες για αυτή τη δουλειά.                                                 |
| `creator.ui.jobs.applyDialog.noteLabel`     | mt     | Nota (mhux obbligatorja)                                                             |
| `creator.ui.jobs.toast.applicationRejected` | sk     | Na túto ponuku ťa nevybrali, preto sa nedá prihlásiť znova.                          |
| `creator.ui.jobs.empty.body`                | ga     | Nuair a chuirfidh gníomhaireacht a n-oibríonn tú léi post suas, taispeánfar anseo é. |
| `availability.creatorNav.jobs`              | bg     | Обяви за работа                                                                      |
| `campaigns.job_posted.subject` (backend)    | lv     | :agency publicēja jaunu darba piedāvājumu                                            |

Two locale-rendered assertions run in the component suite as well: the nav label reads "Ofertas de
trabalho" in pt and "Offerte di lavoro" in it.

---

## Gate board — full, at final HEAD

| Gate                                   | Result                                                     |
| -------------------------------------- | ---------------------------------------------------------- |
| `pest` (apps/api, full)                | **2234 passed, 1 skipped** (8045 assertions)               |
| `phpstan` (level max, apps/api)        | **0 errors**                                               |
| `pint --test` (app, tests, database)   | **clean**                                                  |
| `vitest` (apps/main, full)             | **1278 passed** / 139 files                                |
| `vitest` (apps/admin, full)            | **449 passed** / 53 files                                  |
| `vitest` (packages/api-client)         | **204 passed** / 9 files                                   |
| `vue-tsc --noEmit` (apps/main)         | **clean**                                                  |
| `vue-tsc --noEmit` (apps/admin)        | **clean**                                                  |
| `tsc --noEmit` (packages/api-client)   | **clean**                                                  |
| `eslint` (apps/main)                   | **0 errors** (2 pre-existing `v-html` warnings, untouched) |
| `eslint` (apps/admin)                  | **0 errors**                                               |
| `i18n-locale-parity.spec.ts`           | **green** (24 locales, all namespaces)                     |
| `i18n-notifications-parity.spec.ts`    | **green** (15 live types)                                  |
| `creator-routes-guard.spec.ts` (new)   | **green**, both assertions break-reverted                  |
| **Playwright (apps/main, full suite)** | **24/24 passed** in 3.9m, including the new AH-056 leg     |

**Playwright procedure.** The dev stack was brought down first (`pnpm dev` killed; ports 8000, 5173,
5174 and the stray 8001 E2E API confirmed free) because `reuseExistingServer: false` is a
post-incident invariant — a reused dev API would have been pointed at the developer's real database
by `global-setup.ts`'s unconditional `migrate:fresh`. The suite ran against `catalyst_e2e` with
`TEST_HELPERS_TOKEN` exported. After the run the stack was restarted and health-checked:

```
API /up: 200
SPA :5173: 200
admin :5174: 200
```

New AH-056 leg, from the run:

```
  ✓   9 [chromium] › playwright/specs/creator-jobs-board.spec.ts:45:3 › AH-056 — creator jobs board ›
        an approved rostered creator browses the board, opens a job, and applies (11.8s)

  24 passed (3.9m)
```

### New tests, by file

| File                                           | Tests                                   |
| ---------------------------------------------- | --------------------------------------- |
| `CampaignApplicationSchemaTest.php`            | 12                                      |
| `CreatorJobBoardTest.php`                      | 20                                      |
| `CreatorJobDetailTest.php`                     | 17                                      |
| `CreatorJobApplyTest.php`                      | 22                                      |
| `JobPostedFanOutTest.php`                      | 25                                      |
| `JobPostedMailTest.php`                        | 5 (+4 dataset rows)                     |
| `PreviewJobPostedNotificationsCommandTest.php` | 9 (13 with the `--limit` dataset)       |
| `jobs.api.spec.ts`                             | 6                                       |
| `CreatorJobsPage.spec.ts`                      | 11                                      |
| `CreatorJobDetailPage.spec.ts`                 | 12                                      |
| `CreatorDashboardLayout.spec.ts` (extended)    | +3                                      |
| `AdminFeatureFlagTest.php` (extended)          | +1, plus the registry-listing assertion |
| `creator-routes-guard.spec.ts`                 | 3                                       |
| `creator-jobs-board.spec.ts` (Playwright)      | 1                                       |

---

## Production posture (restated at final code HEAD `ddf875c`)

**§5.40 risk: ⚠ LOW-MEDIUM.** Unchanged from the plan-pause derivation.

**What the migrations do.** Three, all additive. Two `CREATE TABLE`s
(`campaign_applications`, `campaign_job_notifications`) and one nullable `ADD COLUMN`
(`campaigns.listed_at`). No existing row is read or rewritten. **The `down()` on both new tables is
lossy** — dropping them destroys every application and every notification stamp, which is honest
and worth saying out loud rather than pretending a rollback is free. In practice a rollback after
creators have applied would be a data-loss event, not a revert.

**The one runtime write to existing rows.** `campaigns.listed_at` is stamped by
`CampaignController::update()` on the false → true listing flip. It is display metadata; the read
scope never consults it (break-revert 5). No backfill is needed and none is performed: the column
ships in the same release as the board, so there is no window in which a listed campaign lacks a
stamp — and a null stamp renders as "no chip", not as a broken card.

**Nothing reaches production until the arc completes.** Deploy is held to end-of-arc. At deploy,
zero campaigns are listed, so the board is empty for every creator and the fan-out has nothing to
send even if the flag were on. It is not: `job_posted_notifications_enabled` defaults OFF.

**The mail fan-out.** This is the arc's first outbound mail to the live creator base (~279). Three
containments, in order of how much they bound the blast radius: the flag (nothing sends at all), the
per-run cap of 50 (one flip cannot mail the whole roster), and the recipient set (only creators
already on that agency's roster — never cold). The first-enable ritual is a `--dry-run` read before
the flip, documented in `docs/feature-flags.md`.

**The accepted silent miss (Q3.4).** The stamp is written after the two emissions, per recipient.
A creator whose mail dies at the transport layer after their stamp is written is never re-notified
for that job. That is the deliberate side of the trade: for a fan-out to a live roster, one silent
miss is better than a double-send, and the worker retry that would fix the miss would re-mail
everyone the run had already reached.

**No per-creator email opt-out.** The in-app notification is toggleable; the email is not, because
the email channel has never been wired through preference reads platform-wide. Named honestly here,
logged in `docs/tech-debt.md`, trigger: before any second recurring creator mail ships, or on the
first complaint.

**Queue posture.** Default connection, default queue, framework-default retries, plain `dispatch()`
after save. A listing flip can put 51 jobs on the same queue every other job uses; the cap bounds
each burst but not their frequency. Logged in `docs/tech-debt.md` with the second-worker resolution.

**No scheduler dependency.** Deliberate, and not a preference: the production scheduler's existence
is unverified (the standing blocker in `RESUMPTION-TEMPLATE.md`), so a feature whose only trigger is
`schedule:run` could ship, pass every test, and never fire. The trigger is the listing flip; the
drain is an operator command. A queue worker is verified to run; cron is not.

**The AH-005-class boundary.** Brand data reaches a creator audience for the first time on this
platform: three fields, via dedicated narrow resources, pinned by exact-keyset equality. Adding a
fourth is a decision, not a patch.

**The first creator-visible aggregate.** `applicant_count` shows creators how many others have
applied. Non-identifying and deliberate, recorded here so a later "why do we show this?" has an
answer.
