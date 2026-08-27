# Campaign attachments from the Files library — Chunk B: Plan (plan-pause)

- **Status:** Plan-pause. Awaiting Pedram's clearance. **No code written.**
- **Entry:** AH-092 (confirmed free — AH-091 is the latest ad-hoc entry, `adhoc-changes-log.md:73`).
- **Date:** 2026-08-27.
- **Author:** Cursor, against the kickoff's decisions (D1–D6) and the read-pass below.
- **HEAD at plan time:** `b9e0af4d` (`docs(reviews): campaign attachments inventory`), one commit
  ahead of `origin/main`'s `91212092`. The inventory commit is held, not yet pushed — the same
  push-hold posture AH-091 used. Working tree clean.
- **Reads from:** [`campaign-attachments-inventory.md`](campaign-attachments-inventory.md) (this
  chunk's own inventory — re-verified below, two wording reconciliations, zero reversals),
  `docs/02-CONVENTIONS.md` §3.5, `docs/security/tenancy.md` §4, the `TalentPools` module (nested-
  addressing precedent), `docs/reviews/agency-files-plan.md`/`-review.md` (AH-091 shapes this chunk
  extends).
- **No load-bearing catch this time.** Unlike the draft-posting-toggle plan's Q1, nothing here is
  irreversible: every write this chunk makes is on rows it creates itself (`campaign_attachments`),
  and the one open item worth Pedram's ruling (§7 Q1, a narrow attach/delete race) has a safe
  default — name it as debt, do not block the chunk on closing it.

---

## §0. The PROD-DATA RISK line, re-derived

> **⚠️ PROD-DATA RISK: MEDIUM** — the kickoff's estimate holds, and holds for the reason the kickoff
> named, not for data-mutation reasons.
>
> **What touches rows that already exist:** nothing is altered. The one migration is `CREATE TABLE
campaign_attachments` — additive-only, empty at deploy, no column added to `campaigns` or
> `agency_files`. `AgencyFile::hasAttachments()`'s body changes from `return false` to a real query,
> which is an application-logic change with zero schema footprint; its observable effect (a 409 on
> delete) is silent until the first row exists, because chunk A shipped no attachments to guard.
>
> **What is genuinely new:** this is the first code path by which an agency-owned object's metadata
> and a signed S3 GET URL can reach a **creator**-facing HTTP response. That is an authorization-
> surface risk, not a data-corruption one — pinned by the inventory's own framing
> (`campaign-attachments-inventory.md:7-9`) and unchanged by this read pass. A wrong composition of
> gate + visibility filter is a **confidentiality** bug (an OFF attachment's filename/URL appearing
> where it should not), bounded to exactly two emitting call sites (assignment detail, job detail —
> D4), each independently gated and each getting an explicit exact-keyset negative test (§5).
>
> **The one accepted, named limitation:** a signed GET, once minted, remains valid for its TTL
> (60 minutes, reusing `AgencyFileUploadService::signedDownloadUrl()`'s existing constant — D5's "TTL
> posture") even if the agency flips visibility OFF a second later. OFF stops future minting; it does
> not revoke an outstanding S3 presign. This is the same fact the inventory already recorded
> (`campaign-attachments-inventory.md:266-271`) and this plan makes no attempt to shorten the TTL or
> add revocation — that would be a different, larger chunk.
>
> **Zero row deletion, zero row mutation, on any pre-existing table.** `campaigns` and `agency_files`
> rows are only ever read (joined) by this chunk's code; the only rows it writes are
> `campaign_attachments` rows it also creates. The one delete this chunk enables (the 409 guard
> `agency_file.attached`) makes a delete **less** likely to succeed, not more.
>
> **`down()` honesty.** `dropIfExists('campaign_attachments')` is a true structural and content
> inverse — the table is new, so a rollback loses only this chunk's own attach/visibility history,
> never a pre-existing document or campaign. Stated in the migration's own docblock.
>
> **Pre-deploy snapshot:** standard house obligation, nothing added beyond it — no destructive DDL, no
> backfill, no flag.

What is **not** in this chunk's risk: no type narrowing, no backfill, no flag armed by default, no
new bucket/prefix/disk (D6), no historical row touched.

---

## §1. Read-pass — reconciliations against the inventory (nothing overturned)

The kickoff's D1–D6 already absorbed the inventory's B1–B6 findings correctly. Re-verifying at
`b9e0af4d` found **two wording reconciliations, zero factual reversals**:

1. **The picker's "instance count."** The inventory calls it "the third-instance rule"
   (`campaign-attachments-inventory.md:138`); the kickoff calls this "the fourth instance." Re-reading
   `AgencyOptionResource`'s own docblock resolves the discrepancy: it names three prior times the
   **decision procedure** was invoked — "AH-066, AH-085, and this dialog" (AH-090) —
   (`apps/api/app/Modules/Admin/Http/Resources/AgencyOptionResource.php:25-27`), of which AH-066 chose
   the _paginated-with-named-per-page_ exception and AH-085/AH-090 chose `?for=select`. This chunk is
   therefore the **fourth invocation of the decision procedure**, matching the kickoff's count exactly
   — the inventory's "third" was counting only shipped `?for=select` instances (Brand, Agency), not
   the procedure's own AH-066 precedent. No reversal; the kickoff's number is the one to keep, and the
   disposition (thin unpaginated projection) is unaffected either way.
2. **`hasAttachments()`'s "any non-deleted join row."** The kickoff's phrasing anticipates a
   soft-deletable pivot. D3 (confirmed below, §2.3) makes detach a **hard** delete with no
   `deleted_at` column on `campaign_attachments` at all — so "non-deleted" is vacuously every row, and
   the query collapses to a plain existence check:
   `CampaignAttachment::query()->where('agency_file_id', $this->id)->exists()`. Recorded so a future
   reader does not go looking for a soft-delete scope that was never built.

Everything else — the `RESTRICT` FK posture, the `visible_to_creators` column name with the
"Visible to applicants" UI label, the `campaign_id` cascade, the unique pair, the agency-file-led
index, the two-door creator composition, the card/apply-response exclusions, the §5.34 case list — is
confirmed unchanged by this pass. `AgencyFile::hasAttachments()`
(`apps/api/app/Modules/Agencies/Models/AgencyFile.php:76-87`) and
`AgencyFileController::destroy()` (`apps/api/app/Modules/Agencies/Http/Controllers/AgencyFileController.php:143-167`)
are exactly as the inventory found them; no drift since chunk A.

---

## §2. Per-decision dispositions

### 2.1 D1 — the join table: CONFIRMED verbatim, plus the ulid ruling the kickoff asked for

Migration `create_campaign_attachments_table` (additive):

- surrogate `id` (bigint, auto-increment) — **no `ulid` column.** See §2.1.1.
- `campaign_id`, FK → `campaigns.id`, `cascadeOnDelete()`.
- `agency_file_id`, FK → `agency_files.id`, `restrictOnDelete()`.
- `visible_to_creators` (boolean, `default(false)`) — the column name is deliberately broader than
  the UI label; its docblock states it also gates assigned-creator access, not only applicant access.
- `attached_by_user_id` (nullable, FK → `users.id`, `nullOnDelete()`).
- `timestamps()`. No `deleted_at` (§2.3 — detach is a hard delete).
- unique `(campaign_id, agency_file_id)` — named `unique_campaign_attachment`.
- index led by `agency_file_id` alone — named `idx_campaign_attachments_agency_file_id` — the
  `hasAttachments()` lookup's index, distinct from the pair-unique which is `campaign_id`-led.

`CampaignAttachment` model: **plain `Model`, not `Pivot`** — the `CampaignApplication` precedent
(`apps/api/app/Modules/Campaigns/Models/CampaignApplication.php:58`), not the `TalentPoolMembership`
one, because this row is independently _addressed and serialized_ (its own resource, its own
toggle/detach actions), which is exactly why `CampaignApplication` is a first-class model rather than
a `Pivot` subclass. `campaign(): BelongsTo`, `agencyFile(): BelongsTo`, `attachedBy(): BelongsTo`
(nullable). `Campaign::attachments(): HasMany`. `AgencyFile::campaignAttachments(): HasMany` (the
relation `hasAttachments()` queries). A `scopeCreatorVisible()` on the model — see §2.4.

**No `agency_id` column** — confirmed from the inventory (B1): both parents already carry the tenant,
and the attach-write's same-agency structural check (§2.2) is what actually prevents an agency-A file
linking to an agency-B campaign; a denormalized column would not add safety, only a maintenance
burden.

#### 2.1.1 The ulid question — answered: no ulid, nested-address by the file's own ulid

D1 asks this to be proposed, not guessed, because chunk A left it genuinely open. The house has two
live precedents for a join row that a route must address:

- `talent_pool_creators` — **no ulid**, addressed as
  `DELETE talent-pools/{talentPool}/creators/{creator}`: the pivot's own identity is never in the URL,
  only the two parents' identities are
  (`apps/api/app/Modules/TalentPools/Routes/api.php:46`).
- `campaign_applications` — **has a ulid** (`use HasUlid;`,
  `apps/api/app/Modules/Campaigns/Models/CampaignApplication.php:65`), addressed by its own identity
  (`campaigns/{campaign}/applications/{application}/accept`), because it carries an independent status
  machine (pending/accepted/rejected/…) that outlives any single "attach" act.

`campaign_attachments` has no independent lifecycle of its own — it is binary (exists, and one
boolean), the same shape as pool membership, not the richer application shape. **Recommendation: the
`talent_pool_creators` shape.** Address the row as
`{campaign}/attachments/{file}`, where `{file}` implicit-binds `AgencyFile` by its existing
`ulid` route key (`HasUlid::getRouteKeyName()`, already in use platform-wide) — no ulid is added to
`campaign_attachments` at all. This also answers what the resource's `id` field is: the **file's**
ulid, because that is the only identity a client ever needs to address this row (the `campaign_id` is
already the route's parent segment). If a future need arises for the join row to have a life
independent of its file (e.g., a comment thread on the attachment), a ulid can be added additively
then — deferred, not foreclosed.

### 2.2 D2 — the AH-091 seam: CONFIRMED, the 409 test lands, the race is named (§7 Q1)

`AgencyFile::hasAttachments()`'s body becomes (per §1's collapse): a plain `exists()` query on the new
`campaignAttachments()` relation, unconditional on visibility, campaign status, or campaign
soft-deletion — exactly the inventory's rule (`campaign-attachments-inventory.md:104-114`). No caller
changes; `AgencyFileController::destroy()`'s existing `if ($file->hasAttachments())` branch
(`:148-155`) goes from permanently-unreachable to a real, tested 409 the moment any attachment exists.
The deferred test named in the AH-091 plan (`agency-files-plan.md:180-183`) is written this chunk.

**The unreachable-state consequence, restated as the kickoff asks:** because the 409 guards every
delete, "an attachment pointing at a soft-deleted `AgencyFile`" cannot occur through the API — the
delete that would soft-delete the file is refused first whenever an attachment exists. The creator-
visible scope's `whereHas('agencyFile')` (implicitly excluding soft-deleted files, via
`AgencyFile`'s global `SoftDeletes` scope) is therefore **defence-in-depth against a state the guard
already prevents**, not a reachable branch — it earns a unit-level test on the scope in isolation
(mock a soft-deleted file behind a real attachment row, bypassing the guard directly at the model
layer) but not a feature-level "how did this happen" test, because nothing in the shipped API can make
it happen. This is stated once here rather than re-argued at build time.

The one gap the FK cannot close — a concurrent attach racing the file's soft-delete `UPDATE` — is
real and is **not** solved by this chunk's default shape; it is named and ruled on in §7 Q1.

### 2.3 D3 — agency side: CONFIRMED — tab placement, authorization, and hard-delete detach

**Placement:** a dedicated **Attachments** tab, immediately after Overview, before Creators — per the
inventory's read of the current tab order (`CampaignDetailPage.vue:495-523`). Rejected: growing
Overview's summary list (attachments are a managed collection with three per-row actions, not a
summary fact) and a Settings-adjacent section (Settings is access-gated to editors only, and read
access to attachments should follow the broader `view` ability like every other tab).

**Authorization, confirmed on the read pass:** `CampaignPolicy::view` (any active member) gates the
tab's **read** (`GET .../attachments`); `CampaignPolicy::update` (admin + manager,
`CampaignPolicy.php:49-52`) gates **attach, toggle, and detach** — all three are campaign-management
acts on the linkage, not library acts on the file (the library's own delete matrix, `AgencyFilePolicy`,
is untouched and continues to govern the file itself). No new policy class: the controller calls
`Gate::authorize('view'|'update', $campaign)` directly, the same shape `CampaignController` already
uses for its own read/write split.

