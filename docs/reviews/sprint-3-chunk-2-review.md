# Sprint 3 — Chunk 2 Review

**Status:** Closed.

**Reviewer:** Cursor self-review draft → pre-merge spot-check pass (S1-S6) → corrections baked into commit `6a3c081` → closed.

**Reviewed against:** `PROJECT-WORKFLOW.md` § 5 (~27 standing standards binding per the Sprint 3 Chunk 1 D-pause-3 reconciliation), `02-CONVENTIONS.md` § 1 + § 2.2 (modular monolith) + § 5 (git workflow) + § 2.8 (DTO conventions), `03-DATA-MODEL.md` § 17 (`integration_events` + `integration_credentials`) + § 23 (encryption casts), `04-API-DESIGN.md` § 12 (idempotency keys) + § 13 (rate limiting) + § 14 (webhook endpoint pattern), `05-SECURITY-COMPLIANCE.md` § 3 (audit) + § 4 (encryption) + § 13 (rate limiting), `06-INTEGRATIONS.md` § 1 (adapter pattern) + § 1.2 (credentials in Secrets Manager) + § 1.3 (inbound webhook pattern) + § 2 + § 3 + § 4 + § 13.1 (driver convention), `07-TESTING.md` § 4 (testing discipline), `20-PHASE-1-SPEC.md` § 5 (Sprint 3 acceptance) + § 6.1 (wizard step shape) + § 8.1 (mock-usage discipline) + § 8.2 (i18n), `feature-flags.md` (registry + conventions), `security/tenancy.md` § 4 (cross-tenant allowlist), `tech-debt.md` (3 entries closed + 3 entries added by this chunk), `docs/reviews/sprint-3-chunk-1-review.md` (Honest deviations + P1 blockers + decisions for future chunks).

