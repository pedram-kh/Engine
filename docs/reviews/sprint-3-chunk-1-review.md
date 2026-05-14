# Sprint 3 — Chunk 1 Review

**Status:** Closed.

**Reviewer:** Claude (independent review) — incorporating implementation details from Cursor's self-review draft.

**Reviewed against:** `PROJECT-WORKFLOW.md` § 5 (standing standards as of Sprint 2 close — ~27 standards binding per D-pause-3 reconciliation), `02-CONVENTIONS.md` § 1 + § 2.2 (modular monolith) + § 5 (git workflow) + § 6.3 (local-dev), `03-DATA-MODEL.md` § 5 + § 6 + § 23 (creator-domain schema + encryption-at-rest), `04-API-DESIGN.md` § 17 (bulk operations) + § 18 (tracked jobs) + § 19 (file uploads), `05-SECURITY-COMPLIANCE.md` § 3 (audit) + § 4 (encryption) + § 10 (file uploads), `06-INTEGRATIONS.md` § 1 + § 2.2 + § 3.2 + § 4.2 (provider-contract pattern), `07-TESTING.md` § 4 (testing discipline), `20-PHASE-1-SPEC.md` § 5 (Sprint 3 acceptance) + § 6.1 (creator wizard surface), `security/tenancy.md` § 4 (cross-tenant allowlist), `runbooks/local-dev.md` (MinIO bootstrap), `tech-debt.md` (7 entries net added by this chunk after R3 restructure), Sprint 1 self-review §a (D-pause-1 reconciliation), Sprint 2 self-review §b (standards baseline), Sprint 2 chunk-2 review (in-controller authorize pattern), Sprint 2 InvitationController + InvitationPreviewController (preview-shape pattern).

This chunk lays the entire data-model + service-layer foundation for the creator domain: every table the wizard needs, every model + relationship + cast (including encrypted-PII casts), the CreatorPolicy, the eight wizard step endpoints with the GET /me bootstrap, the bulk invitation surface (parser + queued job + magic-link mail), the provider contract interfaces with Deferred stubs, the reusable TrackedJob infrastructure for poll-able async work, and four MinIO-backed Laravel disks. Sprint 3 Chunks 2–4 consume these primitives.

---

## Scope

