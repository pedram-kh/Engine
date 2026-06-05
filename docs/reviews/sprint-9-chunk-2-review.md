# Sprint 9 — Chunk 2 Review

**Status:** Closed.

**Reviewer:** drafted by Cursor (implementation pass); independent spot-check **passed — no PMC** (the `Rejected` ripple complete across every consumer; `rejectDraft` fail-closed + reason-in-the-dedicated-field; the verification flow holds all three safety properties — flag-OFF keeps the break-revert gate green with both arms asserted, the arc auto-flows on the mock but the surface says "simulated," and not_found/mismatch stay `posted` with no machine call; no payment creep, the S10 escrow boundary held; the lean "Social: 1" contract, the listener-dispatched idempotent + flag-gated job, the three Mailables + the tech-debt note, and the Larastan narrowing all sound).

**Reviewed against:** the Chunk 2 kickoff + the plan-approval message (the five confirmed divergences); `PROJECT-WORKFLOW.md` § 3 (chunk lifecycle) + § 5 standards (5.1 backend/frontend constant parity, source-inspection, the build-to-surface precedent, the catalogue-tripwire discipline, the break-revert claim-verification corollary, asymmetric-coverage acknowledgement); the Sprint 8 Chunk 1 state-machine contract (`CampaignAssignmentStateMachine` — sole status authority, fail-closed, audited, flag-gated vendor edges); the Sprint 9 Chunk 1 submission surface (`CampaignDraft` / `CampaignPostedContent` + their resources, the context-thread audit mechanism); the integration-provider precedent (`Kyc`/`Esign`/`Payment` contracts + Mock/Deferred/Skipped stubs + `IntegrationProviderBindingsTest`); the notification precedent (`ConnectionRequestMail`, queued + localized).

This chunk **completes the assignment lifecycle arc**: it builds the agency-side **review** half of the submission→review seam (Chunk 1 shipped the creator-side submission), adds the new **`rejected`** terminal, and wires the **`posted → live_verified`** verification step behind a mock social provider and a feature flag. The result: an assignment can now travel `invited → … → draft_submitted → {approved | revision_requested | rejected}` and `approved → posted → live_verified` end-to-end.

---

## Scope — the lifecycle arc closers

### Backend

