# AH-118 — campaign rules: INVENTORY (read-only)

> **Status:** inventory **ACCEPTED by Pedram, 2026-11-05**, with rulings Q1 to Q13 (§12). Next: [`campaign-rules-plan.md`](campaign-rules-plan.md).
> **Mode:** A (full loop). Triggers: schema, API shape, validation, gate, route table, i18n, a lock on the assignment state machine's neighbours, notification type.
> **Baseline:** `origin/main` = `95fdb0ff`, clean tree. Production as reported by Pedram, not re-read: API `066ff29c`, main SPA `1bc398e8`, admin at the 2026-10-29 build. `deploy-log.md` is the authority.

**Two items.** (1) A per-campaign switch "Only creators in the listed regions can apply". (2) The agency can edit an assignment's offer amount until it is locked.

**PROD-DATA RISK: LOW for both.** Item 1: one defaulted boolean, no backfill, every existing campaign reads OFF. Item 2: one nullable jsonb column on `messages`, no backfill; the feature writes money on live assignments and notifies real users, bounded by the lock, a history line and a flag.

Evidence is `path:line` at `95fdb0ff`.

---

## 1. Schema and migrations (additive only)

Current shapes:

- `campaigns.listing_regions` jsonb nullable, uppercase alpha-2 codes (`2026_07_27_100000_add_jobs_board_listing_to_campaigns.php`).
- `campaign_assignments`: `agreed_fee_minor_units`, `agreed_fee_currency`, `contract_id` (set only when a contract is signed), `payment_id` (never set today).
- `messages.system_event_key varchar(64)`; no data column.

| #   | Table                  | Change                                                            | Ruling          |
| --- | ---------------------- | ----------------------------------------------------------------- | --------------- |
| M1  | `campaigns`            | `restrict_to_listing_regions boolean NOT NULL DEFAULT false`      | accepted        |
| M2  | `messages`             | `system_event_data jsonb NULL` (old and new amounts for the line) | Q5: accepted    |
| M3  | `campaign_assignments` | `offer_edited_at timestamp NULL`                                  | Q5: **skipped** |

Registry follow-ups that fail CI if forgotten: the `messages` keep list in `Erasure/Registry/ErasureRegistry.php:233-235` (add `system_event_data`); `AuditPayloadKeys` (new metadata key `previous_agreed_fee_minor_units` in the money group; `restrict_to_listing_regions` in the flag group, because the campaign audit snapshot at `CampaignController.php:449` gains it).

## 2. State machine and the lock rule (item 2)

No status edge is added. The edit is a non-transition write, so it must not use `CampaignAssignmentStateMachine::commit()`, which always dispatches `AssignmentTransitioned` (board automation, board card, availability block, system message, notifications, verification dispatch all listen). Fee is otherwise written only by `reinvite` (`countered → invited`, `:130`), `reofferAfterDecline` (`declined → invited`, `:174`) and the invite service (`CampaignInvitationService.php:89`).

Edges and facts the lock reads: accept (`invited → accepted`), counter, sign (`accepted → contracted`: `CreatorAssignmentContractController::accept` `:94-98` marks the contract Signed and calls `machine->contract()` in one transaction, so `contract_id != null` is equivalent to signed), contract-less advance (`contracted`, `contract_id` null), `holdPayment` and `releasePayment` (both throw `escrowUnavailable`; `payment_id` is never set today, so that leg is dead code that must still be checked).

**Lock rule as ruled (Q1 to Q4).** Editable only when the status is `invited` or `accepted`, and none of these holds: a Contract row for the assignment with status `sent` (reason `contract_pending`), `contract_id` not null, `payment_id` not null. Everything else is locked: `countered` (use re-invite), `declined` (re-offer only), `contracted` and every later state including contract-less ones (Q3), the payment-eligible states `live_verified`, `manually_verified`, `completed_on_approval` (Q2), `payment_held`, `payment_released`, and every terminal state.

Concurrency: the edit takes `lockForUpdate` on the assignment row and re-checks the rule inside the transaction; contract attach (`CampaignAssignmentContractController::attach`, `:103`) takes the same lock (ruled). Creator accept racing an edit is accepted (Q6), with a tech-debt row naming a stale-offer guard as the fix.

Gate: the `invite` execute ability (admin, manager, staff), as `reinvite`.

## 3. Regions today and the country mapping (item 1)

- `listing_regions` is validated in `ValidatesJobsBoardListing` (`size:2`, `^[A-Z]{2}$`, distinct, max 60); `normalizeListingRegions()` uppercases input. The `store()` whitelist at `CampaignController.php:124-128` must gain the new column.
- Form: `CampaignForm.vue:300-313`, multi-select "Regions" (`app.campaigns.fields.listingRegions`), items from the shared `COUNTRY_OPTIONS`.
- Creator side: `creators.country_code char(2)` nullable, written as sent (no server uppercasing). Mapping is code equality, compared case-insensitively. Explicit codes only (Q8). A null country is locked (Q7), with a message that points to the profile.
- Rule: switch on requires a non-empty `listing_regions`, judged on the merged stored-plus-payload state.

