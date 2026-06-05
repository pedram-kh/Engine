# Sprint 9 — Chunk 1 Review · Creator submission foundation (drafts + media + posted-content)

**Status:** Closed. Spot-check passed (no PMC) — seeded-state paths covered (resubmit → v2 two-step with v1 history; posted-content from seeded approved), the boundary holds (no `verifyLive`, `verification_status` only `pending`, review-trail columns column-only/unwritten), the context-thread produces one enriched audit row per event with free text redacted, the namespace param is additive (portfolio contract green), creator-self scoping + fail-closed all pinned, and the FE reactivity bug was caught by the spec + fixed.

**Reviewer:** drafted by Cursor (implementation); independently spot-checked + accepted, 2026-06-05.

**Reviewed against:** the Sprint-9 Chunk-1 kickoff (D-1…D-9 locked decisions + honest-deviation triggers + spot-check anchors), `docs/03-DATA-MODEL.md §7` (`campaign_drafts` :572-600, `campaign_posted_content` :605-622), `docs/security/tenancy.md §4` (cross-tenant allowlist), the Sprint 8 creator-self pattern (`CreatorAssignmentController` + `CreatorAssignmentTest`), the presigned pattern (`PortfolioUploadService` + `usePortfolioUpload`/`presigned.ts`), and `PROJECT-WORKFLOW.md §5` (5.1 source-inspection, 5.15 allowlist discipline, 5.17 coverage).

This is the **submission half of the submission→review seam** — it lands the two deferred tables, the creator-side submit/resubmit/post surface, and the lifecycle transitions **through `posted` / `verification_status=pending`**. It deliberately **stops** before agency review + verification (Chunk 2).

---

## The three flagged decisions (confirmed at plan-pause, built as proposed)

1. **Five new `creators/me/assignments/*` routes, not three.** The kickoff named "three", but the detail route (D-9) needs a **read** endpoint (the existing `index` is list-only) and the presigned media needs an **init/complete** pair (the existing portfolio endpoints carry a 10-item capacity cap + write to `…/portfolio/` paths — wrong for drafts). Built: `GET {a}` (show), `POST {a}/drafts`, `POST {a}/drafts/media/init`, `POST {a}/drafts/media/complete`, `POST {a}/posted-content`. All five are documented in `tenancy.md §4`.
2. **Namespace param on `PortfolioUploadService`** (`'portfolio'` default → `'drafts'`). Additive optional argument on `initiatePresignedUpload()`/`completePresignedUpload()`; draft media lands at `creators/{ulid}/drafts/{ulid}.{ext}` with **no capacity cap** and a **widened MIME set** (image + video, a draft post may be an image). No portfolio caller breaks — the original `video`-only message + path are preserved for the default namespace (its contract test still passes). This is the "reuse, not duplicate" of the presign mechanics.
3. **Context-thread audit.** `submitDraft()`/`markPosted()` gained an additive `array $context = []` param (the private `commit()` already merges `context` into metadata). The single machine-written transition row now carries `{draft_id, version, media_count}` (`assignment.draft_submitted`) / `{posted_content_id, platform}` (`assignment.posted_by_creator`). **Free text excluded** (`caption`, `post_url` never enter the snapshot — D-3). No new audit verb; the machine stays the sole status + audit authority.

No further divergence surfaced during the build.

---

## The build

### Backend (commit 1 — `feat(campaigns): creator draft/posted submission foundation`)