**Detach = hard-delete the join row.** Confirmed — it is a linkage, not content; the file, its S3
object, and any other campaign's attachment of the same file are untouched. `$attachment->delete()`,
audited, no soft-delete column exists to make it anything else (§2.1).

**The attach-write's structural same-agency check (load-bearing, per the inventory, B1):** the
controller resolves `{campaign}` under the path `{agency}` (existing tenancy middleware), then
resolves the `agency_file_id` from the request body and explicitly asserts
`$file->agency_id === $agency->id` **before** creating the row — the `AgencyFileController`
precedent verbatim (`AgencyFileController.php:182-187`) — 404 on mismatch, never 403, never a
422 that would let an operator fingerprint another agency's file ulids.

**Idempotent attach**, the `TalentPoolMembershipController::store()` shape verbatim
(`TalentPoolMembershipController.php:96-142`): `firstOrCreate(['campaign_id' => ..., 'agency_file_id'
=> ...], ['attached_by_user_id' => ...])`, 201 when created, 200 (no-op, no second audit row) when
already attached. The request body may optionally carry `visible_to_creators` to set the flag in the
same call (defaults to `false` when omitted); a replay of an idempotent attach does **not** re-apply
that value to an already-attached row — attach and toggle are different write intents, and conflating
them would let a re-attach silently flip visibility back.

