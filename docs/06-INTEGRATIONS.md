# 06 — Integrations

> **Status: Always active reference. Defines how Catalyst Engine integrates with external services. The pattern is: build against an interface, not against a vendor. When a vendor is chosen, plug in the concrete adapter.**

This document covers payments, identity verification (KYC), e-signature, social media APIs, email, error monitoring, product analytics, and the cookie consent platform. For each, we define:

- The capability needed
- The abstraction interface
- The integration shape (webhooks, OAuth, API patterns)
- The criteria for vendor selection
- Notes on candidate vendors

The vendor decisions are deliberately deferred to development time. Cursor builds against the interfaces; the developer chooses the vendor and writes the adapter.

---

## 1. The integration architecture

### 1.1 Adapter pattern

Every integration follows the same pattern:

```
app/Modules/{Module}/
├── Contracts/
│   └── PaymentProviderContract.php         # The interface
├── Services/
│   └── PaymentService.php                  # Domain service uses the contract
└── Integrations/
    ├── Stripe/
    │   ├── StripePaymentProvider.php       # Concrete implementation
    │   ├── StripeWebhookHandler.php
    │   └── StripeServiceProvider.php
    └── Mock/
        └── MockPaymentProvider.php          # For tests
```

- The **contract** (interface) is the public surface of the integration.
- The **domain service** depends on the contract, not the concrete vendor.
- The **concrete adapter** lives in an `Integrations/{Vendor}/` folder.
- The **mock adapter** is used in tests; real provider is never hit during automated testing.
- Switching vendors means writing a new adapter; the rest of the app doesn't change.

### 1.2 Where credentials live

All vendor credentials (API keys, webhook secrets, OAuth client secrets) are in **AWS Secrets Manager**. The Laravel config layer reads them at boot. They never appear in `.env.example`, never in code, never in commits.

### 1.3 Inbound webhook pattern

Every vendor that sends webhooks follows the same pattern in our system:

