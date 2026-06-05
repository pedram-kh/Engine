# Decouple per-campaign contract gate from the e-sign vendor flag — Build Review

**Status: Closed**
**Date:** 2026-06-05
**Scope:** Single chunk. Amends the contract-bridge chunk's D-4 (which rode the manual per-campaign flow on `contract_signing_enabled`).

## Goal

Ship the manual per-campaign contract flow to **production without e-sign**: `contract_signing_enabled` stays OFF (master wizard click-through, zero vendor calls), `per_campaign_contract_enabled` ON (manual flow live). One flag was doing two jobs — gating BOTH the per-campaign manual flow AND the master-contract onboarding wizard's vendor-envelope mode. This chunk gives the manual flow its own gate, leaving the wizard untouched.

## The two risks this build was structured around

1. **G3 — re-point the manual flow, LEAVE the wizard.** Mis-classifying a consumer either re-couples the bug or breaks onboarding.
2. **L1 — don't recreate the dead-end.** `accepted` has exactly one outgoing edge (`contract()`); a `requires=false` campaign that isn't contracted would be stuck at `accepted` unless this chunk adds a no-contract advance path.

---

## Consumer classification (G3 — every consumer + its disposition)

### RE-POINTED → `per_campaign_contract_enabled` (D-3/D-5/D-6)

| #   | Consumer                   | File                                        | Change                                                                                                      |
| --- | -------------------------- | ------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| 1   | Machine `contract()` gate  | `CampaignAssignmentStateMachine.php`        | `Feature::active` check + thrown exception → `perCampaignContractDisabled()`                                |
| 2   | Exception factory          | `AssignmentTransitionGatedException.php`    | `contractSigningDisabled()` → `perCampaignContractDisabled()` (`assignment.per_campaign_contract_disabled`) |
| 3   | Attach `flagGate()`        | `CampaignAssignmentContractController.php`  | flag + error code                                                                                           |
| 4   | Creator accept gate        | `CreatorAssignmentContractController.php`   | flag + error code                                                                                           |
| 5   | Assignment-detail meta key | `CreatorAssignmentDraftController::show`    | `contract_signing_enabled` → `per_campaign_contract_enabled` (D-5)                                          |
| 6   | FE consumer                | `CreatorAssignmentDetailPage.vue`           | meta read + `perCampaignContractEnabled` ref                                                                |
| 7   | Type                       | `packages/api-client/src/types/campaign.ts` | `CreatorAssignmentDetailResponse.meta` key rename                                                           |

### LEFT on `contract_signing_enabled` (D-4 — DO NOT TOUCH; the genuine vendor / master-wizard path)

| #   | Consumer                            | File                                                           | Why it stays                                       |
| --- | ----------------------------------- | -------------------------------------------------------------- | -------------------------------------------------- |
| 1   | Wizard master-contract initiate     | `CreatorWizardService::initiateContract`                       | e-sign envelope creation (vendor)                  |
| 2   | Wizard click-through fallback       | `CreatorWizardService::acceptClickThroughContract`             | master-contract onboarding                         |
| 3   | Wizard completion poll              | `WizardCompletionService::pollContract`                        | vendor envelope status                             |
| 4   | Completeness step + applicableSteps | `CompletenessScoreCalculator`                                  | gates the master **wizard** step, not per-campaign |
| 5   | Wizard flags map                    | `CreatorResource` (`wizard.flags`)                             | creator-side wizard flag surface                   |
| 6   | Provider binding                    | `CreatorsServiceProvider::makeProviderResolver(EsignProvider)` | the vendor seam                                    |
| 7   | Skipped provider stub               | `SkippedEsignProvider` (+ `CreatorFeatureFlagsTest`)           | the no-silent-vendor-calls invariant               |
| 8   | FE wizard flag map + creator type   | `useFeatureFlags.ts`, `creator.ts`                             | onboarding wizard surface                          |

