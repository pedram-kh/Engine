# AH-004 · Portfolio overhaul — plan (plan-pause)

> **Status:** Plan — awaiting audit. **No code until this doc is signed off.**
> **Theme:** Creator onboarding + profile + portfolio reshape (AH-003 landed; this is the
> second commit-pair of the shared inventory).
> **Carve-out (unchanged):** nothing in this chunk touches `resources/contracts/**` (legally
> binding content stays English / untouched).

This plan turns the locked AH-004 decisions into a sequenced, audited build. Every divergence
the inventory surfaced is resolved here; the two items that were open at plan-pause (Q5 image
EXIF/thumbnail handling, Q6 drawer-vs-lightbox) are closed, plus the three corrections from the
audit (full-res not 1024px, server-authoritative URL gating, CI memory pin).

---

## 1. Inventory reconciliation (what already exists — do NOT rebuild)

Grounded in the read, so the plan adds only what's missing:

- **Schema already has the link shape.** `creator_portfolio_items` carries `kind`
  (`PortfolioItemKind`: image | video | link), `external_url`, `s3_path`, `thumbnail_path`,
  `mime_type`, `size_bytes`, `duration_seconds`, `position`. → Links need **no migration** (D9).
- **Video already does presigned PUT at 500 MB.** `PortfolioUploadService::MAX_PRESIGNED_BYTES =
500 MB`; `initiatePresignedUpload()` → client PUT → `completePresignedUpload()` verifies the
  object landed and returns the path. → Images **join this proven path**; no S3 multipart rewrite
  (D8, Q3).
- **Gallery + drawer patterns already exist.** The shared `@catalyst/ui` `PortfolioGallery` is the
  preview/lightbox; `ReviewDraftDrawer.vue` is the **wide-`v-dialog`** drawer pattern (the app has
  **no** `v-navigation-drawer`). → D10 reuses both.
- **Images today are downscaled to 1024px.** `PortfolioUploadService::uploadImage()` delegates to
  the avatar re-encoder (`AvatarUploadService`), which `scaleDown(1024)` + re-encodes with
  `strip: true`. → This is the **divergence we flip** (Q5 correction): portfolio images must keep
  full resolution.
- **Cap is 10.** `PortfolioUploadService::MAX_ITEMS_PER_CREATOR = 10`, enforced in
  `PortfolioController::assertHasCapacity()`. → Raise to 30 (D8).

---

## 2. Locked decisions

| Ref        | Decision                                                                                                                                                                                                                                                                                                                                                                                                                                                    |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **D8**     | Cap **10 → 30** files/creator. **Uniform 500 MB** ceiling for all portfolio file types (reuses the proven video ceiling; **do not lower video to 300 MB** — 300 MB was a floor, 500 MB is the uniform cap). **Single presigned PUT** (no multipart) + upload **progress + timeout**. Resume / 15-min presign expiry / S3 storage cost logged as tech-debt carry-overs (already in `tech-debt.md`).                                                          |
| **D9**     | Link entry type — **migration-free**. Add only: create-link endpoint + validation (http/https only; reject `javascript:`/`data:`; length bound) + add-link UI.                                                                                                                                                                                                                                                                                              |
| **D10**    | **Hybrid drawer** across all three surfaces (creator / agency / admin): reuse `PortfolioGallery` inside the wide-`v-dialog` pattern, opening to list **all** the creator's assets, with **download** (presigned GET + `Content-Disposition: attachment`).                                                                                                                                                                                                   |
| **D11**    | One **"Add to portfolio"** affordance = upload files **OR** add link.                                                                                                                                                                                                                                                                                                                                                                                       |
| **Q5**     | Images join the presigned-PUT path. A queued `ProcessPortfolioImageJob` **strips EXIF + re-encodes at full resolution (dimensions preserved)**, generates a **separate** thumbnail → `thumbnail_path`, **overwrites the raw key in place** (destroys EXIF bytes), then flips status. **Download returns the full-res sanitized asset, never the thumbnail.** **Megapixel guard** for decompression bombs; over the cap → `failed` (not a silent downscale). |
| **Q2**     | New `processing_status` column = `processing                                                                                                                                                                                                                                                                                                                                                                                                                | ready | failed`. Megapixel-guard rejection + corrupt uploads terminate into `failed`; creator can delete / re-upload a `failed` item (no silent forever-`processing`). |
| **Gating** | **Server-authoritative.** Withhold both `view_url` and `thumbnail_view_url` (return `null`) + expose `processing_status` until `ready`, at **every** portfolio-URL mint site (see §3).                                                                                                                                                                                                                                                                      |
| **CI**     | Pin the test memory ceiling in the `composer test` command itself: `@php -d memory_limit=512M vendor/bin/pest` — so local and CI agree and the heavy decode tests are deterministic.                                                                                                                                                                                                                                                                        |

