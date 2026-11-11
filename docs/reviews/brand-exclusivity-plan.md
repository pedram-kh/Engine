# AH-120 plan — brand exclusivity between campaigns

> Mode A, step 3 (plan-pause). **NO code until the plan is cleared.** Inventory and rulings:
> [`brand-exclusivity-inventory.md`](brand-exclusivity-inventory.md) (Q1 to Q12 are binding).
> Written against `origin/main` `65f1a671` (docs on top of `c8257afe`). Production: API
> `6d7a9c28` + build 4; AH-115 pushed, not deployed.

**PROD-DATA RISK: LOW.** One new table and one nullable `date` column, no existing row read or
written, no data in the migration, no backfill, no one-shot command. The feature writes new pivot
rows and a date only when a strategist saves a campaign with the flag ON. Flag OFF restores
today's behaviour for every stored value. Pre-deploy snapshot is mandatory (carries migrations).

**Standards applied:** additive-first migration (schema only); honest `down()`; a single predicate
with PHP and SQL forms parity-tested; break-revert on every gate; §5.34 disjoint negative sets;
24-locale done-gate (no English fallback in the flaky 10); flag default OFF; full-suite gates
before push; §4.1 local-run rules for every run.

**Local-run rules (§4.1), every run:** `pkill -f vitest; pkill -f playwright; pkill -f walk.mjs`
first; one suite at a time; `VITEST_MAX_WORKERS=4 npx vitest run ...`; Pest serial with
`-d memory_limit=2G`; Playwright `--workers=2`, dev stack down, isolated `catalyst_e2e`; never
background-then-another.

## 0. Structural intent and one reinterpretation to confirm

Intent: a creator selected on a campaign that names brand B cannot apply to B's campaigns while the
selection (or the strategist's date) holds; nothing visible changes except the Apply action; the
agency is never blocked, only warned.

**Reinterpretation (inventory §4):** Q2 ("a completed blocking campaign ends its block") versus
ruling 2 ("the date may be after completion"). Plan builds: **cancelled ends the block always;
completed ends it only when no date is set; an explicit date wins.** Please confirm at plan review.

## 1. Sub-steps (each independently green)

### API

**A1 — migration, models, flag, enum classification (no behaviour).**

- Migration `2026_11_12_100000_add_brand_exclusivity_to_campaigns` (dated after the last,
  `2026_11_09_100000`; `@migration-risk low`): adds `campaigns.exclusivity_ends_on date NULL`; creates
  `campaign_exclusive_brands` (`id`, `campaign_id` FK cascade, `brand_id` FK restrict, timestamps,
  `unique(campaign_id, brand_id)`, `index(brand_id, campaign_id)`). One migration file per table
  change is acceptable; both in this one set. `down()` honest (drops both; comment: blocked lists
  cannot be restored).
- `CampaignExclusiveBrand` model (no global tenancy scope; read only through the campaign);
  `Campaign::exclusiveBrands()` (`BelongsToMany` via the pivot, `withTrashed`) and cast
  `exclusivity_ends_on` => `date`; `@property` docs; `$fillable` entry.
- `AssignmentStatus::countsAsSelected()` and `AssignmentStatus::endsExclusivity()` (completed set).
- Flag `CampaignBrandExclusivityEnabled` (`NAME = 'campaign_brand_exclusivity_enabled'`, default
  OFF) registered in `CreatorsServiceProvider`, `AdminFeatureFlagController` (label and
  description), `SetFeatureFlagController` allow-list, `UserResource` (`features.campaign_brand_exclusivity`,
  agency-side and creator-side per the region precedent), `docs/feature-flags.md`.
- Tests: schema test, classification test, flag tests. Green: Pest scoped, Pint, PHPStan (no path
  argument), arch tests.

**A2 — the predicate `BrandExclusivity` (service in `Campaigns/Services`).**

- `blockedBrandIdsFor(Creator): list<int>`: one query. Assignments of the creator in
  `countsAsSelected()` states (not soft-deleted) joined to `campaigns` (not soft-deleted, status not
  `cancelled`) joined to the pivot; rule: `exclusivity_ends_on IS NOT NULL AND ends_on >=
today(UTC)` OR `ends_on IS NULL AND assignment status NOT IN completed AND campaign status <>
completed`. Returns distinct brand ids. Flag OFF returns `[]` without querying.
- `isBlocked(Campaign, Creator): bool`; `conflictsFor(Campaign, array $creatorIds): array<int,
ConflictInfo>` (creator id => blocking campaign name/ulid; one bulk query, deterministic pick of
  the earliest blocking campaign); `scopeNotBlockedFor` SQL mirror (a `whereNotExists` closure the
  fan-out applies), written from the same state lists read off the enum.
- "Today" is `Carbon::now('UTC')->toDateString()` in one private method (spec-fakeable).
- Tests: `BrandExclusivityPredicateTest` (disjoint negative set; see §6), parity harness.

**A3 — write path.**

- `ValidatesJobsBoardListing` is the region-rule home but exclusivity is not a listing field; add a
  new concern `ValidatesBrandExclusivity` used by `CreateCampaignRequest` and `UpdateCampaignRequest`:
  - `exclusive_brand_ids`: `sometimes|array|max:50`, each `string|distinct`, resolved to brands of
    **this agency**; refuse the campaign's own brand (on create: the payload brand; on update: the
    stored brand); refuse an **archived** brand unless it is already on the stored list.
  - `exclusivity_ends_on`: `sometimes|nullable|date_format:Y-m-d`; refused if before today (UTC)
    **only when the value differs from the stored one** (so an unrelated edit of a campaign whose
    date has since passed is never refused); clearing (null) always allowed.