> The classification IS the chunk. The master-wizard tests stay GREEN on `contract_signing_enabled` (`CreatorWizardFlagOffTest`, `ClickThroughContractRecordTest`, `CompletenessScoreCalculatorTest`, `CreatorResourceTest`) — the proof G3 was correct: re-pointing the per-campaign consumers did not disturb onboarding.

---

## The build

### Backend

- **D-1/D-2** — new `PerCampaignContractEnabled` feature class (`default ⇒ true`), registered as the 5th `Feature::define` in `CreatorsServiceProvider::registerFeatureFlags()`. The default-ON exception is documented in the class docblock + `feature-flags.md`.
- **D-3** — re-pointed the machine gate + the attach/accept controller gates (table above).
- **D-6** — new exception code `assignment.per_campaign_contract_disabled` (new factory; the `contractSigningDisabled` factory is retired).
- **D-5** — meta-key rename backend + type + Vue.
- **D-7/D-8** — agency **proceed-without-contract** endpoint: `POST …/assignments/{assignment}/contract/proceed-without-contract`, calling `contract($assignment, null, $actor)` (reuses the single existing machine edge — NO second edge). Refuses 422 `assignment.per_campaign_contract_required` when `campaign.requires_per_campaign_contract = true`. Route + policy (`CampaignPolicy::attachContract` — admin/manager/staff, the execute precedent) + the flag gate.
- **D-7 (FE surfacing)** — `per_campaign_contract_enabled` added to the assignments index `meta` so the Creators tab can gate the action.

### Frontend

- Meta-key rename FE + type (D-5).
- Agency "Proceed without contract" action on the `accepted` row alongside "Issue contract" — visible only when `requires_per_campaign_contract === false` AND the flag is ON; success + error snackbars (error maps `assignment.per_campaign_contract_required`).
- API method `campaignsApi.proceedWithoutContract` + `ProceedWithoutContractResponse` type.
- i18n en/pt/it for the new action + the required-error.

### Docs (all amendments)

- `feature-flags.md` — new flag row (default ON ⚠), the D-2 justification on the Default-OFF convention, and the assignment-addendum note moved off the `contract_signing_enabled` row.
- `06-INTEGRATIONS.md` §4.5–4.6 — re-pointed the manual-flow flag references; rewrote §4.6's flag section (the two-flag split).
- `03-DATA-MODEL.md` — `requires_per_campaign_contract` runtime use (D-9).
- `tech-debt.md` — closed the D-8 deferral.
- `security/tenancy.md` — the per-campaign accept route flag note re-pointed (the `:142` master wizard click-through row STAYS).

---

## Coverage (§5.17; break-revert §5.35)

| Area                                 | Test                                                                                                                                                              | Result       |
| ------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------ |
| Re-pointed break-revert (attach)     | `attach is unavailable when per_campaign_contract_enabled is OFF` → 422 `per_campaign_contract_disabled`                                                          | ✓            |
| Re-pointed break-revert (accept)     | `accept is unavailable when per_campaign_contract_enabled is OFF`                                                                                                 | ✓            |
| Re-pointed break-revert (machine)    | `contract is gated when per_campaign_contract_enabled is OFF`                                                                                                     | ✓            |
| Decoupling proof (X3)                | `the master wizard e-sign flag stays OFF throughout the per-campaign flow` (contract_signing OFF + per_campaign ON)                                               | ✓            |
| Wizard untouched (G3)                | `CreatorWizardFlagOffTest` / `ClickThroughContractRecordTest` / `CompletenessScoreCalculatorTest` / `CreatorResourceTest` all green on `contract_signing_enabled` | ✓            |
| No-contract advance (requires=false) | `proceed-without-contract advances accepted → contracted with no contract row` (`contract_id` null, 0 Contract rows)                                              | ✓            |
| Not a dead-end (L1)                  | `after proceed-without-contract the existing draft submit flow continues`                                                                                         | ✓            |
| Mandatory enforced (requires=true)   | `proceed-without-contract is refused … 422 per_campaign_contract_required`; assignment stays `accepted`                                                           | ✓            |
| Mandatory: the only exit             | `a requires=true assignment can still reach contracted via creator-accepts-a-contract`                                                                            | ✓            |
| `contract(null)` machine pin (D-7)   | `contract: accepted → contracted with a null contract leaves contract_id null`                                                                                    | ✓            |
| Flag default                         | `registers per_campaign_contract_enabled with default ON` (+ NOT in the default-OFF set) + a default-ON round-trip                                                | ✓            |
| FE — action gating                   | `CampaignDetailPage.spec` — visible only requires=false + flag ON; hidden otherwise; calls the API                                                                | ✓ (15 tests) |
| FE — meta rename                     | `CreatorAssignmentDetailPage.spec` updated to `per_campaign_contract_enabled`                                                                                     | ✓            |

