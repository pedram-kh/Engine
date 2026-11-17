# PL-1.1 inventory: Brand and new wave

**Date:** 2026-11-17. **Branch:** `pl` (tip `2df50875` when this was written; `origin/pl` was `a186696c`, so the tip is one local commit ahead). **Status:** read-only inventory. No code, no migration, no push.
**Scope:** the code and the two Figma frames that PL-1.1 touches. Rulings R1 to R10 (`production-line-sprints.md` §6) are binding. The wave rulings of 2026-11-17 supersede "a wave is a campaign".
**Companion:** `pl-1-1-plan.md`.

**PROD-DATA RISK: NONE** for this file (docs only).

Paths: `API` = `apps/api`, `MOD` = `apps/api/app/Modules`, `SPA` = `apps/main/src`, `ADM` = `apps/admin/src`. Line numbers were read on this tip. Where something does not exist, it says **DOES NOT EXIST (searched: …)**. Anything not opened says **not read**.

---

## 0. The designs (Figma MCP, file `lYy90qzKk029vjoVCPExs2`, page "STRATEGY & SCRIPT WRITING")

**What was read**

- **13:2 "01 · Brand page – Waves"** (1600 × 1189): full node tree through `get_metadata` (every layer name, text and size) plus a 2000 px screenshot.
- **13:4000 "09 · New wave"** (1600 × 1000): a 2000 px screenshot only. Every label is legible in it.

**What was not read**

- `get_design_context` was **not called** for either frame (it returns reference code and tokens, which an inventory does not need). Colours, spacing and type values are **not read** from Figma; the plan builds from the existing agency design system (R10).
- The node tree of 13:4000 was **not read** (`get_metadata` not called for it). Layer names and the hidden or disabled states of the dialog are **not read**.
- Every other frame on the page (01b, 02 to 08, 10 to 13) is **not read**. Frame 01b is PL-1.3.

**Frame 01 contents (read)**

| Part                                | What the frame shows                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ----------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sidebar                             | Dashboard, My creators, Discover, Talent pools, **Brands** (active), Campaigns, Files, Messages, Team, Settings. No new nav item.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| Header                              | Back arrow, "Bolt Food" title, `Edit` and `Archive` buttons. No status pill beside the title.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           |
| Brand details card (340 wide, left) | Logo and name; fields **Brand name, Slug, Status (pill "Active"), Monthly deliverables ("Food delivery app"), Industry, Website URL, Created**.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         |
| Client files panel (right, top)     | Title "Client files", subtitle "Briefs, guidelines and assets from Bolt Food — available to every wave". Two buttons: **Send questionnaire** and **Upload files**. A green row "Client onboarding questionnaire · Added automatically · Submitted by Anna K. (Bolt Food) · 24 of 24 questions answered · Completed · View responses". A table: **FILE, TYPE, UPLOADED BY, DATE**, a row menu (dots). Four rows: file name, a line "PDF · 2.4 MB", a green tag "Used for USP import" on one row, a type chip (Brief, Research, Guidelines, Assets), an uploader name with a chip (**Client** or **Catalyst**), a short date. A dashed drop zone: "Drag files here to upload · PDF, DOCX, XLSX, images, ZIP up to 100MB". |
| Waves panel (right, bottom)         | Title "Waves", subtitle "Strategy & script writing for each wave of creator content", button **New wave**. Table: **WAVE** (name and "Go-live Oct 3"), **MARKETS** (code chips SK, RO, CZ), **PROGRESS** (six segments and the current step name, or "Complete"), **STATUS** (pill: Client review, Draft, Approved), **UPDATED** (Today, Yesterday, Sep 2), a chevron. Four rows (Waves 7, 8, 6, 5). Footer line: "Click a wave to open its Strategy & Scripts workspace".                                                                                                                                                                                                                                              |

**Frame 09 contents (read, screenshot)**

