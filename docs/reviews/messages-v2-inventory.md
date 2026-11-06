# AH-119 Messages v2: read-only inventory (accepted, with rulings)

> **Status:** inventory accepted 2026-11-06. The rulings Q1 to Q13 are in §11 and **bind**; where a ruling
> changes a recommendation in §0 to §10, the item says so (marked **RULED**). The plan is
> [`messages-v2-plan.md`](messages-v2-plan.md).
> **Read at:** `origin/main` HEAD `50598216` (AH-116/117/118 pushed, not deployed; production unchanged: API
> `066ff29c`, main `1bc398e8`, admin 2026-10-29 build). No code was read as a change; nothing was edited.
> **Figma:** file `lYy90qzKk029vjoVCPExs2`, frames `16:7838` "Messages (restyled)" and `16:8144`
> "Messages – filtered by brand". Vocabulary: "Campaign", never "Wave"; "Online" is dropped.
> **Production counts** are from [`account-deletion-production-readback.md`](account-deletion-production-readback.md)
> (2026-10-20) and are stale; anything not read is marked **not read**.
> **PROD-DATA RISK (chunk):** see the per-item lines; the chunk declares NONE except where an item says
> otherwise (A3 reassign writes on user action; B1 and B2 write user message text on user action).

## 0. Premises the inventory overturned

1. **Redefine "Mine" for assignment rows.** An assignment thread has no owner; ownership lives on the
   agency-creator relation, so assignment rows inherit their creator's owner (A3).
2. **The Figma frames do not match the brief.** They show one row per creator with a "Brand · Wave" tag, no
   "Direct" tag and no Mine/All control. "One row per thread" means the same creator can appear several times.
3. **The mock's counts are inconsistent.** The pill reads "4 unread" while the badges are 2+1+1+3, so the pill
   counts threads and the badges count messages; the popover counts match neither unit. **RULED (Q1).**
4. **Message bodies are kept on erasure (D-C).** Only attachments are scrubbed today
   (`ErasureRegistry.php:233-240`, `Steps.php:65-66`). **RULED (Q5)** for the new columns.
5. **`deleted_at` exists but is unwritten, and the `SoftDeletes` scope hides those rows everywhere**
   (`Message.php:53`, `RelationshipMessage.php:51`).
6. **Agency unread counts include system lines** (`sender_user_id` null counts,
   `MessageService.php:213-226`); campaign threads carry lifecycle lines. **RULED (Q2).**
7. **The existing inbox is unpaginated and runs one unread query per thread**
   (`AgencyRelationshipMessageController.php:63-90`, `RelationshipMessageService.php:233`).
8. **Build 3 renders a deleted message as a blank bubble** (`v-if body`, `ChatPanel.vue:315`,
   `RelationshipThreadView.vue:420`). **RULED (Q9).**

## 1. The two messaging systems today

|                | Relationship ("Direct")                                                                                                                                                                 | Assignment chat                                                                                           |
| -------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| Thread table   | `relationship_threads`: `UNIQUE(agency_id, creator_id)`, `last_message_at`                                                                                                              | `message_threads`: `UNIQUE(assignment_id)`, `agency_id`, `last_message_at`                                |
| Message table  | `relationship_messages`: human only, `sender_user_id` NOT NULL, `body` text, `attachments` jsonb, `softDeletes`                                                                         | `messages`: adds nullable sender, `system_event_key`, `system_event_data` (AH-118)                        |
| Read receipts  | `relationship_message_read_receipts`, `(message_id, user_id)` unique                                                                                                                    | `message_read_receipts`, same shape                                                                       |
| Provisioning   | Lazy on first send or upload; a read returns a transient unsaved thread (AH-012 D1)                                                                                                     | Lazy via `forAssignment()`                                                                                |
| Tenancy        | `BelongsToAgency`; creator side bypasses the scope and is structural by `creator_id`                                                                                                    | Same pattern                                                                                              |
| Agency routes  | `agencies/{agency}/relationship-threads` (inbox), `creators/{creator}/relationship-messages[/read\|/attachments/*]` (`Routes/api.php:82-98`)                                            | `campaigns/{campaign}/message-threads` (rollup), `campaigns/{c}/assignments/{a}/messages[...]` (`:34-48`) |
| Creator routes | `creators/me/relationship-threads[/{agency}/messages...]` (`:112-122`)                                                                                                                  | `creators/me/assignments/{assignment}/messages...` (`:51-60`)                                             |
| Send gate      | `canMessageRelationship` (approved, roster, not blacklisted); history stays readable                                                                                                    | Policy `message` (admin, manager, staff); `view` is any member; `humanSendBlocked()` on terminal states   |
| Unread         | Per viewer user: messages not authored by the viewer with no receipt for that user (`RelationshipMessageService.php:276-292`)                                                           | Same, but system messages count today                                                                     |
| Mark read      | Inserts receipts plus `Notification::markMessageNotificationsRead(user, thread)`                                                                                                        | Same, keyed on the assignment                                                                             |
| Notifications  | `message.relationship_received_by_{agency,creator}`: creator sends fan out to `notifiableMembers()` (admins and managers); agency sends go to the creator's user; subject is the thread | `message.received_by_{agency,creator}`: subject is the assignment; the agency leg carries `campaign_ulid` |
| Mail           | Debounced, agency to creator only                                                                                                                                                       | Same                                                                                                      |