### Local verification

- `php artisan test --filter="CreatorFeatureFlags|CampaignAssignmentStateMachine|CampaignAssignmentContract"` → **87 passed**.
- Campaigns suite + wizard-contract-flag Creators tests → **184 passed**.
- `apps/main` vitest → **852 passed** (incl. the new specs).
- Typecheck (`@catalyst/api-client` + `@catalyst/main`) → clean. Pint + PHPStan (touched files) → clean. ESLint (touched FE files) + Prettier (touched JSON/TS) → clean.

> Note: running the entire `tests/Feature/Modules/Creators` directory in one process hits a pre-existing PHP `memory_limit` (134 MB) fatal inside an unrelated Stripe mock test; re-running with `-d memory_limit=512M` passes. Not introduced by this chunk.

---

## Divergences / autonomous decisions (none are scope changes)

1. **Flag surfacing to the agency FE** (the kickoff didn't specify the mechanism): added `per_campaign_contract_enabled` to the **assignments index `meta`** (optional key, back-compat). The Creators tab gates the action on it + `campaign.requires_per_campaign_contract`.
2. **Two error codes, two layers:** `assignment.per_campaign_contract_disabled` is the machine exception factory (D-6); `assignment.per_campaign_contract_required` is the controller-level guard co-located on the advance endpoint (D-8).
3. **Policy reuse:** the advance endpoint authorizes via `CampaignPolicy::attachContract` (admin/manager/staff — already the execute role set) rather than a new ability.
4. **Route name:** `…/contract/proceed-without-contract` (D-7 — "proceed without a per-campaign contract," not "skip").

## Honest-deviation triggers — none fired

- No "stays" consumer (wizard / `CompletenessScoreCalculator` / `CreatorResource` / provider binding) was re-pointed.
- `requires=false` assignments can leave `accepted` (the advance path is wired) — no recreated dead-end.
- No second machine edge added — `contract(null)` reused, graph stays single-edged.
- The default-ON flag ships with the written justification (`feature-flags.md`).
- `contract_signing_enabled` master-wizard behaviour is unchanged — this chunk only **removed** per-campaign consumers from that flag.

## Spot-check anchors

- Per-campaign flow gated by `per_campaign_contract_enabled` (flag OFF → the new 422). ✓
- Master wizard tests stay green on `contract_signing_enabled` (onboarding undisturbed). ✓
- `requires=false` advances via the no-contract path (`accepted → contracted` → draft flow). ✓
- `requires=true` refuses the no-contract path (only exit is creator-accepts-a-contract). ✓
- New flag registers default ON with a written justification. ✓
- Meta key + exception code renamed (no vendor-flag names on the per-campaign surface). ✓
- Shippable state: `contract_signing_enabled` OFF + `per_campaign_contract_enabled` ON. ✓

## Commit plan (two-commit pair — NOT committed until spot-check)

1. **Backend:** `PerCampaignContractEnabled` + registration; re-pointed gates + new exception factory; proceed-without-contract endpoint + route + index meta; meta-key rename (backend); tests; docs.
2. **Frontend:** meta-key rename FE + type; the proceed-without-contract action + api method + types; i18n (en/pt/it); FE specs.
