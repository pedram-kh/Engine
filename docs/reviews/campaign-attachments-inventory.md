# Campaign attachments from the Files library — read-only inventory (chunk B of two)

- **Status:** Inventory only. No production code, schema, API, UI, or production data was changed.
- **Date:** 2026-08-27.
- **PROD-DATA RISK: NONE** — this pass read repository state and source only. The requested review
  document is the sole repository artifact.
- **Expected eventual build risk: ⚠️ MEDIUM** — this is the first route by which an agency-owned
  object can cross into a creator response. The database change is additive; the risk is the
  authorization and signed-read boundary.
- **HEAD:** `91212092938ed5ac6f4a579db200a9208bf6126a` (`91212092`), exactly equal to
  `origin/main` when this inventory began. The subject is
  `docs(reviews): AH-091 — cite the green CI run at the pushed tip`.
- **Fresh-session orientation through AH-091:** chunk A shipped a standalone, agency-scoped Files
  library with paginated list, presigned upload, forced-download signed reads, object-first delete,
  and no campaign or creator linkage; `hasAttachments()` is the deliberately inert seam for this
  chunk (`docs/reviews/agency-files-review.md:26-34`). Its storage keys are fenced under
  `agencies/{agency_ulid}/files/`, and complete/delete re-assert that prefix
  (`apps/api/app/Modules/Agencies/Services/AgencyFileUploadService.php:109-147,171-190`).

## Locked product boundary

An agency attaches an existing row from its Files library to a campaign. Agency members see the
attachment on that campaign. Each attachment has an explicit **Visible to applicants** switch,
default **OFF**. A creator receives no file metadata and no signed URL while it is off. When it is
on, access is campaign-scoped through one of two existing creator doors: ownership of an assignment
on the campaign, or current eligibility for the campaign through the Jobs Board predicate. A Jobs
Board application is not a third grant. No anonymous/public route is introduced.

This preserves AH-051's distinction between a current roster/contact relationship and an existing
campaign engagement: contact details became roster-only, while campaign assignments deliberately
survive disconnect (`docs/reviews/adhoc-changes-log.md:2236-2244`). It also preserves AH-059's
application/assignment split: creator job surfaces carry the caller's application state and, when
one exists, the assignment state/bridge; the assignment wins as the current engagement
(`docs/reviews/adhoc-changes-log.md:1716-1725,1755-1768`).

## The three shaping answers

1. **B3 — enforcement cannot live in one existing call site.** The boolean filter and attachment
   projection should each have one shared implementation, but the outer campaign authorization has
   two genuinely different server-side gates: assignment ownership for the assignment detail, and
   `JobsBoardVisibility` for the job detail/application flow. Each emitting surface must compose
   its gate with the shared `visible` filter before a URL is minted. A frontend-only check, or a
   call to the current signer with an arbitrary `AgencyFile`, is not enforcement.
2. **B1 — `agency_file_id` must be `RESTRICT`, not cascade.** Cascade would contradict chunk A's
   explicit “attached means delete returns 409” contract by silently erasing attachment rows during
   a hard delete. `campaign_id` should cascade because a join row has no meaning after a campaign is
   hard-deleted. Important limitation: normal `AgencyFile` deletion is a soft-delete `UPDATE`, so
   the FK does not fire; `hasAttachments()` remains the live guard and the FK is only the hard-delete
   backstop.
3. **B4 — a visible attachment on a listed job is for every current Jobs Board viewer, not only
   applicants.** Today that means an authenticated, approved creator on the campaign agency's
   roster whose relation permits messaging, viewing a listed/non-terminal/unexpired campaign, with
   no active hard blacklist from its brand. Application status is not a predicate leg. The board
   is creator-facing, not anonymous: its route group requires `auth:web`, `tenancy.set`, and
   `verified` (`apps/api/app/Modules/Creators/Routes/api.php:83-86`).

## B1 — The join

### Shape

The clean shape is a real `campaign_attachments` join model/table, not
`campaigns.brief.attachments`. The old brief field is an untyped JSON-array slot
(`apps/api/database/migrations/2026_06_05_100000_create_campaigns_table.php:23-24,69-70`;
`packages/api-client/src/types/campaign.ts:52-65`): it cannot enforce file FKs, one-row-per-file,
per-attachment visibility, attribution, or deletion integrity.

