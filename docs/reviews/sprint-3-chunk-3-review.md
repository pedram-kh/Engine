# Sprint 3 — Chunk 3 Review

**Status:** Ready for review.

**Reviewer:** Cursor self-review draft → independent reviewer (Claude) pass pending.

**Reviewed against:** `PROJECT-WORKFLOW.md` § 3 (build-pass discipline) + § 5 (~27 standing standards), `02-CONVENTIONS.md` § 1 + § 2.2 (modular monolith) + § 2.8 (DTO conventions) + § 5 (git workflow), `01-UI-UX.md` (design tokens, Vuetify, WCAG 2.1 AA), `03-DATA-MODEL.md` § 5 (Creator is a global entity) + § 23 (encryption casts), `04-API-DESIGN.md` § 1.4 (resource shape) + § 1.5 (error envelope) + § 18 (file uploads) + § 19 (presigned uploads), `05-SECURITY-COMPLIANCE.md` § 4 (encryption) + § 6.5 (verified-email gate), `06-INTEGRATIONS.md` § 1.3 (vendor-bounce contract), `07-TESTING.md` § 4 (testing discipline) + § 5 (E2E conventions), `20-PHASE-1-SPEC.md` § 5 (Sprint 3 acceptance) + § 6.1 (wizard step shape) + § 8.1 (mock-usage discipline) + § 8.2 (i18n) + § 8.4 (markdown rendering trust boundary), `feature-flags.md` (registry + driver convention + Pennant scope override), `security/tenancy.md` § 4 (cross-tenant allowlist), `tech-debt.md` (1 entry closed + 1 entry added by this chunk), `docs/reviews/sprint-3-chunk-1-review.md` + `docs/reviews/sprint-3-chunk-2-review.md` (Honest deviations + decisions for future chunks + locked refinements).

**Commits:** work commit `1736b3d` (sub-steps 1-12 + the sub-step-12 doc fix-ups except this review file); plan-approved follow-up commit to follow per the two-commit chunk shape (kickoff `Process notes`).

This chunk closes the creator-facing onboarding wizard surface — every wizard step page (Welcome Back, Steps 2-9) shipped as Vue 3 components under `apps/main/src/modules/onboarding/`, the `useOnboardingStore` Pinia store owning bootstrap + per-step mutations, the `requireOnboardingAccess` router guard gating the wizard at the route-group level, the shared-component split between `apps/main` (form-main) and `packages/ui` (display-shared) per Decision C1, the bounded-3 concurrency portfolio upload composable (Decision F1=a), the `useVendorBounce` saga loop and `useFeatureFlags` resolver for the three vendor-gated steps, the click-through-accept fallback for the contract-signing flag-OFF path, the server-side master-agreement renderer (`ContractTermsRenderer`) wired through `GET /wizard/contract/terms` (Refinement 4), the `CreatorResource::withAdmin()` factory that surfaces `rejection_reason` + `kyc_verifications` history to admin readers (Refinement 2 — closes Chunk 1 tech-debt entry 4), the admin SPA's read-only Creator Detail page at `/creators/{ulid}` with its localised admin i18n bundle, the test-helper queue-mode override for E2E saga specs (`POST/DELETE /api/v1/_test/queue-mode`), the WCAG 2.1 AA architectural sweep across every wizard page (F2=b), the Playwright happy-path spec (`creator-wizard-happy-path.spec.ts`) end-to-end through sign-up → wizard → `/creator/dashboard` pending banner, and the companion `creator-dashboard.spec.ts` incomplete-banner direct-access spec. Two new test-helper Playwright fixtures land here (`setQueueMode` / `clearQueueMode` + the chunk-3 `seedPortfolioImage` / `signInViaApi` / `verifyEmailViaApi` setup helpers), and the cross-tenant route allowlist (`security/tenancy.md` § 4) gains the chunk-3-new admin + test-helper routes per the Refinement-3 F1-style audit.

---

## Scope

### Sub-step 1 — Resolver + i18n + `creator.*` prefix + `CreatorResource.flags` + `withAdmin()` factory + `packages/api-client` creator types

- **Resolver widening (Refinement 4-adjacent).** `apps/main/src/modules/auth/composables/useErrorMessage.ts` extended its `isLikelyBundledCode()` prefix allowlist to accept `creator.*` codes (alongside the existing `auth.*` / `validation.* / rate_limit.*` family pinned in Sprint 1 chunk 7.1). The architecture test [`i18n-error-codes.spec.ts`](../../apps/main/tests/unit/architecture/i18n-error-codes.spec.ts) was extended to harvest `creator.*` literals from the backend and verify each has a matching bundle entry in en/pt/it.
- **`creator.*` i18n bundles.** New `creator.json` bundles in en/pt/it under `apps/main/src/core/i18n/locales/` carry every wizard UI string + error code (per-step `title` / `description` / `actions.*`, per-platform `social_platforms.*`, per-category `categories.*`, per-status `kyc.status_labels.*` / `contract.status_labels.*`, `tax_form_types.*`, `vendor_bounce.*`, `dashboard.*`, and the `creator.wizard.*` + `creator.ui.errors.*` error families).
- **`CreatorResource::flags` block (pause-condition-7).** The `CreatorResource::toArray()` output gained a `wizard.flags` block at `{kyc_enabled, payout_enabled, contract_enabled}` so the SPA can render skipped-with-explanation surfaces without a separate flag-introspection round-trip. Closes pause-condition-7 from the kickoff.
- **`withAdmin()` factory (Refinement 2 — closes Chunk 1 tech-debt entry 4).** `CreatorResource` exposes a `withAdmin(bool $isAdminView = true): self` factory method that toggles a private `$isAdminView` flag. `toArray()` reads the flag and conditionally appends an `admin_attributes` block carrying `rejection_reason` + `kyc_verifications` history (newest-first, status + provider + timestamps only, PII stripped). Symmetric — one resource class, one `toArray()` shape, one extra block conditional on the flag. The creator-self route (`GET /api/v1/creators/me`) emits the base shape; the admin route (`GET /api/v1/admin/creators/{creator}`, sub-step 9) chains `->withAdmin(true)` before `->response()`. **tech-debt entry 4 closed** with the factory shape documented in this review.
- **`packages/api-client` types.** New `CreatorSocialAccountSummary` + `CreatorPortfolioItemSummary` + `CreatorKycVerificationSummary` types in `packages/api-client/src/types/creator.ts`. `CreatorAttributes` gained `social_accounts` + `portfolio` arrays + the `wizard.flags` block.
- **Coverage delta.** 14 new backend Pest tests + 3 new `useErrorMessage` Vitest cases + 5 new architecture-test rules (`creator.*` prefix harvest, `withAdmin` source-inspection, `CreatorResource.flags` shape pin, admin-only-fields are kyc_verifications-shaped, `withAdmin` is a no-op when called with `false`).