### 2.4 D4 — the creator read path: CONFIRMED, and the "confirm at read pass" item resolves to yes

**One shared scope**, `CampaignAttachment::scopeCreatorVisible()`:
`$query->where('visible_to_creators', true)->whereHas('agencyFile')` — flag ON, live file, exactly D4's
two-clause definition. `whereHas` with no closure asserts existence through `AgencyFile`'s own global
`SoftDeletes` scope, so a soft-deleted file's row is excluded without a second explicit clause.

**One narrow presenter**, `CreatorCampaignAttachmentResource` (a `JsonResource`), owning exactly
`{ filename, mime_type, size_bytes, url }` — never `storage_key`, never `attached_by`. `url` mints via
`AgencyFileUploadService::signedDownloadUrl($attachment->agencyFile)`.

**The signed-URL reuse question, resolved:** the inventory found `signedDownloadUrl()` mechanically
caller-agnostic — it signs whatever `AgencyFile` it is handed, with no agency-prefix assertion of its
own (`AgencyFileUploadService.php:150-168`). Re-reading it at this pass confirms that finding exactly;
nothing has changed. **No sibling signer is needed.** The safety property this chunk relies on is not
inside the signer — it is that `CreatorCampaignAttachmentResource` is only ever constructed from rows
that already passed `creatorVisible()` **and** the outer campaign gate (assignment ownership or
`JobsBoardVisibility`), at the two call sites below. That composition, not the signer, is the boundary
— stated exactly as the inventory's own "outer campaign gate, then shared attachment filter, then
signed URL" sentence (`campaign-attachments-inventory.md:241-242`).