Properties that matter:

- **No message text outside the message tables.** Notification `data` holds names and ulids only; push bodies
  are allowlisted names only (`PushMessageFactory.php:194-223`); mail carries no text. An edit or delete has no
  replay problem.
- **Creator side.** The creator inbox lists relationship threads only; assignment chat lives inside the
  assignment detail page. "Creator side unchanged in shape" holds.
- **Attachments.** Presigned flow, 25 MB per file, at most 10 per message, MIME allowlist
  `AttachmentMimeTypes::ALL` (png, jpeg, webp, gif already included). Images are EXIF-stripped in place before
  the row exists. Existing limits cover A6 unchanged.
- **Agency frontend.** The relationship surface is `AgencyMessagesPage.vue` (client-side search over the whole
  list) plus the forks `AgencyRelationshipInbox.vue` and `AgencyRelationshipThreadView.vue`. The assignment chat
  uses `ChatPanel.vue` through `ChatDialog` and `BoardCardDrawer`.
- **Poll.** The open thread re-fetches the latest page every ~15 s. An edit or delete in an older loaded message
  appears only after "load earlier" or a reopen.

## 2. Building the unified list

**Recommendation (accepted): one new server endpoint, `GET agencies/{agency}/message-inbox`.** Merging
client-side is rejected: two unread-count passes, facets need the whole set with no pagination story, Mine/All
and brand need joins, and the 45 s poll would double.

- **Data.** A union of both thread tables, each `where agency_id`. A row is `{id, type:
relationship_thread|assignment_thread, creator{… is_erased, avatar_url}, brand{id,name,color}|null,
campaign{id,name}|null, assignment_id, last_message_at, preview, unread_count, owner{id,name}|null}`.
- **Unread.** Set-based (one grouped `NOT EXISTS` per table), not per thread. **RULED (Q1, Q2):** the pill and
  the popover counts are **threads with unread**; the row badge is **messages**; system lines count in neither,
  and a thread with no human message does not surface.
