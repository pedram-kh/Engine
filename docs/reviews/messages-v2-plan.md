# AH-119 Messages v2: plan (for review; no code until cleared)

> **Status:** drafted 2026-11-06 for Pedram's plan review. Mode A, full loop. Inputs:
> [`messages-v2-inventory.md`](messages-v2-inventory.md) (accepted; the rulings **Q1 to Q13** in its §11 bind and are
> not restated here except where a step depends on one).
> **Base:** `origin/main` after the inventory commit `62731fd5` (AH-116/117/118 pushed, not deployed; production
> unchanged: API `066ff29c`, main `1bc398e8`, admin 2026-10-29 build).
> **Vocabulary:** "Campaign", never "Wave"; "Online" is dropped; A7 (last active) is out of the chunk.
>
> **PROD-DATA RISK (chunk): NONE for every step except these, declared in plain words:**
>
> - A3 reassign writes `owner_user_id` on one relation row, on a user's click (no existing row is written by
>   a migration or a command; the effective owner is computed at read time).
> - B1 edit rewrites `body` on one message row and keeps the original in `original_body`, on the sender's click.
> - B2 delete moves `body` into `deleted_body` and sets `deleted_at` on one message row, on the sender's click.
>   No row is removed and no file is deleted.
> - Every migration here is **schema only** (nullable columns, two indexes). No backfill, no default flip, no
>   data command is planned. Nothing in this plan modifies, deletes or migrates an existing production row.
> - A change to erasure code (B4) does not run an erasure; no erasure runs while this ships (see the deploy entry).

## 0. Decisions I need from you (plan questions)

| #   | Question                                                                                                                                                                                                                                                                                 | Recommendation                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P1  | **Q2 scope.** "System lines excluded from unread and from surfacing a thread": only in the new inbox query, or also in the shared per-thread `MessageService::unreadQuery` (`MessageService.php:213-226`), which the campaign rollup, the thread meta and the **creator** side also use? | Change the shared query for **agency viewers only** is awkward (the query has no viewer role). Recommend: the **new inbox query excludes system lines**, and the shared `unreadQuery` is **left as is** in AH-119 (creator behaviour and the AH-108 badge math are untouched, no creator-visible change in build 3). Consequence: the open thread's `meta.unread_count` and the campaign rollup can differ from the inbox by system lines. Logged as a tech-debt row. |
| P2  | **Q11 breadth.** Run the **whole Pest suite** on Postgres, or a `pg` group?                                                                                                                                                                                                              | Measure first (A0.1). If the whole suite passes after the four known failures are fixed, run the whole suite as a second CI step. If more than a handful fail, ship the `pg` group (named below) as blocking and the rest as a non-blocking report, with the gap written in tech-debt.                                                                                                                                                                                |
| P3  | **Palette** (Q7): the eight keys below, mapped to hex only in the agency theme.                                                                                                                                                                                                          | Approve or amend the list in §7 and the §12 colour names.                                                                                                                                                                                                                                                                                                                                                                                                             |
| P4  | **Deploy bundling.** One deploy carrying AH-116/117/118 and AH-119 (both stages, both flags OFF), or AH-116 to 118 first?                                                                                                                                                                | AH-116 to 118 have no migration; AH-119 has five. Recommend **one deploy, one snapshot**, as you described ("pending one deploy"). If you prefer AH-116 to 118 out first, they go first and the AH-119 entry stays `PENDING` on its own.                                                                                                                                                                                                                              |
| P5  | **Mine and unowned.** Rows whose effective owner is null (a creator-initiated or admin-connected relation never reassigned).                                                                                                                                                             | Shown under **All** only; **Mine** is strictly `effective owner = me`.                                                                                                                                                                                                                                                                                                                                                                                                |
| P6  | **Pill with filters on.**                                                                                                                                                                                                                                                                | The pill is the **unfiltered total** of threads with unread for the viewer (not filtered by brand, campaign or Mine/All); the list and the facet counts follow the filters.                                                                                                                                                                                                                                                                                           |
| P7  | **Stage A flag timing.** You wrote "build 4, then Stage A flag ON". Stage A's inbox is agency web only and does not depend on build 4.                                                                                                                                                   | Follow your order unless you say otherwise; I note that the flag could be armed right after the web deploy without risk to the native app.                                                                                                                                                                                                                                                                                                                            |

### Plan rulings (Pedram, 2026-11-06, plan cleared)

P1 accepted (inbox-only exclusion of system lines; shared `unreadQuery` unchanged; a tech-debt row is written when
A2 lands). P2: **decided after A0.1**: the whole suite as a second CI step if it runs in under 15 minutes in CI,
otherwise a `pg` group. P3 approved (the eight keys). P4: one deploy. P5 yes. P6 yes. P7: the Stage A flag is
armed **after the web deploy** (not after build 4); the §11 deploy order step 8 is read that way. **Mine is a
toggle chip in the filter row beside the brand and campaign popovers** (not a segmented control), logged as a
**Figma deviation** (the frames do not draw it). Instruction: build A0 only and stop with the Postgres suite
timing, the four fixes, the CI step as added and the lock test result; no feature code until P2 is ruled.

## 1. Sequence, at a glance

```
A0  CI Postgres leg                      (first; CI only; no feature code)
A1  API: migrations (owner, colour)      A2 inbox + facets   A3 owner   A4 brand colour
A5  Agency web (flag-gated)              A6 shared linkify + images (creator-reachable, native polarity)
--- Checkpoint A: Pedram eyes-on, gate board, creator baseline walk, simulator bundle ---
B1  API: migrations (message columns, GIN)   B2 edit/delete service + 8 routes   B3 search
B4  Erasure + audit + privacy + runbook      B5 web (actions, search)            B6 creator shell + build 4 prep
--- Checkpoint B: gate board, full Playwright, simulator, TestFlight build 4, device walk ---
Docs close, deploy entry, push on your word.
```

Each sub-step ends green (tests, Pint, PHPStan with no path argument, vue-tsc, ESLint, Prettier on touched
packages, locale parity where keys change) and is a commit (conventional, kebab scope, body lines at most 100).
No push without your word.

## 2. Stage A

### A0 CI Postgres leg (first step, Q11)