- **`AssignmentStatus::Rejected`** (D-1/D-2) — a dedicated terminal, distinct from `Cancelled` (which fires from any non-terminal). The enum-add was walked across **every** consumer: `isTerminal()`, the FE union type, the i18n `assignmentStatus` blocks (en/pt/it), and the **catalogue-tripwire** test (`CampaignEnumsTest`) so a future enum-add can't silently skip the surface.
- **`rejectDraft()` machine edge** (D-3) — `draft_submitted → rejected`, fail-closed (only `draft_submitted` is a legal source), **reason-mandatory** (carried in the dedicated audit `reason` field, the `cancel()` precedent, NOT the metadata snapshot), firing the net-new board verb **`assignment.draft_rejected`** (`AuditAction`, marked `requiresReason()`).
- **`verifyLive()` un-gated** (D-11) — was a hard "no manual path" stub; now flag-gated on **`social_verification_enabled`** exactly like `contract()` on `contract_signing_enabled`. Flag-OFF → throws the vendor-gated exception (production-without-adapter stays gated, the footgun guard / break-revert anchor holds). Flag-ON → commits, stamps `verified_live_at`, fires `assignment.live_verified` (Sprint 10's payment trigger).
- **Three review endpoints + the `review` policy ability** (D-4/D-6/D-7) — `CampaignAssignmentReviewController` with `show` (the drawer read: assignment + full draft history + posted content, reusing the Chunk 1 resources with signed media URLs), `approve`, `request-revision` (feedback required), `reject` (feedback required). Authz is the net-new `review` ability (admin + manager + staff). Each mutating action, in **one transaction**, writes the latest draft's review trail (`review_status` / `reviewed_at` / `reviewed_by_user_id` / `review_feedback` — the column-only fields Chunk 1 shipped) **before** driving the machine, so the notification listener reads persisted feedback. The machine stays the sole status authority; its typed exceptions map to 422.
- **Mock `SocialPlatformProvider`** (D-9) — a **lean** contract (`verifyPostUrl(string $handle, string $postUrl): PostVerification`, one DTO + one outcome enum), placed in `App\Modules\Creators\Integrations\{Contracts,Mock,Stubs,Enums,DataTransferObjects}` beside Kyc/Esign/Payment. Deterministic canned outcomes: URL contains the handle → `verified`; recognizable social URL without it → `mismatch`; unrecognizable → `not_found`. Mock/Deferred/Skipped stubs + the `CreatorsServiceProvider` flag-and-driver binding mirror the three existing providers; `IntegrationProviderBindingsTest` gains its **"Social: 1"** built-surface row.
- **`VerifyPostedContentJob` + the `posted_by_creator` listener** (D-10) — the job is dispatched by `DispatchPostedContentVerification` on the `assignment.posted_by_creator` transition (**listener, not inline** in Chunk 1's posted endpoint — the event was fired purely additively there). The job resolves the provider out of the container (mirroring `SimulateEsignWebhookJob`), is **idempotent** (only a `pending` post is processed), writes the outcome onto `verification_status`, and on `verified` drives `posted → live_verified`; on `not_found`/`mismatch` it leaves the assignment `posted` and notifies the agency. Flag-gated defense-in-depth (a no-op if the flag flipped OFF after the job was queued).
- **`SocialVerificationEnabled` flag** (D-11) — default-OFF Pennant feature, registered in `CreatorsServiceProvider`, gating both the machine edge and the verification job.
- **Notification set** (D-14) — three queued Mailables (the `ConnectionRequestMail` pattern, `ShouldQueue` + localized + Blade templates): `DraftSubmittedForReviewMail` (→ agency, on `draft_submitted`), `DraftReviewedMail` (→ the creator's owning user, on approved/revision/rejected), `PostVerificationFailedMail` (→ agency, on verification failure). Agency-facing mail targets `invited_by_user_id`; creator-facing mail targets the creator's `user`. Dispatched from `SendAssignmentNotifications` (an `AssignmentTransitioned` listener) and the job. **Tech-debt logged** (see below): agency mail targets the inviting user, not an agency-wide inbox/role.

### Frontend

- **`ReviewDraftDrawer.vue`** (D-8) — a **wide `v-dialog`** mirroring `ReinviteDialog` (no `v-navigation-drawer` pattern exists in this app), opened from a `draft_submitted` row in the campaign-detail Creators tab. Loads the agency detail on open, previews the latest draft (caption / hashtags / mentions + media via the shared `PortfolioGallery` lightbox), shows version history + posted content with verification status, and offers the three actions with **canonical 422 field-error binding** on `review_feedback`. Added to `CANONICAL_422_FILES`.
- **The "simulated" label** (D-12) — the verified verification chip carries an explicit "Simulated verification (mock provider)" note, so the surface never implies a real platform check happened.
- **The `rejected` chip** (D-2) — the new status rendered across the FE union type + i18n.
- **API client + i18n** — `packages/api-client` gains the `rejected` status, the agency detail resource type, and the three review payload/response types; `campaigns.api.ts` gains `showAssignment` / `approveDraft` / `requestRevision` / `rejectDraft`; the `app.campaigns.review` i18n block (incl. a self-owned `draftStatus` sub-block — see deviations) lands in en/pt/it at parity.

---

## The `Rejected` enum ripple — every consumer walked

Per the catalogue-tripwire discipline, the enum-add was not assumed complete — each consumer was confirmed:

- **`AssignmentStatus::isTerminal()`** — `Rejected` added to the terminal set (state-machine test pins it terminal: no edge leaves it).
- **`CampaignEnumsTest`** (the tripwire) — the `AssignmentStatus` catalogue gains `rejected`; the test bites if the enum and its pin diverge.
- **FE union** (`packages/api-client/src/types/campaign.ts`) — `'rejected'` added (backend/frontend constant parity, §5.1).
- **i18n** — `assignmentStatus.rejected` in en/pt/it.
- **`AuditActionEnumTest`** — `assignment.draft_rejected` added to the action catalogue **and** the `requiresReason` set.

---

## The break-revert anchor — flag-OFF keeps the gate green

`verifyLive()` flips from an unconditional refusal to a flag-gated edge. The pre-existing "no manual path to `live_verified`" guarantee is preserved when the flag is OFF (the default). The state-machine test asserts both arms: flag-OFF → `AssignmentTransitionGatedException` (the existing break-revert test stays green — the footgun guard still bites in production where the flag is off); flag-ON → commits + stamps `verified_live_at`. The vendor-gated `holdPayment`/`releasePayment` stubs are untouched — Sprint 10 still owns escrow.

---

## Acceptance criteria

| #     | Criterion                                                                                                                   | Status |
| ----- | --------------------------------------------------------------------------------------------------------------------------- | ------ |
| D-1/2 | `AssignmentStatus::Rejected` terminal + every consumer (isTerminal, FE union, i18n, catalogue tripwire) walked              | ✅     |
| D-3   | `rejectDraft()` edge — fail-closed source, reason-mandatory, `assignment.draft_rejected` verb (requiresReason)              | ✅     |
| D-4   | Three per-action review endpoints + agency-side `show`, one-transaction trail-then-machine orchestration                    | ✅     |
| D-5   | Feedback required on request-revision + reject; not required on approve                                                     | ✅     |
| D-6   | `review` policy ability (admin + manager + staff); tenancy 404 for non-members                                              | ✅     |
| D-7   | Agency-side detail reuses the Chunk 1 resources (draft history + posted content, signed URLs)                               | ✅     |
| D-9   | Lean `SocialPlatformProvider` (verifyPostUrl + one DTO), handle-based, `Integrations\{Contracts,Mock}`, "Social: 1" binding | ✅     |
| D-10  | `VerifyPostedContentJob` dispatched by the `posted_by_creator` listener (not inline), idempotent                            | ✅     |
| D-11  | `SocialVerificationEnabled` flag gates `verifyLive` + the job; flag-OFF keeps the break-revert gate green                   | ✅     |
| D-12  | FE labels the verified state "simulated" (mock provider, not a real check)                                                  | ✅     |
| D-13  | not_found/mismatch leave the assignment `posted` (no machine call)                                                          | ✅     |
| D-14  | Three queued localized Mailables; agency → invited_by, creator → owning user; tech-debt note logged                         | ✅     |
| D-8   | `ReviewDraftDrawer` as a wide dialog (ReinviteDialog pattern), canonical 422 binding, in `CANONICAL_422_FILES`              | ✅     |
| —     | All existing + new tests green; Pint + Larastan clean                                                                       | ✅     |

---

## Verification results

| Gate                                      | Result                                                                                       |
| ----------------------------------------- | -------------------------------------------------------------------------------------------- |
| `apps/api` Pest — chunk surface           | **117 / 117** (400 assertions) — review endpoints, job, state machine, mock, bindings, enums |
| `apps/main` Vitest — full suite           | **845 / 845** (96 files) — incl. the 4 new `ReviewDraftDrawer` specs + the detail-page specs |
| `apps/main` `vue-tsc --noEmit`            | 0 errors                                                                                     |
| `apps/main` ESLint (changed files)        | 0 errors                                                                                     |
| Pint (`apps/api`)                         | clean                                                                                        |
| Larastan level 8 (`apps/api`)             | clean (the `PostVerificationFailedMail` outcome-literal narrowing resolved — see deviations) |
| **Break-revert — `verifyLive` flag gate** | flag-OFF → `AssignmentTransitionGatedException` asserted; flag-ON → commits + stamps         |

(The full backend Pest suite was not re-run end-to-end this pass — only the chunk surface (117). Flagged per the asymmetric-coverage discipline; the spot-check can run the full suite.)

---

## Files touched

**Backend — modified:**

- `Modules/Campaigns/Enums/AssignmentStatus.php` — `Rejected` + `isTerminal()`.
- `Modules/Audit/Enums/AuditAction.php` — `AssignmentDraftRejected` + `requiresReason()`.
- `Modules/Campaigns/Services/CampaignAssignmentStateMachine.php` — `rejectDraft()`; `verifyLive()` flag-gated + `verified_live_at`; `approve`/`requestRevision` context param.
- `Modules/Campaigns/Policies/CampaignPolicy.php` — `review` ability.
- `Modules/Campaigns/Routes/api.php` — the four review routes.
- `Modules/Campaigns/CampaignsServiceProvider.php` — register the two new listeners.
- `Modules/Creators/CreatorsServiceProvider.php` — register the flag + bind the social provider.
- `config/integrations.php` — the `social` driver entry.

**Backend — new:**

- `Modules/Campaigns/Http/Controllers/CampaignAssignmentReviewController.php`
- `Modules/Campaigns/Jobs/VerifyPostedContentJob.php`
- `Modules/Campaigns/Listeners/{DispatchPostedContentVerification,SendAssignmentNotifications}.php`
- `Modules/Campaigns/Mail/{DraftSubmittedForReviewMail,DraftReviewedMail,PostVerificationFailedMail}.php` + `resources/views/mail/campaigns/{draft-submitted,draft-reviewed,verification-failed}.blade.php`
- `Modules/Creators/Features/SocialVerificationEnabled.php`
- `Modules/Creators/Integrations/Contracts/SocialPlatformProvider.php` + `DataTransferObjects/PostVerification.php` + `Enums/PostVerificationOutcome.php` + `Mock/MockSocialProvider.php` + `Stubs/{DeferredSocialProvider,SkippedSocialProvider}.php`
- `lang/{en,pt,it}/campaigns.php`

**Backend — tests:** `CampaignAssignmentReviewTest`, `VerifyPostedContentJobTest`, `MockSocialProviderTest` (new); `CampaignAssignmentStateMachineTest`, `CampaignEnumsTest`, `AuditActionEnumTest`, `CreatorFeatureFlagsTest`, `IntegrationProviderBindingsTest` (extended).

**Frontend:**

- `packages/api-client/src/types/campaign.ts` — `rejected` + agency detail + review payload/response types.
- `apps/main/src/modules/campaigns/api/campaigns.api.ts` — the four review wrappers.
- `apps/main/src/modules/campaigns/components/ReviewDraftDrawer.vue` (+ `.spec.ts`) — new.
- `apps/main/src/modules/campaigns/pages/CampaignDetailPage.vue` (+ `.spec.ts`) — Review button + drawer wiring.
- `apps/main/src/core/i18n/locales/{en,pt,it}/app.json` — `rejected` + the `review` block.
- `apps/main/tests/unit/architecture/form-error-pattern.spec.ts` — `ReviewDraftDrawer.vue` in `CANONICAL_422_FILES`.

**Docs:** `tech-debt.md` (agency-notification-targeting entry); this review.

---

## Honest deviations & notes

- **The `review.draftStatus` i18n sub-block (not the creator namespace).** The drawer's draft-history chip first reused `creator.ui.assignments.detail.reviewStatus.*` — a creator-namespace key from an agency-side component. To avoid the cross-namespace coupling (and the test-harness key-miss it caused), a self-owned `app.campaigns.review.draftStatus` block was added in all three locales and the drawer repointed at it. Same four labels, agency-owned.
- **Non-member review is a 404, not a 403.** The `tenancy.agency` middleware intercepts a non-member before the `Gate` runs (no information leak across tenants), so the cross-tenant feature test asserts **404**. The `review` ability's true/false behaviour per role is covered separately by a dedicated policy unit test so the authz logic itself is still pinned.
- **`PostVerificationFailedMail` outcome narrowing.** Larastan flagged the mail constructor (which accepts only `'mismatch'|'not_found'`) being handed the broader `PostVerificationOutcome`. The job now maps explicitly to the two failure literals — `verified` never reaches that path (it returns earlier), so the narrowing is sound, not a cast-over-a-bug.
- **Full backend Pest not re-run end-to-end.** Only the 117-test chunk surface was run this pass; the count is flagged honestly rather than implying a fresh full-suite run.
- **Pre-existing working-tree changes.** The roster contact-email work that was uncommitted at kickoff has since been committed (`61a30dd`); the working tree is now purely this chunk, so there is nothing unrelated to keep unstaged.

---

## Tech-debt logged

- **Agency-side assignment notifications target the inviting user, not an agency-wide inbox/role** — added to `docs/tech-debt.md`. Agency-facing mail (`DraftSubmittedForReviewMail`, `PostVerificationFailedMail`) is addressed to `invited_by_user_id`, mirroring the `ConnectionRequestMail` precedent. No shared inbox/role fan-out exists; another manager on the same agency sees nothing. Low risk at Phase-1 volumes (status is durable + visible on the campaign surface); resolved when the real notification subsystem lands (same trigger as the existing notification-subsystem debt entry).

---

## Commit shape (as merged)

The two-commit pair, spot-check-passed and pushed:

1. **`fc438a2`** — `feat(campaigns): agency draft review + mock social verification (Sprint 9 Chunk 2)` — all backend (enum/machine/audit, the review endpoints + policy, the social provider + bindings, the job + listeners, the flag, the notification set + lang) + their tests + the tech-debt note + this review.
2. **`d5d93eb`** — `feat(main): agency review drawer + verification surface (Sprint 9 Chunk 2)` — the api-client types, the api wrappers, `ReviewDraftDrawer.vue` + spec, the detail-page wiring, the i18n, the `CANONICAL_422_FILES` entry.

A trailing `docs(reviews)` commit flips this file's status to Closed.

**Sprint-9-close follow-up (not a chunk-2 blocker):** run the full backend Pest suite end-to-end at the Sprint 9 close to confirm no cross-module regression — this chunk verified the 117-test chunk surface but did not re-run the full suite.

---

_Provenance: drafted by Cursor (Sprint 9 Chunk 2 build pass, 2026-06-05) — backend lifecycle arc (the `rejected` terminal + mock social verification) + the agency review drawer, against the kickoff's five confirmed divergences. Spot-check passed (no PMC); pair `fc438a2` / `d5d93eb` pushed and this review closed per `PROJECT-WORKFLOW.md` § 3 steps 8–9._
