# Verification-Failure Resolution — Review

**Status:** Ready for review.

**Reviewer:** drafted by Cursor (implementation pass); independent spot-check **pending**.

**Reviewed against:** the verification-resolution kickoff (single chunk) + the plan-approval message (the two confirmed divergences: ACT3 reset lives in the creator-self PATCH, not the agency endpoint; two distinct resubmit verbs); `PROJECT-WORKFLOW.md` § 3 (chunk lifecycle) + § 5 standards (5.1 backend/frontend constant parity, source-inspection, the catalogue-tripwire discipline, the build-to-surface precedent, the hand-written-audit free-text discipline, asymmetric-coverage acknowledgement); the Sprint 8 Chunk 1 state-machine contract (`CampaignAssignmentStateMachine` — sole status authority, fail-closed, audited, flag-gated vendor edges); the Sprint 9 Chunk 2 review surface (`CampaignAssignmentReviewController`, `SendAssignmentNotifications`, the `verifyLive` flag-gate + the not_found/mismatch detection path); the Sprint 9 Chunk 1 submission surface (`CampaignPostedContent` + `VerifyPostedContentJob` idempotency); the notification precedent (`DraftReviewedMail` / `PostVerificationFailedMail`, queued + localized).

Sprint 9 **detected** failed auto-verification (`VerifyPostedContentJob` sets `verification_status` to `not_found`/`mismatch`, the assignment stays `posted`, the agency is emailed) but gave the agency **no action and no visibility** to resolve it — a dead-end. This chunk builds the **resolution half**: agency visibility (the failed status on the Creators tab) + three resolution actions, and the creator-side in-place fix that re-arms verification.

The keystone is the **dead-end-preventer**: a new non-terminal status **`manually_verified`** that is distinct for audit (a human override, not a real pass) yet **payment-eligible alongside `live_verified`** via a new `AssignmentStatus::isPaymentEligible()` predicate, so a manually-verified assignment can still be paid in S10 without collapsing the audit distinction.

---

## Scope

### Backend