**Exactly two consuming surfaces, confirmed:**

1. **Assignment detail** — `CreatorAssignmentDraftController::show()`
   (`CreatorAssignmentDraftController.php:80-169`). After `resolveOwnedAssignment()`'s structural
   owner-only resolve (`:535-555`, unchanged — no new predicate needed, the ownership door is
   untouched), query `CampaignAttachment::query()->where('campaign_id',
$model->campaign_id)->creatorVisible()->with('agencyFile')->get()` and add
   `'attachments' => CreatorCampaignAttachmentResource::collection($attachments)->resolve($request)`
   inside the existing inline `attributes` block (`:105-138`), alongside `offer_attachment`'s existing
   inline-array precedent. **Not** added to the flat `GET creators/me/assignments` list — D4's fence,
   confirmed.
2. **Jobs Board detail** — `CreatorJobBoardController::show()`
   (`CreatorJobBoardController.php:163-190`) eager-loads `attachments` filtered by the same scope
   (`$job->load(['attachments' => fn ($q) => $q->creatorVisible()->with('agencyFile')])`) after
   `JobsBoardVisibility::visibleTo()` has already run (`:167`, unchanged); `CreatorJobDetailResource`
   (which `extends CreatorJobCardResource`, `CreatorJobDetailResource.php:54`) adds one new attribute
   key, `'attachments' => ...`, inside its own `toArray()` (`:70-82`) — **never** inside
   `cardAttributes()` (`CreatorJobCardResource.php:88-106`), which both the list and detail share and
   whose exact keyset is pin-tested. The card stays byte-identical; only the detail gains the key.