- **Migrations:** `campaign_drafts` (`2026_06_05_110000`, pre-staged + verified) + `campaign_posted_content` (`2026_06_05_110001`). Both: `id`+`ulid`, `assignment_id` FK **CASCADE**, jsonb blobs, P2/P3 columns as column-only, the spec index pairs. `Sprint9MigrationTest` pins the full column set (per `03-DATA-MODEL.md §7`) + asserts the CASCADE on parent delete.
- **Models + factories + enums:** `CampaignDraft` / `CampaignPostedContent` (`HasUlid`, enum/array casts, `assignment()` relation, **no `Audited` trait** — D-3) + `CampaignDraftFactory` / `CampaignPostedContentFactory` + `DraftReviewStatus` / `PostedContentVerificationStatus` enums.
- **Service:** `PortfolioUploadService` namespace param (decision 2). State machine `submitDraft`/`markPosted` `$context` thread (decision 3).
- **Controller:** `CreatorAssignmentDraftController` — mirrors `CreatorAssignmentController` (scope-bypass, `creator_id` + ULID resolution, 404 on non-owned). `show`, `submitDraft` (version compute → create row → `startProducing()` if `contracted`/`revision_requested` → `submitDraft()` — the two-step machine path, D-4/5/6), `initMedia`/`completeMedia` (presigned), `submitPostedContent` (`approved → posted`, D-7). Fail-closed (`assignment.not_producible` / `assignment.not_approved`); media-ownership guard (`draft.media_invalid`).
- **Resources:** `CampaignDraftResource` (with `signedViewUrl` media URLs for Chunk 2's drawer) + `CampaignPostedContentResource`.
- **Routes + docs:** 5 routes in `creators/me/assignments`; 5 rows added to `tenancy.md §4`.

### Frontend (commit 2 — `feat(main): creator assignment detail + draft submission surface`)

- **api-client types** (`types/campaign.ts`): `CampaignDraftResource`, `CampaignPostedContentResource`, `CreatorAssignmentDetailResource/Response`, the submit/posted/media payloads + responses.
- **api wrappers** (`assignments.api.ts`): `show`, `submitDraft`, `initDraftMedia`, `completeDraftMedia`, `submitPostedContent`.
- **Route + page:** `/creator/assignments/:ulid` → `CreatorAssignmentDetailPage.vue` — **fail-closed state-dependent actions** (`producing`/`contracted` → submit; `revision_requested` → feedback + resubmit; `draft_submitted` → awaiting review; `approved` → post URL; `posted` → awaiting verification). Presigned media upload (init → PUT with exact `Content-Type` → complete), draft version history, 422 binding via `extractFieldErrors`. The flat list links here (a "View" affordance per row).
- **i18n:** `creator.ui.assignments.detail.*` in en/pt/it; the page added to the `CANONICAL_422_FILES` allowlist.

---

## Spot-check anchors → evidence

| Anchor                                                                               | Evidence                                                                                                                                                                                                                                                                                                                                  |
| ------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Two tables ship with correct FKs (CASCADE to assignment)                             | `Sprint9MigrationTest`: column-set + `cascades drafts + posted-content when their assignment is deleted` (green).                                                                                                                                                                                                                         |
| Draft submit creates a versioned row + `producing → draft_submitted` (audit + event) | `submits a v1 draft, transitions producing → draft_submitted, audits + emits the event` — asserts status, `submitted_draft_at`, `assignment.draft_submitted` audit with `{draft_id, version, media_count}` + **no `caption`**, and `AssignmentTransitioned` dispatched.                                                                   |
| Resubmit increments version + preserves history (two-step path)                      | `resubmits as v2 via the two-step path (revision_requested → producing → draft_submitted), preserving v1 history` — versions `[1, 2]` both present.                                                                                                                                                                                       |
| Post-content submit creates the row + `approved → posted`                            | `submits posted content, transitions approved → posted, leaving verification pending` — `verification_status=pending`, `verified_at` null, audit carries `{posted_content_id, platform}` + **no `post_url`**.                                                                                                                             |
| `startProducing` as the explicit step (D-4)                                          | `submits from contracted via the explicit startProducing step — two audit rows` (`assignment.producing` + `assignment.draft_submitted`).                                                                                                                                                                                                  |
| Creator-self scoping (non-owned 404)                                                 | `404s on show…` + `404s when submitting a draft on another creator's assignment`.                                                                                                                                                                                                                                                         |
| Fail-closed (non-`producing` submit / non-`approved` posted)                         | `fails closed — a draft cannot be submitted on a non-producible assignment (422)` + `…posted content cannot be submitted unless approved (422)`.                                                                                                                                                                                          |
| Media uses the presigned pipeline + Content-Type match                               | `initiates a presigned draft-media upload under the drafts namespace` (path under `…/drafts/`, `.mp4`); `accepts image MIME` (widened set); `completes…once the object exists`; `rejects a complete whose upload_id belongs to another creator`. FE: `uploads media then binds a 422` drives init→PUT(`contentType: file.type`)→complete. |
| Detail route shows fail-closed state-dependent actions                               | `CreatorAssignmentDetailPage.spec` — one block per status (producing/draft_submitted/approved/posted/revision_requested), each asserting the others are absent.                                                                                                                                                                           |
| Arc stops at `posted`/`pending`                                                      | No `verifyLive` call anywhere; `verification_status` only ever written `pending`; no review/approve/reject endpoints.                                                                                                                                                                                                                     |

---

## Test runs

- **Backend** (Pest): new `CreatorAssignmentDraftTest` (16) + `Sprint9MigrationTest` (3) green; `PortfolioUploadServiceTest` + `CampaignAssignmentStateMachineTest` + `CreatorAssignmentTest` + `CreatorWizardEndpointsTest` re-run green (no regression from the service/machine signature changes). **Pint** clean; **Larastan level-8** clean (573 files, no errors).
- **Frontend** (Vitest): new `CreatorAssignmentDetailPage.spec` (6) + extended `assignments.api.spec` (8) green; full `src/modules/creators` suite **94 passed**; `form-error-pattern` allowlist green. **vue-tsc** typecheck clean; **ESLint** clean on changed files.

### One bug found + fixed during the build

The draft-media upload chain mutated the **captured raw** `MediaUploadItem` object after pushing it into the `media` ref array — a Vue 3 reactivity gotcha (the array tracks the proxy, not the raw object), so `readyMedia`/`mediaSubmittable` never recomputed and the submit button stayed disabled. Fixed by patching items **through** the reactive array by id (`splice(idx, 1, {...current, ...patch})`). Caught by the FE submit-form spec.

---

## Out of scope (Chunk 2 / logged) — confirmed untouched

Agency review drawer + approve/revision/reject + feedback; the mock `SocialPlatformProvider` + `VerifyPostedContentJob`; un-gating `verifyLive`; review-side notifications; the `assignment.draft_rejected` verb + reject edge. The review-trail columns (`reviewed_*`, `review_feedback`, `client_review_*`, `ai_qc_*`) ship as column-only and are never written this chunk.

## Docs follow-up (done)

- `tenancy.md §4` — the five new `creators/me/assignments/*` rows.
- `03-DATA-MODEL.md §7` — both deferred notes flipped to ✅ **Built (Sprint 9 Ch 1)**.
- `tech-debt.md` — the "drafts/posted_content tables deferred to S9" entry **closed** (built).
- `services.md` — no change (the social mock is Chunk 2).

## Commit plan (committed post-spot-check)

1. **Backend:** migrations + models/factories/enums + service/machine changes + controller + resources + routes + `tenancy.md §4` + `03-DATA-MODEL.md` + `tech-debt.md` + backend tests.
2. **Frontend:** api-client types + api wrappers + detail route/page + list link + i18n (en/pt/it) + `form-error-pattern` allowlist + FE specs.

The pre-existing unrelated working-tree changes (Agencies `AgencyCreatorController`/`Resource`/test, `roster/*`, `agency.ts`, the `app.json`s) were deliberately left unstaged.