The join needs:

- surrogate `id`; add a `ulid` only if routes address the attachment row itself rather than the
  nested `(campaign, agency_file)` pair;
- `campaign_id`, FK to `campaigns.id`, `cascadeOnDelete`;
- `agency_file_id`, FK to `agency_files.id`, `restrictOnDelete`;
- one boolean, default `false`, implementing the locked creator-visibility opt-in. If persisted as
  `visible_to_applicants`, its contract must say that it also covers assigned creators; a name such
  as `visible_to_creators` is semantically less narrow than the UI label;
- nullable `attached_by_user_id`, FK to `users.id`, `nullOnDelete`;
- `created_at` and `updated_at`;
- unique `(campaign_id, agency_file_id)`, making attach idempotent and preventing two visibility
  states for the same file on the same campaign; and
- a separate index led by `agency_file_id`. The pair unique is led by `campaign_id` and is not the
  right index for chunk A's file-centric `hasAttachments()` lookup.

No denormalized `agency_id` is required for the current shape: both parents already carry the
tenant and the attachment is only queried through one of them. That makes the attach write's
same-agency check load-bearing, however. It must resolve both campaign and file under the path
agency (and 404 a mismatch); two independent FKs do not prevent an agency-A file from being linked
to an agency-B campaign.

The canonical pivot to copy is `talent_pool_creators`: first-class model, surrogate PK, attribution
with `nullOnDelete`, timestamps, pair uniqueness, and explicit FK rules
(`apps/api/database/migrations/2026_06_03_100003_create_talent_pool_creators_table.php:12-29,35-62`).
The attachment-specific departure is the file-side `RESTRICT`.

### Ordering

There is no locked reorder interaction. Do not manufacture a `position` contract and its reorder
API: attach order can be deterministic as `created_at`, then `id`. If manual ordering becomes a
product requirement, `position` belongs in the table and needs concurrency/reorder tests; it should
not be an unused column. This matches the pivot precedent, which has timestamps but no position
(`apps/api/database/migrations/2026_06_03_100003_create_talent_pool_creators_table.php:50-62`).

### Delete constraint and the `hasAttachments()` seam

`AgencyFile::hasAttachments()` currently returns false and explicitly says chunk B must replace it
with the real query (`apps/api/app/Modules/Agencies/Models/AgencyFile.php:76-87`). It must ask one
question only:

> Does any current `campaign_attachments` row exist with
> `campaign_attachments.agency_file_id = $this->id`?

It must **not** filter by creator visibility, campaign listing state, campaign status, or campaign
soft-deletion. An OFF attachment is still an attachment; cancelled/unlisted campaigns still own
their configuration; a soft-deleted campaign may be restored. A hard-deleted campaign has already
cascaded its join rows away.

The existing delete endpoint checks the seam before deleting the S3 object and returns
`409 agency_file.attached` (`apps/api/app/Modules/Agencies/Http/Controllers/AgencyFileController.php:143-167`).
That makes `restrictOnDelete` the only FK posture consistent with the shipped API. By comparison,
campaign applications use campaign-side cascade because they cannot outlive the parent campaign
(`apps/api/database/migrations/2026_07_27_110000_create_campaign_applications_table.php:42-44,69-79`).

There is one caveat the FK cannot solve: `AgencyFile` soft deletion only sets `deleted_at`
(`apps/api/database/migrations/2026_08_27_130000_create_agency_files_table.php:39-45,82-83`).
Therefore a concurrent attach racing between `hasAttachments()` and the object delete could still
create a dangling attachment unless attach/delete serialize on the file row or otherwise close
that race. `RESTRICT` protects force-delete and migration/maintenance paths; it is not a substitute
for the application guard.

## B2 — Library-side reality

### The picker cannot consume the shipped index

The AH-091 index is a server-paginated table feed: default 25, capped at 100, newest first
(`apps/api/app/Modules/Agencies/Http/Controllers/AgencyFileController.php:45-70`). Its resource is
also too heavy for a picker because every row mints a signed download URL and includes uploader
details (`apps/api/app/Modules/Agencies/Http/Resources/AgencyFileResource.php:12-18,32-54`).

