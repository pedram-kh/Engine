# 00 — Master Architecture

> **Status: Always active reference. Reflects the system at full maturity (end of Phase 4). Used by Cursor to ensure Phase 1 decisions don't paint the system into a corner.**

This document describes the **target architecture** for Catalyst Engine across all four phases. Phase 1 implements only a subset of what's described here, but Phase 1 must be **shaped** correctly so future phases can be added without restructuring.

---

## 1. Product summary

Catalyst Engine is a two-sided platform for influencer marketing. Agencies use it to manage campaigns for their brand clients with a roster of creators. In later phases, brands self-serve directly, creators discover and apply to campaigns, and AI agents handle execution at scale.

The product is built in Europe (English, Portuguese, Italian) under GDPR-first defaults. The first customer is **Catalyst** (an agency partner whose roster of hundreds of creators bootstraps the platform).

---

## 2. Scale targets (end-state)

These are the targets the architecture must support by end of Phase 4. Phase 1 won't hit them, but the architecture cannot have hard ceilings below these numbers.

| Dimension                 | Target                                 |
| ------------------------- | -------------------------------------- |
| Agencies                  | 1,000+                                 |
| Brands per agency         | 50+ average, 500+ max                  |
| Active campaigns          | 50,000+ concurrent                     |
| Creators (registered)     | 500,000+                               |
| Creators (active monthly) | 100,000+                               |
| Campaigns per month       | 20,000+                                |
| Audit events per day      | 10,000,000+                            |
| Concurrent admin users    | 200+                                   |
| API requests              | 5,000 RPS sustained, 20,000 RPS burst  |
| Storage                   | Petabyte-class (creator video content) |
| Geographic scope          | EU first; US, LATAM later              |
| Uptime target             | 99.95% (Phase 4)                       |
| RTO / RPO                 | < 1 hour / < 15 minutes                |

**Phase 1 actual expected scale:** 1 agency, 5–10 brands, 500 creators, 50 active campaigns, ~10,000 audit events/day. The architecture supports the end-state; Phase 1 just doesn't fill it.

---

## 3. Core entity model (high-level)

Detailed schema is in `03-DATA-MODEL.md`. The hierarchy you must understand at architecture level:

```
Platform (Catalyst Engine — global)
└── Admin Users (Catalyst Engine ops staff)

Tenant: Agency
├── Agency Users (admin / manager / staff)
└── Brand (owned by agency)
    ├── Brand Users (Phase 2+)
    └── Campaign
        └── CampaignAssignment  (one Creator engaged on one Campaign)

Global: Creator
├── Profile (region, language, categories, samples)
├── Social Accounts (linked via OAuth)
├── Availability (calendar)
├── Identity Verification (KYC)
└── Tax Profile

Cross-cutting:
- Contract (Universal master, optionally per-campaign addendum)
- Audit Log (every privileged action)
- Payment (escrow + payout)
- Message (per CampaignAssignment)
- Board (per Campaign — columns + automations)
- Card (one per CampaignAssignment, lives on a Board)
```

### Key architectural decisions baked into this model

**Brand is a first-class entity from Phase 1.** Even though Phase 1 has no brand-side users, Brand has its own table, its own ID, and Campaigns reference Brand. This avoids the "retrofit brand later" disaster.

**Creator is a global entity, not tenant-scoped.** A creator can work with multiple agencies. Creator profile, KYC, tax, and bank details belong to the creator, not to any agency. Agencies see creators through `AgencyCreatorRelation` records that store agency-specific data (their rating of the creator, blacklist status, history).

**CampaignAssignment is its own entity, not just a join table.** It has its own state machine (invited → accepted → contracted → drafted → approved → posted → paid), its own messages, its own card on the board, its own contract, its own payment. Treating it as a first-class entity from Phase 1 saves enormous pain later.

**Contract is polymorphic.** A contract can attach to a Creator (the universal master signed at onboarding) or a CampaignAssignment (the optional per-campaign addendum). Both share the same Contract table.

