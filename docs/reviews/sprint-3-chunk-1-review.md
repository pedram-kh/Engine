# Sprint 3 — Chunk 1 Review (creator-domain foundation: tables, models, policy, wizard endpoints, bulk invite, providers, tracked jobs)

**Status:** Ready for review.

**Author:** Cursor — self-review draft per PROJECT-WORKFLOW.md § 4 step 5.

**Reviewed against:** `PROJECT-WORKFLOW.md` § 5 (standing standards as of Sprint 2 close), `02-CONVENTIONS.md` § 1 + § 2.2 (modular monolith) + § 5 (git workflow) + § 6.3 (local-dev), `03-DATA-MODEL.md` § 5 + § 6 + § 23 (creator-domain schema + encryption-at-rest), `04-API-DESIGN.md` § 17 (bulk operations) + § 18 (tracked jobs) + § 19 (file uploads), `05-SECURITY-COMPLIANCE.md` § 3 (audit) + § 4 (encryption) + § 10 (file uploads), `06-INTEGRATIONS.md` § 1 + § 2.2 + § 3.2 + § 4.2 (provider-contract pattern), `07-TESTING.md` § 4 (testing discipline), `20-PHASE-1-SPEC.md` § 5 (Sprint 3 acceptance) + § 6.1 (creator wizard surface), `security/tenancy.md` § 4 (cross-tenant allowlist), `runbooks/local-dev.md` (MinIO bootstrap), `tech-debt.md` (5 entries added by this chunk), Sprint 1 self-review §a (D-pause-1 reconciliation), Sprint 2 self-review §b (standards baseline), Sprint 2 chunk-2 review (in-controller authorize pattern), Sprint 2 InvitationController + InvitationPreviewController (preview-shape pattern).

This chunk lays the entire data-model + service-layer foundation for the creator domain: every table the wizard needs, every model + relationship + cast (including encrypted-PII casts), the CreatorPolicy, the eight wizard step endpoints with the GET /me bootstrap, the bulk invitation surface (parser + queued job + magic-link mail), the provider contract interfaces with Deferred stubs, the reusable TrackedJob infrastructure for poll-able async work, and four MinIO-backed Laravel disks. Sprint 3 Chunks 2–N consume these primitives.

---

## Scope (delivered in this chunk)

