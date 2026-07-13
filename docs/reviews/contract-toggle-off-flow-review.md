# Toggle-OFF campaigns flow without contract involvement — Build Review

**Status: Closed** — approved (push HELD)
**Date:** 2026-07-13
**Baseline HEAD:** `94a357b` (origin/main)
**Scope:** Single chunk. Extends the contract-gate-decouple chunk (which added the agency _manual_ proceed-without-contract path but left the creator stranded at `accepted`). Ref: kickoff "Toggle-OFF campaigns flow without contract involvement", investigation I1–I6 (same thread).
**Provenance:** built + reviewed in the same session; two-commit pair (`98dec53` backend, `8260dd0` frontend) + full-board fixups + this docs commit, push HELD.

## Review verdict — approved

Approved on substance. **Three break-reverts verified** (Direction A: restore the unconditional flag gate → OFF-flows spec red; Direction B: drop the accept toggle read → ON-mandatory spec red; toggle-veto pin: remove the D-8 refusal → requires=true veto red — all reverted to green, the agency-controller file at zero diff). **§5.2 four-leg notification split green** (dispatched-event leg + no-mail leg + unchanged positive + false-fire fix). Full board green (see Local verification).

## Goal

A campaign's **"Require a per-campaign contract"** toggle (`requires_per_campaign_contract`) is a real product switch with two legitimate states:

- **OFF** — the assignment pipeline flows with **zero contract involvement**: the creator never sees, waits for, or hears about a contract.
- **ON** — unchanged; the creator-accepts-a-contract path is byte-identical to today.

The decouple chunk shipped an _agency_ button to escape the `accepted` dead-end, but a creator on an OFF campaign still landed in `accepted` with no step forward. This chunk makes the toggle load-bearing end-to-end.

---

## The core intent (D1) — flag vs. toggle

> The **toggle** (`requires_per_campaign_contract`) is the single source of "does this campaign need a contract"; the **flag** (`per_campaign_contract_enabled`) is the single source of "is the contract feature operational." They answer two different questions.

The flag governs the contract _feature_ (attaching / signing a real per-campaign contract), so it is load-bearing **only when a contract is actually involved**. A contract-less advance (`$contract === null`) is the _absence_ of the feature and is permitted regardless of the flag.

---

## Per-decision evidence

| Dec.   | Intent                                                      | Where                                                                                                                                                                                                       | Evidence (test)                                                                                                                    |
| ------ | ----------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| **D1** | Machine gate reads `$contract !== null`, not the flag alone | `CampaignAssignmentStateMachine::contract()` — gate is `$contract !== null && ! Feature::active(...)`; `$context` param threaded into `commit()`                                                            | machine: `a null (contract-less) advance succeeds even when …OFF`; `…gated …OFF AND a real contract is passed`                     |
| **D2** | OFF flows automatically on accept                           | `CreatorAssignmentController::accept` — one outer `DB::transaction`, chains `contract($a, null, $actor, ['auto_advanced'=>true])` when `!requires`                                                          | `OFF: creator accept auto-advances accepted → contracted with no contract and no agency action`                                    |
| **D3** | Creator copy consults the toggle                            | `CreatorAssignmentDraftController::show` meta `requires_per_campaign_contract`; api-client type; `CreatorAssignmentDetailPage.vue` `isAwaitingContract`/`isContractSigningDisabled` also require `requires` | FE `shows NO contract copy when accepted on an OFF (requires=false) campaign`; BE `creator show surfaces requires… = true`         |
| **D4** | One-shot idempotent stuck-row remediation                   | `campaigns:advance-contractless-accepted` (`--dry-run`, accepted-only + requires=false-only, drives the machine directly)                                                                                   | `AdvanceContractlessAcceptedAssignmentsTest` (idempotent, dry-run, skips ON, skips non-accepted, flag-independent, backfill audit) |
| **D5** | Accept-time snapshot posture                                | No re-evaluation listener on `CampaignController::update`; forward motion only via D2 (new accepts), D4 command, or the agency button                                                                       | (posture — see below)                                                                                                              |
| **D6** | Auto-advance is audit-distinguishable                       | `$context` → audit metadata + event; three signatures (below)                                                                                                                                               | `auto-advance …auto_advanced context`; backfill `source: backfill`; manual proceed `auto_advanced` absent                          |
| **D7** | ON behavior byte-identical                                  | issue/attach/sign untouched; D-8 veto untouched; flag-OFF refusals for real contract ops untouched                                                                                                          | `ON: requires=true accept stays at accepted`; the four decouple pins stay green                                                    |

---

## D5 — accept-time snapshot posture (recorded decision)