**Board lives at the Campaign level.** Each campaign has its own board with its own columns and its own automation rules. Cards on the board map 1:1 to CampaignAssignments. See `10-BOARD-AUTOMATION.md`.

---

## 4. Multi-tenancy model

This is critical. Get it wrong in Phase 1 and you spend Phase 3 rebuilding it.

### The model: row-level tenancy with global users

- **Tenant = Agency.** Every tenant-scoped row carries an `agency_id`.
- **Brand, Campaign, CampaignAssignment, Board, Card, Message** are tenant-scoped via their parent.
- **Creator, AdminUser, Contract templates** are global (not tenant-scoped).
- **CreatorAgencyRelation** (creator's blacklist status, ratings, history per agency) is tenant-scoped.

### Enforcement

- Every tenant-scoped Eloquent model uses a `BelongsToAgency` global scope that automatically filters by the current request's agency context.
- Agency context is established at authentication time and stored on the request.
- API routes are mounted under `/api/v1/agencies/{agency}/...` for tenant-scoped resources, and the `agency` parameter is verified against the authenticated user's permissions.
- Cross-tenant access is **never** legitimate from non-admin paths. Admin SPA can act across tenants but must log every access.
- Database-level safety: `agency_id` columns are `NOT NULL` on tenant-scoped tables, with a foreign key constraint. Tests assert the constraint can't be violated.

### Future-proofing

- Tenancy is row-level, not schema-per-tenant or database-per-tenant. This keeps Phase 1 simple and supports thousands of agencies on shared infrastructure.
- If a future enterprise customer demands physical isolation, the schema-per-tenant pattern can be added without breaking row-level tenants. Don't pre-build it.

### Tenant data export and deletion (GDPR)

- Every tenant-scoped table participates in agency-level export and deletion.
- Creator-level GDPR export is separate (creator data is global).
- Both are designed as background jobs from Phase 1, even though Phase 1 may not have heavy traffic.

---

## 5. System architecture

### High-level diagram

```
                    ┌─────────────────────┐
                    │    CloudFront CDN   │
                    └──────────┬──────────┘
                               │
                ┌──────────────┴──────────────┐
                │                             │
        ┌───────▼────────┐          ┌────────▼─────────┐
        │  Main SPA      │          │  Admin SPA       │
        │  (Vue 3 +      │          │  (Vue 3 +        │
        │   Vuetify)     │          │   Vuetify)       │
        │  app.domain    │          │  admin.domain    │
        └───────┬────────┘          └────────┬─────────┘
                │                            │
                └────────────┬───────────────┘
                             │
                    ┌────────▼─────────┐
                    │  ALB (HTTPS)     │
                    └────────┬─────────┘
                             │
        ┌────────────────────┴────────────────────┐
        │                                         │
┌───────▼────────┐                       ┌────────▼─────────┐
│ Laravel API    │                       │ Laravel Workers  │
│ (ECS Fargate)  │                       │ (Horizon, ECS)   │
│ Auto-scaling   │                       │ Auto-scaling     │
└───┬─────┬──────┘                       └──┬───────────────┘
    │     │                                 │
    │     │     ┌─────────────────┐         │
    │     └────►│ Redis Cluster   │◄────────┘
    │           │ (ElastiCache)   │
    │           └─────────────────┘
    │
    │     ┌──────────────────┐
    ├────►│ PostgreSQL       │
    │     │ (RDS Multi-AZ)   │
    │     └──────────────────┘
    │
    │     ┌──────────────────┐
    ├────►│ S3               │
    │     │ (creator media,  │
    │     │  contracts, etc) │
    │     └──────────────────┘
    │
    │     ┌──────────────────┐
    └────►│ External APIs    │
          │ - Stripe Connect │
          │ - Meta / TikTok /│
          │   YouTube        │
          │ - Persona (KYC)  │
          │ - DocuSign       │
          └──────────────────┘
```

### Component decisions

**Two SPAs, one API.** Main app and admin SPA are separate Vue applications with separate routers, separate auth flows, separate bundles. They share a component library (`packages/ui/`) and design tokens (`packages/design-tokens/`). They consume the same Laravel API.

**Modular monolith for the API.** Laravel app organized as a modular monolith under `app/Modules/` with clear module boundaries. Modules can be extracted to services later if needed; they likely won't need to be. Modules in Phase 1: `Identity`, `Agencies`, `Brands`, `Campaigns`, `Creators`, `Contracts`, `Payments`, `Messaging`, `Boards`, `Audit`, `Admin`. See `02-CONVENTIONS.md` for module structure.

**Horizon-managed Redis queues.** All async work goes through Laravel Horizon. Queue names: `default`, `notifications`, `payments`, `social-sync`, `media`, `audit`, `analytics`. Different queues have different concurrency and retry policies.

**PostgreSQL as the primary database.** Single primary with read replicas (Phase 2+). Use Postgres features intentionally: JSON columns for flexible metadata, full-text search, generated columns, partial indexes.

**S3 for all binary storage.** Creator video samples, draft content, signed contracts, exported reports, profile photos. Bucket per concern. CloudFront for delivery. Signed URLs for private content.

**Stripe Connect for payments.** Express accounts for creators. Escrow via separate platform balance + delayed payouts. See `06-INTEGRATIONS.md`.

---

## 6. AWS topology

### Regions

- **Primary:** `eu-central-1` (Frankfurt). All production data resides here.
- **DR / cold standby:** `eu-west-1` (Ireland). Database snapshots replicated; full failover plan documented in Phase 2.
- **No US regions** for production data. CDN edges may be global.

### Account structure

- **Production account.** Production resources only. Strict access controls.
- **Staging account.** Mirror of production for pre-production testing.
- **Shared services account.** CI/CD runners, central logging, IAM identity center.
- **Dev account (optional).** Sandboxes for individual developers.

In Phase 1 with a solo developer, "account structure" can start as production + staging in two AWS accounts. Shared services and dev accounts are added when team grows.

### Network

- **VPC per environment.** Public subnets for ALB only. Private subnets for ECS tasks, RDS, ElastiCache.
- **No public IPs on application tier.** All outbound through NAT Gateway.
- **VPC Endpoints for S3, ECR, Secrets Manager** to avoid NAT costs and improve security.
- **WAF on ALB.** Rate limiting, OWASP top 10 rules, geo restrictions if needed.

### Compute

- **ECS Fargate for the API and workers.** Auto-scaling target tracking on CPU and request count.
- **Separate task definitions for web and workers.** Workers don't accept HTTP traffic.
- **Min 2 tasks per service in production** for high availability.

### Data

- **RDS PostgreSQL Multi-AZ** in production. Phase 1: db.t3.medium or db.m6g.large. Auto-scaling storage.
- **Read replicas added in Phase 2** when read load justifies it.
- **Automated daily snapshots, 35-day retention.**
- **ElastiCache Redis** (cluster mode disabled in Phase 1, cluster mode enabled in Phase 3+).
- **S3 buckets:**
  - `catalyst-engine-media-prod` — creator media, drafts (private, signed URL access)
  - `catalyst-engine-contracts-prod` — signed contract PDFs (private, audit-tracked access)
  - `catalyst-engine-exports-prod` — generated reports and GDPR exports
  - `catalyst-engine-public-prod` — public profile photos (CloudFront)
- **Lifecycle rules:** old draft media archived to Glacier after 90 days.

### Edge & CDN

- **CloudFront** for static assets (both SPAs), public S3 content, and select API caching.
- **ACM certificates** for custom domains.
- **Route 53** for DNS.

---

## 7. Authentication & authorization

### Authentication

- **Main app:** Laravel Sanctum SPA authentication (cookie-based, same-domain). Stateful API auth.
- **Admin app:** same Sanctum cookie auth, separate domain (`admin.domain.com`), separate auth guard, mandatory 2FA, IP allowlist optional, much shorter session timeout (30 minutes).
- **API tokens:** Sanctum personal access tokens for any future programmatic use (Phase 3 public API).
- **2FA:** TOTP via authenticator apps. Recovery codes. Forced for admin users; optional but encouraged for agency admins; available for all users from Phase 1.
- **Passwords:** Argon2id hashing (Laravel default). Min 12 characters, breach-checked via HaveIBeenPwned k-anonymity API at signup.

### Authorization

- **Role-based access control** with policy classes per resource.
- **Roles (Phase 1):**
  - `creator` — can manage own profile, sign contracts, set availability
  - `agency_admin` — full agency control
  - `agency_manager` — manage campaigns, no billing or user management
  - `agency_staff` — execute campaigns, no creation of brands/users
  - `platform_admin` (admin SPA) — Catalyst Engine ops, full access scoped by admin role
  - `platform_super_admin` — adds the ability to manage other admins
- **Future roles (designed for, not built in Phase 1):**
  - `brand_admin`, `brand_user` — Phase 2
  - `platform_support`, `platform_finance`, `platform_security` — Phase 2 admin role refinement

- **Policies live in module-local `Policies/` folders.** Every controller method that touches a resource calls `$this->authorize(...)`.
- **All authorization decisions are tested.** A policy without tests fails review.

---

## 8. Audit, logging & observability

### Audit log

- **Every privileged action emits a structured audit event.** Stored in the `audit_logs` table, append-only.
- **Required fields:** actor (user ID + type), action (verb + resource), target (type + ID), agency_id (if scoped), timestamp, IP, user agent, reason (free-text, mandatory for destructive/sensitive actions), metadata (JSON).
- **Audit log is append-only.** No updates, no deletes. Old entries archive to S3 cold storage after 1 year (Phase 3+).
- **Admin actions on production are doubly logged** (database + dedicated S3 audit bucket with object lock).

### Application logs

- **Structured JSON logs** to CloudWatch.
- **Sentry** for errors and exceptions, separate DSN for backend / main SPA / admin SPA.
- **Log retention:** 90 days hot, 1 year cold (S3), 7 years compliance archive (Glacier Deep Archive).

### Metrics & alerting

- **CloudWatch metrics** for infra (CPU, memory, queue depth, DB connections).
- **Custom application metrics** (signup funnel, payment success rate, campaign creation rate).
- **Alerting:**
  - Pages: site down, payment failure rate spike, DB connection pool exhaustion, queue backlog, error rate spike
  - Notifies (no page): elevated error rate, queue lag, high latency

### Product analytics

- **Tool TBD** (Amplitude, PostHog, or Mixpanel — choose in Phase 1 based on EU data residency).
- Instrument every meaningful event from Phase 1: signup steps, KYC steps, profile completion, campaign creation, invitation sent, application submitted, draft submitted, content posted, payment released.

---

## 9. Security baseline

Detailed in `05-SECURITY-COMPLIANCE.md`. Architectural baseline:

- **TLS 1.2+ everywhere.** No HTTP. HSTS preload eligible from day one.
- **Encryption at rest:** RDS, S3, EBS — all encrypted with AWS-managed keys (Phase 1) or customer-managed KMS keys (Phase 2+).
- **Field-level encryption** for sensitive PII (tax IDs, bank details) at the application layer.
- **Secrets Management:** AWS Secrets Manager. No env files in production. Rotation policies for database credentials.
- **Dependency scanning:** Dependabot + GitHub security alerts. Critical CVEs patched within 72 hours.
- **Static analysis security testing:** semgrep or Snyk on every PR.
- **OWASP Top 10 awareness in every code review.**

---

## 10. GDPR architectural commitments

- **EU-only data residency** for production. No data in US regions.
- **Consent capture** at every collection point with clear purpose statements.
- **Data subject access requests:** automated export within 30 days. Designed as a background job from Phase 1.
- **Right to erasure:** soft delete with anonymization, then hard delete after legal retention period. Audit logs are retained per legal requirement and reference anonymized actor IDs after erasure.
- **Data processing agreements** with all third parties (Stripe, social APIs, KYC, e-sign).
- **DPO contact** built into platform (Phase 2).
- **Cookie consent and tracking consent** implemented via a CMP (Phase 1).

---

## 11. Data evolution principles

Detailed in `08-DATABASE-EVOLUTION.md`. Architectural principles:

- **Expand → Migrate → Contract** for every schema change after Phase 1 ships.
- **Soft deletes everywhere** on entities that matter (creators, contracts, campaigns, payments).
- **Append-only patterns** for audit, payment events, and contract events.
- **No destructive migrations** on tables with live data. Three-deploy minimum for renames, splits, type changes.
- **Tested rollback** on every migration.

---

## 12. The board engine (architectural placement)

Detailed in `10-BOARD-AUTOMATION.md`. Architectural placement:

- Each Campaign has one Board. Board has Columns. Cards live in Columns.
- **Smart automation model (Phase 1):** the system emits domain events (`creator.invited`, `draft.submitted`, etc.). Per-board configuration maps events to column moves. No arbitrary user-defined rules in Phase 1.
- Card movements are audit-logged with `from_column`, `to_column`, `triggered_by` (event or user), `reason`.
- Future phases may add a generic rule builder; the architecture leaves room.

---

## 13. Internationalization architecture

- **Locales supported in Phase 1:** `en`, `pt`, `it`. English is default fallback.
- **Backend i18n:** Laravel `lang/` directory, separate file per module.
- **Frontend i18n:** `vue-i18n`, JSON files per locale per app, lazy-loaded by route.
- **Locale resolution order:**
  1. Explicit user setting (stored on user record)
  2. `Accept-Language` header
  3. Default `en`
- **Database content:** stored as written; no auto-translation. Some fields (e.g., agency-defined campaign categories) may have translation tables (Phase 2+).
- **Currency:** GBP, EUR primary in Phase 1. Stored as integer minor units (pence/cents). Currency is a property of the campaign, not the user.
- **Dates and numbers:** locale-aware formatting on frontend, UTC storage on backend.

---

## 14. Mobile readiness

- **Phase 1 is web-responsive only.** Mobile native apps come in Phase 2.
- **API-first design** ensures Phase 2 mobile clients consume the same API as the SPAs. No web-only assumptions in API design.
- **Authentication strategy** must support both Sanctum SPA cookies (current) and Sanctum personal access tokens (mobile, Phase 2). Both are designed in from Phase 1.
- **Push notifications** are Phase 2; database tables and event hooks are designed in Phase 1.

---

## 15. Third-party integrations (architectural placement)

Detailed in `06-INTEGRATIONS.md`. Phase 1 integrations:

| Concern               | Service                          | Purpose                                      |
| --------------------- | -------------------------------- | -------------------------------------------- |
| Payments              | Stripe Connect (Express)         | Escrow, payouts, KYC for payments            |
| Identity verification | Persona or Veriff                | Creator KYC                                  |
| Tax forms             | Track1099 or HelloSign API       | W-9/W-8BEN equivalent for EU                 |
| Contracts             | DocuSign or HelloSign            | E-signature for universal master contract    |
| Social APIs           | Meta Graph, TikTok, YouTube Data | Profile verification, metrics, post tracking |
| Email                 | Postmark or AWS SES              | Transactional email                          |
| Error monitoring      | Sentry                           | Backend + both SPAs                          |
| Product analytics     | TBD (Amplitude / PostHog)        | Funnel and retention                         |

Each integration is wrapped in a service class. Direct vendor SDK usage is forbidden outside the service class. This makes vendor swaps possible and tests mockable.

---

## 16. Performance budgets (Phase 1 targets)

- **API p50 response time:** < 150ms for read endpoints, < 500ms for write
- **API p95 response time:** < 500ms for read, < 1500ms for write
- **SPA initial load:** < 2.5s on Fast 3G, < 1s on broadband
- **SPA route transition:** < 200ms perceived
- **Background job latency:** < 5s queue lag p95
- **Database query budget per request:** < 20 queries (use Laravel Telescope to enforce in dev)

These are Phase 1 targets. They tighten over later phases.

---

## 17. CI/CD and environments

- **Environments:** `local`, `staging`, `production`. (Add `preview` per-PR in Phase 2 if useful.)
- **Branching:** trunk-based with short-lived feature branches. PRs merge to `main`.
- **Pipeline on PR:** lint, typecheck, unit tests, feature tests, build SPAs, security scan.
- **Pipeline on merge to `main`:** all of the above + deploy to staging.
- **Pipeline on tag (e.g., `v1.2.3`):** deploy to production with manual approval gate.
- **Migrations** run as part of deploy, but with safety: reviewed in PR, dry-run on staging, manual approval for destructive migrations on production.
- **Feature flags** via Laravel Pennant. New features launched off, toggled on per-tenant.
- **Rollback:** previous container image is always one click away. Database rollback is via tested `down()` migration or restored snapshot.

---

## 18. Disaster recovery

- **Daily automated backups** of RDS (35-day retention).
- **Cross-region snapshot replication** to `eu-west-1` (Phase 2).
- **S3 cross-region replication** for critical buckets (Phase 2).
- **Documented runbook** for: DB restore, region failover, application rollback, payment service degradation.
- **Annual disaster recovery exercise** (Phase 3+).
- **RTO target:** 4 hours (Phase 1) → 1 hour (Phase 4).
- **RPO target:** 1 hour (Phase 1) → 15 minutes (Phase 4).

---

## 19. The "build for now, design for later" matrix

The architectural intent is summarized here. Use this as a quick reference when deciding what to build:

| Concern       | Phase 1 build                                        | Phase 1 design                                      |
| ------------- | ---------------------------------------------------- | --------------------------------------------------- |
| Multi-tenancy | One tenant (Catalyst), full isolation                | Supports thousands                                  |
| Brands        | Created by agency, no brand-side users               | Schema and permissions ready for brand portal       |
| Creators      | Imported via bulk + self-onboarding                  | Schema ready for direct marketplace discovery       |
| Contracts     | Universal master only                                | Polymorphic — per-campaign addendum schema present  |
| Payments      | Standard payout, single currency per campaign        | Multi-currency, fast payout columns present         |
| Calendar      | Basic availability blocks + auto-block on assignment | Schema ready for Google Calendar sync, Gantt views  |
| Boards        | Smart automation, fixed event catalog                | Architecture leaves room for arbitrary rule builder |
| Search        | Postgres full-text                                   | API and indexing service abstraction ready for swap |
| AI features   | None                                                 | API hooks and event stream ready for ML consumers   |
| Mobile        | Responsive web                                       | API contract is mobile-ready                        |
| Public API    | None                                                 | Versioning (`/api/v1/...`) and abstraction in place |
| Languages     | en, pt, it                                           | Translation table pattern ready for content i18n    |
| Regions       | EU only                                              | Architecture is region-pluggable, not US-baked      |

---

## 20. Open questions to resolve before Phase 1 build

These are decisions still to be made. They block specific parts of Phase 1 and must be resolved before the spec is built upon.

1. **Hosting choice:** ECS Fargate vs Elastic Beanstalk vs Vapor. Recommendation: ECS Fargate with Terraform.
2. **Product analytics tool:** Amplitude vs PostHog vs Mixpanel. EU data residency is the key filter.
3. **KYC vendor:** Persona vs Veriff vs Onfido. EU presence and pricing matter.
4. **E-sign vendor:** DocuSign vs HelloSign (now Dropbox Sign) vs SignWell.
5. **Tax forms vendor for EU creators:** EU equivalents to W-9/W-8BEN — research needed.
6. **Email provider:** Postmark vs AWS SES vs Resend.
7. **CMP for cookie consent:** OneTrust, Cookiebot, or self-built minimal.

These will be resolved in `06-INTEGRATIONS.md` once decisions are made.

---

**End of master architecture. The other documents in this folder build on this foundation.**
