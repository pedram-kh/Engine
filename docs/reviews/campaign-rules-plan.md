# AH-118 — campaign rules: PLAN (plan-pause)

> **Status:** **DRAFT for Pedram's plan review, 2026-11-05.** No code written.
> **Mode:** A (full loop, plan-pause). **Inventory:** [`campaign-rules-inventory.md`](campaign-rules-inventory.md), accepted 2026-11-05, rulings Q1 to Q13 in its §12 (restated where a step depends on them).
> **Baseline:** `origin/main` = `1a5ba0e8` (the inventory commit). Production, as reported and not re-read: API `066ff29c`, main SPA `1bc398e8`, admin at the 2026-10-29 build. `deploy-log.md` is the authority.

**PROD-DATA RISK: LOW.** Two additive migrations: `campaigns.restrict_to_listing_regions boolean NOT NULL DEFAULT false` (metadata-only add, no row rewritten, every campaign reads OFF) and `messages.system_event_data jsonb NULL`. No backfill, no one-shot command, no data write in a migration. The offer edit writes `agreed_fee_minor_units` on live assignments and notifies real users; it is bounded by the lock, a history line, an audit row and the flag `assignment_offer_edit_enabled` (OFF at deploy). A bug in the region check can only block applies on a campaign whose switch is ON, and the flag is OFF at deploy.

**Evidence labels.** `READ` = read at `95fdb0ff` and cited in the inventory. `VERIFY AT BUILD` = the step's first action reads or measures it before any code. Anything marked VERIFY is not yet known.

---

## 0. Summary

1. **Build order: API first (A1 to A7), then agency web (B1 to B3), then creator web (C1 to C6), then docs (D1).** Each step ends green on the touched packages.
2. **Two flags, both OFF at deploy:** `campaign_region_restriction_enabled`, `assignment_offer_edit_enabled`. Neither changes behaviour until flipped.
3. **No state-machine edge is added.** The edit is a non-transition write with its own service. The lock predicate is one class, read by the endpoint, the payload and contract attach.
4. **Creator pages change, so native build 4 is required** (§6), with a device walk for Pedram.
5. **Deploy:** snapshot, migrate, API, flags OFF, web; native build 4 after (§9).

---

## 1. Rulings applied

| Ruling                                                          | Applied as                                                                                                                         |
| --------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| **Q1** lock on a sent contract                                  | Lock reason `contract_pending` while a `Contract` row for the assignment has status `sent`.                                        |
| **Q2** lock payment-eligible states                             | `live_verified`, `manually_verified`, `completed_on_approval` are locked (reason `status`).                                        |
| **Q3** contract-less locks at `contracted`                      | Every status past `accepted` is locked, with or without a contract.                                                                |
| **Q4** countered blocked; declined re-offer only                | `countered` and `declined` are locked (reason `status`); the agency uses re-invite and re-offer.                                   |
| **Q5** M2 yes, M3 skipped                                       | `messages.system_event_data` only.                                                                                                 |
| **Q6** accept the race                                          | No guard in this chunk. Tech-debt row 2 names the stale-offer guard as the fix.                                                    |
| **Q7** null country locked                                      | A creator with no `country_code` is locked; the message points to the profile.                                                     |
| **Q8** explicit codes only                                      | Code equality, case-insensitive. No EU or EEA groups.                                                                              |
| **Q9** skip locked creators in fan-out                          | `JobPostedFanOutService` excludes locked creators.                                                                                 |
| **Q10** detail page only                                        | No card flag, no chip. Tech-debt row 3.                                                                                            |
| **Q11** push toggleable, default ON; mail rides the item-2 flag | `PushEligibility::TOGGLEABLE` gains the type, no per-type override. The mail leg is gated by `assignment_offer_edit_enabled` only. |
| **Q12** amount only                                             | Currency stays the assignment's (equal to the campaign's); `fee_per` is not touched.                                               |
| **Q13** existing rows untouched                                 | The lock applies only at apply time; no application or assignment is read or changed when the switch turns ON.                     |
| Also ruled                                                      | Server-computed `offer_edit_locked` plus reason; `lockForUpdate` on the edit and on contract attach.                               |

---

## 2. The lock predicate (one source)