The third-instance rule therefore applies directly: `GET .../files?for=select` should switch the
same route to a complete, unpaginated, thin projection. The convention explicitly forbids a picker
from reading a paginated index or using an inflated `per_page`
(`docs/02-CONVENTIONS.md:558-567`). Its two proof obligations are a completeness test beyond the
default page boundary and explicit documentation of exclusions
(`docs/02-CONVENTIONS.md:569-577`).

For this picker, the projection needs the file ULID, original filename, MIME type, and size. It must
exclude `storage_key`, `download_url`, and uploader details; choosing a file is not permission to
mint a read URL. It should include only non-soft-deleted rows usable as campaign attachments.
Omitting `?for=select` must leave the existing paginated Files page byte-for-byte unchanged.

### Reuse and lifecycle

A file should be attachable to multiple campaigns. The proposed uniqueness is per
`(campaign_id, agency_file_id)`, not globally on `agency_file_id`. That is also the product value of
a library: reuse one uploaded document without copying bytes. Detaching it from one campaign
hard-deletes only that join row; the library row, S3 object, and other campaigns' joins remain.

Lifecycle outcomes:

- **Library file soft-delete:** the supported endpoint must refuse while any join exists. If a row
  is nevertheless soft-deleted by a race/manual write, every campaign serializer must fail closed:
  no signed URL and no creator-readable attachment. The normal `belongsTo` SoftDeletes scope helps,
  but the invariant should be tested rather than treated as cleanup.
- **Campaign cancellation/completion:** these are status transitions, not row deletes. Keep all
  joins. The Jobs Board scope hides terminal campaigns while preserving the agency's listing intent
  (`apps/api/app/Modules/Campaigns/Models/Campaign.php:78-86,223-246`); the agency record and assigned
  creator's engagement remain.
- **Campaign soft-delete:** keep joins so restore is coherent. Campaigns use `SoftDeletes`
  (`apps/api/app/Modules/Campaigns/Models/Campaign.php:70-76`).
- **Campaign hard-delete:** cascade-delete joins, never the reusable `agency_files` row or S3
  object.

## B3 — Creator read paths

### Assigned creator: assignment detail

The route is `GET /creators/me/assignments/{assignment}`. There is no PHP
`CreatorAssignmentDetailResource`: the controller constructs the assignment envelope inline,
including campaign data (`apps/api/app/Modules/Creators/Http/Controllers/CreatorAssignmentDraftController.php:75-168`).
The TypeScript name is only the client contract
(`packages/api-client/src/types/campaign.ts:485-518`).

Authorization is structural. The controller resolves the authenticated creator, drops the ambient
agency scope, then finds the assignment by **both** `creator_id` and assignment ULID; a non-owner
gets 404 (`apps/api/app/Modules/Creators/Http/Controllers/CreatorAssignmentDraftController.php:530-555`).
This path does not consult current roster status, blacklists, or Jobs Board listing state. That is
correct under the locked “assigned via the campaign” grant and AH-051's rule that disconnect does
not erase campaign assignments.

After that ownership check, the response may query the assignment's `campaign_id` for attachments,
apply the shared creator-visible scope, and serialize only active files. Attachments must not be
added to the flat assignment list: the detail is the campaign-context read surface named by the
feature.

### Jobs Board list and detail

The list is serialized by `CreatorJobCardResource`, whose deliberately narrow shared card block is
used by both list and detail (`apps/api/app/Modules/Creators/Http/Resources/CreatorJobCardResource.php:65-105`).
Keep attachment arrays and signed URLs off cards. A 25-row board should not mint every campaign's
document URLs, and the card's exact-keyset boundary is intentionally narrow.

The detail is serialized by `CreatorJobDetailResource`, extending that card with the campaign
description, listing fields, assignment bridge, and one additional brand field
(`apps/api/app/Modules/Creators/Http/Resources/CreatorJobDetailResource.php:10-50,54-82`). This is
the Jobs Board surface that should carry the visible attachment projection.