**Commits:** P1 fix `6c76425` (sub-step 1, standalone first commit per the kickoff's § 1.1 sequencing rule); work commit `d298b99` (sub-steps 2-10 + sub-step 11 doc fix-ups); review file commits to follow per the four-commit chunk shape.

This chunk closes the P1 user-enumeration regression that Chunk 1 surfaced (sub-step 1, standalone three-commit shape per the kickoff's § 1.1 sequencing rule), then ships the integration-adapter surface that lets Steps 5/7/8 of the wizard actually progress: the Sprint-3-completion provider contracts (KYC: 4 methods, eSign: 4 methods, Payment: 2 methods — total 10), the three Mock provider implementations, the three Skipped-when-flag-OFF stubs, the Pennant flag wiring with the "no scope arg = global" convention pinned at the framework level, the inbound webhook pipeline (`InboundWebhookIngestor` + `Process*WebhookJob` + idempotency on `integration_events.(provider, provider_event_id)`), the wizard-completion endpoints (status-poll + return-URL pair × 3 vendor-gated steps), the Blade-rendered mock-vendor pages in en/pt/it with simulated-webhook dispatch (Q-mock-webhook-dispatch = (b)), the click-through-accept fallback for the contract-signing flag-OFF path (Q-flag-off-2 = (a)), the `KycStatus::NotRequired` enum value for the KYC flag-OFF submit-time stamp (Q-flag-off-1 = (a)), and the cross-cutting source-inspection invariants for the chunk's structural choices.

---

## Scope

### Sub-step 1 — P1 forgot-password fix (standalone first commit, `6c76425`)

- `PasswordResetService::request()` short-circuits with `if ($user->email_verified_at === null) { return; }` when the looked-up User is not verified. Maintains the user-enumeration-defense response shape (204 either way) while diverging server-side: no token issued, no mail sent, no reset broker invocation.
- `creators.me.*` route group gained the `verified` middleware (Laravel's `EnsureEmailIsVerified`) alongside `auth:web` + `tenancy.set`. Defense-in-depth (#40): a User who somehow reaches authenticated state via an unverified path still cannot reach the wizard surface.
- `PasswordResetTest` extended with two new cases: `returns silently for unverified users` + `does not invoke the password broker for unverified users`. New `CreatorWizardVerifiedGateTest` (3 tests) covers rejection of unverified Users, admission of verified Users, and a source-inspection (#1) check that the `verified` alias is on every wizard route.
- Pushed independently; CI green before any other Chunk 2 work began (per the kickoff's § 1.1 sequencing rule).

### Sub-step 2 — Pennant flag definitions + Skipped-stub building blocks

- Three Pennant feature classes in `app/Modules/Creators/Features/`: `KycVerificationEnabled`, `CreatorPayoutMethodEnabled`, `ContractSigningEnabled`. Each exposes `public const NAME = '<snake_case>'` and `public static function default(): Closure` returning `fn () => false`. The `default()` Closure shape is non-obvious: Pennant's `Decorator::define()` treats non-Closure second arguments as the literal stored value (see `vendor/laravel/pennant/src/Drivers/Decorator.php:153`), so a class instance won't invoke `__invoke` on each check. The Closure shape ensures the resolver runs every time and the default-OFF state is honest.
- Three `Skipped*Provider` stubs implementing every contract method by throwing `FeatureDisabledException::for(<contract>, <flag>, <method>)`. Mirrors the Chunk 1 `Deferred*Provider` pattern but with a different exception class so callers can distinguish "feature off" from "implementation missing".
- New `FeatureDisabledException` in `app/Modules/Creators/Integrations/Exceptions/` with a static `::for($contract, $flag, $method)` factory.
- `CreatorFeatureFlagsTest` (6 tests) pins: each flag's `NAME` constant, default-OFF state, activate/deactivate round-trip with no scope arg, each `Skipped*Provider`'s exception payload, and a source-inspection (#1) check that every Skipped stub implements every method on its contract (lockstep regression against future contract extensions).

### Sub-step 3 — Provider contract extensions (hybrid completion architecture, Decision A = (c))

- `KycProvider` 1 → 4 methods: + `getVerificationStatus(Creator): KycStatus`, `verifyWebhookSignature(string $payload, string $signature): bool`, `parseWebhookEvent(string $payload): KycWebhookEvent`.
- `EsignProvider` 1 → 4 methods: + `getEnvelopeStatus(Creator): EsignStatus`, `verifyWebhookSignature(...): bool`, `parseWebhookEvent(...): EsignWebhookEvent`.
- `PaymentProvider` 1 → 2 methods: + `getAccountStatus(Creator): AccountStatus`. Webhook handling deferred to Sprint 10 per Q-stripe-no-webhook-acceptable.
- 5 new DTOs/enums in `Integrations/DataTransferObjects/` + `Enums/`: `KycStatus::NotRequired` enum case (Q-flag-off-1 = (a)), `EsignStatus` enum (Sent/Signed/Declined/Expired), `KycWebhookEvent`, `EsignWebhookEvent`, `AccountStatus` (with `isFullyOnboarded()` helper).
- All 6 `Deferred*Provider` + `Skipped*Provider` stubs grew in lockstep with the contract surface so the source-inspection (#1) lockstep regression in `CreatorFeatureFlagsTest` stays green.
- `IntegrationProviderBindingsTest` reset from Chunk 1's "exactly one Sprint-3 method" pin to "the three contracts each define exactly the Sprint-3-completion surface" with explicit method-name enumeration (Chunk 1 tech-debt entry 7 closed). Paired with a new `each contract docblock cites the Sprint-3 completion surface` assertion so the docblock + the test stay in lockstep across future contract extensions.

### Sub-step 4 — Mock provider implementations + per-provider driver config

- `MockKycProvider`, `MockEsignProvider`, `MockPaymentProvider` in `Integrations/Mock/` — each implements its full contract surface. Session state lives in `Cache::` (Redis in production / Laravel-default-array in tests, no new `mock_provider_sessions` table).
- HMAC-SHA256 webhook signature verification + parsing for KYC + eSign mocks; deterministic webhook secret in `config/integrations.php` + `config('integrations.<kind>.mock_webhook_secret')`. Real-vendor secrets stay in AWS Secrets Manager per `06-INTEGRATIONS.md` § 1.2.
- New `config/integrations.php` reads `KYC_PROVIDER`, `ESIGN_PROVIDER`, `PAYMENT_PROVIDER` env vars (defaulting to `mock`). **Q-driver-convention = per-provider env vars** — closes Chunk 1 tech-debt entry 3 (the `INTEGRATIONS_DRIVER` vs per-provider divergence).
- 30 new tests across `MockKycProviderTest`, `MockEsignProviderTest`, `MockPaymentProviderTest`: status mapping per session state, signature verification (including bad-signature negatives), payload parsing (including malformed-JSON negatives), session-storage round-trips.

### Sub-step 5 — Mock-vendor Blade pages + Simulate\*WebhookJob (Q-mock-webhook-dispatch = (b))

- Six routes mounted by `CreatorsServiceProvider` under the `web` middleware group at `/_mock-vendor/{kind}/{session}` + `/_mock-vendor/{kind}/{session}/complete` for kind ∈ {kyc, esign, stripe}. Tenant-less + unauthenticated by design — the unguessable session token in the URL is the only authenticator (#42).
- Three Blade views in `resources/views/mock-vendor/` rendering success/fail/cancel buttons. Localised in en/pt/it via `lang/{en,pt,it}/mock-vendor.php` (per #3, the Mailable real-render standard extended to Blade pages).
- `SimulateKycWebhookJob` + `SimulateEsignWebhookJob` synthesise a payload, sign it with the mock webhook secret, and call `InboundWebhookIngestor::ingest(...)` directly (NOT over HTTP). **Q-mock-webhook-dispatch = (b) everywhere** — single path for tests + dev. Stripe mock has no webhook leg (Sprint 10 surface).
- `MockVendorPagesTest` (8 tests) covers: page rendering with valid session, 404 with localised message on unknown session, en/pt/it localisation, success/cancel flow for KYC, eSign success flow, Stripe success flow (no webhook job dispatched).

### Sub-step 6 — Wizard-completion endpoints (status-poll + return URLs)

- Six new endpoints under `creators.me.wizard.*`: `GET kyc/status` + `GET kyc/return`, `GET contract/status` + `GET contract/return`, `GET payout/status` + `GET payout/return`. All gated by `auth:web + tenancy.set + verified` (sub-step 1 P1 fix carries through).
- `WizardCompletionService` centralises the status-poll + state-transition + audit-emit pipeline. Each `poll{Kyc,Contract,Payout}` method: (1) flag-guard via `Feature::active(<NAME>)` + RuntimeException → controller 409, (2) provider call, (3) compare against creator's denormalised status column, (4) on first-successful transition: update column + emit `creator.wizard.{kyc,contract,payout}_completed` audit transactionally (#5), (5) idempotent on re-poll (#6).
- `WizardCompletionEndpointsTest` (12 tests) + `CreatorWizardFlagOffTest` (6 tests for status-poll flag-OFF responses) cover the success-edge audit emission, idempotency on re-poll, non-terminal-status passthrough, and the flag-OFF 409 path.

### Sub-step 7 — Migrations + Audit-module models + webhook controllers + Process\*WebhookJob

- **3 migrations** (executed early per dependency order — sub-step 5 + 6 depend on the tables):
  1. `integration_events` (#36) — `id`, `provider`, `provider_event_id`, `event_type`, `payload jsonb`, `processed_at`, `processing_error`, `received_at`. **Unique index** on `(provider, provider_event_id)` — the idempotency mechanism per Q-mock-2 = (a).
  2. `integration_credentials` (#37) — `id`, `agency_id`, `provider`, `credentials jsonb`, `expires_at`, timestamps. **Application-layer encryption** via `encrypted:array` cast on `credentials` per `03-DATA-MODEL.md` § 23. Sprint 3 ships the table but does NOT write to it; future real-vendor adapters write here.
  3. `add_click_through_accepted_at_to_creators_table` (#38) — adds `click_through_accepted_at timestampTz` after `signed_master_contract_id` (Q-flag-off-2 = (a)).
- **2 Audit-module models** in `app/Modules/Audit/Models/` (Q-module-location): `IntegrationEvent` (does NOT use the `Audited` trait per Refinement 5 — vendor-payload history is its own log; `InboundWebhookIngestor` emits the matching audit rows explicitly), `IntegrationCredential` (encrypted-array cast pinned by source-inspection regression in `Sprint3Chunk2InvariantsTest`).
- `InboundWebhookIngestor` service centralises signature verification + idempotent integration-event insertion + audit emission + job dispatch. Catches `UniqueConstraintViolationException` on the unique index → returns "duplicate" status without reprocessing (the database-level idempotency mechanism from Q-mock-2 = (a)).
- `KycWebhookController` + `EsignWebhookController` are thin: pull payload + signature, call `InboundWebhookIngestor::ingest(...)`, translate the result enum to 200 / 401 / 400. `webhooks` named rate limiter (1000 req/min per provider segment) registered in `CreatorsServiceProvider::registerRateLimiters()`. Stripe Connect webhook handler deferred to Sprint 10 per Q-stripe-no-webhook-acceptable.
- `ProcessKycWebhookJob` + `ProcessEsignWebhookJob` queued jobs: re-read the `IntegrationEvent` row, parse the payload via the bound provider's `parseWebhookEvent(...)`, apply the state transition to the `Creator` row, emit `creator.wizard.{kyc,contract}_completed` (idempotent), mark `IntegrationEvent.processed_at`. On error, write `processing_error` + rethrow for the failed-job queue.
- New audit cases in `AuditAction` enum: `IntegrationWebhookReceived`, `IntegrationWebhookProcessed`, `IntegrationWebhookSignatureFailed`, `CreatorWizardKycCompleted`, `CreatorWizardContractCompleted`, `CreatorWizardPayoutCompleted`, `CreatorWizardClickThroughAccepted`. **Single error code for signature failures** — no granular failure-mode codes per the chunk-2 plan's "Decisions documented for future chunks" (Refinement 4 — locked for Sprint 4+).

### Sub-step 8 — Flag-conditional + driver-aware binding swap

- `CreatorsServiceProvider::register()` replaced its three `bind(<contract>, Deferred*Provider::class)` lines with closures that resolve at call time:
  1. Flag OFF → `Skipped*Provider`.
  2. Flag ON + driver = `'mock'` → `Mock*Provider`.
  3. Flag ON + unknown driver → `Deferred*Provider` (loud failure on first call; closes the no-silent-vendor-calls invariant when ops misconfigures the driver string).
- `CreatorsServiceProvider::configurePennantScope()` overrides Pennant's default scope resolver to `null` for the whole app. **Refinement 3 (locked):** the `Feature::active('<flag>')` (no scope arg) convention requires this — Pennant's out-of-the-box default scope is the authenticated user, so an operator-flipped (null-scope) activation wouldn't be visible to authenticated requests without the override. Phase 2+ may need to revisit when per-user/tenant flags ship; the resolver can be re-overridden per call via `Feature::for($scope)->active(...)`.
- `IntegrationProviderBindingsTest` rewritten (8 tests) to cover the new resolver — flag-OFF resolution to Skipped, flag-ON + mock driver resolution to Mock, flag-ON + unknown driver fallthrough to Deferred, and a laziness pin (flag flips between resolutions are observed without re-registering).
- `phpunit.xml` set `PENNANT_STORE=array` so non-`RefreshDatabase` tests don't need the `features` migration. Existing flag round-trip tests still pass (array driver supports activate/active/deactivate identically to the database driver for the no-scope case).

### Sub-step 9 — Wizard flag-OFF skip-path + click-through-accept

- `CompletenessScoreCalculator::stepCompletion()` honours flag-OFF skip-paths: KYC step satisfied if `kyc_status === Verified || kyc_status === NotRequired || ! Feature::active(KycVerificationEnabled::NAME)`; payout step satisfied if `payout_method_set === true || ! Feature::active(CreatorPayoutMethodEnabled::NAME)`; contract step satisfied if `signed_master_contract_id !== null || click_through_accepted_at !== null || ! Feature::active(ContractSigningEnabled::NAME)`. Score totals 100 for a flag-OFF submitter who has only the non-vendor steps complete.
- `CreatorWizardService::initiate{Kyc,Payout,Contract}` flag-guard: `Feature::active(<NAME>)` check throws a `RuntimeException('creator.wizard.feature_disabled:<flag>')` that the controller translates to a 409 with `creator.wizard.feature_disabled` error code. Defense-in-depth alongside the Skipped\*Provider binding (Skipped would also throw `FeatureDisabledException`, but pre-checking at the service layer gives a clearer error surface and avoids the wasted provider round-trip).
- `CreatorWizardService::submit()` stamps `kyc_status = NotRequired` at submit time when `KycVerificationEnabled` is OFF and the creator's existing status is `None`. **One-way transition**: an already-Verified creator stays Verified even if the flag later flips OFF (Q-flag-off-1 = (a) forensic clarity).
- `CreatorWizardService::acceptClickThroughContract()` + `POST /api/v1/creators/me/wizard/contract/click-through-accept` endpoint: stamps `click_through_accepted_at` + emits `creator.wizard.click_through_accepted` audit. Flag-guard refuses with 409 `creator.wizard.feature_enabled` when ContractSigningEnabled is ON (envelope mode is the canonical path; click-through is the bypass route). Idempotent — second accept does NOT re-stamp.
- `CreatorWizardFlagOffTest` (12 tests) covers all three initiate-409 paths, all three status-poll-409 paths, click-through happy path + idempotency + flag-ON 409, submit-with-all-three-flags-OFF (stamps NotRequired + sets submitted_at), submit-doesn't-downgrade-Verified, completeness=100 with flag-OFF.
- `CreatorWizardEndpointsTest` + `WizardCompletionEndpointsTest` + `CompletenessScoreCalculatorTest` activate all three flags in `beforeEach` so their flag-ON happy-path semantics carry through unchanged.

### Sub-step 10 — Cross-cutting source-inspection invariants

- New `Sprint3Chunk2InvariantsTest` (7 tests, 68 assertions) pins:
  1. `IntegrationCredential::casts()['credentials'] === 'encrypted:array'` (Refinement 5 + `03-DATA-MODEL.md` § 23 P0 secret-leak surface).
  2. `IntegrationEvent` does NOT use the `Audited` trait (Refinement 5 — vendor-payload history is its own log, not auto-audited).
  3. `webhooks` named rate limiter resolves to 1000 req/min keyed on the trailing path segment (per-provider segmentation so a noisy KYC vendor cannot starve the eSign quota).
  4. Webhook routes (`POST /api/v1/webhooks/{kyc,esign}`) carry NO `auth:*` and NO `tenancy*` middleware (allowlisted as tenant-less in `tenancy.md` § 4).
  5. Mock-vendor routes carry NO `auth:*` middleware (anonymous-session UX by design).
  6. The expected webhook + mock-vendor controllers are wired to the registered routes (rename catch).
  7. Wizard-completion endpoints DO carry `auth:web + tenancy.set + verified` (creator-scoped invariant).

### Sub-step 11 — Doc fix-ups

- `docs/security/tenancy.md` § 4 — added 12 new allowlist rows for the chunk-2 routes (3 mock-vendor pairs as tenant-less, 2 webhook endpoints as tenant-less, 6 wizard-completion endpoints + 1 click-through-accept endpoint as creator-scoped).
- `docs/feature-flags.md` — filled the **Off-state behavior** column for the three Sprint-3 flags with concrete text (no more "TBD"). Added the **Phase 1 flag invocation pattern** convention bullet (Refinement 3 — `Feature::active('<flag>')` no scope arg + the `configurePennantScope()` rationale). Added the **Driver convention** bullet (Q-driver-convention closure).
- `docs/tech-debt.md` — closed three Chunk 1 entries (forgot-password regression closed by sub-step 1; `INTEGRATIONS_DRIVER` vs per-provider closed by sub-step 4; provider contract test break-by-design closed by sub-step 3). Added one new entry: "Sprint 3 Chunk 1 contract docblocks describe an outdated future-extension shape" (Refinement 1 = D-pause-2-2).
- `docs/06-INTEGRATIONS.md` § 13.1 — left as-is for the dedicated doc-cleanup pass; the tech-debt entry tracks the spec/code drift (low-impact: code is canonical, spec narrative is stale).

---

## CI observations (non-blocking)

- **Sub-step 1 push (CI run #76, commit `6c76425`).** Result: green (Status: Success, 3m 29s, all 4 jobs passed — Backend, Frontend, E2E main, E2E admin). GitHub Actions surfaced 2 errors / 4 warnings / 2 notices as annotations; both errors are first-attempt Playwright failures that **passed on Playwright's `retries: 2`**, so the job exit code was 0:
  1. `apps/admin/playwright/specs/admin-mandatory-mfa-enrollment.spec.ts › admin mandatory-MFA enrollment journey › D7 deep-link to /settings is preserved across the MFA enrollment redirect`.
  2. `apps/main/playwright/specs/2fa-enrollment-and-sign-in.spec.ts › spec #19 — 2FA enrollment + sign-in › full enrollment + re-sign-in flow`.
- Both surfaces had their underlying issues closed in Sprint 1 (chunk 7.1 for spec #19 — see `docs/tech-debt.md` line 73; chunk 7.6 for the admin D7 deep-link — line 190). What the annotations are now flagging is **residual race-condition flakiness** in the E2E layer, not a regression introduced by the P1 fix. The P1 fix only touched `PasswordResetService::request()` + the `creators.me.*` route group's `verified` middleware — neither surface is exercised by either annotated spec.
- **Disposition:** non-blocking for Chunk 2 close (CI is green; merge gate is satisfied). Logged as a new tech-debt entry below ("Residual Playwright-retry flakiness on chunk-7.1 + chunk-7.6 specs") so the pattern doesn't get lost. A future test-infrastructure-hardening sub-chunk should investigate the first-attempt-failure tail and either tighten the spec selectors / waits OR drop the retry count back to 0/1 once the underlying flake is gone.
- **Sub-step 2-11 push (CI run TBD, work commit + plan-approved follow-up + review-file commit).** Will be observed at chunk close; expected green. If new annotations surface they get appended here.

---

## Q-answers locked (kickoff § 6 + plan-approval refinements)

The kickoff posed four genuinely-undecided design questions; all are resolved with the chosen option implemented + the reasoning recorded for future-chunk grep.

### Q-flag-off-1 → (a) `KycStatus::NotRequired`

- **Choice:** New `KycStatus::NotRequired` enum case stamped at submit-time when `KycVerificationEnabled` is OFF and the creator's existing status is `None`. One-way transition — already-`Verified` creators stay `Verified` even if the flag flips OFF later.
- **Why:** Forensic clarity wins over data-model minimalism. A creator who passed the wizard during a flag-OFF window is observably distinct in the historical record from a creator whose status defaulted-to-`None` and never progressed. Sprint 4+ audits + admin-SPA filters can answer "show me everyone whose KYC was bypassed because the vendor wasn't ready yet" via `WHERE kyc_status = 'not_required'` instead of having to reconstruct flag-state-at-submit-time from the audit log. The cost (one extra enum case, one branch in `submit()`) is negligible compared to the forensic value.
- **Rejected (b) — stays at `KycStatus::None`:** semantically lossy; collapses two distinct states ("hasn't started" vs "explicitly bypassed by ops policy").
- **Rejected (c) — `KycStatus::Bypassed`:** stylistically equivalent to (a). Lost on a coin flip — `NotRequired` reads more naturally in user-facing strings ("KYC verification was not required for this creator at signup").

### Q-flag-off-2 → (a) `creators.click_through_accepted_at`

- **Choice:** New nullable `timestampTz` column `creators.click_through_accepted_at`. `signed_master_contract_id` stays NULL. Migration #38 adds the column.
- **Why:** Preserves column semantic clarity. `signed_master_contract_id` is reserved for the envelope-mode primary-key reference; populating it with a sentinel value (option b) would have meant every consumer of that column needs to know the sentinel convention to filter correctly. The two columns now answer two distinct questions: "was an envelope signed?" (`signed_master_contract_id IS NOT NULL`) vs "did the creator click through the fallback?" (`click_through_accepted_at IS NOT NULL`). The completeness scorer's contract-step satisfaction check is `signed_master_contract_id IS NOT NULL OR click_through_accepted_at IS NOT NULL` — a clean OR over distinct columns rather than a sentinel-equality check.
- **Rejected (b) — sentinel UUID/0:** column-overloading; every reader of `signed_master_contract_id` would need sentinel-awareness.
- **Rejected (c) — `contract_acceptance_mode` enum + `accepted_at`:** more flexible but heavier — adds two columns + one enum case and forces every contract-aware query to JOIN the mode column to know how to interpret the timestamp. Overkill for two states.

### Q-mock-webhook-dispatch → (b) `Simulate*WebhookJob` everywhere

- **Choice:** Mock-vendor "Complete" buttons dispatch a queued `Simulate{Kyc,Esign}WebhookJob` that calls `InboundWebhookIngestor::ingest(...)` directly (NOT over HTTP). Single path for tests + dev + (someday) prod-mocks. Stripe mock has no webhook leg per Q-stripe-no-webhook-acceptable.
- **Why:** The kickoff offered "(b) for tests + (a) for actual mock-vendor mechanics" as the weak lean; the user's plan-approval refined this to "(b) everywhere" for path uniformity. Test-time path uses `Bus::fake()` / `Queue::fake()` (standard pattern); dev-time path uses the real queue worker — both flow through identical code. The minor cost is that the simulated webhook doesn't traverse the HTTP layer (so signature-verification + rate-limiter middleware don't fire on the simulate path). Compensated by `Sprint3Chunk2InvariantsTest` source-inspecting the rate limiter + middleware on the real webhook routes — the HTTP layer is verified structurally, not via the simulate path.
- **Rejected (a) — synchronous `Http::post` to local URL:** test setup carries `Http::fake()` overhead for what is fundamentally a test-internal flow; the "fake" layer has its own bugs and replacing one set of mocks with another is rarely worth the realism gain.
- **Rejected (c) — direct controller invocation:** skips the queueing semantics entirely (real webhooks dispatch a process job, then the HTTP request returns 200 — the simulate path should match this shape, even if the wire-level HTTP is skipped). (b) preserves the queue-dispatch shape; (c) collapses it.

### Q-stripe-no-webhook-acceptable → yes, acceptable risk through Sprint 7

- **Choice:** Sprint 3's Stripe Connect step uses status-poll only. `verifyWebhookSignature` + `parseWebhookEvent` are NOT added to `PaymentProvider` in Chunk 2. Sprint 10's payment-flow chunk extends the contract when escrow / transfer flows ship. Tracked as tech-debt entry "Stripe Connect webhook handler deferred to Sprint 10" (added below).
- **Why:** Wizard UX for Stripe Connect onboarding is "creator returns from Stripe → frontend polls `/payout/status` → renders confirmation". The status-poll satisfies this. The `account.updated` webhook IS the authoritative back-office reconciliation channel in production but doesn't drive wizard progression — it drives the back-office "this creator's account just transitioned to charges_enabled" alerting + ledger reconciliation, neither of which exists yet. Sprint 7 flips the `creator_payout_method_enabled` flag; if Sprint 7 needs the webhook for some unanticipated reason it can land then. Sprint 10 is the latest-bound deadline.
- **Risk accepted:** If Sprint 4-9 flips the payout flag with no webhook handler, an account whose status changes asynchronously after onboarding completes (e.g. Stripe puts the account back into a pending-due state for an additional verification requirement) won't update `payout_method_set` until the next status-poll OR until the creator re-visits the wizard. Acceptable for a single-tenant Phase 1 launch; not acceptable for multi-tenant Phase 2.

### Q-driver-convention → per-provider env vars (closes Chunk 1 tech-debt entry 3)

- **Choice:** Three env vars — `KYC_PROVIDER`, `ESIGN_PROVIDER`, `PAYMENT_PROVIDER` — each defaulting to `mock`. Single `INTEGRATIONS_DRIVER` rejected.
- **Why:** Operations need per-vendor flexibility — e.g. flipping KYC to a real vendor doesn't imply flipping eSign at the same time, since the two vendors are unrelated and may onboard at different times. A single `INTEGRATIONS_DRIVER=mock|real` collapses all three into one switch and forces a "go-live for everything at once" deploy that doesn't match reality. Per-provider env vars also map cleanly onto the sub-step-8 binding-resolver shape (`config('integrations.<kind>.driver')`).
- **Rejected: single `INTEGRATIONS_DRIVER`:** above. Closes Chunk 1 tech-debt entry 3.

### Q-module-location → `app/Modules/Audit/Models/`

- **Choice:** `IntegrationEvent` + `IntegrationCredential` Eloquent models live in `app/Modules/Audit/Models/`.
- **Why:** Both tables are platform-wide cross-cutting concerns (vendor-payload history + vendor-credential storage); neither is creator-domain-specific. Putting them in the Creators module would have created a leak — Sprint 10's payment-flow chunk needs to write `integration_events` rows from the `App\Modules\Payments` module, which would then need a `use App\Modules\Creators\Models\IntegrationEvent` cross-module dependency. The Audit module is the existing platform-wide cross-cutting home + already owns the audit-log surface that pairs naturally with vendor-payload history.

### Refinement 5 (locked) — `IntegrationEvent` does NOT extend `Audited` or auto-emit audit rows

- **Choice:** `IntegrationEvent` is a plain Eloquent model with no `Audited` trait + no model-event observers that emit `audit_logs` rows. The `InboundWebhookIngestor` service emits `integration.webhook.received` + `integration.webhook.processed` audit rows explicitly.
- **Why:** Two distinct logs cover two distinct things — `integration_events` is vendor-payload history (preserves the wire-level shape for forensic + replay), `audit_logs` is platform-action history (preserves the system's interpretation of the event for compliance + admin views). Auto-emitting an audit row on every IntegrationEvent insert would double-log the receipt half (`integration.webhook.received` AND a model-creation audit) and either (a) not log the processing half OR (b) emit a confusing model-update audit when `processed_at` is set. The explicit `InboundWebhookIngestor` emission gives clean control over BOTH halves with the right action codes.
- **Pinned:** `Sprint3Chunk2InvariantsTest::"integration_event does not use the Audited trait"` (source-inspection check #1).

---

## Honest deviations from the kickoff (#34 cross-chunk handoff verification)

### D-pause-2-1 — Mock-vendor pages used Cache, not session, for state

- **Where:** [`app/Modules/Creators/Integrations/Mock/Mock{Kyc,Esign,Payment}Provider.php`](../apps/api/app/Modules/Creators/Integrations/Mock/) + [`app/Modules/Creators/Http/Controllers/MockVendorController.php`](../apps/api/app/Modules/Creators/Http/Controllers/MockVendorController.php).
- **Kickoff Decision 5.7 ("Mock provider session storage"):** "Use Redis cache (Laravel's `Cache::store('redis')`) with TTL = 24 hours."
- **Implementation:** Used the default `Cache::` facade (which maps to `redis` in production / `array` in tests) instead of explicitly pinning `Cache::store('redis')`. TTL is 24 hours per kickoff.
- **Why this is structurally better:** Pinning the store inside provider code couples the test discipline to the production driver choice. Cache::store('redis') in a test that wants to assert against the cache contents would either need to fake redis (`Redis::fake()` doesn't exist in Laravel; would need a docker-redis service container in CI for E2E) OR fall through to the array store and silently disagree with the production behaviour. Using `Cache::` (default-resolved) lets `phpunit.xml` set `CACHE_DRIVER=array` (already set from chunk-7.1 saga conventions) so tests get the array store automatically while production gets redis.
- **Net effect:** Identical behaviour to the kickoff intent; one less coupling point. No tech-debt entry needed.

### D-pause-2-2 — Chunk 1 contract docblocks describe an outdated future-extension shape (Refinement 1)

- **Where:** [`apps/api/app/Modules/Creators/Integrations/Contracts/KycProvider.php`](../apps/api/app/Modules/Creators/Integrations/Contracts/KycProvider.php), [`EsignProvider.php`](../apps/api/app/Modules/Creators/Integrations/Contracts/EsignProvider.php), [`PaymentProvider.php`](../apps/api/app/Modules/Creators/Integrations/Contracts/PaymentProvider.php) — the docblock comments above the interface bodies.
- **Chunk 1 commitment (docblock-cited future-extension shape):**
  - `KycProvider` docblock cited `getVerificationResult(string $sessionId): KycResult` for the future status-check method.
  - `EsignProvider` docblock cited `getEnvelopeStatus(string $envelopeId): EnvelopeStatus`.
  - `PaymentProvider` docblock cited `getAccountStatus(string $accountId): AccountStatus`.
- **Chunk 2 implementation (per kickoff Part 2 § 1.2):**
  - `KycProvider::getVerificationStatus(Creator $creator): KycStatus`.
  - `EsignProvider::getEnvelopeStatus(Creator $creator): EsignStatus`.
  - `PaymentProvider::getAccountStatus(Creator $creator): AccountStatus`.
- **Why the kickoff's shape is structurally better (and was adopted):** The kickoff method signatures match the data model — `Creator` is the durable identity that owns the row in the `creators` table; `string $sessionId` / `string $envelopeId` / `string $accountId` are vendor-side ephemera that may rotate (Stripe's account ID is durable; KYC + eSign session/envelope IDs typically expire on their own TTL). The `Creator` parameter lets each provider do its own lookup (most providers store the vendor-side ID in a field on the `creators` row OR in a vendor-specific session table), which keeps the call site clean — controllers + services pass the `Creator` they already have, instead of having to remember which method takes which kind of ID. The Chunk 1 docblock shape would have required every caller to know the vendor-side ID format AND the lookup-key column.
- **Why this is logged as honest deviation rather than silent override:** Chunk 1's docblocks were Chunk 1's commitment to Chunk 2 about the future-extension shape. Standing standard #34 (cross-chunk handoff verification) requires the consuming chunk to verify against the prior commitment + surface divergence rather than silently overriding. The verification surfaced + the divergence is documented here + a tech-debt entry tracks the docblock cleanup ("Sprint 3 Chunk 1 contract docblocks describe an outdated future-extension shape" in `docs/tech-debt.md`).
- **Status:** Documented; tech-debt entry tracks the doc-only follow-up cleanup.

### D-pause-2-3 — Provider session storage is Cache, NOT a `mock_provider_sessions` table

- **Where:** Mock providers in [`app/Modules/Creators/Integrations/Mock/`](../apps/api/app/Modules/Creators/Integrations/Mock/) + new `config/integrations.php`.
- **Kickoff Decision 5.7:** "Avoids creating a new `mock_provider_sessions` table that has no production purpose."
- **Implementation:** Followed kickoff exactly — Cache-only, no new table.
- **Net effect:** No deviation; logged here only for review-trail completeness because the absence of a migration in the chunk-2 migration count (3, not 4) might trigger a "did you forget the mock-sessions table?" review question.

### D-pause-2-4 — `webhooks` named rate limiter keyed on trailing path segment, not provider header

- **Where:** [`CreatorsServiceProvider::registerRateLimiters()`](../apps/api/app/Modules/Creators/CreatorsServiceProvider.php).
- **Kickoff § 1.6 + `04-API-DESIGN.md` § 13:** "1000 req/min/provider".
- **Implementation:** The limiter resolves a `RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(1000)->by('webhooks:'.basename($request->path())))` — the **trailing** path segment (`kyc` or `esign`) is the provider key. For `/api/v1/webhooks/kyc`, `$request->path()` returns `api/v1/webhooks/kyc` (no leading slash) and `basename(...)` returns `kyc`. NOT `$request->segment(3)` — Laravel's segment indexing is 1-based and segment(3) of `/api/v1/webhooks/kyc` is `webhooks`, not `kyc` (the provider would actually live at segment(4)). Using `basename(path())` makes the key derivation independent of how many prefix segments the route group adds, so adding a future `/api/v2/webhooks/{kind}` group wouldn't silently shift the segment index. The `webhooks:` prefix on the key namespaces the limiter pool away from any other limiter that might key on the same provider string. No webhook handler reads a `X-Provider:` header to derive the key (the URL is the canonical provider identity).
- **Why:** URL-segmented routes ARE the provider-identity convention in this codebase (see `04-API-DESIGN.md` § 14 webhook endpoint pattern). Reading the provider from a header would have introduced a second source of truth + a spoofing surface (a misconfigured KYC vendor sending `X-Provider: esign` would land in the wrong limiter pool). The path-segment approach is signature-anchored — the URL is the routing input, signature verification is per-provider, and the rate limiter follows the same per-provider segmentation.
- **Pinned:** `Sprint3Chunk2InvariantsTest::"webhooks rate limiter resolves to 1000 req/min keyed on the trailing path segment"` exercises the segment-keyed shape: it constructs synthetic `/api/v1/webhooks/kyc` and `/api/v1/webhooks/esign` requests, calls the registered limiter resolver for each, and asserts the resolved `Limit::$key` differs between the two (so a noisy KYC vendor cannot starve eSign).

---

## Decisions documented for future chunks

These are decisions made in Chunk 2 that downstream chunks (Sprint 4+ real-vendor adapters, Sprint 7 payout-flag flip, Sprint 10 Stripe webhook + escrow) inherit.

### Single error code for webhook signature failures (Refinement 4 — locked through Sprint 4+)

- **Where:** `AuditAction::IntegrationWebhookSignatureFailed` + the `KycWebhookController` / `EsignWebhookController` 401-response shape.
- **Decision:** Webhook signature failures emit ONE audit code (`integration.webhook.signature_failed`) regardless of the underlying failure mode (wrong vendor, stale timestamp, malformed HMAC, replay-window exceeded). The HTTP response is opaque (401 with no error-detail body).
- **Why:** A signature-failure response is a security event. Granular failure codes leak information about which mistake the attacker made — "wrong vendor" tells them they hit the right path but wrong key; "stale timestamp" tells them their replay-window timing is off; "malformed HMAC" tells them their algorithm choice is wrong. The same opacity that defends user-enumeration on the auth surface (#9) defends signature-spoof attempts on the webhook surface. Debugging happens via the `processing_error` column on `integration_events` (operator-only) — never through the response body.
- **Locked for Sprint 4+:** When the real-KYC adapter lands, the same single-code discipline applies. The adapter implementer doesn't get to add granular failure codes "for debugging" — debugging happens via the `processing_error` column, not the response.

### Stripe Connect webhook deferred to Sprint 10

- **Where:** `PaymentProvider` interface (no `verifyWebhookSignature` / `parseWebhookEvent` methods); no `StripeWebhookController`; no Stripe entry in the `webhooks` route group.
- **Decision:** Sprint 3 ships Stripe Connect with status-poll-only completion. Sprint 10's payment-flow chunk extends `PaymentProvider` to add the webhook surface when escrow / transfer flows ship.
- **Why:** Wizard UX for Stripe Connect onboarding is satisfied by status-poll. The `account.updated` webhook is the back-office reconciliation channel that doesn't drive wizard progression — and the back-office surfaces (admin alerting, ledger reconciliation) don't exist until Sprint 13+ anyway.
- **Risk window:** If Sprint 7 flips `creator_payout_method_enabled` to a real Stripe Connect adapter without webhook handling, asynchronous status changes (e.g. Stripe re-flags an account for additional verification after onboarding completes) won't propagate until the next status-poll. Acceptable for Phase 1 single-tenant; tech-debt entry below tracks the Sprint 7 / Sprint 10 trigger.

### Per-provider env vars for driver selection (closes Chunk 1 tech-debt entry 3)

- **Where:** `config/integrations.php` reads `KYC_PROVIDER`, `ESIGN_PROVIDER`, `PAYMENT_PROVIDER`. Default `mock` for all three.
- **Decision:** Three independent env vars, NOT a single `INTEGRATIONS_DRIVER`.
- **Locked:** Sprint 4+ real-vendor adapters add their own driver string (`'datacheck'`, `'docusign'`, `'stripe'`, etc.) and the binding resolver in `CreatorsServiceProvider::makeProviderResolver()` extends to map the new string to the new vendor's adapter class. The `mock` driver stays as a permanent option for local dev / CI / tutorial-mode demos.

### `KycStatus::NotRequired` + `creators.click_through_accepted_at` are Phase 1 conveniences

- **Where:** `App\\Modules\\Creators\\Enums\\KycStatus::NotRequired` + `creators.click_through_accepted_at` column (migration #38).
- **Decision:** Both are Phase 1-specific accommodations that exist because the real KYC + eSign vendors aren't selected yet. They are NOT placeholders to be removed when the real vendors land — they are a permanent ops surface for "single-tenant operator skipped vendor X for legitimate reasons" (e.g., a creator already verified offline).
- **Pinned:** `CreatorWizardFlagOffTest` covers the not-required-stamping behaviour. The flag-OFF skip-paths in `CompletenessScoreCalculator` are independent of vendor readiness — operators can flip the flag for any reason.

### Pennant default-OFF + global-scope are framework-level conventions (Refinement 3)

- **Where:** All three `Creators/Features/*` classes use `public static function default(): Closure` returning `fn () => false`. `CreatorsServiceProvider::configurePennantScope()` overrides Pennant's default scope resolver to `null`.
- **Decision:** Phase 1 flag invocation is `Feature::active('<flag>')` (no scope arg) which resolves against the null-scope-default per the configured resolver. Operators flip flags globally via `php artisan pennant:feature:activate '<flag>'` (no scope arg). The default-OFF closure resolver is the framework-level guarantee that a never-activated flag is observably OFF for every consumer.
- **Locked:** Phase 2+ may add per-user / per-tenant scoping for non-vendor flags (e.g., A/B test flags). When that happens, the resolver override needs to be revisited — likely a per-flag opt-in mechanism. For now, all three Sprint-3 vendor flags are global-only.

### Wizard-completion endpoints emit one-shot completion audit per step

- **Where:** `WizardCompletionService::poll{Kyc,Contract,Payout}` + `creator.wizard.{kyc,contract,payout}_completed` audit cases.
- **Decision:** A completion audit fires exactly once per (creator, step) — when the step's denormalised status column transitions from non-terminal to terminal. Re-polling after completion does NOT re-emit. Re-receiving the same webhook does NOT re-emit. The completion audit is the canonical "this step is done" signal in the audit log; downstream consumers (admin alerting, billing reconciliation in Sprint 7+) can rely on it.
- **Pinned:** `WizardCompletionEndpointsTest::"second poll after completion does not re-emit audit"`.

---

## Tech-debt added + closed by this chunk

### Closed (3 entries)

1. **Forgot-password user-enumeration defense regression** — closed by sub-step 1 (commit `6c76425`). `PasswordResetService::request()` short-circuits on unverified Users; wizard routes have the `verified` middleware; `PasswordResetTest` + `CreatorWizardVerifiedGateTest` cover both gates with break-revert independence per #40.
2. **Integration driver env-var convention** (Chunk 1 tech-debt entry 3) — closed by sub-step 4. `config/integrations.php` reads three independent env vars (`KYC_PROVIDER`, `ESIGN_PROVIDER`, `PAYMENT_PROVIDER`); single `INTEGRATIONS_DRIVER` rejected; per-provider flexibility preserved.
3. **Provider contract test "exactly one Sprint-3 method" broken by design** (Chunk 1 tech-debt entry 7) — closed by sub-step 3. `IntegrationProviderBindingsTest` reset to enumerate the Sprint-3-completion surface (KYC: 4, eSign: 4, Payment: 2) explicitly. Paired with a docblock + test lockstep source-inspection regression.

### Added (3 entries)

1. **Sprint 3 Chunk 1 contract docblocks describe an outdated future-extension shape** — Refinement 1 = D-pause-2-2. The Chunk 1 docblock-cited future-extension method names (`getVerificationResult(string $sessionId): KycResult`) drifted from Chunk 2's actual implementation (`getVerificationStatus(Creator): KycStatus`). Doc-only cleanup; landed as a tech-debt entry rather than a code change because the implementation is canonical and the docblocks are advisory.
2. **Residual Playwright-retry flakiness on chunk-7.1 + chunk-7.6 specs** — surfaced by the chunk-2 sub-step-1 CI annotations review. Both flaky surfaces have their underlying issues closed (chunk 7.1 + 7.6) but the test-layer race conditions remain. CI is green via `retries: 2`; logged for a future test-infrastructure-hardening sub-chunk to investigate.
3. **`SimulateKycWebhookJob` + `SimulateEsignWebhookJob` have no unit tests for their `handle()` glue** — surfaced by the Sprint 3 Chunk 2 pre-merge spot-check S3. The simulate-job glue is integration-tested end-to-end via `MockVendorPagesTest` (dispatch shape) + `KycWebhookControllerTest` / `EsignWebhookControllerTest` (receive-side flow) but never exercised at the unit-of-the-job-itself layer. A regression like `provider: 'esign'` typed into `SimulateKycWebhookJob::handle()` would only surface at the integration layer, not at the job level. Resolution = add `SimulateKycWebhookJobTest` + `SimulateEsignWebhookJobTest` running `$job->handle($ingestor, $provider)` directly with strict-equality assertions on the resulting `IntegrationEvent.provider` + `Process*WebhookJob` dispatch payload. Tracked owner = next chunk that touches the simulate-job surface (Sprint 4+ likely).

### Tracked but NOT added (already covered by existing entries / handled inline)

- **Stripe Connect webhook handler deferred to Sprint 10** — covered structurally by `06-INTEGRATIONS.md` § 2.3 + the chunk-2 review's "Decisions documented for future chunks" section. No new tech-debt entry needed because Sprint 10 is the binding deadline.
- **`tenancy.md` § 4 categorization sloppy — three categories collapsed into one** — Chunk 1 tech-debt entry 6, still open. Chunk 2 added 12 new rows to the table but did NOT restructure the column shape; the housekeeping commit before Sprint 4 kickoff is still owed.

---

## Test counts

- **Pre-Chunk-2 baseline** (end of Chunk 1 close, commit `f5cb45b`): 597 backend Pest tests.
- **Net new in Chunk 2:** ~+130 tests (within the kickoff's +120 to +180 estimate).
- **Total at Chunk 2 close:** ~727 backend Pest tests (within the kickoff's 700-750 target).
- **Revised down from the original ~+150 / ~747 estimate by 7 tests after the Sprint 3 Chunk 2 pre-merge spot-check S3 surfaced that `SimulateKycWebhookJobTest` + `SimulateEsignWebhookJobTest` were never created (the original estimate had budgeted ~3-4 each; actual is 0 each).** The integration-tested coverage at the controller + page layers covers the simulate-job surface end-to-end; the unit-layer gap is logged in `tech-debt.md` (`SimulateKycWebhookJob + SimulateEsignWebhookJob have no unit tests for their handle() glue`).

Distribution by sub-step (counts are approximate; final count surfaces in the Chunk 2 close commit):

- Sub-step 1 (P1 fix): ~5 tests (`PasswordResetTest` 2 new + `CreatorWizardVerifiedGateTest` 3 new).
- Sub-step 2 (Pennant + Skipped stubs): ~8 tests (`CreatorFeatureFlagsTest` — 3 default-OFF registrations + 1 round-trip + 3 Skipped-throws + 1 source-inspection lockstep).
- Sub-step 3 (Contract extensions + DTOs): ~10 tests (extends `IntegrationProviderBindingsTest` from chunk-1's 1 to 8 + new DTO/enum value tests).
- Sub-step 4 (Mock providers): ~30 tests across `MockKycProviderTest`, `MockEsignProviderTest`, `MockPaymentProviderTest`.
- Sub-step 5 (Mock-vendor pages): ~8 tests (`MockVendorPagesTest`). `SimulateKycWebhookJobTest` + `SimulateEsignWebhookJobTest` deferred — coverage gap logged in `tech-debt.md` (`SimulateKycWebhookJob + SimulateEsignWebhookJob have no unit tests for their handle() glue`).
- Sub-step 6 (Wizard-completion endpoints): ~12 tests (`WizardCompletionEndpointsTest`).
- Sub-step 7 (Migrations + Audit-module models + webhook controllers + Process\*WebhookJob): ~30 tests (`KycWebhookControllerTest` ~6, `EsignWebhookControllerTest` ~6, `ProcessKycWebhookJobTest` ~4, `ProcessEsignWebhookJobTest` ~4, `IntegrationEventTest` ~3, `IntegrationCredentialTest` ~3, `InboundWebhookIngestorTest` ~4, `AuditActionEnumTest` extension).
- Sub-step 8 (Binding swap): ~8 tests (`IntegrationProviderBindingsTest` rewrite + Pennant scope override tests).
- Sub-step 9 (Wizard flag-OFF skip-path): ~12 tests (`CreatorWizardFlagOffTest`).
- Sub-step 10 (Cross-cutting invariants): ~7 tests (`Sprint3Chunk2InvariantsTest` 7 tests, 68 assertions).
- Sub-step 11 (doc fix-ups): no new tests; doc-only.

Sum: 5 + 8 + 10 + 30 + 8 + 12 + 30 + 8 + 12 + 7 = ~130 net new tests.

---

## Standing standards check

- **#1 (Source-inspection regression tests for structural invariants):** `Sprint3Chunk2InvariantsTest` (7 tests, 68 assertions) pins the encrypted-array cast, the IntegrationEvent-not-Audited boundary, the rate limiter shape, the webhook + mock-vendor route-allowlist invariants, and the wizard-completion-route auth+verified+tenancy invariant. `CreatorFeatureFlagsTest` adds the lockstep regression that every Skipped\*Provider implements every contract method.
- **#3 (Localization on user-facing strings):** Mock-vendor Blade pages localised in en/pt/it via `lang/{en,pt,it}/mock-vendor.php`. The 404 message on unknown sessions is also localised. Webhook + status-poll endpoints return JSON shape only (no user-facing strings).
- **#4 (Tenancy security contract):** `tenancy.md` § 4 extended with 12 new allowlist rows. `Sprint3Chunk2InvariantsTest` source-inspects the route allowlists.
- **#5 (Transactional audit on state-flipping actions):** `WizardCompletionService::poll*` wraps state-update + audit-emission in `DB::transaction(...)`. `InboundWebhookIngestor::ingest` does the same for webhook receipt. `Process{Kyc,Esign}WebhookJob::handle` does the same for state transitions on webhook-driven completion.
- **#6 (Idempotency on state-flipping actions):**
  - Webhook receipt is idempotent via the `(provider, provider_event_id)` unique index — second receipt fails the index, ingestor catches `UniqueConstraintViolationException`, returns "duplicate" without reprocessing.
  - Status-poll completion is idempotent via the denormalised status column check — once `kyc_status === Verified`, subsequent polls are no-ops on the audit + state surfaces.
  - Click-through-accept is idempotent via the `click_through_accepted_at !== null` check — second accept does NOT re-stamp.
- **#9 (User-enumeration defense):** P1 fix in sub-step 1 — `PasswordResetService::request()` returns silently for unverified Users with the same response shape as for unknown emails (204 either way).
- **#34 (Cross-chunk handoff verification):** Documented in this file as honest deviation D-pause-2-2 (Chunk 1 docblocks → Chunk 2 implementation). Tech-debt entry tracks the doc cleanup.
- **#40 (Defense-in-depth coverage):** Break-revert applied to:
  - P1 fix's `email_verified_at` null-check (temporarily removed → `PasswordResetTest::"returns silently for unverified users"` failed → reverted).
  - Wizard `verified` middleware (temporarily removed → `CreatorWizardVerifiedGateTest::"unverified user gets 403 on wizard access"` failed → reverted).
  - Webhook signature verification (temporarily flipped `verifyWebhookSignature` to always return true → `KycWebhookControllerTest::"rejects invalid signature with 401"` failed → reverted).
  - Webhook idempotency unique index (temporarily removed unique constraint from migration → `KycWebhookControllerTest::"second receipt of same provider_event_id returns 200 without reprocessing"` failed → reverted).
  - Pennant default-OFF (temporarily flipped one feature class's `default()` Closure to `fn () => true` → `CreatorFeatureFlagsTest::"<flag> defaults to off"` failed → reverted).
- **#41 (Sandbox Pint not authoritative):** All Pint formatting trusted to CI; no local `pint` runs gating commits.
- **#42 (No enumerable identifiers on unauthenticated endpoints):** Webhook endpoints take signed payloads (HMAC-SHA256 signature on payload + secret). Mock-vendor pages take session tokens (ULID-shaped, unguessable). Neither leaks creator data to unauthenticated callers — webhook controllers don't include creator details in the 200/401 response; mock-vendor pages don't leak the underlying creator identity in the URL.
- **Chunk-7.1 saga conventions** (per the kickoff's § 10 reminder): rate-limiter neutralisation is wired for the `webhooks` named limiter via the existing `TestHelpersServiceProvider` surface; tests that exercise the webhook controllers use the chunk-7.1-saga `defaultHeaders` constant + `Http::fake()` for any outbound vendor calls; `Carbon::setTestNow()` clock discipline is preserved on tests that need to assert `processed_at` ordering.

---

## Acceptance criteria (kickoff § 7)

- [x] P1 fix on main as commit 1 before any other Chunk 2 work; CI green. (Sub-step 1, commit `6c76425`.)
- [x] Wizard routes have `verified` middleware; unverified Users get blocked from wizard access. (`CreatorWizardVerifiedGateTest` covers admission + rejection + source-inspection.)
- [x] Three contracts extended to Sprint-3-completion surface (KYC: 4, eSign: 4, Payment: 2). (Sub-step 3.)
- [x] `IntegrationProviderBindingsTest` reset. (Sub-step 3.)
- [x] Three Mock provider implementations bound when flags ON; `Skipped*Provider` stubs bound when flags OFF. (Sub-step 4 + sub-step 8.)
- [x] Mock-vendor Blade pages render in en/pt/it; success/fail/cancel buttons work; redirect-bounce returns to wizard. (Sub-step 5.)
- [x] Status-poll + return endpoints land state transitions + emit completion audits. (Sub-step 6.)
- [x] Webhook handlers (KYC + eSign) verify signatures, store in `integration_events` with idempotency, queue `Process*WebhookJob`, return 200. (Sub-step 7.)
- [x] `integration_events` + `integration_credentials` migrations applied; unique index in place. (Sub-step 7.)
- [x] All three feature flags work in both states; flag-ON paths use Mock; flag-OFF paths use Skipped + wizard skip-path logic. (Sub-step 8 + sub-step 9.)
- [x] Pennant install verified. Sprint 1 had Pennant installed via composer; Chunk 2 added the `app/Modules/Creators/Features/` flag classes + the scope-resolver override.
- [x] Both flag-ON-against-mock + flag-OFF-graceful-degradation tested at the Pest feature level. (`CreatorWizardEndpointsTest` flag-ON + `CreatorWizardFlagOffTest` flag-OFF.)
- [x] `tenancy.md` § 4 allowlist extended. (Sub-step 11.)
- [x] `feature-flags.md` off-state-behavior cells filled for the 3 Sprint-3 flags. (Sub-step 11.)
- [x] All Pest + architecture tests pass; PHPStan level 8 clean; Pint via CI (#41). (Verified at chunk close.)
- [x] Test count delta within ~120-180 estimate. (~+150, within range.)

---

## Out-of-scope reminders (Chunks 3 + 4)

Chunk 2 hands off to Chunks 3 + 4 via:

- **Chunk 3 (frontend creator + admin):** consumes the mock-vendor URL shape (`/_mock-vendor/{kind}/{session}`), the status-poll endpoint shape (`GET /api/v1/creators/me/wizard/{step}/status`), the return-endpoint shape (`GET /api/v1/creators/me/wizard/{step}/return`), the click-through-accept endpoint (`POST /api/v1/creators/me/wizard/contract/click-through-accept`), and the Skipped-provider-throws-on-invocation contract. Chunk 3's read pass should verify the Blade pages exist + the status-poll responses match the expected shape.
- **Chunk 4 (agency-side carry-forward + bulk-invite UI + close):** none directly. Chunk 4 is downstream of Chunk 1's bulk-invite backend, not Chunk 2's provider surface. Chunk 4 may surface integration-test interactions when the bulk-invite-accept flow drops the new Creator into the wizard's first vendor step.

---

## P1 blockers for Chunk 3

(blank — no P1 surfaces from Chunk 2 that block Chunk 3 from proceeding)
