# Admin all-creators filter (#3) + creator profile modal (#4) + minimal rich text (#2) — read-only inventory

- **Status:** Inventory only. No edits, no plan, no code. Kickoff follows after review.
- **Date:** 2026-08-18
- **Author:** Cursor (read-only pass), for Claude's three-chunk kickoff.
- **HEAD:** `58ca123322d5ac61b239712c51ce4e7f26e1fde4` (`58ca1233`), **= `origin/main`**
  (`git rev-list --left-right --count origin/main...HEAD` → `0 0`). Working tree clean apart
  from this file. Tip is `docs(reviews): close the eyes-on batch — inventory, AH-071..AH-078,
resumption, deploy-log`.
- **Orientation read before writing:** `docs/WORKING-PROCESS.md`; `docs/PROJECT-WORKFLOW.md` §5
  (esp. 5.31 markdown/v-html, 5.32, 5.34, 5.37, 5.38, **5.40**); `docs/reviews/RESUMPTION-TEMPLATE.md`
  Part 2 (through **AH-078**); `docs/reviews/adhoc-changes-log.md` (AH-078 → AH-071, plus AH-063,
  AH-059, AH-054, AH-051); `docs/reviews/admin-connections-review.md` (AH-051 D-1 contact gate);
  `docs/reviews/jobs-board-c5-review.md` (AH-059 D4 — the two card types); `docs/02-CONVENTIONS.md` §9
  (`v-html`); `docs/05-SECURITY-COMPLIANCE.md` (markdown sanitizer); `apps/main/tests/unit/architecture/form-error-pattern.spec.ts`.

**§5.40 line for this document:** `PROD-DATA RISK: NONE` — this pass read files and ran `git`
read-only commands. Nothing was executed against any database.

**Plan-pause forecast (re-derived at plan-pause, not binding here):**

| Chunk                            | Forecast     | Why                                                                                                                                                                                                                                                                                                       |
| -------------------------------- | ------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **#3** Admin all-creators filter | **NONE**     | Additive query params on an existing admin list. No migration. No row write.                                                                                                                                                                                                                              |
| **#4** Creator profile modal     | **NONE–LOW** | No new endpoint, no migration expected. LOW only if the kickoff widens the roster-detail gate or adds `avatar_url` to `CampaignAssignmentResource` (a creator-identity accretion on a live payload).                                                                                                      |
| **#2** Minimal rich text         | **LOW**      | No schema change (both columns are already `text`). The risk is a **new render surface for agency-authored content in creator browsers** — `v-html` of `campaigns.description` on the job-detail page, which today is Vue-escaped. Sanitizer miss = XSS to every rostered creator who opens a listed job. |

---

## 0. The three answers that shape the kickoffs

Read this section first; the rest is evidence.

### 0.1 I4.3 — one-endpoint is **feasible for applicants, not for every assignment card**

`GET /api/v1/agencies/{agency}/creators/{creator}` (`AgencyCreatorDetailController::show`,
`apps/api/app/Modules/Agencies/Http/Controllers/AgencyCreatorDetailController.php:43-51`) already
returns the screenshot's payload (`AgencyCreatorDetailResource`,
`apps/api/app/Modules/Agencies/Http/Resources/AgencyCreatorDetailResource.php:86-113`) and
authorizes on **relation-exists, any status** (`AgencyCreatorRelationGuard::requireExisting`,
`:117-119` of the controller; guard at
`apps/api/app/Modules/Agencies/Support/AgencyCreatorRelationGuard.php:45-58` — 404 when no row).
Pinned: `AgencyCreatorDetailTest` reads across `roster` / `prospect` / `external`
(`apps/api/tests/Feature/Modules/Agencies/AgencyCreatorDetailTest.php:76-82`); a stranger with no
row is 404 (`:73`).

**Application cards: yes, same endpoint, same authz.** An applicant is rostered by definition.
`JobsBoardVisibility::visibleTo()` leg 3 is `permitsMessaging()` (roster + non-blacklisted)
(`apps/api/app/Modules/Campaigns/Services/JobsBoardVisibility.php:98-108`); apply re-uses that
builder. `CampaignApplicationListItemResource` states this as the identity invariant
(`apps/api/app/Modules/Campaigns/Http/Resources/CampaignApplicationListItemResource.php:20-26`).

**Assignment cards and the campaign Creators-tab rows: not always.** Direct invite is
**first-contact-capable** — `CampaignAssignmentController::store` targets any
`approved` + `is_discoverable` creator, **"NO roster relation required"**
(`apps/api/app/Modules/Campaigns/Http/Controllers/CampaignAssignmentController.php:151-158`).
`CampaignInvitationService::invite` creates the assignment and does not touch
`agency_creator_relations` (`apps/api/app/Modules/Campaigns/Services/CampaignInvitationService.php:81-110`).
A cold-invitee therefore has a board card and a Creators-tab row, and
`rosterApi.show` **404s**.

So: a single `CreatorProfileDialog` fed by the existing roster-detail resource is the right
shape **where a relation exists**. It is not a drop-in for every assignment card. Kickoff
must pick: hide Profile when the relation is missing; fall back to the thinner discover
public-profile resource (`GET …/creators/discover/{creator}`); or refuse the "no new endpoint"
frame for that subset. Reported, not decided.

### 0.2 I2.2 — the sanitizer already exists; the architecture-spec allowlist does not

`apps/main` already depends on `markdown-it@^14.1.1` and `dompurify@^3.4.3`
(`apps/main/package.json:25,27`). The sanctioned client pipeline is `renderBio()` in
`apps/main/src/modules/onboarding/composables/useBioRenderer.ts:24-58`: `html: false`,
DOMPurify allowlist `{p, br, strong, em, a, ul, ol, li, code}`, links forced
`target="_blank" rel="noopener nofollow"`. Pinned by `useBioRenderer.spec.ts`.

