# PL-1.1 plan: Brand and new wave

**Date:** 2026-11-17. **Branch:** `pl`. **Status:** plan only, for Pedram's read. **No code, no migration run, no push.** No Cursor build prompt is issued until Pedram has read this and said go (`production-line-sprints.md` §2, ruling 9).
**Inputs:** `pl-1-1-inventory.md` (read it first; section numbers below point into it), `production-line-sprints.md` §6 (rulings R1 to R10, binding), Figma frames 13:2 and 13:4000.
**Standing rules applied:** schema-only migrations, the alarm rule, break-revert on every gate, the 24-locale gate, `WORKING-PROCESS.md` §4.1 local-run rules.

**Section numbers** follow the brief (1 data model to 12 size and order); section 8, the PROD-DATA RISK line, is placed first because the alarm rule puts it before anything else.

**Goal.** An agency user opens a brand, sees its details, its client files and a table of its waves, and creates a wave from the New wave dialog. All behind a new flag that is OFF by default. With the flag OFF the agency screens are unchanged.

---

## 8. PROD-DATA RISK (stated first, before any code)

**PROD-DATA RISK: NONE.**

- Two **new, empty tables** (`waves`, `brand_files`). No existing table is altered, no index is added to an existing table, no column is added to `brands` or `campaigns`.
- No migration writes data. No backfill, no seed of business data, no default flip. No data command is needed.
- The feature writes rows and S3 objects only when someone uses it, and only with the flag ON. Flag OFF: every new endpoint answers 404 and nothing is written.
- Blast radius of a bug: confined to the two new tables and the new `brands/{brand}/files/` prefix in the media bucket. No existing row or object is read-modify-written.
- **Carried to the sprint-boundary merge.** The migrations reach production only when `pl` is merged into `main` and deployed, so they join that merge's snapshot-and-migrate obligations (`production-line-sprints.md` §3). Nothing is deployed from `pl`.

---

## 1. Data model

### 1.1 Table `waves` (new module `Strategy`, model `Wave`)

| Column                                   | Type                                                    | Rule                                                                                                                      |
| ---------------------------------------- | ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `id`                                     | bigint PK                                               |                                                                                                                           |
| `ulid`                                   | ulid, unique                                            | Public id (`HasUlid`).                                                                                                    |
| `agency_id`                              | bigint FK `agencies`, `restrictOnDelete`                | Tenant scope. Model uses `BelongsToAgency`.                                                                               |
| `brand_id`                               | bigint FK `brands`, `restrictOnDelete`                  | Required.                                                                                                                 |
| `campaign_id`                            | bigint FK `campaigns`, `restrictOnDelete`, **nullable** | The optional link (R1, R4).                                                                                               |
| `name`                                   | string(160)                                             | Required, trimmed.                                                                                                        |
| `go_live_on`                             | date                                                    | Required. A plain date, no time zone (the `exclusivity_ends_on` shape).                                                   |
| `markets`                                | jsonb                                                   | Array of two-letter uppercase ISO codes. See 1.3.                                                                         |
| `status`                                 | string(16), default `draft`                             | Enum `WaveStatus`, one case (`draft`) in this chunk. Later chunks add cases; a string column needs no migration for that. |
| `created_by_user_id`                     | bigint FK `users`, `restrictOnDelete`                   | Same shape as `campaigns.created_by_user_id`.                                                                             |
| `created_at`, `updated_at`, `deleted_at` | timestamps, soft delete                                 | Soft delete is in the schema now so a later delete needs no migration.                                                    |

Indexes: **partial unique `unique_waves_campaign_id` on `(campaign_id) WHERE deleted_at IS NULL`**, plus `idx_waves_brand_updated (brand_id, updated_at)` and `idx_waves_agency_id (agency_id)`.

**One wave per campaign, in the database (R1).** The partial unique index is the guard. A null `campaign_id` never collides with another null (unique indexes treat nulls as distinct on Postgres and SQLite), so any number of unlinked waves is fine. Soft-deleted waves do not hold a campaign (the `unique_brand_creator_blacklist` precedent, `2026_06_04_100000_create_brand_creator_blacklists_table.php:82`, raw `CREATE UNIQUE INDEX … WHERE deleted_at IS NULL`, which runs on the SQLite test database as well). The other direction, **a wave has at most one campaign**, is the single nullable column.

**Same brand for the link, server-side.** In the wave service, not the database: the campaign is looked up with `agency_id = route agency` **and** `brand_id = route brand`. A campaign that is in another brand, in another agency, or does not exist gives the **same** 422 `wave.campaign_not_available` with the same body, so the response does not reveal whether a foreign campaign exists. The reason it is not a composite foreign key: that needs a new unique index on `campaigns (id, brand_id)`, which alters an existing production table. Not worth it this chunk (open question 13). What keeps the rule true after the link: `brand_id` is not editable on a campaign (`UpdateCampaignRequest.php:19`); a test pins that (6).

### 1.2 Table `brand_files` (model `BrandFile`, in the `Brands` module)

Mirrors `agency_files` (inventory §3) with a brand parent:

| Column                   | Type                                                                                                       |
| ------------------------ | ---------------------------------------------------------------------------------------------------------- |
| `id`, `ulid` unique      |                                                                                                            |
| `agency_id`              | FK `agencies`, restrict (tenant scope, `BelongsToAgency`)                                                  |
| `brand_id`               | FK `brands`, restrict                                                                                      |
| `original_filename`      | string(255)                                                                                                |
| `storage_key`            | string(512), unique                                                                                        |
| `mime_type`              | string(128)                                                                                                |
| `size_bytes`             | unsigned bigint                                                                                            |
| `file_type`              | string(16): `brief`, `research`, `guidelines`, `assets`, `other` (the four chips in frame 01 plus `other`) |
| `uploaded_by_user_id`    | nullable FK `users`, `nullOnDelete` (as `agency_files`)                                                    |
| timestamps, `deleted_at` |                                                                                                            |

Index `idx_brand_files_brand_created (brand_id, created_at)`.

**Why a new table and not `agency_files` plus a `brand_id`** (open question 3): `agency_files` is the Files library by design ("no parent"), feeds `AgencyFilesPage` and the campaign attach picker, and its service pins the key prefix `files/`. A brand-keyed row there would appear in the Files library and be attachable to campaigns, which is a product change nobody ruled on. A separate table keeps the library unchanged and keeps the flag-OFF screens unchanged. The cost is one more copy of the presign flow (the codebase has four; a shared base is already a recorded tech-debt item, not attempted here).

**Storage key:** `agencies/{agency_ulid}/brands/{brand_ulid}/files/{file_ulid}.{ext}` on the `media` disk. The service asserts that prefix on complete and on delete.