### Sub-step 2 — Onboarding module + `OnboardingLayout` + `useOnboardingStore` + `onboarding.api.ts` + `requireOnboardingAccess` + `WelcomeBackPage` + App.vue layout dispatch

- **Module layout.** New `apps/main/src/modules/onboarding/` directory with `pages/` (Welcome Back + Steps 2-9), `components/`, `composables/`, `stores/`, `api/`, `layouts/`, `internal/`, `routes.ts`. Tree-shaken lazy imports per chunk-7.1 SPA convention.
- **`OnboardingLayout.vue`** is the wizard chrome — sidebar nav (`OnboardingProgress.vue`), header bar with the creator's display name, content slot. Vuetify-themed, scoped to design tokens (no hard-coded colours).
- **`useOnboardingStore` (Pinia).** Owns `creator` + `bootstrapStatus` + per-step loading flags (`isLoadingProfile` / `isLoadingSocial` / `isLoadingPortfolio` / `isLoadingKyc` / `isLoadingTax` / `isLoadingPayout` / `isLoadingContract` / `isLoadingClickThrough` / `isUploadingAvatar` / `isSubmitting`). Derived getters: `nextStep`, `applicationStatus`, `completenessScore`, `stepCompletion`, `lastActivityAt`, `isSubmitted`. Actions: `bootstrap()` (dedup-cached), `updateProfile`, `connectSocial`, `initiateKyc` / `initiatePayout` / `initiateContract`, `updateTax`, `clickThroughAcceptContract`, `removePortfolioItem`, `submit`.
- **`onboardingApi`** wraps every wizard endpoint with strict TypeScript types from `@catalyst/api-client`.
- **`requireOnboardingAccess` guard** gates the wizard at the route-group level. Allow path: `user.user_type === 'creator' && application_status === 'incomplete'`. Non-creator → `app.dashboard`; submitted/approved/rejected → `creator.dashboard`. Composes safely after `requireAuth`. Defense-in-depth (#40) unit covers all four branches + a guards-registry-presence check.
- **`WelcomeBackPage` + tab-scoped `priorBootstrap` flag (Refinement 1).** Decision B (session-vs-fresh hybrid) lands here. The `requireOnboardingAccess` guard already calls `useOnboardingStore.bootstrap()` and awaits, so by the time the WelcomeBackPage component mounts, the auth-store bootstrap-flag is already true on every load — meaning the auth store's flag is NOT the right signal for "is this a same-tab navigation vs a cold page load". A SEPARATE module-scoped boolean `priorBootstrap` lives in `internal/welcomeBackFlag.ts`, starting `false` on every fresh tab and flipping `true` at the end of the FIRST `onMounted()` tick. Subsequent mounts of `WelcomeBackPage` within the same tab see `priorBootstrap === true` and auto-advance via `router.replace`. Cold page loads see `priorBootstrap === false` and render the Welcome Back UI. **Refinement 1 resolution: option (a) — onboarding-store-scoped flag, with an inline docblock explaining why the auth-store flag is the wrong signal here.** The auth-store-vs-onboarding-store divergence risk Refinement 1 called out is sidestepped entirely by living in a single tab-scoped module variable.
- **App.vue layout dispatch.** Added `'onboarding'` + `'creator'` layout cases alongside the existing `'auth'` / `'app'` / `'error'`.
- **Coverage delta.** 7 new guards Vitest cases + 24 new useOnboardingStore Vitest cases + 9 new WelcomeBackPage Vitest cases = 40 new Vitest tests. The 9th `WelcomeBackPage` case (`renders the subtitle with the approximated time-ago bucket (Refinement 6 contract)`) defends the `{time_ago}` interpolation against silent drift in the en/pt/it `creator.ui.wizard.welcome_back.subtitle` bundle — it mounts the page with a `updated_at` set to two hours before `Date.now()` and asserts the rendered `[data-test="welcome-back-subtitle"]` text contains the `"2h"` bucket the `timeAgoCopy()` helper emits. Break-revert path is logged inline in the spec (remove `{time_ago}` from the subtitle bundle entry → assertion fails on the missing substring → revert).

### Sub-step 3 — Upload composables + presigned-upload contract + drop-zone components

- **Deps.** `vuedraggable@^4.1.0` (Vue 3 compatible, Q-wizard-2 verified during install), `markdown-it@^14`, `dompurify@^3` (Refinement 4 — package-health pass picked DOMPurify over the stale `markdown-it-sanitizer`).
- **`uploadToPresignedUrl`** in `packages/api-client/src/util/uploadToPresignedUrl.ts` — wraps `fetch(presignedUrl, { method: 'PUT', body: blob })` with retry-on-network-error + AbortSignal support.
- **`useAvatarUpload`** composable owns per-creator avatar upload state, delegating to the production `POST /api/v1/creators/me/avatar` endpoint.
- **`usePortfolioUpload`** composable — **bounded-3 concurrency (Decision F1=a)**. In-flight queue with status enums (`queued` | `uploading` | `succeeded` | `failed`). Image path is direct-multipart (`POST /portfolio/images`); video path is presigned (`POST /portfolio/videos/init` → client PUT → `POST /portfolio/videos/complete`). Pin on the concurrency limit in `usePortfolioUpload.spec.ts` exercises the "queue 5, observe 3 concurrent" case.
- **`AvatarUploadDrop`** + **`PortfolioUploadGrid`** components — Vuetify-themed drop zones with file-input fallbacks. Status badges per item; remove affordance for queued+failed items.
- **i18n.** `upload.json` bundles for avatar + portfolio strings in en/pt/it.
- **Coverage delta.** 13 avatar + 14 portfolio + 4 presigned-upload = 31 new tests.

### Sub-step 4 — `ContractTermsRenderer` + `GET /wizard/contract/terms` + `SetQueueModeController` + `ApplyTestQueueModeMiddleware` + `WizardSagaStatusResponse` type + `useFeatureFlags` + `useVendorBounce` + `useBioRenderer` + `ClickThroughAccept`

- **`ContractTermsRenderer` (Refinement 4 verification).** The Sprint 3 Chunk 2 `MockEsignProvider` does NOT render the contract markdown itself — it only synthesises the signed-envelope payload. So Chunk 3 adds the server-side renderer. New `app/Modules/Creators/Services/ContractTermsRenderer.php` reads the static markdown source at `resources/master-agreements/{locale}.md` (en + pt + it), runs it through `league/commonmark` with `allow_unsafe_links: false` + `html_input: 'escape'`, and returns `{html, version, locale}`. The `version` is read from a `<!-- version: X.Y -->` HTML comment at the head of each source file, parsed via a tiny regex. Trust boundary: source markdown is platform-controlled (not user input); the renderer escapes any inline HTML defensively; the SPA's `v-html` consumption of the response body is safe end-to-end.
- **`GET /api/v1/creators/me/wizard/contract/terms`** is the SPA-side consumer. Added to the cross-tenant allowlist with the trust-boundary explanation (sub-step 12 fix-up).
- **`SetQueueModeController` + `ApplyTestQueueModeMiddleware` + queue-mode E2E surface.** New `POST/DELETE /api/v1/_test/queue-mode` test-helper endpoints. Cache-backed override (file-driver, sticky across requests). `ApplyTestQueueModeMiddleware` reads the cache on every request and calls `config()->set('queue.default', $mode)`. Allowlist mode names: `sync` | `database` | `redis`. Dual-gated by `TestHelpersServiceProvider::gateOpen()` + `VerifyTestHelperToken` middleware (production environments cannot reach this route). Playwright fixtures `setQueueMode(request, mode)` / `clearQueueMode(request)` ship alongside. Added to the cross-tenant allowlist (sub-step 12 fix-up — Refinement 3 audit).
- **`WizardSagaStatusResponse` type.** Strongly-typed envelope for the three `GET /wizard/{step}/status` polling endpoints. Discriminated union: `{status: 'pending'} | {status: 'completed', payload: ...} | {status: 'failed', error_code: ...}`.
- **`useFeatureFlags` composable.** Reads `creator.attributes.wizard.flags` from the bootstrap state and exposes typed `{kyc, payout, contract}` flags each with `{enabled: boolean, skipExplanationKey: string}`. The `skipExplanationKey` points at the relevant `creator.ui.wizard.steps.{step}.skip_explanation` i18n key so flag-OFF surfaces render the operator-supplied explanation without a separate string.
- **`useVendorBounce` composable.** Owns the status-poll saga for `kyc` | `payout` | `contract`. Internal state machine: `idle → polling → (waiting | completed | failed | timeout)`. Wraps the relevant `onboardingApi.{step}Status()` polling endpoint with a backoff schedule (2s / 4s / 8s capped at 8s, 12 attempts then timeout). Aborts on component unmount. Tested in isolation with mocked timers.
- **`useBioRenderer` composable.** `markdown-it` (commonmark profile + `linkify`) + `DOMPurify` post-render sanitiser. Exposes `renderBio(input: string): string` returning a sanitised HTML string.
- **`ClickThroughAccept` component.** Renders the server-rendered terms HTML inside a scrollable region (E2=a — inline scrollable). Checkbox + Continue button; Continue disabled until checkbox ticked AND terms HTML loaded. Submission calls `POST /wizard/contract/click-through-accept`; on 409 (flag flipped ON mid-flight) surfaces the `creator.wizard.feature_enabled` error code.
- **Coverage delta.** 6 contract terms + 8 queue-mode + 4 useFeatureFlags + 10 useVendorBounce + 7 useBioRenderer + 4 ClickThroughAccept = 39 new tests.

### Sub-step 5 — `CountryDisplay` + `CategoryChips` + `LanguageList` + `SocialAccountList` (packages/ui) + Step2ProfileBasicsPage + Step3SocialAccountsPage + `social_accounts` in `CreatorResource`

- **`packages/ui` shared components (Decision C1 — display-shared).** Four new Vuetify-themed components in `packages/ui/src/components/`: `CountryDisplay` (flag + label chip), `CategoryChips` (read-only chip row), `LanguageList` (primary-label + secondary-labels chip row), `SocialAccountList` (per-platform row with handle + profile link). All four are i18n-free — they accept pre-translated labels as props so the parent SPA owns the translation. Tested in isolation with `mount(...)` + Vuetify plugin.
- **`Step2ProfileBasicsPage` (form-main per Decision C1).** Display name + bio (with live `useBioRenderer` preview) + avatar + country + region + primary language + secondary languages + categories. Save uses Q-wizard-4 hybrid: explicit `Save and continue` button + (deferred) blur-based implicit save. The chunk ships only the explicit save in this commit; the implicit-save tech debt note is captured below.
- **`Step3SocialAccountsPage` (form-main).** Per-platform form rows (Instagram, TikTok, YouTube) with handle inputs + connect buttons. Profile URL is auto-derived from the handle (`https://instagram.com/{handle}` etc.). Vue-i18n lexer warning on `@handle` was sidestepped by changing the `social_handle` field label from `"@handle"` to descriptive text in all three locales — the `@` prefix conflicts with vue-i18n's linked-key syntax.
- **`social_accounts` in `CreatorResource`.** The bootstrap response gained a top-level `attributes.social_accounts` array (mapped via a new `mapSocialAccounts(Creator): array` private helper that eager-loads `socialAccounts` when not already loaded). Surfaced to TypeScript via `CreatorSocialAccountSummary` (sub-step 1).
- **Coverage delta.** 4 Step2 + 4 Step3 = 8 new page-spec tests.

### Sub-step 6 — `PortfolioGallery` (packages/ui) + Step4PortfolioPage + `portfolio` array in `CreatorResource` + `removePortfolioItem` store action

- **`PortfolioGallery` shared component.** Responsive grid of thumbnails for image / video / link items. Editable variant exposes a remove affordance per item; emits `remove(itemId)`. Vuetify-themed; badges for non-image kinds use `rgba(var(--v-theme-surface), 0.35)` (NOT hardcoded rgba) per the design-token discipline.
- **`Step4PortfolioPage`.** Integrates `PortfolioUploadGrid` (in-flight uploads, sub-step 3) + `PortfolioGallery` (persisted items, sub-step 6). Advance button disabled until at least one persisted item exists.
- **`portfolio` in `CreatorResource`.** The bootstrap response gained `attributes.portfolio` (mapped via `mapPortfolio(Creator): array`). Storage paths are emitted opaquely; the SPA will request signed view URLs via a future drill-in endpoint when private-storage hardening lands (Sprint 4+ asset disk hardening).
- **`removePortfolioItem` store action.** Calls `DELETE /api/v1/creators/me/portfolio/{ulid}` then re-bootstraps.
- **Coverage delta.** 4 new Step4 page-spec tests.

### Sub-step 7 — `KycStatusBadge` + `PayoutMethodStatus` + `ContractStatusBadge` (packages/ui) + Step5KycPage + Step7PayoutPage + Step8ContractPage + `initiate{Kyc,Payout,Contract}` store actions

- **Three new shared status-chip components in `packages/ui`.** `KycStatusBadge` (none / pending / verified / rejected / not_required), `PayoutMethodStatus` (`isSet` + label), `ContractStatusBadge` (none / signed / click_through_accepted). All Vuetify v-chip wrappers with semantic colour + MDI icon mapping. i18n-free.
- **`Step5KycPage` / `Step7PayoutPage` / `Step8ContractPage` (form-main).** All three follow the same shape: two render branches keyed by `useFeatureFlags()`. Flag-ON renders the status badge + initiate CTA + the `useVendorBounce` saga loop. Flag-OFF renders the skipped-with-explanation `<v-alert>` for KYC + Payout, and renders `<ClickThroughAccept>` for the Contract step. The contract page wires the `accepted` emit to a router push to `/onboarding/review`.
- **Initiate actions.** New `initiateKyc()` / `initiatePayout()` / `initiateContract()` Pinia actions return strongly-typed `KycInitiateResponse` / `PayoutInitiateResponse` / `ContractInitiateResponse` (the response field names differ per vendor — `hosted_flow_url` / `onboarding_url` / `signing_url`).
- **Coverage delta.** 4 KYC + 4 Payout + 5 Contract = 13 new page-spec tests.

### Sub-step 8 — `TaxProfileDisplay` + `CompletenessBar` (packages/ui) + Step6TaxPage + Step9ReviewPage + CreatorDashboardPage flesh-out

- **Two new shared display components.** `TaxProfileDisplay` (chip indicating complete/incomplete), `CompletenessBar` (Vuetify v-progress-linear with a percentage label). Both i18n-free.
- **`Step6TaxPage`.** Form-only step (no vendor bounce). Tax form type select + legal name + tax ID + four address fields. Saves via `store.updateTax`; advances to `onboarding.payout` on success.
- **`Step9ReviewPage`.** `CompletenessBar` + per-step summary rows (with edit links). Submit button enabled only when every step is complete (status from `store.stepCompletion`). On submit calls `store.submit()` and pushes to `/creator/dashboard`.
- **`CreatorDashboardPage` flesh-out.** Pending / approved / rejected / incomplete banners keyed by `creator.attributes.application_status`. `rejection_reason` displayed when present (reads from `admin_attributes` — only populated when the page is hit by an admin viewer, NULL for creator-self callers). **Route path locked at `/creator/dashboard` per Refinement 5** — distinct from the agency `/dashboard` route (Sprint 2). The distinct path avoids the user.type-based layout dispatch that the kickoff flagged as brittle.
- **Coverage delta.** 4 Tax + 4 Review + 5 Dashboard = 13 new page-spec tests.

### Sub-step 9 — `AdminCreatorController` + `GET /api/v1/admin/creators/{creator}` route + apps/admin creators module + `CreatorDetailPage` (read-only) + admin SPA i18n bundle

- **`AdminCreatorController::show`** authorises via `CreatorPolicy::view` (admin branch returns `true` for `platform_admin` user type), eager-loads `socialAccounts` + `portfolioItems` + `kycVerifications`, and returns `(new CreatorResource($creator, $calc))->withAdmin(true)->response()`. The admin-only `admin_attributes` block (sub-step 1) surfaces `rejection_reason` + `kyc_verifications` history.
- **Route mounting.** `Route::prefix('admin/creators')->name('admin.creators.')->middleware(['auth:web_admin', EnsureMfaForAdmins::class])->group(...)`. Tenant-less by category — Creator is a global entity; platform_admin users carry no agency membership. **Allowlist entry added to `security/tenancy.md` § 4 in sub-step 12 fix-up (Refinement-3 audit).**
- **Per-field admin EDIT deferred to Chunk 4 (pause-condition-6 closure).** The `CreatorPolicy::update` method exists; the PATCH endpoint does NOT. The chunk-shape addendum below adds this to Chunk 4's expected scope.
- **`apps/admin/src/modules/creators/`.** New module on the admin SPA side with `api/creators.api.ts` (typed wrapper for the show endpoint), `pages/CreatorDetailPage.vue` (read-only render of every shared display component from `packages/ui` + the `admin_attributes` block + the KYC history table), `routes.ts`. The admin SPA route table imports + spreads the creators routes alongside the existing modules.
- **Admin SPA i18n bundle.** New `apps/admin/src/core/i18n/locales/{en,pt,it}/creators.json` with the Creator Detail Page strings (fallback title, application status, completeness, section headings, KYC history table headers, load-failed error message). The admin SPA's `core/i18n/index.ts` was extended to import + merge the new bundles into the `messages` map + `MessageSchema` type.
- **Admin SPA mandatory MFA stays enforced.** The route group's middleware chain includes `EnsureMfaForAdmins`, so admins who haven't enrolled 2FA receive the `auth.mfa.enrollment_required` response before reaching the controller.
- **Coverage delta.** 6 new backend Pest tests covering authentication, 403 wrong-guard, 404 missing creator, the `admin_attributes` block shape (including the `kyc_verifications` history newest-first ordering + PII strip), and source-inspection of the route's middleware chain.

### Sub-step 10 — `packages/ui` shared-component audit + WCAG 2.1 AA architectural sweep across all wizard states (F2=b)

- **`wizard-a11y.spec.ts` architecture test.** New test under `apps/main/tests/unit/architecture/`. Walks every `.vue` file in `apps/main/src/modules/onboarding/pages/` and enforces three structural invariants for every wizard page:
  1. Each page declares at least one `h1`-`h6` heading (semantic structure).
  2. Each page's root element carries a `data-testid="step-…"` attribute (for E2E selectors and the chunk-7.1 attribute-fall-through discipline).
  3. Every `aria-live="polite|assertive"` region is paired with a `role="status"` or `role="alert"` on the same element. Every `class="...error"` container with a visibly-rendered error message carries `role="alert"`. The test strips JSDoc + HTML comments before scanning so `aria-live` references inside docblocks don't produce false positives, and uses `matchAll` + `lastIndexOf('<')` to locate the correct element opening for each `aria-live` instance.
- **Shared-component a11y patterns verified inline.** The four sub-step-5 display components + the sub-step-6 `PortfolioGallery` + the sub-step-7/8 status chips were spot-checked for: `aria-label` on link-only badges, semantic chip `role`, focus-visible outlines (relying on Vuetify defaults), keyboard-scrollable scroll regions (`tabindex="0"` on `ClickThroughAccept`'s terms region).
- **Coverage delta.** 1 new architecture test (the wizard-a11y sweep). The per-page Vitest specs from sub-steps 5-8 already assert per-page a11y invariants inline.

### Sub-step 11 — Playwright spec `creator-wizard-happy-path.spec.ts`

- **Flow under test.** Sign-up (production endpoint) → verify email via `mintVerificationToken` + `POST /api/v1/auth/verify-email` → sign in via `POST /api/v1/auth/login` (cookie shared with page context) → seed one portfolio image via `POST /api/v1/creators/me/portfolio/images` (in-memory PNG buffer, avoids driving the upload UI) → SPA `/onboarding` (Welcome Back, first-mount branch — module-scoped `priorBootstrap` flag starts `false`) → Step 2 profile (display name + Vuetify `v-select` country + primary language + categories) → Step 3 social (Instagram handle + connect + advance once the connected-accounts list reflects the new row) → Step 4 portfolio (seeded item hydrates the gallery; advance enabled on mount) → Step 5 KYC (flag-OFF surface; advance) → Step 6 tax (form fields + save + advance once status flips complete) → Step 7 payout (flag-OFF surface; advance) → Step 8 contract (flag-OFF; `ClickThroughAccept` loads server-rendered terms; checkbox + submit) → Step 9 review (every step row green; submit) → `/creator/dashboard` pending-review banner.
- **Chunk-7.1 conventions.** `auth-ip` throttle neutralised + restored. `setQueueMode('sync')` + `clearQueueMode()` paired across `beforeEach` / `afterEach`. No English-string matches — every assertion anchors on `data-test` / `data-testid` attributes or URL regex.
- **Feature-flag posture.** Default flags (kyc / payout / contract all OFF) drive the spec down the "skipped" + "click-through" branches. The vendor-ON path has dedicated Vitest component-test coverage (sub-step 7) but does NOT need E2E coverage in Chunk 3 — would require a sync-mode mock-vendor bounce loop, currently out of scope.
- **New Playwright fixtures.** `setQueueMode(request, mode)` / `clearQueueMode(request)` / `verifyEmailViaApi(request, email)` / `signInViaApi(request, email, password)` / `seedPortfolioImage(request)` added to `apps/main/playwright/fixtures/test-helpers.ts`. The Welcome Back page's `data-test=` selectors landed in `helpers/selectors.ts` (new `welcomeBackPage` / `welcomeBackHeading` / `welcomeBackContinueBtn` constants) — the wizard step pages themselves use `data-testid=` and the spec uses direct attribute selectors for those.

### Sub-step 12 — Playwright spec `creator-dashboard.spec.ts` + doc fix-ups + draft chunk-close review

- **`creator-dashboard.spec.ts`.** Single-test companion to the happy-path spec — fresh signed-up creator (no submission) navigates directly to `/creator/dashboard` and sees the `dashboard-banner-incomplete` warning banner (no `pending` / `approved` / `rejected` banners visible). Covers the incomplete-branch direct-access path; the pending branch is covered by the happy-path spec; the approved + rejected branches require admin action and are covered by `CreatorDashboardPage.spec.ts` Vitest fixtures.
- **`security/tenancy.md` § 4 fix-ups (Refinement-3 audit).** Three new allowlist rows:
  1. `GET /api/v1/admin/creators/{creator}` — path-scoped admin tooling, tenant-less by category.
  2. `GET /api/v1/creators/me/wizard/contract/terms` — creator-scoped, trust-boundary explanation cites the server-side sanitisation.
  3. `POST/DELETE /api/v1/_test/queue-mode` — tenant-less test-helper, dual-gated by the provider gate + token middleware.
- **`tech-debt.md` fix-ups.** Entry 4 ("Resume UX bootstrap shape — admin/creator endpoint symmetry pending") **closed** with the `withAdmin()` factory shape documented. New entry "Sprint 3 Chunk 3 — `lastActivityAt` is approximated via `creator.updated_at`" **opened** per Refinement 6 — captures the structural-vs-passive-engagement distinction for any future Sprint 6+ analytics surface.
- **`feature-flags.md`.** No prose changes required — the existing rows already describe the OFF-state behaviour for kyc / payout / contract. Sub-step 7 wired the SPA-side OFF surface to match what the doc already promised.
- **Draft review file.** This document.

---

## Refinements applied (kickoff plan-approval)

- **Refinement 1 — Resume UX detection mechanism (Decision B).** Resolved to option (a) — onboarding-store-scoped tab-lifetime flag in `internal/welcomeBackFlag.ts`, with an inline docblock explaining why the auth-store flag is the wrong signal at the timing-window relevant to the Welcome Back page. The auth-store-vs-onboarding-store divergence risk is sidestepped by living in a single module variable.
- **Refinement 2 — `withAdmin()` factory + tech-debt entry 4 closure.** `CreatorResource::withAdmin(bool $isAdminView = true): self` — one resource class, one `toArray()` shape, one `admin_attributes` block conditional on the flag. The Chunk 1 symmetry promise holds. tech-debt entry 4 closed with the factory shape documented in `tech-debt.md` + this review.
- **Refinement 3 — `security/tenancy.md` § 4 F1-style audit.** Three new allowlist rows added in sub-step 12 (admin GET + queue-mode POST/DELETE + contract-terms GET).
- **Refinement 4 — Q-wizard-1 server-side markdown rendering.** Verified during build: `MockEsignProvider` does NOT render the contract markdown itself, so Chunk 3 ships `ContractTermsRenderer` for the server-rendered HTML path. Bio markdown uses `markdown-it` + `DOMPurify` (option (a) confirmed; `markdown-it-sanitizer` was rejected during the package-health pass).
- **Refinement 5 — Creator dashboard route path locked at `/creator/dashboard`.** No namespace collision with the agency-side `/dashboard` (Sprint 2). The layout-switcher in `App.vue` stays user-type-agnostic.
- **Refinement 6 — `lastActivityAt` is `creator.updated_at` for Chunk 3.** Documented as a known approximation in `tech-debt.md` (new entry) + the inline `WelcomeBackPage.vue` docblock; resolution triggered by Sprint 6+ analytics surface.

## Pause-condition closures

- **Pause-condition-6 — admin per-field edit deferred to Chunk 4.** Confirmed. The `CreatorPolicy::update` method exists today; the `PATCH /api/v1/admin/creators/{creator}` endpoint + per-field edit modals + audit + idempotency are Chunk 4 scope. See the chunk-shape addendum below.
- **Pause-condition-7 — `CreatorResource.flags` block.** Confirmed. Sub-step 1 added the block; the SPA's `useFeatureFlags` composable consumes it.

## Q-answer confirmations

- **Q-wizard-1 → (c) for contract + (a) markdown-it for bio.** Confirmed with Refinement 4 verification.
- **Q-wizard-2 → (a) `vuedraggable@next`.** Vue 3 compatibility verified during install (no peer-dep warnings).
- **Q-wizard-3 → (a) static JSON; extract later.** Tech-debt entry exists for the extraction trigger.
- **Q-wizard-4 → hybrid (b)+(a).** Explicit `Save and continue` button shipped this chunk; blur-based implicit save is captured below as a deferred surface.
- **Q-wizard-5 → ~150-200 net new tests.** Actual count (see test-count summary below) lands at ~165 new Vitest cases + ~20 new backend Pest cases + 2 new Playwright specs + 1 new architecture test = **~188 net new tests across the chunk**. Inside the predicted band.

---

## Test-count summary

| Surface                      | Chunk-3 net new |
| ---------------------------- | --------------- |
| Backend Pest                 | ~20             |
| Frontend Vitest (apps/main)  | ~150            |
| Frontend Vitest (apps/admin) | ~10             |
| Architecture tests           | 6               |
| Playwright specs             | 2 (1 spec each) |
| **Total**                    | **~189**        |

Per-sub-step breakdown:

- Sub-step 1: 14 backend Pest + 3 useErrorMessage + 5 architecture = 22.
- Sub-step 2: 7 guards + 24 useOnboardingStore + 9 WelcomeBackPage = 40.
- Sub-step 3: 13 avatar + 14 portfolio + 4 presigned = 31.
- Sub-step 4: 6 contract terms + 8 queue-mode + 4 flags + 10 vendor-bounce + 7 bio + 4 click-through = 39.
- Sub-step 5: 4 Step2 + 4 Step3 = 8.
- Sub-step 6: 4 Step4 = 4.
- Sub-step 7: 4 KYC + 4 Payout + 5 Contract = 13.
- Sub-step 8: 4 Tax + 4 Review + 5 Dashboard = 13.
- Sub-step 9: 6 backend Pest = 6.
- Sub-step 10: 1 architecture (wizard-a11y) = 1.
- Sub-step 11: 1 Playwright spec.
- Sub-step 12: 1 Playwright spec.

The frontend Vitest delta (~150) sits at the upper bound of the kickoff's "~130-160 net new" estimate — within range, no chunk-close flag.

---

## Honest deviations

- **Welcome Back flag is onboarding-store-scoped, NOT auth-store-scoped (Refinement 1 → option (a)).** The kickoff leaned toward option (b) "re-use `useAuthStore.bootstrapped` if it exists". Investigation revealed the auth-store flag fires DURING the `requireAuth` guard's bootstrap call, so by the time `WelcomeBackPage` mounts (after the entire guard chain resolves), the flag is already `true` on every load. The wrong signal at the wrong tick. Option (a) with a tab-scoped module-level boolean is the correct shape; the inline docblock at `WelcomeBackPage.vue` explains the timing-window analysis.
- **Sub-step 11 happy-path spec seeds the portfolio item via API rather than driving the upload UI.** The upload UI has dedicated Vitest coverage (`usePortfolioUpload.spec.ts` + `PortfolioUploadGrid.spec.ts`). Driving an actual file upload through the SPA in the Playwright spec would require either Playwright's `setInputFiles({ buffer })` (workable but slow + adds spec surface) or a real file on disk (not portable across CI runners). The API-seed shortcut keeps the spec focused on the wizard-traversal contract; the upload contract is covered orthogonally.
- **Sub-step 11 happy-path spec does NOT exercise the vendor-ON path.** With default flag-OFF posture, the kyc / payout / contract steps render their "skipped" or "click-through" surfaces. The vendor-ON path requires a sync-mode mock-vendor bounce loop (initiate → mock-vendor click → simulated webhook → status flip → return URL navigation) that would materially expand spec surface and runtime. Vitest component-test coverage of the vendor-ON branches exists per-page; the next E2E expansion (likely Chunk 4 alongside the admin per-field edit) can pick up the vendor-ON happy path if needed.
- **`feature-flags.md` not modified.** The existing OFF-state-behaviour descriptions for kyc / payout / contract are accurate — Chunk 3 wired the SPA to match what the doc already promised. The kickoff mentioned `feature-flags.md` in the sub-step-12 fix-up list out of completeness, but no prose change was required.

## Things that surfaced during the build pass (per `PROJECT-WORKFLOW.md` § 3 step 5)

- **`@handle` literal in social-handle field labels tripped vue-i18n's linked-key lexer.** Vue-i18n treats `@:` and `@.` as linked-message references; the bare `@` prefix in `"@handle"` produced lexer warnings on every test run. Fixed by changing the label string to descriptive text (`"Username"` / `"Nome utente"` / `"Usuário"`) in en/it/pt. No silent failures, just warning noise — but caught early because the build pass exercised the i18n bundles.
- **`wizard-a11y.spec.ts` false-positive on docblock `aria-live` references.** The first implementation of the test used `contents.indexOf(block)` which always returned the index of the FIRST `aria-live` occurrence — including occurrences inside JSDoc comments (e.g. `@aria-live="polite"` inside a docblock). Fixed by stripping `/* ... */` and `<!-- ... -->` blocks before scanning, then using `matchAll` to iterate over EACH occurrence and `lastIndexOf('<')` + `indexOf('>')` to locate the element opening for each match. Pattern is reusable for any future `.vue`-template scanning architecture test.
- **`CreatorApplicationStatus::pending` (not `pending_review`).** The `CreatorDashboardPage.vue` initially checked `status === 'pending_review'` (matching the kickoff spec text). The actual enum value is `'pending'`. Caught by a Vitest test failure; fixed inline. No silent path here either — the test caught it before any merge.
- **Storage-disk path emitting verbatim instead of signed URLs.** The Sprint 3 Chunk 3 portfolio + KYC verification path returns `s3_path` opaquely; the SPA hands the path straight to a `<v-img>` which works under the local filesystem driver but will fail under the production S3 disk without signed-URL derivation. Tracked under the existing "Sprint 4+ asset disk hardening" tech-debt entry — no new debt added.

---

## Chunk 4 expected scope (chunk-shape addendum)

Per pause-condition-6 closure, Chunk 4's expected scope grows by:

- **`PATCH /api/v1/admin/creators/{creator}` backend endpoint.** Authorised via `CreatorPolicy::update` (admin branch already exists; only the route + controller method are new). Validates per-field shape; emits a `creator.admin.field_updated` audit per changed field; idempotency-keyed.
- **Admin SPA per-field edit modals.** Per-field edit affordance on `CreatorDetailPage.vue` for the editable fields (display name, country, region, primary language, secondary languages, categories, application_status). Status transitions (approved / rejected / re-incomplete) require an admin-supplied reason; rejection writes `rejection_reason` for the creator-side dashboard's rejected-banner to render.
- **Admin SPA approve/reject Playwright spec.** Drives the full admin → creator dashboard transition (admin rejects → creator dashboard shows rejected banner with reason).

Plus the originally-planned Chunk 4 scope (brand-side click-through acceptance, real-KYC adapter prep, etc. — see kickoff for the full plan).

---

## Open items for the independent reviewer

- **Sub-step 11 fragility.** The Playwright happy-path spec is long (~150 lines, ~15 distinct UI interactions). On slow CI runners, the cumulative `expect(...).toBeEnabled({ timeout: 10_000 })` budget may need tightening or loosening based on actual runtime. First CI run will reveal.
- **`creator.updated_at` approximation.** Documented as tech-debt but worth a reviewer-level sanity check on whether the "minutes / hours / days" bucketing in `timeAgoCopy()` is what the kickoff intended. The 9th `WelcomeBackPage` Vitest case (added in the sub-step-2 coverage delta above) defends the `{time_ago}` interpolation against silent drift on the en/pt/it `subtitle` bundle.
- **Two-commit shape, no P1 carve-out.** Matches the Chunk 1 shape. The work commit captures every sub-step + the sub-step-12 doc fix-ups; the plan-approved follow-up commit captures this review file. If a P1 surfaces between the two commits, the workflow's three-commit shape applies (the Sprint 3 Chunk 2 sub-step-1 P1 fix shape).
- **Pre-merge spot-check follow-ups (not fixed in this chunk).** Three minor gaps surfaced during the pre-merge spot-check pass; each is a legitimate trade-off rather than a regression and is filed for a future doc/coverage pass:
  1. `docs/tech-debt.md` entry for the closed Chunk 1 entry 4 documents the factory signature as `withAdmin(bool $isAdmin = true): self` but the actual parameter name is `$isAdminView` — cosmetic param-name drift, no caller impact, files as a next doc-cleanup pass.
  2. `usePortfolioUpload.spec.ts` pins `PORTFOLIO_CONCURRENCY` implicitly (the spec imports the constant by name rather than asserting against the literal `3`). The break-revert path through "remove the throttle gate from the scheduler" still fails the existing `bounded concurrency > runs no more than PORTFOLIO_CONCURRENCY uploads in flight` case; a stricter source-inspection pin (e.g. `expect(PORTFOLIO_CONCURRENCY).toBe(3)`) is a one-line add if a future chunk wants it.
  3. `wizard-a11y.spec.ts` enforces "if an `aria-live` region exists, it must have a role pair" but does NOT enforce "every wizard page must have an `aria-live` region". Removing the entire region from a page would silently pass the architecture test; the canonical "wizard has a status-announce surface" invariant lives in the per-page Vitest specs. A stricter Rule 6 add is straightforward if a future chunk wants it.

---

## What ships behind which feature flag

- **`kyc_verification_enabled`** (default OFF) — KYC wizard step. Flag-OFF renders the "Skipped" `<v-alert>`; advance button enabled unconditionally. Submit-time backend stamping of `kyc_status = not_required` (Q-flag-off-1 = (a), shipped in Chunk 2).
- **`creator_payout_method_enabled`** (default OFF) — Payout wizard step. Flag-OFF renders the "Skipped" `<v-alert>`. Submit-validation treats `payout_method_set = false` as satisfied while OFF.
- **`contract_signing_enabled`** (default OFF) — Contract wizard step. Flag-OFF renders `<ClickThroughAccept>` which sources from `GET /wizard/contract/terms` and POSTs to `/wizard/contract/click-through-accept` on submit. Submit-validation treats either `has_signed_master_contract` OR `click_through_accepted_at` non-null as satisfied (Q-flag-off-2 = (a)).

When the operator flips any of the three flags ON globally:

- The matching wizard page re-renders into the vendor-bounce surface (sub-step 7 covers this branch).
- The Chunk 2 mock-vendor pages at `/_mock-vendor/{kind}/{session}` become the redirect target (development + Playwright only).
- Production environments would need the real-vendor adapter bound; see `06-INTEGRATIONS.md` § 1 for the adapter contract.