There is **no** `no-v-html.spec.ts` architecture allowlist. The mechanism is ESLint
`vue/no-v-html` (plugin-vue recommended, **warning**). Standing warnings: `ClickThroughAccept.vue`
(trusted server HTML, justified in the file docblock `:12-21`, **no** disable comment) and
`ProfileBasicsForm.vue:408` (bio preview via `renderBio`, **no** disable comment). The AH-063
precedent for a **new** suppression is `AuthFooterMonogram.vue:66` —
`<!-- eslint-disable-next-line vue/no-v-html -->` on a build-time `?raw` SVG with no runtime
input. A sanctioned rich-text **render** component needs: (1) sanitize-then-`v-html`,
(2) an in-file justification, (3) an AH-063-style disable if the standing warning count must
not grow. It does **not** join `form-error-pattern.spec.ts` unless it is also a submitting
form that binds 422s (`CANONICAL_422_FILES` at
`apps/main/tests/unit/architecture/form-error-pattern.spec.ts:47-96`).

**Mails stay plaintext.** `offer_description` and `campaigns.description` do not flow into any
mailable. `NotificationType::AssignmentInvited` is in `DEFERRED_WITHOUT_EMITTER`
(`apps/main/src/modules/notifications/templates.spec.ts:35-43`) — no emit site, no mail.
`CampaignInvitationService` explicitly does not notify (`:54-57`). `ApplicationAcceptedMail`
and `JobPostedMail` carry campaign **name** only. No mail-safe renderer is required for this
ask unless the kickoff adds an invite mail.

Two mismatches with the ask, if `renderBio` is cloned as-is: `breaks: false` (`useBioRenderer.ts:30`)
so single newlines stay paragraphs, not `<br>`; the allowlist is **wider** than
"links + bold/italic + line breaks" (lists + `code`). Size of #2 is "extract a tighter sibling
of `renderBio` + swap `{{ }}` at every render site," not "add a markdown library."

### 0.3 I3.1 — application-status and KYC filters already exist on the same endpoint; "connected" is a new EXISTS, and roster-only is the honest predicate

`GET /api/v1/admin/creators` (`AdminCreatorController::index`,
`apps/api/app/Modules/Creators/Http/Controllers/Admin/AdminCreatorController.php:71-133`) already
accepts `?status=` (application) and `?kyc_status=` (orthogonal). Unknown enum → empty page, not
422 (`:84-106`). The All Creators page calls this with **neither** param
(`apps/admin/src/modules/creators/pages/AllCreatorsPage.vue:51-54`). The review-queue chip group
(`CreatorListPage.vue:123-139`) and the KYC-queue chip group (`KycQueuePage.vue:113-124`) are
the clone target.

**Cheap:** `application_status` is a column filter served by `idx_creators_application_status`
(`apps/api/database/migrations/2026_05_14_100000_create_creators_table.php:99`). `kyc_status` is
the same shape (`varchar(16)` on `creators`, `:85` of that migration) **with no dedicated
index** — the KYC queue already lives with that; cloning the chip onto All Creators costs
nothing new in the query.

**Connected is the new predicate, and it is not indexed for this direction.**
`agency_creator_relations` unique is `(agency_id, creator_id)` (`:113` of
`2026_05_14_100007_create_agency_creator_relations_table.php`); indexes are
`(agency_id, is_blacklisted)` and `invitation_token_hash`. There is **no** `creator_id`-leading
index. An `EXISTS (… WHERE creator_id = creators.id AND …)` is a Hash Semi Join at current
scale, not a nested-loop disaster, but it is the one query that is new.

**Honesty vs cheapness (decide at kickoff):**

| Predicate                                       | SQL                                                                                  | Honest "connected"?                                                                                                                       |
| ----------------------------------------------- | ------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------- |
| Any relation row                                | `EXISTS` on `creator_id`                                                             | **No.** Includes `pending_request`, `prospect`, `declined`, `ended`, `external`.                                                          |
| Any non-blacklisted relation                    | `EXISTS` + `is_blacklisted` false/null                                               | **No.** Same statuses; this is `CreatorPolicy::hasNonBlacklistedRelation` (`CreatorPolicy.php:297-312`), the **pre-AH-051** contact gate. |
| `relationship_status = roster`                  | `EXISTS` + status                                                                    | **Almost.** Matches the vocabulary. Includes blacklisted-but-rostered.                                                                    |
| `permitsMessaging()` (roster + non-blacklisted) | `AgencyCreatorRelation::scopePermitsMessaging` (`AgencyCreatorRelation.php:150-157`) | **Yes**, per AH-051 D-1 / AH-010: "connected" = roster, non-blacklisted. Contact and messaging share this primitive so they cannot drift. |

For an **admin, cross-agency** list, "connected" means "has at least one such relation with
**any** agency," not "connected to a particular agency." A creator with only `pending_request`
rows is unconnected under the honest predicate. That is the AH-051 tightening applied as a
filter rather than a withhold.

---

## I3.1 — The admin all-creators list today

### Controller / query

One endpoint serves three pages:

| Page         | Route                                                                                 | What it sends                      |
| ------------ | ------------------------------------------------------------------------------------- | ---------------------------------- |
| Review queue | `app.creators.list` → `/creators` (`apps/admin/src/modules/creators/routes.ts:25-30`) | `?status=` (default `pending`)     |
| KYC queue    | `app.creators.kyc` → `/creators/kyc` (`:32-36`)                                       | `?kyc_status=` (default `pending`) |
| All creators | `app.creators.all` → `/creators/all` (`:38-42`)                                       | **no filter params**               |