---

## 3. Step 0 — portfolio-URL mint-site enumeration (the confirmed-leak audit)

Every site that mints a portfolio `view_url` / `thumbnail_view_url` must apply the `ready`-gate.
The read found **three distinct resource classes** (one shared across two surfaces):

| #   | Resource class                                                         | Mint lines     | Surface(s)                                                                                                                                     | Leaks today?                                                 |
| --- | ---------------------------------------------------------------------- | -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| A   | `Creators/Http/Resources/CreatorResource::mapPortfolio()`              | `:290`, `:293` | **Creator owner** (onboarding/dashboard) **and platform-admin** (`AdminCreatorController` returns `CreatorResource` at `:141/176/266/336/396`) | **Yes** — `view_url => signedViewUrl(s3_path)` unconditional |
| B   | `Agencies/Http/Resources/AgencyCreatorDetailResource::mapPortfolio()`  | `:156`, `:159` | **Agency roster** (`AgencyCreatorDetailController`)                                                                                            | **Yes**                                                      |
| C   | `Agencies/Http/Resources/CreatorPublicProfileResource::mapPortfolio()` | `:129`, `:132` | **Agency discover** (`AgencyCreatorDiscoveryController`)                                                                                       | **Yes**                                                      |

**Out of scope (verified non-portfolio minters):** `MessageResource` (chat attachments),
`ContractResource` (contract PDF — carve-out), `CampaignDraftResource` (draft media),
`TalentPoolMemberResource` / `CreatorDiscoveryResource` (mint **avatar_url only**, no portfolio).