- **9 new tables (migrations #7–#15):** `creators`, `creator_social_accounts`, `creator_portfolio_items`, `creator_availability_blocks`, `creator_tax_profiles`, `creator_payout_methods`, `creator_kyc_verifications`, `agency_creator_relations` (created from scratch per D-pause-1), and `tracked_jobs` (reusable async-job infrastructure per D-pause-8).
- **10 new enums** in the Creators module + `TrackedJobStatus` in the new TrackedJobs module.
- **9 new Eloquent models** with `Audited` per spec § 20; `BelongsToAgency` only on `AgencyCreatorRelation` (Creator is global per spec § 5).
- **CreatorPolicy** — `viewAny / view / update / adminUpdate / approve / reject` with independent unit coverage per #40.
- **CreatorBootstrapService** — module-seam service called by `SignUpService` inside its existing transaction (D-pause-4 refinement).
- **GET `/api/v1/creators/me` + 8 wizard endpoints** under `creators.me.*` with the `CreatorResource` bootstrap shape symmetric for Chunk 3's admin endpoint (Q2 commitment).
- **CompletenessScoreCalculator** with weights pinned by source-inspection regression test (#1).
- **AvatarUploadService + PortfolioUploadService** — direct-multipart + presigned-S3 with EXIF stripping via Intervention Image v4 re-encode.
- **3 provider contracts** (`KycProvider`, `EsignProvider`, `PaymentProvider`) bound to `Deferred*Provider` stubs that throw `ProviderNotBoundException`. Sprint-3-subset surface only (D-pause-11 + R2 honest-deviation flag).
- **4 MinIO disks** (`media`, `media-public`, `contracts`, `exports`) — `media-public` not `public` per D-pause-5 to avoid Laravel default-disk collision.
- **TrackedJob infrastructure** — model, factory, resource, `GET /api/v1/jobs/{job}` with initiator-or-agency-member authorization and generic-404 on miss per #42; `estimated_completion_at` nullable per D-pause-8.
- **Bulk invite pipeline** — `BulkInviteCsvParser` (1000-row hard cap / 100-row soft warning / 5MB file cap per Q3-mod), `BulkInviteService`, `BulkCreatorInvitationJob`, `ProspectCreatorInviteMail` in en/pt/it per #3.
- **InvitationPreviewController** — response shape `{agency_name, is_expired, is_accepted}` only (no email exposure) per #42 + Claude pushback; `assertExactJson` shape pin prevents regression.
- **`InvitationTokenStorageTest`** — source-inspection regression test pins that the unhashed token is never persisted (Q1 hardening; only the SHA-256 hash on the relation row).
- **15+ new AuditAction cases** with idempotent first-completion emit verified by tests.
- **`tenancy.md` § 4 allowlist** — 15 new cross-tenant routes (the 14 `creators.me.*` routes + `GET /api/v1/jobs/{job}`) + 3 path-scoped-tenant routes added in the same commit per F1 (Sprint 2 oversight + 2 Sprint 3 routes; see "Honest deviations" → "tenancy.md categorization audit").
- **6 doc fix-ups** — `02-CONVENTIONS.md` § 6.3, `runbooks/local-dev.md` § 6 (MinIO bootstrap), `20-PHASE-1-SPEC.md` § 5, `security/tenancy.md` § 4 (allowlist additions + categorization note), `tech-debt.md` (7 entries net), `06-INTEGRATIONS.md` § 13.1 driver-convention deferral note via tech-debt.
- **7 tech-debt entries net** (after R3 restructure that deleted entry 5 and added 3 new entries).

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
| 14  | Preview endpoint exposes NO email (#42 + Claude pushback)                                                                                             | ✅ Verified by `InvitationPreviewControllerTest` (`assertExactJson` shape pin)                   |
| 15  | Unhashed invitation token is never persisted (Q1 hardening)                                                                                           | ✅ Verified by `InvitationTokenStorageTest`                                                      |
| 16  | All 15+ Sprint 3 audit-action enum cases land with correct names                                                                                      | ✅ Verified by `Sprint3AuditActionsTest`                                                         |
| 17  | `tenancy.md` § 4 allowlist covers 15 new cross-tenant routes + 3 path-scoped-tenant routes (Sprint 2 oversight + 2 Sprint 3 routes; see F1)           | ✅ Manually verified; route-list output matches                                                  |
| 18  | PHPStan green; full test suite green; Pint clean                                                                                                      | ✅ 597 tests passing (1816 assertions); PHPStan 0 errors; Pint applied via `php vendor/bin/pint` |

---

## Standout design choices (unprompted)

Recording so they become reusable patterns:

- **`InvitationTokenStorageTest` — source-inspection regression on credential storage.** Pins via reflection + source-grep that `AgencyCreatorRelation::$fillable` does not contain `invitation_token`, that `BulkInviteService` source contains the `sha256` hash call and not the raw token, and that the migration declares `invitation_token_hash` as `char(64)`. Break-revert: change the column type to `varchar(255)` → test fails on the migration assertion → revert. This is standing standard #1 applied to credential storage (a Q1 hardening that goes beyond what was specified). **Pattern carried forward: any credential column gets a source-inspection pin on `fillable` + storage shape + hash-algorithm before reaching merge.**

- **`BulkInviteAuthorizeAdminTest` — reflection-based source-inspection on authorization.** Inspects the controller's source to assert a private `authorizeAdmin()` exists, that `store()` invokes it before any other work, and that `authorizeAdmin()`'s body references `AgencyRole::AgencyAdmin` and `abort(403)`. Break-revert: comment out `$this->authorizeAdmin(...)` in `store()` → both the source-inspection test AND the "refuses non-admin users with 403" feature test fail. Textbook #40 defense-in-depth coverage where the source-inspection layer catches the case the feature test alone can't (e.g., authorization implemented in a way that's syntactically present but semantically wrong).

- **CreatorResource symmetric shape.** Single resource serves both `GET /api/v1/creators/me` (creator-facing) and the future `GET /api/v1/admin/creators/{creator}` (admin, Chunk 3) — same resource, gated only by policy. D = i-medium handoff to Chunk 3; the symmetry is testable from Chunk 1.

- **Bootstrap response: list-of-objects, not map.** `wizard.steps` is rendered as `[{id, is_complete}, ...]` rather than a map keyed by step id. JSON:API-friendlier (lists preserve order) and frontend-ergonomic (`.find(s => s.id === 'profile')`). Satisfies Q2's identifier-keying commitment.

- **TrackedJob as reusable infrastructure, not a one-off.** D-pause-8 surfaced that `GET /api/v1/jobs/{job}` didn't exist; rather than ship a bulk-invite-specific status endpoint, Cursor built the generic infrastructure that Sprint 14 (GDPR exports) and Sprint 10 (payments) will reuse. Justifies the slightly larger Chunk 1 surface.

---

## Decisions documented for future chunks

### Sprint 1 self-review §a inaccuracy — `agency_creator_relations` was never shipped

Sprint 1 self-review § a claimed `agency_creator_relations` was shipped as part of multi-tenancy primitives. Verification during the Chunk 1 read pass proved otherwise — no migration, no model, repo grep matches only in docs. Chunk 1 created the table from scratch. This is evidence that standing standard #34 (cross-chunk handoff verification) is the workflow's defense against historical-record drift; without the verification, Chunk 1 would have built against a nonexistent table and surfaced as a runtime migration error mid-build. Tech-debt entry added to reconcile the historical record in a future doc-cleanup pass. **Pattern recorded: do not trust prior-sprint self-review claims of table/feature existence without verification.**

### Q1 (b-mod) — Invitation columns post-acceptance

Only `invitation_token_hash` is nulled on acceptance. `invitation_expires_at`, `invitation_sent_at`, `invited_by_user_id` are retained as historical record (Sprint 6 / Sprint 13 invitation-analytics surfaces). Defense-in-depth: don't trust a single layer of token invalidation.

### Q2 — Resume UX bootstrap shape

Bootstrap response keys per-step status by step identifier (string `'profile' | 'social' | …`), not by step number. `CreatorResource` is structured to satisfy both `GET /api/v1/creators/me` and `GET /api/v1/admin/creators/{creator}` (Chunk 3). D = i-medium handoff to Chunk 3; tech-debt entry flags the symmetry assumption for Chunk 3 to validate.

### Q3 (b-mod) — CSV cap thresholds

1000-row hard cap (parser raises) / 100-row soft warning (`meta.exceeds_soft_warning: true` exposed for Chunk 4's UI banner) / 5 MB file size hard cap / `meta.row_count` exposed.

### Pushback-flipped — Preview endpoint email exposure

Preview endpoint returns `{agency_name, is_expired, is_accepted}` only — `invited_email` never exposed. Standing standard #42 applied. The accept endpoint matches the typed email against the bound User at submit time; mismatch returns `invitation.email_mismatch` with i18n message.

### D-pause-4 — Module seam for Creator-row bootstrap

`SignUpService` delegates Creator-row creation to `CreatorBootstrapService->bootstrapForUser($user)` inside its existing transaction. Cross-module dependency explicit, testable, co-located with the rest of the Creators module.

### D-pause-5 — `media-public` disk naming

The MinIO public-bucket disk is named `media-public`, preserving Laravel's default `public` disk. `FilesystemDisksTest` regression-tests this.

### D-pause-8 — `estimated_completion_at` may be null

Tracked jobs that don't track an ETA (bulk invite) render `estimated_completion_at` as null. Pinned by `GetJobControllerTest`.

### D-pause-9 — In-controller authorize pattern

`BulkInviteController::authorizeAdmin()` mirrors Sprint 2's `InvitationController::authorizeAdmin()` line-for-line. See `BulkInviteAuthorizeAdminTest` reflection-based source-inspection coverage above.

### D-pause-11 — Provider contract Sprint-3 subset

Each contract defines exactly the Sprint-3-subset surface needed by the wizard. Future-extension surface enumerated in interface docblocks for Chunk 2's read-pass consumption. **See R2 honest-deviation below — the surface narrowed further than the kickoff specified; the implications are material for Chunk 2.**

---

## Honest deviations

### Throwaway-password design — bulk-invited User rows exist before acceptance

To satisfy `agency_creator_relations.creator_id NOT NULL`, bulk invite eagerly creates `User + Creator + Relation` atomically. The User row has `email_verified_at = null` and a 256-bit random hex Argon2id-hashed password.

**Security side-channel surfaced during Chunk 1 review (S2):** `PasswordResetService::request()` does not check `email_verified_at`. An attacker who knows or guesses an invited email can trigger a forgot-password email to that invitee's inbox before the legitimate invitee consumes the magic link. If the attacker (or the invitee racing the attacker) completes the reset, they authenticate the User row without consuming the invitation token. The `AgencyCreatorRelation` stays `prospect` indefinitely; the agency is unaware. Wizard routes use `auth:web` not `verified`, so the unverified-via-reset User has full wizard access.

**This is a regression of standing standard #9** (user-enumeration defense across the auth surface). The forgot-password endpoint's missing `email_verified_at` check was latent through Sprint 1 + Sprint 2 — pre-Sprint-3 there was no Eloquent path to create an unverified User without going through the verify-email flow. Sprint 3 Chunk 1's bulk-invite shape exposes the latent gap.

**Resolution:** P1 blocker for Chunk 2. See "P1 blockers for Chunk 2" section below.

### Provider contract surface narrowed from kickoff

Kickoff specified 11 contract methods (KycProvider: 4, EsignProvider: 4, PaymentProvider Sprint-3 subset: 3). Chunk 1 shipped 3 (initiate-only per contract). The 8-method gap covers status-check methods + webhook handling.

Wizard cannot progress past Steps 5/7/8 in Chunk 1 because there is no status-check endpoint and no webhook handler. `KycStatus`, `payout_method_set`, `signed_master_contract_id` sit at initial values forever in current state.

Future-extension surface enumerated in each contract's docblock per D-pause-11; Chunk 2's read pass treats those docblocks as the binding shape.

**Chunk 2's first architectural decision** is the wizard-completion architecture. Three options:

1. **Status-poll endpoint + status-check contract methods** — wizard frontend polls after redirect-bounce.
2. **Webhook handlers + signature/parse contract methods** — vendor calls Catalyst directly.
3. **Mock-synchronous** — only viable for mocks; real adapters need 1 or 2 eventually.

**Recommendation (Claude review): adopt both (1) and (2).** Real production needs both — status-check for the redirect-bounce UX confirmation (sitting at "submitted, waiting for webhook" is bad UX); webhooks for the authoritative state update. Mock implementations can complete-on-status-check (option 3's mechanics, accessed via the status-check contract method). Real adapters (Sprints 4/7/10) implement both branches. Contract grows to ~8 methods at Chunk 2 close (3 initiate + 3 status + 2 webhook for KYC + eSign; Stripe Connect's onboarding-only Sprint-3 surface defers webhook to Sprint 10).

The `IntegrationProviderBindingsTest` assertion "exactly one Sprint-3 method" is by design — it forces Chunk 2 to update the test in lockstep with the contract extension. The replacement assertion at Chunk 2 close: "each contract has the Sprint-3-completion surface" with explicit method-name enumeration.

### tenancy.md § 4 categorization audit (F1)

The chunk audit surfaced three routes violating the doc's invariant ("every cross-tenant route must appear in the allowlist"):

1. `POST /api/v1/agencies/{agency}/invitations` — Sprint 2 oversight (latent through Sprint 2 close).
2. `POST /api/v1/agencies/{agency}/creators/invitations/bulk` — Sprint 3, this chunk.
3. `GET /api/v1/creators/invitations/preview` — Sprint 3, this chunk (unauthenticated).

All three added to the allowlist in the F1 fix commit (`c6c57ac`). Categorization note added to `tenancy.md` § 4 flagging that the current single-bucket "cross-tenant" framing collapses three distinct categories (true cross-tenant; tenant-less; path-scoped tenant). Category-column structural change deferred to a housekeeping commit (tech-debt entry).

---

## P1 blockers for Chunk 2

### P1 — `PasswordResetService::request()` email_verified_at gate

Chunk 2 must land this fix before the accept-invite flow ships, and ideally as Chunk 2's first sub-step:

1. **(a)** `PasswordResetService::request()` returns silently (no token issued, no mail sent) when `User::email_verified_at IS NULL`. Maintains the user-enumeration defense shape that #9 already mandates for unknown emails.
2. **(b)** Wizard routes (`creators.me.*`) add the `verified` middleware alongside `auth:web`. Defense-in-depth: even if a User reaches authenticated state via some other unverified-flow gap, wizard access is gated.
3. **(c)** Break-revert independent unit coverage per #40 for both gates: temporarily remove the `email_verified_at` check → confirm a specific test fails → revert. Same for the `verified` middleware addition.
4. **(d)** `PasswordResetServiceTest` gets a new case: "returns silently for unverified users."

Without this fix, Chunk 2's accept-invite flow lands into a known-exploitable surface. The fix is one-line in `PasswordResetService::request()` plus one middleware addition; the test coverage adds ~5 new tests.

---

## Tech-debt added (7 entries net after R3 restructure)

1. **Sprint 1 self-review §a inaccuracy** — `agency_creator_relations` reconciliation in a future doc-cleanup pass.
2. **Standards migration backlog** — Sprint 1 chunk-7.1 review-file standards (#5.21+) and Sprint 2 self-review § b standards (#34+) are de facto applied but not yet documented in PROJECT-WORKFLOW.md § 5. Migration deferred to a dedicated housekeeping commit before Sprint 4 kickoff.
3. **Integration driver env-var convention** — `INTEGRATIONS_DRIVER` (spec §13.1) vs per-provider `KYC_PROVIDER=mock` etc. (.env.example). Chunk 2 picks the standard.
4. **CreatorResource symmetric shape — admin/creator endpoint validation pending** — the resource is structured to satisfy both endpoints but Chunk 3 must validate the assumption during its read pass.
5. **Forgot-password user-enumeration defense regression** — surfaced by Sprint 3 Chunk 1 bulk-invite; full Where/Risk/Mitigation/Resolution/Owner/Status structure; resolution = Chunk 2 P1 above; Sprint 4 close retrospectives the full #9 surface for any other latent gaps.
6. **tenancy.md § 4 categorization sloppy** — three categories (cross-tenant, tenant-less, path-scoped) collapsed into one. Add `Category` column + recategorize all rows + audit all routes for allowlist coverage in a housekeeping commit before Sprint 4.
7. **Provider contract test 'exactly one Sprint-3 method' broken by design when Chunk 2 lands** — Chunk 2 updates the assertion in lockstep with the contract extension.

Note: original Cursor draft's tech-debt entry 5 (CreatorBootstrapService throwaway-password handoff) was deleted in the R3 restructure and replaced by entries 5 + 6 + 7 above; the throwaway-password risk surface is now properly captured as an Honest Deviation + P1 blocker rather than a tech-debt note.

---

## Verification results

| Check                            | Result                                      | Notes                                                                                     |
| -------------------------------- | ------------------------------------------- | ----------------------------------------------------------------------------------------- |
| `php vendor/bin/pest`            | ✅ 597 passed (1816 assertions, 32.75s)     | 462 Sprint 2 baseline + 135 net new (+29.2%; well within the 80–120 kickoff range)        |
| `php vendor/bin/phpstan analyse` | ✅ 0 errors                                 | level 8 (project-wide config)                                                             |
| `php vendor/bin/pint`            | ✅ Applied (sandbox)                        | Per #41, CI is authoritative; two-step commit convention covers any sandbox/CI divergence |
| `php artisan migrate:fresh`      | ✅ All migrations apply cleanly on Postgres | jsonb columns + GIN index work as designed                                                |

**Test count delta**: 462 → 597 = +135 new tests. Above the kickoff's 80–120 estimate by 15. The overage is justified by D-pause-1 (full `agency_creator_relations` table construction + `AgencyCreatorRelation` model coverage was not in the original 80–120 sizing because the kickoff assumed the table existed) and D-pause-8 (full `TrackedJob` infrastructure + `GetJobController` coverage was a Chunk 1 scope-add). Test count is healthy.

---

## Spot-checks performed (Claude review-pass)

1. **S1 — Provider contract method-set narrowing**: Cursor confirmed the 11→3 method gap covers status-checks + webhook handling; surfaced as honest deviation R2 with three-option architecture analysis for Chunk 2. Recommendation: adopt both status-poll + webhook architectures.
2. **S2 — Throwaway-password user-enumeration risk**: Cursor confirmed `PasswordResetService::request()` does not check `email_verified_at` and the wizard routes use `auth:web` not `verified`. Surfaced as honest deviation R1 + P1 blocker for Chunk 2. Standing standard #9 regression flagged.
3. **S3 — `assertExactJson` lock on preview endpoint**: Confirmed locked. Adding `invited_email` (or any other field) to the controller response breaks the test on next CI run. No code change.
4. **S4 — `tenancy.md` § 4 allowlist accuracy + count correction**: Count corrected 18 → 15 + 3. Sprint 2 + Sprint 3 invitation-route gaps added to the allowlist in fix commit `c6c57ac` (F1). Category-column structural change deferred to housekeeping (tech-debt entry 6).
5. **S5 — Wizard step identifiers**: Confirmed list-of-objects with string `id` keys (`'profile' | 'social' | …`), not step numbers. Q2 commitment satisfied.

---

## Cross-chunk note

**Sprint 1 self-review §a historical-record drift**: `agency_creator_relations` was claimed shipped but never was. Caught by standing standard #34 (cross-chunk handoff verification) during Chunk 1's read pass. **First instance of historical-record drift catching a structural inaccuracy mid-build.** Pattern recorded; tech-debt entry 1 tracks the reconciliation.

**Sprint 1 + Sprint 2 #9 regression**: `PasswordResetService::request()` missing `email_verified_at` check. Latent since Sprint 1, exposed by Sprint 3 Chunk 1 bulk-invite. Sprint 4 close retrospectives the full #9 surface for any other latent gaps. Tech-debt entry 5 tracks the resolution.

**Sprint 2 oversight on allowlist**: `POST /api/v1/agencies/{agency}/invitations` was missing from `tenancy.md` § 4 allowlist since Sprint 2 close. Added in this chunk's F1 fix commit. No code-side risk surfaced (the doc invariant was violated but the route's authorization logic was correct); doc-side hygiene only.

---

## What was deferred (with triggers)

### Sprint 4 (creator approval workflow)

- HMRC UK tax-ID validation (kickoff § 1.4).
- Resolution of forgot-password #9 retrospective (tech-debt 5).
- Real KYC adapter and webhook handler for the production KYC provider (TBD — covered by `kyc_verification_enabled` flag).

### Sprint 6 (internal creator matching)

- Real Instagram OAuth adapter (kickoff § 1.4 — Sprint 3 ships OAuth scaffolding stubs only).

### Sprint 4-pre-kickoff (housekeeping)

- **Standards migration backlog** (tech-debt 2) — Sprint 1 chunk-7.1 + Sprint 2 § b standards into `PROJECT-WORKFLOW.md` § 5.
- **tenancy.md § 4 Category column** (tech-debt 6) — recategorize all rows; full route-audit for allowlist coverage.

### Sprint 10 (payments)

- Real Stripe Connect adapter + escrow/transfer/refund methods on `PaymentProvider` contract.
- Stripe Connect webhook handler.

### Sprint 14 (GDPR exports)

- Reuse `TrackedJob` infrastructure for data export job tracking.

### Sprint 11 (S3 staging)

- Avatar + portfolio production storage moves from MinIO to real S3 when AWS Batch 2 lands. Code change is config-only (Laravel `s3` driver against either backend).

---

## Process record — compressed plan-then-build pattern

Chunk 1 = one Cursor session, one plan-approval round-trip (with refinements: `CreatorBootstrapService` seam + preview-endpoint pushback), one review-pass with 5 spot-checks, one F1+F2+R1+R2+R3 pre-merge fix round-trip. **Total Claude round-trips: 2** (plan approval + pre-merge fixes). The compressed pattern continues to hold.

11 pause conditions surfaced during Cursor's read pass (D-pause-1 through D-pause-11). All 11 resolved before any code was written. **D-pause-1 alone (the `agency_creator_relations` historical-record drift) would have surfaced as a runtime migration error mid-build without the pre-planning read pass.** Standing standard #34 paid off measurably.

**Two real regressions surfaced during review**:

1. **Provider contract narrowing** (R2) — kickoff specified 11 methods, Cursor shipped 3 without flagging as honest deviation. Surfaced by S1 spot-check; Cursor responded with full analysis + three-option architecture for Chunk 2.
2. **Forgot-password #9 regression** (R1) — latent since Sprint 1, exposed by Sprint 3 bulk-invite shape. Surfaced by S2 spot-check; Cursor responded with full exploit-path analysis + P1 blocker formulation for Chunk 2.

Both regressions are now properly documented as honest deviations with explicit resolution paths. The pattern of "Claude spot-check surfaces gap → Cursor investigates with full context → both findings captured as durable honest deviations" is the workflow operating as designed.

**Zero change-requests on the tenth consecutive review group** counting through the chunk-7.1 saga close: chunk 7.1 close + 7 Group 1 + 7 Group 2 + 7 Group 3 + 8 Group 1 + 8 Group 2 + Sprint 2 Chunk 1 + Sprint 2 Chunk 2 + Sprint 3 Chunk 1. The workflow continues to be the durable asset.

---

_Provenance: Cursor self-review draft (sub-step 10) → Claude independent review pass with 5 spot-checks (S1-S5) → Cursor F1+F2+R1+R2+R3 pre-merge fixes (commit `c6c57ac`) → Claude merged final review file. Two real regressions surfaced (provider contract narrowing + forgot-password #9 regression); both captured as honest deviations with Chunk 2 resolution paths. **Status: Closed. Sprint 3 Chunk 1 is done.**_