Backend: `AdminCreatorController::index` (`:71-133`). Gate: `CreatorPolicy::viewAny` =
`platform_admin` (`CreatorPolicy.php:64-67`). Route middleware `auth:web_admin` +
`EnsureMfaForAdmins` (`apps/api/app/Modules/Creators/Routes/api.php:348-353`). Pagination
`per_page` 1–100, default 25 (`:75-76`). Order: `submitted_at DESC, id DESC` (`:80-81`).
Eager-load `user:id,email` (`:79`). List-card attributes only: `display_name`, `email`,
`application_status`, `kyc_status`, `profile_completeness_score`, `submitted_at`, `created_at`
(`:110-122`). No relation payload, no connected flag, no search `q`.

Client: `adminCreatorsApi.list` (`apps/admin/src/modules/creators/api/creators.api.ts:203-211`)
already threads `status` and `kyc_status` via `AdminCreatorListParams` (`:54-59`). Adding
`connected` is a params + query-string change on a wrapper that already exists.

### Existing filter / search params

**None on All Creators.** No chip group, no `q`, no connected column. The table shows
application-status and KYC as **display chips**, not filters (`AllCreatorsPage.vue:121-129`).

Search exists on the **agency** roster (`?q=` FTS, `idx_creators_search_gin`) — not on this
admin list. Out of the asked filter set unless the kickoff adds it.

### Chip-filter precedent to clone

`CreatorListPage.vue:68-74, 123-139`: `v-chip-group` + `filter` + `mandatory`, values
`pending | approved | rejected | incomplete | all`, `watch` resets page to 1 and re-queries.
`KycQueuePage.vue:55-61, 113-124`: same widget, `kyc_status` axis
`pending | verified | rejected | none | all` (KYC also has `not_required`; the KYC page does
not chip it). `AgencyListPage.vue:130-141` is the same chip-group pattern on a different
resource. Clone the review-queue widget; All Creators would host **three** chip groups (or one
group plus two) sending independent params the backend already composes with AND.

### What "connected" means queryably

See §0.3. Additional facts:

- `RelationshipStatus`: `roster | external | prospect | pending_request | declined | ended`
  (`apps/api/app/Modules/Creators/Enums/RelationshipStatus.php:10-40`).
- Admin already **lists** per-creator connections on the drill-in
  (`GET /admin/creators/{ulid}/connections`, AH-051). The All Creators filter is the inverse
  question: among **all** creators, who has / hasn't a qualifying relation.
- Blacklisted-but-rostered: the relation row remains `roster` with `is_blacklisted = true`.
  Contact is withheld (`canSeeContactDetails`); the admin drill-in still shows the connection.
  Filtering them as "unconnected" would disagree with the drill-in.

### Candidate filters and query cost

| Filter                  | Backend today                                   | Index                                      | Cost to add on All Creators                                                                                                                                                                                              |
| ----------------------- | ----------------------------------------------- | ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Application status      | `?status=` (`AdminCreatorController.php:83-92`) | `idx_creators_application_status`          | **FE only** — page does not send the param. Enum: `incomplete \| pending \| approved \| rejected` (`ApplicationStatus.php:20-26`).                                                                                       |
| KYC state               | `?kyc_status=` (`:94-106`)                      | **none** on `creators.kyc_status`          | **FE only**. Enum: `none \| pending \| verified \| rejected \| not_required` (`KycStatus.php:30-36`). KYC page omits `not_required` from chips.                                                                          |
| Connected / unconnected | **absent**                                      | no `creator_id`-leading index on relations | **New query param** + `EXISTS` / `NOT EXISTS`. Honest form: `permitsMessaging()` (or roster-only — kickoff). Cheap at current volume; flag the missing index if the admin list is filtered this way as a standing query. |

Tests already pinning the two existing params: `AdminCreatorIndexTest.php` (`?status=`),
`AdminCreatorHistoryTest.php:24,50` (`?kyc_status=`). All Creators has **no** page spec
(only `AllCreatorsPage.vue`; `CreatorListPage.spec.ts` and `KycQueuePage.spec.ts` are the
mirrors).

---

## I4.1 — The screenshot's source, and the AH-051 contact gate

### Resource / endpoint

Agency roster detail, not the admin creator show.

- **Route:** `GET /api/v1/agencies/{agency}/creators/{creator}`
  (`apps/api/app/Modules/Agencies/Routes/api.php:122-123`), name `agencies.creators.show`.
  PATCH sibling at `:124-125` is rating/notes only.
- **Controller:** `AgencyCreatorDetailController::show` (`:43-51`) → `detailResponse` (`:95-109`).
- **Resource:** `AgencyCreatorDetailResource` (`:86-161`). Dedicated shape — **not**
  `CreatorResource::withAdmin(true)` (docblock `:20-33`: admin KYC PII must never reach an
  agency; relation-private rating/notes/blacklist have no home on `CreatorResource`).
- **FE wrapper:** `rosterApi.show` (`apps/main/src/modules/roster/api/roster.api.ts:67-69`).
  Types: `AgencyCreatorDetailResource` / `AgencyCreatorDetailProfile`
  (`packages/api-client/src/types/agency.ts:353-428`).
- **Page that is the screenshot:** `apps/main/src/modules/roster/pages/CreatorDetailPage.vue`.

Payload blocks matching the ask:

| Block            | Where                                                                                                                                                                                       | Notes                                                                                                                                                                                                              |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Profile          | `attributes.creator.{display_name, bio, country, region, languages, accent, companions, categories, avatar_url, cover_url, application_status}` (`AgencyCreatorDetailResource.php:121-148`) | `bio` is markdown-source on the type (`packages/api-client/src/types/creator.ts:103-104`) but the roster page renders it escaped (`CreatorDetailPage.vue:425-426`) — asterisks today if the creator used markdown. |
| Contact          | `phone, whatsapp, address_street, address_postal_code` — **keys omitted** when the gate fails (`:154-159`)                                                                                  | Email is **not** in this gate; `email` always ships on a relation-exists read (`:128`, D-2a-8).                                                                                                                    |
| Account-creation | `account_name`, `account_last_name` (`:131-132`)                                                                                                                                            | Sign-up first/last; never on discover.                                                                                                                                                                             |
| Socials          | `social_accounts[]` `{platform, handle, profile_url, is_primary}` (`:171-186`)                                                                                                              | No OAuth tokens, no metrics.                                                                                                                                                                                       |
| Rating / notes   | `internal_rating`, `internal_notes` (`:99-100`)                                                                                                                                             | Agency-private.                                                                                                                                                                                                    |
| Blacklist        | `is_blacklisted, blacklist_scope, blacklist_type, blacklisted_at` (`:106-109`)                                                                                                              | Structured facts only; `blacklist_reason` withheld (GDPR, `:35-42`).                                                                                                                                               |

