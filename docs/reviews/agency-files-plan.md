# Agency Files library (chunk A) — PLAN (plan-pause, cleared)

- **Status:** Plan cleared by Pedram. Build in progress per the sub-steps below.
- **Entry:** AH-091 (confirmed free — AH-090 is the latest ad-hoc entry, `adhoc-changes-log.md:73`).
- **Date:** 2026-08-27
- **Author:** Cursor, against the kickoff's locked decisions (D1–D7) and the read-pass corrections
  below, ratified by Pedram.
- **HEAD at plan time:** `b24070ed` (`docs(reviews): agency files library inventory`) = `origin/main`'s
  parent, `fc4baaac`. Working tree clean.
- **Read at plan time:** `docs/reviews/agency-files-inventory.md` (this chunk's own inventory —
  re-verified below, three findings corrected), `docs/PROJECT-WORKFLOW.md` §5.34/§5.40,
  `docs/WORKING-PROCESS.md`, `docs/03-DATA-MODEL.md` §16, `docs/security/tenancy.md` §4,
  `docs/tech-debt.md` (the AH-010a AV-gap entry), `docs/feature-flags.md`.

---

## §0. The PROD-DATA RISK line, re-derived

> **⚠️ PROD-DATA RISK: LOW-MEDIUM** — the kickoff's estimate holds.
>
> **What touches rows that already exist:** nothing. The one migration is a `CREATE TABLE
agency_files`. No existing table is altered, backfilled, or has a column added.
>
> **What the feature writes to live data, once deployed:** new `agency_files` rows (schema-only
> table, empty at deploy) and new S3 objects under a brand-new prefix,
> `agencies/{agency_ulid}/files/{file_ulid}.{ext}`, inside the EXISTING `media` bucket. No existing
> prefix is read, written, or deleted by this chunk's code.
>
> **The one irreversible action this chunk ships:** DELETE hard-deletes exactly one S3 object (under
> the new prefix only, verified by an explicit prefix + agency-ownership check before the delete
> runs) and then soft-deletes its own new-table row. A bug here can only destroy an object this
> chunk itself created under its own new prefix — it has no reach into any pre-existing object.
>
> **Blast radius of a bug, worst first:** (1) a tenancy-scope defect letting Agency A read or delete
> Agency B's file — bounded by the explicit `file.agency_id === agency.id` check (route-model
> binding runs before tenancy context, so `BelongsToAgencyScope` cannot filter at bind time) plus
> the negative tests in sub-step 5; (2) a storage-prefix defect letting a forged `upload_id` complete
> outside the agency's own prefix — bounded by the same `str_starts_with` fence every existing
> presigned service uses, tested with an explicit forged-path case; (3) delete succeeding on the row
> but failing on the object (or vice versa) — resolved by object-first ordering + a hard failure
> (`StorageDeleteFailedException`) if the object delete does not report success, so a failed delete
> leaves the row visible rather than orphaning bytes silently.
>
> **`down()` honesty:** `dropIfExists('agency_files')` is a true structural inverse but a lossy
> content inverse — a rollback discards every row's metadata (filename, category, uploader) and
> cannot touch the S3 objects those rows pointed at, which become permanently un-tracked (not
> deleted, not listed anywhere) until a future reconciliation pass. Named in the migration's own
> `down()` comment. A pre-deploy DB snapshot is mandatory per house policy; this chunk adds no new
> requirement beyond that.

---

## §1. Read-pass findings that correct the kickoff / inventory

Three corrections, ratified by Pedram, folded into the build rather than re-litigated:

1. **The MIME-allowlist duplication is in three services, not two.** The inventory's I4/I6 named
   `AssignmentOfferAttachmentUploadService` and `MessageAttachmentUploadService` as the byte-identical
   pair. A third, `RelationshipMessageAttachmentUploadService`
   (`apps/api/app/Modules/Messaging/Services/RelationshipMessageAttachmentUploadService.php:49-67`),
   carries the exact same 14-entry array. The extraction (sub-step 2) updates all three call sites,
   not two.
2. **The signed-read `Content-Disposition: attachment` posture already has a precedent.** The
   inventory's I4 claimed "no `temporaryUrl()` call anywhere sets `Content-Disposition`." That is
   wrong: `PortfolioItemPresenter::signedDownloadUrl()`
   (`apps/api/app/Modules/Creators/Support/PortfolioItemPresenter.php:114-132`) already passes
   `['ResponseContentDisposition' => 'attachment; filename="..."']` to `temporaryUrl()`, with its own
   filename-sanitization helper. D4's download endpoint reuses this exact shape rather than
   inventing a new one; a correction addendum is recorded in the inventory and in tech-debt (the AV
   entry, corrected in the same pass — see §1.3).