### 1.3 How markets are stored

A `jsonb` array of ISO 3166-1 alpha-2 codes, uppercase, distinct, in the order the user picked them: `["SK","RO"]`. Same shape and rules as `campaigns.listing_regions` (inventory §4): `array`, **min 1, max 20**, each `string|size:2|distinct|regex:/^[A-Z]{2}$/`. The picker uses `COUNTRY_OPTIONS` and `labelForCountryCode` from `@catalyst/api-client` (the list the Discover and campaign screens already use). The API checks the shape only, as campaigns do, because no backend country list exists; `ZZ` would pass (stated limit, open question 11). A separate `markets` table is not needed: the table column shows chips and PL-2's per-market personas will key on the code string.

---

## 2. Migrations

Two files, additive and schema-only, no data writes, honest `down()`:

1. `2026_11_17_100000_create_waves_table.php`: `Schema::create('waves', …)` plus the raw partial unique index.
2. `2026_11_17_100001_create_brand_files_table.php`.

`down()` is `Schema::dropIfExists`, with a comment that it destroys data once rows exist, so it is only a rollback tool before the flag is armed. **Not touched:** `brands`, `campaigns`, `agency_files`, `features`. Nothing runs against a database in this chunk's planning; in the build, migrations run on the local and in-memory test databases only.

Required in the same commits, or CI fails:

- `ErasureRegistry.php`: classify `waves` and `brand_files` as `NotApplicable`, "Agency-authored (§3.7)", declaring `created_by_user_id` and `uploaded_by_user_id` as user references, next to `agency_files` (`ErasureRegistry.php:324`). `ErasureRegistrySchemaTest` enforces it (inventory §7). `waves.name` is agency-authored text; no creator data.
- `AuditActionEnumTest.php`: the new action values in the pinned list (3.5).

---

## 3. API

All routes are in the standard stack `auth:web` + `tenancy.agency` + `tenancy`, prefix `agencies/{agency}`, nested under the brand. They are **not** cross-tenant routes; the two presigned-upload routes get an allowlist row "NOT a scope bypass, listed for the presigned-upload discipline" in `docs/security/tenancy.md` §4, the logo-route precedent (`tenancy.md:240`). New module `MOD/Strategy` (provider added to `bootstrap/providers.php`, alphabetical); brand files live in `MOD/Brands`. Response shape follows `BrandResource`: `{ data: { id: <ulid>, type, attributes, relationships } }`; no internal ids.

**Flag gate.** Every endpoint below starts with `if (! Feature::active(StrategyEnabled::NAME)) return 404 not_found`, the inline pattern of the five existing flags (inventory §5; no flag middleware exists, so none is invented). Before the policy and before the body is read. A route-table test covers all of them (6).

### 3.1 Waves