Modal "New wave", subtitle = the brand name ("Bolt Food"), a close button. Fields: **Wave name** (text, "Wave 8"), **Go-live date** (date field with calendar icon, "Nov 7, 2026"), **Markets** (chips with a code badge and name, each removable: "SK Slovakia", "RO Romania", and a dashed **Add market**). Then **Start from**: two radio cards. **Copy from a previous wave** (selected): a wave dropdown ("Wave 7") and four checkboxes (USPs "6 USPs", Personas "SK: 2 · RO: 2", Messaging matrix "Agent regenerates from copied USPs + personas", Creators "5 SK · 4 RO — statuses reset to Shortlisted"). **Start fresh**: "Blank USPs and personas — e.g. new client brief". Footer: **Cancel**, **Create wave**.

**Gaps between frame 09 and the rulings**

- Frame 09 has **no campaign field**. R4 says the dialog offers an unlinked campaign of the brand, or "create a campaign for this wave now". The campaign picker has no design (R10). "Create a campaign now" is PL-1.2.
- "Copy from a previous wave" and "Start fresh" copy USPs, personas, matrix and creators, which do not exist until PL-2 and PL-3. They are a data operation (`new-sections-design-review.md` §2 frame 09; inventory §N3 "schema-only migrations").
- The six-segment **Progress** column and the **Status** values other than Draft need data that PL-1.3 and PL-2 create.
- The client **upload chip "Client"**, "Send questionnaire", the auto-added questionnaire row and "Used for USP import" all belong to the portal (PL-4) and the USP import (PL-2).

---

## 1. Brands today

**Model and table**

- `MOD/Brands/Models/Brand.php`: traits `BelongsToAgency`, `HasFactory`, `HasUlid`, `SoftDeletes` (`:40-46`). Public id is the ULID (`HasUlid::getRouteKeyName`, `API/app/Core/Concerns/HasUlid.php:37`). Defaults `status = active`, `default_currency = EUR`, `default_language = en`, `client_portal_enabled = false`. `FLOOR_FIELDS` = name, slug, description, industry, website_url, logo_path.
- Table `brands`, created in `API/database/migrations/2026_05_13_100000_create_brands_table.php`: `id`, `ulid` unique, `agency_id` FK `restrictOnDelete`, `name` (160), `slug` (64), `description` text nullable, `industry` (64) nullable, `website_url` (2048) nullable, `logo_path` (512) nullable, `default_currency`, `default_language`, `status` (16, default `active`), `brand_safety_rules` json nullable, `exclusivity_window_days` nullable, `client_portal_enabled` bool default false, timestamps, soft deletes. Unique `(agency_id, slug)` (`unique_brands_agency_slug`), indexes on `agency_id` and `status`. The only other migration that alters it is `2026_11_06_100001_add_color_to_brands.php`.
- "Monthly deliverables" in the UI is `brands.description` relabelled (inventory E3; the i18n label is in `SPA/core/i18n/locales/en/app.json`).

**Routes** (`MOD/Brands/Routes/api.php`). All sit in one group `auth:web` + `tenancy.agency` + `tenancy`, prefix `agencies/{agency}`:

- `apiResource brands` (index, store, show, update, destroy) with `withTrashed(['show'])` so an archived brand still opens.
- `POST brands/{brand}/restore`.
- `POST` and `DELETE brands/{brand}/logo` (`BrandLogoController`).

**Controller and policy**

- `MOD/Brands/Http/Controllers/BrandController.php`: each action calls `assertBelongsToAgency` (`:115,126,157,208`; the method is at `:240`) then `Gate::authorize`. Audit is written by hand with `Audit::log` (`AuditAction::BrandCreated`, `BrandUpdated`, `BrandArchived`, `BrandRestored`).
- `MOD/Brands/Policies/BrandPolicy.php`: view and viewAny for any accepted member; create, update, archive, restore for `agency_admin` and `agency_manager`; delete for `agency_admin`. `membership()` (`:76-82`) takes the user's **first** accepted membership and ignores the route's agency. It is safe today because the tenancy middleware has already proved membership of the route agency, but a policy that must be right for a user in two agencies should read the current `TenancyContext` instead.