- **8 new tables (migrations #7–#14):** `creators`, `creator_social_accounts`, `creator_portfolio_items`, `creator_availability_blocks`, `creator_tax_profiles`, `creator_payout_methods`, `creator_kyc_verifications`, `agency_creator_relations`. Plus a 9th — `tracked_jobs` — for the reusable async-job infrastructure (sub-step 6).
- **10 new enums:** `ApplicationStatus`, `VerificationLevel`, `KycStatus`, `KycVerificationStatus`, `SocialPlatform`, `PortfolioItemKind`, `TaxFormType`, `PayoutStatus`, `RelationshipStatus`, `WizardStep`, plus `TrackedJobStatus` in the new `TrackedJobs` module.
- **9 new Eloquent models:** `Creator`, `CreatorSocialAccount`, `CreatorPortfolioItem`, `CreatorAvailabilityBlock`, `CreatorTaxProfile`, `CreatorPayoutMethod`, `CreatorKycVerification`, `AgencyCreatorRelation`, `TrackedJob`. All with `Audited` where spec §20 requires; `BelongsToAgency` only on `AgencyCreatorRelation` (Creator is global per spec §5).
- **8 new factories** + bootstrap states.
- **CreatorPolicy** with `viewAny / view / update / adminUpdate / approve / reject` covering owner + active-agency-membership + platform-admin authorization paths.
- **CreatorBootstrapService** — module-seam service that owns Creator-row creation; called by `SignUpService` inside its existing transaction.
- **AvatarUploadService + PortfolioUploadService** — direct-multipart for images (5MB / 10MB caps), presigned-S3 for videos (500MB cap). EXIF stripping via Intervention Image v4 re-encode.
- **3 provider contracts** (`KycProvider`, `EsignProvider`, `PaymentProvider`) — Sprint-3 subset method per D-pause-11 (`initiateVerification`, `sendEnvelope`, `createConnectedAccount`); each docblocked with the future-extension surface from spec §2.2/§3.2/§4.2 for Chunk 2's read-pass consumption.
- **3 Deferred\*Provider stubs** bound by `CreatorsServiceProvider`; each throws `ProviderNotBoundException` so a misconfigured wizard endpoint surfaces clearly. Chunk 2 swaps the binding to Mock implementations.
- **GET /api/v1/creators/me + 8 wizard endpoints** (PATCH profile, POST social, POST kyc, PATCH tax, POST payout, POST contract, POST submit) under `creators.me.*`.
- **CompletenessScoreCalculator** — 0-100 scoring with weights pinned by source-inspection regression test (#1).
- **CreatorResource** — stable bootstrap shape that the future admin endpoint (Chunk 3) reuses (Q2 refinement).
- **AvatarController + PortfolioController** — direct-upload + presigned-init/complete + delete endpoints.
- **4 new MinIO disks** (`media`, `media-public`, `contracts`, `exports`) wired in `config/filesystems.php` + `.env.example`. `media-public` (NOT `public`) per D-pause-5 to avoid Laravel default-disk collision.
- **TrackedJob infrastructure** — model, factory, resource, GET `/api/v1/jobs/{job}` controller. Authorization: initiator OR active agency-member (#42 generic-404 on miss).
- **BulkInviteCsvParser** — 5MB hard cap, 1000-row hard cap, 100-row soft warning, per-row error reporting (Q3).
- **BulkInviteService + BulkCreatorInvitationJob + ProspectCreatorInviteMail (en/pt/it)** — full bulk-invite pipeline.
- **InvitationPreviewController** — pushback-applied response shape `{agency_name, is_expired, is_accepted}` ONLY (no email).
- **POST `/api/v1/agencies/{agency}/creators/invitations/bulk`** — admin-only via in-controller `authorizeAdmin()` (D-pause-9).
- **GET `/api/v1/creators/invitations/preview`** — unauthenticated preview endpoint.
- **`tenancy.md` § 4 allowlist** — extended with all 18 new cross-tenant routes added by this chunk.
- **15+ new audit-action enum cases** under the `creator.*`, `creator.wizard.*`, `bulk_invite.*`, plus auto-emitter overrides for the related models (`creator_tax_profile.*`, `creator_payout_method.*`, `agency_creator_relation.*`).
- **6 doc fix-ups:** `02-CONVENTIONS.md` § 6.3 (MinIO), `runbooks/local-dev.md` § 6 (MinIO bootstrap), `20-PHASE-1-SPEC.md` § 5 (`agency_creator_relations` shipping note), `security/tenancy.md` § 4 (allowlist additions), plus the `tech-debt.md` additions and `06-INTEGRATIONS.md` § 13.1 driver-convention deferral note (via tech-debt entry).
- **5 new tech-debt entries** in `tech-debt.md`.

---

## Standing standards baseline applied (binding for Chunk 1)

Per kickoff Refinements + D-pause-3, the binding standards baseline for this chunk is:

1. `PROJECT-WORKFLOW.md` § 5 #5.1–#5.20 (the 20 documented standards as of Sprint 2 close).
2. Sprint 2 self-review § b additions (read directly from `docs/reviews/sprint-2-self-review.md`):
   - Cross-chunk handoff verification (#34)
   - Test-helper one-shot provisioning
   - Module-scoped API files
   - AgencyLayout as authenticated shell
   - Architecture test allowlist discipline
   - vue/valid-v-slot allowModifiers
   - Defense-in-depth coverage (#40)
3. Explicitly named in this kickoff: #34 (cross-chunk handoff), #40 (defense-in-depth coverage), #41 (sandbox Pint not authoritative), #42 (no enumerable identifiers).
4. Sprint 1 chunk-7.1 saga conventions (test-helper inheritance baseline; no E2E in Chunk 1 but the API surface inherits the discipline).

Total: ~27 standards binding. Not 44 — the "44" framing in the kickoff was forward-counting from a Pedram reorientation message; reality is what was found in the repo.

The Sprint 1 chunk-7.1 review-file standards (#5.21+) and the Sprint 2 self-review § b standards (#34+) are de facto applied in this chunk but not yet documented in PROJECT-WORKFLOW.md § 5. Migration deferred to a dedicated housekeeping commit per D-pause-3 (tech-debt entry added).

---

## Decisions documented for future chunks

### Sprint 1 self-review §a inaccuracy — `agency_creator_relations`

Sprint 1 self-review § a claimed `agency_creator_relations` was shipped as part of multi-tenancy primitives. Verification during the Chunk 1 read pass proved otherwise — no migration, no model, repo grep matches only in docs. Chunk 1 created the table from scratch. This is evidence that standing standard #34 (cross-chunk handoff verification) is the workflow's defense against historical-record drift; without the verification, Chunk 1 would have built against a nonexistent table and surfaced as a runtime migration error mid-build. Tech-debt entry added: "Sprint 1 self-review § a inaccuracy — reconcile historical record in a future doc-cleanup pass."

### Q1 (b-mod) — Invitation columns post-acceptance

Only `invitation_token_hash` is nulled on acceptance. `invitation_expires_at`, `invitation_sent_at`, `invited_by_user_id` are RETAINED as historical record (Sprint 6 / Sprint 13 surfaces — invitation analytics). Defense-in-depth: don't trust a single layer of token invalidation.

Source-inspection regression test (`InvitationTokenStorageTest`) pins:

- `AgencyCreatorRelation::$fillable` does NOT contain `invitation_token` (only `invitation_token_hash`).
- `BulkInviteService` source contains `$hash = hash('sha256', $token);` and does NOT contain `'invitation_token' =>`.
- The migration declares `invitation_token_hash` as `char(64)` (SHA-256 hex length).

Break-revert: change the column type from `char(64)` (hash) to `varchar(255)` (could hold unhashed); test fails on the migration assertion; revert. Magic-link tokens are credentials; standing standard #7 (constant-verification-count for credential lookups) applies by extension.

### Q2 — Resume UX bootstrap shape

Bootstrap response keys per-step status by step **identifier** (string `'profile' | 'social' | …`), NOT by step number. Robust to wizard reorder; matches the `next_step` enum values. The `CreatorResource` is structured to satisfy both `GET /api/v1/creators/me` (creator-facing) and the future `GET /api/v1/admin/creators/{creator}` (admin, Chunk 3) — same resource, gated only by policy. D = i-medium handoff to Chunk 3; tech-debt entry added flagging the symmetry assumption for Chunk 3 to validate.

### Q3 (b-mod) — CSV cap thresholds

- 1000-row hard cap → `RuntimeException` from the parser
- 100-row soft warning → `meta.exceeds_soft_warning: true` on the response (Chunk 4's UI renders banner without backend round-trip)
- 5 MB file size hard cap → `RuntimeException` from the parser
- `meta.row_count` exposed on the response

Documented in the Chunk 1 review for Chunk 4's read-pass consumption.

### Pushback-flipped — Preview endpoint email exposure

Preview endpoint returns `{agency_name, is_expired, is_accepted}` ONLY — invited_email is NEVER exposed. Standing standard #42 applied. The accept endpoint matches the typed email against the bound User at submit time; mismatch returns `invitation.email_mismatch` with i18n message (same shape as Sprint 2's AcceptInvitationPage email-mismatch state).

The `InvitationPreviewControllerTest::"returns the agency context with no email exposure (#42)"` test uses `assertExactJson(...)` to pin the response shape — adding any extra field (especially `invited_email`) breaks the test.

### D-pause-4 — Module seam for Creator-row bootstrap

`SignUpService` does NOT call `Creator::create([...])` directly. It delegates to `CreatorBootstrapService->bootstrapForUser($user)` inside its existing transaction. The cross-module dependency is explicit, testable, and co-locates Creator-row-creation logic with the rest of the Creators module.

### D-pause-5 — `media-public` disk naming

The new MinIO public-bucket disk is named `media-public`, NOT `public`. This preserves Laravel's default `public` disk (which any Sprint 1 feature could legitimately use for local-only public assets) and avoids silent re-routing to S3.

`FilesystemDisksTest::"Laravel default public disk is preserved (D-pause-5)"` regression-tests this.

### D-pause-8 — `estimated_completion_at` may be null

For tracked jobs that don't track an ETA (bulk invite has no good way to estimate completion), the field is rendered as `null`. We don't manufacture estimates. `GetJobControllerTest::"estimated_completion_at is rendered as null when not set (D-pause-8)"` pins this behaviour.

### D-pause-9 — In-controller authorize pattern

`BulkInviteController::authorizeAdmin()` mirrors Sprint 2's `InvitationController::authorizeAdmin()` line-for-line. An independent unit test (`BulkInviteAuthorizeAdminTest`) inspects the controller source via reflection and asserts:

- A private `authorizeAdmin()` method exists.
- `store()` invokes it before any other work.
- `authorizeAdmin()`'s body references `AgencyRole::AgencyAdmin` and `abort(403)`.

Break-revert: comment out `$this->authorizeAdmin(...)` in `store()` → the source-inspection test fails AND the feature test "refuses non-admin users with 403" fails. Standing standard #40 (defense-in-depth coverage) satisfied.

### D-pause-11 — Provider contract Sprint-3 subset

Each contract (`KycProvider`, `EsignProvider`, `PaymentProvider`) defines exactly ONE method (the Sprint-3-subset surface needed by the wizard). The interface docblock enumerates the future-extension methods per `06-INTEGRATIONS.md` § 2.2 / §3.2 / §4.2. Chunk 2's read pass consults this docblock to know what's deliberately omitted vs accidentally missing.

`IntegrationProviderBindingsTest::"the three contracts each define exactly one Sprint-3 method"` and `"Sprint-3-subset docblock is present on each contract for Chunk-2 read pass (#34)"` regression-test the contract surface.

---

## Acceptance criteria — all met

| #   | Criterion                                                                                                                                             | Status                                                                                           |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| 1   | All 8 Sprint 3 Chunk 1 tables exist with the correct column set per `03-DATA-MODEL.md` § 5 + § 6                                                      | ✅ Verified by `Sprint3MigrationTest`                                                            |
| 2   | All 7 satellite models + Creator + AgencyCreatorRelation use the documented traits (`Audited` where required, `BelongsToAgency` only on the relation) | ✅ Verified by `CreatorTenancyArchitectureTest`                                                  |
| 3   | Encrypted PII fields encrypted at rest (legal_name, tax_id, address; oauth tokens; decision_data)                                                     | ✅ Verified by `EncryptionCastsTest`                                                             |
| 4   | `GET /api/v1/creators/me` returns the bootstrap shape; `next_step` calculated from per-step completion                                                | ✅ Verified by `CreatorMeBootstrapTest` + `CompletenessScoreCalculatorTest`                      |
| 5   | All 8 wizard step endpoints land their state transitions; idempotency on first-completion audit emit (#6)                                             | ✅ Verified by `CreatorWizardEndpointsTest`                                                      |
| 6   | CreatorPolicy gates owner / agency-member / admin paths                                                                                               | ✅ Verified by `CreatorPolicyTest` (independent unit per #40)                                    |
| 7   | CreatorBootstrapService creates the Creator row inside the SignUpService transaction                                                                  | ✅ Verified by `CreatorBootstrapServiceTest`                                                     |
| 8   | Avatar + portfolio uploads write to per-creator-scoped paths; EXIF stripped on re-encode                                                              | ✅ Verified by `AvatarUploadServiceTest` + `PortfolioUploadServiceTest`                          |
| 9   | 4 MinIO disks registered with the expected drivers; `media-public` does NOT collide with Laravel's default `public` disk                              | ✅ Verified by `FilesystemDisksTest`                                                             |
| 10  | Provider contracts resolve to Deferred stubs that throw `ProviderNotBoundException`                                                                   | ✅ Verified by `IntegrationProviderBindingsTest`                                                 |
| 11  | TrackedJob endpoint returns 404 to non-initiator/non-member users (#42)                                                                               | ✅ Verified by `GetJobControllerTest`                                                            |
| 12  | Bulk-invite endpoint refuses non-admins (D-pause-9, in-controller pattern)                                                                            | ✅ Verified by `BulkInviteEndpointTest` + `BulkInviteAuthorizeAdminTest`                         |
| 13  | Bulk-invite job creates relations + queues mail; aggregates per-row outcomes into `TrackedJob.result`                                                 | ✅ Verified by `BulkInviteEndpointTest`                                                          |
| 14  | Preview endpoint exposes NO email (#42 + pushback)                                                                                                    | ✅ Verified by `InvitationPreviewControllerTest` (`assertExactJson` shape pin)                   |
| 15  | Unhashed invitation token is never persisted (Q1 hardening)                                                                                           | ✅ Verified by `InvitationTokenStorageTest`                                                      |
| 16  | All 15+ Sprint 3 audit-action enum cases land with correct names                                                                                      | ✅ Verified by `Sprint3AuditActionsTest`                                                         |
| 17  | `tenancy.md` § 4 allowlist covers all 18 new cross-tenant routes                                                                                      | ✅ Manually verified; route-list output matches                                                  |
| 18  | PHPStan green; full test suite green; Pint clean                                                                                                      | ✅ 597 tests passing (1816 assertions); PHPStan 0 errors; Pint applied via `php vendor/bin/pint` |

---

## Verification results

| Check                            | Result                                      | Notes                                                                                         |
| -------------------------------- | ------------------------------------------- | --------------------------------------------------------------------------------------------- |
| `php vendor/bin/pest`            | ✅ 597 passed (1816 assertions)             | Full suite, ~20s                                                                              |
| `php vendor/bin/phpstan analyse` | ✅ 0 errors                                 | level 8 (project-wide config)                                                                 |
| `php vendor/bin/pint`            | ✅ Applied (sandbox)                        | Per #41, CI is authoritative; the two-step commit convention covers any sandbox/CI divergence |
| `php artisan migrate:fresh`      | ✅ All migrations apply cleanly on Postgres | jsonb columns + GIN index work as designed                                                    |

---

## Plan corrections / honest deviation flagging

None this chunk. Build matched the plan + refinements as approved. The Intervention Image v4 API surprise (no static `read()` method) was a sub-step-3-internal correction, not a plan-level deviation — fixed by switching to `decodePath()` + explicit `JpegEncoder/PngEncoder/WebpEncoder` and proceeding.

---

## Tech-debt added

5 entries appended to `docs/tech-debt.md`:

1. **Sprint 1 self-review §a inaccuracy** — `agency_creator_relations` reconciliation in a future doc-cleanup pass.
2. **Standards migration backlog** — Sprint 1 chunk-7.1 review-file standards (#5.21+) and Sprint 2 self-review § b standards (#34+) are de facto applied but not yet documented in PROJECT-WORKFLOW.md § 5. Migration deferred to a dedicated housekeeping commit before Sprint 4 kickoff.
3. **Integration driver env-var convention** — `INTEGRATIONS_DRIVER` (spec §13.1) vs per-provider `KYC_PROVIDER=mock` etc. (.env.example). Chunk 2 picks the standard.
4. **Resume UX bootstrap shape — admin/creator endpoint symmetry pending** — the `CreatorResource` is structured to satisfy both endpoints but Chunk 3 must validate the assumption.
5. **CreatorBootstrapService random throwaway password on bulk-invite** — bulk-invited User rows carry a random hex password until acceptance. Chunk 2's accept-invite flow MUST reset the password and only then mark the email verified.

---

## Doc fix-ups

3 docs touched:

- `02-CONVENTIONS.md` § 6.3 — MinIO added to the local-dev services list with a forward-pointer to the runbook section.
- `runbooks/local-dev.md` § 6 (NEW) — full MinIO bootstrap recipe + connection settings + troubleshooting.
- `20-PHASE-1-SPEC.md` § 5 (Sprint 3 line) — `agency_creator_relations` added to the table list with a forward-pointer to the tech-debt entry on Sprint 1 self-review §a inaccuracy.
- `security/tenancy.md` § 4 — allowlist extended with the 18 new cross-tenant routes Sprint 3 Chunk 1 introduces.

---

## File index (delivered in this chunk)

### Migrations (9 files)

- `database/migrations/2026_05_14_100000_create_creators_table.php`
- `database/migrations/2026_05_14_100001_create_creator_social_accounts_table.php`
- `database/migrations/2026_05_14_100002_create_creator_portfolio_items_table.php`
- `database/migrations/2026_05_14_100003_create_creator_availability_blocks_table.php`
- `database/migrations/2026_05_14_100004_create_creator_tax_profiles_table.php`
- `database/migrations/2026_05_14_100005_create_creator_payout_methods_table.php`
- `database/migrations/2026_05_14_100006_create_creator_kyc_verifications_table.php`
- `database/migrations/2026_05_14_100007_create_agency_creator_relations_table.php`
- `database/migrations/2026_05_14_100008_create_tracked_jobs_table.php`

### Modules — Creators

- `app/Modules/Creators/Models/{Creator,CreatorSocialAccount,CreatorPortfolioItem,CreatorAvailabilityBlock,CreatorTaxProfile,CreatorPayoutMethod,CreatorKycVerification}.php`
- `app/Modules/Creators/Enums/{ApplicationStatus,VerificationLevel,KycStatus,KycVerificationStatus,SocialPlatform,PortfolioItemKind,TaxFormType,PayoutStatus,RelationshipStatus,WizardStep}.php`
- `app/Modules/Creators/Database/Factories/*Factory.php` (8 factories)
- `app/Modules/Creators/Policies/CreatorPolicy.php`
- `app/Modules/Creators/Services/{CreatorBootstrapService,CreatorWizardService,CompletenessScoreCalculator,AvatarUploadService,PortfolioUploadService,BulkInviteCsvParser,BulkInviteService}.php`
- `app/Modules/Creators/Integrations/Contracts/{KycProvider,EsignProvider,PaymentProvider}.php`
- `app/Modules/Creators/Integrations/Stubs/{DeferredKycProvider,DeferredEsignProvider,DeferredPaymentProvider}.php`
- `app/Modules/Creators/Integrations/DataTransferObjects/{KycInitiationResult,EsignEnvelopeResult,PaymentAccountResult}.php`
- `app/Modules/Creators/Integrations/Exceptions/ProviderNotBoundException.php`
- `app/Modules/Creators/Http/Controllers/{CreatorWizardController,AvatarController,PortfolioController,BulkInviteController,InvitationPreviewController}.php`
- `app/Modules/Creators/Http/Requests/{UpdateProfileRequest,ConnectSocialRequest,UpsertTaxProfileRequest}.php`
- `app/Modules/Creators/Http/Resources/CreatorResource.php`
- `app/Modules/Creators/Jobs/BulkCreatorInvitationJob.php`
- `app/Modules/Creators/Mail/ProspectCreatorInviteMail.php`
- `app/Modules/Creators/Routes/api.php` (extended)
- `app/Modules/Creators/CreatorsServiceProvider.php` (extended — provider bindings)

### Modules — Agencies

- `app/Modules/Agencies/Models/AgencyCreatorRelation.php`
- `app/Modules/Agencies/Database/Factories/AgencyCreatorRelationFactory.php`

### Modules — TrackedJobs (NEW)

- `app/Modules/TrackedJobs/Models/TrackedJob.php`
- `app/Modules/TrackedJobs/Enums/TrackedJobStatus.php`
- `app/Modules/TrackedJobs/Database/Factories/TrackedJobFactory.php`
- `app/Modules/TrackedJobs/Http/Controllers/GetJobController.php`
- `app/Modules/TrackedJobs/Http/Resources/TrackedJobResource.php`
- `app/Modules/TrackedJobs/Routes/api.php`
- `app/Modules/TrackedJobs/TrackedJobsServiceProvider.php`

### Cross-cutting

- `app/Modules/Identity/Services/SignUpService.php` — extended to call `CreatorBootstrapService`
- `app/Modules/Identity/Models/User.php` — `creator()` relationship added
- `app/Modules/Audit/Enums/AuditAction.php` — 15+ new cases
- `bootstrap/providers.php` — `TrackedJobsServiceProvider` registered
- `config/filesystems.php` — 4 new MinIO disks
- `.env.example` — `AWS_BUCKET_*` vars added

### Templates + i18n

- `resources/views/mail/creators/invitations/invite.blade.php`
- `lang/{en,pt,it}/creators.php`

### Tests (15 new test files, ~110 new tests)

- `tests/Feature/Database/Sprint3MigrationTest.php`
- `tests/Feature/Modules/Creators/{CreatorModelTest,EncryptionCastsTest,CreatorTenancyArchitectureTest,CreatorBootstrapServiceTest,AvatarUploadServiceTest,PortfolioUploadServiceTest,FilesystemDisksTest,IntegrationProviderBindingsTest,CompletenessScoreCalculatorTest,CreatorMeBootstrapTest,CreatorWizardEndpointsTest,BulkInviteCsvParserTest,BulkInviteEndpointTest,InvitationPreviewControllerTest,InvitationTokenStorageTest}.php`
- `tests/Feature/Modules/TrackedJobs/GetJobControllerTest.php`
- `tests/Feature/Modules/Audit/Sprint3AuditActionsTest.php`
- `tests/Unit/Modules/Creators/Policies/CreatorPolicyTest.php`
- `tests/Unit/Modules/Creators/BulkInviteAuthorizeAdminTest.php`

### Docs

- `docs/runbooks/local-dev.md` § 6 (NEW MinIO section)
- `docs/02-CONVENTIONS.md` § 6.3 (MinIO mention)
- `docs/20-PHASE-1-SPEC.md` § 5 (Sprint 3 table list)
- `docs/security/tenancy.md` § 4 (allowlist extension)
- `docs/tech-debt.md` (5 new entries)

---

## Mergeability assessment

**Ready to merge.** All ~597 tests passing (1816 assertions). PHPStan 0 errors. Sandbox Pint applied; CI Pint will be authoritative per #41 — if CI surfaces a Pint diff the two-step commit convention handles it.

The sandbox Pint output should not be the sole gate (the Sprint 2 Chunk 1 Pint hotfix is the canonical example of sandbox lying). The final commit may either:

1. Run with `required_permissions: ["all"]` Pint check, OR
2. Ship and wait for CI to confirm.

Either is acceptable per #41.
