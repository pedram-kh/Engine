# Sprint 3 — Chunk 4 Review (DRAFT)

**Status:** Ready for review.

**Reviewer:** _(Pedram + independent reviewer pending — this file is the Cursor self-review draft. Replace with the Claude-led independent review at chunk close, mirroring the chunk-1 / chunk-2 / chunk-3 pattern.)_

**Commits:**

- `eeb7d2b` — work commit (sub-steps 1-12 + the two pre-merge spot-check PMC test pins). Files touched: ~85 across apps/api, apps/main, apps/admin, packages/api-client, docs/security, docs/tech-debt.
- _this commit_ — plan-approved follow-up (sprint-3 chunk-4 hash + Ready-for-review draft + Sprint 3 self-review draft).

**Reviewed against:** `PROJECT-WORKFLOW.md` § 3 (build-pass discipline) + § 5 (standing standards #9, #34, #40-#42), `02-CONVENTIONS.md` § 1 + § 2.2 (modular monolith), `01-UI-UX.md` (design tokens, Vuetify, WCAG 2.1 AA), `03-DATA-MODEL.md` § 5 (Creator is a global entity), `04-API-DESIGN.md` § 1.4 (resource shape) + § 1.5 (error envelope), `05-SECURITY-COMPLIANCE.md` § 6.5 (verified-email gate), `06-INTEGRATIONS.md`, `07-TESTING.md` § 4-5, `09-ADMIN-PANEL.md` § 6.4 (admin creator management), `20-PHASE-1-SPEC.md` § 5 (Sprint 3 acceptance) + § 7 (critical-path E2E #9), `feature-flags.md`, `security/tenancy.md` § 4 (cross-tenant allowlist), `tech-debt.md` (3 new entries by this chunk), `docs/reviews/sprint-3-chunk-3-review.md` (pause-condition-6 closure path).

This chunk closes Sprint 3 by landing the six surfaces named in the kickoff plan:

1. **Agency-side bulk-invite UI** + magic-link Step 1 pre-fill.
2. **Sprint 2 carry-forward** (workspace switching full UX, `requireMfaEnrolled` on admin-gated agency routes, brand restore UI, agency users list pagination, invitation history list, `AcceptInvitationPage` email-mismatch + already-member Playwright coverage).
3. **Admin per-field edit** (deferred from Chunk 3 per pause-condition-6) + admin approve/reject UI.
4. **Critical-path E2E #9** — agency admin bulk-invites 5 creators end-to-end via the SPA's CSV upload.
5. **Sprint 3 self-review** (this file + the Sprint 3 closer review tracked separately).
6. **Docs / tenancy allowlist / tech-debt** fix-ups.

Every Sprint 2 carry-forward item is now closed at the SPA layer; Sprint 3's wizard + admin creator surfaces are end-to-end functional from sign-up through approval; the bulk-invite UX completes the agency-side acquisition funnel that creator onboarding consumes.

Sprint 3 acceptance criteria from `20-PHASE-1-SPEC.md` § 5 are now ~100% met across the four chunks. Sprint 4 kickoff inherits a clean closed-loop state.

---

## Scope

### Sub-step 1 — Admin per-field PATCH endpoint (closes pause-condition-6)

- **`PATCH /api/v1/admin/creators/{creator}`.** New `AdminCreatorController::update` method. Authorises via `CreatorPolicy::adminUpdate` (admin branch). Validates via `AdminUpdateCreatorRequest`. Persists changes in a single transaction with the audit row emission, then re-loads the creator and returns the same `(new CreatorResource($creator, $calc))->withAdmin(true)->response()` shape as the GET endpoint.
- **`AdminUpdateCreatorRequest`.** Pins the 7 editable fields (`display_name`, `bio`, `country_code`, `region`, `primary_language`, `secondary_languages`, `categories`), per-field max-lengths, the 16-category enum (`CATEGORY_ENUM`), and the conditional `reason` requirement (`bio`, `country_code`, `region`, `primary_language`, `secondary_languages`, `categories` — every field where a deliberate operator-intent record matters for the audit trail). The display name change does not require a reason because the field is non-sensitive (most renames are typo fixes / display-name updates the operator drives via support tooling). Note: `country_code`, `primary_language`, and `secondary_languages.*` are validated as `size:2` strings — they are NOT enum-constrained on the backend. The frontend's curated country / language dropdowns are admin-UX choices, not mirrors-of-backend invariants (see the `field-edit-config-parity.spec.ts` architecture-test scope note in sub-step 9).
- **`CreatorPolicy::adminUpdate`** ships the admin branch; agency-side updates remain forbidden (this surface is platform-admin only).
- **Idempotency.** Submitting the same value for a field is a no-op (returns 200 with the unchanged resource; no audit row emitted). The audit emission predicate is `array_key_exists($field, $changes) && $oldValue !== $newValue` per the Sprint 1 Chunk 5 standing audit-on-change pattern.
- **Field status immutability.** `application_status` and `kyc_status` are intentionally NOT in `EDITABLE_FIELDS`. Per-field PATCH cannot transition application state — that's what the dedicated approve / reject endpoints (sub-step 2) are for. The request validator rejects unknown keys with `creator.admin.field_status_immutable` per existing standing audit-on-change discipline.
- **Coverage delta**: 14 Pest tests covering happy path per field, reason-required-when-required, idempotent no-op, immutable status fields, cross-tenant safety (admin reads any agency's creator; the route is in the tenancy allowlist), 422 on unknown field, 422 on enum violation.

### Sub-step 2 — Admin approve / reject endpoints (replace Chunk 1 policy stubs)

- **`POST /api/v1/admin/creators/{creator}/approve`.** Transitions `application_status` from `pending` to `approved` in a single transaction with the audit row. Optional `welcome_message` (max 1000 chars) is persisted on `creators.welcome_message` — surfaces on the creator's dashboard as a personalised approval message. Idempotent: returns `creator.already_approved` on already-approved applications.
- **`POST /api/v1/admin/creators/{creator}/reject`.** Symmetric to approve. Mandatory `rejection_reason` (10–2000 chars) persisted on `creators.rejection_reason`. Idempotent: returns `creator.already_rejected` on already-rejected applications.
- **`CreatorPolicy::approve` + `::reject`** replace the Chunk 1 placeholder stubs with real platform-admin branches. The Chunk 1 stubs were `return false` placeholders awaiting the controller's existence; this chunk replaces them with the production gate.
- **Coverage delta**: 2 Pest tests per endpoint (happy path + idempotent re-call) + 1 architecture test pinning the route chain to `auth:web_admin + EnsureMfaForAdmins` + 1 policy unit test per method.

### Sub-step 3 — Agency members + invitation history paginated endpoints

- **`GET /api/v1/agencies/{agency}/members`.** Path-scoped tenant route (under `tenancy.agency, tenancy` middleware). Any agency member can list. Supports `?role=agency_admin|agency_manager|agency_staff`, `?search=`, `?page=`, `?per_page=`. Returns a paginated `MembershipResource` collection with `data[]`, `links{}`, and `meta{}` per `04-API-DESIGN.md` § 1.4.
- **`GET /api/v1/agencies/{agency}/invitations`.** Admin-only (enforced inline in `InvitationController::index`). Returns the full invitation history (pending / accepted / expired / cancelled) for the agency. Supports `?status=`, `?search=`, `?page=`, `?per_page=`. Newest-first by `created_at`.
- **Coverage delta**: 8 Pest tests covering ordering, filtering (role, status, search), pagination metadata, cross-tenant rejection, admin-only gate.

### Sub-step 4 — Magic-link `/auth/accept-invite` route + SignUp token pass-through

- **SPA flow.** Decision A2 (magic-link) chose the email pre-fill UX over the token-on-wizard-Step-1 path. New `/auth/accept-invite?token=…&agency=…` route in `apps/main/src/modules/auth/routes.ts` hits the existing `creators.invitations.preview` endpoint, retrieves `{agency_name, is_expired, is_accepted, email}`, then either:
  - **Existing-account path** — redirects to `/sign-in?redirect=…` with the token preserved in the redirect query string. After sign-in the user is bounced to `AcceptInvitationPage` to consume the invitation.
  - **New-account path** — redirects to `/sign-up?token=…&email=…` with the email pre-filled (disabled input; tooltip explains why) and the token preserved through the verify-email → sign-in → accept flow.
- **`SignUpPage` token-aware behaviour.** When `?token=…&email=…` query params are present, the email input is disabled + pre-filled (token-bound), the heading shifts to "Accept your invitation", and the token is preserved as a hidden field through to verify-email. After verify-email + sign-in, `AcceptInvitationPage` resolves the token and lands the user in the workspace.
- **Magic-link pre-fill on Step 1.** Per the kickoff Q-pre-fill resolution, the Step 1 (Profile Basics) page reads the SPA's `useAuthStore.user.email` (which is the email the user verified via the magic-link flow) as the canonical input for the `email_display` field. No token-pass-through to the wizard — the linkage is implicit through the verified-email gate.

### Sub-step 5 — Workspace switching full UX + `requireMfaEnrolled` on admin-gated agency routes

- **`useAgencyStore.switchAgency` action.** Sprint 2 stubbed the switch action; Chunk 4 wires the full UX:
  1. **Setter-injected `AuthRebootstrapHook` (Pinia circular-dependency workaround).** `useAgencyStore.ts` is module-import-clean — it imports nothing from auth at module scope. The agency store's module exports a `setAuthRebootstrap(hook)` function that populates a module-scoped `let authRebootstrap: AuthRebootstrapHook | null` variable. The wiring happens from inside `useAuthStore.ts`'s factory function body: `useAuthStore.ts` imports `{ setAuthRebootstrap, useAgencyStore }` and calls `setAuthRebootstrap({ resetBootstrapStatus, bootstrap, isBootstrapping })` immediately before `return { … }`-ing the store API. The setter therefore runs the first time any consumer materialises the auth store via `useAuthStore()` — typically during router boot, well before the first `switchAgency()` call. `apps/main/src/main.ts` itself stays store-agnostic (just `app.use(createPinia())`). The seam is unidirectional: auth knows about agency, agency does not know about auth, no module-load cycle.
  2. On `switchAgency(targetAgencyId)`: updates `currentAgencyId`, persists to localStorage, sets `isSwitchingAgency = true`, invokes the rebootstrap hook (which re-fetches `/me` with the new agency context), then clears `isSwitchingAgency`.
  3. Idempotency: switching to the already-current agency is a no-op (no rebootstrap, no loading flag flip).
  4. Error handling: rebootstrap failures propagate to the caller and `isSwitchingAgency` is reset to `false` in the `finally` block. The committed `currentAgencyId` + `localStorage` write happen BEFORE the await, so a rejected rebootstrap leaves the store in a half-state — see "Honest deviations from the kickoff plan" below for the full picture.
- **`AgencyLayout.vue` workspace-switcher UI.** v-list of agencies in the user's `memberships`, with the current one marked. Loading skeleton while `isSwitchingAgency`.
- **`requireMfaEnrolled` on `/agency-users`** (the agency admin team-management page). Per `20-PHASE-1-SPEC.md` § 7 — admin-sensitive routes require 2FA enrolment. Architecture test (`agency-routes-mfa-guard.spec.ts`) pins the guard chain order: `requireAuth → requireMfaEnrolled → requireAgencyAdmin`. Other agency routes (dashboard, brands, settings) intentionally stay at `requireAuth + requireAgencyAdmin` only — the MFA gate applies to sensitive admin surfaces, not the whole agency shell.
- **Coverage delta**: 18 new `useAgencyStore` Vitest cases + 1 architecture test (`agency-routes-mfa-guard.spec.ts`).

### Sub-step 6 — Brand restore UI

- **`POST /api/v1/agencies/{agency}/brands/{brand}/restore`.** Backend route added under the standard `auth:web, tenancy.agency, tenancy` stack. The controller queries with `withTrashed()` and explicitly matches on `ulid` (not the integer `id`, which the route param resolver doesn't carry). Idempotent: restoring an already-active brand is a 200 no-op.
- **`brandsApi.restore(ulid)` SPA wrapper.**
- **`BrandListPage.vue` restore affordance.** Admin-only "Restore" button on archived rows (visible when `status === 'archived'` AND `agencyStore.isAdmin`). Confirmation dialog (matches the archive-dialog pattern from Sprint 2). Success snackbar with the restored brand's name interpolated.
- **i18n.** New keys: `app.brands.actions.restore`, `app.brands.restore.confirmTitle`, `app.brands.restore.confirmMessage`, `app.brands.restore.confirm`, `app.brands.restore.cancel`, `app.brands.restore.success` (with `{name}` interpolation), `app.brands.errors.restoreFailed`.
- **Coverage delta**: 8 backend Pest (happy path, admin + manager auth, staff rejection, audit emission, idempotency, cross-tenant rejection, missing brand 404, unknown ULID 404) + 7 Vitest (visibility, dialog open / cancel / confirm, API call, success snackbar, error alert).

### Sub-step 7 — Agency users pagination + invitation history list

- **`AgencyUsersPage.vue` rewrite.** Replaces the Sprint 2 hardcoded `agencyStore.memberships` array with two `v-data-table-server` instances:
  - **Members table.** Consumes the sub-step-3 `GET /agencies/{agency}/members` endpoint. Role-filter chips (all / admin / manager / staff) + search input (debounced 350ms). Empty / empty-filtered states.
  - **Invitation history table** (admin-only). Consumes the sub-step-3 `GET /agencies/{agency}/invitations` endpoint. Status-filter chips (all / pending / accepted / expired / cancelled) + search input. Empty / empty-filtered states.
- **Coverage delta**: 9 Vitest cases (members + invitations table rendering, filter application, empty states, error alerts, admin-only invitation history visibility).

### Sub-step 8 — `AcceptInvitationPage` email-mismatch + already-member Playwright coverage

- **`invitations-error-paths.spec.ts`.** Two new E2E tests:
  1. **Email mismatch.** Invitee email seeded as `alice@example.com`. User signs in as `bob@example.com` and navigates to `alice`'s accept URL. SPA renders the `acceptInvitationEmailMismatch` state with a clear "this invitation is for a different email" message. The page does NOT auto-redirect.
  2. **Already member.** User accepts their first invite (becomes member). A second invitation is seeded for the SAME user + SAME agency. User navigates to the second accept URL. SPA renders the `acceptInvitationAlreadyMember` state. The page does NOT re-accept (no idempotent 200 — these are distinct invitation tokens).
- Anchors on `data-test` attributes (`acceptInvitationEmailMismatch`, `acceptInvitationAlreadyMember`) added in Sprint 2 chunk 2.
- Chunk-7.1 conventions applied: `auth-ip` neutralised + restored; `signOutViaApi` in `afterEach`; `resetClock` belt-and-suspenders.

### Sub-step 9 — Admin per-field edit modals (`EditFieldRow` + 7 fields)

- **`EditFieldRow.vue` shared component.** Wraps a label + slot content + an `mdi-pencil` icon button. Emits `edit` event on click. Used in the admin SPA's `CreatorDetailPage.vue` to wrap every editable field with a uniform inline-edit affordance.
- **`EditFieldModal.vue` shared component.** Generic edit dialog that renders different input controls based on the `EditFieldConfig` prop:
  - `text` — single-line text input.
  - `textarea` — multi-line textarea (bio).
  - `select` — `v-select` with options.
  - `multi-select` — `v-combobox` with `multiple` (categories, secondary languages).
  - `region-text` — text input with country-code-aware placeholder (region field).
- **Reason field.** Conditionally shown for sensitive fields (`bio`, `country_code`, `region`, `primary_language`, `secondary_languages`, `categories`). Required + non-empty validator before save.
- **`FIELD_EDIT_CONFIG`** — single source of truth in `apps/admin/src/modules/creators/config/field-edit.ts` mapping each editable field to its control type, label key, max length, options array, and reason requirement.
- **`adminCreatorsApi.updateField(ulid, field, value, reason)`.** Wraps `PATCH /admin/creators/{ulid}` with the field + reason in the payload.
- **`CreatorDetailPage.vue` integration** — wraps the 7 editable fields with `EditFieldRow` instances; opens `EditFieldModal` on edit click; calls `updateField`; refreshes the page state on success; shows a success snackbar with the field label interpolated.
- **`field-edit-config-parity.spec.ts` architecture test.** Source-inspects the backend `AdminUpdateCreatorRequest` and the frontend `field-edit.ts` config; asserts `EDITABLE_FIELDS`, `REASON_REQUIRED_FIELDS`, and `CATEGORY_ENUM` are bit-identical across the two layers. Drift between the layers is now a hard CI failure. **Country-code and language-code curations are intentionally NOT pinned by this test** — the backend has no `COUNTRY_CODES` / `LANGUAGE_CODES` enum to mirror (`AdminUpdateCreatorRequest::rules()` validates both as `size:2` strings, no `Rule::in(...)`). The admin SPA's `COUNTRY_OPTIONS` is a curated 9-code list (IE / GB / PT / IT / ES / FR / DE / US / CA) that the field's `select` control surfaces with an `allowCustomCode: true` escape valve. Admin-vs-wizard list alignment is docstring-only — see the `docs/tech-debt.md` entry "Country-code list curations not enforced by architecture test" for the resolution path.
- **i18n.** New keys under `admin.creators.detail.fields.*`, `admin.creators.detail.edit.*`, including success / failure snackbars and the per-field labels.
- **Coverage delta**: 7 EditFieldModal Vitest + 8 CreatorDetailPage Vitest (per-field edit) + 1 architecture test = 16 tests.

### Sub-step 10 — Admin approve / reject buttons

- **`ApproveCreatorDialog.vue`.** Confirmation dialog with optional `welcome_message` textarea (max 1000 chars). Backend error `creator.already_approved` surfaces inline with the dialog staying open (operator can dismiss).
- **`RejectCreatorDialog.vue`.** Confirmation dialog with mandatory `rejection_reason` textarea (10–2000 chars). Client-side validator mirrors backend min/max. Error `creator.already_rejected` surfaces inline.
- **`CreatorDetailPage.vue` integration.** "Approve application" + "Reject application" buttons in the page header, conditionally shown based on `application_status === 'pending'`. Approve / reject dialogs open on click; on success the page reloads + the decision snackbar shows.
- **Coverage delta**: 7 ApproveCreatorDialog Vitest + 8 RejectCreatorDialog Vitest + 4 CreatorDetailPage Vitest (decision flow) = 19 tests.

### Sub-step 11 — Bulk-invite UI + Critical-path E2E #9 + Magic-link Step 1 pre-fill

- **`bulkInviteApi.submit(agencyId, file)` + `getJob(jobUlid)`.** Multipart CSV upload + `TrackedJob` polling.
- **`useBulkInviteCsv` composable.** Client-side CSV parser mirroring the backend `BulkInviteCsvParser`. Validates only the `email` column; ignores all other columns. Hard limits: 1 MiB file size, 1000 rows. Soft warning threshold: 100 rows. Returns `{rows, errors, rowCount, exceedsSoftWarning, fatal}`.
- **`BulkInvitePage.vue`** — agency-side bulk-invite UI. State machine: `idle | parsing | preview | submitting | tracking | complete | failed`. File picker (v-file-input) → client-side parse (preview rows + per-row errors + fatal banner + soft-warning banner) → submit → poll → terminal state (stats + per-row failures, or `failure_reason`).
- **Route.** `/creator-invitations/bulk`, gated by `requireAuth → requireMfaEnrolled → requireAgencyAdmin`. The route is `agency`-layout-wrapped (consistent with the rest of the agency surface).
- **Agency-users CTA.** New "Bulk-invite creators" button on `AgencyUsersPage.vue` (admin-only), navigates to the bulk-invite page.
- **Critical-path E2E #9 (`bulk-invite-creators.spec.ts`).** Drives the full journey: seed agency admin with 2FA enrolled → sign in (email + password + TOTP) → navigate via the agency-users CTA → upload a 5-row CSV → observe tracking → observe complete with `invited: 5, already_invited: 0, failed: 0`.
- **Test-helper extension.** `CreateAgencyWithAdminController` (`POST /api/v1/_test/agencies/setup`) gains an `enroll_2fa` flag. When `true`, the controller seeds `users.two_factor_secret` (via the production `TwoFactorService::generateSecret()`) + `two_factor_recovery_codes` (hand-rolled hex strings — see new tech-debt entry) + `two_factor_confirmed_at = now()` so the SPA's `requireMfaEnrolled` guard treats the user as enrolled on first sign-in. Without this, the bulk-invite spec would have to drive ~12 SPA navigations just to enroll 2FA before reaching the page under test. The `Playwright `seedAgencyAdmin`fixture forwards the flag through and exposes`twoFactorSecret`on the result. The spec mints the TOTP code via the existing`mintTotpCodeForEmail` helper.
- **Polling-cadence test pin.** The happy-path Vitest test (`happy path: submits CSV, polls job, and renders complete state with stats`) asserts `expect(bulkInviteApi.getJob).toHaveBeenCalledTimes(2)` after `await vi.advanceTimersByTimeAsync(3000)` — i.e., the first poll fires synchronously after submit, and a second poll fires within a 3-second window. This is a **single-cycle** pin: it catches both "interval got dropped" (only 1 call) and "interval got grown beyond 3 s" (still only 1 call within the window), but does NOT exercise a multi-cycle poll loop. Multi-cycle coverage runs through the Chunk 4 Playwright spec (`bulk-invite-creators.spec.ts`) against the real worker, which is what proves the loop actually terminates on `complete` after N processing cycles. Pre-merge PMC-2 added an `clears the poll timer when the page unmounts during tracking` test that pins the abort-on-unmount cleanup with a 3×interval advance — same single-cycle granularity, different invariant (no calls after unmount, regardless of how many cycles passed).
- **Coverage delta**: 8 BulkInvitePage Vitest (includes PMC-2 abort-on-unmount) + 14 useBulkInviteCsv Vitest + 1 E2E spec + 4 Pest tests for the test-helper enroll-2fa branch + 9 backend Pest tests for the bulk-invite controller (already present in Chunk 1; verified intact) = 36 tests.

### Sub-step 12 — Docs + tenancy allowlist + tech-debt + chunk-4 review draft + Sprint 3 self-review draft

- **`security/tenancy.md` § 4.** Three new allowlist rows for the admin-creator PATCH / approve / reject endpoints. Each row carries the tenancy-category justification (path-scoped admin tooling; tenant-less by category — Creator is a global entity).
- **`tech-debt.md`.** Two new entries:
  - **BulkInvitePage exposes `onFileSelected` for unit-test access.** Vuetify's `<v-file-input>` is hostile to JSDOM file-input simulation; the page exposes `onFileSelected` via `defineExpose` so the unit spec can drive the parse → preview → submit flow directly. The Playwright E2E spec drives the real `<input type="file">` via `setInputFiles`, so production coverage is intact.
  - **`seedAgencyAdmin` hand-rolls recovery codes** instead of calling `RecoveryCodeService::generate()`. The 8 codes are `bin2hex(random_bytes(5))` strings rather than the production `XXXXX-XXXXX` hyphenated decimal shape. The codes are never consumed by the bulk-invite spec (the spec mints a TOTP code, not a recovery code), so the format mismatch is invisible at runtime — but it's a divergence worth recording.
- **`docs/reviews/sprint-3-chunk-4-review.md`** (this file).
- **Sprint 3 self-review draft.** Tracked separately as `docs/reviews/sprint-3-self-review-draft.md` (the project-wide retrospective covering Chunks 1-4 holistically).

---

## Refinements applied (kickoff plan-approval)

_Update on close — the kickoff plan-approval surfaced four divergences from the original plan; resolutions are captured here in the locked-design-decision shape so future chunks inherit them cleanly._

- **Refinement 1 — Magic-link UX is email pre-fill, not token-on-wizard-Step-1.** Decision A2 (kickoff Q-pause-PC-A2 = (b)). The Step 1 form does not need to be token-aware; the magic-link flow lands the user in the workspace with verified email, and Step 1 reads `useAuthStore.user.email` as the canonical source. Wizard-Step-1 token handling was de-scoped.
- **Refinement 2 — Bulk-invite UX is single async path (D-pause-9 = b reinterpreted, Q-pause-PC6 = α).** Submit a CSV → backend persists `TrackedJob` + dispatches `BulkCreatorInvitationJob` → returns 202 + job ULID → SPA polls `/jobs/{id}` at 3s cadence until terminal. No "inline preview + edit" UX; the client-side parser is for pre-upload validation only, not interactive editing.
- **Refinement 3 — Admin per-field edit layout is one row per field, not a single multi-field form.** Decision E2 = b. Each editable field has its own `EditFieldRow` + `EditFieldModal`; the operator edits one field at a time. Avoids partial-state ambiguity when multiple fields would otherwise share a single submit button.
- **Refinement 4 — Testing convention: Vue Test Utils stub limitations call for `defineExpose` escape hatch.** Discovered during build: Vuetify's `<v-file-input>` cannot be stubbed cleanly via `mount(..., { stubs })` because Vuetify registers components globally and the stub map only intercepts locally-resolved components. The pragmatic fix — expose the handler via `defineExpose({ onFileSelected })` — is recorded as tech-debt with three resolution options (extract to composable / drop unit coverage in favour of Playwright / wait for Vue Test Utils plugin-component stubbing).

## Pause-condition closures

- **Pause-condition-6 — admin per-field edit (deferred from Chunk 3).** Closed. Sub-step 1 ships the PATCH endpoint; sub-step 9 ships the SPA modals. The field-edit-config-parity architecture test pins the backend / frontend contract.
- **All Sprint 2 carry-forward items.** Closed:
  - Workspace switching full UX (sub-step 5).
  - `requireMfaEnrolled` on admin-gated agency routes (sub-step 5).
  - Brand restore UI (sub-step 6).
  - Agency users list pagination (sub-step 7).
  - Invitation history list (sub-step 7).
  - `AcceptInvitationPage` email-mismatch + already-member Playwright coverage (sub-step 8).

## Q-answer confirmations (kickoff)

- **Q-pause-PC-A2 → (b) email pre-fill via magic link.** Confirmed. Refinement 1 above.
- **Q-pause-PC6 → (α) single async path.** Confirmed. Refinement 2 above.
- **Q-pause-PC-E2 → (b) one row per field.** Confirmed. Refinement 3 above.
- **Q-pause-PC-test-strategy → defineExpose escape hatch + Playwright covers the real DOM path.** Confirmed. Refinement 4 above.

---

## Test-count summary

| Surface                      | Chunk-4 net new                                                                                         |
| ---------------------------- | ------------------------------------------------------------------------------------------------------- |
| Backend Pest                 | ~34                                                                                                     |
| Frontend Vitest (apps/main)  | ~65                                                                                                     |
| Frontend Vitest (apps/admin) | ~35                                                                                                     |
| Architecture tests           | 2 test files (`agency-routes-mfa-guard` extended with PMC-1 negative-case + `field-edit-config-parity`) |
| Playwright specs             | 2 (invitations-error-paths + bulk-invite-creators)                                                      |
| **Total**                    | **~138**                                                                                                |

The `apps/main` Vitest delta of ~65 includes the two PMC additions landed pre-merge: PMC-1 (negative-case "only the named routes carry `requireMfaEnrolled`" assertion in `agency-routes-mfa-guard.spec.ts`) and PMC-2 (abort-on-unmount test in `BulkInvitePage.spec.ts`). Both were verified via the standing #40 break-revert discipline — see the spot-check audit transcript.

**Running project totals after Chunk 4 close (Sprint 3 complete):**

- Backend Pest: ~747 (Chunk 3 close) + ~34 = ~781
- Main SPA Vitest: ~449 (Chunk 3 close) + ~65 = ~514
- Admin SPA Vitest: ~242 (Chunk 3 close) + ~35 = ~277
- Plus design-tokens (17), api-client (88), architecture tests across both SPAs.

---

## Standout design choices (unprompted)

Recording so they become reusable patterns:

- **`field-edit-config-parity.spec.ts` architecture test.** Source-inspects PHP + TS for cross-layer invariant parity. The PHP-parsing helper handles both `public const` (default arrays) and `private const` (validation enums); reusable for any future "backend constants must match frontend constants" check. Closes a class of bug (frontend rejects what backend accepts, or vice versa) at CI time instead of at runtime.
- **Setter-injection for circular Pinia store dependencies.** `useAgencyStore.ts` exports a module-scope `setAuthRebootstrap(hook)` that populates a private `authRebootstrap` variable; `useAuthStore.ts` imports the setter and calls it from inside its factory function body, so the wiring runs lazily the first time a consumer materialises the auth store (no separate `main.ts` call). The agency store never imports auth at module level — the seam is unidirectional and there is no module-load cycle. Reusable pattern for any cross-store action coupling where one store needs to call into another's action without a top-level import.
- **Test-helper `enroll_2fa` flag.** Adds an out-of-band 2FA-enrolled seed branch to the existing `agencies/setup` helper. Without it, the bulk-invite E2E spec would have driven ~12 SPA navigations just to enroll 2FA before reaching the page under test. The seam is gated by the chunk-6.1 helper-token middleware (production traffic cannot reach it) and the production `TwoFactorService::generateSecret()` is reused so the TOTP-derivation shape is bit-identical. Same pattern available to any future spec needing a pre-enrolled subject.
- **`VFileInputStub` + `defineExpose({ onFileSelected })`** for Vuetify file-input testing in JSDOM. The Vuetify v-file-input cannot be cleanly stubbed via `mount(..., { stubs })` because of the plugin-global registration. The two-step workaround — file-text() polyfill on the test File object + page-side `defineExpose` for the parse handler — keeps the unit spec focused on the page's state machine, while Playwright covers the real DOM path. The tech-debt entry captures three resolution options for the next chunk that touches BulkInvitePage substantively.

---

## Decisions documented for future chunks

These decisions made in Chunk 4 propagate to Sprint 4+.

### Admin per-field edit ships as one row per field, NOT a single multi-field form

- **Where:** `apps/admin/src/modules/creators/components/EditFieldRow.vue` + `EditFieldModal.vue` + `CreatorDetailPage.vue`.
- **Decision:** Each editable field has its own `EditFieldRow` + opens its own modal. No "edit all fields" page-level form.
- **Why:** Avoids partial-state ambiguity (operator changes 5 fields and the 5th save fails — what's the rollback?). Each field edit is its own transaction with its own audit row. The audit trail is structurally cleaner.
- **Phase 2+ pattern:** Brand admin editing, Campaign admin editing, etc. follow the same one-field-per-modal pattern.

### Magic-link UX preserves token through verify-email + sign-in; wizard Step 1 is token-unaware

- **Where:** `apps/main/src/modules/auth/routes.ts` + `SignUpPage.vue` + `Step2ProfileBasicsPage.vue` (Step 1 in product nomenclature).
- **Decision:** The token-on-URL pattern flows: `/auth/accept-invite?token=…&agency=…` → either `/sign-in?redirect=/accept-invitation?token=…` (existing account) or `/sign-up?token=…&email=…` (new account). After verify-email + sign-in, the user is bounced to `AcceptInvitationPage` to consume the invitation. The wizard pages do NOT receive or process the token.
- **Why:** The invitation token is the AgencyCreatorRelation linker, not a wizard-state linker. Consuming it at the AcceptInvitationPage keeps the linker logic in one place and avoids the wizard pages having to know about agency relations they didn't initiate.
- **Phase 2+ pattern:** Future invitation-style flows (e.g., agency-to-creator collab invites in Sprint 6+) reuse this token-on-URL → consume-at-dedicated-page shape.

### `requireMfaEnrolled` applies to admin-sensitive agency routes, not the whole agency shell

- **Where:** `apps/main/src/modules/auth/routes.ts` + `architecture/agency-routes-mfa-guard.spec.ts`.
- **Decision:** `/agency-users` (the team-management page) carries `requireMfaEnrolled` in its guard chain; `/dashboard`, `/brands/*`, `/settings` do NOT. The MFA gate is applied at the route level where sensitive operations happen, not as a blanket gate on the agency shell.
- **Why:** A user without 2FA enrolled should be able to view their dashboard, settings, and brands — just not perform admin actions on the agency members. The fine-grained gate matches the user-mental-model expectations.
- **Phase 2+ pattern:** Sprint 4+ admin-sensitive routes (campaign approval, agency suspension, etc.) inherit the same per-route MFA-enrolment gate posture.

### Backend / frontend constant parity is enforced via architecture tests

- **Where:** `apps/admin/tests/unit/architecture/field-edit-config-parity.spec.ts`.
- **Decision:** When a backend Laravel `Request` class pins enums / field lists that the frontend mirrors, an architecture test source-inspects both layers and asserts bit-identical content.
- **Why:** Prevents the subtle UX bug where the frontend shows a field as editable but the backend rejects the value (or vice versa). CI catches the drift before merge.
- **Phase 2+ pattern:** Any new admin-editable surface, brand-admin field list, campaign-admin field list, etc., ships with its own parity architecture test.

---

## Honest deviations from the kickoff plan

- **`onFileSelected` exposed via `defineExpose`** instead of being driven through a stubbed v-file-input in unit tests. Recorded as tech-debt with three resolution options. The Playwright critical-path spec drives the real DOM path; production coverage is intact.
- **Test-helper `enroll_2fa` flag generates recovery codes by hand**, not via `RecoveryCodeService::generate()`. The codes are never consumed in the spec, but the format mismatch is recorded as tech-debt.
- **Magic-link Step 1 pre-fill is implicit**, not driven by an explicit token-pass-through to the wizard. The auth store's verified-email is the canonical source. Documented in Decision A2 + Refinement 1.
- **Critical-path E2E #9 uses `page.goto()` for SPA navigations** rather than clicking the sidebar `v-list-item` and the "Bulk-invite creators" CTA. Vuetify's `:to`-bound widgets are intermittently flaky under Playwright (see commit `043355e` — the project pattern is verify-visible-then-goto). The CTA's discoverability is still asserted via `toBeVisible`; only the navigation itself is direct-URL.
- **Workspace switching is non-atomic on rebootstrap failure.** The `switchAgency` action commits `currentAgencyId` + `localStorage` BEFORE awaiting `bootstrap()` — the `persists the new selection BEFORE awaiting bootstrap` unit test in `useAgencyStore.spec.ts` deliberately pins this behaviour so a page refresh mid-rebootstrap still lands on the new tenant. The consequence on the unhappy path: if `bootstrap()` rejects, `isSwitchingAgency` resets to `false` (via the `finally` block) and the rejection re-throws to the caller, but `currentAgencyId` is NOT rolled back to the previous value. The user is left in a half-state — the agency-id is switched, the loading flag is off, but the user's `/me` payload is still the previous tenant's. The next route navigation triggers a fresh `bootstrap()` and converges, so the half-state is transient rather than corrupting. This is by design per Decision D2 = b (session-stored agency, no URL navigation) — atomic switch-or-rollback would require either (a) a two-phase commit pattern that holds the previous `currentAgencyId` until rebootstrap settles, or (b) a Sprint 4+ refactor that unifies identity + tenancy into a single store. The error-path test (`resets isSwitchingAgency to false even when bootstrap rejects`) pins the loading-flag reset but does NOT pin the `currentAgencyId` retention — the half-state behaviour is documented here rather than test-enforced.

## Bugs uncovered during the chunk-close E2E pass (fixed in this chunk)

The chunk-close Playwright pass ran the new critical-path E2E #9 against a real Laravel + Vite stack and surfaced three issues that all green unit + type-check passes had hidden. All three are fixed in this chunk.

### B1 — `@catalyst/api-client` HTTP client JSON-stringified multipart uploads

- **Where:** `packages/api-client/src/http.ts` + `packages/api-client/src/http.spec.ts`.
- **Discovery:** The bulk-invite Playwright spec uploaded a 5-row CSV and the SPA caught a 422 (`bulk_invite.missing_file`). The Laravel controller's `$request->file('file')` was returning `null` — no multipart part existed in the body.
- **Root cause:** Every state-changing request hard-coded `Content-Type: application/json`. Axios 1.x's default `transformRequest` reads the header BEFORE serialising the body — when it saw `application/json` on a `FormData` payload, it called `formDataToJSON(data)` and shipped a plain JSON object. The browser never wrote the `multipart/form-data; boundary=…` header, and Laravel saw an empty file part.
- **Fix:** Two-part, with asymmetric test coverage worth naming explicitly:
  1. **Per-request null `Content-Type` for FormData bodies.** Detect `config.data instanceof FormData` inside `request<T>()` and set the header to `null`; axios 1.x interprets that as "drop this header from the request entirely" so the browser writes its own `multipart/form-data; boundary=…`. **This is the fix the Vitest suite pins** via `http.spec.ts`'s axios-mock-adapter path. The PMC-1 spot-check break-revert (replacing the conditional with an unconditional `'application/json'`) confirms the regression net: the `drops the JSON Content-Type when the body is FormData (multipart)` test fails when the per-request null override is removed.
  2. **Removed the instance-level default headers** from the inline `axios.create({...})` block in `createHttpClient`. This is **defense-in-depth for the production path** where no `axiosInstance` is injected — without it, a future change that drops the per-request null would silently regress because the instance default would re-merge in. No test pins this leg independently: the Vitest harness injects its own axios instance (with `Content-Type: application/json` defaults baked in) via `createHttpClient({ axiosInstance })`, so the inline-create path is moot under unit tests. Production-path coverage for this leg comes from the Chunk 4 Playwright spec (`bulk-invite-creators.spec.ts`) running against a real Vite + Laravel stack — which is what surfaced the bug in the first place.
- **Coverage:** Two new unit tests in `http.spec.ts` exercise the per-request leg: one verifies the `Content-Type` drop for FormData (target URL `/avatar`), the other verifies plain-object bodies on the same axios instance still get `application/json` (target URL `/sessions`). The instance-default leg has no Vitest pin; it relies on the Playwright critical-path E2E #9 as the integration-side proof.
- **Scope of impact (pre-fix):** Every multipart endpoint silently sent `[object FormData]` (or the JSON form-encoded equivalent) instead of a real upload. The avatar + portfolio + bulk-invite endpoints were all affected. **Avatar + portfolio have no Vitest coverage at the transport level** — their composable specs (`useAvatarUpload.spec.ts`, `usePortfolioUpload.spec.ts`) mock `onboardingApi` directly, so the FormData never reaches `http.ts` in tests. They ride on the new `http.spec.ts` per-request pin + (for portfolio image) the bulk-invite Playwright spec's exercise of the same `http.ts` code path. Sprint 3 Chunks 1–3 + Sprint 2 unit tests likewise use `axios-mock-adapter`, which never enforces the multipart contract.

### B2 — `CreatorPolicyTest` carried Chunk 1 `Sprint 4 stub` assertions

- **Where:** `apps/api/tests/Unit/Modules/Creators/Policies/CreatorPolicyTest.php`.
- **Discovery:** Backend Pest run after sub-step 2 (admin approve / reject).
- **Root cause:** The Chunk 1 placeholder policy methods returned `false` for everyone with a "Sprint 4 stub" comment. Sub-step 2 of this chunk replaced both stubs with real platform-admin branches (`$user->type === UserType::PlatformAdmin`). The corresponding unit assertions still asserted the stub behaviour.
- **Fix:** Rewrote both `approve` / `reject` policy tests to assert the new contract — `true` for platform-admin, `false` for owners, `false` for agency members. The test names + the section comment now match the live code.

### B3 — `AuditAction` catalogue test missed four chunk-4 verbs

- **Where:** `apps/api/tests/Feature/Modules/Audit/AuditActionEnumTest.php`.
- **Discovery:** Same Pest run.
- **Root cause:** Sub-steps 1 + 2 + 4 introduced `creator.admin.field_updated`, `creator.approved`, `creator.rejected`, and `creator.invitation_accepted`. The enum-catalogue parity test pins the full list and didn't get the four additions.
- **Fix:** Added the four entries to the expected list (alphabetical positioning matches the existing structure). The reason-mandatory + sensitive-credential subset tests already covered the new verbs by inheritance (none of them require a `reason`, none are sensitive-credential actions).

### Other E2E regressions fixed in this chunk

Sub-step 5's `requireMfaEnrolled` guard on `/agency-users` broke two pre-existing Playwright specs that signed in an agency-admin without enrolling 2FA. Both now use the new `seedAgencyAdmin({ enroll2fa: true })` + `mintTotpCodeForEmail` flow.

- `playwright/specs/invitations.spec.ts` — the first test (`agency_admin can invite a user via the modal`) drives the TOTP step inline after the password submit.
- `playwright/specs/permissions.spec.ts` — the admin-side test does the same; the staff-side test was updated to assert the new redirect destination (`/auth/2fa/enable`, not `/brands`), since the chain now fails at `requireMfaEnrolled` before reaching `requireAgencyAdmin`. The staff-cannot-see-Invite-user-button assertion is preserved as a defence-in-depth check on `/brands`.

## Open questions for the independent review

- Is the `defineExpose({ onFileSelected })` test-only API surface acceptable, or should we refactor BulkInvitePage to extract the parse state machine into a composable now (instead of deferring to the tech-debt entry's trigger)?
- The `enroll_2fa` test-helper flag's hand-rolled recovery codes — acceptable as recorded tech-debt, or should we wire through `RecoveryCodeService::generate()` before the chunk closes?
- The cross-tenant allowlist categorisation tech-debt entry (chunk-1 F1) is still open. Worth a dedicated housekeeping pass before Sprint 4 kickoff, or carry into Sprint 4?

---

## Coverage details by surface

_(Detailed test-by-test breakdown follows — pending fill-in during the independent review pass. The Chunk 3 review's structure is the template.)_

- **Sub-step 1 — Admin per-field PATCH:** _to be filled_
- **Sub-step 2 — Admin approve / reject:** _to be filled_
- **Sub-step 3 — Members + invitation history listings:** _to be filled_
- **Sub-step 4 — Magic-link auth route:** _to be filled_
- **Sub-step 5 — Workspace switching + MFA guard:** _to be filled_
- **Sub-step 6 — Brand restore:** _to be filled_
- **Sub-step 7 — Agency users pagination:** _to be filled_
- **Sub-step 8 — Invitation error-path E2E:** _to be filled_
- **Sub-step 9 — Admin edit modals:** _to be filled_
- **Sub-step 10 — Admin approve / reject UI:** _to be filled_
- **Sub-step 11 — Bulk-invite UI + E2E #9:** _to be filled_
- **Sub-step 12 — Docs + review:** _to be filled_