3. **The AV/content-verification tech-debt entry already undercounts by one.** `docs/tech-debt.md`'s
   AH-010a entry (`:558-559`) names four surfaces (portfolio, campaign messaging, relationship
   messaging, offer attachments) but omits `AssignmentContractUploadService` (the contract-PDF
   uploader), which carries the identical no-sniff/no-AV posture. Agency Files is therefore the
   **sixth** affected surface, not the fifth the kickoff assumed — the entry is corrected to the true
   prior count (five) before adding this chunk as the sixth, rather than preserving the undercount.

A fourth item is not a correction but a locked decision applied at build time: `docs/03-DATA-MODEL.md`
§16 already specs an unbuilt, broader `files` registry (`kind` enum, `is_public`, `checksum`,
`virus_scanned_at` — Phase 2 columns). D1 does not build that table. §16 is amended to distinguish
the narrower, locked `agency_files` shape this chunk ships from the still-unbuilt generic registry,
so a future reader does not conflate the two. `mime_type` ships as `varchar(128)`, not the generic
spec's `varchar(64)` — several of the allowed Office MIME strings
(`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, 62 chars, plus the
`vnd.openxmlformats-officedocument.wordprocessingml.document` sibling) sit close enough to 64 that a
future longer MIME string would silently truncate at the DB layer; 128 is the same margin
`AssignmentOfferAttachmentUploadService`'s peers implicitly assume by never hitting the limit, made
explicit here instead of inherited by luck.

---

## §2. Sub-step plan (each independently green)

1. **Plan record + schema/model seam.** This file. Migration `create_agency_files_table` (additive
   only): `id`, unique `ulid`, `agency_id` (FK → `agencies`, RESTRICT — the tenancy-anchor precedent
   from `boards`/`board_columns`), `original_filename` (`varchar(255)`), unique `storage_key`
   (`varchar(512)`), `mime_type` (`varchar(128)`, see §1), unsigned `size_bytes` (`bigint`), `category`
   (`varchar(32)`, v1 single value), nullable `uploaded_by_user_id` (FK → `users`, `nullOnDelete` —
   the attribution-column precedent), `timestamps()`, `softDeletes()`, plus
   `idx_agency_files_agency_created` on `(agency_id, created_at)` for the newest-first list query.
   `AgencyFileCategory` enum (`CampaignAttachment = 'campaign_attachment'`). `AgencyFile` model:
   `HasUlid`, `BelongsToAgency`, `HasFactory`, `SoftDeletes`, enum cast on `category`, integer cast on
   `size_bytes`, `agency()` + `uploadedBy()` relations, and a `hasAttachments(): bool` method that
   returns `false` unconditionally today, docblocked as chunk B's future query point (D5). Factory +
   schema test (columns, FKs, indexes) + model test (casts, relations, factory-backed
   soft-delete-hides-from-default-query).

2. **Shared MIME catalogue (extraction, not the presign/complete flow).** New
   `App\Core\Storage\AttachmentMimeTypes` — `IMAGES`, `VIDEOS`, `DOCUMENTS` as the three disjoint
   maps that already exist inline across the codebase, `ALL = [...IMAGES, ...VIDEOS, ...DOCUMENTS]`
   (byte-identical to the three duplicated 14-entry arrays), and `NON_VIDEO = [...IMAGES,
...DOCUMENTS]` for this chunk's own use (D3's "documents, not media" subtraction).
   `MessageAttachmentUploadService::ACCEPTED_MIME_TYPES`,
   `RelationshipMessageAttachmentUploadService::ACCEPTED_MIME_TYPES`, and
   `AssignmentOfferAttachmentUploadService::ACCEPTED_MIME_TYPES` become `= AttachmentMimeTypes::ALL`
   (PHP 8.4 allows referencing an imported class constant array in a class-const initializer). No
   other line in any of the three services changes — the presign/complete three-call shape is
   explicitly NOT touched, per the kickoff's scope fence. A new Core test pins `ALL` against the
   exact 14 entries (so a future edit to one call site's array can't silently drift from the other
   two) and `NON_VIDEO` against its 11. The three existing attachment test files run unmodified and
   green.

3. **`AgencyFileUploadService`.** New service in `App\Modules\Agencies\Services`, built on
   `AssignmentContractUploadService`'s mechanics (agency-keyed, no re-encoding) rather than the
   video-capable `PortfolioUploadService`: `initiatePresignedUpload($agency, $mimeType,
$declaredBytes)` validates against `AttachmentMimeTypes::NON_VIDEO`, enforces `1 <=
$declaredBytes <= 25 * 1024 * 1024` (D3's ceiling — the offer/message-attachment peer, not the
   PDF-specific 10 MB), plans `agencies/{agency_ulid}/files/{file_ulid}.{ext}`, and presigns a 15-minute
   `PutObject` (or the `/_test/presigned-upload/` local fallback). `completePresignedUpload($agency,
$uploadId, $originalFilename)` re-asserts the prefix, then — the deliberate improvement on the
   template it copies — derives the MIME from the SERVER-CHOSEN extension (never re-trusts the
   client-declared `mime_type` past init) and reads the object's ACTUAL byte size via
   `Storage::size()`, rejecting (`RuntimeException`) an empty (`0` bytes — a landed-but-truncated
   PUT) or over-cap (`> MAX_BYTES` — a client that PUT more than it declared) object; only a landed
   object that is both non-empty and within cap gets a row created downstream. `signedDownloadUrl($file)`
   mints a 60-minute GET with `ResponseContentDisposition => 'attachment; filename="<sanitized
original_filename>"'`, reusing `PortfolioItemPresenter::downloadFilename()`'s sanitization
   approach (alnum/dash/underscore, extension preserved, ULID fallback for an empty name). `delete($file)`
   re-derives the expected prefix, calls `Storage::disk('media')->delete($path)`, and throws the new
   `StorageDeleteFailedException` (mirroring `StorageWriteFailedException`'s rationale — a failed
   delete is a server fact, not a 422-able user error) when the disk reports failure, so the caller
   never soft-deletes a row whose object delete did not succeed.

4. **Agency API + authorization.** `AgencyFilePolicy` (`viewAny`/`view`/`create` → any active
   membership; `delete` → admin + manager, mirroring `BrandPolicy`'s `archive`/`restore` shape — the
   ratified matrix), registered in `AgenciesServiceProvider::registerPolicies()`. Routes under
   `agencies/{agency}` in the existing `auth:web → tenancy.agency → tenancy` group, LITERAL upload
   routes registered before the `{file}` param route (the `creators/discover` ordering precedent):
   `GET files` (paginated, newest-first, 1–100 `per_page` clamp, eager-loads `uploadedBy:id,name`),
   `POST files/uploads/init`, `POST files/uploads/complete` (creates the row, 201), `GET
files/{file}` (a fresh signed download URL — never a stored one), `DELETE files/{file}` (204).
   `AgencyFileController` explicitly checks `$file->agency_id === $agency->id` BEFORE
   `Gate::authorize(...)` on every `{file}` route (route-model binding resolves before tenancy
   context, the `BrandController`/`BrandLogoController` precedent) — mismatch answers 404, never 403
   (non-fingerprinting, `docs/05-SECURITY-COMPLIANCE.md` §7). `AgencyFileResource` exposes `id`
   (ulid), `original_filename`, `mime_type`, `size_bytes`, `category`, `uploaded_by` (name, nullable),
   `download_url`, `created_at` — never `storage_key`.

5. **Deletion contract + the full negative-case suite.** `AgencyFile::hasAttachments()` (from
   sub-step 1) is called first in the delete flow; a `true` (unreachable today) answers `409
agency_file.attached`. Then: delete the S3 object (service call), and ONLY on success soft-delete
   the row — object-first, so a failed object delete (network blip, permissions drift) leaves the row
   fully intact and visible rather than a ghost row over live bytes. Feature-test matrix (each case
   isolated to one predicate, per §5.34):
   - **Tenancy:** Agency B's `GET files/{file}` and `DELETE files/{file}` on Agency A's file both 404
     (not 403 — pinned explicitly); Agency B's `GET files` never lists Agency A's rows.
   - **Roles:** staff gets 403 on delete; admin and manager both succeed; all three roles can list/
     upload/download.
   - **Storage-prefix fence:** a forged `upload_id` outside the agency's own `files/` prefix is
     rejected at complete-time; a `upload_id` that never landed in S3 (no object at that key) is
     rejected.
   - **MIME/size:** every `VIDEOS` entry is rejected at init (D3's subtraction, one case per MIME);
     unsupported/garbage MIME rejected; `declared_bytes` of `0` and `> 25 MB` rejected at init; a
     landed object of `0` bytes and one `> 25 MB` are both rejected at complete (the server-side
     verification sub-step 3 adds); a `.jpg`-declared init whose landed object's SERVER-derived
     extension mismatches is exercised via the same complete-time re-derivation.
   - **Delete ordering:** a forced object-delete failure (mocked `Storage::delete()` → `false`)
     leaves the row queryable afterward (no soft-delete happened); a successful delete removes the
     object AND hides the row from both `index` and `show` (soft-deleted).
   - **Deferred (named, not built):** the `hasAttachments() === true` → 409 case is NOT written this
     chunk — there is no attachment relation to make it true. Chunk B's arrival is what turns this
     from "vacuously false, untestable" into a real case; recorded here so its absence is a decision,
     not an oversight.

6. **Shared client contract + the Files page.** `packages/api-client/src/types/agency.ts` gains
   `AgencyFileResource`, `AgencyFileUploadInitResponse` (matching the existing five near-identical
   presigned-init shapes — deliberately NOT unified into one generic type, per the kickoff's scope
   fence on the base-class extraction), and the list/create payload types. `apps/main/src/modules/files/api/files.api.ts`
   mirrors `brands.api.ts`'s shape (`list`, `initUpload`, `completeUpload`, `show` for a fresh
   download URL, `destroy`). `AgencyFilesPage.vue`: `v-data-table-server` + `CEmptyState`
   (`BrandListPage.vue`'s clone target), a `v-file-input` upload affordance wired to the
   init → `uploadToPresignedUrl` → complete sequence (`AttachContractDialog.vue`'s pattern — boolean
   `uploading` state, `ApiError`/`extractFieldErrors` inline errors), a download action that calls
   `show()` for a fresh signed URL rather than caching one, and a delete confirm dialog gated on
   `agencyStore.isAdmin || currentRole === 'agency_manager'`. Route `/files` → `files.list` in
   `apps/main/src/modules/auth/routes.ts` (`layout: 'agency'`, the standard
   `['requireAuth', 'requireAgencyUser']` chain), one new `AgencyLayout.vue` nav item, and the new
   route name added to `agency-routes-agency-user-guard.spec.ts`'s pinned expected set (both the
   list-of-names assertion and the guard-order assertion cover it automatically once added there). No
   drag-drop, no detail page, no preview, no folders — the "not-Drive" fence stays visible in the
   page's own docblock.

7. **UI tests + i18n.** `AgencyFilesPage.spec.ts` (Vitest): empty state, loading skeleton, populated
   table, upload happy path (asserts init → PUT → complete call order), upload MIME/size rejection
   surfaced inline, delete confirm → success → row removed, delete 403 hides the action for staff,
   download click issues a fresh `show()` call (never reuses a cached URL), and a workspace-switch
   reload. `files.api.spec.ts` pins the request shapes. `app.files.*` (~12-15 leaves: title, empty
   heading/body, upload label/hint, uploading/error states, table headers, delete confirm
   title/message/confirm/cancel, delete-success toast) + one `app.nav.files` leaf, added across all 24
   locales with real per-locale MT (the flaky-10 discipline: `bg, el, et, fi, ga, hu, lt, lv, mt, ro`
   get real translations, never English fallback). `i18n-locale-parity.spec.ts` green; spot-values
   recorded in the review file.

8. **Docs, debt, deployment record.** `docs/reviews/agency-files-inventory.md` gets a short
   correction addendum (the three §1 findings, each citing this plan). `docs/03-DATA-MODEL.md` §16
   gains a subsection distinguishing the shipped `agency_files` table from the still-unbuilt generic
   `files` registry. `docs/security/tenancy.md` §4 gains the five agency-files routes, each annotated
   `**NOT a scope bypass**` (the AH-034 precedent format) — listed for the presigned-upload/explicit-
   ownership-check discipline, not because any of them actually bypasses the tenant scope.
   `docs/tech-debt.md`'s AH-010a AV entry is corrected to five prior surfaces (adding the omitted
   contract-PDF uploader) and extended to six with Agency Files. Four new discrete debt entries:
   (a) the presigned three-call flow stays unextracted across now six services — named as standing
   debt with the "rule of three, already exceeded" framing, not smuggled into this chunk; (b) no
   aggregate per-agency byte/count quota — the §"E2E and quota rulings" reasoning, trigger = a
   storage-economics or abuse signal; (c) the `Content-Disposition: attachment` hardening applies
   only to the two document-download surfaces (portfolio's `download_url` and this chunk) — the other
   four signed-read call sites (avatar, logo, message/offer-attachment `view_url`, contract PDF
   `view_url`) stay at the default inline-serve posture, which is correct for images/video meant to
   render but is an explicit, named gap for anyone assuming the whole platform is hardened; (d) no
   browser-drivable presigned-upload receiver exists anywhere (the `/_test/presigned-upload/` fallback
   has no matching route), so no Playwright suite can exercise a real presigned round trip until that
   receiver is built — named once, not per-surface. `docs/reviews/adhoc-changes-log.md` gains the
   AH-091 entry (Why/What/Touched/Decisions/Ref). `docs/runbooks/deploy-log.md` gains a PENDING entry
   (one migration, no new bucket/IAM/secret). `docs/reviews/agency-files-review.md` (the completion
   package) and `docs/reviews/RESUMPTION-TEMPLATE.md` Part 2 refresh close the chunk.

9. **Evidence gates + handoff.** Break-revert proofs (mutate → run the specific test → confirm red →
   revert → `git status`/`git diff` clean → re-green) for: the explicit `agency_id` ownership check
   (comment it out, confirm the tenancy 404 test fails), the storage-prefix fence in
   `completePresignedUpload` (loosen the `str_starts_with` check, confirm the forged-path test
   fails), the `ResponseContentDisposition` option (drop the option, confirm the disposition-parsing
   test fails), the video subtraction (add `video/mp4` back into the service's accepted set, confirm
   the video-rejection test fails), the object-delete-first ordering (swap the order so the row
   soft-deletes before the object-delete call, confirm the failure-ordering test fails), and the
   `agency-routes-agency-user-guard.spec.ts` registration (remove `files.list` from the expected
   array, confirm it fails). Then the full board: `composer run ci` (Pint + PHPStan + full serial
   Pest at `-d memory_limit=2G`), `pnpm --filter @catalyst/api-client test` + typecheck, `pnpm --filter
main test` + `vue-tsc` + ESLint + the i18n parity specs, and the full existing Playwright suite
   (the agency nav is E2E-traversed by every existing agency-shell spec that asserts the sidebar
   item set — none currently assert Files' absence, but the nav-item-count class of assertion is
   exactly what a new item can silently break). Two-commit pair (`feat(agencies): agency files
library` then `docs(reviews): AH-091 agency files library`). **Push held for Pedram's trigger +
   Claude's clearance**, per house process — this file and the review file are the artifacts for that
   verbatim read.

---

## §3. E2E and quota rulings (restated from the kickoff, ratified)

- **No new Playwright leg this chunk.** Verified at plan time: every presigned service's local
  fallback returns a `/_test/presigned-upload/{path}` URL, and no route in
  `apps/api/app/TestHelpers` (or anywhere else) receives a PUT at that path. A truthful browser round
  trip needs a new gated test-helper upload receiver (write the PUT body to the local `e2e-media`
  disk at the given key) plus confirming the URL's origin resolves inside the Playwright browser
  context — genuine new test infrastructure, not a cheap addition to an existing spec. Named as debt
  in §8(d) above rather than built ad hoc under this chunk's E2E budget.
- **No per-agency quota.** A count cap bounds no byte volume, does not account for objects that
  landed in S3 but whose `complete` call never ran (an abandoned upload — orphaned under the new
  prefix, invisible to any count that only reads `agency_files` rows), and a real limit needs
  atomicity (a concurrent double-upload racing a count check) and a product-tier decision this chunk
  has no mandate to make. The 25 MB per-object cap plus ordinary pagination are the real defenses
  this chunk ships; the aggregate quota is named debt with an explicit trigger (a storage-economics
  review or an abuse signal), not a silently-accepted gap.

---

## §4. Scope fences (unchanged from the kickoff)

No campaign linkage, no creator visibility, nothing outside the agency's own section, no new
disk/bucket (a prefix under the existing `media` bucket only), no AV/content scanning, no aggregate
quota, no extraction of the shared presigned three-call flow (MIME-allowlist extraction only), no
change to any of the six existing signed-read call sites' behavior, no detail page, no preview, no
folders, no sharing.