- Controller: `refuseDisabledBrandExclusivity` (flag OFF and a non-empty list or a set date ->
  422 `campaign.brand_exclusivity_disabled`; empty list and null always allowed). Pop
  `exclusive_brand_ids` out of `validated()` before `fill`; sync the pivot in `store` and `update`
  inside the same flow as the save; add both to `store()`'s explicit attribute whitelist
  (`exclusivity_ends_on`).
- `auditableSnapshot` gains `exclusive_brand_ids` (sorted ULID-independent: brand internal ids,
  sorted) and `exclusivity_ends_on`; `AuditPayloadKeys::notPersonalKeys()` classification. No new
  `AuditAction`.
- `CampaignResource`: `exclusive_brand_ids` (brand ULIDs, sorted) and `exclusivity_ends_on`
  (`Y-m-d` or null); pivot eager-loaded on `show` and `update`; the list payload's treatment is
  open point 3 (never emit a wrong empty array).
- Tests: `CampaignBrandExclusivityWriteTest`, snapshot and registry tests, keyset on
  `CampaignResource`.

**A4 — creator job detail and apply.**

- `CreatorJobBoardController::show`: annotate `caller_brand_locked` and `caller_brand_lock_reason`
  next to the region ones; `CreatorJobDetailResource` emits `is_brand_locked` (bool) and
  `brand_lock_reason` (`'brand_exclusive'|null`), always present; exact-keyset test updated.
- `apply`: after the 409 duplicate, before the region check: 422 `job.brand_exclusive`, generic
  detail, `meta.reason = 'brand_exclusive'`; never names a brand. **No card change, no change to
  `visibleTo()`** (Q6).
- Tests: `CreatorJobBrandExclusiveTest` (detail payload, apply 422, order against region, 409
  precedence, visible-not-404, flag OFF inert), extended `CreatorJobDetailTest`.

**A5 — fan-out skip.** `JobPostedFanOutService::eligibleRelations` gets the SQL mirror leg beside
the region leg (campaign-brand fixed; correlated on `creator_id`). Tests:
`JobPostedFanOutBrandExclusivityTest` including PHP/SQL parity over the same fixtures.

