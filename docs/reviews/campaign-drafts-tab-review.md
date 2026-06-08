# Campaign-Detail Drafts Tab — Review

**Status:** Ready for review.

**Reviewer:** Cursor (implementation pass — draft for independent review)

**Reviewed against:** Campaign-Detail Drafts Tab kickoff (D-1…D-7); `20-PHASE-1-SPEC.md` (campaign detail tabs); Sprint 9 review machinery (`ReviewDraftDrawer`, three review endpoints); `PROJECT-WORKFLOW.md` §3 + §5; `04-API-DESIGN.md`; `security/tenancy.md`; `tech-debt.md`.

---

## Scope

### Backend

- **`GET /api/v1/agencies/{agency}/campaigns/{campaign}/drafts`** — net-new, agency-scoped, in the existing campaigns route group. View-gated. Paginated (`page` / `per_page`, default 25, max 100). Filterable by `?review_status=pending|approved|rejected|revision_requested`. Returns all draft versions (flat rows, not latest-per-assignment). Two-hop join through `campaign_assignments` (b1 — no `campaign_id` denormalization).
- **`CampaignDraftListItemResource`** — summary shape (`type: campaign_draft_list_item`): version, review_status, submitted_at, review_feedback, nested `assignment.{id,status,creator}`. **No media, no presigned URLs.**
- **`CampaignDraftController::index`** — single action, no service layer (assignments-index precedent).

### Frontend

- **`listDrafts`** api-client method + TypeScript types in `@catalyst/api-client`.
- **`DraftsTab.vue`** — filterable paginated list; lazy-mounted via `v-if="tab === 'drafts'"` (Board precedent). Row action opens page-level `ReviewDraftDrawer` via assignment stub.
- **`CampaignDetailPage.vue`** — Drafts tab lit up (`drafts` removed from `comingSoonTabs`); `ReviewDraftDrawer` + resolve/view-post drawers hoisted to page level; `onReviewed` reloads drafts list when drafts tab is active.
- **i18n** — `app.campaigns.drafts.*` added (en/pt/it); `comingSoon.drafts` removed.

### Tests

- **`CampaignDraftListTest.php`** — 8 feature tests (all versions, filter, pagination, cross-tenant 404, non-member 404, view-gate, summary shape, invalid filter → empty page).
- **`DraftsTab.spec.ts`** — mount, filter re-fetch, stub emit, canReview gate, expose.reload.
- **`CampaignDetailPage.spec.ts`** — live Drafts tab (not coming-soon); **onReviewed reloads drafts list after approve from Drafts tab**; existing Creators-tab review assertions unchanged post-drawer-hoist.

### Docs (written, not committed per workflow)

- `04-API-DESIGN.md` — campaign-wide draft list endpoint.
- `security/tenancy.md` — NOT-allowlisted agency-scoped section (join + `assertBelongsToAgency`).
- `tech-debt.md` — closed orphaned Drafts tab gap; opened `campaign_id` denormalization trigger.

---

## Acceptance criteria — all met

| #   | Criterion                                                 | Status | Evidence                                                                                             |
| --- | --------------------------------------------------------- | ------ | ---------------------------------------------------------------------------------------------------- |
| D-1 | GET drafts endpoint, paginated + filterable, all versions | ✅     | `CampaignDraftController`, `CampaignDraftListTest`                                                   |
| D-2 | b1 two-hop join, no denormalization                       | ✅     | Join query + inline tech-debt comment + `tech-debt.md` trigger                                       |
| D-3 | Summary resource, NO signed media URLs                    | ✅     | `CampaignDraftListItemResource`; test asserts no `media`/`view_url`/`thumbnail_view_url` in `data[]` |
| D-4 | List view-gated; drawer/review endpoints unchanged        | ✅     | `Gate::authorize('view')` on list; S9 review tests untouched                                         |
| D-5 | Cross-tenant → 404                                        | ✅     | `assertBelongsToAgency` + join `agency_id`; cross-tenant absence test                                |
| D-6 | Drafts tab + drawer reuse 100%                            | ✅     | `DraftsTab` → stub → hoisted `ReviewDraftDrawer` → `showAssignment`; zero drawer changes             |
| D-7 | No net-new audit verbs / notifications                    | ✅     | Confirmed — list read adds none                                                                      |

---

## Standout design choices (unprompted)

- **`type: campaign_draft_list_item`** distinct from `campaign_draft` — structural guard preventing FE from treating list rows as drawer payloads.
- **Drawer hoist to page level** — wiring-only change enabling both Creators and Drafts tabs to share one `ReviewDraftDrawer` without duplication.
- **`onReviewed` dual-refresh** — assignments list (Creators tab) + drafts list (when active) prevents stale pending status after approve from Drafts tab.

---

## Decisions documented for future chunks

- Campaign-wide draft reads: **view** ability. Review detail + actions: **review** ability (unchanged). Matters when a viewer-only role lands.
- List resource stays summary-only; signed media loads lazily via `showAssignment`.
- Invalid `review_status` filter → empty page (200), `CampaignController::applyStatusFilter` precedent.

---

## Follow-up items

### For future campaigns FE work

- Playwright campaign-detail E2E remains deferred (`tech-debt.md` — existing entry).

---

## What was deferred (with triggers)

| Item                                                       | Trigger                                                    |
| ---------------------------------------------------------- | ---------------------------------------------------------- |
| Denormalize `campaign_id` onto `campaign_drafts`           | Campaign-drafts list query slow at volume (`tech-debt.md`) |
| Agency-side campaign-detail Playwright E2E                 | Next chunk materially extending campaign-detail FE         |
| Viewer-only role FE polish (`!canReview` hides row action) | When viewer role is added to agency membership             |

---

## Verification results

| Gate                         | Result                                                       |
| ---------------------------- | ------------------------------------------------------------ |
| `CampaignDraftListTest`      | 8 passed (36 assertions)                                     |
| `DraftsTab.spec.ts`          | 5 passed                                                     |
| `CampaignDetailPage.spec.ts` | 25 passed (incl. drafts reload + Creators review regression) |
| Pint                         | Clean (new PHP files)                                        |
| PHPStan                      | Clean (new controller + resource)                            |
| vue-tsc (`apps/main`)        | Clean                                                        |
| ESLint (touched FE)          | Clean                                                        |

---

## Spot-checks for independent review

1. **Summary shape (D-3 anchor):** `CampaignDraftListTest` — `json_encode(data[])` must not contain `media`, `view_url`, or `thumbnail_view_url`.
2. **Cross-tenant 404 (D-5 anchor):** Agency A admin → Agency B campaign `/drafts` → 404.
3. **onReviewed reload (S5 promotion):** `CampaignDetailPage.spec.ts` — approve from Drafts tab → `listDrafts` called twice.
4. **Drawer reuse:** List item stub → `openReview` → hoisted drawer; no changes to `ReviewDraftDrawer.vue` or review endpoints.
5. **Auth split:** List uses `view`; `showAssignment` still uses `review` (grep `CampaignAssignmentReviewController`).

---

## Cross-chunk note

None this round. S9 review machinery reused verbatim.

---

_Provenance: drafted by Cursor as the closing artifact for the Campaign-Detail Drafts Tab chunk (`PROJECT-WORKFLOW.md` §3 step 6). Status "Ready for review" — pending independent review pass._