Both endpoints build from `JobsBoardVisibility::visibleTo()`. The service is explicitly the single
campaign-visibility source for list, detail, apply, and fan-out
(`apps/api/app/Modules/Campaigns/Services/JobsBoardVisibility.php:18-27,78-88`), and the detail
re-applies it by ULID before serialization
(`apps/api/app/Modules/Creators/Http/Controllers/CreatorJobBoardController.php:153-189`).
Attachment visibility is an additional, nested filter; it does not replace or weaken that campaign
predicate.

### Application flow

The Apply dialog already lives on the authorized job-detail page and posts the campaign ULID. The
server re-validates `JobsBoardVisibility` immediately before insert
(`apps/api/app/Modules/Creators/Http/Controllers/CreatorJobBoardController.php:192-231`). Its 201
response is an application row only
(`apps/api/app/Modules/Creators/Http/Controllers/CreatorJobBoardController.php:291-301`).

Therefore the application flow needs no third attachment query and should not add URLs to
`CreatorJobApplyResponse`. If the dialog repeats attachments for UX, it must render the same
already-fetched detail payload. Applying does not create durable file access: if the campaign is
later delisted, the roster relation ends, or a qualifying blacklist appears, the job detail is 404
even though the application row remains.

### Can the visibility enforcement be one place?

Partly:

- one `CampaignAttachment` scope/relation should define “creator visible” as flag ON plus a live
  (non-soft-deleted) `AgencyFile`;
- one narrow creator attachment presenter/resource should own the exact metadata keyset and URL
  minting; and
- every creator response should consume those shared pieces.

But the outer authorization cannot be one generic static signer. Assignment detail grants by
assignment ownership; job detail grants by the six-leg Jobs Board predicate. Those gates must
remain on their respective server surfaces. The application dialog inherits the latter and does
not create another gate. The safe composition is **outer campaign gate, then shared attachment
filter, then signed URL**.

### Signed URL reality

`AgencyFileUploadService::signedDownloadUrl()` is not, despite the premise in the kickoff,
agency-prefix-asserting. It accepts only an `AgencyFile` and directly signs its `storage_key`
(`apps/api/app/Modules/Agencies/Services/AgencyFileUploadService.php:150-168`). Prefix checks exist
on upload completion and delete, not this read method
(`apps/api/app/Modules/Agencies/Services/AgencyFileUploadService.php:116-147,179-190`). Agency-side
safety currently comes from controller tenancy/ownership and policy checks before
`AgencyFileResource` invokes the signer
(`apps/api/app/Modules/Agencies/Http/Controllers/AgencyFileController.php:135-146`;
`apps/api/app/Modules/Agencies/Http/Resources/AgencyFileResource.php:32-40`).

The method works mechanically for the same private object regardless of caller, but it authorizes
nothing. A creator path therefore needs a sibling presenter/gateway (or a factored low-level signer
behind one) that can only be reached after the assignment/jobs-board gate and ON-flag check. Calling
the current public method directly from a creator resource would make a future missed filter a
data leak.

Mint at creator resource serialization, matching the existing emission-scoped attachment pattern:
the assignment resource mints a URL only inside an already-authorized row
(`apps/api/app/Modules/Campaigns/Http/Resources/CampaignAssignmentResource.php:67-84`). Never expose
the raw `storage_key`.

One unavoidable current-architecture fact must be accepted or redesigned: a signed GET already
issued remains usable until its 60-minute expiry even if the agency turns visibility OFF a moment
later. OFF can stop future minting, not revoke an S3 presign. Strict immediate revocation would
require a shorter TTL or an application-mediated download rather than the existing direct-S3
pattern.

## B4 — Jobs Board exposure

### The real audience

`JobsBoardVisibility` currently admits only:

1. an approved creator;
2. whose agency relation is roster + permits messaging (therefore neither hard nor soft
   agency-wide blacklisted);
3. where the campaign flag is ON and status is `draft`, `active`, or `paused`;
4. whose campaign has not expired (`ends_at` null or at/after UTC start-of-today); and
5. who is not actively hard-blacklisted by the campaign's brand.

The implementation and rationale are explicit at
`apps/api/app/Modules/Campaigns/Services/JobsBoardVisibility.php:29-69,88-130`.
Brand-soft-blacklisted viewers remain eligible; agency-relation soft blacklists do not. Applicants
and assignees are not extra predicate legs. Thus an ON attachment is readable from job detail by
every creator who can see that listing, whether never applied, pending, accepted, or rejected.