**A6 — agency reads.**

- `POST /agencies/{a}/campaigns/{campaign}/exclusivity-conflicts` (`InviteExclusivityController`,
  `invite` ability, 25-id cap per call matching the dialog's page, creator ULIDs in, ULID map out
  with `blocking_campaign: {id, name}`; unknown ULIDs ignored; flag OFF returns `{data: []}`);
  route-table pin updated.
- `CampaignApplicationController::index`: `has_exclusivity_conflict` and `exclusivity_conflict`
  (`{campaign: {id, name}}` or null) on **pending rows only**, from one `conflictsFor` call over the
  page; `CampaignApplicationListItemResource` keyset updated.
- Tests: `ExclusivityConflictsEndpointTest`, `CampaignApplicationConflictTest`, extended
  `CampaignApplicationListTest`.

**A7 — test helpers (testing env only).** `CreateListedJobController` and
`CreateContractedAssignmentController` accept optional `exclusive_brand_ids` and
`exclusivity_ends_on` (brand ULIDs resolved in the agency); `SetFeatureFlagTest` covers the new
flag. Their feature tests extended.

**Checkpoint A (API green, stop for review before web):** full Pest serial, Pint `--all`, PHPStan,
the AH-120 break-revert record for A2 to A6.

### Agency web (`apps/main`)

**W1 — types and API client.** `packages/api-client`: campaign attributes, create/update payloads,
application list item, conflicts call; `campaigns.api.ts` + `campaigns.api.spec.ts`;
`features.campaign_brand_exclusivity` in `user.ts`.

**W2 — form and Overview.** `CampaignForm.vue`: `v-autocomplete` multiple over the agency's
non-archived brands excluding the campaign's own brand (already-listed archived brands shown with
the Archived pill, removable not addable), max 50 with a counter, date field with min = today;
rendered only when the flag is on; client messages for the 422 codes. `CampaignDetailPage.vue`
Overview read row (brand names, end date) and the Settings path. Pages pass
`:brand-exclusivity-enabled` as for region.

**W3 — applications and invite surfaces.** `ApplicationsTab.vue`: "conflict" chip with a tooltip
naming the blocking campaign, on pending rows. `InviteCreatorsDialog.vue`: after selection (and
before submit) call the conflicts endpoint; a non-blocking inline warning listing the conflicted
creators, no extra confirm step, the existing 409 availability loop untouched.
`AcceptApplicationDialog.vue`: the same warning from the row's own conflict data.

### Creator web

**C1 — detail page.** `CreatorJobDetailPage.vue`: a branch 5a′ before the region branch (branch
order: engagement states, brand lock, region lock, apply): generic "Not available to you" alert, no
profile link, no explanation; `lockFromBrandRefusal` for a stale-page 422 `job.brand_exclusive`
(flips `is_brand_locked`, toast). List page untouched. `CreatorJobDetailResource` type in
`api-client`.

**C2 — native.** No native-only code; the same SPA. Build 5 is **held** until the AH-120 tip is
bundled (Q10): `cap sync ios` only (R-7), `CURRENT_PROJECT_VERSION` stays 5, archive after the full
gates and the creator walk.

## 2. Pre-existing surfaces that must stay byte-identical

- Creator walk: `walk.mjs --set creator --compare ../../docs/reviews/ah-114-creator-baseline/baseline.json`
  must print `COMPARE OK: 142 targets identical` (the detail page is walked flag-OFF; the new
  branch is flag-gated by data).
- Agency 126 / 126 walk pin where the campaign form is walked; the form change is flag-gated so DOM
  with the flag OFF is unchanged (a flag-off DOM pin in the spec).
- `shared-components-frozen` and `shared-tokens-frozen`: no `packages/ui` change planned.

## 3. i18n keys for approval (en, then 24 locales; real MT baseline in the flaky 10)