### Authorization path (the AH-051 roster-only contact gate)

Three stacked legs, not one:

1. **Tenant / membership.** Route sits under `auth:web → tenancy.agency → tenancy`
   (controller docblock `:27-28`). Non-member → 404 before the controller runs.
2. **`viewAny` on `AgencyCreatorRelation`.** Any accepted membership
   (`AgencyCreatorRelationPolicy::viewAny`, `:45-48` — admin / manager / staff). Read stays
   any-member (D-2a-4).
3. **Relation exists (any status).** `requireRosterRelation` →
   `AgencyCreatorRelationGuard::requireExisting` (`:45-58`). No row → 404 (must not leak
   whether the creator exists). `ended` / `declined` still resolve — "a terminal state is
   still a relation" (`:42-43`).

**Contact is a fourth, narrower gate,** applied after the page is authorized:

- Controller: `$canSeeContact = Gate::allows('canSeeContactDetails', [$creator, $agency])`
  (`AgencyCreatorDetailController.php:104-108`).
- Policy: `CreatorPolicy::canSeeContactDetails` (`:144-166`).
  - Platform admin: always (`:146-148`).
  - Caller must be an **active member of THIS agency** (`:153-155`) — not a user-wide union.
  - THIS agency must hold a **non-blacklisted `roster`** relation, via
    `AgencyCreatorRelation::permitsMessaging()` (`:160-165`).
  - `pending_request` / `declined` / `prospect` / `ended` / `external` all fail.
  - Does **not** add messaging's creator-`approved` leg (`:132-134`) — roster is the consent
    event; application-approval is orthogonal here.

Pinned: `ContactDetailsWithholdingTest` §7 (`:176-228`) — positive `roster` case + parameterized
withhold for `pending_request`, `declined`, `prospect`, `ended`. Break-revert named in the
test: relax the gate back to `hasNonBlacklistedRelation` → those four fail.

Write (rating/notes): `AgencyCreatorRelationPolicy::update` = admin + manager (`:67-70`);
staff 403. Scope-guarded to those two keys
(`AgencyCreatorDetailController.php:69-74`;
`UpdateAgencyCreatorRelationRequest` `internal_notes` max 5000). Blacklist is a **separate**
endpoint (`CreatorBlacklistController`), same admin/manager floor (`blacklist` ability, `:92-95`
of the policy).

---

## I4.2 — The three mount contexts

AH-059 D4 split the board into **two card types**: a real `board_cards` row (an assignment) and
a **pseudo-card** (an application). They do not share a drawer, a resource, or a drag group
(`docs/reviews/jobs-board-c5-review.md` D4; `BoardApplicationsColumn.vue:1-44`).

### A. Campaign Creators-tab rows

`CampaignDetailPage.vue:601-673` — `v-list-item` per assignment from
`campaignsApi.assignments`. Identity on the wire
(`CampaignAssignmentResource.php:85-88`):

```
creator: { id (ULID), display_name } | null
```

**No `avatar_url`.** The row renders name + status chip + fees (`CampaignDetailPage.vue:628-647`).
No click-through to roster detail; no profile affordance.

AH-075 precedent for putting a photo on a list that already has the creator ULID: the roster
list / pool picker grew a signed `avatar_url` (bounded page, same minting helper).
`AddCreatorsToPoolDialog.vue:25-29` documents that the slim roster row carries it "since the
invite-offer-details batch put `avatar_url` on it for the campaign invite picker." The Creators
tab never consumed that pattern. Adding it here is an **api-client + resource accretion** on
every assignment list row (exact-keyset discipline on sibling resources is the caution).

The row **does** carry `creator.id` (ULID) — enough to call `rosterApi.show(agencyId, creatorId)`
without a new endpoint, subject to §0.1's 404 for non-related invitees.

### B. Board assignment cards