Facts: the `backend` job already starts `postgres:16-alpine` (`.github/workflows/ci.yml:24-35`) but Pest runs on
SQLite (`phpunit.xml:53-54`). The AH-118 offer-edit and contract-attach `lockForUpdate` ran on Postgres on one
connection only; four failures predate AH-118 on Postgres (`CampaignApplication*`, `CampaignAttachment*`:
the transaction aborts; `CampaignCrudTest`, `InviteEmailNotificationTest`: jsonb key order)
(`campaign-rules-plan.md` §14; `tech-debt.md:3339`, `:3475`, `:3632`).

- **A0.1 Measure (no code):** on a throwaway local Postgres 16 database `catalyst_test` (never the dev DB; its
  counts read before and after), run the **whole Pest suite**, serially, `-d memory_limit=2G`. Record the
  failure list in the review file. **Stop-gate:** a failure that is an **application** bug on Postgres (not a test
  assertion) is declared and ruled on before A0.2.
- **A0.2 Fix the known failures** at the test level: the two jsonb key-order asserts become order-agnostic
  (`toEqual`); the transaction-abort cases are read to the cause (a statement that fails inside the test
  transaction that SQLite tolerates) and fixed where the cause lies, in the spec or, if it is application code,
  after your word.
- **A0.3 The leg:** a second Pest configuration for Postgres (`phpunit.pgsql.xml`, same env pins as
  `phpunit.xml` except `DB_CONNECTION=pgsql` and the CI service's host, database and credentials), a composer
  script `test:pg`, and a second step in the `backend` job after the SQLite run, using the existing service
  container. Per P2, whole suite or `--group=pg`. The group, if used, is:
  `CampaignAssignmentContractTest`, `AssignmentOfferEditTest`, `OfferEditNotificationsTest`,
  `CampaignRulesSchemaTest`, every spec under `tests/Feature/Modules/Erasure/`, `AgencyCreatorRosterTest`
  (the FTS test **un-skipped**, live on pgsql), and the A0.4 test.
- **A0.4 Two-connection lock test** (closes the escalated item at `tech-debt.md:3632`): a second connection
  holds `lockForUpdate` on an assignment row while the edit runs; the edit must wait, then see the moved state
  and refuse (`assignment.offer_locked`). The same shape for the contract attach racing an edit.
  Pg-only (`markTestSkipped` on SQLite is acceptable **only** because the leg now runs it).
- **A0.5 Break-reverts:** remove `lockForUpdate` from `AssignmentOfferEditor` and from the attach: the
  A0.4 test fails on the pg leg and **passes on SQLite** (proving the leg is what bites); un-skip the FTS
  test and break the `to_tsquery` prefix form: it fails. Each mutation confirmed applied (Python string replace,
  not `sed -i`), then reverted and `git diff` clean.
- **Docs:** `tech-debt.md` rows at `:3339`, `:3475`, `:3632` updated with what closed and what did not;
  `docs/07-TESTING.md` (or the testing section of `PROJECT-WORKFLOW.md`) gets the Postgres leg and the `test:pg`
  command; the local-run note for the Postgres leg goes in the runbook of local dev.
- **Evidence for A0:** the CI run of the pushed tip with the new step green, cited. A0 is pushed **alone**
  first, on your word, so the leg is proven on its own before feature code depends on it. **PROD-DATA RISK: NONE**
  (CI and tests only).

### A1 API schema (additive, schema only)

- `owner_user_id` on `agency_creator_relations`: nullable, FK to `users`, `nullOnDelete`, index on
  `(agency_id, owner_user_id)`.
- `color` on `brands`: nullable string (a palette key), no default, no index.
- Erasure registry entries and the user-FK declaration for `owner_user_id` (`RegistryVerifier` fails CI
  otherwise); `brands.color` registered if `brands` is column-level (verify when writing).
- `down()` is the honest inverse (drop the columns; the comment names what is lost: reassigned owners and chosen
  colours).
- Specs: `MessagesV2SchemaTest` (columns, nullability, FK behaviour), `ErasureRegistrySchemaTest` (extended).
- **PROD-DATA RISK: NONE** (nullable columns on 857 relations and the brands table; no data written).

### A2 Inbox endpoint and facets

- `GET /api/v1/agencies/{agency}/message-inbox`, middleware `auth:web`, `tenancy.agency`, `tenancy`
  (standard stack). Behind `messages_v2_inbox_enabled` (class `MessagesV2InboxEnabled` in
  `Modules/Messaging/Features/`, default off); off answers 404 `not_found`.
- `MessageInboxQuery` (service): per source `where agency_id`, `has human message` (Q2: a thread surfaces only
  with a non-system, non-deleted message), set-based unread per viewer (one grouped `NOT EXISTS` per table;
  system lines excluded per P1), union ordered by `last_message_at desc, id desc`, cursor on
  `(last_message_at, type, id)`, `per_page` capped (default 30, max 100).
- Row, per the inventory §2. `preview` is the last **human** message (system never); a deleted last message
  shows as a placeholder flag (B2 wires it; A2 returns `preview_kind: text|attachment|deleted` with `deleted`
  never produced until Stage B).
- Filters: `brand[]` and `campaign[]` (ulids, each validated to belong to the agency: a foreign ulid is ignored
  and 404 is **not** leaked), `unread=1`, `owner=me|all` (Mine = effective owner is the viewer, A3), `q` is
  reserved for B3 and ignored in A.
- Direct rows are hidden when `brand[]` or `campaign[]` is present.
- `meta`: `unread_threads_total` (the pill, P6), `facets.brands[]` and `facets.campaigns[]` each `{id, name,
color?, unread_threads}` (counts ignore their own facet's selection and follow `owner`, `unread` is not
  applied to the counts), `next_cursor`.
- Specs: `UnifiedInboxApiTest` (union order, one row per thread, tags, combined unread, `View campaign` data only
  on assignment rows, flag off 404, pagination across both sources with equal timestamps), `UnifiedInboxFacetsTest`
  (own-facet exclusion, unread-thread counts versus badge message counts, Q1), `UnifiedInboxSystemLinesTest`
  (Q2: a thread with only system lines does not surface; system lines add nothing to either count),
  `UnifiedInboxTenancyTest` (§5.34 negatives: another agency's threads, brand, campaign and creator ulids;
  a non-member 404; a staff member allowed to read).
- Break-reverts: drop `where agency_id` on each source; count system lines; drop the facet self-exclusion;
  show Direct rows under a brand filter; flag check removed; cursor tie-break removed.
- Query plan read on a seeded local Postgres with 5,000 threads: no per-row query (query count asserted flat in
  a Pest spec).
- **PROD-DATA RISK: NONE** (read-only).

### A3 Thread owner

- Defaults at the five creation sites (inventory A3): the inviter (`BulkInviteService.php:76`), the requesting
  user (`AgencyConnectionRequestController.php:126`), the accepting user
  (`CreatorConnectionRequestController.php:155`), none for `CreatorAgencyDirectConnectService.php:155` and
  `AdminCreatorConnectionController.php:352`; `SignUpService.php:245` unchanged (it keeps `invited_by_user_id`).
- **Effective owner** = `coalesce(owner_user_id, invited_by_user_id)` in the inbox query and a model accessor;
  **no backfill, no command**.
- `PATCH /api/v1/agencies/{agency}/creators/{creator}/owner` `{owner_user_id}`: **admin and manager only**
  (Q6, a policy ability `reassignOwner`), the new owner an **active member** of the agency (else 422
  `owner.not_a_member`), the relation in the agency (else 404), idempotent when unchanged (200, no audit row), flag
  `messages_v2_inbox_enabled`. Audit action `agency_creator_relation.owner_changed` with ids only
  (`from_user`, `to_user`; the keys are added to `AuditPayloadKeys`). No notification (Q6).
- Specs: `RelationshipOwnerDefaultsTest` (each site, including the two null cases), `RelationshipOwnerReassignTest`
  (admin and manager allowed; staff and creator 403; non-member target 422; foreign relation 404; audit row
  keys; unchanged is a no-op), `InboxMineFilterTest` (Mine, All, null owner under All only, assignment rows
  inheriting the creator's owner).
- Break-reverts: the role gate; the active-member check; the tenancy check on the relation; the audit call; the
  coalesce; the default at each site.
- **PROD-DATA RISK: NONE** (one row on a user's click; no existing row touched).

### A4 Brand colour

- `App\Modules\Brands\Enums\BrandColor` (palette keys, §7). `CreateBrandRequest` accepts `color` as an optional
  enum value; absent, the server assigns the next palette key by a stable rule (the agency's brand count modulo
  the palette). `UpdateBrandRequest` accepts it; `BrandResource` returns `color` (the stored key or `null`);
  the campaign resource's embedded brand carries it too. An unknown key is 422.
- Existing brands: `color` stays null; the web derives a deterministic key from the brand ulid (no write).
- Specs: `BrandColorTest` (create default, create explicit, edit, invalid key, resource shape, campaign embed,
  audit still `brand.updated` with no new payload key).
- Break-reverts: the enum validation; the default assignment; the resource key.
- **PROD-DATA RISK: NONE.**

## 3. Agency web (Stage A, `apps/main`, agency tree only)

Rule: no creator, onboarding or auth file imports `modules/agency/` (spec 2); no frozen shared component is
edited in this section (spec 5 stays green unedited); every new selector starts with the agency theme classes
(spec 1); agency source reads creators through `creatorLabel` (single-reader spec).

- **W1 Client:** `packages/api-client/src/types/messaging.ts` gains `MessageInboxRow`, `MessageInboxFacets`,
  `MessageInboxResponse`; `modules/messaging/api/messageInbox.api.ts`; the flag exposed on `/me`
  (`features.messages_v2_inbox`, `UserResource.php:63`) and read from the auth store.
- **W2 Composable:** `useMessageInbox(filters)` (45 s poll kept; cursor "load more"; filters in the route query
  so a reload and a back navigation keep them; the brand and campaign selections cleared when the agency
  changes).
- **W3 Inbox fork** (`AgencyRelationshipInbox.vue`): rows per the Figma frames (avatar, unread dot, time,
  preview, tag, badge, chevron); tag **Direct** (neutral) or **Brand · Campaign** with the brand's colour dot;
  the pill; search (client-side over the loaded rows until B3); brand and campaign multi-select popovers
  (search field, colour dot, per-item unread-thread count, "N selected", Clear); **All / Unread**; **Mine / All**
  (a segmented control beside it: the frames do not draw it, so it is a design call I flag for your eyes at
  Checkpoint A); "Clear filters". Flag off: today's inbox, byte for byte.
- **W4 Assignment thread:** child route `messages.assignment-thread` at `/messages/c/:campaignUlid/:assignmentUlid`
  (a route-table change; the agency route-guard and MFA architecture specs extended); the page
  `AgencyAssignmentThreadPage.vue`; **the thread-view fork made transport-agnostic** (Q10): it takes a transport
  (relationship or assignment), renders system lines (the existing `system.*` keys), the closed-thread state
  (`human_send_blocked`), and shows **View campaign** and the campaign tag only on assignment threads. The
  header ⋯ menu carries **Reassign owner** (admin and manager only) with a dialog listing active members.
  `Online` is not drawn.
- **W5 Brand colour UI:** `BrandForm.vue` palette picker (a radiogroup of swatches with names); the colour dot on
  tags and popovers; the campaigns table avatar (`CampaignListPage.vue` column, `AgencyBrandMark.vue`).
- **Specs (Vitest):** `AgencyMessagesPage.spec.ts` (rewritten: unified rows, tags, pill, filters, Mine/All, flag
  off), `AgencyRelationshipInbox.spec.ts` (new), `useMessageInbox.spec.ts` (new), `InboxFilterPopover.spec.ts`
  (new), `AgencyAssignmentThreadPage.spec.ts` (new), `AgencyRelationshipThreadPage.agency.spec.ts` (extended:
  the ⋯ menu, owner dialog), `BrandForm.spec.ts`, `BrandListPage.spec.ts`, `CampaignListPage.spec.ts`;
  architecture: `agency-routes-agency-user-guard.spec.ts`, `agency-routes-mfa-guard.spec.ts`,
  `agency-theme-scoping.spec.ts`, `creator-label-single-reader.spec.ts`, `agency-color-system-parity.spec.ts`
  (the palette tokens), and the new `i18n-messages-v2-keys.spec.ts` (every key used in the new sources exists
  in 24 locales with the same placeholders, no English value in the flaky ten).
- **Proof:** the creator baseline walk must print `COMPARE OK: 142 targets identical to baseline.json` (nothing
  creator-reachable changed in W1 to W5); the 24-route agency walk (`walk-l4.mjs`) re-run; pins for the new
  computed styles; a screenshot set into a fresh folder.
- **PROD-DATA RISK: NONE.**

## 4. Shared surfaces with native polarity (A6; creator-reachable, build 4)

"Native polarity" here means: every change to a shared message component is proven in **both** the web and the
Capacitor-mode specs, and a pin proves the creator DOM is **unchanged** for a message without a URL and without
an image. The hash re-pins of `shared-components-frozen.spec.ts` are made **on purpose, in the same commit as the
edit**, each with a one-line reason (AH-118 C4 is the precedent).

- **S1 Linkify (A5):** a pure `core/text/linkify.ts` (`linkify(text): Segment[]`: `text` and `url` segments;
  http and https only; trailing punctuation and closing brackets trimmed; a bare `www.` form accepted and
  emitted as `https://`; no `mailto:`, `javascript:`, `data:`, `file:`; never HTML) and a small
  `MessageText.vue` that renders segments as text nodes and `<a href rel="noopener noreferrer"
target="_blank">`. Wired into `ChatPanel.vue:316`, `RelationshipThreadView.vue:421` and the agency fork
  `:522`. Native: external links in the Capacitor shell open through the existing external-link path the
  consent line uses (AH-104d W3 left that path **not verified on a device**: a build 4 walk item).
- **S2 Images (A6):** `useComposerImages(composerEl, onFiles)` (paste via `clipboardData.files`, drag-drop with a
  drop-target state; only `image/*` MIME types in `AttachmentMimeTypes::IMAGES` are accepted by it, other
  files still go through the paperclip; **existing limits** (10 per message, 25 MB, the init and complete
  flow) are enforced by the existing code and surfaced with the existing error strings); the bubble renders a
  `kind=file` `image/*` attachment as `<img loading="lazy" alt="{name}">` from `view_url` (a failed load falls
  back to the existing file card). Wired into the three composers. Native: a paste of an image into a
  textarea in WKWebView is **not assumed to work**: the paste path is feature-detected and the paperclip stays
  the native route; inline rendering applies on both.
- **Specs:** `linkify.spec.ts` (new: `javascript:` and `data:` rejected, `<script>` stays text, trailing
  punctuation, parentheses, long URL, unicode host, adjacent URLs, empty), `MessageText.spec.ts` (new:
  `rel` and `target`, no `v-html`, source-inspection that the file contains no `v-html`),
  `useComposerImages.spec.ts` (new), `ChatPanel.spec.ts`, `ChatPanel.native.spec.ts`,
  `RelationshipThreadView.spec.ts`, `RelationshipThreadView.native.spec.ts`, `reportProblem.spec.ts`,
  `shared-components-frozen.spec.ts` (re-pin).
- **Break-reverts:** allow `javascript:`; drop `rel`; interpolate HTML; accept a file that is not an image on
  paste; skip the size check; the polarity pin (render a URL-free message through the new component and compare
  to the old DOM).
- **PROD-DATA RISK: NONE.**

### Checkpoint A (stop)

Pedram eyes-on in the dev stack with a seeded agency (several brands, campaigns, Direct and assignment threads,
two owners): the list, the popovers, the Mine/All control, the assignment thread, the owner dialog, a message
with a URL, an image paste. The evidence package: per-step gate board, the break-revert record verbatim, the
creator walk `COMPARE OK 142` after the S1 and S2 re-pins (the re-pins are the intended changes), the agency walk,
a simulator build of the bundle (not a device walk), Playwright (§10). The review file
`messages-v2-review.md` opens here. Stage B starts only on your word.

## 5. Stage B

### B1 API schema (additive; indexes)

Two migrations, schema only:

1. `edited_at` (timestamptz, nullable), `original_body` (text, nullable), `deleted_by_user_id` (nullable FK to
   `users`, `restrictOnDelete` to match the sender FKs), `deleted_body` (text, nullable) on **both** `messages` and
   `relationship_messages`. `deleted_at` already exists and is the deleted marker (no new marker column).
2. The B3 indexes (pg-guarded like the creators migration): `idx_messages_body_fts` and
   `idx_relationship_messages_body_fts`, `USING gin (to_tsvector('simple', coalesce(body, '')))`. Lock and
   cost as in the inventory (about 7,149 and 3,798 rows at the 2026-10-20 read, a write-blocking lock for
   milliseconds to seconds; **the read-back list in §11 re-reads the size before the deploy**).
   `CREATE INDEX` in the transaction, not `CONCURRENTLY`, unless the §11 owner read shows a reason.

`down()` is honest: it drops the columns and indexes, and the comment says dropping `original_body` and
`deleted_body` loses the withdrawn text, so a snapshot comes first. Specs: `MessageEditSchemaTest`
(columns, nullability, FK, the indexes on pgsql; skipped on SQLite and run on the A0 leg).
**PROD-DATA RISK: NONE** (nullable columns without a default: metadata only; an index build).

### B2 Edit and delete

- `MessageRevisionService` (one place for both surfaces, two thin adapters for `Message` and
  `RelationshipMessage`): inside a transaction, `lockForUpdate` the message row, then re-check.
  **Edit:** sender only; `kind=text` with a body; not deleted; `now() - created_at <= 15 minutes` (server
  clock, `Carbon::setTestNow` in specs); **thread open** (Q12: `humanSendBlocked()` for assignment threads,
  the `canMessageRelationship` state for relationship threads); the new body is 1 to 5000 characters and
  different from the current one (an identical body is a 200 no-op); the first edit writes `original_body`,
  every edit sets `edited_at`. **Delete:** sender only; not a system message; not already deleted; **allowed in
  a closed thread** (Q12); moves `body` to `deleted_body`, nulls `body`, sets `deleted_at` and
  `deleted_by_user_id`; attachments stay in place (Q4).
- Mechanism for Q3 and for "deleted text cannot leak": `deleted_at` keeps its `SoftDeletes` scope, so unread
  counts, the digest and the search never see a deleted message; **only** the feed (`pageForThread`) and the
  preview opt in with `withTrashed()`, and the resource masks (`body` null, `attachments` empty,
  `is_deleted` true). `last_message_at` is not moved by an edit or a delete.
- Routes (eight), all behind `messages_v2_edit_search_enabled` (404 when off), on the existing stacks:
  `PATCH` and `DELETE` on `…/campaigns/{c}/assignments/{a}/messages/{message}`,
  `…/creators/{creator}/relationship-messages/{message}`, `creators/me/assignments/{a}/messages/{message}`,
  `creators/me/relationship-threads/{agency}/messages/{message}`. The `{message}` is a ulid resolved **within the
  thread** (foreign ulid: 404, never 403); creator surfaces stay structural by `creator_id`; agency surfaces
  keep the existing gates (`message` ability for the campaign thread, `canMessageRelationship` is **not**
  required to delete).
- Resource: `edited_at` and `is_deleted` are added to both message resources (always present, additive);
  `original_body` and `deleted_body` are **never** on any resource or payload.
- Error codes: `message.edit_window_closed`, `message.edit_thread_closed`, `message.not_editable`,
  `message.already_deleted` (422), not-yours and not-found are 404.
- Audit: `message.edited` and `message.deleted`, metadata ids only (`message_ulid`, `thread_ulid`; keys added
  to `AuditPayloadKeys`; a spec asserts no body text in any audit row).
- Specs: `MessageEditApiTest` and `RelationshipMessageEditApiTest`, `MessageDeleteApiTest` and
  `RelationshipMessageDeleteApiTest`, `MessageEditWindowTest` (14:59 allowed, 15:01 refused, an edit does not
  extend the window), `MessageStateMachineTest` (every edge of the inventory §3 table, including the refused
  ones), `MessageRevisionRaceTest` (pg leg: a second connection holds the row; edit against delete),
  `MessageRevisionTenancyTest` (§5.34: another agency, another thread's message, another user's message, a
  creator editing an agency message, a system row), `MessageUnreadAfterDeleteTest` (Q3: the recipient's
  unread falls, the receipts are untouched), `MessageResourceMaskTest` (deleted: no body, no attachments, no
  signed URL minted; edited: `edited_at` only), `MessageRevisionAuditTest`, `MessageStageBFlagTest`.
- Break-reverts: the 15-minute bound; the sender check; the closed-thread check on edit; the closed-thread
  allowance on delete; the thread-scoped lookup; the row lock (the pg race test); `withTrashed` on unread; the
  resource mask (body leaks); the original captured on every edit instead of the first; the flag check.
- Also exercised: no notification, push or mail is emitted by an edit or a delete (a spec with the notification,
  push and mail fakes, the §5.2 split: the event-dispatched leg and the no-side-effect leg are separate tests).
- **PROD-DATA RISK (declared above):** B1 and B2 write one user-owned message row on the sender's click; the
  original text is preserved.

### B3 Search

- `GET /api/v1/agencies/{agency}/message-search?q=&cursor=` behind the Stage B flag, standard agency stack, any
  active member (the same read gate as the threads; no per-brand ACL exists).
- `MessageSearch` service: on pgsql, per table `to_tsvector('simple', coalesce(body,'')) @@ to_tsquery('simple',
<prefix form of the terms>)` joined to the thread table for `agency_id`, **plus the tenancy filter applied on
  the join, never trusted to the index**; ranked with `ts_rank`, newest first on ties; `ts_headline` snippet with
  a length cap; at most 30 results per page; the term count and length capped; minimum 2 characters. On SQLite an
  `ILIKE` fallback with escaped wildcards (the creators precedent). Deleted messages are absent by the
  `SoftDeletes` scope and because `body` is null.
- Result row: the inbox row shape (so the web reuses it) plus `message_ulid`, `snippet`, `sent_at`, and
  `sender_is_erased` (Q5: an erased creator's messages stay findable with the anonymised label from
  `creatorLabel`).
- Specs: `MessageSearchApiTest` (both tables, ranking, prefix, snippet cap, pagination, min length, flag off),
  `MessageSearchTenancyTest` (another agency's bodies never return), `MessageSearchDeletedTest` (a deleted
  message never matches; an edited one matches its current text, not the original), `MessageSearchErasedTest`
  (Q5), `MessageSearchPgTest` (the GIN index is used: `EXPLAIN` shows an index scan on a seeded table; the
  prefix and a non-prefix substring behave as documented), `AdminNeverReadsWithdrawnTextTest` (Q13: a
  source-inspection spec that no file under `Modules/Admin` and no admin resource, query or export names
  `original_body` or `deleted_body`).
- Break-reverts: the tenancy filter on each table; the deleted exclusion; the min length; the snippet cap; the
  flag check.
- **PROD-DATA RISK: NONE** (read-only).

### B4 Erasure, audit catalogue, privacy and runbook

- **Registry (Q5):** `edited_at`, `original_body`, `deleted_by_user_id`, `deleted_body` registered on both
  tables. **Scrub rules:** `original_body` and `deleted_body` on rows whose `sender_user_id` is the subject are
  set to null (a new `ScrubText` disposition or the existing null-scrub shape, whichever the registry's
  `ColumnRule` already supports; decided when the registry is read at B4, no new disposition type if one
  fits); `body` is kept (D-C); `deleted_by_user_id` is a user FK, declared (and, if it is the subject, left
  pointing at the anonymised user, as `sender_user_id` is).
- **Selections and steps:** two `Selection`s per table (`messages.original_body`, `messages.deleted_body`, and the
  relationship pair) with dry-run counts, and the matching `Steps` entries, in the same stage as the attachment
  scrub (4c). `SubjectSet` unchanged.
- **Files (Q4):** the existing per-object purge already finds attachments by sender and non-null
  `attachments`; a spec proves a **deleted** message's attachments are still collected and deleted at erasure.
- **Specs:** `ErasureRegistrySchemaTest`, `ErasureSelectionsCoverageTest` (the pgsql compile test covers the new
  selections), `ErasureDryRunTest`, `ErasureEngineTest` (a subject with edited and deleted messages: the originals
  and deleted text are null afterwards, the bodies and the other party's rows are byte-identical),
  `ErasureFilesTest` (deleted messages' attachments), `AuditPayloadKeysGuardTest`, `AuditActionEnumTest`,
  `Sprint3AuditActionsTest` (pinned lists extended), all of them also on the A0 Postgres leg.
- **Docs:** `account-deletion-pii-map.md` (§3.4 rows), `account-erasure.md` (the Q13 note: the admin never sees
  withdrawn text; what the scrub nulls; the dry-run counts to read), the policy section and the deletion-text
  sheet under `docs/store/` (kept originals, kept deleted text, attachments of deleted messages kept until
  erasure; the file names confirmed when I open `docs/store/`), `tenancy.md` §4 (inbox, search, owner, the eight
  routes), `feature-flags.md` (both flags). **The privacy text is a notice, not legally reviewed; I say so in
  the review.**
- **Runbook caution:** the erasure worker program loads `Steps`; **no deploy or `queue:restart` while an erasure
  is claimed** (`account-erasure.md`; the C4 countdown is open, earliest real due date 2026-11-15).
- **PROD-DATA RISK: NONE** (no erasure runs).

### B5 Web (`apps/main`): actions and search

- `messaging.api.ts` and `relationshipMessaging.api.ts`: `edit(messageId, body)` and `remove(messageId)`;
  `useMessageThread` gains `editMessage` and `deleteMessage` (optimistic with rollback on error, the poll
  merges `edited_at` and `is_deleted`).
- `MessageActions.vue` (shared, small): a ⋯ button on the viewer's **own** messages only; **Edit** is offered
  only while the client clock says the window is open (a hint: the server is authoritative) and the thread is
  open; **Delete** always (Q12). Inline edit replaces the bubble text with a field, Save and Cancel; "edited"
  appears beside the time; a deleted message renders the placeholder "Message deleted" with no attachments;
  the inbox preview shows the same placeholder.
- Agency inbox search: the search field calls `message-search` when the term has 2 or more characters
  (debounced, 300 ms) and shows a "Messages" results group under the threads; a result opens its thread and
  scrolls to nothing special (no deep anchor in this chunk). Stage B flag off: the field stays the client-side
  filter.
- Flag on the SPA through `/me` (`features.messages_v2_edit_search`).
- Specs: `MessageActions.spec.ts` (new), `useMessageThread.spec.ts` (extended), `ChatPanel.spec.ts` and
  `RelationshipThreadView.spec.ts` (and their `.native.spec.ts`) extended, `AgencyMessagesPage.spec.ts`
  (search results), `MessageSearchResults.spec.ts` (new), `i18n-messages-v2-keys.spec.ts` (extended).
- **PROD-DATA RISK: NONE** (UI calling the B2 and B3 endpoints behind the flag).

### B6 Creator shell and build 4 preparation

- The creator-reachable edits are exactly the files of the inventory §4; `shared-components-frozen.spec.ts` is
  re-pinned in the same commits with a one-line reason each.
- `CURRENT_PROJECT_VERSION` 3 to 4 in the iOS project (the one native project-file change; no plugin is added);
  `MARKETING_VERSION` unchanged; a simulator build proves the bundle carries the change and reports
  `CFBundleVersion` 4.
- A build 4 device walk file `docs/reviews/messages-v2-device-walk.md` (Pedram's, on TestFlight): W1 a URL in a
  creator-sent and an agency-sent message (opens in the browser, the app survives), W2 inline image from the
  agency in both threads (the signed URL, scrolling), W3 attach an image from the creator composer (paperclip),
  W4 edit within the window (the agency sees "edited" after the poll), W5 delete (the placeholder on both
  sides, the unread count falls), W6 a message older than 15 minutes (no Edit), W7 a closed thread (Delete yes,
  Edit no), W8 an old build 3 install against a flag-OFF server is unchanged, W9 the icon badge after a delete.
- **PROD-DATA RISK: NONE.**

### Checkpoint B (stop)

The gate board and review file complete: break-revert record verbatim, per-decision evidence, the Pest SQLite and
Postgres boards, full Vitest, vue-tsc, ESLint, Prettier, Pint, PHPStan (no path argument), locale parity,
creator baseline walk, full Playwright, a simulator build. TestFlight build 4 and the device walk are Pedram's.

## 6. Flags

| Flag                              | Gates                                                                            | Default | Armed                               |
| --------------------------------- | -------------------------------------------------------------------------------- | ------- | ----------------------------------- |
| `messages_v2_inbox_enabled`       | the inbox endpoint, the owner reassign endpoint, the unified UI                  | off     | after build 4 (P7)                  |
| `messages_v2_edit_search_enabled` | the eight edit and delete routes, the search endpoint, the actions and search UI | off     | **only after build 4 is live** (Q9) |

Both are Pennant features in `Modules/Messaging/Features/`, toggled from the admin Feature-flags page, registered
in `feature-flags.md`, and exposed on `/me`. The additive response fields (`edited_at`, `is_deleted`, `owner`,
`color`, `meta` blocks) are present with the flags off so an older bundle ignores them.

## 7. Palette (P3)

Eight keys, stored as the key, mapped to colours **only** in the agency theme (a dark and a light value each, so
the dot and the tag text keep contrast on both themes): `violet`, `green`, `amber`, `blue`, `pink`, `teal`,
`red`, `slate`. The default for a new brand is the next key in this order. The frames use violet, green, orange,
blue and pink dots; orange maps to `amber`, and I read the exact hex values from the Figma file when I build W5.

## 8. Specs by name (summary)

**Pest (new):** `MessagesV2SchemaTest`, `UnifiedInboxApiTest`, `UnifiedInboxFacetsTest`,
`UnifiedInboxSystemLinesTest`, `UnifiedInboxTenancyTest`, `RelationshipOwnerDefaultsTest`,
`RelationshipOwnerReassignTest`, `InboxMineFilterTest`, `BrandColorTest`, `MessageEditSchemaTest`,
`MessageEditApiTest`, `RelationshipMessageEditApiTest`, `MessageDeleteApiTest`, `RelationshipMessageDeleteApiTest`,
`MessageEditWindowTest`, `MessageStateMachineTest`, `MessageRevisionRaceTest` (pg), `MessageRevisionTenancyTest`,
`MessageUnreadAfterDeleteTest`, `MessageResourceMaskTest`, `MessageRevisionAuditTest`, `MessageStageBFlagTest`,
`MessageSearchApiTest`, `MessageSearchTenancyTest`, `MessageSearchDeletedTest`, `MessageSearchErasedTest`,
`MessageSearchPgTest` (pg), `AdminNeverReadsWithdrawnTextTest`, the A0 two-connection lock tests (pg).
**Pest (extended):** `MessageApiTest`, `RelationshipMessageApiTest`, `MessageAttachmentTest`,
`RelationshipMessageAttachmentTest`, `MessageDigestTest` (deleted rows excluded), `MessageReadMarksNotificationsTest`,
`MessageModelsTest`, `AgencyCreatorRosterTest` (FTS live on pgsql), `ErasureRegistrySchemaTest`,
`ErasureSelectionsCoverageTest`, `ErasureDryRunTest`, `ErasureEngineTest`, `ErasureFilesTest`,
`AuditPayloadKeysGuardTest`, `AuditActionEnumTest`, `Sprint3AuditActionsTest`.

**Vitest (new):** `linkify.spec.ts`, `MessageText.spec.ts`, `useComposerImages.spec.ts`, `useMessageInbox.spec.ts`,
`InboxFilterPopover.spec.ts`, `AgencyRelationshipInbox.spec.ts`, `AgencyAssignmentThreadPage.spec.ts`,
`MessageActions.spec.ts`, `MessageSearchResults.spec.ts`, `i18n-messages-v2-keys.spec.ts`.
**Vitest (extended):** `AgencyMessagesPage.spec.ts`, `AgencyRelationshipThreadPage.agency.spec.ts`,
`AgencyRelationshipThreadPage.spec.ts`, `ChatPanel.spec.ts`, `ChatPanel.native.spec.ts`,
`RelationshipThreadView.spec.ts`, `RelationshipThreadView.native.spec.ts`, `useMessageThread.spec.ts`,
`reportProblem.spec.ts`, `BrandForm.spec.ts`, `BrandListPage.spec.ts`, `CampaignListPage.spec.ts`,
`shared-components-frozen.spec.ts` (re-pins), `agency-routes-agency-user-guard.spec.ts`,
`agency-routes-mfa-guard.spec.ts`, `agency-theme-scoping.spec.ts`, `creator-label-single-reader.spec.ts`,
`agency-color-system-parity.spec.ts`.

## 9. Playwright

New: `agency-messages-unified.spec.ts` (A: both thread kinds in one list sorted by activity, tags, the pill,
brand and campaign popovers with counts, All/Unread, Mine/All after a reassign, View campaign only on an
assignment row, the flag-off page unchanged), `message-edit-delete.spec.ts` (B: an agency edit seen by the
creator, a creator delete seen by the agency, the 15-minute edge with a controllable clock if the helper
supports it, a closed thread), `message-search.spec.ts` (B3 on the **Postgres** the E2E jobs already run, which
also proves the FTS path in a browser). Seeds need a guarded test helper (`CreateMessageInboxFixtureController`
in `TestHelpers`, local and testing only, covered by the existing helper architecture specs). Existing
exposure: `creator-messages-badge.spec.ts` (must stay green), `notification-deep-link.spec.ts` (unchanged
destinations; run). Process: the dev stack down on 8000, 8001 and 5174, `catalyst_e2e` created and migrated by
hand, `TEST_HELPERS_TOKEN` exported, output into `playwright-report/`, dev DB counts read before and after, the
dev stack restarted and health-checked.

## 10. i18n keys for approval (all under `app.messaging` unless noted; `en` wording)

**Stage A, agency** (`app.messaging.inbox.*`):
`tagDirect` "Direct"; `tagCampaign` "{brand} · {campaign}"; `allBrands` "All brands"; `allCampaigns` "All campaigns";
`searchBrands` "Search brands"; `searchCampaigns` "Search campaigns"; `brandsHeading` "Brands"; `campaignsHeading`
"Campaigns"; `selectedCount` "{count} selected" (plural); `clearSelection` "Clear"; `clearFilters` "Clear filters";
`ownerMine` "Mine"; `ownerAll` "All"; `ownerFilterLabel` "Show conversations owned by"; `noBrandMatch` "No brands match";
`noCampaignMatch` "No campaigns match"; `viewCampaign` "View campaign"; `noOwner` "No owner"; `loadMore`
"Load more"; `filteredEmpty` "No conversations match these filters."
(Existing, reused: `inboxSearch`, `inboxUnreadPill`, `inboxFilterAll`, `inboxFilterUnread`, `inboxNoMatch`.)

**Stage A, thread and owner** (`app.messaging.thread.*`):
`moreActions` "More actions" (aria); `reassignOwner` "Reassign owner"; `reassignTitle` "Reassign this conversation";
`reassignHint` "The owner decides who sees this conversation under Mine."; `reassignSelect` "New owner";
`reassignConfirm` "Reassign"; `reassignDone` "Owner changed to {name}."; `reassignError` "We couldn't change the
owner."; `ownerLabel` "Owner: {name}".

**Stage A, brand colour** (`app.brands.fields.color` "Colour"; `app.brands.color.{violet, green, amber, blue,
pink, teal, red, slate}` "Violet", "Green", "Amber", "Blue", "Pink", "Teal", "Red", "Slate").

**Stage A, images** (`app.messaging.attachment.*`): `dropHint` "Drop an image to attach it"; `imageUnavailable`
"Image unavailable"; `pasteUnsupportedType` "Only images can be pasted here. Use the paperclip for other files."

**Stage B** (`app.messaging.actions.*`): `menu` "Message actions"; `edit` "Edit"; `delete` "Delete"; `save` "Save";
`cancel` "Cancel"; `edited` "edited"; `deleted` "Message deleted"; `deleteTitle` "Delete this message?";
`deleteBody` "Both sides will see \"Message deleted\". Files you attached stay stored until the account is
erased."; `deleteConfirm` "Delete"; `editHint` "You can edit a message for 15 minutes after sending it.";
`editFieldLabel` "Edit message".
**Stage B, errors** (`app.messaging.errors.*`): `editWindowClosed` "This message can no longer be edited.";
`editThreadClosed` "This conversation is closed, so messages can't be edited."; `notEditable` "This message can't
be edited."; `alreadyDeleted` "This message was already deleted."; `ownerNotMember` "That person is not a member of
this agency."; `editFailed` "We couldn't save the change."; `deleteFailed` "We couldn't delete the message.".
**Stage B, search** (`app.messaging.search.*`): `resultsHeading` "Messages"; `noResults` "No messages match";
`error` "We couldn't search messages."; `minChars` "Type at least 2 characters to search messages."; `fromYou`
"You"; `inThreadWith` "in {name}".

Count: Stage A 33 keys plus eight colour names, Stage B 25 keys; 66 `en` strings. At 24 locales that is 1,584
strings; the flaky ten get a real machine-translation baseline at merge time, and the plural key
(`selectedCount`) carries the locale plural shapes. Backend error codes map to the `errors.*` keys through the
existing code-to-key tables (and a spec like `i18n-creator-codes.spec.ts` pins each new code). Parity proves keys
and placeholders only; the English-value check for the flaky ten is a separate `rg` against `en`.

## 11. Deploy entry (opened in `deploy-log.md` as `PENDING` at the docs close; stacks onto the open entries, P4)

Order, as you ruled:

1. **Pre-checks (read, and written in the entry):** no claimed erasure (`erasure_started_at` null for every user;
   the runbook rule); the colleague's read-back list below; `migrate:status` shows the five migrations Pending.
2. **Snapshot** (RDS) and **verify it completed** before anything else; the ID goes in the entry before `migrate`.
3. **`migrate`**: (1) `owner_user_id`, (2) `brands.color`, (3) message columns, (4) the two GIN indexes, plus any
   registry-driven schema change; all additive. Read back `\d+` for the touched tables.
4. **API deploy.** `queue:restart` is **not** required by the code in this chunk unless a queued class changed;
   the erasure worker program loads `Steps` and `ErasureRegistry`, so it restarts **only if no erasure is
   claimed** (otherwise the deploy waits).
5. **Both flags verified OFF** on the admin Feature-flags page (read back, not assumed). A flag-OFF smoke: the
   agency Messages page is unchanged, `message-inbox` and the edit and search routes answer 404, a message
   shows no new control.
6. **Web deploy** (main Netlify from the mirror; record both SHAs, the mirror rewrites them). Web creators get
   the new bundle here; the admin site is untouched.
7. **TestFlight build 4** (`CURRENT_PROJECT_VERSION` 4): archive, entitlements read, upload, install, the device
   walk (§5 B6). Pedram's, results recorded.
8. **Stage A flag ON** (`messages_v2_inbox_enabled`), a reason recorded, after build 4 per the ruling; read one
   agency's inbox, Mine/All, one reassign, in the browser.
9. **Stage B flag ON** (`messages_v2_edit_search_enabled`) **only after build 4 is live** (the admin Creator
   app page's app-version column read for the creators who have the native app; web creators already run the
   new bundle). Read one edit, one delete and one search end to end, then the first real day's `audit_logs` for
   `message.edited`, `message.deleted`.
10. Record the deploy, the snapshot ID, the migration output and the flag reads; anything unexpected.

## 12. The colleague read-back list (from the inventory §9; read-only; results into the deploy entry)

```sql
-- Threads
SELECT 'message_threads' AS t, count(*) FROM message_threads
UNION ALL SELECT 'relationship_threads', count(*) FROM relationship_threads;

-- Threads with at least one human, undeleted message (what the inbox will show)
SELECT 'message_threads_with_human_msg', count(*) FROM message_threads t
  WHERE EXISTS (SELECT 1 FROM messages m WHERE m.thread_id = t.id AND m.kind <> 'system' AND m.deleted_at IS NULL)
UNION ALL
SELECT 'relationship_threads_with_msg', count(*) FROM relationship_threads t
  WHERE EXISTS (SELECT 1 FROM relationship_messages m WHERE m.thread_id = t.id AND m.deleted_at IS NULL);

-- Message volume and system share
SELECT kind, count(*) FROM messages GROUP BY kind ORDER BY 2 DESC;
SELECT count(*) FROM relationship_messages;

-- Brands and agencies
SELECT count(*) AS brands, count(*) FILTER (WHERE deleted_at IS NOT NULL) AS archived FROM brands;
SELECT count(*) FROM agencies;

-- The largest agency (inbox page size, the union)
SELECT agency_id, count(*) FROM message_threads GROUP BY 1 ORDER BY 2 DESC LIMIT 5;
SELECT agency_id, count(*) FROM relationship_threads GROUP BY 1 ORDER BY 2 DESC LIMIT 5;

-- Body length (search snippet cap, index size)
SELECT max(length(body)), percentile_cont(0.99) WITHIN GROUP (ORDER BY length(body)) FROM messages;
SELECT max(length(body)), percentile_cont(0.99) WITHIN GROUP (ORDER BY length(body)) FROM relationship_messages;

-- Who may build an index and alter these tables
SELECT current_user;
SELECT tablename, tableowner FROM pg_tables
  WHERE tablename IN ('messages', 'relationship_messages', 'brands', 'agency_creator_relations');

-- Existing deleted rows (the column is "present but unwritten"; expected 0)
SELECT count(*) FROM messages WHERE deleted_at IS NOT NULL;
SELECT count(*) FROM relationship_messages WHERE deleted_at IS NOT NULL;
```

Plus, not SQL: the response headers of the platform site (`curl -sI https://platform.catalyst-growth.com`)
for any `Content-Security-Policy` (the repo sets none: `apps/main/public/_headers` carries one rule only), and the
host a production `view_url` signs for (the media bucket), so `img-src` is known before S2 ships. The deploy-time
reads (snapshot, `migrate:status`, flags) are in §11, not here.

## 13. Risks and what I will not claim

- **The Postgres leg may expose more than four failures.** A0.1 measures it; the chunk's first deliverable is
  the measurement, and P2 is where you choose the breadth.
- **Shared `unreadQuery` unchanged (P1)** leaves a small, named inconsistency between the inbox and the open
  thread's count; it is logged, not hidden.
- **Native paste of an image** is not assumed (feature-detected; the paperclip is the native route); the
  external-link path in the Capacitor shell was **never device-verified** (AH-104d W3) and is a build 4 walk item.
- **Edit and delete reach older bundles only through the poll** and, until build 4 is live, only behind the Stage B
  flag; a build 3 install renders a deleted message as a blank bubble, which is why that flag waits (Q9).
- **The mirror rewrites SHAs**; the deploy entry records both.
- **Not proven by this plan:** production behaviour, the sizes in §12 (the 2026-10-20 counts are stale), the
  privacy text's legal sufficiency (a notice, not legally reviewed), the index build time on production.
- **No push without Pedram's word.**

Stopping here for plan review.