**The agency Brand page today**

- Route `/brands/:ulid`, name `brands.detail`, `SPA/modules/auth/routes.ts:312-317`, `meta: { layout: 'agency', guards: ['requireAuth','requireAgencyUser'] }`. Siblings: `brands.list`, `brands.create`, `brands.edit` (`:300-323`).
- Page `SPA/modules/brands/pages/BrandDetailPage.vue` (237 lines). Header (`AgencyPageHeader`): back button, name, `AgencyStatusPill kind="brand"`, Edit and Archive buttons (only when active, no role check). One card `data-test="brand-detail-card"`: logo, name, a rule, a field list (slug, industry, website, created), then the description block. Single column (`agency-detail__main`). An archive dialog.
- Other files: `SPA/modules/brands/api/brands.api.ts`, `components/BrandForm.vue`, pages for list, create and edit, `brandFloor.ts`. Specs: `BrandDetailPage.spec.ts`, `BrandListPage.spec.ts`, `BrandForm.spec.ts`.
- Shared parts the page already uses: `modules/agency/components/AgencyPageHeader.vue` (slots `leading`, `title-suffix`, `actions`), `AgencyStatusPill.vue` (a `kind` plus a status table in `modules/agency/status/agencyStatusTones.ts`, kinds include `brand` and `campaign`), the `agency-card`, `agency-fields`, `agency-field` classes (`modules/agency/theme/agency.css:1189-1228`).

**Frame 01 against what exists**

| Frame 01 part                                                                            | Exists?                                                                             |
| ---------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| Sidebar, topbar                                                                          | Yes, unchanged.                                                                     |
| Back arrow, title, Edit, Archive                                                         | Yes. The page also shows a status pill beside the title; the frame does not.        |
| Brand details card: logo, name, slug, industry, website, created, "Monthly deliverables" | Yes, in a full-width single-column card. The frame puts it in a 340 px left column. |
| "Brand name" and "Status" as labelled fields in the card                                 | No. The name is the card title; the status is the pill in the header.               |
| Two-column layout (details left, panels right)                                           | **New.**                                                                            |
| Client files panel (upload, table, types, drop zone)                                     | **New.**                                                                            |
| Send questionnaire, questionnaire row, "Used for USP import", "Client" chip              | **New and out of this chunk** (PL-4, PL-2).                                         |
| Waves panel and New wave button                                                          | **New.**                                                                            |
| Wave progress and non-Draft statuses                                                     | **New and needs later chunks** (PL-1.3, PL-2).                                      |

---

## 2. Campaigns today

- **Model.** `MOD/Campaigns/Models/Campaign.php`: `BelongsToAgency`, `HasUlid`, `SoftDeletes` (`:76-82`); `brand()` is `belongsTo(Brand::class)->withTrashed()` (`:166-169`, so an archived brand still renders); `board()` is a `HasOne` (`:256`). `status` is `draft | active | paused | completed | cancelled` (`MOD/Campaigns/Enums/CampaignStatus.php`).
- **Table.** `API/database/migrations/2026_06_05_100000_create_campaigns_table.php`: `agency_id` FK restrict, **`brand_id` NOT NULL FK restrict**, `name`, `status` default `draft`, `created_by_user_id` FK `users` restrict, many other columns, soft deletes; index `idx_campaigns_agency_brand (agency_id, brand_id)`. Later migrations only add columns (jobs board listing, `creator_posts_content`, region restriction, brand exclusivity).
- **Scoping to an agency.** Three layers: the `BelongsToAgency` global scope (filters by `TenancyContext` agency, `API/app/Core/Tenancy/BelongsToAgencyScope.php`); every agency campaign route is in the `tenancy.agency` + `tenancy` group (3 groups in `MOD/Campaigns/Routes/api.php`); and controllers add `where('campaigns.agency_id', $agency->id)` and `assertBelongsToAgency`. `CampaignController::index` already supports `?brand={ulid}` (`:432-446`): an unknown or foreign brand returns an empty page, not an error.
- **Policy.** `MOD/Campaigns/Policies/CampaignPolicy.php` (view and viewAny for any member; create and update for `agency_admin` and `agency_manager`).
- **Does anything block a one-to-one link from a wave?** No. A wave carries the link (`waves.campaign_id`), so `campaigns` needs **no column, no index and no migration**. Facts that matter:
  - Nothing in the campaign code reads a wave; "wave" appears only in a comment about an incident (`CreatorJobCardResource.php:119`) and a filter comment (`AgencyRelationshipInbox.vue:20`).
  - Campaigns are soft-deleted in schema (`SoftDeletes`) but **no code path deletes a campaign** (searched `campaign->delete(`, no match; no `destroy` route in `MOD/Campaigns/Routes/api.php`). A wave's link to a soft-deleted campaign is therefore theoretical today.
  - **A campaign's brand cannot be changed through the API:** `UpdateCampaignRequest`'s docblock says "`brand_id` / `agency_id` are NOT editable" (`MOD/Campaigns/Http/Requests/UpdateCampaignRequest.php:19`). There is no database-level guard, so the same-brand rule holds after the link is made only because the API has no way to move a campaign. The plan pins this with a test (plan §6) so a later change cannot break it silently.
  - Campaign statuses are not a graph (inventory A5.5); any status may be linked. Whether a `cancelled` or `completed` campaign should be offered is a question for Pedram (plan §11).