1. Endpoint at `/api/v1/webhooks/{vendor}` receives the request.
2. Signature is verified using the vendor's signing secret. Failure returns 401.
3. The raw event is stored in `integration_events` (idempotency check by `provider_event_id`).
4. A queued job (`Process{Vendor}WebhookJob`) handles the event asynchronously.
5. The endpoint returns 200 immediately (don't make the vendor wait).

If the same `provider_event_id` arrives twice, the second insert fails the unique constraint and we know it's a duplicate.

### 1.4 Outbound HTTP pattern

All outbound HTTP via `Illuminate\Http\Client` (Laravel's HTTP client) wrapped in a `HttpClient` service per vendor. The service:

- Adds vendor-specific headers and authentication
- Sets sensible timeouts (10s default, configurable per call)
- Implements retry with exponential backoff (3 retries by default)
- Logs every request and response (with secrets redacted)
- Tracks metrics: latency, error rate per vendor

---

## 2. Payments — Stripe Connect

### 2.1 Capabilities needed

- Hold funds in escrow when an agency funds a campaign
- Release funds to creators when content is approved
- KYC creators sufficient for receiving payouts (a separate KYC layer from creator identity verification)
- Multi-currency support (EUR, GBP at minimum)
- Multiple payout speeds (standard / fast / instant)
- Refunds and dispute handling
- Tax compliance support (VAT handling for EU creators)
- 1099-equivalent reporting (or local equivalent)

### 2.2 The contract

```php
namespace App\Modules\Payments\Contracts;

interface PaymentProviderContract
{
    public function createConnectedAccount(
        Creator $creator,
        ConnectedAccountRequest $request
    ): ConnectedAccountResult;

    public function getOnboardingLink(Creator $creator): string;

    public function fundEscrow(
        Payment $payment,
        FundingRequest $request
    ): EscrowResult;

    public function releaseEscrow(
        Payment $payment,
        ReleaseRequest $request
    ): ReleaseResult;

    public function refundEscrow(
        Payment $payment,
        RefundRequest $request
    ): RefundResult;

    public function getAccountStatus(Creator $creator): AccountStatus;

    public function verifyWebhookSignature(string $payload, string $signature): bool;

    public function parseWebhookEvent(string $payload): WebhookEvent;
}
```

Concrete implementations in `app/Modules/Payments/Integrations/Stripe/StripePaymentProvider.php`.

### 2.3 Webhook events handled

```
account.updated                     → update creator KYC status, payout readiness
charge.succeeded                    → escrow funded
charge.refunded                     → escrow refunded
charge.dispute.created              → mark dispute, alert admin
charge.dispute.closed               → resolve dispute
transfer.created                    → escrow released to creator
transfer.failed                     → alert, retry
payout.paid                         → creator received funds
payout.failed                       → alert, retry
```

### 2.4 Vendor selection criteria

- **EU presence:** Stripe operates in EU. Strong fit.
- **Connected accounts model:** Stripe Connect is the only major option that fits a marketplace model with creator KYC + escrow built in.
- **Multi-currency:** Stripe handles EUR, GBP, USD natively.
- **Payout speeds:** Stripe supports standard (T+2) and instant payouts (T+0 with fee).
- **Fees:** ~0.4–1.5% on Connect transactions plus standard processing.
- **Compliance:** Stripe handles SCA, PSD2, regulatory paperwork.

### 2.5 Recommendation

**Stripe Connect (Express accounts).** It's the de facto standard for marketplace payments and has no real EU competitor at scale. Build against the contract above; the Stripe adapter is the first concrete implementation.

Alternatives considered but lower priority:

- Adyen MarketPay (more enterprise, higher minimums, complex integration)
- Mangopay (EU-native, smaller, less developer-friendly)

---

## 3. Identity verification (KYC)

### 3.1 Capabilities needed

- Verify creator's identity (government ID + selfie/liveness)
- Sufficient for our compliance and for downstream payment KYC
- Webhook on completion
- Multi-country support (EU + UK)
- Reasonable UX (creator-facing flow that doesn't kill conversion)

### 3.2 The contract

```php
namespace App\Modules\Creators\Contracts;

interface IdentityVerificationProviderContract
{
    public function startVerification(
        Creator $creator,
        VerificationRequest $request
    ): VerificationSession;

    public function getVerificationResult(string $sessionId): VerificationResult;

    public function verifyWebhookSignature(string $payload, string $signature): bool;

    public function parseWebhookEvent(string $payload): VerificationWebhookEvent;
}
```

### 3.3 Vendor selection criteria

| Vendor      | EU presence               | Pricing           | Pass rate | Developer experience | Notes                                                     |
| ----------- | ------------------------- | ----------------- | --------- | -------------------- | --------------------------------------------------------- |
| **Persona** | Strong                    | $1–3/verification | High      | Excellent            | US-based but EU compliant; widely used                    |
| **Veriff**  | Estonian-based, strong EU | €1–3/verification | High      | Good                 | EU-native, often preferred for GDPR-sensitive deployments |
| **Onfido**  | UK-based, strong EU       | £1–3/verification | High      | Good                 | Used by many UK marketplaces                              |
| **Sumsub**  | UK-based                  | $1–2              | Medium    | Decent               | Cheaper; broader geographic coverage                      |

### 3.4 Recommendation

**Lean toward Veriff for EU-first deployment.** EU-native vendor, GDPR-friendly, good UX. Persona and Onfido are also strong candidates and the contract above abstracts the choice.

When vendor is selected, write the adapter and update `06-INTEGRATIONS.md` with chosen vendor.

---

## 4. E-signature

### 4.1 Capabilities needed

- Send a contract for signature
- Templated documents with placeholders
- Mobile-friendly signing experience
- Webhook on completion
- Audit-grade signed PDF download
- Multi-language UI (en, pt, it)

### 4.2 The contract

```php
namespace App\Modules\Contracts\Contracts;

interface ESignatureProviderContract
{
    public function sendForSignature(
        Contract $contract,
        SignatureRequest $request
    ): SignatureEnvelope;

    public function getEnvelopeStatus(string $envelopeId): EnvelopeStatus;

    public function downloadSignedDocument(string $envelopeId): SignedDocument;

    public function voidEnvelope(string $envelopeId, string $reason): void;

    public function verifyWebhookSignature(string $payload, string $signature): bool;

    public function parseWebhookEvent(string $payload): SignatureWebhookEvent;
}
```

### 4.3 Vendor selection criteria

| Vendor                                | Pricing             | API quality | Multi-lang | Notes                                 |
| ------------------------------------- | ------------------- | ----------- | ---------- | ------------------------------------- |
| **DocuSign**                          | Higher per-envelope | Excellent   | Yes        | Industry standard, expensive at scale |
| **Dropbox Sign** (formerly HelloSign) | Mid                 | Excellent   | Yes        | Solid, fair pricing                   |
| **SignWell**                          | Lower               | Good        | Yes        | Cheaper, smaller, less battle-tested  |
| **PandaDoc**                          | Mid                 | Good        | Yes        | More CRM-flavored, less needed here   |

### 4.4 Recommendation

**Dropbox Sign as default candidate.** Solid API, fair pricing, multi-language support. DocuSign is overkill for Phase 1 volumes. Re-evaluate at Phase 3 when volume justifies enterprise pricing negotiations.

---

## 5. Social media APIs

### 5.1 Capabilities needed

- OAuth flow for creators to connect their accounts
- Profile data: username, follower count, account type (personal/business/creator)
- Media data: recent posts, post URLs, post metrics
- Webhooks for new post events (where supported)
- Verify a specific post URL belongs to the connected creator

### 5.2 The contract

```php
namespace App\Modules\Creators\Contracts;

interface SocialPlatformProviderContract
{
    public function getAuthorizationUrl(Creator $creator, string $redirectUri): string;

    public function exchangeAuthorizationCode(string $code, string $redirectUri): OAuthTokens;

    public function refreshTokens(string $refreshToken): OAuthTokens;

    public function getAccountProfile(string $accessToken): AccountProfile;

    public function getAccountMetrics(string $accessToken): AccountMetrics;

    public function getMediaItems(string $accessToken, MediaQuery $query): MediaList;

    public function getPostMetrics(string $accessToken, string $postId): PostMetrics;

    public function verifyPostUrl(string $accessToken, string $postUrl): PostVerification;

    public function revokeAccess(string $accessToken): void;
}
```

One concrete implementation per platform: `MetaSocialProvider`, `TikTokSocialProvider`, `YouTubeSocialProvider`.

### 5.3 Per-platform notes

#### Meta (Instagram + Facebook)

- **API:** Meta Graph API + Instagram Graph API.
- **Auth:** OAuth 2.0 via Facebook Login.
- **Account types:** Only Creator and Business accounts (Personal accounts not supported by API).
- **Required scopes:** `instagram_basic`, `instagram_manage_insights`, `pages_show_list`, `pages_read_engagement`.
- **Webhooks:** available for new posts via Subscribed Fields.
- **Rate limits:** strict; cache aggressively.
- **App review:** required for production use of certain scopes — plan time for this in Phase 1.

#### TikTok

- **API:** TikTok Login Kit + TikTok API for Business.
- **Auth:** OAuth 2.0.
- **Required scopes:** `user.info.basic`, `video.list`, `user.info.stats`.
- **Account types:** Creator and Business accounts.
- **Webhooks:** limited; mostly polling.
- **Rate limits:** documented per endpoint.

#### YouTube

- **API:** YouTube Data API v3 + YouTube Analytics API.
- **Auth:** Google OAuth 2.0.
- **Required scopes:** `youtube.readonly`, `yt-analytics.readonly`.
- **Webhooks:** PubSubHubbub for new uploads.
- **Rate limits:** quota-based (10,000 units/day default).

### 5.4 Token storage

- Encrypted at rest (Laravel `encrypted` cast — see `05-SECURITY-COMPLIANCE.md` § 4.3).
- Refresh tokens used to renew access; renewal happens automatically before expiry.
- On creator disconnection, tokens are revoked at the provider AND deleted locally.

### 5.5 Sync schedule

- On creator OAuth completion: full profile + recent media synced.
- Daily background job: refresh metrics for all connected accounts.
- On campaign post submission: real-time fetch of the specific post.
- Rate-limit aware scheduling.

---

## 6. Email (transactional)

### 6.1 Capabilities needed

- Reliable transactional email delivery
- Multi-language templates (en, pt, it)
- Bounce and complaint handling
- DKIM, SPF, DMARC configured
- EU data residency

### 6.2 The contract

Laravel's mail abstraction is sufficient. Concrete provider plugged in via Laravel's mail driver config. No custom contract layer needed.

### 6.3 Vendor selection criteria

| Vendor       | EU presence         | Pricing           | Reputation       | Notes                                 |
| ------------ | ------------------- | ----------------- | ---------------- | ------------------------------------- |
| **AWS SES**  | EU regions          | $0.10/1000 emails | Good once warmed | Same cloud as us; cheap; needs warmup |
| **Postmark** | EU region available | ~$10/10k          | Excellent        | Premium transactional reputation      |
| **Resend**   | Global              | $0.40/1000        | Newer, good      | Modern API, increasingly popular      |
| **Mailgun**  | EU region           | ~$1/1000          | Good             | Mature, slightly older feel           |

### 6.4 Recommendation

**AWS SES for cost; Postmark if deliverability matters more than cost.** SES is the default for an AWS-native deployment. Postmark gives premium deliverability at higher cost — worth it for password resets and 2FA codes specifically.

A reasonable approach: AWS SES for bulk transactional (notifications, digests), Postmark for security-critical (auth flows). Or just SES if budget is tight.

---

## 7. Error monitoring — Sentry

### 7.1 Capabilities needed

- Backend exception tracking (Laravel)
- Frontend error tracking (both Vue SPAs)
- Source maps for stack traces
- Performance monitoring (transaction tracing)
- User context attached to errors (with PII redacted)
- Release tracking
- EU data residency

### 7.2 Configuration

- **Project per environment per surface:** `catalyst-engine-api-prod`, `catalyst-engine-main-prod`, `catalyst-engine-admin-prod`. Same for staging.
- **EU data region** explicitly chosen at Sentry org setup.
- **PII redaction filters** configured: passwords, tokens, secrets, full credit cards, government IDs, KYC documents.
- **User context** attached to events: user ULID + role (no email or name).
- **Release tracking** via CI: every deploy registers a Sentry release with the commit SHA.
- **Source maps uploaded** during build; not served publicly.

Sentry is the standard. Only swap if pricing becomes prohibitive (Phase 4+ scale).

---

## 8. Product analytics

### 8.1 Capabilities needed

- Track user events (signup, KYC, campaign creation, payment, etc.)
- Funnel analysis
- Retention cohort analysis
- User session replay (optional but useful)
- EU data residency
- GDPR consent integration (only track if user consents)

### 8.2 The contract

```php
namespace App\Core\Analytics\Contracts;

interface ProductAnalyticsProviderContract
{
    public function identify(User $user, array $traits = []): void;

    public function track(string $event, array $properties = [], ?User $user = null): void;

    public function group(string $groupId, array $traits = []): void;

    public function alias(string $previousId, string $newId): void;

    public function flush(): void;
}
```

The contract abstracts over the major analytics SDKs which all have similar primitives.

### 8.3 Vendor selection criteria

| Vendor        | EU residency             | Pricing                  | Self-host option | Notes                                       |
| ------------- | ------------------------ | ------------------------ | ---------------- | ------------------------------------------- |
| **Amplitude** | EU instance available    | Generous free, mid-paid  | No               | Industry standard, comprehensive            |
| **Mixpanel**  | EU residency available   | Free tier, paid mid+     | No               | Strong on funnel analysis                   |
| **PostHog**   | EU cloud + self-hostable | Generous free, fair paid | Yes              | Open source; only one with self-host option |
| **Heap**      | Limited EU               | Higher                   | No               | Auto-tracking heavy                         |

### 8.4 Recommendation

**PostHog (EU cloud) is the strongest fit** for a GDPR-first product. It offers EU data residency, excellent product analytics, optional self-hosting if you ever need it, and pricing is competitive. The self-host option is a meaningful escape hatch.

Amplitude with EU residency is a strong alternative if you want a more enterprise feel.

---

## 9. Cookie consent management

### 9.1 Capabilities needed

- Cookie banner with explicit opt-in for non-essential
- Granular categories (essential / functional / analytics / marketing)
- Consent record server-side with versioning
- Withdrawal mechanism
- Multi-language (en, pt, it)
- Block scripts before consent

### 9.2 Vendor selection criteria

| Vendor                 | Pricing            | Quality | Notes                      |
| ---------------------- | ------------------ | ------- | -------------------------- |
| **OneTrust**           | Enterprise pricing | Premium | Overkill for early stage   |
| **Cookiebot**          | Per-domain pricing | Good    | Strong EU presence         |
| **Iubenda**            | Mid pricing        | Good    | Italy-based, strong EU     |
| **Self-built minimal** | Free               | DIY     | Full control; ~3 days work |

### 9.3 Recommendation

**Self-built minimal CMP for Phase 1.** A basic consent banner with category toggles, server-side consent storage, and script-blocking integration is genuinely a few days' work and avoids per-domain SaaS pricing. Phase 2 can swap to a paid CMP if compliance review demands more.

Alternatively, Iubenda has nice templates and is reasonably priced.

---

## 10. Tax forms (EU equivalents to W-9/W-8BEN)

### 10.1 The need

Catalyst Engine collects tax information from creators to:

- Comply with VAT reverse-charge rules
- Issue correct invoices
- Support agency-side accounting

This is **not** the same as Stripe's tax KYC; this is platform-level tax data collection.

### 10.2 What's collected

- Legal name
- Tax form type (`eu_self_employed`, `eu_company`, `uk_self_employed`, `uk_company`, etc.)
- Tax ID (VAT number, NIF, partita IVA, NIPC, UK UTR / VAT)
- Tax ID country
- Address

### 10.3 Validation

- VAT numbers validated against VIES (EU's VAT validation service) on submission.
- UK VAT numbers validated against HMRC's VAT registration check.
- NIF/CPF formats validated structurally.

### 10.4 Implementation

There's no good "vendor" for this — it's our own form with VIES integration. Validation happens in `app/Modules/Creators/Services/TaxProfileValidator.php`.

---

## 11. Search & embeddings (Phase 3)

Phase 3 introduces semantic search and AI-driven creator matching. The pattern will be the same:

- A `SearchProviderContract` interface
- Implementations for Postgres FTS (Phase 1 baseline), Meilisearch / OpenSearch / Algolia (Phase 2/3)
- An `EmbeddingProviderContract` for AI-driven similarity (Phase 3)

Phase 1 doesn't need to define these contracts in detail — but `app/Core/Search/` exists with the `PostgresSearchProvider` so the pattern is in place.

---

## 12. Vendor decision tracker

A living document in `docs/integrations/vendor-decisions.md` (created during Phase 1) tracks:

| Capability        | Status   | Vendor                      | Decided    | Notes |
| ----------------- | -------- | --------------------------- | ---------- | ----- |
| Payments          | Selected | Stripe Connect              | YYYY-MM-DD |       |
| KYC               | Open     | TBD (likely Veriff)         |            |       |
| E-sign            | Open     | TBD (likely Dropbox Sign)   |            |       |
| Email             | Open     | TBD (likely SES + Postmark) |            |       |
| Error monitoring  | Selected | Sentry (EU)                 | YYYY-MM-DD |       |
| Product analytics | Open     | TBD (likely PostHog EU)     |            |       |
| Cookie consent    | Open     | Self-built or Iubenda       |            |       |
| Tax validation    | Built-in | VIES + HMRC                 | YYYY-MM-DD |       |

When a vendor is chosen, this table is updated, the adapter is implemented, and tests are added.

---

## 13. Test patterns for integrations

### 13.1 Local development

- All integrations have a `Mock{Vendor}Provider` adapter used in development and tests.
- `.env` `INTEGRATIONS_DRIVER=mock` for local; `=production` for staging and prod.
- Mock providers return deterministic test data for every call.
- Webhooks can be triggered locally via `php artisan integrations:fire-webhook {vendor} {event}` console command.

### 13.2 Test environments

- **Stripe:** test mode keys for staging.
- **KYC vendor:** sandbox environment for staging.
- **E-sign vendor:** sandbox environment for staging.
- **Social APIs:** sandbox/test apps where available; otherwise dedicated staging accounts.
- **Email:** Mailtrap or SES sandbox for staging.

### 13.3 Contract tests

Each integration has a contract test asserting the adapter conforms to the contract:

```php
test('StripePaymentProvider implements PaymentProviderContract', function () {
    $provider = app(StripePaymentProvider::class);
    expect($provider)->toBeInstanceOf(PaymentProviderContract::class);
});
```

Mock implementations also have full test coverage to ensure they're behaviorally equivalent to the real ones.

---

## 14. Operational concerns

### 14.1 Monitoring per vendor

- Latency per vendor call tracked in CloudWatch metrics.
- Error rate per vendor tracked.
- Alerts fire on:
  - Sustained error rate above 5% for 5 minutes
  - p95 latency above 5x baseline
  - Webhook processing lag above 5 minutes

### 14.2 Vendor outage playbook

For each vendor, a documented playbook in `docs/runbooks/vendor-outage-{vendor}.md`:

- Symptom recognition
- Severity classification (does it block users?)
- User-facing messaging
- Workaround if any
- Recovery verification

Phase 1 has placeholder runbooks; Phase 2 fully fleshes them out after experiencing real incidents.

### 14.3 Vendor switching

Switching vendors involves:

1. Implement the new adapter against the contract.
2. Add config for the new driver.
3. Migrate data if needed (e.g., creator KYC histories).
4. Run both adapters in parallel briefly (dual-write) for verification.
5. Switch the driver flag.
6. Decommission the old adapter.

The whole process is enabled by the contract pattern. It's not free, but it's not a full rebuild.

---

## 15. Phase-by-phase integration plan

| Phase | New integrations                                                                                                                                                                   |
| ----- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| P1    | Stripe Connect, KYC vendor, E-sign vendor, Meta/TikTok/YouTube, Email, Sentry, Product analytics, CMP, VIES                                                                        |
| P2    | Google Calendar (creator availability sync), Push notification provider (FCM/APNs), HubSpot or Salesforce (agency CRM if needed), Mailtrap (testing)                               |
| P3    | Embedding provider (OpenAI/Anthropic for AI matching), Search engine (Meilisearch/OpenSearch), Affiliate tracking, additional social platforms (Twitter, Twitch, LinkedIn)         |
| P4    | DAM integrations (Bynder, etc.), Marketing platform integrations (Klaviyo, Braze), Direct ad platform APIs for whitelisting (Meta Ads, TikTok Ads), Reverse ETL (Hightouch/Census) |

---

**End of integrations document. Build against the contracts; choose vendors as you go.**