**The apply response gains nothing** — `CreatorJobBoardController::apply()`'s 201 is an application
row only (`:291-301` per the inventory); confirmed no third query, no attachment field on
`CreatorJobApplyResponse`. A dialog wanting to show attachments re-renders the already-fetched detail
payload, per the inventory's own answer (B3, "Application flow").

### 2.5 D5 — the §5.34 case set: CONFIRMED, translated into the test list at §5

### 2.6 D6 — scope fences: CONFIRMED, plus the two "propose if trivially free" answers

- **Bulk attach — not trivially free as a backend contract; trivially free as a UI behaviour.** A
  bulk endpoint would need partial-failure semantics (what happens when file 3 of 5 is already
  attached — 409 the whole batch, or skip silently?) that the single-attach idempotent shape (§2.3)
  does not need to answer, because a duplicate is a 200 no-op, not an error. **Proposal:** the picker
  allows multi-select; the dialog fires one `POST .../attachments` per selected file (sequential or
  `Promise.all`, surfacing any individual failure inline against that row) against the existing
  single-attach route. No bulk backend endpoint, no new request/response shape — genuinely free
  because it costs zero backend surface. `v1` still ships "attach one row" as the only backend act;
  the picker merely calls it more than once per dialog close.
- **The E2E leg — addressed at §6 S7 / §7 Q3, not assumed free.**

No other D6 fence is affected: no ordering UI, no anonymous access, no new bucket/prefix, no cards/
apply-response attachment field (already covered by D4 above).

---

## §3. API surface (nested addressing, per §2.1.1)