- **Existing erasure and board coupling.** Every campaign gets one board (lazy provisioning, `BoardService.php:43-65`). PL-1.1 does not touch it.

---

## 3. File storage

**How private files are stored and served today**

- **Disk.** `Storage::disk('media')`: S3 by default (private visibility), or a local disk when `MEDIA_DISK_DRIVER=local` (`API/config/filesystems.php:125-151`). Local dev runs MinIO from `docker-compose.yml` (S3 endpoint set in `API/.env`; the key `MEDIA_DISK_DRIVER` is **not defined** there, so the S3 path runs against MinIO; values not printed).
- **The pattern to reuse: Agency Files (AH-091).**
  - Service `MOD/Agencies/Services/AgencyFileUploadService.php`. Three calls: `initiatePresignedUpload` (MIME must be in `AttachmentMimeTypes::NON_VIDEO`, declared size 1 byte to 25 MB, server-chosen key `agencies/{agency_ulid}/files/{ulid}.{ext}`, presigned PUT valid 15 minutes with `ContentType` and `ContentLength` signed), `completePresignedUpload` (checks the key starts with this agency's prefix, then derives MIME **from the extension of the server-chosen key** and size from `Storage::size()`, rejecting empty or over-cap objects; it never re-trusts the client MIME), `signedDownloadUrl` (presigned GET, 60 minutes, `Content-Disposition: attachment` with a sanitised file name built from the server extension, returns `null` on a non-S3 disk), `delete` (re-asserts the agency prefix, object first, throws `StorageDeleteFailedException` unless the disk confirms, so a row is never soft-deleted over live bytes).
  - Controller `MOD/Agencies/Http/Controllers/AgencyFileController.php`: `index` (paginated, `per_page` 1 to 100, double-scoped by `agency_id`), `initUpload` and `completeUpload` (`Gate::authorize('create')`, `RuntimeException` becomes 422 `agency_file.presign_failed` or `agency_file.complete_failed`), `show` (404 through `assertBelongsToAgency`), `destroy` (409 `agency_file.attached` if attached, object delete, then soft-delete in a transaction, then audit). Routes `MOD/Agencies/Routes/api.php:164-173`; the literal `files/uploads/*` routes are registered before `files/{file}`.
  - Table `agency_files` (`2026_08_27_130000_create_agency_files_table.php`): `agency_id`, `original_filename`, `storage_key` unique, `mime_type`, `size_bytes`, `category`, `uploaded_by_user_id` nullable, soft deletes. Model `MOD/Agencies/Models/AgencyFile.php`. Resource `AgencyFileResource.php` returns a fresh `download_url` per read (`:40`).
  - Policy `MOD/Agencies/Policies/AgencyFilePolicy.php`: view, viewAny and **create for any member**; delete for admin and manager.
  - SPA: `SPA/modules/files/api/files.api.ts` (init, PUT to the presigned URL through `uploadToPresignedUrl` from `@catalyst/api-client`, complete, show, delete), `files/components/AgencyFileUploadDialog.vue`, `files/pages/AgencyFilesPage.vue`.
- **Limits.** 25 MB (`AgencyFileUploadService::MAX_BYTES`), MIME set `NON_VIDEO` = jpeg, png, webp, gif, pdf, doc, docx, xls, xlsx, txt, csv (`API/app/Core/Storage/AttachmentMimeTypes.php`). **ZIP is not in the set and 100 MB is above the cap**; frame 01's "PDF, DOCX, XLSX, images, ZIP up to 100MB" is not met by the existing service.
- **Virus and content checks.** None. Virus scanning is recorded as out of scope in two sibling services (`AssignmentOfferAttachmentUploadService.php:35`, `RelationshipMessageAttachmentUploadService.php:36`). There is no magic-byte sniff; the MIME is bound to the extension the server chose, and the client-declared MIME only has to be on the allowlist at init.
- **Authorisation.** Through the tenancy stack plus the explicit `assertBelongsToAgency`, plus the agency prefix check in the service. Nothing in storage is public; downloads are short-lived signed URLs.
- **Which pattern the brand client files should reuse.** The presign, complete, signed-download and object-first-delete mechanics of `AgencyFileUploadService`, copied into a brand-scoped service (the codebase already copies this flow into four services; a shared base is a recorded tech-debt item and is not attempted here). **Not** the `agency_files` table itself: its docblock says a file "has no parent" and belongs only to its agency, it feeds the Files library page and the campaign attach picker, it has one category (`campaign_attachment`), and its service pins the key prefix `files/`. A brand-keyed row in that table would show in the Files library and be attachable to a campaign. See plan §1 and open question 3.

---

## 4. Markets

- **A list exists and is reusable.** `COUNTRY_OPTIONS` in `packages/api-client/src/countries.ts:38` is the full ISO 3166-1 alpha-2 list as `{ code, label }`, Phase-1 launch markets first (`LAUNCH_MARKET_COUNTRY_CODES`, `:26`); `labelForCountryCode` is exported next to it. It is re-exported by `SPA/modules/onboarding/data/countries.ts` and used by Discover, roster, pools and the campaign form. Slovakia (`SK`) and Romania (`RO`) are in it (`:226,239`). Labels are English strings, not translated.
- **The nearest stored precedent:** `campaigns.listing_regions`, a `jsonb` array of two-letter codes (`2026_07_27_100000_add_jobs_board_listing_to_campaigns.php:50`), validated as `array`, `max:60`, each `string|size:2|distinct|regex:/^[A-Z]{2}$/` (`MOD/Campaigns/Http/Requests/Concerns/ValidatesJobsBoardListing.php:66,83-84`), picked with a multi-select of `COUNTRY_OPTIONS` (`SPA/modules/campaigns/components/CampaignForm.vue:243,409-421`).
- **No backend list of valid country codes exists** (searched `app` and `config` for a country enum or `Rule::in` list; only the regex shape check). A syntactically valid but unassigned code such as `ZZ` would pass.
- **Not in the design:** frame 09 shows chips with a code badge and the country name, and an "Add market" control. That is a different look from the campaign form's plain `v-select` chips. Built from the existing component or the design system (R10).

---

## 5. Flags

- **Declaration.** A final class per flag with `NAME` and `default()` returning a Closure that returns false, e.g. `MOD/Messaging/Features/MessagesV2InboxEnabled.php` ("MUST return a Closure; Pennant stores a non-Closure second argument as the literal value"). It is registered in the module's service provider with `Feature::define(Name::NAME, Name::default())` (`MessagingServiceProvider.php:24`). Pennant table `features` (`2026_05_07_003823_create_features_table.php`).
- **API check.** Inline in the controller: `if (! Feature::active(Flag::NAME)) { return 404 not_found }` (`AgencyMessageInboxController.php:30`, `AgencyCreatorOwnerController.php:36`). **No flag middleware exists** (searched `app` for `Feature::active` inside `*Middleware*` files; no match).
- **Web check.** `UserResource` (`MOD/Identity/Http/Resources/UserResource.php:60-88`) exposes `features.<name>` booleans on `/me`, `false` without evaluating the flag for a non-agency user (`isAgencySide`); the type is in `packages/api-client/src/types/user.ts:139`; pages read `authStore.user?.attributes.features?.messages_v2_inbox === true` (`SPA/modules/messaging/pages/AgencyMessagesPage.vue:63`). The comment states the rule: UI gate only, the API enforces the flag itself.
- **Admin Feature-flags page.** `MOD/Admin/Http/Controllers/AdminFeatureFlagController.php` holds a map `NAME => ['label', 'description']` (`:120-145` read); the page is `ADM/modules/feature-flags/pages/FeatureFlagsPage.vue`. The map is `private const FLAGS` (`:67`); the list is built from it (`:155`) and a toggle for a name not in it is refused (`:175`), so **a flag that is not added to this map cannot be switched on from the admin page**. (`tests/Feature/Modules/Admin/AdminFeatureFlagTest.php` was not opened.) Toggling needs a reason and writes a `feature_flag.toggled` audit row (`docs/feature-flags.md`).
- **Registry doc.** `docs/feature-flags.md` has one row per flag (Gates, Default, Off-state behaviour, Manual steps).
- **Local seeding.** Walk seeders call `Feature::activate(Flag::NAME)` (`API/database/seeders/Ah115WalkSeeder.php:85`); their cleanup seeders deactivate it.
- **Sprint-table flag family.** The sprint table says each sprint ships behind "its own flag (strategy, portal, editing, AI)". No such flag exists yet (searched `strategy` in `MOD/*/Features`).

---

## 6. Tenancy and authorisation

- **Roles.** `MOD/Agencies/Enums/AgencyRole.php`: `agency_admin`, `agency_manager`, `agency_staff`. Stored on `agency_users.role`, unique `(agency_id, user_id)`. There is **no strategist, editor or client role** (editor is PL-7). The user types and the platform admin are separate (`UserType`, `auth:web_admin`).
- **Isolation, layer by layer.**
  1. `SetTenancyFromAgencyRoute` (`tenancy.agency`, `API/app/Core/Tenancy/SetTenancyFromAgencyRoute.php`): resolves `{agency}`, requires an accepted, non-deleted membership of that agency for the user, else **404** (does not reveal that the agency exists); sets `TenancyContext`.
  2. `EnsureTenancyContext` (`tenancy`): fails closed with a 500 if no context.
  3. `BelongsToAgency` trait: global scope on every query, `agency_id` auto-filled on create from the context, throws on create without one.
  4. Controllers: `assertBelongsToAgency` returning 404, and an explicit `where('agency_id', $agency->id)` on lists ("belt-and-suspenders", `AgencyFileController.php:70`).
  5. Policies: role checks only; cross-tenant access is stopped before them.
- **The rule to follow.** `docs/security/tenancy.md` §3 (middleware), §4 (the cross-tenant allowlist), §6 (review checklist: new route inside the `tenancy` group or allowlisted; new tenant model uses `BelongsToAgency`). A new route inside the standard group is **not** added to the allowlist (the Files routes are not). The logo routes are listed there "for the media-upload discipline" (`tenancy.md:240-241`); the plan lists the new presigned routes the same way.
- **Known sharp edge.** The policy `membership()` helper in `BrandPolicy` and `AgencyFilePolicy` ignores the route agency (inventory F2). New wave and brand-file policies should resolve the role from the current `TenancyContext` agency.
- **Tests that show the shape.** `tests/Feature/Modules/Brands/BrandCrudTest.php`, `tests/Unit/Modules/Brands/BrandPolicyTest.php`, `tests/Feature/Tenancy/SetTenancyContextTest.php`, `tests/Unit/Core/Tenancy/BelongsToAgencyTest.php`, `tests/Feature/Modules/Agencies/AgencyFileApiTest.php` (cross-tenant cases **not read in full**).

---

## 7. Audit and erasure

**Audit**

- Facade `Audit::log(action:, subject:, before:, after:, reason:, ...)` (`MOD/Audit/Facades/Audit.php`, service `MOD/Audit/Services/AuditLogger.php`). It resolves the actor and `agency_id` from the request context; `AuditAction::requiresReason()` forces a reason for the few actions that need one.
- New objects are audited **by hand in the controller** (`brand.created/updated/archived/restored`; `agency_file.uploaded/deleted`), with `before` and `after` snapshots. Verbs live in `MOD/Audit/Enums/AuditAction.php` (brand cases `:76-79`, file cases `:516-517`).
- **Every new case must also be added to the pinned list** in `tests/Feature/Modules/Audit/AuditActionEnumTest.php` (the "Sprint 1-3 catalogue" `toBe` list, `:234-241`), or that test fails. `Sprint3AuditActionsTest.php:60` and `NotificationTypeEnumTest.php` also read `AuditAction::cases()` and were **not read in full**. Audit-only verbs have no `NotificationType` tie (comments at `AuditActionEnumTest.php:211-216`).
- Expected for PL-1.1: audit on wave created, brand file uploaded, brand file deleted. Wave update and delete only if they are built (plan §9).

**Erasure**

- `MOD/Erasure/Registry/ErasureRegistry.php` names **every table** and every column or foreign key that points at `users` or `creators`; `RegistryVerifier` runs in `ErasureRegistrySchemaTest` and **fails CI on an unclassified table or an undeclared user FK**. So a new table is a required edit there, in the same commit.
- Today `brands` and `campaigns` are `NotApplicable` ("Agency-authored", §3.7; `campaigns` declares `created_by_user_id`); `agency_files` is `NotApplicable` and declares `uploaded_by_user_id`; `campaign_attachments` declares `attached_by_user_id` (`ErasureRegistry.php:316-325`).
- **Answer for PL-1.1.** Account erasure is **creator-only** (`docs/reviews/account-deletion-pii-map.md`, D-A; agency users are never erased). Waves and brand client files hold agency-authored data, no creator data and no creator reference, so both new tables are `NotApplicable`. Each carries one reference to a user (`created_by_user_id`, `uploaded_by_user_id`), declared in the registry as the existing tables do. No change to the erasure path, the audit scrub (which keys on `actor_type = 'user'`) or the object purge is needed for this chunk. **Agency deletion does not exist**: the admin agency routes are index, show, suspend, reactivate and member 2FA reset only (`MOD/Admin/Routes/api.php:61-80`), and every agency FK is `restrictOnDelete`. Client uploaders (people outside the agency) do not exist until PL-4, which is where the erasure question for client-held files must be decided (inventory §N3). Not designed here.

---

## 8. i18n

- **Where agency strings live.** `SPA/core/i18n/locales/<locale>/app.json` (namespace `app`; brand strings under `app.brands.*`, campaign strings under `app.campaigns.*`), one folder per locale. The 24 locales are the `UI_LOCALES` registry in `packages/api-client/src/locales.ts`, which mirrors `API/app/Core/Enums/Locale.php` (parity pinned by `packages/api-client/src/locales.spec.ts`).
- **The 24-locale check.**
  - `apps/main/tests/unit/architecture/i18n-locale-parity.spec.ts`: every non-`en` locale has exactly the `en` key set, file by file; same `{named}` placeholders; same plural form count.
  - `i18n-used-keys-resolve.spec.ts`: every `t('…')` call in `SPA` resolves in `en` (static keys exactly; template keys by prefix).
  - A per-feature spec for the "flaky ten" (bg, el, et, fi, ga, hu, lt, lv, mt, ro), e.g. `SPA/core/i18n/messagesV2Keys.spec.ts`: key presence, placeholders, and "not an English copy" across all 24.
  - The standard (`WORKING-PROCESS.md` §4): new keys never ship with English fallback in the flaky ten; "match the surrounding English" is rejected.
- **No machine-translation generator exists in the repo** (inventory K). The tooling to produce the 23 translations is therefore outside the repo; plan §7 and open question 9 state how.
- **Admin SPA.** The Feature-flags page strings are in `ADM`; the flag label and description come from the API map, in English (**the admin locale handling is not read**).
- **API strings.** Error `message` text in `ErrorResponse` is English; the SPA maps error `code` to keys (`i18n-auth-codes.spec.ts` is the pattern for `auth.*`). New wave and file codes need the same mapping (the spec that harvests codes for a prefix is **not read** beyond its header).

---

## 9. Walk baselines

- **Agency baseline: 126 targets** (`docs/reviews/ah-115-agency-baseline/README.md`): 24 routes × dark and light × 1280 and 390 wide (96), 6 crossover targets, 24 auth targets. Run with `node tools/walk/walk.mjs --set agency --compare ../../docs/reviews/ah-115-agency-baseline/baseline.json` from `apps/main` (about one minute, Apple silicon only, mock API through `tools/walk/l3-fixtures.mjs` and `l4-fixtures.mjs`).
- **Targets that touch the Brand page: 16.** The routes `/brands` (`brands`), `/brands/new` (`brand-create`), `/brands/:ulid` (`brand-detail`) and `/brands/:ulid/edit` (`brand-edit`) from `tools/walk/agency-routes.mjs:21-24`, each in 2 themes and 2 widths. Of these only `brand-detail` (4 targets) renders the page PL-1.1 changes; fixtures are `brand-detail-1280` and `brand-detail-1600` in `tools/walk/l4-fixtures.mjs:337,343` (the fixtures were **not opened**). The other 12 reach shared code only through the same shell.
- **Crossover targets** go creator route to `dashboard`, `campaigns` and `messages`; none is a brand page.
- **Adding routes does not change the walk.** `walk.mjs` iterates the fixed `AGENCY_ROUTES` list (`:404`), so a new wave route does not add a target. A new route must still be added to the `requireAgencyUser` arch spec (`tests/unit/architecture/agency-routes-agency-user-guard.spec.ts`, which parses `routes.ts` and pins the expected agency routes; its expected set was **not read in full**).
- **With the flag OFF the 126 must be identical** (the mock `/me` carries no `features.strategy`, so the flag reads false). The creator baseline (146) is not touched: no creator code changes. Per ruling 4 the baselines run at sprint end, and earlier when a shared screen changes; the Brand detail page is a shared agency screen, so run the agency 126 at the end of this chunk.
- **Playwright surfaces.** `apps/main/playwright/specs/brands.spec.ts` and `permissions.spec.ts` (and `playwright/helpers/selectors.ts`) traverse the brand pages (found by file name match; contents **not read**). They are the E2E exposure of this chunk, with the flag OFF.
- **Flag-ON screens have no baseline.** Pedram's local walk and the plan's tests cover them; a wave target set is a PL-1 sprint-end decision.

---

## 10. Not read, in one list

- Figma: `get_design_context` for 13:2 and 13:4000; the node tree of 13:4000; colours, spacing and type values; every other frame (01b, 02 to 13) and the page's prototype links.
- The body of `CampaignController::update` (the request docblock was read, not the controller's whitelist).
- `tests/Feature/Modules/Admin/AdminFeatureFlagTest.php`, `Sprint3AuditActionsTest.php`, `NotificationTypeEnumTest.php`, the cross-tenant cases of `AgencyFileApiTest.php`, `BrandCrudTest.php` in full.
- `tools/walk/l4-fixtures.mjs` brand-detail fixtures, the `requireAgencyUser` arch spec's expected set.
- The admin SPA's locale handling for flag descriptions; the error-code harvest spec for new API codes.
- Production state, staging (does not exist), the deploy log beyond its header.
