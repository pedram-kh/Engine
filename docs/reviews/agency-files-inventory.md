# Agency Files library (chunk A) — read-only inventory (chunk A of two)

- **Status:** Inventory only. No edits, no plan, no code.
- **Date:** 2026-08-27
- **Author:** Cursor (read-only pass), fresh session.
- **PROD-DATA RISK: NONE** — this pass read files and ran `git` read-only commands. Nothing was
  executed against any database, bucket, or running service.
- **HEAD:** `fc4baaac0e20f72694d687f86d09be2d11268a4b` (`fc4baaac`), **= `origin/main`**
  (`git rev-parse HEAD` and `git rev-parse origin/main` both resolve to this SHA; working tree
  clean apart from this file). HEAD's subject: `docs(reviews): record AH-090's §5.41 discharge —
the carried CI fix, cited`.
- **Orientation read before writing:** `docs/reviews/adhoc-changes-log.md` AH-090 (`:73-147`, the
  latest landed entry — platform automations, awaiting Pedram's close) confirms this session
  starts clean of any open ad-hoc thread. `docs/PROJECT-WORKFLOW.md` §5.40 (the mandatory
  `PROD-DATA RISK` alarm line, `:492`) is the convention this file's header follows.

**The feature, as locked by Pedram (restated for the record, not re-litigated here):** a
per-agency, S3-backed document library reachable from a new left-menu **"Files"** entry —
deliberately not "Drive": no folders, no sharing, no preview expectations. Upload, list, delete.
First organizing concept is a `category`, first value `campaign_attachments`; chunk B is what
_uses_ that category to attach files to campaigns and render them onward. **Chunk A ships the
library only** — no campaign linkage, no creator visibility, nothing outside the agency's own
section.

---

## Corrections (post-plan-pause, ratified, AH-091, 2026-08-27)

Appended, not rewritten — the findings below stand as originally written; these three corrections
were surfaced and ratified during the plan-pause and are recorded here rather than silently folded
into the prose above.

1. **AV-debt surface count was undercounted at "confirmed none... a fifth" (I4, `:254-259` below).**
   The count omitted `AssignmentContractUploadService` (contract-PDF attachments), which carries
   the identical no-AV-scan posture and should have been counted among the existing surfaces
   independent of this feature. With `AgencyFileUploadService` added, the count is **six**, not
   five: portfolio, campaign messaging, relationship messaging, offer attachments, contract-PDF
   attachments, and agency files. Corrected in `docs/tech-debt.md`'s AH-010a entry.
2. **The disposition claim "no `Content-Disposition: attachment` override anywhere in the
   codebase" (I4, `:277-278` below) is wrong.** `PortfolioItemPresenter::signedUrl()`
   (`apps/api/app/Modules/Creators/Support/PortfolioItemPresenter.php:111,130`) already passes
   `ResponseContentDisposition => 'attachment; filename="..."'` on its signed GET — one existing
   precedent, not zero. The inventory's broader point stands (none of the ~14 OTHER `temporaryUrl()`
   call sites do this — see the corrected count in `docs/tech-debt.md`'s AH-091 entry, item 1), but
   "anywhere" overstated it; `AgencyFileUploadService::signedDownloadUrl()` is the second precedent,
   not the first, and copies this pattern rather than inventing it.
3. **`docs/03-DATA-MODEL.md` §16 ("Files & uploads") describes a generic, never-built `files`
   registry** — no migration has ever created it; every real upload path (this one included) writes
   its own narrow table. `agency_files` is NOT that table's Phase-1 slice: no `kind` polymorphism, no
   `s3_bucket`/`checksum`/`is_public`/`metadata`, agency-scoped only, and `mime_type` at
   `varchar(128)` rather than §16's `varchar(64)` (several Office MIME strings, e.g. the 71-character
   `application/vnd.openxmlformats-officedocument.wordprocessingml.document`, exceed 64 — a detail
   that would have bitten at runtime had the spec's width been copied verbatim). §16 now carries a
   note making this distinction explicit, plus the shipped `agency_files` table shape.

---

## I1 — The upload machinery that exists

### Every current upload path, enumerated

| Surface                                              | Transport        | Service                                                                                                                                                                                                                                                                                                                                     | Controller                                                                                                                                     | Path shape                                                                                               |
| ---------------------------------------------------- | ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| Creator avatar                                       | Direct multipart | `AvatarUploadService` (`apps/api/app/Modules/Creators/Services/AvatarUploadService.php:34-177`)                                                                                                                                                                                                                                             | `AvatarController` (`apps/api/app/Modules/Creators/Http/Controllers/AvatarController.php:34-167`)                                              | `creators/{ulid}/avatar/{file_ulid}.{ext}` (`AvatarUploadService.php:70-75`)                             |
| Brand logo                                           | Direct multipart | `BrandLogoUploadService` (`apps/api/app/Modules/Brands/Services/BrandLogoUploadService.php:45-203`)                                                                                                                                                                                                                                         | `BrandLogoController` (`apps/api/app/Modules/Brands/Http/Controllers/BrandLogoController.php`)                                                 | `agencies/{agency_ulid}/brands/{brand_ulid}/logo/{file_ulid}.{ext}` (`BrandLogoUploadService.php:85-91`) |
| Portfolio image (small)                              | Direct multipart | `PortfolioUploadService::uploadImage()` (`apps/api/app/Modules/Creators/Services/PortfolioUploadService.php:69-82`)                                                                                                                                                                                                                         | `PortfolioController`                                                                                                                          | `creators/{ulid}/portfolio/{file_ulid}.{ext}`                                                            |
| Portfolio image/video (large)                        | **Presigned S3** | `PortfolioUploadService::initiatePresignedUpload()` / `initiatePresignedImageUpload()` (`PortfolioUploadService.php:104-147,203-261`)                                                                                                                                                                                                       | `PortfolioController`                                                                                                                          | `creators/{ulid}/portfolio/{file_ulid}.{ext}`                                                            |
| Draft submission media                               | **Presigned S3** | `PortfolioUploadService` with `namespace='drafts'` (`PortfolioUploadService.php:104-123,270-275`)                                                                                                                                                                                                                                           | `CreatorAssignmentDraftController`                                                                                                             | `creators/{ulid}/drafts/{file_ulid}.{ext}`                                                               |
| Invite-offer attachment (AH-034)                     | **Presigned S3** | `AssignmentOfferAttachmentUploadService` (`apps/api/app/Modules/Campaigns/Services/AssignmentOfferAttachmentUploadService.php:37-192`)                                                                                                                                                                                                      | `CampaignAssignmentController::attachmentInit/attachmentComplete` (routes at `apps/api/app/Modules/Campaigns/Routes/api.php:73,75`)            | `agencies/{agency}/campaigns/{campaign}/offer-attachments/{file_ulid}.{ext}` (`:84-90`)                  |
| Per-campaign contract PDF                            | **Presigned S3** | `AssignmentContractUploadService` (`apps/api/app/Modules/Campaigns/Services/AssignmentContractUploadService.php:23-110`)                                                                                                                                                                                                                    | `CampaignAssignmentContractController::initMedia/completeMedia/attach` (routes at `apps/api/app/Modules/Campaigns/Routes/api.php:109,111,113`) | `agencies/{agency}/assignments/{assignment}/contracts/{file_ulid}.pdf` (`:51-56`)                        |
| Message attachment (campaign + relationship threads) | **Presigned S3** | `MessageAttachmentUploadService` (`apps/api/app/Modules/Messaging/Services/MessageAttachmentUploadService.php:29-134`), glued via `HandlesMessageAttachments` (`apps/api/app/Modules/Messaging/Http/Controllers/Concerns/HandlesMessageAttachments.php:26-104`) and the relationship-thread sibling `HandlesRelationshipMessageAttachments` | `AgencyMessageController` / `CreatorMessageController` / relationship equivalents                                                              | `messages/{thread_ulid}/{file_ulid}.{ext}` (`:78`)                                                       |

**This is the direct-precedent set for a new agency-scoped, standalone document library:**
`AssignmentOfferAttachmentUploadService` and `AssignmentContractUploadService` are the two that
are already **agency-keyed** (not creator-keyed), and `AssignmentContractUploadService`
(`apps/api/app/Modules/Campaigns/Services/AssignmentContractUploadService.php:16-22`) is explicitly
docblocked as mirroring "the presigned mechanics of `PortfolioUploadService` but with
agency/assignment ownership — NOT creator-keyed."

### The presigned-URL flow (the "AH-034 pattern")

Every presigned service (`AssignmentOfferAttachmentUploadService`, `AssignmentContractUploadService`,
`MessageAttachmentUploadService`, `PortfolioUploadService`'s presigned branch) follows the **identical
three-call shape**, byte-for-byte the same structure copy-pasted per surface (no shared base class or
trait — see the reuse gap flagged in the shaping answer below):

1. **`initiatePresignedUpload(...)`** — validates `mimeType` against a hardcoded
   `ACCEPTED_*_MIME_TYPES` const array, validates `declaredBytes <= MAX_BYTES`, plans a
   **server-chosen** path (`{tenant-prefix}/{sub-scope}/{Str::ulid()}.{ext}`), and either:
   - returns a real S3 presigned `PutObject` request via `$client->createPresignedRequest($command,
'+15 minutes')` (e.g. `AssignmentOfferAttachmentUploadService.php:104-121`), or
   - falls back to a synthetic `/_test/presigned-upload/{path}` URL when `Storage::disk('media')`
     is not an `AwsS3V3Adapter` (local/test driver — same file, `:92-102`). This fallback exists in
     **every** presigned service, verbatim.
2. **`completePresignedUpload(...)`** (the confirm-after-upload step) — re-checks the `upload_id`
   starts with the exact expected prefix (`assertUploadBelongs` / inline `str_starts_with`, e.g.
   `AssignmentOfferAttachmentUploadService.php:157-171`) and that `Storage::disk('media')->exists($uploadId)`
   is true. **This is the sole server-side confirmation that anything landed** — no byte-count or
   content check against the declared size/MIME happens here (see I4).
3. **`signedViewUrl(?string $path): ?string`** (a static helper, e.g.
   `AssignmentOfferAttachmentUploadService.php:179-191`, `BrandLogoUploadService.php:117-127`) — calls
   `Storage::disk('media')->temporaryUrl($path, now()->addMinutes($TTL))`, `null`-safe, and returns
   `null` when the disk isn't S3 (test fakes). TTL is a per-service constant, always **60 minutes**
   for agency/creator resource emissions (15 minutes is only ever used for the _upload_ URL's own
   expiry, a distinct constant). Every one of these is called **only from inside an already-authorized
   resource's serialization** (the "AH-004 posture", e.g. `CampaignAssignmentResource.php:122`,
   `ContractResource.php:58`, `AgencyCreatorDetailResource.php:222`) — never from an unauthenticated
   or unscoped path.

**Isolation backstop:** every service's prefix check is the _only_ thing standing between "an
authenticated agency user" and "read/write anyone's object" for the presigned family — there is no
S3 bucket policy or IAM condition doing tenant isolation; it is 100% application-layer prefix
string-matching (confirmed for the offer-attachment surface by `tenancy.md:221-222`, which explicitly
flags these two routes as "**NOT a scope bypass** — runs the FULL standard tenant stack," listed only
for the presigned-upload discipline).

### Disk/bucket config

`apps/api/config/filesystems.php:65-187` defines four **module-scoped** disks, each opted into
explicitly per call site (`Storage::disk('media')`, never the app default):

- `media` (private) — avatars, portfolio, drafts, offer attachments, message attachments. Bucket
  `catalyst-engine-media` (`:142`). Swaps driver to `local` under `MEDIA_DISK_DRIVER=local`
  (Playwright E2E only, `:127-148`).
- `contracts` (private) — bucket `catalyst-engine-contracts` (`:150-161`). **Not currently used**
  by `AssignmentContractUploadService`, which writes to `media` like everything else — the
  `contracts` disk exists but the per-campaign contract PDF path does not use it (worth flagging:
  a dedicated disk was provisioned and then bypassed).
- `exports` (private) — GDPR/CSV exports, unrelated to this feature.
- `media-public` (public-read) — reserved for unauthenticated-serve assets; nothing in the upload
  paths above uses it (every read path here is a signed GET off a private disk).

### Path/key namespacing conventions

Every tenant-scoped path is `{owner-prefix}/{sub-scope}/{Str::ulid()}.{ext}` — the **filename is
always server-chosen** (a fresh ULID), never the client's original filename. Two prefix families
exist today:

- **Creator-keyed:** `creators/{creator_ulid}/{namespace}/...` (avatar, portfolio, drafts).
- **Agency-keyed:** `agencies/{agency_ulid}/{sub-scope}/{sub-scope-ulid}/...` (brand logo, offer
  attachments, contracts) — this is the family a standalone agency library would join.

No existing surface has a **bare** `agencies/{agency_ulid}/{something}/{file}.{ext}` two-segment
path with no intermediate entity ULID — every agency-keyed path today nests under a _child_
entity (`brands/{brand_ulid}`, `campaigns/{campaign_ulid}`, `assignments/{assignment_ulid}`). A
standalone library has no such child to nest under; the natural shape is the new, one-level
`agencies/{agency_ulid}/files/{file_ulid}.{ext}`.

### MIME/size validation — honestly, which layer enforces

| Surface                                                                  | Where MIME is checked                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    | Where size is checked                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| ------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Avatar / brand logo (direct multipart)                                   | **Server, magic-byte.** `UploadedFile::getMimeType()` reads actual file content via Symfony's guesser (`AvatarUploadService.php:135-143`, `BrandLogoUploadService.php:163-171`, both docblocked "MIME from content, not from the client... detects as text/x-php here").                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | **Server**, against `config('uploads.avatar_max_bytes')` / `brand_logo_max_bytes` (`config/uploads.php:27,46`), checked pre-decode (`assertWithinSize`).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| Portfolio / offer attachment / message attachment / contract (presigned) | **Client-declared only, at init time.** `initiatePresignedUpload($mimeType, ...)` just checks the string is a key in the allowlist array — it never inspects bytes, because at init time no bytes exist yet. The S3 `PutObject`'s `ContentType` is set to this client-declared value (e.g. `AssignmentOfferAttachmentUploadService.php:106-111`). **No server-side re-verification of actual content happens for non-image types at complete time.** Raster images (`jpg`/`png`/`webp`) ARE re-decoded by `PortfolioImageProcessor` at complete time for the portfolio and offer-attachment surfaces (`AssignmentOfferAttachmentUploadService.php:134-147`) — an undecodable file is rejected there — but this is an image-only side effect of the EXIF-strip step, not a deliberate content-sniff control, and PDFs/docs/video get **zero** content verification. This is a documented platform-wide gap (`docs/tech-debt.md:558-560`). | **Client-declared, checked at init against a `MAX_BYTES` class constant** (e.g. 25 MB for offer/message attachments, 10 MB for contracts, 500 MB for portfolio presigned). **Never re-verified against the actual object size at complete time** — every `completePresignedUpload()` only checks prefix + existence (e.g. `AssignmentContractUploadService.php:93-106`), not `HeadObject` size. The only real backstop is that the S3 SDK's presigned `PutObject` command signs `ContentLength` as a request parameter, so a PUT with a materially different byte count fails at S3 with a signature/length mismatch — enforcement lives in AWS's request validation, not in this codebase. |

**Caps are not centralized.** `avatar_max_bytes` / `brand_logo_max_bytes` live in
`config/uploads.php` (env-overridable, single source of truth per the file's own docblocks,
`:1-48`). Every presigned surface's `MAX_BYTES` / `MAX_PRESIGNED_BYTES` is a **hardcoded PHP class
constant** on its own service (`AssignmentOfferAttachmentUploadService::MAX_BYTES = 25 * 1024 *
1024`, etc.) — not config-driven, not shared. A new library's cap would need its own constant (or
be the first presigned surface to earn a `config/uploads.php` entry).

---

## I2 — What a general document store needs that none of those have

**Every existing upload row is a column (or JSON blob) on a parent entity, never its own
listable/deletable row:**

- Avatar / logo: a single nullable `*_path` column on `Creator` / `Brand` — one file, replace-in-place,
  no history, no list.
- Portfolio: `creator_portfolio_items` (`apps/api/database/migrations/2026_05_14_100002_create_creator_portfolio_items_table.php:27-58`)
  **is** a real per-file row table — `id`, `ulid`, `creator_id` FK (`cascadeOnDelete`), `kind`,
  `title`, `description`, `s3_path`, `external_url`, `thumbnail_path`, `mime_type`, `size_bytes`,
  `duration_seconds`, `position`, `timestamps()`, `softDeletes()`. **This is the closest existing
  precedent for a document-library row shape** — not agency-scoped (it's creator-scoped via
  `creator_id`, no `BelongsToAgency`), but structurally exactly what's needed: ulid + owner FK +
  mime/size + soft-delete.
- Draft media: `campaign_drafts.media_attachments` is a **`jsonb` array** embedded on the draft row
  (`apps/api/database/migrations/2026_06_05_110000_create_campaign_drafts_table.php:61-62`,
  `{s3_path, mime_type, kind, thumbnail_path, duration_seconds}`) — not a table, no independent
  ULID, no independent delete (drafts are never deleted — see I3), no per-file listing endpoint.
  **Not an extension candidate.**
- Contract PDF: `contracts.body_pdf_path` (`apps/api/database/migrations/2026_05_17_100000_create_contracts_table.php:78`)
  is a single nullable string column on a legal-record row — one PDF per contract version, no
  delete path at all (see I3). **Not an extension candidate**: a `Contract` is a signature/lifecycle
  record, not a document container, and extending it to hold arbitrary agency documents would
  conflate two unrelated domains.
- Message attachments: `messages.attachments` — a `jsonb` **array** column (`Message.php:40,106`),
  same shape as draft media. Not a table, not independently addressable/deletable.

**Conclusion: no existing table is close enough to extend.** The two real candidates
(`campaign_drafts.media_attachments`, `messages.attachments`) are JSON blobs with no independent
identity or delete lifecycle; `contracts.body_pdf_path` is a single-file column on an unrelated
domain object. `creator_portfolio_items` is the right _shape_ to imitate but is the wrong _owner_
(creator, not agency) and wrong _trait_ (plain FK, not `BelongsToAgency`). A new `agency_files`
table, shaped like `creator_portfolio_items` but agency-owned, is the only clean path — matching
the kickoff's own proposed shape (`ulid, agency_id, original filename, storage key, mime, size,
category, uploaded_by, timestamps`).

### The tenancy mechanism — `BelongsToAgency`

`apps/api/app/Core/Tenancy/BelongsToAgency.php:35-65` is the trait: installs a global scope
(`BelongsToAgencyScope`) that auto-filters every query by `TenancyContext`'s current agency,
auto-fills `agency_id` on create when unset (throwing `MissingAgencyContextException` if no
context is active), and provides the `agency()` relation. **Canonical example:** `Board`
(`apps/api/app/Modules/Boards/Models/Board.php:34-40`) — `use BelongsToAgency; use HasFactory;`,
plain `agency_id` FK, docblocked as "tenant-scoped via BelongsToAgency... denormalized from the
campaign, mirroring `message_threads`." This is the trait a new `AgencyFile` model should use.

**Notably, `Contract` does NOT use this trait** despite having a nullable `agency_id` column
(`contracts` migration `:57-61`) — it's scoped by polymorphic `subject_type`/`subject_id`, not by
the standard tenancy scope. `campaign_drafts` has **no** `agency_id` at all (scoped transitively
via `assignment → campaign → agency`). Neither is the pattern to copy; `Board`/`BoardColumn`-style
direct `BelongsToAgency` is the right one for a directly agency-owned resource with no more-specific
parent.

---

## I3 — Delete semantics

**Reality, surface by surface — does delete touch the S3 object, the row, or both:**

| Surface                          | Row delete                                                                                                                                                                                                   | Object delete                                                                                                                                                                                           | Cite                                                                                   |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| Portfolio item                   | **Soft-delete** (`SoftDeletes` on `CreatorPortfolioItem`)                                                                                                                                                    | **Hard-delete, immediately, same request** — `deleteStoredObjects($item->s3_path, $item->thumbnail_path)` runs **before** `$item->delete()`                                                             | `PortfolioController.php:325-348`                                                      |
| Brand logo                       | Column nulled (`logo_path = null`), brand row untouched                                                                                                                                                      | **Hard-delete, immediately** — `Storage::disk('media')->delete($path)`                                                                                                                                  | `BrandLogoUploadService.php:100-107`, `BrandLogoController` DELETE route               |
| Brand logo _replace_ (re-upload) | Column repointed to new key                                                                                                                                                                                  | **Old object orphaned, on purpose** — docblocked: "Replace-in-place is by REFERENCE, not by object... The previous object is left behind... object deletion happens only on the explicit remove action" | `BrandLogoUploadService.php:65-73`                                                     |
| Avatar                           | Column nulled                                                                                                                                                                                                | **Hard-delete, immediately**                                                                                                                                                                            | `AvatarController.php:98-117`, `AvatarUploadService.php:84-91`                         |
| Message attachment               | **No delete path exists.** Messages are permanent chat history — no `SoftDeletes`, no destroy endpoint found.                                                                                                | Never deleted.                                                                                                                                                                                          | `Message.php` (no `SoftDeletes` trait; no destroy route in `Messaging/Routes/api.php`) |
| Draft media                      | **No delete path exists.** `campaign_drafts` is append-only version history by design — "each version is its own row with its own review trail... history preserved." No `CampaignDraftController::destroy`. | Never deleted.                                                                                                                                                                                          | `2026_06_05_110000_create_campaign_drafts_table.php:12-16`                             |
| Contract PDF                     | **No delete path exists.** A `Contract` row itself is soft-deletable (`softDeletes()`) but nothing ever nulls or removes `body_pdf_path` — it's a permanent legal-record attachment.                         | Never deleted.                                                                                                                                                                                          | `2026_05_17_100000_create_contracts_table.php:111`                                     |

**The honest summary:** the _only_ precedent for a user-facing, standalone "delete my file" action
is the portfolio item, and its answer is unambiguous — **soft-delete the row, hard-delete the S3
object, in the same request, object-delete-first.** Nothing in this codebase queues cleanup or
defers object deletion; nothing does object-only deletion; nothing does row-only (leaving the
object) deletion **as an intentional pattern** — the brand-logo _replace_ path leaves an orphan,
but that's a side effect of "replace" semantics, not a delete action, and it's explicitly flagged
as a known, accepted orphan in the service's own docblock. There is no queued/async cleanup job
anywhere in the upload/delete surface today (no `DeleteOrphanedMediaJob` or similar found).

**Soft-delete is NOT a uniform house norm for "user content."** It's inconsistent even among the
things closest to a document library: `CreatorPortfolioItem` soft-deletes; `Brand`/`Contract` soft-delete
(but for the whole parent record, not the file column); `Creator` (avatar owner), `Message`, and
`CampaignDraft` do **not** soft-delete at all. Whether `agency_files` rows should soft-delete is a
kickoff decision, not something the codebase already answers — but the _object_-deletion half of
the question **is** answered consistently everywhere a delete path exists at all: hard-delete the
object, synchronously, in the same request as the row mutation.

**⚠ The chunk-B constraint to flag now, before chunk A's schema locks:** chunk B's plan is "attach
files to campaigns." Nothing in today's codebase models a many-to-one or many-to-many "this row is
referenced elsewhere, so its deletion needs a rule" relationship for _storage_ objects — the closest
analogue is `contracts.subject_id` (polymorphic, no delete-time check at all, because contracts are
never deleted) or `campaign_assignments.contract_id` (nullable FK with **no** `restrictOnDelete`/`
cascadeOnDelete` visible from this pass — would need a follow-up read of that migration if this
matters at kickoff). **If chunk A's `agency_files` schema and delete endpoint ship before chunk B's
attachment mechanism exists, the delete action today has no attachment to conflict with** — but the
delete endpoint's contract (hard 404? soft "can't delete, in use"? cascade the campaign's reference?)
is exactly the kind of decision that's cheap to leave a hook for now (e.g., a `deleted_at` soft-delete
column so chunk B _can_ choose to show "removed" placeholders on a campaign that referenced a
since-deleted file, versus a hard-delete that chunk B must defensively null-check) and expensive to
retrofit once campaigns are pointing at rows. This is a chunk-A schema question, not a chunk-A
feature question — flagging per the kickoff's own instruction, not ruling on it.

---

## I4 — Limits, quotas, and the security surface

**Size caps per path** (see I1 table) — no single ceiling; ranges from 2 MB (brand logo) to 500 MB
(portfolio presigned video/image). Nothing in the existing set matches a plausible "PDF and similar
documents" library ceiling exactly; the two closest by _intent_ are the contract PDF cap (10 MB,
`AssignmentContractUploadService::MAX_PRESIGNED_BYTES`) and the offer/message-attachment cap (25 MB,
covers PDFs among other types).

**MIME allowlist mechanism: per-path, not shared, and duplicated verbatim in two places.**
`AssignmentOfferAttachmentUploadService::ACCEPTED_MIME_TYPES` (`:46-64`) and
`MessageAttachmentUploadService::ACCEPTED_MIME_TYPES` (`:40-58`) are **byte-for-byte identical**
14-entry arrays (images + video + PDF + doc/xls/csv/txt) — copy-pasted, not a shared constant.
There is no `config/uploads.php` MIME allowlist and no shared `App\Core\Uploads\*` allowlist class.
A new library's "PDFs and similar documents" set would most naturally reuse this exact list (minus
video, if the kickoff wants to exclude it) but there's no single place to import it from today —
it would be a third copy-paste, or the first extraction into something shared.

**Virus/content scanning: confirmed none, exactly as expected.** `docs/tech-debt.md:558-560`:
"Virus scanning + content verification are OUT (platform-wide gap, not AH-010-specific)... the same
gap campaign messaging + portfolio uploads carry... **no AV scan** on any type." The entry already
lists four affected surfaces (portfolio, campaign messaging, relationship messaging, offer
attachments); a new agency-files library would be a fifth, inheriting the identical gap unless the
kickoff decides otherwise.

**Direct-to-S3 (browser) vs. through-the-API:** split, by design, on whether server-side re-encoding
is needed. Avatar and brand logo go **through the API** (direct multipart) _because_ the server must
decode-and-re-encode the image (EXIF strip) — bytes must pass through PHP. Everything else
(portfolio presigned, offer attachments, contract PDFs, message attachments) goes **direct-to-S3**:
the browser PUTs straight to the presigned URL via `uploadToPresignedUrl()`
(`packages/api-client/src/presigned.ts:31-80` — deliberately bypasses the platform's own HTTP client
to avoid leaking CSRF/session credentials to the S3 vendor domain, `withCredentials: false`). A
generic PDF/document library has no re-encoding need, so the presigned-direct-to-S3 path is the
right fit — it's also the one every agency-keyed precedent (`AssignmentOfferAttachmentUploadService`,
`AssignmentContractUploadService`) already uses.

**The signed-read footgun — current posture, reported honestly:** every `temporaryUrl()` call found
in this pass (`AssignmentOfferAttachmentUploadService.php:190`, `BrandLogoUploadService.php:123-126`,
`ContractResource.php:58`, `CampaignAssignmentResource.php:122`, `MessageResource.php:84`,
`RelationshipMessageResource.php:104`, and eight more resource files) is called with **exactly two
arguments** — `($path, $expiry)` — **no third `$options` argument setting
`ResponseContentDisposition` or `ResponseContentType`.** There is no `Content-Disposition:
attachment` override anywhere in the codebase. The response's `Content-Type` is whatever was set
on the object at `PutObject` time (the client-declared MIME from init), and S3's default behavior
for a GET with no disposition override is to let the browser decide how to render it — which for
`application/pdf`, `image/*`, `video/*`, and `text/plain|csv` generally means **inline rendering**
in the tab, from the bucket's own domain. **The mitigating factor today is the MIME allowlist
itself, not any disposition header:** no allowlist anywhere in the codebase includes `text/html` or
`image/svg+xml` — the two classic inline-XSS vectors — so the current posture is "safe by allowlist
omission," not "safe by explicit header." A PDF library inherits this exact posture unless the
kickoff decides to add an explicit `ResponseContentDisposition: attachment` param to `temporaryUrl()`
for this surface (which none of the six existing signed-read call sites do).

---

## I5 — The UI surface

**The agency left-menu registry:** a plain literal array,
`apps/main/src/modules/agency/layouts/AgencyLayout.vue:55-65` —

```55:65:apps/main/src/modules/agency/layouts/AgencyLayout.vue
const navItems = [
  { key: 'dashboard', icon: 'mdi-view-dashboard-outline', routeName: 'app.dashboard' },
  { key: 'roster', icon: 'mdi-account-multiple-outline', routeName: 'roster.list' },
  { key: 'discover', icon: 'mdi-account-search-outline', routeName: 'discover.list' },
  { key: 'pools', icon: 'mdi-account-multiple-plus-outline', routeName: 'pools.list' },
  { key: 'brands', icon: 'mdi-tag-outline', routeName: 'brands.list' },
  { key: 'campaigns', icon: 'mdi-bullhorn-outline', routeName: 'campaigns.list' },
  { key: 'messages', icon: 'mdi-message-text-outline', routeName: 'messages.inbox' },
  { key: 'agencyUsers', icon: 'mdi-account-group-outline', routeName: 'agency-users.list' },
  { key: 'settings', icon: 'mdi-cog-outline', routeName: 'settings' },
]
```

A "Files" entry is a one-line addition here (`{ key: 'files', icon: 'mdi-file-multiple-outline' (or
similar), routeName: 'files.list' }`) plus the matching `app.nav.files` i18n key across all 24
locales, an `appRoutes` entry in `apps/main/src/modules/auth/routes.ts` (same `layout: 'agency',
guards: ['requireAuth', 'requireAgencyUser']` chain every peer route uses, e.g. `brands.list` at
`:280-284`), and a line in the `requireAgencyUser` arch-test's expected-route set
(`docs/tech-debt.md:873` names this test: `agency-routes-agency-user-guard.spec.ts`).

**Closest list-page shape to clone:** `BrandListPage.vue`
(`apps/main/src/modules/brands/pages/BrandListPage.vue:1-462`) — `v-data-table-server` +
server pagination, status filter chips, `CEmptyState` for the zero-rows case, per-row action
icons, and a confirm-dialog + snackbar pattern for a destructive action (archive/restore there,
delete here). It is a closer fit than `CreatorRosterPage.vue`, which carries URL-driven filter
state, debounced search, and country/language selects (`CreatorRosterPage.vue:1-60`) — machinery a
flat "list files, delete a file" surface doesn't need. Files needs no create/edit _pages_ (upload
is an in-page affordance, not a separate route) and no detail page (no preview expectation per the
kickoff) — so it's simpler than either precedent, closer to brands' table + dialog shape than to
roster's filter-heavy one.

**The upload affordance's precedent:** there is **no drag-drop or file-input component in
`packages/ui`** — none of its 16 components (`packages/ui/src/components/*.vue`) touch file
upload. The two existing upload UIs live locally in their own modules:

- `AvatarUploadDrop.vue` (`apps/main/src/modules/onboarding/components/AvatarUploadDrop.vue:1-236`)
  — a real drag-and-drop zone (`<label>` wrapping a visually-hidden `<input type="file">`, `@drop`/
  `@dragover`/`@dragleave` handlers, a11y-described hint text, error `role="alert"`) — but this is
  **creator-onboarding-only**, not reusable as-is, and not agency-side.
- `AttachContractDialog.vue` (`apps/main/src/modules/campaigns/components/AttachContractDialog.vue:1-211`)
  is the **agency-side** precedent, and it's simpler: a plain Vuetify `<v-file-input>` (`:173-183`),
  no drag-drop, wired directly to the three-call presigned flow (`campaignsApi.initContractMedia` →
  `uploadToPresignedUrl` → `campaignsApi.completeContractMedia`, `:76-99`). **This is the pattern an
  agency Files upload affordance should clone** — same transport helper, same agency-side idiom,
  `v-file-input` rather than a bespoke drop zone (no existing agency-side drag-drop component to
  reuse, and building one is out of scope for "just clone the precedent").

**Upload progress/errors today:** `uploadToPresignedUrl()` exposes an optional `onProgress(percent)`
callback (`packages/api-client/src/presigned.ts:36-42`) driven by axios's `onUploadProgress`, used
by the portfolio upload UI for a determinate bar on large files — but `AttachContractDialog.vue`
(the closer agency-side precedent) does **not** wire it, only a boolean `pdfUploading` spinner state
(`:40,73,98`). Errors surface via `ApiError` + `extractFieldErrors()` bound to Vuetify's
`:error-messages` prop (`:91-96`), the same per-field-422 pattern used throughout the agency SPA.

---

## I6 — Ripple

**Tenancy `§4` (`docs/security/tenancy.md:89-260`):** a new `agency_files` CRUD surface running the
full `auth:web` + `tenancy.agency` + `tenancy` stack is **not a scope bypass** and doesn't strictly
require a `§4` row — but the AH-034 precedent (`tenancy.md:221-222`) shows the house habit of
documenting new _presigned-upload_ routes in `§4` anyway, "listed here only for the presigned-upload
discipline," annotated `**NOT a scope bypass**`. A files-library init/complete pair would likely earn
the same two rows by that convention, even though nothing here is a genuine bypass.

**i18n ×24:** confirmed — `apps/main/src/core/i18n/locales/` has exactly 24 locale directories
(`bg, cs, da, de, el, en, es, et, fi, fr, ga, hr, hu, it, lt, lv, mt, nl, pl, pt, ro, sk, sl, sv`).
Every nav label, empty-state string, upload hint/error, and delete-confirm dialog string needs a key
in all 24 `app.json` (or module-specific json) files — the exact ripple size the AH-034 entry itself
logged (`adhoc-changes-log.md:2817`, "`apps/main/src/core/i18n/locales/*/app.json` (24)").

**api-client types — shared, confirmed, and confirmed duplicated per-feature.**
`packages/api-client/src/types/{creator,campaign,messaging}.ts` each define their **own** presigned
init-response interface with the identical shape (`{ data: { upload_url, upload_id, storage_path,
expires_at, max_bytes } }`): `PortfolioVideoInitResponse` (`creator.ts:524-527`, aliased as
`PortfolioImageInitResponse` at `:567`), `DraftMediaInitResponse` (`campaign.ts:751-753`),
`OfferAttachmentInitResponse` (`campaign.ts:778-780`), `ContractMediaInitResponse`
(`campaign.ts:816-818`), plus a messaging equivalent (`messaging.ts:115`). Since `apps/main` is the
consumer and `@catalyst/api-client` is shared workspace-wide, a files-library init/complete pair
would be the **sixth** near-identical interface — either another copy-paste (matching every
predecessor) or the first extraction into one generic shared type. `uploadToPresignedUrl()` itself
(`presigned.ts:31-80`) is already shared and needs no change.

**E2E exposure — no spec drives a presigned-upload UI through the browser today.** Two relevant,
contrasting data points:

- `creator-wizard-happy-path.spec.ts:26-30` **explicitly avoids** driving the file-upload UI: it
  pre-seeds a portfolio image via the production API endpoint "without driving the file-upload UI
  in the spec (the upload UI has dedicated Vitest coverage...)." The avatar step is likewise never
  E2E-driven through the browser.
- `brands.spec.ts:14-45` **does** drive a real upload through the browser
  (`setInputFiles(LOGO_FIXTURE)`, `:105`) — but that's the **direct-multipart** brand-logo path
  against the local-filesystem `media` disk (`MEDIA_DISK_DRIVER=local`), not a presigned-S3 flow.

**No existing Playwright spec exercises the three-call presigned init/complete/confirm sequence
through actual browser UI interaction.** A Files-library E2E spec (upload → list → delete) would be
the first to do so for the presigned family, and would need the same `MEDIA_DISK_DRIVER=local` E2E
posture `brands.spec.ts` already established (real bytes, real signed GET, `naturalWidth`-style
"the URL actually resolves" assertion is brand's version of proving the round-trip; a files library
would need its own proof — e.g. asserting the signed URL 200s or that a downloaded blob's byte
length matches — since there's no `naturalWidth` equivalent for a PDF).

**Deploy obligations a new table + S3 prefix imply:** a new migration (`agency_files`, additive,
low-risk per every precedent's own `@migration-risk low` docblock convention), and — per
`filesystems.php:76-91`'s own docblock — the `media` disk's local-dev MinIO buckets are
"bootstrapped by `minio-init`" and production/staging buckets are provisioned "per AWS Secrets
Manager." If the library uses the existing `media` bucket (likely, since no dedicated disk exists
for it and the `contracts` disk precedent shows a "provisioned but then bypassed" outcome — see I1),
**no new bucket is needed**, only a new key prefix under the existing `media` bucket — a materially
lighter deploy footprint than provisioning a sixth disk.

---

## The three shaping answers, restated plainly

1. **I1's presigned-flow reusability:** the mechanics (`initiatePresignedUpload` /
   `completePresignedUpload` / `signedViewUrl`, the `/_test/presigned-upload/` local fallback, the
   `uploadToPresignedUrl()` client helper) are fully reusable — every surface already copy-pastes
   the identical shape with zero shared abstraction. A files library is a sixth copy, following the
   `AssignmentContractUploadService` template most closely (agency-keyed, PDF-oriented, no
   re-encoding). There is no shared base class to extend and none is proposed by this inventory —
   only a kickoff decision on whether this is finally the chunk that extracts one.
2. **I3's delete reality:** the only precedent for a genuine user delete action
   (`CreatorPortfolioItem`) is unambiguous — soft-delete the row, hard-delete the S3 object,
   synchronously, object-first. No queued cleanup, no object-only or row-only pattern exists as
   intentional design anywhere in the codebase today. Soft-delete is not a uniform house norm
   (`Message` and `CampaignDraft` never soft-delete), but it IS the norm on the one table shaped
   like this one. The chunk-B constraint (a file referenced by a future campaign attachment) has
   no existing analogue to copy and is a genuine open question for the kickoff, not a pre-answered
   one — flagged, not ruled.
3. **I4's signed-read content-disposition posture:** none of the ~14 `temporaryUrl()` call sites
   in the codebase pass a `Content-Disposition` override; the only defense against inline-render
   footguns today is that no MIME allowlist anywhere includes `text/html` or `image/svg+xml`. A
   files library inherits this exact posture by default (S3's default inline-serve behavior for
   PDF/image/text types) unless the kickoff explicitly decides to add
   `ResponseContentDisposition: attachment` — which would make this library the FIRST surface in
   the codebase to do so.