Flipping the toggle **ON after a creator accepted does NOT retroactively demand a contract** — already-`contracted` rows stay `contracted` (immutable progress, matching the contract-snapshot philosophy). Flipping **OFF after acceptance** advances existing stuck rows **only** via the D4 command or the agency button — **never automatically on edit**. `CampaignController::update` stays side-effect-free (no re-evaluation listener).

## Q2 — the flag-vs-toggle asymmetry (accepted, principled)

The machine permits `contract(null)` regardless of the flag, while the agency `proceed-without-contract` _endpoint_ keeps its `flagGate`. This is principled, not merely pin-preserving: **the endpoint is part of the contract feature's surface (flag territory), while the auto-advance is the absence of the feature (toggle territory).** It only manifests when the flag is manually turned OFF (non-default), and the D4 command drives the machine directly, so remediation is never blocked.

## Named finding — pre-existing false-fire fix (rides this chunk)

Since the decouple chunk shipped, the agency's `proceed-without-contract` path (`contract($assignment, null)`) has been sending the agency a **"the creator accepted the contract"** notification for a contract that never existed. `notifyAgencyOfContractAcceptance` is now gated on `contract_id !== null`, so a contract-less advance **never** announces a contract acceptance — regardless of which path produced it (auto-advance, backfill, or the agency button). The agency still learns of the accept itself via the existing accepted-notification, so no information is lost — only the false claim. This is a **bug fix**, logged as its own finding rather than a side effect.

---

## D6 — three distinguishable contract-less paths

| Path                          | Audit `metadata` signature                |
| ----------------------------- | ----------------------------------------- |
| Accept-chained auto-advance   | `auto_advanced: true` (no `source`)       |
| D4 backfill command           | `auto_advanced: true`, `source: backfill` |
| Agency manual proceed-without | (neither key present)                     |

A future reader can tell all three apart — a transition nobody manually performed never appears without saying why it happened.

---

## Gate table (contract-less advance vs. real contract, by flag)

| Caller                                       | `$contract` | flag ON                   | flag OFF                                 |
| -------------------------------------------- | ----------- | ------------------------- | ---------------------------------------- |
| Machine `contract()`                         | `null`      | advances                  | **advances** (D1 — flag irrelevant)      |
| Machine `contract()`                         | real        | advances                  | refuses `per_campaign_contract_disabled` |
| Creator accept (requires=false)              | `null`      | auto-advances             | auto-advances                            |
| Creator accept (requires=true)               | —           | stays accepted            | stays accepted                           |
| Agency `proceed-without-contract` (endpoint) | `null`      | advances (requires=false) | `flagGate` refuses (feature surface, Q2) |
| D4 command                                   | `null`      | advances                  | advances (drives machine directly)       |

---

## Break-revert (§5.35) — all three verbatim

**Direction A — restore the unconditional flag gate → the OFF-flows spec goes red.**
Machine gate `$contract !== null && ! Feature::active(...)` → `! Feature::active(...)`:

```
contracted requires the per_campaign_contract_enabled flag (the per-campaign manual contract flow).
  at tests/Feature/Modules/Campaigns/CampaignAssignmentContractTest.php:508
  ➜ 508  ->assertOk()
Tests: 1 failed
```

Reverted → green.

**Direction B — remove the toggle read in `accept` → the ON-mandatory spec goes red.**
`! $campaign->requires_per_campaign_contract` → (dropped): requires=true wrongly auto-advances:

```
Failed asserting that two strings are identical.
-'accepted'
+'contracted'
  at tests/Feature/Modules/Campaigns/CampaignAssignmentContractTest.php:521
Tests: 1 failed, 16 passed
```

Reverted → green.

**Toggle-veto pin — remove the D-8 mandatory refusal → the requires=true veto goes red.**
`if ($campaign->requires_per_campaign_contract)` → `if (false)`:

```
Expected response status code [422] but received 200.
Failed asserting that 200 is identical to 422.
  at tests/Feature/Modules/Campaigns/CampaignAssignmentContractTest.php:398
  ➜ 398  ->assertUnprocessable()
Tests: 1 failed
```

Reverted → green (the `CampaignAssignmentContractController.php` file shows **zero** diff post-revert — parity confirmed).

---

## §5.2 Event::fake split — the notification proof

- **Listener-swallowing leg** (`Event::fake([AssignmentTransitioned::class])`): `OFF: creator accept dispatches the assignment.contracted transition event` — proves the transition genuinely fires.
- **No-fake leg** (`Mail::fake`, listener runs): `OFF: creator accept auto-advances …` asserts `ContractAttachedMail` + `ContractAcceptedMail` are **not** queued — the listener ran and chose not to send.
- **Positive leg** (unchanged): `creator accept …notifies agency` still queues `ContractAcceptedMail` for a _real_ contract (`contract_id !== null`).
- **False-fire fix leg:** `the agency proceed-without-contract path does NOT announce a contract acceptance`.

