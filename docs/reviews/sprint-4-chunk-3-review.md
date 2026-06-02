# Sprint 4 — Chunk 3 Review

**Status:** Closed.

**Author:** Cursor (build + self-review draft).

**Scope:** Creator approval workflow + manual/vendor KYC gate — built as **one chunk, no sub-chunk gates** (reviewer's explicit call), organized below by the seven net-new clusters from the kickoff.

**Reviewed against:** `20-PHASE-1-SPEC.md` § 6.2 (approval workflow) + § 8 (i18n / mock discipline / no-skip), `02-CONVENTIONS.md` § 2.2 (modular monolith) + § 5 (git), `04-API-DESIGN.md` § 1.4 (resource shape) + § 1.5 (error envelope), `05-SECURITY-COMPLIANCE.md` § 4 (encryption — PII drill-in deferred) + § 6 (admin gating), `07-TESTING.md` § 4 (defense-in-depth + break-revert), `09-ADMIN-PANEL.md` § 6.4 (creator management), `security/tenancy.md` (creators are a global entity — no agency tenancy on the admin list), the locked decisions D-c3-1…D-c3-11, and the two plan-pause divergences approved by the user (**D-NEW-1**: approve gates on `verified` **OR** `not_required`; **D-NEW-2**: expose `kyc_vendor_available` from the backend).

This chunk is **wiring + a few net-new endpoints + one migration + two emails**, not a workflow build — the approve/reject path, the admin Approve/Reject dialogs, the admin detail page, and the four creator dashboard banners all already shipped in Sprint 3 Ch4.

---

## Internal build order (no gates — informational)

1. **Backend foundation** — migration (`kyc_method` + `verified_by_user_id`) → `KycMethod` enum → model wiring → audit actions.
2. **Backend endpoints** — manual verify (C1) → approve gate (C2) → review-queue index (C3) → reopen + `submit()` clear (C6) → resource exposure (C5) → mailables + dispatch (C7).
3. **Backend tests** — per-cluster Pest, each break-revert-anchored.
4. **Frontend** — shared types → admin SPA list + verify UI (C3/C4) → main SPA dashboard rewire + resubmit (C5/C6).
5. **Frontend tests + docs.**

---

## Cluster 1 — Manual KYC verify (D-c3-3 / D-c3-4 / D-c3-5)

**Schema (the one migration).** `2026_05_16_100000_add_kyc_method_and_verified_by_to_creators_table.php` adds `kyc_method` (`string(16)`, nullable until set) + `verified_by_user_id` (FK `users.id`, `nullOnDelete`) to **`creators`** (distinct from the pre-existing `creator_tax_profiles.verified_by_user_id`, a different concept — D-c3-4). New `KycMethod` enum (`vendor` / `manual`), cast on the model; new `verifiedBy()` relation; both columns added to `$fillable` + the `auditableAllowlist`.

**Endpoint / policy / action / audit.** `POST /api/v1/admin/creators/{creator}/verify-identity` → `AdminCreatorController::verifyIdentity()`, `platform_admin`-gated via the new `CreatorPolicy::verifyIdentity()`. Sets `kyc_status = verified`, `kyc_verified_at = now()`, `verified_by_user_id = acting admin`, `kyc_method = manual`. Optional `note` (`VerifyCreatorIdentityRequest`) captured in the audit metadata. Idempotent: already-verified → **409** `creator.kyc_already_verified` (mirrors the approve-409 pattern). Emits the new `AuditAction::CreatorKycManuallyVerified` (`creator.kyc.manually_verified`) capturing actor + note — attribution + audit are **load-bearing**, not optional (a permanent admin override of identity is compliance-sensitive).

**Vendor stamp.** `ProcessKycWebhookJob` now stamps `kyc_method = vendor` whenever it writes `kyc_status` — so the discriminator is always populated from whichever path clears identity (D-c3-5).

**Coverage:** `AdminVerifyIdentityTest` (happy path sets all four fields + `kyc_method=manual` + audit actor; optional note; already-verified 409; non-`platform_admin` 403). `ProcessKycWebhookJobTest` gains the vendor-stamp assertion (stamped even on a webhook rejection).
**Break-revert:** drop `verified_by_user_id` from the write → attribution test fails; drop `kyc_method` → discriminator test fails.

## Cluster 2 — Approve precondition (D-c3-7 + D-NEW-1)

`AdminCreatorController::approve()` now gates on KYC being cleared. The gate is **whitelist-shaped (fail-closed)** — `! in_array($creator->kyc_status, [KycStatus::Verified, KycStatus::NotRequired], true)` → **422** `creator.kyc_not_verified`. This is an allow-list of the two approvable states (not a block-list of the currently-unapprovable ones), so a future `KycStatus` enum case defaults to **blocked**, never silently approvable — the same fail-closed principle as the Chunk-2 feed-metadata whitelist. The `not_required` branch is the approved **D-NEW-1** divergence — it preserves the Sprint-3 flag-OFF happy path (where `submit()` sets `kyc_status = not_required` as a terminal "identity waived" state) while enforcing a real gate whenever KYC is on. The existing already-approved 409 is unchanged. The existing approve tests were **updated to satisfy the gate** (new `CreatorFactory::kycVerified()` state), **not weakened** — the gate still fails on unverified KYC.

The SPA mirrors the gate: `CreatorDetailPage`'s `canApprove` now ANDs in `isKycCleared` (`verified || not_required`). The backend re-validates as SOT; gating the affordance just spares the admin a guaranteed-fail click.

**Coverage:** `AdminCreatorUpdateTest` — approve with `none`/`pending` → 422 (break-revert anchor: remove the gate → these fail); approve with `verified` and with `not_required` → succeed; existing suite green under `kycVerified()`. **Fail-closed exhaustiveness pin** — a dataset-driven test iterates **all** `KycStatus::cases()` (so a new enum case auto-enrolls) and asserts only `{verified, not_required}` clear identity while every other case → 422 with status unchanged. The approvable set is pinned _independently_ of the controller, so flipping the controller to a block-list — or adding a new case to its whitelist — fails this test. SPA: `CreatorDetailPage.spec` — Approve hidden when `kyc_status=none`, shown when `not_required`.

## Cluster 3 — Review queue (D-c3-8)

`GET /api/v1/admin/creators` → `AdminCreatorController::index()`, `platform_admin`-gated via the new `CreatorPolicy::viewAny()` (class-level `authorizeAny()` helper). Filterable by `?status=` (any `ApplicationStatus`; absent ⇒ all), paginated (`page` / `per_page`), returns the slim list-card shape (`display_name`, `application_status`, `kyc_status`, `profile_completeness_score`, `submitted_at`, `created_at`) + `meta` (total/page/per_page/last_page). Creators are **global** — the admin sees all; no agency tenancy on this list. Route ordering: the index sits **before** the `{creator}` param route.

SPA: `CreatorListPage.vue` at `/creators` (`app.creators.list`) — a `v-data-table-server` mirroring the `BrandListPage` pattern (status filter chips defaulting to `pending`; click-through to the detail drill-in). `adminCreatorsApi.list()` added.

**Coverage:** `AdminCreatorIndexTest` (status filter returns only matching rows; non-admin 403; pagination meta). SPA: `CreatorListPage.spec` (mounts + loads pending; `all` drops the param; filter re-queries; row click navigates to detail; error code surfaced).

## Cluster 4 — Verify-identity UI (D-c3-6)

`CreatorDetailPage` gains an identity section: **"Verify manually"** (active when not yet verified — opens `VerifyIdentityDialog`, a confirm dialog mirroring `ApproveCreatorDialog` with an optional note; calls `adminCreatorsApi.verifyIdentity()` then refreshes) and **"Request vendor verification"** (a **disabled affordance** with a "No KYC provider configured" tooltip — D-c3-6). The vendor button is intentionally always disabled (no backend to call yet); the tooltip is driven by `kyc_vendor_available` (D-NEW-2 — see below). The manual control disappears once `kyc_status = verified` (re-verify would 409).

**`kyc_vendor_available` (D-NEW-2).** Exposed on `admin_attributes`, computed backend-side from the KYC flag + the absence of a real (non-mock) driver — honest, testable, flag-driven rather than a hard-coded frontend constant.

**Coverage:** SPA `CreatorDetailPage.spec` — manual verify opens dialog, calls the endpoint with the note, refreshes, and the manual control then disappears; manual control hidden when already verified; vendor control disabled when no vendor available.

## Cluster 5 — Creator rejection-reason wiring (D-c3-1)

`rejection_reason` + `rejected_at` now appear on the **creator-facing** `attributes` block of `CreatorResource` (previously only on the admin `admin_attributes` block — so they were `null` for the creator). The dashboard rejected-banner reads from `attributes` (was reading the always-null `admin_attributes`). This satisfies the spec's "in-app notification" via the existing banner (D-c3-1 — no notification subsystem; logged as tech-debt).

**Coverage:** `CreatorMeRejectionReasonTest` (`/creators/me` for a rejected creator includes the reason — break-revert: withhold it → the creator-facing test fails; null for a non-rejected creator). SPA `CreatorDashboardPage.spec` (banner renders the reason from `attributes`).

## Cluster 6 — Resubmit loop (D-c3-9 / D-c3-10)

`POST /api/v1/creators/me/reopen` → `CreatorWizardController::reopen()` → `CreatorWizardService::reopen()`: source-state-guarded `rejected → incomplete` (only `rejected` may reopen; otherwise **409** `creator.reopen.invalid_state`). Resolved off `$request->user()->creator`, so a creator can only ever reopen their **own** application — non-owner reopen is structurally impossible. On reopen the rejection fields are **preserved** (editing guidance) and `submitted_at` is cleared so the wizard reads as not-submitted; the existing `requireOnboardingAccess` guard already admits `incomplete` creators. On the subsequent `submit()` (`incomplete → pending`), the rejection fields are now **cleared** (D-c3-10 — added; the resubmission supersedes, audit preserves history).

SPA: the dashboard rejected-banner gains an **"Update & resubmit"** button → `useOnboardingStore.reopen()` → routes to `onboarding.welcome-back`. Errors resolve through the existing `resolveSubmitErrorKey` helper (`creator.reopen.invalid_state` bundle entry added en/pt/it).

**Coverage:** `CreatorReopenTest` (reopen only from `rejected` — break-revert: drop the source guard → the non-rejected reopen test fails; flips to `incomplete` + preserves reason; `submit()` after reopen → `pending` + clears the fields; wizard guard admits the reopened creator; reopen never touches another creator). SPA: store `reopen` action test + dashboard resubmit-click test (calls `reopen`, navigates).

## Cluster 7 — Emails (D-c3-11)

Two queued, localized mailables mirroring `ProspectCreatorInviteMail`: `CreatorApprovedMail` + `CreatorRejectedMail` (the rejection mail carries the reason). Both `ShouldQueue`, markdown views (`resources/views/mail/creators/{approved,rejected}.blade.php`), `catalyst` theme, `Mail::locale()` to the creator's preferred language (en/pt/it fallback to `en`). Dispatched from the approve / reject actions respectively. No real-provider decision blocks this — `config/mail.php` default is `log`; verified via `Mail::fake()`.

**Coverage:** `CreatorLifecycleMailTest` (each dispatches on the right action, to the creator, in the creator's locale; reason present in the rejection mail; en fallback when no preferred language; rejection markdown renders the reason).

---

## Honest-deviation triggers — answers

- **Resubmit guard interaction:** clean. The reopen reuses the existing `requireOnboardingAccess` admit-`incomplete` path; the only new transition is the source-guarded reopen. No unexpected guard collision. No flag/pause needed.
- **Approve × incomplete-state affordance (B4 looseness):** the gate is a simple `kyc_status` check; the pre-existing "approve doesn't require `application_status = pending`" looseness is **noted, not expanded** (out-of-scope per the kickoff — flagged here, not changed).
- **Schema placement:** `kyc_method` / `verified_by_user_id` on `creators` collided with nothing (the tax-profile column is a separate concept). Migration landed clean.
- **Emails needing a real provider:** no — the log mailer + `Mail::fake()` cover dispatch + content + locale. Provider stays the deferred `services.md` item.
- **Further § 6.2 divergences beyond D-NEW-1/2:** none.

## Spot-check anchors (for the reviewer)

1. **Manual-verify attribution + `kyc_method=manual` + audit actor** — `AdminVerifyIdentityTest` (break-revert: drop `verified_by_user_id` / drop `kyc_method`).
2. **Approve refuses unverified KYC, fail-closed** — `AdminCreatorUpdateTest` gate tests (break-revert: remove the gate) + the whitelist-shaped `in_array([Verified, NotRequired])` gate + the all-`KycStatus::cases()` exhaustiveness pin (a future enum case defaults to blocked).
3. **`kyc_method=vendor` stamped on the webhook path** — `ProcessKycWebhookJobTest`.
4. **Resubmit source-guard + `submit()` clears rejection fields** — `CreatorReopenTest` (break-revert: drop the source guard).
5. **`rejection_reason` reaches the creator on `/creators/me`** — `CreatorMeRejectionReasonTest` (break-revert: withhold it).
6. **Rejection email carries the reason** — `CreatorLifecycleMailTest`.
7. **Vendor-verify control disabled (not wired); manual is the only live `kyc_status` writer outside the mock webhook** — `CreatorDetailPage.spec` + the absence of any vendor backend call.

## Test summary

- **Backend (Pest):** 66 passing across the Chunk-3 files (`AdminVerifyIdentityTest`, `AdminCreatorIndexTest`, `AdminCreatorUpdateTest`, `CreatorLifecycleMailTest`, `CreatorReopenTest`, `CreatorMeRejectionReasonTest`, `ProcessKycWebhookJobTest`) — incl. the fail-closed all-`KycStatus::cases()` gate pin. PHPStan clean (2G memory); Pint clean. (Run the full suite under `php -d memory_limit=2G` — the default 128M cap OOMs the broad `--filter` run, an env quirk unrelated to this chunk.)
- **Admin SPA (Vitest):** 315 passing (full suite) — incl. `CreatorListPage.spec` (5, new), `CreatorDetailPage.spec` (16, +5 gate/verify). `vue-tsc` clean; ESLint clean (admin `eslint.config.js` gained `vue/valid-v-slot: { allowModifiers: true }`, mirroring the main SPA, for the Vuetify dotted-slot table).
- **Main SPA (Vitest):** 620 passing (full suite) — incl. `CreatorDashboardPage.spec` (+2 reason/resubmit), `useOnboardingStore.spec` (+1 reopen), and the `i18n-creator-codes` architecture test (the new `creator.kyc_not_verified` / `creator.kyc_already_verified` / `creator.reopen.invalid_state` backend codes now carry en/pt/it translations). `vue-tsc` clean.

## Out of scope (logged at close)

- **No notification subsystem** (D-c3-1) → new tech-debt entry "Real in-app notification subsystem".
- **No PII drill-in** (D-c3-2) → new tech-debt entry "Admin PII drill-in for encrypted KYC / tax-profile decision data (vendor-era)".
- No real KYC vendor (disabled affordance only). No real email provider (log mailer; `services.md` email row sharpened). No new admin sub-role (all admin actions on the existing `platform_admin` gate, D-c3-8). No `application_status=pending`-only approve tightening.

## Commit plan (two-commit pair)

Spot-check passed; the pre-merge confirmation (PMC) added the fail-closed all-`KycStatus::cases()` gate pin (the gate was already whitelist-shaped — this is the test that pins it).

1. **Work commit** — all backend + frontend code + tests (the `apps/**` + `packages/**` changes, including the fail-closed gate pin and the admin `eslint.config.js` rule).
2. **Docs commit** — this review (`Status: Closed`) + the two `tech-debt.md` entries + the `services.md` email-posture touch.