`creator.json` (`creator.ui.jobs...`):

1. `detail.brandLocked`: "Not available to you"
2. `toast.brandNotAvailable`: "This job isn't available to you."

`app.json` (`app.campaigns...`): 3. `fields.exclusiveBrands`: "Block creators selected here from applying to" 4. `fields.exclusiveBrandsHint`: "Creators selected for this campaign can't apply to campaigns of these brands. Invitations are not affected." 5. `fields.exclusiveBrandsPlaceholder`: "Choose brands" 6. `fields.exclusiveBrandsLimit`: "Up to {max} brands" 7. `fields.exclusivityEndsOn`: "Exclusivity ends on" 8. `fields.exclusivityEndsOnHint`: "Leave empty to end when the creator's work on this campaign is complete." 9. `fields.exclusiveBrandsOverview`: "Blocks applications to" 10. `fields.exclusivityEndsOnOverview`: "Ends on {date}" 11. `errors.exclusivityDateInPast`: "Choose today or a later date." 12. `errors.exclusivityOwnBrand`: "A campaign cannot block its own brand." 13. `errors.brandExclusivityDisabled`: "Brand exclusivity is not available yet." 14. `applications.exclusivityConflict`: "Conflict" 15. `applications.exclusivityConflictTooltip`: "Selected for {campaign}, which blocks applications to this brand. You can still accept." 16. `invite.exclusivity.warning`: "{count} selected creator(s) are on a campaign that blocks this brand. You can still invite them." 17. `invite.exclusivity.creatorLine`: "{name}: {campaign}" 18. `accept.exclusivityWarning`: "This creator is selected for {campaign}, which blocks applications to this brand. You can still accept."

(18 keys; 17 and 18 carry ICU-style placeholders that must survive MT, enforced by the parity
spec. Admin flag label and description are English-only PHP like AH-118.)

## 4. Specs by name

**API new:** `CampaignBrandExclusivitySchemaTest`, `AssignmentStatusExclusivityClassificationTest`
(every case classified exactly once), `BrandExclusivityPredicateTest`,
`CampaignBrandExclusivityWriteTest`, `CreatorJobBrandExclusiveTest`,
`JobPostedFanOutBrandExclusivityTest`, `ExclusivityConflictsEndpointTest`,
`CampaignApplicationConflictTest`, `CampaignBrandExclusivityFlagTest`, a creator-surface leak test
(`CreatorSurfacesNeverNameExclusiveBrandsTest`: detail keyset, 422 body, `/me`, assignments
resources). **Extended:** `CreatorJobDetailTest`, `CampaignApplicationListTest`,
`SetFeatureFlagTest`, `CreateListedJobRegionTest`/`CreateContractedAssignmentTest` siblings,
`AuditPayloadKeys` registry test, route-table pin.

**Frontend:** `CampaignForm.spec.ts`, `CampaignDetailPage.spec.ts`, `CampaignCreatePage.spec.ts`,
`ApplicationsTab.spec.ts`, `InviteCreatorsDialog.spec.ts`, `AcceptApplicationDialog.spec.ts`,
`CreatorJobDetailPage.spec.ts`, `campaigns.api.spec.ts`, locale parity and placeholder-survival
specs.

## 5. Playwright

New `creator-brand-exclusivity.spec.ts`: Zava campaign blocking Yahoo with a creator contracted on
it; flag ON: Yahoo job visible, "Not available to you", no Apply, no Zava/brand text on the page,
apply API 422 `job.brand_exclusive`; an end date in the past (set via helper) lifts it; a creator
with an existing application keeps the "Applied" state; agency Applications tab shows the conflict
badge naming the Zava campaign; flag OFF: Apply works. Extend `test-helpers.ts`. Full Playwright
(`--workers=2`, dev stack down, isolated DB, restart and health-check after) before push because
`creator-jobs-board.spec.ts` and `jobs-board-full-lifecycle.spec.ts` traverse the job detail and
the campaign form.