`App\Modules\Campaigns\Services\AssignmentOfferLock` (new). `evaluate(CampaignAssignment): ?OfferLockReason`, null meaning editable. Order of checks:

1. Flag `assignment_offer_edit_enabled` OFF: `feature_disabled`.
2. `payment_id !== null`, or status `payment_held` / `payment_released`: `payment`.
3. `contract_id !== null`: `contract_signed`.
4. A `Contract` row (`subject_type = campaign_assignment`, `subject_id`, status `sent`): `contract_pending`.
5. Status not in `{invited, accepted}`: `status`.

`OfferLockReason` is a string enum with those five values. The agency assignment payload carries `offer_edit_locked: bool` and `offer_edit_lock_reason: string|null`, always present. The SPA never re-derives the rule.

---

## 3. API steps (apps/api), in build order

### A1 — Migrations, models, registries

- `2026_11_05_100000_add_restrict_to_listing_regions_to_campaigns.php`: `boolean('restrict_to_listing_regions')->default(false)->after('listing_regions')`. `@migration-risk low`, honest `down()` (the column's content is gone on rollback, comment says so).
- `2026_11_05_100001_add_system_event_data_to_messages.php`: `jsonb('system_event_data')->nullable()`. Same header and `down()` shape.
- `Campaign`: docblock, `$fillable`, boolean cast, `$attributes` default `false`. `Message`: docblock, `$fillable`, array cast. Factories updated.
- `ErasureRegistry.php:233-235`: `system_event_data` added to the `messages` keep list. `AuditPayloadKeys`: `previous_agreed_fee_minor_units` in the money group; `restrict_to_listing_regions` in the flag group.
- Campaign audit snapshot (`CampaignController::auditableSnapshot`, `:449`) gains `restrict_to_listing_regions`.
- **Specs:** `ErasureRegistrySchemaTest`, `AuditPayloadKeysGuardTest`, a new schema test for both columns (default false, nullable jsonb, `down()` restores structure).

### A2 — Flags

- `CampaignRegionRestrictionEnabled` and `AssignmentOfferEditEnabled` in `Creators/Features/` (the one-registry convention), `default()` returns a Closure returning `false`; both `Feature::define`d in `CreatorsServiceProvider` next to `:259-270`; added to the admin Feature-flags list (`AdminFeatureFlagController`) and to `docs/feature-flags.md`.
- Web exposure: `UserResource` `features` (`Identity/Http/Resources/UserResource.php:62`) gains `campaign_region_restriction_enabled` and `assignment_offer_edit_enabled`, evaluated for agency users only (`false` without evaluating the flag for a creator, the `account_deletion` precedent). A UI gate only; the API enforces each flag itself.
- **Specs:** a flag test per flag (off by default; the `/me` keyset), `UserResource` keyset spec.

### A3 — Item 1: validation, storage, resource

- `ValidatesJobsBoardListing` gains `restrict_to_listing_regions` (`sometimes`, `boolean`) and a merged-state rule: the switch ON with an empty `listing_regions` (payload where it speaks, stored row elsewhere) is a 422 on `listing_regions`.
- **Ruling 1 (supersedes accept-and-ignore):** while `campaign_region_restriction_enabled` is OFF, a write that sets the switch ON is rejected with 422 `campaign.region_restriction_disabled`; a write that sets it OFF (or omits it) is fine. **Recorded deviation:** the resource reports the STORED value, not a flag-masked `false` (the apply and detail paths are what the flag gates, via `isRegionLockedFor`).
- `CampaignController::store` whitelist (`:124-128`) gains the column (`?? false`); `update` goes through `fill`. `CampaignResource` (`:62-66`) emits it.
- **Specs:** `CampaignCrudTest`, `CampaignJobsBoardListingTest`.

### A4 — Item 1: the predicate, apply, detail, fan-out

- `Campaign::isRegionLockedFor(Creator): bool`, the single predicate: false when the flag is OFF, the switch is OFF, or `listing_regions` is empty; otherwise true when the creator's `country_code` is null or its upper-cased value is not in `listing_regions`.
- `CreatorJobBoardController::apply`: after the visibility 404 and the duplicate 409, before the insert, a locked creator gets 422 `job.region_not_allowed` in the existing `errors[{status,code,detail}]` envelope (`detail` in English like its siblings; the SPA maps the code). A null country gets the same code, with `meta.reason = country_missing` so the SPA can point to the profile.
- `CreatorJobBoardController::show`: select `restrict_to_listing_regions` (and `listing_regions`, already in `DETAIL_COLUMNS`); `CreatorJobDetailResource` emits `is_region_locked`. `index` and `CreatorJobCardResource` are **not** changed (Q10); the exact-keyset assertions pin that.
- `JobPostedFanOutService::eligibleRelations` (`:195-232`) excludes locked creators with the same predicate (in SQL: a `whereNot` over the creator's country; VERIFY AT BUILD that the SQL form and the PHP predicate agree on null and case, with a parity test).
- **Break-reverts:** drop the apply check; drop the null-country leg; drop the case-insensitivity; drop the flag leg; drop the fan-out exclusion; drop the switch-ON-but-empty-regions rule.
- **Specs:** `CreatorJobApplyTest`, `CreatorJobDetailTest`, `CreatorJobBoardTest` (keyset unchanged), `JobPostedFanOutTest`, new `CreatorJobApplyRegionTest` (the §5.34 negative set: each case eligible in every respect except one: in region, out of region, null country, lowercase stored country, switch OFF, flag OFF, existing application, existing assignment, agency invite and accept-application unaffected).

### A5 — Item 2: the edit service and endpoint

- `AssignmentOfferEditor::edit(CampaignAssignment, int $newAmount, User $actor)`: in one transaction, `lockForUpdate` the assignment, re-evaluate `AssignmentOfferLock`, refuse if locked, refuse if the amount equals the current one (`assignment.offer_unchanged`), write `agreed_fee_minor_units` only, then audit, then the history line. Notification and mail are dispatched after the commit, never inside it.
- Route: `PATCH agencies/{agency}/campaigns/{campaign}/assignments/{assignment}/offer`, middleware as its siblings, ability `invite`, body `{ agreed_fee_minor_units }` (integer, min 1; the currency is not accepted, Q12). Errors: 422 `assignment.offer_locked` with `meta.reason` (the lock reasons other than `feature_disabled`), 422 `assignment.offer_unchanged` with no notification (ruling 3), 404 `not_found` while the flag is OFF (ruling 4; the `account_deletion_enabled` precedent), 404 on a cross-tenant or cross-campaign assignment. A non-member or a creator also gets 404, never 403: `tenancy.agency` hides the agency before the policy runs (house convention); the `invite` Gate itself is covered by a test that denies it and expects 403.
- Contract attach (`CampaignAssignmentContractController::attach`, `:103`) takes `lockForUpdate` on the assignment before it creates the contract row, so an in-flight edit and an attach serialize.
- Payload: `CampaignAssignmentResource` and `CampaignAssignmentReviewController::show` carry `offer_edit_locked` and `offer_edit_lock_reason`.
- **Break-reverts:** each of the five lock legs (the `payment_id` leg seeded by hand, as `payment_id` is never set today); the in-transaction re-check; `lockForUpdate` on edit and on attach (a concurrency test that interleaves the two); the ability gate; the tenancy check; the flag 404; currency not writable; the notification dispatched inside the transaction.
- **Specs:** new `AssignmentOfferEditTest` (lock matrix over all 17 statuses, contract sent, contract signed, `payment_id`, flag, role matrix, tenancy, no-op), `CampaignAssignmentContractTest` (attach vs edit), `CampaignAssignmentStateMachineTest` (the edit does not dispatch `AssignmentTransitioned`; status untouched), `CreatorAssignmentTest` (the creator payload shows the new amount).

### A6 — Item 2: audit, notification, system line, mail, push

- `AuditAction::AssignmentOfferEdited = 'assignment.offer_edited'` (145 to 146); metadata `{ from, to?, previous_agreed_fee_minor_units, agreed_fee_minor_units, agreed_fee_currency, status }`, no free text. `NotificationType::AssignmentOfferEdited` (same value), creator-only; `PushEligibility::TOGGLEABLE` gains it; the push template key `assignment_offer_edited` and its `PushMessageFactory` mapping; `notification-route-keys.json` entry `{ "bag": ["assignment_ulid"], "push": ["assignment_ulid"] }`.
- History line: the editor writes a system message with key `assignment.offer_edited` and `system_event_data = { previous_minor, new_minor, currency }` through `MessageService::writeSystemMessage` (signature gains an optional data argument; existing callers unchanged). `MessageResource` emits `system_event_data` (always present, null for other messages). `WriteSystemMessage`'s allowlist is not touched (it is transition-driven).
- In-app: `NotificationService::notify` to the creator, `data` = `campaign_name`, `assignment_ulid`, `previous_amount`, `new_amount`, `currency`. Mail: `OfferEditedMail` queued through `queueMail` (so the recipient's email preference and locale apply), only while `assignment_offer_edit_enabled` is ON, which is the same flag that gates the endpoint, so the mail leg needs no second check inside the listener.
- **Break-reverts:** audit omitted; in-app omitted; mail omitted; mail sent with the flag OFF (unreachable through the endpoint, so this one is a direct service test); history line omitted; the line carrying no data.
- **Specs:** `AuditActionEnumTest`, `NotificationTypeEnumTest`, `EmailPreferenceGuardTest`, `NotificationRouteKeysContractTest`, `PushEligibilityTest`, `PushMessageFactoryTest`, `PushDispatcherTest`, `SystemMessageTest`, `MessageApiTest`, new `OfferEditedMailTest` (real rendering per locale: subject and body, the queued-locale assertion, the emitted URL pinned, §5.3), `ErasureRegistrySchemaTest`.

### A7 — API gates

Full Pest serial (`-d memory_limit=2G`), Pint `--all`, PHPStan with no path argument (`--memory-limit`), locale parity for `lang/`. Dev database counts read before and after the run.

---

## 4. Agency web (apps/main, agency tree), after A7

### B1 — api-client and flags

`packages/api-client`: `restrict_to_listing_regions` on the campaign resource and the create and update payloads; `offer_edit_locked` and `offer_edit_lock_reason` on the agency assignment resource; `campaignsApi.editOffer(...)`; `features` on the user type gains the two keys. The `campaigns.api.spec.ts` request-shape cases.

### B2 — The switch (create and edit)

`CampaignForm.vue`, under the Regions select (`:300-313`): a `v-switch` "Only creators in the listed regions can apply", default OFF, with a hint; hidden unless `features.campaign_region_restriction_enabled`. The SPA mirrors the merged-state rule (the switch is disabled while the regions list is empty, the server 422 remains the backstop). `CampaignDetailPage.vue` settings save sends it. `CampaignListPage.vue`'s single-key listing patch is not changed.

### B3 — Edit offer

New `EditOfferDialog.vue` modelled on `ReinviteDialog.vue` (amount field, currency shown read-only, success snackbar, a 422 `assignment.offer_locked` mapped by `meta.reason` to a reason sentence). An "Edit offer" button on the Creators-tab row (`CampaignDetailPage.vue:919-935`) and in the board drawer's Detail tab (`BoardCardDrawer.vue:220`), rendered only when the flag is ON, shown disabled with the reason when `offer_edit_locked`. After a save the row and the drawer refetch.

**Specs:** `CampaignForm.spec.ts`, `CampaignDetailPage.spec.ts`, `BoardCardDrawer` spec, new `EditOfferDialog.spec.ts`, `campaigns.api.spec.ts`. The agency walk script is re-run (`walk-l4.mjs`, 96 stops, 0 problems) and the creator baseline (`walk.mjs --set creator --compare …/baseline.json`) must still print `COMPARE OK: 142 targets identical` after B1 to B3 (agency steps must not move a creator pixel).

---

## 5. Creator web (apps/main, creator tree), after B3

Every creator surface this chunk touches, each with a web-polarity and a native-polarity spec (`isNativePlatform` mocked true, the `.native.spec.ts` convention):

| Step   | Surface                                                                                  | Change                                                                                                                                                                                                                                                                                                                                                                                                                           | Specs                                                                                                                                       |
| ------ | ---------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| **C1** | `packages/api-client`                                                                    | `is_region_locked` on the job detail attributes; `system_event_data` on the message resource; the notification type union and route keys                                                                                                                                                                                                                                                                                         | api-client type specs, `notification-route-keys` contract                                                                                   |
| **C2** | `CreatorJobDetailPage.vue` (apply branch `:411-421`)                                     | In the never-applied branch only: when `is_region_locked`, the Apply button is replaced by a disabled note "Not available in your region" (the null-country variant says to set the country in the profile, with a link to `creator.profile`). The lifecycle, rejected, accepted and pending branches keep winning. A stale click that gets 422 `job.region_not_allowed` shows the toast and flips the page to the locked state. | `CreatorJobDetailPage.spec.ts` (web and native: the bottom sheet is not reachable when locked)                                              |
| **C3** | `CreatorAssignmentDetailPage.vue` (`:561`), `CreatorAssignmentsPage.vue` (`:73`, `:298`) | No code change; the fee comes from the payload. Specs only, proving the edited amount renders and that a payload carrying the new keys changes nothing else.                                                                                                                                                                                                                                                                     | `CreatorAssignmentDetailPage.spec.ts`, `CreatorAssignmentsPage.spec.ts`, `CreatorAssignmentsPage.native.spec.ts`                            |
| **C4** | `ChatPanel.vue` (`systemLine`, `:152`)                                                   | The line `app.messaging.system.assignment.offer_edited` renders `{from}` and `{to}` from `system_event_data`, formatted with the existing money formatter; an absent or malformed data bag falls back to `systemFallback`.                                                                                                                                                                                                       | `ChatPanel.spec.ts`, `ChatPanel.native.spec.ts`                                                                                             |
| **C5** | Notifications: `templates.ts`, `notificationVisual.ts`, `NotificationPreferencesPage`    | The row text, an icon and tone, and a toggle row in the preferences matrix (in-app, email, push).                                                                                                                                                                                                                                                                                                                                | `templates.spec.ts`, `NotificationPreferencesPage.spec.ts`, `NotificationPreferencesPage.push.spec.ts`, `i18n-notifications-parity.spec.ts` |
| **C6** | Deep link                                                                                | A tap on the notification or the push opens `creator.assignment.detail` through the route-keys contract.                                                                                                                                                                                                                                                                                                                         | `notification-route-keys` contract, `notification-deep-link.spec.ts` (Playwright)                                                           |

`CreatorJobsPage.vue` is **not** changed (Q10).

---

## 6. Native rebuild and device walk for Pedram (TestFlight build 4)

Build 4 follows the web deploy (it bundles `dist`). Build number to bump per the AH-108 precedent (`CURRENT_PROJECT_VERSION` 3 to 4; VERIFY AT BUILD that nothing else pins it). The flags are flipped ON in production before the walk; the walk uses two test creators (one with a country in the listed regions, one outside, one with no country) and one test campaign.

| #   | Walk step                                                                                                                              | Surface                        |
| --- | -------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------ |
| N1  | Open the restricted job as the out-of-region creator: the job is listed, the detail shows "Not available in your region", no Apply.    | Job board, detail              |
| N2  | Same job as the in-region creator: Apply works, the bottom sheet opens.                                                                | Detail, sheet                  |
| N3  | The creator with no country: the locked note points to the profile, and the link opens it.                                             | Detail                         |
| N4  | An agency invite to the out-of-region creator still lands and is accepted normally.                                                    | Assignments                    |
| N5  | The agency edits an `invited` offer: the in-app row appears, the push arrives (production APNs), a tap opens the assignment detail.    | Notifications, push, deep link |
| N6  | The chat shows the line "Offer changed from X to Y" on both sides.                                                                     | Chat                           |
| N7  | The assignment detail and the list show the new amount.                                                                                | Assignments                    |
| N8  | The preferences matrix shows the new row; switching push off stops the next push.                                                      | Preferences                    |
| N9  | An edit after a contract is sent is refused on the agency side with the reason (`contract_pending`); after signing, `contract_signed`. | Agency web                     |
| N10 | The email arrives in the creator's language (subject and body read).                                                                   | Mail                           |

Tester note: build 4 is the first build with these keys; an older build (3) against the new API shows the generic error on a 422 apply and an unknown notification type (VERIFY: the unknown-type fallback of build 3 is not proved; read `templates.ts` at build time and say what it does).

---

## 7. Specs: the full by-name list

**Backend, edited:** `CampaignCrudTest`, `CampaignJobsBoardListingTest`, `CreatorJobApplyTest`, `CreatorJobBoardTest`, `CreatorJobDetailTest`, `JobPostedFanOutTest`, `CampaignAssignmentContractTest`, `CampaignAssignmentStateMachineTest`, `CreatorAssignmentTest`, `SystemMessageTest`, `MessageApiTest`, `AuditActionEnumTest`, `NotificationTypeEnumTest`, `EmailPreferenceGuardTest`, `NotificationRouteKeysContractTest`, `PushEligibilityTest`, `PushMessageFactoryTest`, `PushDispatcherTest`, `AuditPayloadKeysGuardTest`, `ErasureRegistrySchemaTest`, the `UserResource` features spec.

**Backend, new:** `CampaignRegionSchemaTest` (both columns), `CreatorJobApplyRegionTest`, `AssignmentOfferLockTest`, `AssignmentOfferEditTest`, `OfferEditedMailTest`, the two flag tests.

**Frontend, edited:** `CampaignForm.spec.ts`, `CampaignDetailPage.spec.ts`, `BoardCardDrawer` spec, `campaigns.api.spec.ts`, `CreatorJobDetailPage.spec.ts`, `CreatorAssignmentDetailPage.spec.ts`, `CreatorAssignmentsPage.spec.ts`, `CreatorAssignmentsPage.native.spec.ts`, `ChatPanel.spec.ts`, `ChatPanel.native.spec.ts`, `templates.spec.ts`, `NotificationPreferencesPage.spec.ts`, `NotificationPreferencesPage.push.spec.ts`, `i18n-notifications-parity.spec.ts`, `i18n-creator-codes.spec.ts` (the new code `job.region_not_allowed` and `assignment.offer_locked` reasons), `listingFloor` spec if the floor list is touched (it is not planned to be).

**Frontend, new:** `EditOfferDialog.spec.ts`, a creator-surface native polarity spec where a surface lacks one (job detail).

**Admin:** `operations.json` x24 and its parity spec, only for the push type name (§8).

**Playwright (full suite before the push; dev stack down, own E2E DB, output into `playwright-report/`):** edited `creator-jobs-board.spec.ts` (locked detail, no Apply), `jobs-board-full-lifecycle.spec.ts` (the lifecycle with an invite to an out-of-region creator), `notification-preferences-matrix.spec.ts` (the new row), `notification-deep-link.spec.ts`, `campaign-applications.spec.ts` (traverses the same detail page); new `assignment-offer-edit.spec.ts` (agency edits, creator sees it, a contract-sent edit is refused). The surfaces are E2E-traversed, so the full run is required.

---

## 8. i18n keys for approval (24 locales each; real MT baseline for the flaky ten: bg, el, et, fi, ga, hu, lt, lv, mt, ro, never English fallback)

**Creator (`creator.json`):**

- `creator.ui.jobs.detail.regionLocked`: "Not available in your region"
- `creator.ui.jobs.detail.regionLockedNoCountry`: "Add your country to your profile to apply to jobs that are limited by region."
- `creator.ui.jobs.detail.regionLockedProfileLink`: "Open my profile"
- `creator.ui.jobs.toast.regionNotAllowed`: "This job is only open to creators in specific regions."

**Shared and agency (`app.json`):**

- `app.messaging.system.assignment.offer_edited`: "The offer changed from {from} to {to}."
- `app.campaigns.fields.restrictToRegions`: "Only creators in the listed regions can apply"
- `app.campaigns.fields.restrictToRegionsHint`: "Creators outside these regions can still see the job but cannot apply. Invitations are not affected."
- `app.campaigns.errors.restrictNeedsRegions`: "Choose at least one region to restrict applications."
- `app.campaigns.offerEdit.action`: "Edit offer"
- `app.campaigns.offerEdit.title`: "Edit the offer amount"
- `app.campaigns.offerEdit.body`: "The creator is notified and the change is recorded in the conversation."
- `app.campaigns.offerEdit.amountLabel`: "New fee ({currency})"
- `app.campaigns.offerEdit.cancel`, `.submit`, `.success`
- `app.campaigns.offerEdit.unchanged`: "That is the current amount."
- `app.campaigns.offerEdit.locked.status`: "The offer can no longer be edited at this stage."
- `app.campaigns.offerEdit.locked.contract_pending`: "A contract is waiting for the creator to sign."
- `app.campaigns.offerEdit.locked.contract_signed`: "The contract is signed."
- `app.campaigns.offerEdit.locked.payment`: "Payment has started."
- `app.campaigns.offerEdit.locked.feature_disabled`: not rendered (the button is hidden); no key.
- `app.campaigns.offerEdit.error`: "The offer could not be changed. Please try again."

**Notifications (`notifications.json`):**

- `notifications.types.assignment_offer_edited`: "The offer for {campaign_name} changed from {previous_amount} to {new_amount}."
- the preferences-matrix label: "The agency changes your offer"

**API `lang/*/campaigns.php` (`OfferEditedMail`):** subject, greeting, body (campaign, previous and new amount), call-to-action, footer.

**API `lang/*/push.php`:** `assignment_offer_edited` title and body.

**Admin `operations.json` (x24):** the push type label, only if the push-deliveries filter lists types by name (VERIFY AT BUILD; the admin build is old, so the admin deploy is optional and is noted in the deploy entry either way).

The English strings above are drafts for your read; the other 23 locales are regenerated after approval, then parity must be green.

---

## 9. Deploy: the shape of the `deploy-log.md` entry

Opened `PENDING` in D1, stacked onto an open entry if one exists. Order:

1. **Pre-deploy snapshot** (verify it completed; record the ID).
2. **`php artisan migrate`**: two additive migrations (`campaigns`, `messages`); neither rewrites a row.
3. **API** deploy, then `queue:restart` (new mailable, lang files and notification type must load). No erasure may be claimed during it (the runbook rule).
4. **Both flags OFF.** Verify `/me` `features` shows both false for an agency user, and that a campaign created with the switch ON reports `restrict_to_listing_regions: false` while the flag is OFF.
5. **Main web build** (agency and creator surfaces). Smoke: the creator job detail still shows Apply; the agency campaign form shows no new switch.
6. **Flag flips (Pedram, in the admin Feature-flags page, with a reason):** `campaign_region_restriction_enabled` first; read one restricted test campaign end to end; then `assignment_offer_edit_enabled`.
7. **Native build 4** after the web deploy, then the §6 walk.
8. Record both SHAs (the mirror rewrites them) and the snapshot ID. One-shot commands: none.

**Admin deploy IS in the entry (ruling 5, decided after reading the admin code):** `PushDeliveriesPage.vue` builds its type filter from the hardcoded `PUSH_NOTIFICATION_TYPES` list plus a label key, so the new push type `assignment.offer_edited` is missing from the filter until the admin SPA is rebuilt. Rows still list; only the filter and label are affected. Admin deploy follows the main web deploy.

**Preconditions (all must be recorded before the entry opens):**

1. **Postgres run of the lock tests:** `CampaignAssignmentContractTest`, `AssignmentOfferEditTest`, `OfferEditNotificationsTest`, `CampaignRulesSchemaTest` against local Postgres (not sqlite, where `lockForUpdate` is a no-op), result written in the review.
2. Full serial Pest, Pint, PHPStan green at the pushed tip, CI cited.
3. The API and the web contract (`notification-route-keys.json` consumers) pushed together.

---

## 10. Risks named

1. **A flag-OFF payload that still leaks the new field.** The `is_region_locked` key is always present on the detail and false while the flag is OFF; pinned by the exact-keyset spec.
2. **Edit racing contract attach.** Mitigated by the shared `lockForUpdate`; proved by an interleaved test. Edit racing creator accept is accepted (Q6, tech-debt row 2).
3. **The SQL and PHP region predicates drifting** (fan-out vs apply). One parity test over the null, lowercase and in/out cases.
4. **Old native build 3 against the new API.** Unknown type and 422 handling not proved (§6).
5. **`/me` `features` keyset change** touches the sign-in payload for every agency user; additive, pinned by spec.
6. **Playwright on this machine:** `TEST_HELPERS_TOKEN` exported, the dev stack down on 8000, 8001 and 5174, `catalyst_e2e` created and migrated by hand first, the dev database counts read before and after (AH-116 traps).

---

## 11. Gates before the push

Full backend Pest serial (`-d memory_limit=2G`), Pint `--all`, PHPStan (no path argument), locale parity (API and SPA); full Vitest on `apps/main` and `packages/api-client`, `vue-tsc`, ESLint, Prettier; the creator baseline walk (`COMPARE OK: 142 targets identical to baseline.json`); the agency walk (96 stops, 0 problems); full Playwright (main and admin); the dev database counts before and after. CI cited at the pushed tip. **No push without Pedram's word.**

---

## 12. Questions for the plan review

1. **Flag OFF on write (A3):** RULED: reject with 422 `campaign.region_restriction_disabled` (not accept-and-ignore).
2. **Null-country reason (A4):** a `meta.reason = country_missing` on the 422 (ruling 2; the other reason on this code is `outside_regions`, a recorded deviation: the apply 422 always carries `meta.reason`), so the creator page can link to the profile. RULED yes.
3. **No-op edit (A5):** an edit to the same amount is a 422 `assignment.offer_unchanged` with no notification. Confirm.
4. **Flag OFF on the edit endpoint:** 404 (as `account_deletion_enabled`) rather than 403. Confirm.
5. **Admin push-type label:** if the admin SPA must show the new push type by name, an admin deploy joins the entry. Decide after the A6 read.

---

## 13. Recorded deviations from this plan (as built, API checkpoint `cc224c09`)

1. **Region switch with the flag OFF:** 422 `campaign.region_restriction_disabled` (ruling 1), and the resource reports the stored value (not masked).
2. **Apply 422 `meta.reason`:** `country_missing` or `outside_regions`.
3. **History-line payload keys:** `system_event_data = { previous_amount, new_amount, currency }` (minor units), not `previous_minor`/`new_minor`.
4. **Audit metadata:** `{ from, previous_agreed_fee_minor_units, agreed_fee_minor_units, agreed_fee_currency }` (no `to`, no `status`). The audit row and the history line are written inside the editor's transaction (A6); the notifier runs after commit.
5. **Notifier has no flag check:** the endpoint 404s while the flag is OFF, so every caller holds it; the mail still honours the recipient's `email` preference.
6. **Notifier is silent for an unavailable creator** (erased, pending deletion, erasure-claimed; `AgencyCreatorSurfaces` row `silent`): the edit, audit and history line still happen, no mail, in-app row or push.
7. **Lock reasons:** `feature_disabled`, `payment`, `contract_signed`, `contract_pending`, `status`, evaluated in that order. The editable window is `invited` and `accepted` only.
8. **API `messages.php` lang lines skipped** (unused).
9. **Authz:** 404 (tenancy) for non-members and creators; `CampaignPolicy::invite` checks the role only, not the campaign's agency, so tenancy is the cross-agency boundary (pre-existing, shared by every ability).
10. **Web contract specs** (`templates.spec.ts`, `notificationDestination.registry.spec.ts`) read `notification-route-keys.json` and are red until C1; API and C1 go out together.

## 14. Postgres run (precondition record)

Run against a throwaway local Postgres 16 database `catalyst_test` (never the dev `catalyst` DB; its counts were identical before and after: users 3, audit_logs 3, migrations 87, the rest 0).

- `CampaignAssignmentContractTest`, `AssignmentOfferEditTest`, `OfferEditNotificationsTest`, `CampaignRulesSchemaTest`: **70 passed** at `cc224c09`.
- Whole `tests/Feature/Modules/Campaigns` + `Messaging` on Postgres: 4 failures remain, **all pre-existing on `origin/main`** (`CampaignApplication*`, `CampaignAttachment*` abort the transaction; `CampaignCrudTest` and `InviteEmailNotificationTest` assert jsonb key order). Logged as tech-debt, not touched.
- **Found by the Postgres run:** two of our own asserts compared `system_event_data` key order, which jsonb does not keep; fixed with `toEqual` (`cc224c09`). The behaviour was right, the assertions were not.
- **Limit, stated plainly:** this proves the SQL (`FOR UPDATE`) runs on Postgres and the re-check logic, in ONE connection. It is not a two-connection concurrency proof; that remains untested.