**Recommendation (fix-it-right, consistent with AH-003's structural reinforcement):** the three
`mapPortfolio()` + `signedViewUrl()` implementations are duplicated. Consolidate into one shared
**`PortfolioItemPresenter`** (or a `MapsPortfolioItems` trait) that all three resources call, so
the `ready`-gate lives in **one** place and a future 4th surface can't silently re-introduce the
leak. The gate: `view_url` and `thumbnail_view_url` are `null` unless `processing_status ===
'ready'`; `processing_status` is always emitted; link items (no `s3_path`) are `ready` by
definition and continue to expose `external_url`.

---

## 4. Sub-step sequence (build order)

> Each sub-step is independently green-able (lint + typecheck + tests) before the next.

### Sub-step 1 — Migration: `processing_status` (+ backfill)

- Add `processing_status` enum/string column to `creator_portfolio_items`, values
  `processing | ready | failed`, default `ready`.
- **Backfill:** all existing rows + every **link** and **video** row are `ready` (they already
  have their final assets). Only newly-uploaded large images transit `processing`.
- Model: cast to a `PortfolioProcessingStatus` enum; factory states `processing()` / `failed()`.
- **Done-gate:** migration up/down clean; existing portfolio feature tests still green.

### Sub-step 2 — Images onto presigned-PUT (uniform 500 MB, cap 30)

- Extend `PortfolioUploadService` accepted-MIME map so the **presigned** path accepts the image
  MIME types (jpeg/png/webp) in the `portfolio` namespace (today only video uses presign).
- Raise `MAX_ITEMS_PER_CREATOR` 10 → 30.
- New/extended endpoints: image initiate-presign + complete (parallel to video's
  `initiateVideoUpload` / `completeVideoUpload`), creating the item as `processing`.
- Keep the legacy small-image direct `uploadImage` path **only** if still needed for the
  video-poster thumbnail (which is already EXIF-stripped via the re-encoder); otherwise route all
  portfolio images through presign. **Decision for auditor:** retain direct path for poster frames
  vs. fold posters into the worker too (see §8).
- FE: `usePortfolioUpload` gains **progress** (XHR/`fetch` upload progress events) + **timeout**.
- **Done-gate:** upload-service + controller feature tests; FE upload composable unit tests.

### Sub-step 3 — `ProcessPortfolioImageJob` (full-res EXIF strip + thumbnail) + megapixel guard

- Queued job dispatched on image `complete`. Steps:
  1. Download the raw object from the `media` disk.
  2. **Megapixel guard:** read dimensions; if `width * height > MAX_MEGAPIXELS` (decompression-bomb
     ceiling) → set `failed`, stop. (Byte limits don't bound decode memory — pixels do.)
  3. Decode → **re-encode at full resolution, EXIF stripped** (`strip: true`; no `scaleDown`).
  4. **Overwrite the raw key in place** with the sanitized bytes (destroys GPS/EXIF in S3).
  5. Generate a **separate** thumbnail derivative (bounded, e.g. ≤512px longest side) → write to a
     `…_thumb.<ext>` key → `thumbnail_path`.
  6. Flip `processing_status = ready`.
  - Any decode/exception → `failed` (item kept so the creator sees it and can delete/re-upload).
- **New re-encoder method** (do NOT reuse the avatar `scaleDown(1024)` path): a full-res
  strip-only encode + a separate thumbnail encode. Avatar path is unchanged.
- **Done-gate:** job feature tests (success → ready + thumbnail + EXIF gone; oversize → failed;
  corrupt → failed). **These are the heavy decode tests → require the §6 memory pin.**

### Sub-step 4 — Resource gating at all enumerated mint sites (§3)

- Introduce the shared `PortfolioItemPresenter`; refactor resources A/B/C to use it.
- Gate: `view_url` / `thumbnail_view_url` `null` until `ready`; always emit `processing_status`.
- FE gallery item mappers (creator `ConnectionsPortfolioSection`, agency `ReviewDraftDrawer` and
  the roster/discover/admin detail pages) render a **processing** placeholder and a **failed**
  state (with delete/re-upload) off `processing_status`.
- **Done-gate:** per-resource feature test asserting a `processing` item emits `view_url: null` +
  `processing_status: processing` on **all three** classes; FE unit tests for placeholder/failed.

### Sub-step 5 — Link create endpoint + validation + add-link UI (D9)

- `POST` create-link endpoint: `kind=link`, `external_url`, optional `title`/`description`; no
  S3 path; `processing_status = ready`.
- **Validation:** `http`/`https` scheme only; **reject** `javascript:` / `data:` (the gallery
  renders links as clickable `href` — XSS surface); max length bound; honors the 30-cap.
- FE: add-link form within the single "Add to portfolio" affordance (D11).
- **Done-gate:** endpoint feature tests (valid link 201; `javascript:`/`data:`/overlong → 422);
  FE add-link unit tests.

### Sub-step 6 — Drawer across 3 surfaces + download (D10)

- Wide-`v-dialog` drawer (ReviewDraftDrawer pattern) wrapping `PortfolioGallery`, listing all the
  creator's assets, on creator / agency / admin surfaces.
- **Download:** presigned GET with `ResponseContentDisposition=attachment; filename="…"`
  (returns the **full-res sanitized** asset for images, the file for video, opens-out for links).
  Available on all three surfaces.
- **Download authorization (audit) — download must NOT be a broader grant than view.** The authz
  model per surface: **creator** downloads own portfolio; **agency-user** downloads only
  roster/discover creators they can already see (the same scope that gates resources B/C);
  **admin** downloads any. Two acceptable implementations, in preference order:
  1. **Preferred — no separate endpoint:** the drawer downloads via the **already-authorized**
     `view_url` the resource minted (the same gated URL from §3/§4), with the browser forcing
     `attachment` (or the presign re-minted with `ResponseContentDisposition`). Download then
     **inherits** the resource's authz + the `ready`-gate for free — state this explicitly.
  2. **If a dedicated download endpoint is needed** (e.g. to set `Content-Disposition` server-side
     per item), it MUST re-run the **same authorization gate** as resources A/B/C for the calling
     surface — never a bare "presign by item id". An unauthorized caller gets **403/404**, and a
     `processing`/`failed` item is non-downloadable on every surface.
- **Done-gate:** download endpoint/affordance feature test (presigned GET carries
  `Content-Disposition`; `processing`/`failed` items are non-downloadable); **per-surface authz
  feature test** — a caller who cannot view a creator's portfolio gets **403/404** on download
  (creator-A cannot download creator-B; agency-user cannot download a creator outside their
  roster/discover scope); FE drawer unit tests per surface.

### Sub-step 7 — i18n (new en strings → 24-locale regenerate → parity green)

- Author new `en` strings (add-link form, drawer, processing/failed states, download) in the
  appropriate namespace; regenerate across all 24 locales via the AH-001 pipeline; pass
  parity/placeholder/plural gates. (Same done-gate as AH-003.)

---

## 5. Schema detail (sub-step 1)

```
ALTER TABLE creator_portfolio_items
  ADD COLUMN processing_status VARCHAR  NOT NULL DEFAULT 'ready';  -- processing | ready | failed
-- backfill: all existing rows -> 'ready' (covered by the DEFAULT on a non-null add)
```

- `down()` drops the column.
- Enum `PortfolioProcessingStatus` mirrors the three values; model cast added.
- Link + video items are created `ready`; large images created `processing`, flipped by the job.

---

## 6. CI memory pin (the load-bearing one)

`composer test` is bare `@php vendor/bin/pest` (no memory flag); `setup-php` pins no
`memory_limit` (contrast `composer stan` → `--memory-limit=2G`). The `ProcessPortfolioImageJob`
tests are exactly the heavy image-decode tests that exceed the default 128 MB ceiling (the
pre-existing OOM the locale chunk already runs per-module to dodge). Fix **in the composer
script** so local and CI agree:

```diff
- "test": ["@php vendor/bin/pest --colors=always"],
+ "test": ["@php -d memory_limit=512M vendor/bin/pest --colors=always"],
```

This is preferred over only adding `ini-values: memory_limit=512M` to the `setup-php` step,
because the composer-script pin is honored everywhere `composer test` runs (local, CI, pre-push),
not just in the GH workflow.

### The memory pin and `MAX_MEGAPIXELS` are a matched pair (audit)

Decode memory scales with **pixels**, not file bytes. A ~50 MP image decodes to ~200 MB of raw
bitmap, and GD/Imagick overhead is 2–3× on top, so a near-cap decode must fit inside the
512 MB–768 MB envelope. Therefore the cap is set **with** the pin, not independently:

- **`MAX_MEGAPIXELS = 50`** (see §8 item 2) — above 48 MP phones / 45 MP pro DSLRs (covers real
  creator content), and close enough to the decompression-bomb line it guards.
- **Test path:** the `composer test` pin (`512M`) covers the "valid 50 MP image → ready" test
  without OOM. (At 100 MP that test would OOM at 512 MB — which is why the cap is 50, not 100.)
- **Production worker:** the live `queue:work` that runs `ProcessPortfolioImageJob` must be sized
  **independently** to match — set the worker memory explicitly (`php artisan queue:work
--memory=768` and/or the container/Horizon memory limit) so a 50 MP decode on the live worker
  doesn't get reaped at the default 128 MB. The 512 MB test pin and the prod worker memory are two
  separate ceilings that must both clear a 50 MP decode.
- **If 100 MP is ever wanted instead:** both the test pin and the prod worker memory must rise to
  ~1 GB+ **together** — they move as a pair. 50 MP is the call unless flagged otherwise.

---

## 7. Tech-debt carry-overs (already logged)

Recorded in `docs/tech-debt.md` ("Portfolio upload — resume / presign-expiry / storage cost"):

- **Resumable/multipart** uploads (a dropped large single-PUT restarts from zero).
- **15-min presign expiry** (slow uploader on a bad connection can outrun the TTL).
- **S3 storage cost** at scale — 30 × 500 MB ≈ ~15 GB/creator of durable storage; with **full-res
  retention** (this plan) the real footprint is the original + a small thumbnail per image, so the
  cost note stands and is, if anything, reinforced.

---

## 8. Open decisions for the auditor

1. **Video-poster path (sub-step 2):** keep the existing direct `uploadImage` re-encoder for the
   client-captured video poster frame (already EXIF-stripped, small), or fold posters into the
   worker too? Recommendation: **keep** the direct poster path — it's small, already sanitized,
   and not a decompression-bomb risk; only large standalone images need the worker.
2. **Megapixel cap value (sub-step 3) — SETTLED (audit):** `MAX_MEGAPIXELS = 50`. Above 48 MP
   phones / 45 MP pro DSLRs (real creator content), close to the decompression-bomb line it
   guards, and a near-cap decode fits the 512 MB–768 MB envelope. Set as a **matched pair** with
   the memory pin + the prod worker memory (see §6) — they rise together if ever raised.
3. **Thumbnail ceiling (sub-step 3):** ≤512px longest side for the grid thumbnail.
4. **Privacy window (accepted, noted):** between PUT and worker completion the raw (EXIF-bearing)
   object exists in S3, but the bucket is private (presigned reads only) **and** the resource gate
   (§3/§4) withholds any URL while `processing`, so it is never reachable; the worker then
   overwrites the key. No additional mitigation planned — flag if you want the raw uploaded to a
   quarantine prefix instead of the final key.

---

## 9. Test plan (summary)

- **Backend (Pest, at 512 MB):** migration up/down; image presign initiate/complete; cap-30
  enforcement; worker success/oversize/corrupt → ready/failed/failed; EXIF-gone + full-res-retained
  assertions; thumbnail generated; **per-resource gate** (A/B/C emit `view_url: null` while
  `processing`); link create + URL validation (reject `javascript:`/`data:`/overlong); download
  presigned-GET `Content-Disposition` + non-downloadable while processing/failed; **per-surface
  download authz** (unauthorized caller → 403/404); **failed-item S3 cleanup** — deleting a
  `failed` item removes its raw S3 object (no orphaned storage left behind the gate).
- **Frontend (Vitest):** upload progress/timeout; processing/failed gallery states + delete/
  re-upload; add-link form validation; drawer per surface + download affordance.
- **E2E (Playwright):** extend the happy-path / a portfolio spec to upload an image, observe
  `processing → ready`, add a link, open the drawer, download.
- **Gates:** typecheck, lint, Pint, Larastan L8, i18n parity (24-locale) — all green per sub-step.

---

_Hand-back: this plan is for audit. On sign-off, AH-004 builds as its own commit-pair (feature +
docs/log), separate from AH-003, per the shared-inventory kickoff._
