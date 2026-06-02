# Services — external integrations readiness & outstanding work

> **Purpose.** A single reference for every external service Engine C (Phase 1) depends on:
> what each is for, what state the _code_ is in, what setup **you** still have to do (accounts,
> app reviews, vendor selection, secrets), the lead time, and where in the Sprint 4 plan each
> real adapter lands. This complements `06-INTEGRATIONS.md` (the architecture) and fills the
> role the spec assigns to `docs/integrations/vendor-decisions.md` (§12 tracker, at the bottom).
>
> Intended home in the repo: `docs/services.md`.

---

## The principle (so nothing here reads as "behind")

The architecture is **build-against-the-interface, plug-in-the-vendor-later**. Through Sprint 3
_every_ integration runs on a `Mock{Vendor}Provider` behind a feature flag — and that is the
spec's intended state, not a gap. Contracts + mock adapters exist for KYC / e-sign / payments;
social is feature-flagged OAuth scaffolding; nothing real is wired yet. Choosing vendors and
writing real adapters is development-time work.

So the outstanding work splits into two very different kinds:

1. **Code** — writing the real adapters. This is _inside_ Sprint 4 (workstream #2) and runs to a
   plan; you don't pre-do it.
2. **External paperwork with a clock** — vendor accounts, app reviews, OAuth verification. Some
   of these have multi-week approval times the spec deliberately scheduled for **Sprint 0** so
   they'd be approved by the time the adapter chunk runs. _These are the only things that can
   actually fall behind_, and they're the answer to "what should I already have connected?"

---

## ⏱ Act on these now — long lead times, run in parallel with the build

These don't block Sprint 4 (it builds on mocks), but the approval clocks should already be
running. The spec's risk table puts all of them in Sprint 0.

| Item                                    | Why it's slow                                                                                                                                      | Needed for                                   | Start                                     |
| --------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------- | ----------------------------------------- |
| **Stripe Connect** platform application | Connect/marketplace review + identity; days→weeks                                                                                                  | Payout adapter (S4 wk2), payments (spec S10) | **Now** — you flagged this is not started |
| **Meta (Instagram) app review**         | Business verification + advanced-scope review (`instagram_basic`, `instagram_manage_insights`, `pages_*`); the slowest, frequently multiple rounds | Real Meta social adapter (spec **Sprint 5**) | **Now** — not previously flagged          |
| **TikTok app review**                   | Login Kit + API-for-Business review (`user.info.basic/stats`, `video.list`)                                                                        | Real TikTok adapter (Sprint 5)               | **Now** — not previously flagged          |
| **Google / YouTube OAuth**              | Consent-screen verification for sensitive scopes (`youtube.readonly`, `yt-analytics.readonly`)                                                     | Real YouTube adapter (Sprint 5)              | Soon (simpler than Meta/TikTok)           |

> Social _adapters_ are Sprint 5, but the _approvals_ are the Sprint-0 lead-time items — apply
> now so review time overlaps the Sprint 4/5 build instead of stalling Sprint 5.

---

## 🧱 Foundation — confirm these Sprint-0 items are actually in place

Real-vendor work to **staging** depends on these. If any weren't done in Sprint 0, they gate the
moment you switch a flag from `mock` to a real driver.

| Item                                                    | Spec source         | State   | Blocks                                                                                                                   |
| ------------------------------------------------------- | ------------------- | ------- | ------------------------------------------------------------------------------------------------------------------------ |
| **Sentry projects** (×3: api / main / admin, EU region) | Sprint 0 acceptance | Confirm | Error monitoring on staging/prod; not core-flow blocking                                                                 |
| **AWS Secrets Manager** structure                       | Sprint 0 acceptance | Confirm | _Every_ real vendor credential lives here (`06-INTEGRATIONS` §1.2). Hard prerequisite for any real adapter beyond local. |
| **AWS environments** (eu-central-1 staging + prod)      | Sprint 0 acceptance | Confirm | Anywhere a real vendor is exercised outside your laptop                                                                  |

---

## 🎯 Sprint 4 adapter targets — pick the vendor & get sandbox access _before_ the chunk

These are the three real adapters in **Sprint 4 workstream #2**. Each needs a vendor _decision_
and sandbox/test credentials before its chunk can do more than re-confirm the mock. Sandbox
signup is usually fast (instant→days) — the decision is the gating step.

### Payments — Stripe Connect (Express)

- **Capability:** escrow funding/release, creator payout KYC, multi-currency, payout speeds, refunds/disputes.
- **Code state:** the built interface is `App\Modules\Creators\Integrations\Contracts\PaymentProvider` (the 2-method onboarding contract under the **Creators** module — _not_ a `PaymentProviderContract`; that name was a doc mislabel, corrected Sprint 4 Ch2) + `MockPaymentProvider` ✅. Sprint 4 Ch2 added the inbound-webhook pair (`verifyWebhookSignature` + `parseWebhookEvent`) and shipped the **real `StripePaymentProvider`** ✅ (test-mode: real Connect Express account + onboarding link + `account.updated` → `creator_payout_methods.status`), at `app/Modules/Creators/Integrations/Stripe/` (deliberately _not_ the spec's `Modules/Payments/` path — see the deferral note below). **Deferred to Sprint 10:** escrow methods (`fundEscrow`/`releaseEscrow`/`refundEscrow`), the 8 money-movement webhooks (`charge.*`/`transfer.*`/`payout.*`), the `payments`/`payment_events` model, and migrating the adapter + contract into a broad `Modules\Payments\Contracts\PaymentProviderContract`.
- **Vendor:** **decided — Stripe** (no real EU marketplace alternative).
- **Your action:** finish the Connect application (above); add **test-mode** keys (`STRIPE_SECRET_KEY`) + the webhook signing secret (`STRIPE_WEBHOOK_SECRET`) + `STRIPE_CONNECT_CLIENT_ID` to Secrets Manager (`catalyst/${env}/api/stripe`); configure the `/api/v1/webhooks/stripe` endpoint URL in the Stripe dashboard. The adapter is reached only when `creator_payout_method_enabled` is ON **and** `PAYMENT_PROVIDER=stripe` (test/staging) — it stays bound-but-unreachable in prod (flag OFF).
- **Lands in:** ✅ S4 wk2 — onboarding adapter + `account.updated` shipped. Escrow + money-movement surfaces later (spec S10).

### Identity verification — KYC

- **Capability:** gov-ID + liveness, webhook on completion, EU+UK, conversion-friendly UX.
- **Code state:** `IdentityVerificationProviderContract` + mock ✅ (4-method surface). Real adapter ❌.
- **Vendor:** **open — lean Veriff** (EU-native, GDPR-friendly). Persona / Onfido also viable; contract abstracts it.
- **Your action:** **select the vendor**, open a sandbox account, add API key + webhook secret to Secrets Manager.
- **Lands in:** S4 wk2 (adapter) → feeds the **KYC review queue** in the approval workflow (S4 wk3). _This is the dependency edge — KYC adapter must precede the review-queue half of the approval workflow._

### E-signature

- **Capability:** templated master contract, mobile signing, completion webhook, audit-grade signed PDF, multi-lang (en/pt/it).
- **Code state:** `ESignatureProviderContract` + mock ✅ (4-method surface; contract step graceful-degrades to click-through accept when flag OFF). Real adapter ❌. **Acceptance-record foundation now exists (S4 Ch4):** the spec'd `contracts` table (`03-DATA-MODEL.md §8`) is built and the click-through accept routes through it — every acceptance is a versioned + timestamped + attributed `contracts` row (`status=signed`, `signature_provider=internal`), unified with the future vendor path. **The vendor adapter EXTENDS this table — it does not rebuild it:** it fills the envelope columns (`signature_envelope_id`, `sent_at`, `expires_at`, `signature_provider=docusign|dropboxsign`) that ship now and stay null for click-through. ⚠️ Before the adapter lands it must convert the two `signed_master_contract_id` sentinel writers to real `contracts` rows so the deferred DB-level FK can be added — see `tech-debt.md` "Deferred `contracts` FK".
- **Vendor:** **open — lean Dropbox Sign** (solid API, fair pricing, multi-lang; DocuSign is overkill for P1 volumes).
- **Your action:** **select the vendor**, open a sandbox/dev account, add API key + webhook secret to Secrets Manager, prepare the master-contract template.
- **Lands in:** S4 wk2 (adapter) — spec-native home is S9 (drafts/contracts), pulled forward by the full-Sprint-4 scope.

---

## 🔌 Built-in / wiring-only — no vendor account, but confirm it's exercised

### VIES + HMRC VAT validation (tax profiles)

- **Capability:** validate EU VAT numbers (VIES), UK VAT (HMRC), structural NIF/CPF checks. Platform-level tax data, _separate_ from Stripe's KYC.
- **Code state:** `TaxProfileValidator` is built-in (Sprint 3 step 6). **Confirm** whether it makes _live_ VIES/HMRC calls or only does format validation today — likely format-only, with the live call still to wire.
- **Your action:** none external (VIES is a free public EU service; HMRC is free). Just a code-side wiring/confirm task.
- **Lands in:** not in the locked Sprint 4 scope — log as a small follow-up if live calls aren't wired.

### Cookie consent (CMP)

- **Capability:** opt-in banner, granular categories, server-side versioned consent, script-blocking, multi-lang.
- **Decision:** **self-built minimal** for Phase 1 (~3 days; avoids per-domain SaaS). Iubenda is the paid fallback.
- **Your action:** none yet — pre-public-launch work, not Sprint 4.

---

## 💤 Deferrable — later sprints, listed for completeness

| Service                                        | Decision                                                    | When                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| ---------------------------------------------- | ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Email provider** (transactional)             | open — SES for bulk, Postmark for auth/security-critical    | Dev uses Mailhog/Mailpit; brand mail theme (`catalyst.css`) shipped in 3.5. The approval + rejection **mailables now exist** (`CreatorApprovedMail` / `CreatorRejectedMail`, queued + localized en/pt/it, Sprint 4 Chunk 3 D-c3-11) and dispatch on the approve/reject actions — they ride the **`log` mailer** (`config/mail.php` default) and are verified via `Mail::fake()`, not a real inbox. **Real provider needed before these (and Sprint 3's bulk-invite) go to staging.** Select + verify domain (DKIM/SPF/DMARC); SES needs a sandbox-exit request (~24h) + warmup. |
| **Product analytics**                          | open — lean PostHog (EU cloud, self-host escape hatch)      | GDPR-consent-gated; no core-flow dependency. Wire when convenient.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                              |
| **Social real adapters** (Meta/TikTok/YouTube) | platforms decided; _approvals_ are the lead-time item above | Spec **Sprint 5**. Currently feature-flagged stubs (`social_oauth_*_enabled` = off).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |

> ⚠️ **Email is the sleeper in Sprint 4.** The approval workflow (wk3 / Chunk 3) now sends
> approval/rejection emails (the two mailables shipped on the `log` mailer) and Sprint 3's
> bulk-invite sends invitations. These work on the local/log mailer in dev, but a real provider +
> verified domain is needed before they go anywhere real. Worth selecting now.

---

## Sprint 4 plan mapping (where each piece is addressed)

Locked Sprint 4 = full scope, dependency-ordered. Service touchpoints per chunk:

1. **Dashboard + harness gap** (main SPA) — no new vendors. KPIs read existing Sprint 3 data. Email only indirectly (none sent here).
2. **Vendor adapters** (backend) — **Stripe + KYC + e-sign** real adapters land here. KYC ordered first (feeds chunk 3). Requires the vendor selections + sandbox keys above.
3. **Creator approval workflow** (admin SPA) — consumes the **KYC adapter** (KYC review queue) from chunk 2; sends **approval/rejection email** (needs a real email provider for staging).
4. **Prospect creators list** (minimal) — no new vendors.

---

## Vendor decision tracker

(Mirrors `06-INTEGRATIONS.md` §12 — keep this updated as decisions land; this table is the
living `vendor-decisions.md` the spec asks for.)

| Capability        | Status       | Vendor                             | Sandbox/keys           | Lead-time action                           |
| ----------------- | ------------ | ---------------------------------- | ---------------------- | ------------------------------------------ |
| Payments          | Decided      | Stripe Connect (Express)           | ❌ test keys pending   | Connect application — **start now**        |
| KYC               | **Open**     | TBD (lean Veriff)                  | ❌                     | Select, then sandbox                       |
| E-sign            | **Open**     | TBD (lean Dropbox Sign)            | ❌                     | Select, then sandbox                       |
| Email             | **Open**     | TBD (lean SES + Postmark)          | ❌                     | Select + verify domain (Sprint 4 relevant) |
| Social — Meta     | Platform set | Meta Graph / IG Graph              | ❌                     | **App review — start now**                 |
| Social — TikTok   | Platform set | TikTok Login Kit                   | ❌                     | **App review — start now**                 |
| Social — YouTube  | Platform set | Google OAuth                       | ❌                     | Consent-screen verification                |
| Error monitoring  | Decided      | Sentry (EU)                        | Confirm projects exist | Sprint-0 item — confirm                    |
| Product analytics | Open         | TBD (lean PostHog EU)              | ❌                     | Defer                                      |
| Cookie consent    | Decided      | Self-built (Iubenda fallback)      | n/a                    | Pre-launch                                 |
| Tax validation    | Built-in     | VIES + HMRC                        | n/a (free)             | Confirm live calls wired                   |
| Secrets / cloud   | Foundation   | AWS Secrets Manager + eu-central-1 | Confirm                | Sprint-0 item — confirm                    |

---

_Update this file whenever a vendor is selected, an account/approval lands, or an adapter ships.
Keep the principle in mind: mocks are the correct default; the only things that "fall behind"
are the external approvals with a clock._