- **Params and meta.** `brand[]`, `campaign[]`, `unread`, `owner=me|all`, `q`; `meta` carries the pill total and
  the facets (popover counts ignore their own facet's selection).
- **Pagination.** Cursor on `(last_message_at, type, id)`: N from each source, merge, trim; the next page is
  `< cursor` per source.
- **Direct rows under a brand or campaign filter** are hidden.
- **Old endpoints.** `relationship-threads`, the rollup and the creator inbox stay untouched.
- **Frontend.** A new child route `messages.assignment-thread` carries the campaign and assignment ulids (a
  route-table change). **RULED (Q10):** the assignment pane is a transport-agnostic fork of
  `AgencyRelationshipThreadView`, not the frozen `ChatPanel`.

## 3. Per item

Rulings are applied inline.

### A1 Unified inbox

Schema: none. The new `message-inbox` endpoint, agency only; creator unchanged. Stage A flag. `PROD-DATA RISK:
NONE`. `MessageThread` needs `assignment.campaign.brand` eager-loaded. Production thread counts **not read**
(§9).

### A2 Filters

Schema: none; the facet queries use `idx_campaigns_agency_brand`. **RULED (Q1):** pill and popover counts are
threads with unread. Plus Mine/All (A3). `PROD-DATA RISK: NONE`.

### A3 Thread owner

- Schema: `agency_creator_relations.owner_user_id`, nullable FK to `users`, `nullOnDelete`.
- Defaults at the five creation sites: `BulkInviteService.php:76` (the inviter),
  `AgencyConnectionRequestController.php:126` (the requesting user), `CreatorConnectionRequestController.php:155`
  (the accepting user), `CreatorAgencyDirectConnectService.php:155` (none), `AdminCreatorConnectionController.php:352`
  (none); `SignUpService.php:245` keeps `invited_by_user_id`.
- **No backfill:** effective owner = `coalesce(owner_user_id, invited_by_user_id)` read at query time; no
  existing row is written (857 relations as of 2026-10-20).
- Reassign: a new endpoint, e.g. `PATCH agencies/{agency}/creators/{creator}/owner`; the new owner must be an
  active member. **RULED (Q6):** admin and manager only; **the owner gates only the Mine filter** (no routing,
  no notification change).
- Audit: an explicit new action (the allowlist at `AgencyCreatorRelation.php:194` would not emit one).
- `PROD-DATA RISK: NONE` (nullable column; one row written per reassign, on user action).

### A4 Brand colour

Schema: `brands.color`, nullable. **RULED (Q7): a palette key** (enum, not free hex). The default palette key is
assigned at create; existing brands stay null and render a deterministic fallback from the ulid (no backfill).
Touch points: `CreateBrandRequest`, `UpdateBrandRequest`, `BrandResource`, `BrandForm.vue`, the campaigns table
avatar (`CampaignListPage.vue`), `AgencyBrandMark.vue`. Edits already audit as `brand.updated`. `PROD-DATA RISK:
NONE`.

### A5 Auto-links

Schema: none. A pure `linkify(text)` returning text and URL segments, rendered as `<a rel="noopener noreferrer"
target="_blank">`; http and https only; no HTML interpolated. Three bubble renderers: `ChatPanel.vue:316`,
`RelationshipThreadView.vue:421`, `AgencyRelationshipThreadView.vue:522`. The first two are creator-shared and
frozen by hash, so editing them needs build 4. `PROD-DATA RISK: NONE`.

### A6 Images

Schema: none. Paste (`clipboardData.files`) and drag-drop feed the existing attachment flow with the same
limits; an `image/*` file renders its `view_url` inline. The signed URL lasts 15 minutes (the poll re-mints it).
To verify at build: the media host in CSP `img-src` (**not read**). `PROD-DATA RISK: NONE`.

### A7 "Last active" — **DROPPED (Q8)**

Not built. No new disclosure of creator activity to agencies; nothing about it is added to the privacy text.
The sources considered (`users.last_login_at`, `personal_access_tokens.last_used_at`, `sessions.last_activity`)
stay unused for this purpose.

### B1 Edit own message (15 min)

Schema: `edited_at` (timestamptz, nullable) and `original_body` (text, nullable) on both message tables
(a nullable ADD COLUMN in PG 17 is metadata-only). The window runs from `created_at`, enforced server-side;
`original_body` is captured on the first edit only. Sender only; `kind=text` with a body only. **RULED (Q12):**
an edit is **refused in a closed thread** (send gate or terminal assignment). Endpoints: `PATCH` on the four
message route sets. `⚠️ PROD-DATA RISK: LOW`: it rewrites `body` of a user-edited message; the original is kept.

### B2 Delete own message

Schema: `deleted_at` (existing) or an equivalent, plus `deleted_by_user_id` and `deleted_body` (text, nullable).
On delete the `body` moves to `deleted_body` and `body` is nulled (fail closed: no reader can leak it).
**RULED (Q3):** a deleted message drops out of unread. **RULED (Q4):** attachments stay in place and are hidden
by the resource, **kept until erasure**; the privacy text is updated. **RULED (Q12):** delete is **allowed in a
closed thread**. Sender only, system messages never; §5.34 over-reach negatives (another user's message, another
tenant, a system row, already deleted). `⚠️ PROD-DATA RISK: LOW`: the feature moves a user's own message text out
of `body`; it deletes no row.

### B3 Search

Schema: an expression GIN index per table, `to_tsvector('simple', coalesce(body,''))` (no stemming; matches the
creators precedent, `tech-debt.md:763`). Not a generated column (that rewrites the table under an exclusive
lock). Migration cost: a write-blocking lock for roughly milliseconds to seconds at 7,149 + 3,798 rows (counts of
2026-10-20); DDL only. One endpoint, `GET agencies/{agency}/message-search?q=`: joins each thread table for the
`agency_id` filter (the GIN index cannot enforce tenancy), prefix matching, `ts_headline` snippet with a length
cap, capped results. Deleted content cannot match (it is not in `body`). SQLite gets an ILIKE fallback.
**RULED (Q5):** creator message bodies stay searchable after erasure, with the anonymised sender label
(`is_erased`). **RULED (Q11):** the FTS path is exercised by a Postgres CI leg. `PROD-DATA RISK: NONE` (an index).

### Edit/delete state machine

| From               | Event                                                  | To        | Rules                                                |
| ------------------ | ------------------------------------------------------ | --------- | ---------------------------------------------------- |
| `sent`             | edit (sender, within 15 min, not deleted, thread open) | `edited`  | first edit saves `original_body`, stamps `edited_at` |
| `edited`           | edit (window not expired, thread open)                 | `edited`  | `original_body` unchanged                            |
| `sent` or `edited` | delete (sender; allowed in a closed thread)            | `deleted` | body moved to `deleted_body`; terminal               |
| `deleted`          | any                                                    | refused   | no undo, no edit                                     |

Edit versus delete is a race: a row lock or a conditional update. An edit never resets read state. A deleted
message shows a placeholder (a flag plus an i18n string, no stored text). On the wire `edited_at` and
`is_deleted` are additive; **`original_body` and `deleted_body` are never on any resource.**

## 4. Creator-app surfaces (native rebuild, build 4)

The native app bundles the web `dist` (`capacitor.config.ts:16`, `webDir: 'dist'`); any creator-reachable Vue
change ships only with a new build. No `ios/` or `android/` project file should change. **RULED (Q9):**
A5, A6, B1 and B2 reach the creator app.

Files that would be edited and reach the creator app:

- `apps/main/src/modules/messaging/components/ChatPanel.vue` (frozen)
- `apps/main/src/modules/messaging/components/RelationshipThreadView.vue` (frozen)
- `apps/main/src/modules/messaging/api/messaging.api.ts` and `relationshipMessaging.api.ts` (edit and delete)
- `apps/main/src/modules/messaging/composables/useMessageThread.ts` (edit and delete state)
- `packages/api-client/src/types/messaging.ts` (`edited_at`, `is_deleted`)
- new shared files they import: `linkify`, an image-composer helper, a message-actions menu
- `apps/main/src/core/i18n/locales/*/app.json` (24 files)
- `apps/main/tests/unit/architecture/shared-components-frozen.spec.ts` (hash re-pin)

Mounted but not edited: `CreatorMessagesPage.vue`, `CreatorRelationshipThreadPage.vue`,
`CreatorAssignmentDetailPage.vue`, and (agency) `ChatDialog.vue`, `BoardCardDrawer.vue`. A1 to A4 touch agency
only files and do not need build 4.

## 5. Erasure, audit, notification follow-ups

**Erasure registry** (`RegistryVerifier` fails CI on an unregistered column or user FK):

- `messages` and `relationship_messages` are column-level tables: register `edited_at`, `original_body`,
  `deleted_by_user_id` (declare the user FK), `deleted_body`.
- **RULED (Q5):** `original_body` and `deleted_body` are **scrubbed on subject rows** (set null). This needs a
  scrub step, a `Selection`, a `Steps` entry, dry-run counts, and extends `ErasureSelectionsCoverageTest`,
  `ErasureRegistrySchemaTest`, `SubjectSetTest`. Bodies in `body` stay (D-C); search keeps finding them,
  anonymised.
- `agency_creator_relations.owner_user_id` is a user FK: declare it. `brands.color` is not PII; register it if
  `brands` is column-level (to verify at build).
- **RULED (Q4):** a deleted message's attachments stay until the existing per-object purge
  (`FileKeyCollector.php:77-78`) at erasure.
- The PII map, the deletion notice and the policy text are updated for: kept originals, kept deleted text,
  attachments of deleted messages kept until erasure.
- **RULED (Q13):** the admin console never sees withdrawn text: no admin resource, query or export reads
  `original_body` or `deleted_body`; a runbook note records it ([`account-erasure.md`](../runbooks/account-erasure.md)).

**Audit.** New `AuditAction` cases: message edited, message deleted, relation owner changed. Update
`AuditActionEnumTest` and `Sprint3AuditActionsTest`. New metadata keys go in `AuditPayloadKeys` or
`AuditPayloadKeysGuardTest` fails. Metadata is ids only, never body text. Send stays unaudited (D-17). Verify
at build whether the admin audit page has a label list that needs the new verbs (**not read**).

**Notifications and push.** No notification and no push on edit or delete; owner changes notify no one. A
deleted-unread message leaves its notification row until the thread opens. The iOS icon badge is written at send
time and on foreground, so it may be stale until the next foreground (AH-108). `MessageDigestService` counts
through the scoped model: verify deleted rows are excluded. The agency-leg deep link stays as is (it opens the
campaign Messages tab).

**Tenancy docs.** New rows in `docs/security/tenancy.md` §4: the inbox, search, owner and the edit and delete
routes.

## 6. i18n

`app.messaging.*` has 35 relationship keys and 13 system keys in `en/app.json`. The new key list is in the
plan for approval (about 45 keys; 24 locales; none ships as English fallback in the flaky ten: bg, el, et, fi,
ga, hu, lt, lv, mt, ro). "Wave" never appears; "Online" is dropped.

## 7. Specs and Playwright

Named in the plan (§8, §9). Existing agency Messages E2E: none; creator Messages E2E: `creator-messages-badge.spec.ts`.

## 8. Flags

- Stage A: `messages_v2_inbox_enabled` (the unified endpoint's UI, the owner reassign, the filters; default off).
- Stage B: `messages_v2_edit_search_enabled` (edit, delete, search endpoints answer 404 when off; UI affordances;
  exposed to the SPA through `/me`). **RULED (Q9):** armed only after build 4 is live.

## 9. Production read-backs (colleague, read-only)

`count(*)` of `message_threads` and `relationship_threads`; threads with at least one human message; `brands`
(total, archived); `agencies`; `max(length(body))` and the 99th percentile on both message tables; whether
`engine_c_user` can run `CREATE INDEX`; the media host's CSP `img-src`. The exact statements are in the plan.

## 10. Open questions (as asked)

Q1 to Q13 of the inventory. All answered in §11. A7's source question (Q8) is moot.

## 11. Rulings (Pedram, 2026-11-06)

| #   | Ruling                                                                                                                                                     |
| --- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Q1  | The pill and the popover counts are **threads with unread**; the row badge is **messages**.                                                                |
| Q2  | **System lines are excluded from unread and from surfacing a thread.**                                                                                     |
| Q3  | Deleting an unread message **reduces the recipient's unread**.                                                                                             |
| Q4  | Files of a deleted message are **hidden, kept until erasure, privacy text updated**.                                                                       |
| Q5  | **Scrub `original_body` and `deleted_body` on subject rows at erasure; bodies stay searchable, anonymised.**                                               |
| Q6  | Reassign: **admin and manager**. **The owner gates only Mine.**                                                                                            |
| Q7  | Brand colour is a **palette key**.                                                                                                                         |
| Q8  | **A7 (last active) is dropped.**                                                                                                                           |
| Q9  | **Yes to the creator app (build 4).** The **Stage B flag is armed only after build 4 is live.**                                                            |
| Q10 | The assignment pane is a **fork** (transport-agnostic).                                                                                                    |
| Q11 | **Add a Postgres CI leg (service container)** running the pg-gated specs, including the AH-118 lock tests and the erasure specs. **In scope, first step.** |
| Q12 | **Delete is allowed in closed threads; edit is refused there.**                                                                                            |
| Q13 | **The admin never sees withdrawn text**; a runbook note records it.                                                                                        |

**Effects on the inventory:** A7 is out of the chunk (items, i18n, privacy text, Playwright). Q2 changes how
unread is counted for the agency inbox (the plan decides whether the shared per-thread query changes too, plan
question P1). Q11 adds a CI change ahead of any feature code.

**Known facts for Q11** (all from the repo): the CI `backend` job already starts a `postgres:16-alpine` service
(`.github/workflows/ci.yml:24-35`) but Pest runs on SQLite (`phpunit.xml:53-54`); the AH-118 `lockForUpdate`
tests have run on Postgres in one connection only and four failures predate AH-118 on Postgres
(`CampaignApplication*`, `CampaignAttachment*`, `CampaignCrudTest`, `InviteEmailNotificationTest`;
`campaign-rules-plan.md` §14; tech-debt rows at `tech-debt.md:3339`, `:3475`, `:3632`); the one dormant
`markTestSkipped` Postgres test is in `AgencyCreatorRosterTest.php:572`; the erasure selections are compiled for
the pgsql grammar without connecting (`ErasureSelectionsCoverageTest.php:91`).
