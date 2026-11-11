# AH-120 inventory — brand exclusivity between campaigns

> Read-only inventory (Mode A, step 1). Written against `HEAD` `c8257afe` (`origin/main` = `HEAD`,
> AH-115 pushed and not deployed; production at `6d7a9c28` + build 4). Every claim cites
> `path:line` as read on that tip. **Status: ACCEPTED with rulings Q1 to Q12 (§13).**

**Client request:** "block people from applying to certain briefs (e.g. if selected for Zava, cannot
apply to Yahoo)."

**PROD-DATA RISK: LOW.** One new table and one nullable column; the migration touches no existing
row and backfills nothing. The feature writes only new rows (the blocked-brand list) when a
strategist saves a campaign with the flag on. The blast radius of a bug is creators wrongly blocked
from applying; no application or assignment row is ever rewritten.

## 1. Rulings that bound this inventory (from the request)

1. Per campaign, the strategist sets "Creators selected for this campaign can't apply to campaigns
   of: [multi-select of the agency's brands]". Brand-level, so future campaigns of a blocked brand
   are covered automatically.
2. "Selected" = an assignment on that campaign in accepted or any later active state. Invited and
   countered do not block; declined, rejected, cancelled end the block immediately. Otherwise the
   block lasts until an optional "exclusivity ends on" date; with no date it ends at completion.
3. One-way as configured; the mirror is set on the other campaign if wanted.
4. Agency invites are not blocked; the invite dialog shows a non-blocking conflict warning.
5. Creator side: the blocked job stays visible, Apply is replaced by a generic "Not available to
   you" (never names the other brand); the server enforces on apply with a 422 code. Reuse the
   AH-118 region-lock pattern.
6. Applications that exist when a block starts are left alone; the agency sees a "conflict" badge.
7. Behind a Pennant flag, default OFF.

## 2. Findings that shaped the rulings

1. **"Ends at completion" never fired for creator-posts campaigns.** Nothing calls `holdPayment()`
   or `releasePayment()` (defined only at `CampaignAssignmentStateMachine.php:580,592`), so
   `payment_released` is unreachable and a posting campaign ends at `live_verified` /
   `manually_verified`, both non-terminal (`AssignmentStatus.php:100-131`). Resolved by Q1.
2. **The AH-118 job card carries no lock flag.** `is_region_locked` is detail-only (Q10 of AH-118,
   `CreatorJobDetailResource.php:98`). Resolved by Q6: detail only.
3. **Cancelling or completing a campaign does not cascade to its assignments.**
   `CampaignController::update` only dispatches `AutoRejectPendingApplicationsJob` on the terminal
   flip (`:280-282`). Resolved by Q2.
4. **`CampaignResource` is used only by the agency controller** (the only other hit is the model's
   own docblock), so the blocked-brand list cannot reach a creator through any resource. A negative
   keyset test still pins it.
5. **Native bundles `dist/`** (`apps/main/capacitor.config.ts:16`), so creator UI changes ship in
   the binary. Resolved by Q10: build 5 is held until AH-120 lands.

## 3. Schema (additive)

| Piece                             | Shape                                                                                                                                   | Notes                                                                                                                                         |
| --------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `campaigns.exclusivity_ends_on`   | `date NULL`, no default, no index                                                                                                       | Metadata-only add; read only on rows already loaded.                                                                                          |
| `campaign_exclusive_brands` (new) | `id`, `campaign_id` FK cascade, `brand_id` FK **restrict**, timestamps; `unique(campaign_id, brand_id)`; `index(brand_id, campaign_id)` | The predicate runs in reverse ("who is selected in a campaign that blocks brand B"); FK integrity; no jsonb containment / GIN; SQLite parity. |

- Existing indexes cover the read: `idx_assignments_creator_status (creator_id, status)` and
  `unique_assignment_campaign_creator (campaign_id, creator_id)`
  (`2026_06_05_100001_create_campaign_assignments_table.php:127-134`).
- `campaign_assignments.brand_id` is denormalized but is the assignment's own brand; the blocking
  campaign's list lives in the pivot.
- **Write-path trap:** `store()` uses a whitelist (`CampaignController.php:101-138`, the AH-054
  catch) and `update()` does `fill($updates)` (`:259`). The brand list must be removed from
  `validated()` before `fill` and synced explicitly in both paths.
- `down()` honest: `dropIfExists` and `dropColumn`, with a comment that blocked lists cannot be
  restored. Migrations modify schema only (WORKING-PROCESS §5).

## 4. The "active" state list (rulings Q1, Q2)

Classification lives **on the enum** (`AssignmentStatus`), not in SQL strings.

| Group                                            | Statuses                                                                                                                                                                        |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Selected** (blocks while the rule below holds) | accepted, contracted, producing, draft_submitted, revision_requested, approved, posted, live_verified, manually_verified, payment_held, payment_released, completed_on_approval |
| **Never blocks**                                 | invited, countered                                                                                                                                                              |
| **Ends the block immediately**                   | declined, rejected, cancelled                                                                                                                                                   |
| **Completed** (ends the no-date block)           | live_verified, manually_verified, payment_held, payment_released, completed_on_approval                                                                                         |

- Methods: `countsAsSelected()` and `endsExclusivity()` (name settled in the plan). A spec
  classifies **every case exactly once**, so a new case fails the build.
- Soft-deleted assignments and campaigns are excluded (both models soft-delete).

**The block rule for one assignment on blocking campaign Z:**

1. The assignment is in a **selected** state, Z is not soft-deleted, and Z's status is not
   `cancelled`; then
2. if `Z.exclusivity_ends_on` is set: blocks while `today(UTC) <= ends_on` (inclusive to the end of
   the day), regardless of assignment completion;
3. if it is null: blocks while the assignment is not in a **completed** state **and** Z's status is
   not `completed`.

Reinterpretation recorded for the plan review (§5.32 structural intent): Q2 says a completed
blocking campaign ends its block, while ruling 2 says the date may run past completion. The intent
kept: **cancelled ends the block always; a completed campaign ends the block only when no date is
set, because the strategist's explicit date wins.**

## 5. The predicate and composition with the region lock

One service, `BrandExclusivity`:

- `blockedBrandIdsFor(Creator): list<int>` — **one query**: the creator's assignments in selected
  states, joined to `campaigns` by primary key and to the pivot, with §4's rule applied. Returns a
  small set.
- `isBlocked(Campaign, Creator)` = `in_array($campaign->brand_id, blockedBrandIdsFor(...))`.
- SQL mirror for the fan-out: a `whereNotExists` in `JobPostedFanOutService::eligibleRelations`
  next to the region leg (`JobPostedFanOutService.php:215-226`), parity-tested against the PHP
  predicate.
- Bulk read for the agency: `conflictsFor(Campaign, creatorIds[])`, used by the applications list
  and the invite dialog.

**Not in `JobsBoardVisibility::visibleTo()`** (`JobsBoardVisibility.php:88-130`): the blocked job
must stay visible, and a leg there would 404 it. Flag OFF returns "not blocked" everywhere, the
shape of `Campaign::restrictsApplicationsToRegions()` (`Campaign.php:249-260`).

**Apply order (Q3):** 404 visibility, 409 duplicate (`CreatorJobBoardController.php:259`), **422
`job.brand_exclusive`**, 422 `job.region_not_allowed` (`:260-276`), insert. Exclusivity precedes
region because the creator cannot fix it; a `country_missing` creator who is also blocked would
otherwise be sent to their profile for nothing. An existing application or assignment still wins
(409 first). The detail page keeps its branch order: engagement states first, then the locks.

**Payload (detail only, always present):** `is_brand_locked: bool`, `brand_lock_reason:
'brand_exclusive' | null`. The AH-118 fields stay untouched. The reason never names the brand; the
422 body detail is generic.

## 6. Endpoints touched

| Endpoint                                                         | Change                                                                                                                                                                                                                                                                                                                                                                                   |
| ---------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET /creators/me/jobs`                                          | **None** (Q6, detail only).                                                                                                                                                                                                                                                                                                                                                              |
| `GET /creators/me/jobs/{campaign}`                               | Annotate `caller_brand_locked` / `caller_brand_lock_reason` beside `:199-200`; resource and exact-keyset test updated.                                                                                                                                                                                                                                                                   |
| `POST /creators/me/jobs/{campaign}/apply`                        | New refusal per §5.                                                                                                                                                                                                                                                                                                                                                                      |
| `POST /agencies/{a}/campaigns`, `PATCH .../{campaign}`           | `exclusive_brand_ids[]` (brand ULIDs, max 50, distinct, this agency, not the campaign's own brand, not archived when newly added) and `exclusivity_ends_on` (UTC date, not in the past when changed; clearing allowed). Flag OFF: a non-empty list is refused 422 `campaign.brand_exclusivity_disabled` (mirrors `refuseDisabledRegionRestriction`, `:491`); clearing is always allowed. |
| `GET .../campaigns/{campaign}`, list                             | `CampaignResource` gains `exclusive_brand_ids` and `exclusivity_ends_on` (pivot eager-loaded on show).                                                                                                                                                                                                                                                                                   |
| Invite dialog data                                               | New read-only `POST .../campaigns/{campaign}/exclusivity-conflicts {creator_ids[]}` (route-table change), returning conflicted creator ULIDs and the blocking campaign's name for the agency. The dialog's candidates come from `rosterApi.list` (`InviteCreatorsDialog.vue:129`), so a pre-check on selection avoids annotating the roster.                                             |
| `POST .../assignments`, `.../applications/{id}/accept`           | **Not blocked** (ruling 4); no new gate.                                                                                                                                                                                                                                                                                                                                                 |
| `GET .../campaigns/{campaign}/applications`                      | `has_exclusivity_conflict` per **pending** row plus the blocking campaign's name, from one bulk call over the page (cap 100). Exact-keyset test changes.                                                                                                                                                                                                                                 |
| Job-posted fan-out                                               | SQL mirror per §5.                                                                                                                                                                                                                                                                                                                                                                       |
| Creator accept of an existing invitation, re-offer after decline | **Never blocked** (Q8).                                                                                                                                                                                                                                                                                                                                                                  |

## 7. Performance

- Detail and apply: one query returning a handful of ids; `(creator_id, status)` on the creator
  side, join to `campaigns` by primary key, pivot by `campaign_id` through its unique index.
- List: no added cost (Q6).
- Fan-out: a correlated `NOT EXISTS` per candidate relation; pivot `(brand_id, campaign_id)` plus
  `(creator_id, status)` cover both directions; the per-run cap bounds volume.
- Applications list and invite dialog: one bulk query per request keyed on the page's creator ids.
- No index beyond the pivot's two.

## 8. Creator surfaces

- **Web:** `CreatorJobDetailPage.vue`. A new branch next to the region branch (`:440`) shows the
  generic "Not available to you" alert with no profile link. The stale-page 422 handler
  (`:149-190`) gets a `job.brand_exclusive` case that flips the page to locked.
- **List:** unchanged.
- **Native:** the same SPA, bundled. Per Q10 build 5 is held until AH-120 lands, so one build
  carries both.
- **Older builds** (4, and any pre-AH-120 build): an unknown 422 code falls to the generic error
  toast (`:190`); the server still enforces and the app still works.
- No creator surface names another brand: negative keyset test on the detail resource and the 422
  body.

## 9. Agency surfaces

- `CampaignForm.vue`: multi-select of the agency's non-archived brands and a date picker, rendered
  only while `features.campaign_brand_exclusivity` is on (the `/me` pattern,
  `UserResource.php:71`). An already-listed archived brand shows with an Archived pill and can be
  removed but not added.
- `CampaignDetailPage.vue` Overview: a read row; Settings edit reuses the form.
- `ApplicationsTab`: "conflict" badge on pending rows, tooltip naming the blocking campaign.
- `InviteCreatorsDialog` and `AcceptApplicationDialog`: a non-blocking warning. Kept separate from
  the availability 409 `acknowledged` loop so the tiers are not collapsed.
- Roster, Discover, pool: untouched.

## 10. Audit

- Add `exclusive_brand_ids` and `exclusivity_ends_on` to `auditableSnapshot`
  (`CampaignController.php:463`); `campaign.updated` then answers who changed the exclusivity and
  when. Classify both in `AuditPayloadKeys::notPersonalKeys()` (an id list and a timestamp-class
  value) or the erasure registry spec fails.
- **No new `AuditAction`** (Q7: no acknowledgement metadata). The apply refusal is not audited,
  same as the region refusal.

## 11. i18n

About 14 to 16 keys x 24 locales; the flaky 10 get a real MT baseline at merge (WORKING-PROCESS
§4). The exact list is in the plan for approval. The admin flag description is English-only PHP in
`AdminFeatureFlagController.php` (AH-118's entry at `:119`).

## 12. Specs and Playwright (by name; detail in the plan)

API new: `CampaignBrandExclusivitySchemaTest`, `BrandExclusivityPredicateTest`,
`AssignmentStatusExclusivityClassificationTest`, `CampaignBrandExclusivityWriteTest`,
`CreatorJobBrandExclusiveTest`, `JobPostedFanOutBrandExclusivityTest`,
`CampaignApplicationConflictTest`, `ExclusivityConflictsEndpointTest`. Extended:
`CreatorJobDetailTest`, `CampaignApplicationListTest`, `SetFeatureFlagTest`, the two test-helper
controllers and their tests. Frontend: `CampaignForm.spec.ts`, `CampaignDetailPage.spec.ts`,
`CreatorJobDetailPage.spec.ts`, `ApplicationsTab.spec.ts`, `InviteCreatorsDialog.spec.ts`,
`AcceptApplicationDialog.spec.ts`, `campaigns.api.spec.ts`, locale parity. Playwright: new
`creator-brand-exclusivity.spec.ts`; `creator-jobs-board.spec.ts` and
`jobs-board-full-lifecycle.spec.ts` traverse the job detail, so the full suite runs before push.

## 13. Rulings (Pedram, 2026-11-11)

| Q   | Ruling                                                                                                                                                                             |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Q1  | (a): `live_verified` and `manually_verified` also end the no-date block; `revision_requested` counts as active. (`payment_held` follows `live_verified` and is classed completed.) |
| Q2  | Yes: a cancelled or completed blocking campaign ends its block (reading in §4: cancelled always; completed only when no date is set).                                              |
| Q3  | Exclusivity before region.                                                                                                                                                         |
| Q4  | Own brand refused; archived brands not selectable; an already-listed brand stays blocked after archiving.                                                                          |
| Q5  | UTC calendar date, inclusive to end of day; a past date is refused on save; clearing allowed.                                                                                      |
| Q6  | Detail only.                                                                                                                                                                       |
| Q7  | Skip acknowledgement metadata.                                                                                                                                                     |
| Q8  | Accepting an existing invitation or a re-offer is never blocked.                                                                                                                   |
| Q9  | Pending rows only; the blocking campaign is named to the agency.                                                                                                                   |
| Q10 | Hold build 5 until AH-120 lands.                                                                                                                                                   |
| Q11 | Cap 50 brands.                                                                                                                                                                     |
| Q12 | Flag `campaign_brand_exclusivity_enabled`, armed after the web deploy.                                                                                                             |
| —   | Pivot table as proposed; classification on the enum with the every-case spec.                                                                                                      |

## 14. Production posture

- **Migration:** creates one table and adds one nullable column. Existing rows are neither read nor
  rewritten; every campaign reads "no exclusivity", which is today's behaviour.
- **Feature writes:** pivot rows and the date, only on a campaign save with the flag ON.
- **Blast radius:** a predicate bug wrongly blocks (or fails to block) a creator from applying;
  nothing else changes. Flag OFF restores today's behaviour instantly, including for stored lists.
- **Deploy:** snapshot, migrate (one migration set), API, flag OFF verified, web, then the flag.
  Stacked with AH-115 (Q10).