## 4. Creator job-board and application endpoints

`creators/me/jobs` (`Creators/Routes/api.php:267-270`). Visibility is one predicate, `JobsBoardVisibility::visibleTo`; region lock does **not** go in it (locked creators still see the job).

- `GET jobs`: `CARD_COLUMNS` (`CreatorJobBoardController.php:57`) does not select `listing_regions`; the flag needs the column and `listing_regions` selected but neither emitted. Per Q10 the card payload gains no flag in this chunk.
- `GET jobs/{campaign}`: gains `is_region_locked`.
- `POST jobs/{campaign}/apply`: 404 (not visible), 409 duplicate, then the region check (422, code `job.region_not_allowed`, same `errors[{status,code,detail}]` envelope), then insert.
- One helper feeds apply and the detail resource.
- Agency invite, bulk invite and accept-application never read `listing_regions`. Existing applications and assignments are untouched (Q13).
- Fan-out (`JobPostedFanOutService::eligibleRelations`, `:195-232`) skips locked creators (Q9).

## 5. Creator and native surfaces (native rebuild and walk required)

`apps/main` is bundled (`capacitor.config.ts`, `webDir: dist`), so everything below ships only in a new TestFlight build (build 4).

`CreatorJobDetailPage.vue` (apply branch `:411-421`); `CreatorAssignmentDetailPage.vue` (fee `:561`); `CreatorAssignmentsPage.vue` (`:73`, `:298`) and its native variant; `ChatPanel.vue` (system lines `:152`); `NotificationsPage` and `NotificationPreferencesPage` (shared); `notification-route-keys.json` and `templates.ts`; push (`PushEligibility`, `PushMessageFactory`, `lang/*/push.php`). `CreatorJobsPage.vue` is **not** changed (Q10). Agency web only: `CampaignForm.vue`, `CampaignDetailPage.vue` (`:919-935`), `BoardCardDrawer.vue` (`:220`), a new edit-offer dialog modelled on `ReinviteDialog.vue`.

Not verified: how an older native build treats an unknown notification type. The chat falls back to `systemFallback` for an unknown system key.

## 6. Notification type and defaults (item 2)

`assignment.offer_edited`, creator-only; a new `AuditAction` of the same value (145 to 146 cases). Defaults: in-app ON, email ON, digest OFF, push eligible and toggleable, default ON (Q11). Mail: `queueMail` (`SendAssignmentNotifications.php:424`) honours the recipient's email preference and locale; the mail leg is gated by the item-2 flag (Q11). Audit metadata: from and to amounts, currency, status at edit; no free text. History line: a system message with `system_event_data`, written directly by the edit service (not via `WriteSystemMessage`, which is transition-driven).

## 7. i18n (24 locales; the flaky ten get a real MT baseline)

Creator `creator.json`, shared and agency `app.json`, `notifications.json`, API `lang/*/campaigns.php` and `lang/*/push.php`, admin `operations.json` (push types). The full key list is in the plan.

## 8. Specs

Listed by name in the plan (§7 there).

## 9. PROD-DATA RISK

See the header. Deploy order: snapshot, migrate, API, flags OFF, web; native build 4 afterwards.

## 10. Flags (Pennant, default OFF)

`campaign_region_restriction_enabled` (item 1) and `assignment_offer_edit_enabled` (item 2, including the mail leg).

## 11. Carried item

`CreatorAppPage.spec.ts` debounce flake (CI run `41929794025`, green on rerun): the spec is `apps/admin/src/modules/creators/pages/CreatorAppPage.spec.ts`, test at line 247; recorded as tech-debt "AH-118 (campaign rules) — open items" row 1. The failed attempt's log was not captured.

## 12. Rulings (2026-11-05)

| Q   | Ruling                                                                               |
| --- | ------------------------------------------------------------------------------------ |
| Q1  | Lock on a sent contract; reason code `contract_pending`.                             |
| Q2  | Lock the payment-eligible states.                                                    |
| Q3  | Contract-less campaigns lock at `contracted`.                                        |
| Q4  | `countered` blocked (re-invite); `declined` re-offer only.                           |
| Q5  | M2 yes; M3 skipped.                                                                  |
| Q6  | Accept the accept-vs-edit race; tech-debt row with the stale-offer guard as the fix. |
| Q7  | Null country is locked; the message points to the profile.                           |
| Q8  | Explicit codes only, no EU/EEA groups.                                               |
| Q9  | The job-posted fan-out skips locked creators.                                        |
| Q10 | Detail page only; the card chip is a logged follow-up.                               |
| Q11 | Push eligible, toggleable, default ON; the mail leg rides the item-2 flag.           |
| Q12 | Amount only; currency stays the campaign's; `fee_per` unchanged.                     |
| Q13 | Turning the switch on never affects existing applications or assignments.            |

Also ruled: two flags, both OFF at deploy; a server-computed `offer_edit_locked` with a reason code; `lockForUpdate` on the edit and on contract attach.
