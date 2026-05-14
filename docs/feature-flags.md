# Feature flags registry — Phase 1

This document is the **single source of truth** for every Laravel Pennant feature flag in Phase 1. The registry exists so that:

- When a sprint builds a vendor-dependent feature, the code is complete against the contract — but the user-facing entry point is gated by a flag that defaults to OFF.
- When a flag is OFF, the UX gracefully degrades. The "off-state behavior" column below describes the fallback the UI must implement.
- Operators can enable a flag without a code deploy, once the vendor manual steps in [`SPRINT-0-MANUAL-STEPS.md`](SPRINT-0-MANUAL-STEPS.md) are complete.

## How to use this registry

### When you build a feature that depends on a vendor

1. Find (or add) the row below for the flag that gates it.
2. Implement the integration code complete against the contract in [`06-INTEGRATIONS.md`](06-INTEGRATIONS.md). Provide a mock implementation for tests.
3. Wrap the user-facing entry points (routes, buttons, wizard steps, navigation links) in a Pennant `Feature::active(...)` check.
4. Implement the off-state behavior described in this registry. Examples:
   - Wizard step skipped with placeholder messaging.
   - Button hidden, with a tooltip on whatever takes its place: "Available once we enable …".
   - Alternative flow engaged (e.g., "paid offline" with reference number when payment processing is off; click-through acceptance when e-sign is off; admin-can-approve-without-KYC when KYC is off).
5. Update the **Off-state behavior** and **Manual steps to enable** cells when you ship — they start as placeholders.

### When you turn a flag ON

Flags default to OFF. The operator turns them on per scope (per-tenant, per-user, or globally) by:

1. Completing the manual steps in the registry row.
2. Verifying secrets in AWS Secrets Manager match what the code expects.
3. Running the artisan command (Sprint 1+ adds `php artisan pennant:set <flag> <scope>`).

Pennant install + the actual flag definitions live in `apps/api/app/Modules/<Module>/Features/`. The package is added in **Sprint 1** (Identity), once we have authenticated users to scope flags against.

## Sprint 0 status

This document is the registry. **No flags are defined in code yet** — Pennant is added in Sprint 1. This file will be updated by every subsequent sprint that ships a vendor-dependent feature.

## Phase 1 flags

| Flag                            | Gates                                                                | Default | Off-state behavior                                                                                                                                                                                                                                                                                                                                                                                                                                                                 | Manual steps to enable                                                                                                                      |
| ------------------------------- | -------------------------------------------------------------------- | ------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `social_oauth_meta_enabled`     | Instagram OAuth connect button + `/oauth/meta/*` endpoints           | off     | _To be filled when Sprint 5 ships._ Expected: "Connect Instagram" button hidden; show placeholder "Coming soon" caption.                                                                                                                                                                                                                                                                                                                                                           | Batch 1 §1.2 + secret `catalyst/${env}/api/oauth/meta` populated + Meta App Review approved.                                                |
| `social_oauth_tiktok_enabled`   | TikTok OAuth connect button + `/oauth/tiktok/*` endpoints            | off     | _To be filled when Sprint 5 ships._ Expected: "Connect TikTok" button hidden; placeholder caption.                                                                                                                                                                                                                                                                                                                                                                                 | Batch 1 §1.3 + secret `catalyst/${env}/api/oauth/tiktok` populated + TikTok app approved.                                                   |
| `social_oauth_youtube_enabled`  | YouTube OAuth connect button + `/oauth/google/*` endpoints           | off     | _To be filled when Sprint 5 ships._ Expected: "Connect YouTube" button hidden; placeholder caption.                                                                                                                                                                                                                                                                                                                                                                                | Batch 1 §1.4 + secret `catalyst/${env}/api/oauth/google` populated + Google OAuth consent screen verified.                                  |
| `kyc_verification_enabled`      | KYC step in creator onboarding wizard + `/integrations/kyc/*`        | off     | KYC wizard step short-circuits on initiate (`POST /wizard/kyc` returns 409 `creator.wizard.feature_disabled`) and `Skipped*Provider` is bound. On submit, `creators.kyc_status` is stamped `not_required` (Q-flag-off-1 = (a)) so the row tells the forensic story "operator-bypassed at submit time". Admin can manually approve creators without KYC.                                                                                                                            | Batch 2 §2.8 + secret `catalyst/${env}/api/kyc` populated + admin KYC review queue available in admin SPA.                                  |
| `creator_payout_method_enabled` | Stripe Express onboarding for creators + payout method UI            | off     | Payout wizard step short-circuits on initiate (409 `creator.wizard.feature_disabled`) and `SkippedPaymentProvider` is bound. Submit-validation treats `creators.payout_method_set = false` as satisfied while the flag is OFF. Creator profile shows "payout setup pending."                                                                                                                                                                                                       | Batch 1 §1.1 + Batch 3 §3.1 (Stripe Connect production approval) + secret `catalyst/${env}/api/stripe` populated with `connect_client_id`.  |
| `contract_signing_enabled`      | E-sign envelope creation + `/integrations/esign/*`                   | off     | E-sign wizard step short-circuits on initiate (409) and `SkippedEsignProvider` is bound. Click-through fallback at `POST /api/v1/creators/me/wizard/contract/click-through-accept` stamps `creators.click_through_accepted_at` (Q-flag-off-2 = (a)); submit-validation treats either `signed_master_contract_id` OR `click_through_accepted_at` non-null as satisfying the contract step. Brand-side click-through acceptance lands in Sprint 9 alongside the real e-sign adapter. | Batch 2 §2.9 + Batch 3 §3.3 (production e-sign keys) + secret `catalyst/${env}/api/esign` populated + production webhook configured.        |
| `payment_processing_enabled`    | Stripe Checkout + Connect transfers + `/integrations/stripe/webhook` | off     | _To be filled when Sprint 10 ships._ Expected: brands mark campaigns "paid offline" and enter a manual reference number; transfers to creators are tracked manually.                                                                                                                                                                                                                                                                                                               | Batch 1 §1.1 + Batch 3 §3.1 + Batch 3 §3.2 (Stripe webhook endpoints) + secret `catalyst/${env}/api/stripe` complete with `webhook_secret`. |