An assignee has a second, stronger path through their own assignment. If the roster relation later
ends, a non-assigned applicant loses the Jobs Board read; an assigned creator still owns the
assignment detail. That is the concrete consequence of “campaign-scoped” across the two existing
authorization chains.

### Required §5.34 partitions

AH-056's current seven-case negative set is: caller not approved; no agency relation; relation not
roster; listing flag OFF; terminal campaign; expired campaign; active brand-hard-blacklist
(`apps/api/tests/Feature/Modules/Creators/CreatorJobBoardTest.php:59-135`). Agency-wide hard and
soft relation blacklists are additionally pinned as hidden, while brand-soft is pinned visible
(`apps/api/tests/Feature/Modules/Creators/CreatorJobBoardTest.php:159-187`). The test header states
that the same cases are rerun for detail, apply, and fan-out
(`apps/api/tests/Feature/Modules/Creators/CreatorJobBoardTest.php:22-36`).

Chunk B's attachment set must distinguish:

- **attached + ON + listed + otherwise eligible:** campaign detail returns the attachment and a
  signed URL;
- **attached + OFF + listed + otherwise eligible:** campaign remains visible, but attachment
  metadata and URL are absent;
- **attached + ON + unlisted:** Jobs Board list is empty and detail/apply are flat 404; the file is
  not independently probeable. An assignment owner may still read it through assignment detail;
- **non-rostered viewer:** empty list/404 through Jobs Board. Again, a real assignment is a separate
  owner grant, not a bypass;
- **agency relation hard or soft blacklist:** empty list/404;
- **brand hard blacklist:** empty list/404;
- **brand soft blacklist:** campaign and ON attachment remain readable, matching the existing
  warn-at-invite posture; and
- **soft-deleted file or campaign:** no attachment URL; a soft-deleted campaign is already excluded
  by the retained SoftDeletes scope
  (`apps/api/tests/Feature/Modules/Creators/CreatorJobBoardTest.php:224-228`).

The OFF case must be asserted by exact attachment keyset/count, not merely by hiding a frontend
link while the signed URL remains in JSON.

## B5 — Agency UI

The current campaign detail has these tabs in order: Overview, Creators, Board, Drafts,
Applications, Payments, Messages, and Settings (Settings only for editors)
(`apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue:495-523`). Attachments are a
collection with attach/detach and visibility controls, so the natural surface is a dedicated
**Attachments** tab immediately after Overview, rather than growing the summary list or hiding
security controls in Settings.

Read should follow `CampaignPolicy::view` for every active agency member; attach, detach, and
visibility changes are campaign configuration and should follow `CampaignPolicy::update`
(admin/manager), not the broader staff “execute” abilities
(`apps/api/app/Modules/Campaigns/Policies/CampaignPolicy.php:34-52,54-82`). The tab can remain
visible/read-only for staff.

The attach action opens a picker dialog backed by `files?for=select`, allowing one or more existing
library rows. Already-attached rows should be unavailable or treated idempotently by the unique
pair. An empty library should point the agency to Files; this dialog should not silently become a
second upload pipeline.

Each attached row should show filename, MIME/size, attach attribution/date, download, detach, and
an explicit switch labelled **Visible to applicants**. The switch starts OFF from the database
default, has no campaign-wide inheritance, and needs helper copy stating the real audience:
eligible Jobs Board viewers and creators assigned to this campaign. Turning a listing on must
never turn attachment visibility on automatically.

Attachment management belongs only on campaign detail. Create has no campaign identity until its
first write, while edit/Settings is already crowded with lifecycle and listing gates. Keeping one
management surface also avoids two clients racing or drifting on attachment state.

## B6 — Ripple

### Tenancy documentation and authorization tests

Agency attachment routes nested under
`/agencies/{agency}/campaigns/{campaign}/attachments` use the ordinary
`auth:web → tenancy.agency → tenancy` campaign stack
(`apps/api/app/Modules/Campaigns/Routes/api.php:19-41`). Like the Applications tab, they are not
cross-tenant allowlist entries merely because they are new
(`apps/api/app/Modules/Campaigns/Routes/api.php:51-54`).