| Method and path                             | Purpose                                              | Notes                                                                                                                                                                                                                                                  |
| ------------------------------------------- | ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `GET brands/{brand}/waves`                  | The waves table                                      | `per_page` 1 to 100 (default 25; the SPA asks for 100, no pager, a brand will not have 100 waves). Order `updated_at desc, id desc` (the frame's order). Double-scoped by `agency_id` and `brand_id`. Resolves an **archived** brand (read-only page). |
| `POST brands/{brand}/waves`                 | Create                                               | Body below. 201 with the wave.                                                                                                                                                                                                                         |
| `GET brands/{brand}/waves/campaign-options` | The dropdown source                                  | Literal route, registered **before** `{wave}`.                                                                                                                                                                                                         |
| `GET brands/{brand}/waves/{wave}`           | Wave page shell                                      | 404 if the wave is not in this brand and agency. Resolves an archived brand.                                                                                                                                                                           |
| `PATCH brands/{brand}/waves/{wave}`         | **Optional sub-step E** (section 9, open question 2) | `name`, `go_live_on`, `markets` only. Not the link, not the status.                                                                                                                                                                                    |

**Create body and rules** (a `FormRequest`; unknown keys ignored; every accepted field must persist, the lesson in `CampaignController.php:128-130`, pinned by a test):

- `name`: required, string, trimmed, 1 to 160.
- `go_live_on`: required, `date_format:Y-m-d`. **Past dates are allowed** (a brand's earlier waves, like frame 01's Wave 5, have to be enterable); open question 10.
- `markets`: required array, min 1, max 20, each `size:2`, `distinct`, `regex:/^[A-Z]{2}$/`.
- `campaign_id`: nullable, string, a ULID (26 chars). Absent or null = no link (R4: the link is optional at creation).

**Service rules** (`WaveService::create`, in one DB transaction):

1. The brand is the route brand, in the route agency. An **archived brand** gives 422 `wave.brand_archived`.
2. If `campaign_id` is given, look the campaign up with `agency_id` and `brand_id` of the route; none found gives 422 `wave.campaign_not_available` (covers other brand, other agency, deleted, unknown).
3. Insert. A `UniqueConstraintViolationException` on `unique_waves_campaign_id` (the campaign already has a wave, including the two-requests-at-once race) maps to 422 `wave.campaign_already_linked`. The pre-check that returns the same code is a courtesy; the index is the guard.
4. `created_by_user_id` is the actor. `status` is `draft`. `agency_id` comes from the route agency.
5. `Audit::log(AuditAction::WaveCreated, subject: wave, after: snapshot)`.

**Campaign dropdown (`campaign-options`).** Returns only: campaigns of **this brand and this agency**, not soft-deleted, **with no wave** (`whereNotIn('campaigns.id', waves.campaign_id where deleted_at is null)`; a query on the wave side, so the `Campaigns` module is not edited, and the `wave()` relation on `Campaign` arrives with PL-1.2 where the campaign side shows the link). Shape per item: `id` (ulid), `name`, `status`. Ordered by name, unpaginated (max 200, a hard cap; a brand will not have more). Any status is offered (open question 6), and the status is shown so the user can tell. A campaign leaves the list the moment a wave links to it, and returns if the wave is soft-deleted.

**Errors**

| Case                                                                                 | Response                                         |
| ------------------------------------------------------------------------------------ | ------------------------------------------------ |
| Flag off                                                                             | 404 `not_found` (nothing written or audited)     |
| Not signed in / not a member of the agency                                           | 401 / 404 (tenancy middleware)                   |
| Brand or wave of another agency, or wave of another brand                            | 404 (`assertBelongsToAgency`-style, 404 not 403) |
| Role not allowed                                                                     | 403                                              |
| Validation                                                                           | 422 with field errors                            |
| `wave.brand_archived`, `wave.campaign_not_available`, `wave.campaign_already_linked` | 422                                              |

### 3.2 Brand client files

| Method and path                              | Purpose                                                                                                                                                                   |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `GET brands/{brand}/files`                   | The files table (newest first, `per_page` 1 to 100). Resolves an archived brand.                                                                                          |
| `POST brands/{brand}/files/uploads/init`     | Body `mime_type`, `declared_bytes`, `file_type`. Returns the presigned PUT, `upload_id`, `storage_path`, `expires_at`, `max_bytes`.                                       |
| `POST brands/{brand}/files/uploads/complete` | Body `upload_id`, `original_filename`, `file_type`. Verifies the object, creates the row, audits. 201.                                                                    |
| `GET brands/{brand}/files/{file}`            | One file with a **fresh** signed `download_url` (60 minutes, `Content-Disposition: attachment`). The list also carries a fresh URL per row, as `AgencyFileResource` does. |
| `DELETE brands/{brand}/files/{file}`         | Object first, then soft-delete the row in a transaction, then audit. 204.                                                                                                 |

The literal `files/uploads/*` routes are registered before `files/{file}`.

**Rules.** `BrandFileUploadService` is a copy of the `AgencyFileUploadService` mechanics (inventory §3), with these differences:

- **Cap 100 MB**, not 25 (frame 01's drop-zone text). A single presigned PUT is enough (S3 allows far more; the URL only has to be valid when the request starts). No multipart, no resume (that is PL-6).
- **MIME set:** `AttachmentMimeTypes::NON_VIDEO` plus ZIP (`application/zip` and `application/x-zip-compressed`, both to the extension `zip`), kept as a constant in the new service. `AttachmentMimeTypes` itself is not edited (its `ALL` is pinned byte for byte). Images, PDF, DOC, DOCX, XLS, XLSX, TXT, CSV, ZIP. No video, no SVG, no executables.
- **Server-side verification on complete:** the key must start with this brand's prefix; MIME comes from the extension of the server-chosen key, never from the client; size from `Storage::size()`; empty or over-cap objects are refused.
- **Download only through the authenticated API,** as a short-lived signed URL forced to `attachment`. No public URL, no inline rendering (matters for ZIP and images with no virus scan).
- **No virus scan and no magic-byte check.** Same as every upload surface today (inventory §3). Stated, not fixed (open question 5).
- `file_type` is one of the five values; invalid gives 422.
- Delete is object-first; a failed object delete gives 500 `brand_file.delete_failed` and leaves the row (the `StorageDeleteFailedException` path).
- An **archived brand** is read-only: upload and delete give 422 `brand_file.brand_archived`; list and download work.
- Errors: 422 `brand_file.presign_failed`, `brand_file.complete_failed`; 500 `brand_file.delete_failed`; the tenancy and flag rows as for waves. The SPA maps each `code` to a string (`i18n-auth-codes.spec.ts` is the pattern).

### 3.3 Policies (open question 4)

New `WavePolicy` and `BrandFilePolicy` in the Strategy and Brands modules. Unlike `BrandPolicy::membership()`, they resolve the role from the **current `TenancyContext` agency** (inventory §6), not "the user's first membership".

| Action                                           | `agency_admin` | `agency_manager` | `agency_staff` |
| ------------------------------------------------ | -------------- | ---------------- | -------------- |
| View waves and files, download                   | yes            | yes              | yes            |
| Create a wave (and edit, if sub-step E is built) | yes            | yes              | no             |
| Upload a client file                             | yes            | yes              | no             |
| Delete a client file                             | yes            | yes              | no             |

This follows the brand and campaign create rules ("staff cannot create brands", `AgencyRole.php`). `AgencyFilePolicy` lets any member upload; the plan chooses the narrower rule for client material, to be confirmed.

### 3.4 Flag on `/me`

`UserResource` gains `features.strategy` (agency-side users only; `false` without evaluating the flag for creators and platform admins), the `messages_v2_inbox` shape (inventory §5). `packages/api-client/src/types/user.ts` gets `strategy?: boolean`.

### 3.5 Audit and erasure

- New `AuditAction` cases: `WaveCreated = 'wave.created'`, `BrandFileUploaded = 'brand_file.uploaded'`, `BrandFileDeleted = 'brand_file.deleted'` (plus `WaveUpdated = 'wave.updated'` if sub-step E is built). Logged by hand in the controllers with `before` and `after` snapshots, no reason required, no `NotificationType` tie. Each is added to the pinned list in `AuditActionEnumTest.php`, and the other two tests that read `AuditAction::cases()` are re-run (inventory §7).
- Erasure: no change beyond the two registry rows (section 2). Stated: waves and brand files touch no creator data; agency deletion does not exist; client uploaders arrive in PL-4, which is where their erasure is decided.

---

## 4. Web

New files live in `SPA/modules/brands/` (files and the two panels) and `SPA/modules/waves/` (the dialog and the wave page). `packages/api-client` gets `types/wave.ts`, `types/brandFile.ts` and the `strategy` feature type.

### 4.1 What is built, and where it comes from

| Part                                                                                                                                                                                        | Figma                                                                                        | Built from                                                                                                                                                                                                                                                                                                                                                                                     |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Two-column Brand page: details card left, panels right                                                                                                                                      | Frame 01 (13:98, 13:99, 13:127)                                                              | Existing card kept as is, in a column; new side column `agency-detail__side`. Only when the flag is ON (4.3).                                                                                                                                                                                                                                                                                  |
| Brand details card contents                                                                                                                                                                 | Frame 01 (13:99)                                                                             | **Existing** card unchanged. The frame's "Brand name" and "Status" rows are not added: the name is the card title and the status the header pill (open question 8).                                                                                                                                                                                                                            |
| Header: back, title, Edit, Archive                                                                                                                                                          | Frame 01 (13:87)                                                                             | **Existing** `AgencyPageHeader`. Unchanged.                                                                                                                                                                                                                                                                                                                                                    |
| Client files panel: title, subtitle, **Upload files** button                                                                                                                                | Frame 01 (13:128 to 13:133)                                                                  | `agency-card`, `v-btn`. Subtitle string from the frame.                                                                                                                                                                                                                                                                                                                                        |
| **Send questionnaire** button, the green questionnaire row, "Used for USP import" tag                                                                                                       | Frame 01                                                                                     | **Not built.** PL-4 and PL-2.                                                                                                                                                                                                                                                                                                                                                                  |
| Files table: FILE (icon, name, "PDF · 2.4 MB"), TYPE chip, UPLOADED BY, DATE, row menu                                                                                                      | Frame 01 (13:172 to 13:284)                                                                  | `v-table` in an `agency-card`; type as `v-chip`; the row menu is `v-menu` with Download and Delete (delete confirm in a `v-dialog`, the archive-dialog pattern). The **Client / Catalyst chip is not built**: every uploader is agency staff in this chunk, so the chip would say nothing; the uploader's name only (open question 8).                                                         |
| Drop zone "Drag files here to upload · PDF, DOCX, XLSX, images, ZIP up to 100MB"                                                                                                            | Frame 01 (13:284)                                                                            | Dashed area; accepts drag and click; opens the upload dialog.                                                                                                                                                                                                                                                                                                                                  |
| Upload dialog (type select, progress, per-file result)                                                                                                                                      | **No design, built from the design system**                                                  | `v-dialog`, `v-select` for type (Brief, Research, Guidelines, Assets, Other, default Other), `v-progress-linear`, `v-alert` for per-file errors. Multi-file, uploaded one after another, one type for the batch. Pattern of `AgencyFileUploadDialog.vue`.                                                                                                                                      |
| Files empty state and error state                                                                                                                                                           | **No design, built from the design system**                                                  | Text row in the card, `v-alert type="error"`.                                                                                                                                                                                                                                                                                                                                                  |
| Waves panel: title, subtitle, **New wave** button                                                                                                                                           | Frame 01 (13:291 to 13:296)                                                                  | `agency-card`, `v-btn`.                                                                                                                                                                                                                                                                                                                                                                        |
| Waves table: WAVE (name, "Go-live …"), MARKETS (code chips), PROGRESS, STATUS pill, UPDATED, chevron, whole row clickable; footer line                                                      | Frame 01 (13:301 to 13:427)                                                                  | `v-table`; markets as `v-chip` showing the code; status via `AgencyStatusPill` with a new `wave` kind; footer line from the frame.                                                                                                                                                                                                                                                             |
| PROGRESS column                                                                                                                                                                             | Frame 01                                                                                     | Six empty segments and the text "Not started" for every wave in this chunk (open question 7). The segments component is built now so PL-1.3 only feeds it data.                                                                                                                                                                                                                                |
| UPDATED column                                                                                                                                                                              | Frame 01 ("Today", "Yesterday", "Sep 2")                                                     | Date with `formatDateTime(…, { dateStyle: 'medium' })` as the Brand page does. The relative words are **not built** (deviation; no shared relative-date helper was read).                                                                                                                                                                                                                      |
| Waves empty, loading and error states                                                                                                                                                       | **No design, built from the design system**                                                  | `v-skeleton-loader`, text row with the New wave button, `v-alert`.                                                                                                                                                                                                                                                                                                                             |
| **New wave dialog:** title "New wave", brand name as subtitle, close button, **Wave name**, **Go-live date**, **Markets** chips with code badge and **Add market**, Cancel, **Create wave** | Frame 09                                                                                     | `v-dialog`, `v-text-field` (date as `type="date"`, the `CampaignForm` precedent for dates), a new `MarketPicker.vue` over `COUNTRY_OPTIONS` (chips with code badge and name, removable, "Add market" opens a searchable `v-menu` list). Look follows the frame; the control is built from the design system.                                                                                   |
| Dialog: **campaign link field**                                                                                                                                                             | **No design** (frame 09 has none; R4)                                                        | `v-select` "Link a campaign (optional)", first item "No campaign", then the options from `campaign-options` with a status pill each. Placed under Markets. Helper text says the campaign can be linked later (PL-1.2 adds that).                                                                                                                                                               |
| Dialog: **Start from** (copy from a previous wave, start fresh)                                                                                                                             | Frame 09                                                                                     | **Not built.** Copy needs USPs, personas, matrix and creators (PL-2, PL-3) and is a data operation. The dialog creates a blank wave, which is what "Start fresh" means.                                                                                                                                                                                                                        |
| **Wave page shell** (details only)                                                                                                                                                          | **No design** (frames 02 to 08 are step pages and were not read; the tracker is 01b, PL-1.3) | `AgencyPageHeader` (wave name; subtitle = brand name as a link back, the AH-116 subtitle-slot precedent; `AgencyStatusPill`), one `agency-card` with the `agency-fields` list: Go-live date, Markets (chips), Linked campaign (name linking to `/campaigns/:ulid`, or "No campaign linked"), Created by, Created, Updated. Back button to the brand page. No tracker, no steps, no empty tabs. |
| Sidebar                                                                                                                                                                                     | Frame 01                                                                                     | **Unchanged.** No new nav item (design review §2).                                                                                                                                                                                                                                                                                                                                             |

### 4.2 Routes and files

- New route `/brands/:ulid/waves/:waveUlid`, name `waves.detail`, `meta: { layout: 'agency', guards: ['requireAuth', 'requireAgencyUser'] }`, in `SPA/modules/auth/routes.ts` next to `brands.edit`. Added to the expected set in `agency-routes-agency-user-guard.spec.ts`. With the flag off the page redirects to `brands.detail` (and the API would 404 anyway).
- New files (about 14): `brands/api/brandFiles.api.ts`, `brands/components/BrandClientFilesPanel.vue`, `brands/components/BrandFileUploadDialog.vue`, `brands/components/BrandWavesPanel.vue`, `waves/api/waves.api.ts`, `waves/components/NewWaveDialog.vue`, `waves/components/MarketPicker.vue`, `waves/components/WaveProgressSegments.vue`, `waves/pages/WaveDetailPage.vue`, plus their specs.
- Edited: `BrandDetailPage.vue`, `auth/routes.ts`, `modules/agency/status/agencyStatusTones.ts` (a `wave` kind: `draft` is a neutral tone; the table is parity-pinned to the api-client type, `agency-status-tone.spec.ts`), `agency.css` (the two-column modifier), `packages/api-client` types and index, the 24 `app.json` files.

### 4.3 Flag OFF leaves the screens unchanged

`BrandDetailPage.vue` reads `authStore.user?.attributes.features?.strategy === true`. The existing markup is **not restructured**: the flag adds a modifier class on the existing `agency-detail` element and a sibling `agency-detail__side` block, both rendered only when ON. With the flag OFF the rendered DOM and computed styles are the ones recorded in the agency baseline, so the 16 brand-page targets must compare identical (section 6, tests T-W1 and the walk).

---

## 5. Flag

- **Name:** `strategy_enabled` (open question 1), default **OFF**. Class `MOD/Strategy/Features/StrategyEnabled.php` with `NAME` and a `default()` that returns a Closure returning false, registered with `Feature::define` in `StrategyServiceProvider` (inventory §5). The sprint table gives each sprint family one flag (strategy, portal, editing, AI); PL-1 to PL-3 and the strategy half of PL-5 share this one.
- **Admin page:** a new entry in `AdminFeatureFlagController::FLAGS` (label "Strategy and script writing", plain description saying what is hidden when off). Without that entry it cannot be switched on (inventory §5).
- **Registry:** a new row in `docs/feature-flags.md`: gates, default off, off-state behaviour, manual steps ("None, gates no vendor").
- **Hidden when OFF:** the Client files panel, the Waves panel and the New wave dialog on the Brand page; the wave page route (redirects to the brand page); all nine API endpoints (404 `not_found`); `features.strategy` is false on `/me`. **Nothing else changes.** Brand page, brands list, Files library, campaigns, sidebar, dashboard are byte-identical.
- **What the flag does not gate:** the two tables exist after the migrations (empty). The Feature-flags admin page shows the flag whether it is on or off.

---

## 6. Tests

Backend is Pest, web is Vitest. Every gate has a break-revert (`WORKING-PROCESS.md` §4: mutate the gate, watch the right spec fail, revert, check `git status` and `git diff` clean, re-green) and a disjoint, complete negative set (§5.34).

**Backend**

| #   | Test                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | Break-revert                                                                                                                                                                                                        |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| B1  | **Flag gate.** For each of the nine endpoints (list, create, campaign-options, show, files list, init, complete, show, delete; plus the edit if built): flag OFF gives 404 `not_found`, no row, no S3 object, no audit row; flag ON works. A route-table test enumerates every route in the two new route files and fails if one is missing from the gate test. Fresh database: `Feature::active` is false. `/me` `features.strategy` false by default, true when armed, false for a creator and a platform admin.                                          | Remove the flag check from one controller action; the matching case fails. Flip `default()` to true; the default test fails.                                                                                        |
| B2  | **Tenancy, waves.** User of agency B: list brand A's waves on A's path, on B's path with A's brand ulid, A's wave ulid on B's path, create on A's brand, and (if built) edit: all 404, none reveals existence. A wave of brand 2 requested under brand 1 of the same agency: 404. `BelongsToAgency` scope check on `Wave` (query under agency B context never returns A's rows).                                                                                                                                                                            | Remove `assertBelongsToAgency` (or the `agency_id` where) in the controller; the cross-agency case returns data and fails.                                                                                          |
| B3  | **Tenancy and authorisation, files.** Agency B on A's brand: list, init, complete, show, delete all 404. `upload_id` that carries agency A's prefix, submitted by agency B on B's own brand: 422 (prefix check). A `brand_files` row of brand 1 requested under brand 2: 404. Download URL appears only in authenticated responses.                                                                                                                                                                                                                         | Remove the prefix assertion in `completePresignedUpload`; the foreign-`upload_id` case fails. Remove the brand/agency ownership check; the cross-brand case fails.                                                  |
| B4  | **Same-brand link.** Campaign of another brand in the same agency, campaign of another agency, deleted campaign, unknown ulid: each 422 `wave.campaign_not_available` with an **identical** body (no existence leak). Own-brand unlinked campaign: 201 and `campaign_id` set.                                                                                                                                                                                                                                                                               | Drop the `brand_id` condition from the lookup; the other-brand case returns 201 and fails.                                                                                                                          |
| B5  | **One wave per campaign.** API: a second wave for a linked campaign gives 422 `wave.campaign_already_linked`. **Database level, bypassing the service:** two direct inserts with one `campaign_id` throw the unique violation; two inserts with null campaign are fine; after soft-deleting the first wave the campaign can be linked again. Race: the unique-violation is mapped to the 422, not a 500.                                                                                                                                                    | Remove the partial unique index from the migration (schema test and the direct-insert test fail). Remove the service pre-check and the exception mapping separately (the API case then fails / the race gives 500). |
| B6  | **Campaign-options.** Returns only this brand's campaigns with no wave; excludes another brand's, another agency's, soft-deleted ones and ones already linked; a campaign reappears when its wave is soft-deleted; carries `status`. **Pin:** `UpdateCampaignRequest` ignores or rejects `brand_id` (the same-brand rule holds after the link).                                                                                                                                                                                                             | Remove the `whereNotIn` linked filter; the linked-campaign case fails.                                                                                                                                              |
| B7  | **Roles.** Policy unit tests for both policies across admin, manager and staff for every action in 3.3; feature tests: staff creating a wave, uploading, deleting a file each 403; staff can list and download. Policy resolves the role from the route agency, with a user who is staff in agency A and admin in agency B acting on A: 403.                                                                                                                                                                                                                | Widen `create` to staff; the staff case fails. Revert to "first membership"; the two-agency case fails.                                                                                                             |
| B8  | **Validation negatives (wave).** Empty and 161-character name; non-date and wrongly formatted date; markets empty, 21 entries, lowercase, three letters, duplicate, non-string; `campaign_id` of the wrong length; archived brand gives 422 `wave.brand_archived`. Every accepted field persists and is returned (mass-assignment whitelist).                                                                                                                                                                                                               | Drop one field from the create whitelist; the persistence test fails.                                                                                                                                               |
| B9  | **Files rules.** MIME allowlist (jpeg, png, pdf, docx, xlsx, zip pass; exe, svg, mp4 fail); `declared_bytes` 0 and over 100 MB fail; complete with a missing object, an empty object, an over-cap object and a wrong extension fail; MIME on the row comes from the key, not the client; invalid `file_type` fails; the signed URL forces `Content-Disposition: attachment` (the `AgencyFileSignedDownloadDispositionTest` shape); a failing object delete returns 500 and keeps the row; archived brand blocks upload and delete but not list or download. | Make MIME client-trusted in `complete`; the extension-derived test fails. Swallow the delete exception; the keep-the-row test fails.                                                                                |
| B10 | **Audit.** `wave.created`, `brand_file.uploaded`, `brand_file.deleted` rows exist with actor, agency and subject; none on a refused request. `AuditActionEnumTest` pinned list updated.                                                                                                                                                                                                                                                                                                                                                                     | Remove the `Audit::log` call; the audit case fails.                                                                                                                                                                 |
| B11 | **Schema.** Both tables, columns, FKs restrict, the partial unique index, `storage_key` unique; `ErasureRegistrySchemaTest` passes with the two new rows.                                                                                                                                                                                                                                                                                                                                                                                                   | Remove a registry row; the schema test fails.                                                                                                                                                                       |
| B12 | **Resource shape.** Ulids only, no internal ids, no `agency_id`, dates as `Y-m-d` and ISO.                                                                                                                                                                                                                                                                                                                                                                                                                                                                  | none (shape)                                                                                                                                                                                                        |
| B13 | **Admin page.** The flag is listed with its label and description, can be toggled with a reason, writes `feature_flag.toggled`.                                                                                                                                                                                                                                                                                                                                                                                                                             | Remove the entry from `FLAGS`; the listing test fails.                                                                                                                                                              |

**Web**

| #   | Test                                                                                                                                                                                                                                                                                                                                           | Break-revert                                                  |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| W1  | **Flag OFF identity.** `BrandDetailPage` with `features.strategy` absent or false renders no panel and no modifier class; its markup anchors are the current ones (the existing spec stays green unmodified). Flag ON renders both panels.                                                                                                     | Render the panels regardless of the flag; the OFF case fails. |
| W2  | `BrandWavesPanel`: rows and column content, markets as codes, empty, loading, error states; New wave button hidden for staff; row click goes to `waves.detail`.                                                                                                                                                                                |                                                               |
| W3  | `NewWaveDialog`: required fields, markets 1 to 20, campaign list loaded, "No campaign" sends `campaign_id: null`, a chosen campaign sends its ulid, 422 codes mapped to the right messages, success refreshes the list and closes.                                                                                                             |                                                               |
| W4  | `MarketPicker`: add, remove, no duplicates, order kept, code badge.                                                                                                                                                                                                                                                                            |                                                               |
| W5  | `BrandClientFilesPanel` and `BrandFileUploadDialog`: list, type chip, download opens the signed URL, delete with confirm, role gating (staff sees no Upload or Delete), upload flow with the mocked `files` calls (init, PUT, complete), per-file error, over-size and wrong-type rejected before bytes move.                                  |                                                               |
| W6  | `WaveDetailPage`: shell fields, linked campaign link, "No campaign linked", flag-off redirect, 404 state.                                                                                                                                                                                                                                      |                                                               |
| W7  | **Architecture specs** (existing, extended): `agency-routes-agency-user-guard` (new route name), `agency-status-tone` (`wave` kind), `i18n-locale-parity`, `i18n-used-keys-resolve`, plus a new `waveKeys.spec.ts` in the `messagesV2Keys.spec.ts` shape (presence, placeholders, not-an-English-copy across all 24, including the flaky ten). | Delete one key in one locale; parity fails.                   |
| W8  | `vue-tsc`, ESLint, Prettier on touched packages.                                                                                                                                                                                                                                                                                               |                                                               |

**Gates before the push Pedram will clear:** full Pest (serial, `-d memory_limit=2G`), full Vitest on `apps/main`, `apps/admin` and `packages/api-client`, `vue-tsc`, ESLint, Pint `--all`, PHPStan/Larastan, locale parity. Playwright: `apps/main/playwright/specs/brands.spec.ts` and `permissions.spec.ts` traverse the brand pages (inventory §9), so they run once at the end of the chunk with the flag OFF. The agency walk (126 targets) runs once at the end: **16 brand targets, all must be identical with the flag OFF.** Local runs follow `WORKING-PROCESS.md` §4.1: clean slate (`pkill -f vitest; pkill -f playwright; pkill -f walk.mjs`), one suite at a time, `VITEST_MAX_WORKERS=4`, Pest serial, Playwright `--workers=2`, no backgrounding another run. CI at the pushed tip must be green and cited (§5.41).

---

## 7. Strings

All strings in `app.json` under two new namespaces. About 65 keys. Each is written in en and in the 23 other locales; none ships as an English copy in the flaky ten (bg, el, et, fi, ga, hu, lt, lv, mt, ro). Country names are the existing English labels from `COUNTRY_OPTIONS` (untranslated today across the app; not changed here).

**`app.waves.*`** (about 38)

- `panel`: `title`, `subtitle`, `newWave`, `footerHint`, `empty`, `emptyHint`, `loadFailed`
- `columns`: `wave`, `markets`, `progress`, `status`, `updated`
- `row`: `goLive` (`Go-live {date}`), `notStarted`, `open` (aria label)
- `progress`: `ariaNotStarted`
- `status`: `draft`
- `dialog`: `title`, `close`; (edit only: `editTitle`)
- `fields`: `name`, `goLive`, `markets`, `addMarket`, `searchMarket`, `noMarketMatch`, `campaign`, `campaignNone`, `campaignHelp`, `campaignNoOptions`
- `actions`: `cancel`, `create`, `creating`; (edit only: `save`)
- `errors`: `nameRequired`, `goLiveRequired`, `marketsRequired`, `marketsMax`, `campaignNotAvailable`, `campaignAlreadyLinked`, `brandArchived`, `createFailed`, `campaignOptionsFailed`; (edit only: `updateFailed`)
- `created` (confirmation message)
- `detail`: `loadFailed`, `notFound`, `backToBrand`, `fields.goLive`, `fields.markets`, `fields.campaign`, `fields.createdBy`, `fields.createdAt`, `fields.updatedAt`, `noCampaign`

**`app.brands.files.*`** (about 27)

- `title`, `subtitle`, `upload`, `empty`, `loadFailed`, `dropzone`
- `columns`: `file`, `type`, `uploadedBy`, `date`
- `type`: `brief`, `research`, `guidelines`, `assets`, `other`
- `actions`: `download`, `delete`, `menu` (aria label)
- `delete`: `confirmTitle`, `confirmMessage`, `confirm`, `cancel`, `failed`
- `dialog`: `title`, `chooseFiles`, `typeLabel`, `uploading`, `done`, `failed`, `tooLarge`, `unsupported`, `close`
- `errors`: `presignFailed`, `completeFailed`, `brandArchived`, `downloadFailed`

The new API error codes (`wave.*`, `brand_file.*`) are mapped to these keys in the SPA. The admin flag label and description are English API text, as for the other flags.

---

## 9. Left out of this chunk, and edit/delete of a wave

**Left out**

- "Create a campaign for this wave now" in the New wave dialog (PL-1.2). The dialog's campaign field only links an **existing** unlinked campaign.
- The wave-side and campaign-side link display, and the new board with its nine columns (R9) (PL-1.2). No board code, no `Campaign::wave()` relation, no campaign page change.
- The wave project tracker, its streams, steps, owners, dates and the progress data (PL-1.3). The wave page has details only.
- "Start from" (copy from a previous wave, start fresh) and everything it copies (USPs, personas, matrix, creators): PL-2 and PL-3.
- Send questionnaire, the questionnaire row, the "Client" uploader chip, "Used for USP import": PL-4 and PL-2.
- Real-time progress, "Today/Yesterday" relative dates, a walk target set for the flag-ON screens.
- The creator picker, creator statuses, hand-off (R5 to R7) and "release creator" (R8): later sprints.

**Edit and delete of a wave: reasoning**

- **Edit (name, go-live date, markets only): small enough, recommended.** One `PATCH`, one request class reusing the create rules, one policy method (already in 3.3), one audit case (`wave.updated`), the New wave dialog reused in edit mode, about six tests (flag, tenancy, roles, validation, persistence, audit). Without it a typo in a wave name or a moved go-live date has no fix in the product, and Pedram's walk would feel broken. It stays clean because it does **not** touch the campaign link or the status: relinking has the one-wave-per-campaign and same-brand rules and belongs with PL-1.2, which owns the link on both sides. It is planned as a separable **sub-step E**, last in the order, so it can be dropped without touching anything else.
- **Delete: leave out.** Mechanically it is small (soft delete, the index already frees the campaign). The reason to wait is meaning: a wave will own strategy, scripts, the tracker and client approvals (R2), and a delete must say what happens to them, to a linked campaign that already has creators, and to approvals the client already gave. Nothing exists yet to decide that with, and a delete that frees a campaign interacts with PL-1.2's link. A cleaner moment is PL-1.2 or PL-2. Until then, test waves are removed by the walk cleanup seeder (section 10).

---

## 10. Walk script for Pedram (local)

**Local data needed (to be built in the chunk).** A walk seeder pair in the `Ah120WalkSeeder` shape: `Pl11WalkSeeder` and `Pl11WalkCleanupSeeder`, guarded to local and testing, idempotent, additive, every account under `@pl11-walk.local`, removed by the cleanup seeder. It leaves the flag **OFF**. It prints the brand ulids it creates.

- **Agency A "PL-1.1 Walk Agency A"**, with an admin, a manager and a staff user (one shared local password constant, as in the AH-120 seeder).
  - Brands: **Bolt Food** (active), **Glovo** (active), **Old Co** (archived).
  - Campaigns: **Bolt Autumn Reels** (Bolt Food, draft, no wave), **Bolt Winter Launch** (Bolt Food, active, no wave), **Bolt Summer** (Bolt Food, completed, linked to Wave 5), **Glovo Spring** (Glovo, no wave).
  - Waves: **Wave 5** (Bolt Food, SK, go-live 8 Aug 2026, linked to Bolt Summer), **Wave 6** (Bolt Food, SK RO CZ, go-live 5 Sep 2026, no campaign).
- **Agency B "PL-1.1 Walk Agency B"**, one admin, brand **Zeta Foods**, campaign **Zeta Q4** (no wave), wave **Zeta Wave 1**.
- **Sample files on your Mac:** a small PDF, a PNG, a ZIP, and one file that must be refused (an `.exe` or an `.svg`). For the over-size check, a file just over 100 MB (for example made with `mkfile 101m big.zip`).
- **Services:** the local stack as in `docs/runbooks/local-dev.md` (`docker compose up` for the local services, MinIO included; `pnpm dev` from the repo root). Downloads need MinIO, because a non-S3 disk returns no signed URL (inventory §3). Your local platform-admin account turns the flag on (credentials are in the local seeders; not printed here).

**Steps**

1. **Seed.** Run `php artisan db:seed --class='Database\Seeders\Pl11WalkSeeder'`; note the two Bolt Food and Zeta Foods ulids it prints.
2. **Flag OFF, nothing changed.** Sign in to the agency app as the Agency A **admin**. Open Brands, then Bolt Food. You see the single details card, Edit and Archive, and **no** Client files and **no** Waves. Open Campaigns, Files, the sidebar: as before. In the API (browser dev tools, Network), `GET …/brands/<bolt>/waves` returns 404 `not_found`.
3. **Turn the flag on.** Sign in to the admin app as platform admin, Feature flags, find "Strategy and script writing", switch on with a reason. Back in the agency app, reload.
4. **Brand page, flag ON.** Open Bolt Food. Left: the same details card. Right: **Client files** (empty, with Upload files and the drop zone) and **Waves** with Wave 6 and Wave 5 (name, go-live, market chips, six empty segments and "Not started", a Draft pill, an updated date). Click Wave 6: the wave page shows go-live, markets SK RO CZ, "No campaign linked". Go back with the arrow. Open Old Co (archived): the panels show, read-only, no Upload and no New wave.
5. **Files, upload.** Upload the PDF with type Brief, then the PNG as Guidelines, then the ZIP as Assets. Each appears in the table with name, size, type chip, your name and date. Try the `.exe` or `.svg`: refused before any upload. Try the 101 MB file: refused with a size message.
6. **Files, download.** Use the row menu, Download, on the PDF: it downloads (it does not open inline).
7. **Files, delete.** Delete the PNG: confirm; it leaves the table. Reload: still gone.
8. **Wave without a campaign.** Click New wave. Name "Wave 7", a go-live date, markets Slovakia and Romania (add, remove one, add again). Leave the campaign on "No campaign". Create. Wave 7 appears first in the table with Draft. Open it: "No campaign linked".
9. **Wave with a campaign, and the dropdown.** Click New wave. Open the campaign list: it offers **Bolt Autumn Reels** and **Bolt Winter Launch**, and **not** Bolt Summer (already linked), **not** Glovo Spring (another brand), **not** Zeta Q4 (another agency). Pick Bolt Autumn Reels, name it "Wave 8", create. Open Wave 8: the campaign shows with a link; the link opens the campaign. Open New wave again: **Bolt Autumn Reels is gone** from the list; Bolt Winter Launch is still there.
10. **Roles.** Sign out, sign in as the Agency A **staff** user. Bolt Food shows the panels and the waves and files (download works), but there is no New wave, no Upload files and no Delete.
11. **Another brand's campaign never offered.** As admin on **Glovo** (a brand with no waves): New wave offers only Glovo Spring, not any Bolt campaign.
12. **A second agency cannot see anything.** Sign out, sign in as the **Agency B** admin. Brands shows only Zeta Foods. On Zeta Foods upload one file and create "Zeta Wave 2" linking Zeta Q4. Now paste Agency A's Bolt Food address (brand ulid from step 1) into the browser: the page shows not found, no waves, no files. In dev tools, `GET /api/v1/agencies/<A>/brands/<bolt>/waves` returns 404.
13. **Flag OFF again.** In the admin app switch the flag off. Reload as Agency A admin: Bolt Food is back to the single card; the wave page address redirects to the brand; the endpoints return 404; the files and waves you made are untouched in the database (they reappear when the flag is on).
14. **Clean up.** `php artisan db:seed --class='Database\Seeders\Pl11WalkCleanupSeeder'` removes the accounts, brands, campaigns, waves and file rows it made, and turns the flag off. Objects you uploaded to MinIO stay in the local bucket; the walk uses a local MinIO, so they can be left.

---

## 11. Open questions for Pedram

1. **Flag name and reach.** `strategy_enabled`, one flag for the strategy family (PL-1 to PL-3 and PL-5's strategy half), or a narrower `waves_enabled` for PL-1 only? **Recommend `strategy_enabled`**: the sprint table already says one flag per section, and splitting later means a rename across API, web and the admin registry.
2. **Edit and delete of a wave.** **Recommend** include **edit** (name, go-live date, markets; not the link, not the status) as sub-step E, and leave **delete** out until PL-1.2 or PL-2 (section 9). Your call.
3. **Where client files live.** **Recommend** a new `brand_files` table, separate from `agency_files`, so the Files library and the campaign attach picker stay exactly as they are. The alternative (a `brand_id` on `agency_files`) is less code but makes client files appear in the library and become attachable to campaigns.
4. **Who may do what.** **Recommend:** admin and manager can create waves, upload and delete client files; staff can view and download. (The Files library lets any member upload; client material is narrower here.) Confirm, or say staff may upload.
5. **File limits and safety.** The design says 100 MB, ZIP allowed. **Recommend** accept 100 MB and ZIP (a brand-files-only rule; the Files library stays at 25 MB and no ZIP), with downloads forced to "attachment". There is **no virus scan and no content sniff** anywhere in the product today; ZIP and 100 MB make that gap more visible. Accept that for now and record it in tech-debt, or hold this chunk until a scan exists?
6. **Which campaigns the dropdown offers.** R4 says "an existing unlinked campaign of that brand". **Recommend** offer every status, show the status beside the name, exclude nothing. Alternative: hide Cancelled (and Completed).
7. **Progress column and statuses in this chunk.** No tracker or strategy data exists yet. **Recommend** keep the column with six empty segments and "Not started", and one status, Draft, so PL-1.3 and PL-2 only feed it. Alternative: omit the column until it has data.
8. **Deviations from frame 01.** **Recommend** (a) keep the existing header status pill and do not add "Brand name" and "Status" rows to the details card; (b) no "Client / Catalyst" uploader chip until clients upload (PL-4); (c) "Updated" as a date, not "Today / Yesterday". Each keeps the flag-OFF page identical or avoids a half-meaning. Say if you want any of them matched to the frame.
9. **Translations for 23 locales.** There is no machine-translation tool in the repo, and the rule is "a real MT baseline at merge time, never English in the flaky ten". **Recommend** the build writes all 23, as the AH-119 chunks did, enforced by the new `waveKeys.spec.ts` (placeholders, not an English copy), and you spot-check one or two locales you can read. Say if you want a different method.
10. **Past go-live dates.** **Recommend** allow them (a brand's earlier waves must be enterable; frame 01 shows past waves). Alternative: only today or later.
11. **Markets validation.** **Recommend** 1 to 20 codes, shape check only (as campaigns). A made-up code like `ZZ` is accepted because no backend country list exists. Alternative: add a backend list of valid codes (a small new file, and a second copy to keep in step with the SPA list).
12. **Wave names.** **Recommend** no uniqueness rule within a brand (two "Wave 8" would be confusing, but a rule can block a legitimate rename later). Alternative: unique per brand among live waves.
13. **Same-brand rule in the database too?** **Recommend** server-side only now, as you specified. A composite foreign key would also lock it in the database but needs a new unique index on the existing `campaigns` table (production, though small); it can be added later as its own reviewed step if you want belt and braces.
14. **After "Create wave".** **Recommend** close the dialog, show a short confirmation and leave you on the Brand page with the new row at the top. Alternative: open the new wave's page.
15. **Archived brands.** **Recommend** panels are visible and read-only on an archived brand (no new wave, no upload, no delete). Alternative: hide the panels.

---

## 12. Size estimate and build order

**Size: M, as the sprint table says for PL-1; this is the largest of the three PL-1 chunks.** Estimates, not commitments:

- Backend: about 30 new files (two migrations, two models and factories, module provider, flag class, two policies, three controllers, five requests, three resources, wave service, brand-file upload service, route files) and about 8 edited (providers, `AuditAction`, `ErasureRegistry`, `UserResource`, `AdminFeatureFlagController`, tenancy doc, feature-flag doc).
- Web: about 14 new files and 7 edited, plus 24 locale files edited.
- Tests: about 13 backend test files and 8 web specs. Seeder pair: 2 files.
- Rough effort: 7 sub-steps, each ending green; about 3 to 4 working days of build plus a day for the translations, the walk and the review file. The brief for Pedram comes before any build prompt (ruling 9).

**Build order** (each sub-step independently green: tests, Pint, Larastan, `vue-tsc`, lint on what it touches):

1. **Flag and module skeleton.** `Strategy` module, `StrategyEnabled`, provider, admin registry entry, `/me` `features.strategy`, api-client type, `docs/feature-flags.md` row. Tests B1 (default and `/me`), B13. Alarm line restated.
2. **Tables.** The two migrations, models, factories, erasure registry rows, schema tests. Test B5 (database level) and B11, with the partial-index break-revert.
3. **Wave API.** Requests, service, policy, controller, resources, audit case, routes, tenancy doc rows. Tests B1 (waves), B2, B4, B5 (API), B6, B7, B8, B10, B12 with break-reverts.
4. **Brand files API.** Upload service, policy, controller, resource, audit cases, routes, tenancy doc rows. Tests B1 (files), B3, B7, B9, B10 with break-reverts.
5. **Web.** api-client types, api modules, Brand page panels, dialogs, `MarketPicker`, wave page, route, `wave` status tone, English strings. Tests W1 to W6, W8. Then **sub-step E (edit)** if approved, API and dialog together.
6. **Strings and seeders.** The 23 translations, `waveKeys.spec.ts`, W7; the walk seeder pair.
7. **Close-out.** Full gate board, Playwright brands and permissions specs, agency walk 126 with the flag OFF, a Production posture section in the review file, the review file and the log entry. Pedram walks it locally (section 10).

Commits: feature commit and docs commit as a pair (`WORKING-PROCESS.md` §2). Push held until Pedram says so; CI green at the tip is cited.

---

## Read and not read

**Read for this plan:** `production-line-sprints.md` (all), `WORKING-PROCESS.md` (all), `PROJECT-WORKFLOW.md` §12, the inventory (`pl-1-1-inventory.md`), the code and the Figma frames listed in it. **Not read:** everything listed in inventory §10 and §0 (notably Figma colours and spacing, frame 09's node tree, every other frame, the body of `CampaignController::update`, `l4-fixtures.mjs` brand fixtures, the Playwright brand specs' contents, a shared relative-date helper if one exists).