## 6. Break-reverts (§4; mutate, watch the right spec fail, revert, verify clean, re-green)

Predicate: drop the creator filter; drop the pivot join; drop the status filter (invited blocks);
add declined to the set; drop `revision_requested`; drop the campaign `cancelled` leg; drop the
campaign `completed` leg; flip the date comparison (`>=` to `>`); drop the "date wins" branch;
drop the completed-assignment leg (live_verified blocks forever); drop the flag leg; drop soft-delete
legs. Mirror: drop the fan-out leg; change one status in SQL only (parity fails). Apply: put region
before exclusivity; drop the 409-first ordering; drop the check. Write: drop the agency scope on
brand resolution; allow own brand; allow archived addition; drop past-date refusal; refuse an
unchanged past date; drop the flag refusal; forget the pivot sync on `store`; leave the list in
`fill`. Resource: emit a brand name on a creator surface. Web: show Apply while locked; drop the
stale-page handler; show the conflict chip on non-pending rows.

**§5.34 disjoint negatives for the predicate** (each eligible in every respect but one): the
creator's selected assignment exists but the campaign has no pivot row for B; pivot names B but the
assignment is `invited`; `declined`; `cancelled`; the assignment belongs to another creator; the
blocking campaign is cancelled; completed with no date; the date has passed; a completed assignment
with no date; flag OFF; the target campaign's brand is a different brand; the blocking campaign is
soft-deleted.

## 7. Deploy entry (stacked with AH-115; one migration set; flag OFF at deploy)

Open into the existing `PENDING` AH-115 entry in `docs/runbooks/deploy-log.md` (and extend
`deploy-ah115.md`): snapshot (verify completed) -> `migrate` (AH-115's
`2026_11_09_100000` plus AH-120's migration; additive) -> `queue:restart` not required (no job
changed; the fan-out service is read per run) but stays a no-op safe step -> API ->
**verify `campaign_brand_exclusivity_enabled` reads OFF and the existing flag set unchanged** ->
main web -> native build 5 (archived after AH-120, Q10) -> then the flags as separate acts:
`messages_v2_creator_inbox_enabled` after build 5 is live, and `campaign_brand_exclusivity_enabled`
after the web deploy (server enforces; no native dependency). First-enable ritual: read one
configured pair end to end (a Zava campaign blocking Yahoo, a selected creator, the Yahoo detail
and the apply refusal, the agency badge) before arming. Rollback of behaviour is the flag; the
migration is not rolled back.

## 8. Production posture

- **Migration:** creates `campaign_exclusive_brands`, adds nullable `exclusivity_ends_on`; no row
  read or rewritten; no default flip; no data write.
- **Feature writes:** pivot rows and the date on campaign create/update with the flag ON, by
  authorised agency members only.
- **Reads:** `campaign_assignments` and `campaigns` by existing indexes; no scheduled job.
- **Blast radius:** a predicate bug wrongly blocks or fails to block an apply; no state of an
  existing application or assignment is changed (they are never touched); flag OFF removes the
  effect immediately. No new email, push or notification (nothing to cap).

## 9. Open points for plan review

1. Confirm the Q2/ruling-2 reading in §0 (cancelled always; completed only without a date).
2. The conflicts endpoint is a POST (read-only) for a body of creator ids; acceptable, or a GET
   with `ids[]` for strict REST? (POST avoids URL length at 25 ids; GET fits the read-only intent.)
3. `exclusive_brand_ids` on the campaign **list** payload: batch-load the pivot (one extra query
   per list, keys always present and correct) or omit the keys on the list and emit them on
   show/create/update only? Plan leans to batch-load so the keyset is data-independent and never
   wrong; nothing on the list needs them, so omitting is the cheaper alternative.
4. Native: hold build 5 means the build-5 device walk list gains one step (the locked detail
   page); confirm adding it to the last section of `creator-restyle-review.md`.