Creator emission expands two already-allowlisted cross-tenant reads:

- the creator-owned assignment detail row at `docs/security/tenancy.md:171`; and
- the Jobs Board list/detail rows at `docs/security/tenancy.md:183-185`.

Those §4 descriptions should be amended to name the attachment join, ON-flag filter, raw-key
withholding, and emission-scoped signing. If implementation chooses a separate creator download
endpoint rather than embedding signed URLs, that new cross-tenant route needs its own §4 row and
the same assignment-or-Jobs-Board gate; it cannot be authorized by file ULID alone.

Required API proofs include same-agency parent matching, cross-agency 404, role matrix, duplicate
attach idempotency, default OFF, independent per-campaign visibility for one reused file,
detach-with-other-campaign-reference, both creator gates, exact OFF omission, and
`hasAttachments() → 409`. The existing Jobs Board seven-case set must remain green and be applied
to the attachment-bearing detail shape.

### API-client and resource types

Expected type ripple:

- `packages/api-client/src/types/agency.ts`: thin `AgencyFileOptionResource` and the
  `for=select` response/params beside the existing Agency Files contracts
  (`packages/api-client/src/types/agency.ts:959-1041`);
- `packages/api-client/src/types/campaign.ts`: `CampaignAttachmentResource`, attach/toggle/detach
  contracts, agency attachment-list response, and the visible attachment projection on
  `CreatorAssignmentDetailResource` and `CreatorJobDetailResource`;
- no attachment field on `CreatorJobCardResource`; and
- no attachment field on `CreatorJobApplyResponse`, whose present contract is application-only
  (`packages/api-client/src/types/campaign.ts:899-979`).

Prefer a separate agency attachment endpoint/resource over adding attachments to
`CampaignResource`: that resource is reused by campaign lists and create/update responses
(`apps/api/app/Modules/Campaigns/Http/Controllers/CampaignController.php:63-75,131-150,270-272`),
which would otherwise load or mint attachments on unrelated surfaces.

### i18n ×24

The agency tab, empty state, picker, detach confirmation, visibility label/helper, success/error
copy, and attachment metadata labels belong in the campaign/app bundle across all 24 locales. The
creator attachment heading/download/error copy belongs in `creator.json` across all 24 because
both assignment detail and job detail live there. Existing generic Files strings may be reused;
new English-only leaves are not acceptable. Locale keyset, placeholders, and plural forms are
architecture-tested (`docs/02-CONVENTIONS.md:590-596`).

### E2E exposure

The existing legs are not equivalent:

- C3's `creator-jobs-board.spec.ts` clicks a card, renders creator job detail, opens Apply, submits,
  and re-reads the page (`apps/main/playwright/specs/creator-jobs-board.spec.ts:81-141`).
- C4's `campaign-applications.spec.ts` is agency-side only; it seeds pending applications directly
  and opens the campaign Applications tab. It does **not** traverse creator job detail
  (`apps/main/playwright/specs/campaign-applications.spec.ts:11-31,43-77`).
- C5's `jobs-board-full-lifecycle.spec.ts` traverses creator job detail before apply and again after
  the application becomes an assignment
  (`apps/main/playwright/specs/jobs-board-full-lifecycle.spec.ts:142-164,217-240`).

After the UI change, an attachment component would render on the C3/C5 detail visits only if their
seed helpers create an attached ON file. Today they do not, so those legs can stay green while the
feature is absent or leaking OFF URLs. At least one real-byte creator download assertion plus an
OFF omission is needed; C4 can cover agency attach/toggle if its seed/flow is extended, but it is
not a creator-read proof.

### Deploy

One additive migration is expected for `campaign_attachments`; no backfill, new disk, bucket,
prefix, secret, feature flag, or object copy is implied. Files remain under the shipped private
`agencies/{agency}/files/` prefix (`docs/reviews/agency-files-review.md:196-203`). The migration's
`down()` deletes join metadata only and must not delete reusable `agency_files` rows or S3 objects.
Normal API/worker reload and main-SPA deployment still apply, and the deploy log/data-model/tenancy
documents must describe the new creator-read boundary.