---

## Coverage

| Area                              | Test                                                                                         | Result       |
| --------------------------------- | -------------------------------------------------------------------------------------------- | ------------ |
| D1 machine (Direction A + B pins) | `…gated …OFF AND a real contract is passed`; `null contract-less advance succeeds …OFF`      | ✓            |
| D1/D6 context seam                | `contract: the $context is merged into the transition audit metadata`                        | ✓            |
| D2 auto-advance + §5.34 negative  | `OFF: creator accept auto-advances …no contract and no agency action` (draft-submittable)    | ✓            |
| D2 flag-independence              | `OFF auto-advance still works when per_campaign_contract_enabled is OFF`                     | ✓            |
| D7 ON path                        | `ON: requires=true accept stays at accepted`; the four decouple pins                         | ✓            |
| Q-notify §5.2 split               | dispatched-event leg + no-mail leg + false-fire fix + unchanged positive                     | ✓            |
| D6 three-way distinguishability   | auto-advance / backfill / manual audit signatures                                            | ✓            |
| D3 backend meta                   | `creator show surfaces requires… = false / = true`                                           | ✓            |
| D3 FE §5.34 negative              | `shows NO contract copy when accepted on an OFF campaign` + the two requires=true copy tests | ✓ (17 tests) |
| D4 command                        | idempotent / dry-run / skips ON / skips non-accepted / flag-independent / backfill audit     | ✓            |

### Local verification (full board)

- **Backend Pest full** (serial, `-d memory_limit=2G`) → **1841 passed, 1 skipped** (the 1 skip is pre-existing).
- **Frontend Vitest full** → main **1177 passed** (130 files), admin **425 passed** (51 files). Locale parity spec (`i18n-locale-parity.spec.ts`) green — zero new keys (OFF renders nothing).
- **Playwright E2E full** → **24/24** (22 main + 2 admin). The four invitations specs green (D2's auto-advance does not touch the invite/accept-user flows).
- **Typecheck** (PHPStan full `composer stan` + vue-tsc across main/admin/ui + api-client tsc) → clean. **Lint** (`pint --test` all + ESLint) → clean (2 pre-existing `v-html` warnings on unrelated files).

#### Full-board fixture ripples (fixed this pass — both are correct-behaviour updates, not regressions)

1. `CreatorAssignmentTest` — `accepts an invited assignment (invited → accepted)` used a requires=false campaign, which now auto-advances (D2). Set the campaign `requires_per_campaign_contract = true` so the test still pins the accept transition in isolation.
2. `NotificationFanOutTest` — `contracted fans out …` fired a contracted event on a contract-less assignment, which the Q1 gate now correctly silences. Attached a `contract_id` so the test exercises a genuine contract-acceptance fan-out (the contract-less silence is covered by `CampaignAssignmentContractTest`).

> Note: PHPStan's parallel worker hits a pre-existing 128 MB `memory_limit` fatal in isolation; `composer stan` sets the limit and passes. Not introduced by this chunk. Three Vitest specs on unrelated onboarding surfaces (`CreatorProfilePage`, `Step2ProfileBasicsPage`) timed out only under 3-way parallel CPU contention; green in isolation.

---

## Divergences / autonomous decisions (none are scope changes)

1. **Atomicity of the accept chain:** the two flips (`accept` then `contract(null)`) run in **one outer `DB::transaction`** so an OFF accept is all-or-nothing.
2. **Meta eager-load:** `requires_per_campaign_contract` added to the `show` campaign column select (the column was previously projected out).
3. **Backfill context key:** `source: backfill` chosen as the discriminator for the D4 path (vs. the accept-chained `auto_advanced` alone).

## Post-deploy step (joins the AH-026 recompute in the pending-deploy list)

Run **once** after deploy (optionally `--dry-run` first):

```
php artisan campaigns:advance-contractless-accepted
```

Idempotent — advances the stuck `accepted` rows on requires=false campaigns to `contracted`; a second run reports 0. No scheduler — must not be forgotten at the next deploy.

## Commit plan (two-commit pair — push HELD)

1. **Backend:** machine gate + `$context`; accept auto-advance; notification gate (incl. false-fire fix); draft-show meta; D4 command; tests.
2. **Frontend:** api-client type; `CreatorAssignmentDetailPage.vue` conditions; FE spec.