| Method   | Path                                                        | Controller action | Gate                     | Notes                                                                                                                                                           |
| -------- | ----------------------------------------------------------- | ----------------- | ------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET`    | `agencies/{agency}/campaigns/{campaign}/attachments`        | `index`           | `view` (any member)      | Unpaginated — a campaign's attachment count is small and bounded; no picker concern applies to a _result_, only to a _choice set_ (§4).                         |
| `POST`   | `agencies/{agency}/campaigns/{campaign}/attachments`        | `store`           | `update` (admin/manager) | Body `{ agency_file_id, visible_to_creators? }`. Idempotent (§2.3). 201/200.                                                                                    |
| `PATCH`  | `agencies/{agency}/campaigns/{campaign}/attachments/{file}` | `update`          | `update`                 | Body `{ visible_to_creators }`. `{file}` binds `AgencyFile` by ulid; row resolved by `(campaign_id, agency_file_id)`, 404 if no attachment exists for the pair. |
| `DELETE` | `agencies/{agency}/campaigns/{campaign}/attachments/{file}` | `destroy`         | `update`                 | Hard-deletes the join row (§2.3). 204.                                                                                                                          |

Route registration order: these four are static-segment routes (`attachments`, then `/{file}`), so —
unlike the `campaigns/{campaign}/assignments/attachments/init` case that had to be registered before
`{assignment}` — there is no collision risk here; `attachments` never collides with any sibling
`{campaign}` route segment. Registered in `Campaigns/Routes/api.php` alongside the existing
`campaigns/{campaign}/...` group.

Both `{campaign}` and `{file}` get the explicit structural same-agency assertions from §2.3 before any
`Gate::authorize()` call — the `AgencyFileController`/`TalentPoolMembershipController` precedent,
404-not-403.

---

## §4. The picker: `?for=select` on the existing Files index (the fourth invocation, §1)

`GET .../files?for=select` (same route as the paginated index, the `AgencyOptionResource`/
`BrandOptionResource` branch pattern) returns the complete, unpaginated set of the agency's
non-soft-deleted files as a new `AgencyFileOptionResource`:

```
{ id: <ulid>, original_filename, mime_type, size_bytes }
```

Excludes `storage_key`, `download_url` (no URL minting for a choice list — the inventory's own
rule), and `uploaded_by`. Two proof obligations per `docs/02-CONVENTIONS.md` §3.5: a completeness test
seeding more than one page's worth of files and asserting every one returns; and an explicit exclusion
line in the resource's docblock (already drafted above). Omitting `?for=select` leaves the existing
paginated branch byte-for-byte unchanged — confirmed by keeping the branch as a pure `if`, the
`AdminAgencyController`/`BrandController` shape.

---

## §5. The §5.34 case matrix (D5, made concrete)

| #   | Case                                                           | Expected                                                                                                                                                                                                                         |
| --- | -------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | attached + visible + listed, viewer otherwise eligible         | job detail AND assignment detail (if also assigned) both return the attachment + a signed URL                                                                                                                                    |
| 2   | attached + **not** visible + listed, viewer otherwise eligible | campaign/job stays visible; attachment key is **absent from the exact keyset** on both surfaces — not null, not an empty array member, absent                                                                                    |
| 3   | attached + visible + **unlisted**                              | Jobs Board list/detail/apply all 404 (delist kills the door); an **assigned** creator still reads it via assignment detail (the other door)                                                                                      |
| 4   | non-rostered / hard-blacklisted (agency or brand) viewer       | Jobs Board list empty, detail 404 — same code as every other AH-056 negative, non-fingerprinting                                                                                                                                 |
| 5   | assigned creator, roster relation later **severed**            | assignment detail still returns the visible attachment (AH-051 survives-disconnect); if that campaign is also on the Jobs Board, the board door is independently dead for the same caller — both directions asserted in one test |
| 6   | brand soft-blacklist                                           | campaign and ON attachment remain readable — the existing warn-at-invite posture, unaffected                                                                                                                                     |
| 7   | `hasAttachments()` reachable case                              | delete on an attached file → 409 `agency_file.attached`, now with a real row backing it                                                                                                                                          |
| 8   | duplicate attach                                               | 200, one row, no second audit entry                                                                                                                                                                                              |
| 9   | independent visibility per campaign                            | the same file attached to two campaigns can be ON on one and OFF on the other — no shared state                                                                                                                                  |
| 10  | detach with other campaign's reference intact                  | detaching from campaign A leaves campaign B's attachment of the same file untouched                                                                                                                                              |
| 11  | cross-agency file→campaign attach attempt                      | 404 (the structural same-agency check, §2.3)                                                                                                                                                                                     |
| 12  | role matrix                                                    | staff 403 on attach/toggle/detach, can still view the tab; admin/manager succeed on all three                                                                                                                                    |
| 13  | OFF omission is exact-keyset, not hidden-link                  | a test asserting the JSON payload's key SET, not merely that a frontend link is absent                                                                                                                                           |

---

## §6. Sub-step plan (each independently green)

**S1 — Schema + model seam.** Migration (§2.1). `CampaignAttachment` model: relations,
`scopeCreatorVisible()`, factory. `Campaign::attachments(): HasMany`.
`AgencyFile::campaignAttachments(): HasMany` + `hasAttachments()`'s real query (§2.2). Schema test
(columns, FKs, both indexes) + model test (relations, scope, factory).

**S2 — Agency API.** `CampaignAttachmentController` (index/store/update/destroy), routes (§3),
`CampaignAttachmentResource` (agency-side: filename, mime, size, `visible_to_creators`,
`attached_by` name, `attached_at`, a freshly-minted `download_url` via
`AgencyFileUploadService::signedDownloadUrl()`). Three new `AuditAction` cases
(`campaign_attachment.added` / `.visibility_changed` / `.removed`). Feature tests: §5's cases 7–13,
plus the standard tenancy/role matrix.

**S3 — The picker.** `?for=select` branch on `AgencyFileController::index()`, `AgencyFileOptionResource`
(§4), completeness + exclusion tests. Existing paginated branch re-asserted unchanged.

**S4 — Creator read path.** `CreatorCampaignAttachmentResource`. Wire into
`CreatorAssignmentDraftController::show()` and `CreatorJobBoardController::show()` /
`CreatorJobDetailResource` (§2.4). Feature tests: §5's cases 1–6, the exact-keyset assertion on BOTH
surfaces (case 2), the break-revert for case 2 named explicitly (drop the `visible_to_creators` filter
→ both surfaces' negative cases must go red — the kickoff's review priority #1). `CreatorJobCardResource`'s
existing exact-keyset test re-asserted green and untouched (review priority #4). `CreatorJobApplyResponse`
shape re-asserted unchanged.

**S5 — Agency UI.** Attachments tab (`CampaignDetailPage.vue`), picker dialog (multi-select, N single-
attach calls per §2.6), per-row card (filename/size/toggle/detach/download), empty state pointing to
Files. `app.campaigns.*` i18n keys ×24, flaky-10 real MT.

**S6 — Creator UI.** Attachment block on the creator assignment-detail page and the Jobs Board job-
detail page (both read from the same response shape). `creator.json` i18n keys ×24.

**S7 — E2E.** Extend `creator-jobs-board.spec.ts` (c3) with a render-only leg: seed one attached+
visible file and one attached+not-visible file directly via factories (not the presigned-upload
flow), assert the visible one's filename renders on the job detail page and the hidden one does not.
**Do not** assert a download click — following that link inherits AH-091's still-open debt (no
browser-drivable presigned receiver has ever been proven for this disk in Playwright, and whether a
local-disk `temporaryUrl()` resolves inside the browser context needs a one-time check before trusting
it in CI). If that check fails, the leg stays render-only and the download gap is named, not silently
skipped. This is the "cheap render-only assertion" the kickoff asked to look for — found, scoped
narrowly, gated on one verification step.

**S8 — Docs.** `docs/security/tenancy.md` §4: amend the two existing creator-emission rows
(`:171`, `:183-185`) to name the attachment join + flag filter + raw-key withholding, per the
inventory's own instruction (B6). `docs/tech-debt.md`: the §7 Q1 race (whichever way it is ruled),
inherit AH-091's E2E-receiver debt rather than duplicate it. `adhoc-changes-log.md` AH-092 entry.
`deploy-log.md` PENDING entry (one migration, no new bucket/secret/flag). `agency-files-review.md`
gets a short note that its `hasAttachments()` seam is now closed, citing this chunk.
`RESUMPTION-TEMPLATE.md` Part 2 refresh.

**S9 — Evidence gates.** Break-revert proofs: the `creatorVisible()` flag clause (review priority #1),
the structural same-agency check on attach, the `agency_file_id` `restrictOnDelete` FK (attempt a hard
delete on an attached file at the DB layer, confirm it fails), the exact-keyset assertions on
`CreatorJobCardResource` (confirm it is untouched — review priority #4), the two-door composition
(review priority #2: sever the roster relation, confirm assignment detail still returns the attachment
while the Jobs Board door 404s, in one test). Then the full board: backend Pest serial, PHPStan max,
Pint, api-client + `apps/main` vitest/typecheck/ESLint, all i18n parity specs, full Playwright both
projects.

---

## §7. Open items for Pedram's ruling

**Q1 — the attach/(soft-)delete race (inventory B1, unsolved by the FK). Recommendation: name as
debt, do not close it this chunk.** A concurrent `POST .../attachments` and a library-file soft-delete
could theoretically interleave between `hasAttachments()`'s read and the file's `UPDATE ... deleted_at
= ...` — the FK cannot see a soft-delete. Closing it fully needs a row lock spanning two different
resources (the file row and the new attachment insert), which is disproportionate to a narrow,
low-probability window that the 409 guard already blocks in the overwhelming common case (one request
at a time, from one agency operator, on their own file). **Recommendation: accept and name it**,
exactly as the inventory already did, rather than add transactional complexity for a window this small.
Alternative if Pedram wants it closed now: wrap `AgencyFileController::destroy()`'s guard-check +
delete in `AgencyFile::query()->lockForUpdate()` and have the attach `store()` acquire the same lock
before its `firstOrCreate` — cheap in code, but it serializes every attach on that file's row, which is
a real (if small) throughput cost for a race this narrow.

**Q2 — should the agency-side `CampaignAttachmentResource` mint a download URL, or only show
filename/size/toggle/detach as D3's own list states?** The inventory's B5 lists "download" as a
per-row action; D3's own text omits it. **Recommendation: include it** — an agency that attaches a
file to a campaign will want to preview what it attached without navigating back to the Files page,
and the signer is already safe to call for the agency's own file behind the existing agency Gate (no
new authorization surface, unlike the creator side). Flagging only because D3's list and the
inventory's list disagree and I am resolving in the inventory's favor.

**Q3 — S7's E2E download-click, contingent on one verification.** Recorded as a build-time check, not
a design fork: if the local-disk `temporaryUrl()` the dev/test stack uses does not resolve inside the
Playwright browser context, the E2E leg stays render-only (metadata + OFF-omission) and the download
click is named as inherited debt rather than attempted and silently flaking. No ruling needed now —
this is what S7 will report against.

---

## §8. Expected ripple

Backend: 1 migration, 1 model + factory, 1 scope, 1 controller (4 actions), 1 policy reuse (no new
policy class), 3 audit actions, 2 resources (agency-side `CampaignAttachmentResource`, thin
`AgencyFileOptionResource`), 1 creator presenter (`CreatorCampaignAttachmentResource`), 1
`hasAttachments()` body swap, 2 existing controllers touched to eager-load attachments
(`CreatorAssignmentDraftController`, `CreatorJobBoardController`), 1 existing resource touched
(`CreatorJobDetailResource` — one new key), 1 existing controller touched
(`AgencyFileController::index` — the `?for=select` branch). Frontend: api-client
`CampaignAttachmentResource`/attach/toggle contracts + `AgencyFileOptionResource`, one new Attachments
tab + picker dialog, two creator-surface attachment blocks. i18n: `app.campaigns.*` (tab, picker,
toggle, detach) + `creator.json` (attachment heading/download) ×24, flaky-10 audited. Docs: AH-092
entry, tenancy.md §4 (2 rows amended), tech-debt (Q1's disposition), deploy-log PENDING,
agency-files-review.md note, resumption refresh, this file's own review-file successor.

---

## §9. What this plan does not do

No multi-file bulk **backend** endpoint (picker-side looping only, §2.6). No attachment field on
`CreatorJobCardResource` or `CreatorJobApplyResponse`. No ordering/reorder UI or `position` column. No
new bucket/prefix/disk — files stay under the existing `agencies/{agency}/files/` prefix. No anonymous
route. No shortened signed-URL TTL or revocation mechanism. No new `CampaignAttachmentPolicy` class
(reuses `CampaignPolicy::view`/`update`). No `ulid` column on `campaign_attachments` (§2.1.1). No
change to the AH-056 `JobsBoardVisibility` predicate itself — attachment visibility composes with it,
never inside it.

---

_Provenance: drafted by Cursor at plan-pause per `WORKING-PROCESS.md` §2 step 3, against the AH-092
kickoff's D1–D6 and the read pass recorded in §1. Every citation re-derived at `b9e0af4d`. No code
written; nothing builds until Pedram clears this file and rules on §7 Q1–Q3._