`BoardCardResource.php:75-79` — card-face creator is `{ id, display_name, avatar_url }`.
`BoardCard.vue:6-7, 39` consumes the signed photo with initial fallback (AH-075/board-facelift
shape, not `CreatorAvatar`'s lightbox). Click opens `BoardCardDrawer` (Sprint 12 Chunk 2 / AH-072).

Drawer tabs **today** (`BoardCardDrawer.vue:4-31, 79, 333-346`):

| Tab      | Default | What it is                                                                                       |
| -------- | ------- | ------------------------------------------------------------------------------------------------ |
| Messages | **yes** | `ChatPanel` on the per-assignment thread                                                         |
| Detail   |         | Identity header (avatar + name + status + campaign · brand), offer terms, timeline, latest draft |
| Drafts   |         | Full `DraftReviewPanel` (AH-072 — was a hand-off, now in-drawer)                                 |
| History  |         | Card movements                                                                                   |

**Where a Profile tab slots:** a fifth `v-tab` beside Detail (same drawer, fetch
`rosterApi.show` on open), **or** a control on the Detail identity header that opens a dialog
without adding a tab. Detail already has the avatar + name (`:161-167`); it does not have
contact, account-creation, socials, rating, notes, or blacklist. Those live only on the roster
detail resource.

Drawer fetch today: `campaignsApi.showAssignment` + `boardApi.movements` (`:261-288`). Profile
would be a **third** request, not an over-fetch of assignment detail — assignment detail does
not carry the roster blocks.

### C. Application pseudo-cards (AH-059 D4)

`BoardApplicationsColumn.vue` — pending-only, **no drawer**, **no `<draggable>`**. Card face
(`:179-237`): avatar (`creator.avatar_url`, `:187-195`), name, pending chip, applied-at, note,
Accept / Reject. Those two actions open `AcceptApplicationDialog` / `RejectApplicationDialog`
— the **same** dialogs the Applications tab uses (`:31-35, 51-53`).

Resource: `CampaignApplicationListItemResource` (`:67-71`) —
`{ id, display_name, avatar_url }`. Contact deliberately not emitted (`:28-33`). Creator ULID
is present → `rosterApi.show` is the profile fetch, and **authorizes** (applicant is rostered).

The Applications **tab** (`ApplicationsTab.vue`) is the keep-both history surface (AH-059 D4):
same composable, all statuses, same two dialogs, avatar + name on the row (`:197-209`). Rows
are not click-throughs to roster. Profile on this surface is also a dialog (or a name-click),
not a drawer — there is no drawer to slot a tab into.

**Confirm same endpoint for applicants:** yes. `requireExisting` finds the `roster` row that
`permitsMessaging()` required for them to see the job. Contact still goes through
`canSeeContactDetails` (same `permitsMessaging()` primitive), so a rostered non-blacklisted
applicant's profile modal gets the contact block; a later agency-wide blacklist would withhold
it without 404ing the page.

### Avatar availability summary

| Context                | Creator ULID | `avatar_url` on the list/card                          | AH-075/078 precedent                                                                      |
| ---------------------- | ------------ | ------------------------------------------------------ | ----------------------------------------------------------------------------------------- |
| Creators tab           | yes          | **no**                                                 | Would need a resource accretion, or wait for the modal fetch                              |
| Board assignment card  | yes          | **yes** (`BoardCardResource.php:78`)                   | Card-face `v-avatar`; modal can reuse `CreatorAvatar.vue` (AH-078, squared + previewable) |
| Application card / tab | yes          | **yes** (`CampaignApplicationListItemResource.php:70`) | Same `v-avatar` + initial                                                                 |

---

## I4.3 — Reuse-vs-build (expanded)

**One component, one endpoint, three hosts — with a relation-exists precondition.**

There is no `CreatorProfileDialog` today. `CreatorDetailPage.vue` **is** the full-page
composition of that resource (profile, contact, account, social, metrics empty-state,
portfolio, availability, campaigns empty-state, rating/notes rail, blacklist rail). A modal
that wants the screenshot's blocks can:

- mount a slim dialog that calls `rosterApi.show` and renders a **subset** of those sections
  (recommended vs. stuffing the full page into a `v-dialog` — availability/portfolio/campaigns
  are page-scale);
- reuse `StarRatingInput.vue`, `BlacklistCreatorDialog.vue`, `BlacklistBadge` (`@catalyst/ui`),
  `CreatorAvatar.vue` (AH-078).

**No new endpoint** holds for every context **except** cold-invite assignment cards (§0.1).
**No over-fetch** holds: the list/card payloads are identity-only; the modal fetches the detail
on open. Assignment-detail (`showAssignment`) is the wrong resource — it has offer terms, not
contact/rating/notes/socials.

### Rating / notes + blacklist: render or omit

| Surface                                      | Components today                                                                                      | Editability                                                                                                      | Campaign-context argument                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| -------------------------------------------- | ----------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Roster page                                  | `CreatorDetailPage.vue:643-727` (rating/notes), `:731+` (blacklist section), `BlacklistCreatorDialog` | `canEdit` = agency_admin \| agency_manager (`:65-67`); staff read-only notes; blacklist section `v-if="canEdit"` | Canonical.                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| Creators tab / board card / application card | **none**                                                                                              | —                                                                                                                | Campaign contexts are looking at **this** creator in **this** commercial relationship. Rating/notes are the agency's private memory; blacklist is why an accept/invite might be the wrong next click. Rendering them (read-only for staff, wired for admin/manager via the existing PATCH / blacklist endpoints) is consistent with the resource they would already fetch. Omitting them makes the modal a public-profile clone, which discover already is. |

Wiring if included: `rosterApi.updateRelation` (`roster.api.ts:75-83`) and
`rosterApi.blacklist` / `unblacklist` (`:92-114`). No new write path. `form-error-pattern`:
`CreatorDetailPage` is **not** on `CANONICAL_422_FILES` (save errors are a banner,
`saveError`, not per-field `extractFieldErrors`). A modal that copies that banner stays off
the allowlist; a modal that binds 422s onto notes would join it.

`internal_notes` max 5000 (FE `maxlength="5000"` at `CreatorDetailPage.vue:675-676`; BE
`UpdateAgencyCreatorRelationRequest`).

---

## I2.1 — The three content fields

The invite copy key is `app.campaigns.invite.descriptionLabel` = **"Description (what you expect)"**
(`apps/main/src/core/i18n/locales/en/app.json:620`). Column name: `offer_description`.

### Field A — invite "what we expect" (`campaign_assignments.offer_description`)

- **Column:** `text`, nullable (`apps/api/database/migrations/2026_07_12_100000_add_offer_fields_to_campaign_assignments.php:20,34`).
- **BE cap:** `max:2000` (`ValidatesAssignmentOffer.php:48`).
- **FE cap:** `maxlength="2000"` (`OfferFieldsForm.vue:176-187`). Editor is a plain `v-textarea`.
- **Written at:** invite (`CampaignInvitationService.php:95`), re-offer, application accept
  (same `AssignmentOffer` VO). Shared editor: `OfferFieldsForm.vue` (InviteCreatorsDialog +
  AcceptApplicationDialog, docblock `:8-16`).

**Render sites (escaped `{{ }}` today):**

| Site                          | Path:line                                 | Audience |
| ----------------------------- | ----------------------------------------- | -------- |
| Creator assignment **list**   | `CreatorAssignmentsPage.vue:174-179`      | creator  |
| Creator assignment **detail** | `CreatorAssignmentDetailPage.vue:531-535` | creator  |
| Board card drawer Detail tab  | `BoardCardDrawer.vue:190, 437-442`        | agency   |

Not on the card face. Not in mail. Agency Creators-tab rows do not show it (fee only).

### Field B — campaign description (`campaigns.description`)

- **Column:** `text`, nullable (`apps/api/database/migrations/2026_06_05_100000_create_campaigns_table.php:51`).
- **BE cap:** `max:5000` on create (`CreateCampaignRequest.php:55`) and update
  (`UpdateCampaignRequest.php:53`).
- **FE cap:** **none**. `CampaignForm.vue:170-180` is a `v-textarea` with no `maxlength` (name
  has `maxlength="255"`; listing_duration/fee have `maxlength="120"`). The 5000 cap is
  backstop-only until the FE mirrors it.
- **Emitted:** `CampaignResource.php:38` (agency); `CreatorJobDetailResource.php:75` (creator
  job **detail** only).

**Render sites:**

| Site                      | Path:line                                                                           | Audience    | Security                                                    |
| ------------------------- | ----------------------------------------------------------------------------------- | ----------- | ----------------------------------------------------------- |
| Agency campaign overview  | `CampaignDetailPage.vue:550-558` (`data-test="overview-description"`)               | agency      | escaped                                                     |
| Campaign create/edit form | `CampaignForm.vue:170-180` (`data-test="campaign-description"`)                     | agency      | editor                                                      |
| **Creator job detail**    | `CreatorJobDetailPage.vue:234-238` (`data-testid="creator-job-detail-description"`) | **creator** | escaped today; **the XSS surface if this becomes `v-html`** |

**Not rendered:**

- Creator job **card** / jobs list — `CreatorJobCardResource::cardAttributes`
  (`:88-105`) has `listing_fee` / `listing_duration`, not `description`. `CreatorJobsPage.vue`
  does not print description.
- Creator **assignment** detail — still omits campaign `description` (only
  `offer_description`). The tech-debt entry at `docs/tech-debt.md:385-407` ("creators still
  cannot see the campaign description") is **stale for the job board** and **still true for
  assignment detail**.
- Campaign list page — no description column.
- Admin SPA — no campaign surfaces.

### Field C — AH-054 job-listing fields, and whether they share the pipeline

They share **`description` as the listing body**. They do **not** share a separate
`listing_description` column.

`ValidatesJobsBoardListing::LISTING_FLOOR_FIELDS`
(`apps/api/app/Modules/Campaigns/Http/Requests/Concerns/ValidatesJobsBoardListing.php:51-57`):

`description`, `listing_duration`, `listing_fee`, `listing_languages`, `listing_regions`.

Mirrored FE-side by `LISTING_FLOOR_FIELDS` in `apps/main/src/modules/campaigns/listingFloor.ts`,
pinned by `listing-floor-parity.spec.ts`. Docblock `:41-43`: "`description` is the body copy of
the job card, and AH-032 already made it the field that absorbs deliverables and usage terms."
`CreatorJobDetailResource` restates this (`:16-19`).

The **other** listing fields are short plaintext / structured, not markdown candidates:

| Field                  | Cap                                                                                           | Render                                                                                              |
| ---------------------- | --------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| `listing_duration`     | `max:120` (`ValidatesJobsBoardListing.php:74`); FE `maxlength="120"` (`CampaignForm.vue:239`) | `CreatorJobsPage.vue:171-172`, `CreatorJobDetailPage.vue:226-227`                                   |
| `listing_fee`          | same 120                                                                                      | `CreatorJobsPage.vue:168-169`, `CreatorJobDetailPage.vue:223-224`                                   |
| `listing_languages`    | array, max 24, `Locale` enum                                                                  | chips on job detail (`:241-252`)                                                                    |
| `listing_regions`      | array, max 60, ISO-2                                                                          | chips (`:255-266`)                                                                                  |
| `listing_examples_url` | `url`, `max:2048` (`:83`)                                                                     | `<a rel="noopener noreferrer">` (`CreatorJobDetailPage.vue:272-280`) — already a link, not markdown |

Markdown-ifying `listing_fee` / `listing_duration` would be a product mistake (they are
display copy like `fee_per`). Markdown-ifying `description` **is** the listing-body ask, and
the job-detail page is the creator-facing half of that field's pipeline — miss it and listed
jobs show literal `**bold**`.

### Completeness grep (both SPAs)

`offer_description` in `apps/main` (no admin hits): the three render sites above +
`OfferFieldsForm.vue` (editor) + specs (`CreatorAssignmentsPage.spec.ts`,
`BoardCardDrawer.spec.ts`, `AcceptApplicationDialog.spec.ts`) + `packages/api-client/src/types/campaign.ts`.

`campaign.attributes.description` / `job.attributes.description` in `apps/main`:
`CampaignDetailPage.vue`, `CampaignForm.vue`, `CreatorJobDetailPage.vue` (+ specs). Admin: no
campaign description. Do not confuse with brand/pool/i18n `description` keys (many hits; not
this column).

---

## I2.2 — Sanitization reality (expanded)

### What exists

| Pipeline                           | Where                                                                         | Trust                                                                                                                                                                                                   |
| ---------------------------------- | ----------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Client markdown-it + DOMPurify** | `useBioRenderer.ts`                                                           | Creator-authored **bio**, previewed only on `ProfileBasicsForm.vue:404-409`. Roster/discover **do not** use it — they `{{ bio }}` (`CreatorDetailPage.vue:425-426`, `DiscoverProfilePage.vue:414-415`). |
| **Server league/commonmark**       | `ContractTermsRenderer` (`html_input: 'escape'`, `allow_unsafe_links: false`) | Platform-controlled master-agreement markdown. SPA consumes via `v-html` in `ClickThroughAccept.vue`. Spec: `PROJECT-WORKFLOW.md` §5.31.                                                                |
| **Laravel mail markdown**          | `markdown: 'mail.campaigns.*'` views, `catalyst` theme                        | Localized scalars (`{{ $campaignName }}` etc.). Blade escapes.                                                                                                                                          |
| **DOMPurify as a library**         | `apps/main` dependency                                                        | Used only by `renderBio`. No shared `@catalyst/ui` sanitizer.                                                                                                                                           |
| **Admin SPA**                      | —                                                                             | No markdown-it / DOMPurify.                                                                                                                                                                             |

`docs/05-SECURITY-COMPLIANCE.md:383` still names "bleach or DOMPurify" as the allow-list
sanitizer for creator bios and contract bodies. The living implementation for user markdown is
DOMPurify in `renderBio`; contracts are server CommonMark, not DOMPurify.

### no-v-html "architecture spec"

**There is no allowlist spec file.** Contrast `form-error-pattern.spec.ts:13-19, 47-96`: a
hard-coded list of SFCs that **must** keep an `extractFieldErrors` import; adding a row is a
code-review event. `v-html` is enforced only by ESLint (warning). Current consumers:

| File                                        | Suppression?                        | Why it is safe (as documented)         |
| ------------------------------------------- | ----------------------------------- | -------------------------------------- |
| `ClickThroughAccept.vue:174`                | no (standing warning)               | Server CommonMark of platform markdown |
| `ProfileBasicsForm.vue:408`                 | no (standing warning)               | `renderBio` output                     |
| `EnableTotpPage.vue:118-128` (main + admin) | block disable                       | Backend-produced QR SVG                |
| `AuthFooterMonogram.vue:66`                 | `eslint-disable-next-line` (AH-063) | Build-time `?raw` SVG                  |

A new `SafeMarkdown.vue` (name illustrative only) that `v-html`s `renderBio`-style output
would either: add a third standing warning, or take the AH-063 disable-with-justification.
Nothing in `tests/unit/architecture/` currently fails if a fourth `v-html` appears.

### Mail

See §0.2. `JobPostedMail.php:34-38, 65-71` — campaign name, agency name, deep link; **nothing
about the brand, and no description.** `ApplicationAcceptedMail.php:62-69` — names + assignment
URL; no `offer_description`. Invite assignment: no mailable at all. **Decide at kickoff: mails
stay plaintext** (the code already does). A mail-safe variant is not implied by this ask.

---

## I2.3 — Length caps

| Field                               | DB                               | BE                                                                                                                     | FE mirror?                                           |
| ----------------------------------- | -------------------------------- | ---------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------- |
| `offer_description`                 | `text` nullable — no varchar cap | `max:2000` (`ValidatesAssignmentOffer.php:48`); invite test posts 2001 → 422 (`CampaignApplicationAcceptTest.php:519`) | **yes**, `maxlength="2000"`                          |
| `campaigns.description`             | `text` nullable                  | `max:5000` create + update                                                                                             | **no maxlength** on `CampaignForm.vue`               |
| `listing_duration` / `listing_fee`  | string columns (AH-054)          | `max:120`                                                                                                              | **yes**, `maxlength="120"`                           |
| `listing_examples_url`              | string                           | `url`, `max:2048`                                                                                                      | **yes**, `maxlength="2048"` (`CampaignForm.vue:293`) |
| creator `bio` (sibling, not in ask) | `text`                           | wizard rules                                                                                                           | counter 500 (`ProfileBasicsForm.vue:399`)            |

Storage will not truncate below the validation cap: both ask-fields are `text`. The FE gap on
campaign `description` is the one to close if markdown makes 5000 of `**` + URLs more likely.

`breaks: false` in `renderBio` is a **behaviour** cap, not a length cap: multi-line invite
text that relies on single newlines will not become `<br>` unless the kickoff sets `breaks: true`
on the new renderer (markdown-it: two newlines = new `<p>` either way).

---

## Ripple (all three)

### Tests pinning the touched lists / cards / drawers

| Chunk  | Existing pins                                                                                                                                                                                                                                                                                                               | Likely new / extended                                                                                                                                                           |
| ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **#3** | `AdminCreatorIndexTest.php` (`?status=`); `AdminCreatorHistoryTest.php` (`?kyc_status=`); `CreatorListPage.spec.ts` (chip re-query); `KycQueuePage.spec.ts` (chip re-query, pending badge)                                                                                                                                  | **New** `AllCreatorsPage.spec.ts` (does not exist). Index feature tests for the connected `EXISTS` (positive roster, negative pending_request/ended, blacklist depending on D). |
| **#4** | `AgencyCreatorDetailTest.php` (relation-exists, any-status read, contact withhold is in `ContactDetailsWithholdingTest`); `CreatorDetailPage.spec.ts` (rating/notes/blacklist/avatar); `BoardCardDrawer.spec.ts`; `BoardApplicationsColumn.spec.ts`; `ApplicationsTab.spec.ts`; `CampaignDetailPage.spec.ts` (Creators tab) | Dialog spec; Creators-tab / card / application-card open-profile cases; 404-for-unrelated-invitee if that path stays reachable; `CreatorAvatar.spec.ts` if reused.              |
| **#2** | `useBioRenderer.spec.ts` (bold/italic/links/XSS/javascript:/lists); `CreatorJobDetailPage.spec.ts`; `CampaignDetailPage.spec.ts`; `CreatorAssignmentDetailPage.spec.ts`; `CreatorAssignmentsPage.spec.ts`; `BoardCardDrawer.spec.ts` (offer_description); `CampaignJobsBoardListingTest.php` / listing-floor parity         | Renderer spec (tighter allowlist + `breaks`); every render site's spec asserting HTML not raw `**`; XSS case on the **creator** job-detail path (the new trust boundary).       |

### Admin list specs

Admin E2E is **two files** (`admin-sign-in`, `admin-mandatory-mfa-enrollment`) — **no creators
list coverage**. #3 is unit + feature, not Playwright, unless the kickoff adds a third admin
spec.

### i18n scope estimate

24 locales, both SPAs (`apps/admin/src/core/i18n/locales/*/creators.json` × 24;
`apps/main/src/core/i18n/locales/*/app.json` × 24).

| Chunk  | Namespace                                                                                                                                                   | Estimate                                                                                                                                                            |
| ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **#3** | `admin.creators.all.*` (+ maybe `list.filters` reuse)                                                                                                       | Small: connected/unconnected (+ application/KYC chip labels **already exist** on `admin.creators.list.filters` / `admin.creators.kyc.filters`). ~4–8 new keys × 24. |
| **#4** | `app.roster.detail.*` already has section labels; dialog chrome (`title`, `close`, empty/error)                                                             | Small–medium: dialog shell + maybe "view profile" on three hosts. Reusing roster section keys avoids a second vocabulary.                                           |
| **#2** | Possibly none if the textarea stays and only the **output** changes. A toolbar (Bold/Italic/Link) is new keys under `app.campaigns.*` / `creator.ui.jobs.*` | Zero if no toolbar; ~6–12 keys × 24 if a visible editor chrome ships.                                                                                               |

`i18n-locale-parity.spec.ts` (both SPAs) is the pin.

### E2E exposure

| Leg           | Spec                                                    | What it already walks                                                                                                            | What a chunk would perturb                                                                                                                                                                                                                             |
| ------------- | ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **c3**        | `apps/main/playwright/specs/creator-jobs-board.spec.ts` | Browse → detail → apply. Asserts `[data-testid="creator-job-detail-description"]` contains the seeded description (`:99`).       | **#2** — if that node starts containing HTML, `toContainText` still passes for the text; a raw-`**` regression would still fail the current assertion only if the seed uses markdown. Worth extending the seed + assertion if #2 ships.                |
| **c4**        | `campaign-applications.spec.ts`                         | Applications **tab**, accept/reject, board post-accept.                                                                          | **#4** if Profile is added on the tab row; selectors are `data-test` on accept/reject today.                                                                                                                                                           |
| **c5**        | `jobs-board-full-lifecycle.spec.ts`                     | Lists → creator applies → **Applications column** (`[data-test="board-applications-column"]`, `:178`) → accept → Invited column. | **#4** on the application pseudo-card and then on the assignment card/drawer. AH-072's drafts-tab selectors are in `hand-off-at-approval-lifecycle.spec.ts`, a sibling of this drawer — a fifth Profile tab must not rename `board-card-drawer-tab-*`. |
| Admin         | none for creators lists                                 | —                                                                                                                                | #3 unexposed unless added.                                                                                                                                                                                                                             |
| Roster detail | `creator-detail.spec.ts`                                | Composed roster detail (name/email).                                                                                             | #4 modal is a **new** surface; this spec stays the full-page pin.                                                                                                                                                                                      |

### api-client surface

| Chunk  | Likely touch                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **#3** | `AdminCreatorListParams` lives in the **admin** SPA (`creators.api.ts:54-59`), not `@catalyst/api-client`. A `connected` boolean/enum is local unless the kickoff promotes the admin list type. **No** `@catalyst/api-client` change required.                                                                                                                                                                                                 |
| **#4** | `AgencyCreatorDetailEnvelope` already in `packages/api-client/src/types/agency.ts:407-428`. Modal can type against it with **zero** client change **unless** Creators-tab rows gain `avatar_url` (`CampaignAssignmentResource` + `packages/api-client/src/types/campaign.ts` creator nest — currently `id` + `display_name` only, `CampaignAssignmentResource.php:85-88`). Application and board-card creator nests already have `avatar_url`. |
| **#2** | Fields already on the wire as `string`. Render-only → **no** client change. An editor that posts markdown is still a string.                                                                                                                                                                                                                                                                                                                   |

`form-error-pattern` allowlist: #3 none (filters are not submitting forms — the spec says so,
`:17-18`). #4 none unless the modal binds 422s. #2: `CampaignCreatePage.vue` and
`CampaignDetailPage.vue` already on the list (`form-error-pattern.spec.ts:52-53`);
`OfferFieldsForm.vue` is **not** — parents pass `feeErrors` in. Extracting a markdown textarea
into a child that takes the `extractFieldErrors` import would be an AH-072-style allowlist
**swap**, not a silent drop.

---

## Load-bearing context (short)

- **AH-051 D-1:** contact = roster + non-blacklisted, `permitsMessaging()`, no approved-leg.
  Same primitive as messaging's relation leg. Pending-request agencies lost contact on purpose.
- **AH-059 D4:** application cards are a pseudo-column; they are not `board_cards`; they open
  dialogs, not the assignment drawer; applicants are rostered by the jobs-board visibility
  predicate.
- **AH-072:** board drawer already has four tabs; Drafts is a full review surface in-drawer.
  Profile is a new tab **or** a dialog from Detail — either is a slot, not a new drawer.
- **AH-075 / AH-078:** signed `avatar_url` on bounded lists; `CreatorAvatar.vue` is the squared
  lightbox primitive for **detail** headers (roster + discover), not yet for campaign rows.
- **AH-054:** listing floor includes `description`; there is no parallel listing-body column.
- **AH-063:** the only in-repo model for a **new** `v-html` suppression (trusted, justified,
  disable-next-line). The two onboarding `v-html`s remain warnings.
- **form-error-pattern:** allowlist-driven, main-SPA only; admin has zero consumers (spec
  header `:24-31`).
  )