## Conventions

- **Naming.** Flags use snake*case, prefixed by the domain (`social_oauth*\_`, `kyc\_\_`, `payment\_\*`). The suffix is always `\_enabled`so the active state reads naturally:`if (Feature::active('payment_processing_enabled')) { ... }`.
- **Default OFF.** Every flag defaults to OFF for every scope. We never ship a vendor-dependent feature ON by default.
- **No silent vendor calls.** When a flag is OFF, the application **must not** make outbound calls to the vendor. The mock provider is what's wired up; the real client is conditionally bound only when the flag is ON.
- **Tests cover both paths.** Every gated feature ships with two test paths: flag-ON happy path against the mock provider, and flag-OFF graceful-degradation path verifying the fallback UX.
- **Pennant scopes.** When the user model exists (Sprint 1+), flags can be scoped per-user, per-tenant (agency), or globally. Default scope for these Phase 1 flags is **global** — operators flip them on for the whole instance once the vendor is ready.
- **Phase 1 flag invocation pattern.** Use `Feature::active('<flag>')` (no scope arg) — operators flip flags globally; per-user / per-tenant scoping is a Phase 2+ capability. To make this pattern resolve correctly under Pennant's default-scope-is-auth-user behaviour, `CreatorsServiceProvider::configurePennantScope()` overrides the default scope resolver to `null` for the whole app. Future modules adding Pennant flags must either follow the no-scope convention or explicitly pass a scope via `Feature::for($scope)->active('<flag>')`. (Sprint 3 Chunk 2 sub-step 8.)
- **Driver convention.** Each vendor-gated provider has a per-provider `*_PROVIDER` env var (e.g., `KYC_PROVIDER=mock|<real-vendor>`) read in `config/integrations.php`. Mixed-vendor staging environments (KYC live + e-sign mock + payment mock) are tractable; closes Chunk 1's tech-debt entry 3 — Q-driver-convention in the chunk-2 plan. (Sprint 3 Chunk 2 sub-step 4.)

## Phase-2-and-beyond flags

Out-of-scope for Phase 1, listed here so we know not to invent these names later:

- `marketing_landing_enabled` (Phase 2 — public marketing site)
- `analytics_advanced_enabled` (Phase 2 — full analytics dashboards)
- `multi_tenant_isolation_enabled` (Phase 3 — full white-label isolation)
- `mobile_app_enabled` (Phase 3 — native apps)

These are placeholders only; no code references them yet.
