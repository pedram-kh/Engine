# Sprint 4 — Chunk 2 Review (Real Stripe Connect onboarding adapter)

**Status:** **Closed.** Build complete, all gates green (pint clean, phpstan 0 errors, full backend suite + targeted Creators suite green, frontend specs green), all four spot-check anchors break-revert-verified, spot-check passed, one pre-merge verification (PMC — contract-shape pin) confirmed clean — see "Pre-merge verification" below.

**Reviewer:** drafted by Cursor (implementation); spot-checked + closed by the reviewer after the PMC.

**Reviewed against:** the Chunk 2 kickoff + the plan-approval message (commit grouping = two commits: build [2a+2b] then docs); the locked decisions D-c2-1 … D-c2-11; `06-INTEGRATIONS.md §2` (and the documented divergences from it); `PROJECT-WORKFLOW.md` §5 (5.1 source-inspection, 5.15 deliberate-allowlist, 5.17 defense-in-depth, 5.35 break-revert-with-`git`-restore, 5.36 asymmetric-coverage-acknowledgement), §6 (sub-chunk planning), §8 (tech-debt append-don't-delete).

The chunk has one goal: **implement the real `StripePaymentProvider` behind the existing 2-method `Creators` seam, and add the one in-scope webhook (`account.updated`)** — no new architecture. Split backend-first into **2a** (adapter + webhook) and **2b** (wire the existing wizard UI through the real flow). Sectioned below.

---

## Plan-pause outcome (read-pass confirmation)

The read pass confirmed the kickoff's inventory against the built code: the 2-method `App\Modules\Creators\Integrations\Contracts\PaymentProvider`, its DTOs, the flag-gated resolver, the `creator_payout_methods` connected-account columns, `Step7PayoutPage.vue` with its OFF fallback, and the full inbound-webhook stack (ingestor + `integration_events` `(provider, provider_event_id)` dedup + queued jobs + audit actions) all exist as described. No divergence beyond D-c2-1/2 was found — the contract name, the absent `getOnboardingLink`, and the DTO names matched the read pass exactly.

**Honest-deviation triggers — resolved:**

- **2-method contract sufficient for `account.updated`?** Yes. The webhook surface needed exactly the two methods the docblock already named (`verifyWebhookSignature` + `parseWebhookEvent`) plus the `PaymentsWebhookEvent` DTO — the same shape `KycProvider`/`EsignProvider` already use. No pressure to widen toward the Sprint-10 escrow contract. ✅
- **Stripe test-mode calls CI-safe?** No — live calls need network + secrets. Took the documented **test seam** (see "Test seam" below) rather than skipping coverage. ✅
- **`requirements_currently_due` display need (D-c2-7)?** No display need surfaced — the wizard step shows redirect/return state, not an itemized requirements list. **No migration.** The 4-value `PayoutStatus` over the existing `status` column is sufficient. ✅
- **Further `06-INTEGRATIONS §2` divergence?** None beyond D-c2-2 (module path), recorded below.

---

## 2a — Real Stripe adapter + `account.updated` webhook (backend)

**Dependency:** `stripe/stripe-php` added to `composer.json` + lockfile (D-c2-10).

**Contract extension (D-c2-3):** `PaymentProvider` grows from 2 → 4 methods:

```php
public function verifyWebhookSignature(string $payload, string $signature): bool;
public function parseWebhookEvent(string $payload): PaymentsWebhookEvent;
```

- The new **`PaymentsWebhookEvent`** DTO (`Integrations/DataTransferObjects/`) carries `providerEventId`, `eventType`, `accountId`, `payoutStatus`, `chargesEnabled`, `payoutsEnabled`, `rawPayload`. It owns the **single source of truth** for status mapping via a static `mapPayoutStatus(chargesEnabled, payoutsEnabled, requirementsCurrentlyDue)` — co-located on the DTO like `AccountStatus::isFullyOnboarded`, so the mock and the real adapter map identically.
- Mapping rule: `charges_enabled && payouts_enabled && no requirements due → Verified`; `requirements due → Restricted`; otherwise `Pending`.
- **Mock + both stubs gained the two methods.** `MockPaymentProvider` verifies via HMAC-SHA256 against `integrations.payment.mock_webhook_secret` and parses the mock payload shape (so flag-on/mock-driver dev still works end-to-end). `SkippedPaymentProvider` throws `FeatureDisabledException`; `DeferredPaymentProvider` throws `ProviderNotBoundException` — preserving the structural flag-off / not-bound guarantees on the new surface too.

**Real adapter — `app/Modules/Creators/Integrations/Stripe/StripePaymentProvider.php` (D-c2-2):**

- Lives alongside the mock it implements, **NOT** at `06-INTEGRATIONS.md:122`'s `Modules/Payments/Integrations/Stripe/`. **Deliberate divergence** (D-c2-1/2): the spec path presumes the spec's broad contract home, which we deferred; standing up `Modules\Payments\Contracts` now would front-load Sprint-10 escrow structure for no Chunk-2 benefit. Recorded as a Sprint-10 migration in `tech-debt.md`.
- `createConnectedAccount(Creator)` → creates a real Connect **Express** account + an onboarding `AccountLink` (using the `return_url`/`refresh_url` from config), mapped into `PaymentAccountResult`.
- `getAccountStatus(Creator)` → reads the persisted `provider_account_id` off `CreatorPayoutMethod`, retrieves the Stripe account, maps its flags → `AccountStatus`.
- `verifyWebhookSignature` → delegates to `Stripe\WebhookSignature::verifyHeader` (HMAC + timestamp tolerance), so we get Stripe's full scheme rather than a hand-rolled HMAC.
- `parseWebhookEvent` → parses the raw JSON, and only `account.updated` yields a non-null `payoutStatus` (via the shared `mapPayoutStatus`); other event types parse to an envelope with `payoutStatus = null` (ignored downstream).

**Resolver (D-c2-9):** `CreatorsServiceProvider::makeProviderResolver` now takes a `realDrivers` map; `PaymentProvider::class` resolves to `StripePaymentProvider` **only** when (`creator_payout_method_enabled` ON) **and** (`PAYMENT_PROVIDER=stripe`). Flag OFF → `SkippedPaymentProvider` (the §52 structural guarantee, unchanged). `StripeClient` is bound from `integrations.payment.stripe.secret_key`; the adapter receives it plus the non-secret `return_url`/`refresh_url`/`webhook_tolerance` and the `webhook_secret`. **No production flag flip** — adapter is bound-but-unreachable in prod.

**Secrets (D-c2-10):** `config/integrations.php` gains a non-secret `payment.stripe` block (`return_url`, `refresh_url`, `webhook_tolerance`) + secret material (`secret_key`, `webhook_secret`, `connect_client_id`) read from ENV → Secrets Manager (`catalyst/${env}/api/stripe`). No secrets in code or env files. Also added `payment.mock_webhook_secret` for the mock's offline HMAC.

**Provider-string fix:** `CreatorWizardService::initiatePayout` previously hardcoded `'provider' => 'mock'` when stamping `creator_payout_methods`. Now derives it from `config('integrations.payment.driver')`, so the row is correctly stamped `'stripe'` when the real adapter runs. (No existing test pinned `provider == 'mock'`, so the change is regression-safe — verified by running the full Creators suite.)

**Webhook pipeline (`account.updated` only) (D-c2-4/5/6):**

- Route: `POST /api/v1/webhooks/stripe` under `throttle:webhooks`, tenant-less (added to the security allowlist — see below). Controller `StripeWebhookController` mirrors `KycWebhookController` exactly: `Stripe-Signature` header → `InboundWebhookIngestor` verifies + parses + dedup-inserts into `integration_events` (provider key `'stripe'`) → dispatches `ProcessStripeWebhookJob`. Empty/malformed payload → handled; duplicate event → 200 (idempotent via the `(provider, provider_event_id)` unique index).
- `ProcessStripeWebhookJob` mirrors `ProcessKycWebhookJob`: re-parses the stored event, looks up `CreatorPayoutMethod` by `provider_account_id`, updates `status` + `verified_at`, and on the **first** transition to `Verified` flips `creators.payout_method_set` (existing rollup) and emits the `CreatorWizardPayoutCompleted` audit. The generic `integration.webhook.received/processed` envelope is emitted by the ingestor (D-c2-6 — no new action needed).
- **D-c2-5 (load-bearing):** the handler maps onto `creator_payout_methods.status` and **never touches `creators.kyc_status`** — payout-KYC and identity-KYC stay separate layers. Pinned by a dedicated test.

### Test seam (CI-safety, honest-deviation #2)

Live Stripe test-mode calls need network + secrets, which aren't CI-safe. The coverage uses a **faked `StripeClient`** (an anonymous subclass with stubbed `AccountService`/`AccountLinkService`) in the adapter test, and **offline HMAC signature generation** (a `stripeSignature` helper computing the `Stripe-Signature` header) in the webhook tests. No live API calls; the mapping + signature-verification + ingestion logic is fully exercised. This is the documented seam, flagged here rather than skipping coverage.

### Defense-in-depth — break-revert verified (§5.17 + §5.35)

Each anchor was broken, the guarding test confirmed to fail, then reverted (`git`-restore-verified clean, no `BREAK-REVERT` markers remain):

1. **D-c2-5 separation** — made the job write `creators.kyc_status`; the separation test failed. Reverted → green. ✅
2. **Status mapping** — forced `mapPayoutStatus` to always return `Verified`; the restricted-status test failed. Reverted → green. ✅
3. **Webhook idempotency** — removed the already-terminal early return _and_ the job-level guard; the no-re-emit test failed. Reverted both → green. ✅
4. **Flag-off guarantee (§52)** — made `SkippedPaymentProvider::verifyWebhookSignature` return instead of throw; the flag-off test failed. Reverted → green. ✅

---

## 2b — Wire the real flow through the existing wizard UI

- The existing `Step7PayoutPage.vue` ON-branch (`initiatePayout()` → redirect) and the `useVendorBounce('payout')` return already carry through unchanged with real Stripe onboarding URLs — the change is **server-side only** (the resolver swaps mock→real). No new UI surface (D-c2-8): no separate settings/profile payout page was built.
- **Stale docblock fixed** (D-c2-8): the old `:12` comment ("payout_method_set flips once the webhook lands") now reflects Sprint-3 reality — `payout_method_set` flips via the **status-poll on bounce-return** _or_ the now-real **`account.updated` webhook** as the authoritative async update. Added a note that `feature-flags.md:44`'s "profile shows payout setup pending" describes a _future_ profile surface, not-built and out of scope.
- The OFF fallback (`payout-flag-off` alert) is untouched and still correct.
- **Coverage:** the existing `Step7PayoutPage` specs stay green. The change altered no component contract (no new props/events/calls — `initiatePayout()` is still the only call), so no new spec was warranted; this is an honest asymmetric-coverage acknowledgement (§5.36) — the real-driver path is exercised by the backend adapter/webhook tests, not a frontend spec.

---

## Divergences from spec (recorded)

| #                         | Spec line                                                                       | Built reality                                                                            | Why                                                                                                                                            |
| ------------------------- | ------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| D-c2-1                    | `06-INTEGRATIONS §2` broad `Modules\Payments\Contracts\PaymentProviderContract` | Extended the built 2→4-method `Creators\Integrations\Contracts\PaymentProvider` in place | The live binding/mock/stubs/UI all target the `Creators` contract; the broad escrow-bearing contract is Sprint-10.                             |
| D-c2-2                    | `06-INTEGRATIONS.md:122` `Modules/Payments/Integrations/Stripe/`                | `app/Modules/Creators/Integrations/Stripe/StripePaymentProvider.php`                     | Follows D-c2-1's contract home; migrates with the contract at Sprint 10 (logged in `tech-debt.md`).                                            |
| D-c2-5                    | `06-INTEGRATIONS:127` "update creator KYC status"                               | Drives `creator_payout_methods.status`, **not** `creators.kyc_status`                    | The spec line means Stripe **payout-KYC**, which `:78` separates from identity-KYC; conflating them would corrupt identity-verification state. |
| `services.md:70` mislabel | called the built interface `PaymentProviderContract`                            | corrected to `App\Modules\Creators\Integrations\Contracts\PaymentProvider`               | Doc-only mislabel caught at read pass; fixed in the docs commit.                                                                               |

---

## Gates

- **Pint:** clean (`{"tool":"pint","result":"passed"}`).
- **PHPStan:** `[OK] No errors`.
- **Backend tests:** full suite green (919 passed at last full run; targeted Creators suite 300 passed / 932 assertions after all edits). 38 new/extended assertions across `StripePaymentProviderTest`, `StripeWebhookControllerTest`, `ProcessStripeWebhookJobTest`, `IntegrationProviderBindingsTest`.
- **Frontend:** `Step7PayoutPage` specs green (docblock-only change).

---

## Pre-merge verification (PMC — contract-shape pin)

The pre-existing `tech-debt.md` entry ("Provider contract test … broken by design when Chunk 2 lands") predicted that extending `PaymentProvider` 2→4 methods would break the enumerated contract-shape assertion, with the risk that an author might _delete_ the failing assertion rather than update it. **Confirmed: the assertion was updated, not deleted or weakened.**

- `IntegrationProviderBindingsTest::"the three contracts each define exactly their built surface (KYC: 4, eSign: 4, Payment: 4)"` now enumerates Payment's four methods — `createConnectedAccount`, `getAccountStatus`, `parseWebhookEvent`, `verifyWebhookSignature` — bringing the total **10 → 12** (KYC 4 + eSign 4 + Payment 4).
- It still uses an exact `expect($actual)->toBe($expected)` match on the sorted public-method list per contract, so it **still fails if any method is added or removed** — the contract-growth guard is intact, not weakened.
- The paired source-inspection docblock check (`"each contract docblock documents its built surface for #34 cross-chunk handoff verification"`) was updated in lockstep: Payment pins `Inbound-webhook surface (Sprint 4 Chunk 2`; KYC/eSign keep `Sprint 3 completion surface`.
- The `tech-debt.md:422` entry's closed-status note is updated to record that the predicted break fired and was handled correctly; the pinned Payment surface is now **"Sprint-3-completion + Chunk-2 webhook"**.
- Both pin tests verified green (`2 passed`).

## Out of scope (logged, Sprint 10)

No escrow methods, no `payments`/`payment_events` model, no `campaign_assignments`, no `Modules\Payments\Contracts` stand-up, no production flag flip, no new settings/profile payout surface. Mock KYC/e-sign untouched. The Sprint-10 payments work (escrow + 8 money-movement webhooks + the ledger model + the `Creators`→`Modules\Payments` migration) is captured as a new `tech-debt.md` entry.

---

## Commit shape

Two commits per the approved plan:

1. **Build (2a + 2b):** the contract/DTO/mock/stub/adapter/resolver/config/webhook/job/route/service changes, the backend tests, the `Step7PayoutPage` docblock fix, and the `docs/security/tenancy.md` allowlist entry for `/api/v1/webhooks/stripe` (build-correctness: a tenant-less route must be allowlisted).
2. **Docs:** `services.md` (Stripe row + mislabel fix), `tech-debt.md` (Sprint-10 payments entry), and this review doc.

Committed after spot-check + the PMC contract-shape verification.

---

## Spot-check anchors (for the reviewer)

1. `account.updated` → `PayoutStatus` mapping with `creators.kyc_status` untouched (break-revert #1 + #2 above).
2. Webhook idempotency via `integration_events` `(provider, provider_event_id)` (break-revert #3).
3. Flag-off `SkippedPaymentProvider` guarantee holds on the new webhook methods (break-revert #4).
4. The real adapter reaches Stripe test-mode via the documented faked-client / offline-HMAC test seam (no live CI calls).
5. The `Modules/Payments` path divergence (D-c2-2) is recorded (this doc + `tech-debt.md`).
6. The stale `Step7PayoutPage.vue:12` docblock is fixed.