- **`AssignmentStatus::ManuallyVerified`** (D-1) — a NEW **non-terminal** status (`posted → manually_verified`). 17 chars, fits the `campaign_assignments.status` `varchar(32)` — **no migration** (a sub-status on `campaign_posted_content.verification_status` `varchar(16)` would have overflowed; a top-level status member is the right home anyway — it's an assignment-lifecycle state, not a post attribute). The enum-add was walked across **every** consumer (the catalogue-tripwire discipline): `isTerminal()` (stays non-terminal), the new `isPaymentEligible()`, the FE union, the i18n `assignmentStatus` blocks (en/it/pt), and `CampaignEnumsTest` (16-case pin + payment-eligible pin + varchar-fit).
- **`AssignmentStatus::isPaymentEligible()`** (D-3, the dead-end-preventer) — returns true for **both** `live_verified` and `manually_verified`. Proven **now** by a tripwire test even though no payment is built this chunk; the S10 release-gate MUST consume this predicate, not the literal `live_verified` string (tech-debt logged). `manuallyVerify()` stamps `verified_live_at` (the verification-complete timestamp — the override distinction lives in the status + the distinct verb, not a separate column).
- **`manuallyVerify()` machine edge** (ACT1/D-4) — `posted → manually_verified`, fail-closed (only `posted` is a legal source), **reason-mandatory** (carried in the dedicated audit `reason` field, the `cancel`/`rejectDraft` precedent), firing the net-new verb **`assignment.manually_verified`** (`AuditAction`, marked `requiresReason()`), **explicitly distinct** from `assignment.live_verified`.
- **`returnForResubmit()` machine edge** (ACT2/D-5) — `posted → approved` (the creator re-posts via the existing `approved → submit-post-URL` surface verbatim; the failed `campaign_posted_content` row is **kept as history**). Fires the net-new verb **`assignment.resubmit_requested`** (a card moving back to "approved").
- **Three agency resolution endpoints** (`CampaignAssignmentResolutionController`, the `review` ability) — `manually-verify`, `request-resubmit-fresh`, `request-resubmit-in-place`. All three **fail-close on the resolvable precondition**: `posted` AND the latest posted-content verification failed (`not_found`/`mismatch`) → else 422 `assignment.not_resolvable`. ACT3 takes **no machine edge** (it's a nudge): it hand-writes its own **`assignment.resubmit_requested_in_place`** audit row and notifies the creator — the in-place verb is the _agency-request_ audit; the creator's actual URL edit audits separately as its own mutation.
- **Creator-self in-place PATCH** (`PATCH creators/me/assignments/{a}/posted-content`, ACT3 mechanism/D-6) — the creator edits the failed post URL in place; this resets `verification_status → pending` (clearing `verified_at`/`platform_post_id`) and **re-dispatches the idempotent `VerifyPostedContentJob`** — the reset-to-`pending` is exactly what re-arms it. **No state transition** (stays `posted`). Fail-closed: `posted` + failed only. The agency's in-place request is a **nudge, not a precondition** — the creator may fix whenever in that state. Audits the distinct verb **`assignment.posted_content_updated`** (the free-text URL is NOT snapshotted).
- **Visibility** (D-7) — `CampaignAssignment` gains `postedContent()` (hasMany) + `latestPostedContent()` (hasOne `latestOfMany`); the Creators-tab index eager-loads the latter and `CampaignAssignmentResource` exposes `verification_status` (null when absent) — the field that drives the FE row action.
- **Notifications** (D-8) — manual-verify acceptance rides the transition listener (`SendAssignmentNotifications` → `PostManuallyVerifiedMail`, the override reason NOT surfaced to the creator). The two resubmit notifications (`ResubmitRequestedMail`, mode `fresh`/`in_place`) are sent **directly** from the resolution endpoint so the free-text feedback reaches the creator **without** riding the audit snapshot (the hand-written-audit discipline). All queued + localized + Blade, the `DraftReviewedMail` pattern.
- **Audit catalogue** — four net-new verbs added to `AuditAction` **and** `AuditActionEnumTest` (the tripwire): `assignment.manually_verified` (also in the `requiresReason` set), `assignment.resubmit_requested`, `assignment.resubmit_requested_in_place`, `assignment.posted_content_updated`.

### Frontend

- **`ResolveVerificationDrawer.vue`** (D-7) — a wide `v-dialog` (the `ReviewDraftDrawer` pattern) opened from a `posted`+failed row. Loads the agency detail, renders the failed post + its reason (labelled "simulated", the mock-provider note), and offers the three actions with **canonical 422 binding** on `reason` (the override). Closes + emits `resolved` on success; surfaces an unexpected 5xx inline rather than silently closing.
- **The Resolve row action** — `CampaignDetailPage.vue` shows a "Resolve" button **only** when `canReview && status === 'posted' && verification_status ∈ {not_found, mismatch}`.
- **Creator in-place fix form** — `CreatorAssignmentDetailPage.vue` renders an in-place URL-edit form (prefilled with the failed URL) when `posted` + failed, replacing the "awaiting verification" panel; submit calls the PATCH and reloads to reflect the re-armed `pending` state.
- **The `manually_verified` chip** — the new status rendered across the FE union + i18n (en/it/pt).
- **API client + i18n** — `packages/api-client` gains `manually_verified`, `verification_status` on `CampaignAssignmentResource`, the three resolution payload types + `ResolutionActionResponse`, and `UpdatePostedContentPayload`; `campaigns.api.ts` gains `manuallyVerify` / `requestResubmitFresh` / `requestResubmitInPlace`; `assignments.api.ts` gains `updatePostedContent`; the `app.campaigns.resolution` + `creator.ui.assignments.detail.resubmitInPlace` i18n blocks land in all three locales at parity.

---

## The `ManuallyVerified` enum ripple — every consumer walked

- **`isTerminal()`** — stays **false** (payment follows; state-machine + enum tests pin it non-terminal).
- **`isPaymentEligible()`** — NEW; `{live_verified, manually_verified}` pinned by `CampaignEnumsTest`.
- **`CampaignEnumsTest`** (the tripwire) — 15→16-case catalogue + the payment-eligible set + the varchar(32) fit.
- **FE union** (`packages/api-client/src/types/campaign.ts`) — `'manually_verified'` added (backend/frontend constant parity, §5.1).
- **i18n** — `assignmentStatus.manually_verified` in en/it/pt.
- **`AuditActionEnumTest`** — the four new verbs in the catalogue; `assignment.manually_verified` also in the `requiresReason` set.

---

## The dead-end-preventer — proven before its consumer exists

`isPaymentEligible()` is the load-bearing decision. A manual override that could never be paid would merely **relocate** the verification dead-end to the payment step. The predicate is built + tripwire-pinned **this chunk** (a test asserts both `live_verified` and `manually_verified` satisfy it and `posted` does not), so the contract is locked before S10's release-gate consumes it. The S10 boundary held: **no payment code** is built; `holdPayment()`'s source guard (still `live_verified`-only and vendor-gated/unreachable) is left untouched, with a tech-debt note that S10 must widen it to the payment-eligible set.

---

## Two distinct resubmit verbs — board legibility

Per the kickoff confirmation, the two resubmit movements are **self-describing** on the board:

- **`assignment.resubmit_requested`** (ACT2) — the card moves back to **approved** (a fresh post is coming).
- **`assignment.resubmit_requested_in_place`** (ACT3) — the card **stays** in posted/failed (a nudge, no movement).

They are different board movements, so they are different verbs (not one verb with a metadata discriminator). The creator's actual in-place edit is a **third, separate** audit (`assignment.posted_content_updated`) — the agency-request and the creator-mutation are distinct facts.

---

## Acceptance criteria

| #   | Criterion                                                                                                       | Status |
| --- | --------------------------------------------------------------------------------------------------------------- | ------ |
| D-1 | `AssignmentStatus::ManuallyVerified` (non-terminal, varchar(32) no-migration) + every consumer walked           | ✅     |
| D-3 | `isPaymentEligible()` true for both `live_verified` + `manually_verified`; tripwire-pinned; S10 boundary held   | ✅     |
| D-4 | `manuallyVerify()` edge — fail-closed source, reason-mandatory, distinct `assignment.manually_verified` verb    | ✅     |
| D-5 | `returnForResubmit()` edge — `posted → approved`, `assignment.resubmit_requested`, failed post kept as history  | ✅     |
| D-6 | ACT3: agency endpoint notifies + audits only (no transition); creator PATCH resets + re-dispatches, fail-closed | ✅     |
| D-6 | Creator can PATCH a posted+failed URL whenever in that state (agency request is a nudge, not a precondition)    | ✅     |
| D-7 | `verification_status` exposed on the index resource; FE Resolve row action gated on posted+failed               | ✅     |
| D-8 | manual-verify → creator via the listener; resubmit → creator sent directly (feedback off the audit snapshot)    | ✅     |
| —   | Four net-new audit verbs in the catalogue + the tripwire; `manually_verified` in `requiresReason`               | ✅     |
| —   | FE drawer + creator form, canonical 422 binding, chip + i18n parity (en/it/pt)                                  | ✅     |
| —   | All existing + new tests green; Pint + Larastan clean; vue-tsc + ESLint clean                                   | ✅     |

---

## Verification results

| Gate                                   | Result                                                                                                                                       |
| -------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| `apps/api` Pest — chunk surface        | **124 / 124** (448 assertions) — enums, audit catalogue, state machine, resolution endpoints, creator PATCH, review (regression), verify job |
| `apps/main` Vitest — changed specs     | **68 / 68** — resolution drawer, detail-page row action, creator in-place form, both api wrappers, review/reinvite regression                |
| `apps/main` `vue-tsc --noEmit`         | 0 errors                                                                                                                                     |
| `apps/main` ESLint (changed files)     | 0 errors                                                                                                                                     |
| Pint (`apps/api`, --dirty)             | clean                                                                                                                                        |
| Larastan level 8 (`apps/api`, changed) | clean                                                                                                                                        |

(The full backend Pest + full FE Vitest suites were not re-run end-to-end this pass — only the chunk surface + the changed/adjacent specs. Flagged per the asymmetric-coverage discipline; the spot-check can run both full suites.)

---

## Files touched

**Backend — modified:**

- `Modules/Campaigns/Enums/AssignmentStatus.php` — `ManuallyVerified` + `isPaymentEligible()` + docblock.
- `Modules/Audit/Enums/AuditAction.php` — the four new verbs + `requiresReason()` for manual-verify.
- `Modules/Campaigns/Services/CampaignAssignmentStateMachine.php` — `manuallyVerify()` + `returnForResubmit()`.
- `Modules/Campaigns/Models/CampaignAssignment.php` — `postedContent()` + `latestPostedContent()` relations.
- `Modules/Campaigns/Http/Resources/CampaignAssignmentResource.php` — `verification_status`.
- `Modules/Campaigns/Http/Controllers/CampaignAssignmentController.php` — eager-load `latestPostedContent`.
- `Modules/Campaigns/Listeners/SendAssignmentNotifications.php` — the manual-verify branch.
- `Modules/Campaigns/Routes/api.php` — the three resolution routes.
- `Modules/Creators/Http/Controllers/CreatorAssignmentDraftController.php` — `updatePostedContent()`.
- `Modules/Creators/Routes/api.php` — the creator PATCH route.
- `lang/{en,it,pt}/campaigns.php` — the manual-verify + resubmit mail keys.

**Backend — new:**

- `Modules/Campaigns/Http/Controllers/CampaignAssignmentResolutionController.php`
- `Modules/Campaigns/Mail/{PostManuallyVerifiedMail,ResubmitRequestedMail}.php` + `resources/views/mail/campaigns/{post-manually-verified,resubmit-requested}.blade.php`
- `tests/Feature/Modules/Campaigns/CampaignAssignmentResolutionTest.php`

**Backend — tests extended:** `CampaignEnumsTest`, `AuditActionEnumTest`, `CampaignAssignmentStateMachineTest`, `CreatorAssignmentDraftTest`.

**Frontend:**

- `packages/api-client/src/types/campaign.ts` — `manually_verified`, `verification_status`, the resolution + update-posted payload/response types.
- `apps/main/src/modules/campaigns/api/campaigns.api.ts` — the three resolution wrappers (+ spec).
- `apps/main/src/modules/creators/assignments.api.ts` — `updatePostedContent` (+ spec).
- `apps/main/src/modules/campaigns/components/ResolveVerificationDrawer.vue` (+ `.spec.ts`) — new.
- `apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue` (+ `.spec.ts`) — Resolve button + drawer wiring.
- `apps/main/src/modules/creators/pages/CreatorAssignmentDetailPage.vue` (+ `.spec.ts`) — in-place fix form.
- `apps/main/src/core/i18n/locales/{en,it,pt}/app.json` + `creator.json` — `manually_verified` + the resolution / resubmit-in-place blocks.
- Existing spec fixtures (`ReinviteDialog.spec.ts`, `ReviewDraftDrawer.spec.ts`, `CampaignDetailPage.spec.ts`) — `verification_status` added (now a required resource field).

**Docs:** `03-DATA-MODEL.md` (the `manually_verified` + resolution note + the state diagram fork), `10-BOARD-AUTOMATION.md` (the four verbs + default automations), `20-PHASE-1-SPEC.md` §6.7/§6.8 (the resolution actions + the payment-eligible gate), `tech-debt.md` (the S10 `isPaymentEligible()` consumption note), `security/tenancy.md` (the creator PATCH allowlist row); this review.

---

## Honest deviations & notes

- **ACT3 reset lives in the creator PATCH, not the agency endpoint (confirmed divergence).** The agency "request resubmit in-place" action only notifies + audits — re-firing the job against the unchanged URL would just re-fail. The reset (`verification_status → pending` + re-dispatch) is tied to the creator's actual URL change. The creator may PATCH a posted+failed URL **whenever** it is in that state; the agency request is a nudge, not a precondition.
- **Two distinct resubmit verbs (confirmed divergence).** `assignment.resubmit_requested` (ACT2, the `posted → approved` edge) vs `assignment.resubmit_requested_in_place` (ACT3, no transition). The in-place verb is the agency-request audit; the creator's PATCH audits separately as `assignment.posted_content_updated`.
- **Resubmit notifications sent directly, not via the transition listener.** Only the manual-verify acceptance rides `SendAssignmentNotifications`. The two resubmit mails carry free-text feedback, which must not ride the machine context (it would land in the audit metadata snapshot), so they are sent directly from the controller — the `PostVerificationFailedMail` direct-send precedent.
- **`verified_live_at` reused for the manual override.** It records when verification completed (manual or not); the override distinction lives in the `manually_verified` status + the distinct `assignment.manually_verified` verb, so no new column / migration.
- **`manually_verified` is a top-level `AssignmentStatus`, not a `verification_status` sub-state.** It's an assignment-lifecycle state (payment follows from it), and at 17 chars it would overflow `verification_status`'s `varchar(16)` while fitting `status`'s `varchar(32)`.
- **Full suites not re-run end-to-end.** Only the 124-test backend chunk surface + the changed/adjacent FE specs were run; flagged honestly for the spot-check.
- **Pre-existing working-tree change.** `DraftReviewStatus.php` + its `widen_campaign_drafts_review_status_columns` migration + the `ReviewDraftDrawer` spec/vue tweaks were already in the tree at kickoff (prior work); the `ReviewDraftDrawer.spec.ts` + `ReinviteDialog.spec.ts` fixture edits here are the minimal `verification_status` additions forced by the now-required resource field.

---

## Tech-debt logged

- **S10 payment-release gate must consume `isPaymentEligible()`, not the literal `live_verified`** — added to `docs/tech-debt.md`. `manually_verified` is payment-eligible today but no payment is built this chunk; the predicate + tripwire are in place so the contract is locked. S10 must gate the release flow on the predicate and widen `holdPayment()`'s source set to `{live_verified, manually_verified}`, else a manual override dead-ends at payment (the exact failure this chunk prevents). Low risk now (no consumer); medium at S10 if missed (a silent dead-end). Resolved by the S10 payments workstream.

---

## Commit shape (proposed — not committed until spot-check)

The two-commit pair:

1. **`feat(campaigns): agency verification-failure resolution + manual-verify (resolution chunk)`** — all backend (the `manually_verified` status + `isPaymentEligible`, the two machine edges, the four audit verbs, the resolution controller + routes + authz, the visibility relations/resource, the creator-self PATCH, the two Mailables + lang, the notification branch) + their tests + the docs (data-model, board, phase-1 spec, tech-debt, tenancy) + this review.
2. **`feat(main): verification-failure resolution surface + creator in-place fix (resolution chunk)`** — the api-client types, the api wrappers, `ResolveVerificationDrawer.vue` + spec, the Resolve row action + drawer wiring, the creator in-place form, the i18n (app + creator), and the fixture additions for the now-required `verification_status` field.

A trailing `docs(reviews)` commit flips this file's status to Closed after the spot-check passes.

---

_Provenance: drafted by Cursor (verification-resolution build pass, 2026-06-05) — the resolution half of the agency verification-failure flow (the `manually_verified` payment-eligible override + the two resubmit movements + the creator in-place fix), against the kickoff's two confirmed divergences. Status: Ready for review — independent spot-check pending; not committed until spot-check._
