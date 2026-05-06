# 21 — Phase 2 Specification

> ⚠️ **REFERENCE ONLY** — Do NOT implement features from this document.
>
> This phase is REFERENCE ONLY unless `ACTIVE-PHASE.md` points to Phase 2. Cursor uses this document only to ensure Phase 1 is shaped correctly to accept these features later.

---

## 1. Phase 2 mission

**Scale beyond one agency. Bring brand-side users into the platform. Add native mobile. Reach enterprise reliability.**

Phase 2 takes the proven foundation and proves it works for more than one customer, more than one user surface, and more than one device.

---

## 2. Success criteria

- 5+ agencies in production
- 500+ creators active
- 50+ campaigns/month across the platform
- 99.9% uptime sustained
- SOC 2 Type 1 audit completed and certified
- Native mobile apps in production
- First brand-side users live
- First paid customers (transitioning Catalyst from free pilot to paid)

---

## 3. Major features

### 3.1 Brand client portal

- Brand-side users with their own login, scoped to their brand
- Brand client view: campaigns for their brand only, drafts pending approval, performance reports
- Client approval lane integrated into the campaign board
- Markup hiding (clients never see actual creator fees)
- White-label brand portal (agency logo, optionally co-branded)
- Brand-specific settings: approval workflows, exclusivity rules, brand safety preferences

### 3.2 Native mobile apps (iOS + Android)

- Creator-side mobile app (priority): browse assignments, accept/decline, submit drafts, message agency
- React Native preferred (shared code; matches frontend stack)
- Push notifications for campaign invitations, draft feedback, payment notifications
- Offline support for reading content; require connection for actions
- Biometric auth integration

### 3.3 Fast payouts

- 48-hour payout option
- Tiered fees: standard (free, T+5–7), fast 48h (1–2% fee), instant (3% fee)
- Wallet UX with clear fee disclosure
- Payment method management improved

### 3.4 Calendar enhancements

- Two-way Google Calendar sync (OAuth)
- Outlook / Apple Calendar sync via CalDAV or native APIs
- Posting cadence Gantt view across campaign creators
- Travel locations (creator says "I'll be in Tokyo Aug 1–10" → useful for location-based campaigns)
- Soft vs hard availability blocks (already designed in P1, fully utilized in P2)

### 3.5 Discovery improvements

- Filtered search across full creator pool (filters by audience demographics, engagement quality)
- Saved talent pools per agency, per brand
- Audience demographics from social APIs (Phase 1 columns activated)
- Engagement rate calculations refined
- Fake follower detection via integration (HypeAuditor or Modash)

### 3.6 Agency power tools

- Multi-campaign rollup dashboard (cross-brand, cross-campaign personal kanban)
- Per-brand rollup dashboard
- Bulk operations: bulk approve drafts, bulk message, bulk pay, bulk export
- Saved campaign templates per agency
- Saved board templates per agency
- Custom report builder with scheduled email delivery
- Brand-specific custom contract templates layered on universal master

### 3.7 Per-campaign contracts (full UI)

- Brands can layer per-campaign contracts on top of the universal contract
- DocuSign / e-sign vendor integration fully utilized
- Contract template library per brand with placeholder substitution

### 3.8 Real analytics

- Real-time campaign performance pulled from social APIs
- UTM and promo code conversion tracking
- ROI / CPM / CPE / CPA calculations
- Audience overlap analysis between selected creators
- Per-brand benchmarking (anonymized aggregate data)
- Time-series metrics history (daily snapshots of post performance)

### 3.9 Trust & safety expansion

- Formal blacklist appeal process (creators can appeal blacklists)
- Two-way reviews (brand rates creator, creator rates brand)
- Verification tiers (premium creators, verified brands)
- Dispute resolution workflow with SLAs
- Content moderation tools

### 3.10 WebSocket realtime

- Laravel Reverb (or Pusher) for real-time updates
- Channels: agency.{agency}.campaign.{campaign}, message threads, board updates
- Replaces 30-second polling from Phase 1
- Authorized via Laravel Broadcasting

### 3.11 Enterprise hardening

- SOC 2 Type 1 audit and certification
- Annual penetration testing
- WAF + DDoS protection (Cloudflare or AWS Shield Advanced)
- Rate limiting refined (per-tenant tiers)
- Field-level PII encryption with customer-managed KMS keys
- Data residency options (EU hosting on request — already EU-only)
- Read-only API access for enterprise clients
- Two-person approval rule for high-risk admin actions

### 3.12 Operational maturity

- On-call rotation with PagerDuty
- Defined SLAs (99.9% uptime, response time targets)
- Public status page
- Customer support tooling (Zendesk or Intercom)
- Refined admin roles (`support`, `finance`, `security`)
- Refund reconciliation tooling
- Subscription billing management

### 3.13 Push notifications

- iOS APNs and Android FCM integration
- Notification preferences per channel per event type
- In-app + email + push triple-channel delivery
- Notification history viewable

### 3.14 Virus scanning on uploads

- ClamAV worker scans every uploaded file
- Files quarantined until clean
- VirusTotal API integration for high-risk uploads (optional)

---

## 4. Database changes (Phase 2)

All Phase 2 schema additions follow expand/migrate/contract:

- `brand_users` table created (designed in P1, built now)
- `brand_user_invitations` table
- Activate Phase 2 columns that were nullable in Phase 1: `client_review_*` on drafts, `appeal_*` on agency*creator_relations, `metrics_history` on campaign_posted_content, `external_calendar*_`on availability blocks,`payout*speed`on payments,`virus_scan*_` on files
- `audit_logs_partitioned` (transitioning from single table to monthly partitions; multi-deploy)
- Mobile session tracking tables
- Push notification token tables
- `campaign_templates`, `board_templates` tables
- `dispute_resolutions` table
- `creator_reviews` table (reviews of creators by agencies/brands)
- `agency_reviews` table (reviews of agencies by creators)
- `payment_invoices` table (agency-level invoicing)
- Subscription billing tables (if SaaS pricing tiers)

---

## 5. API additions

- Brand portal endpoints under `/api/v1/agencies/{agency}/brands/{brand}/portal/...`
- Mobile-specific endpoints (same routes, Bearer token auth)
- Realtime channels (Broadcasting)
- Calendar sync endpoints
- Bulk operations endpoints (already designed in P1, expanded coverage)
- Analytics endpoints (campaign performance, creator performance, brand performance)
- Contract template management endpoints
- Saved templates endpoints
- Subscription / billing endpoints

---

## 6. New integrations

- Google Calendar API (creator availability sync)
- Outlook Calendar API
- FCM (Firebase Cloud Messaging) for Android push
- APNs for iOS push
- HypeAuditor or Modash for fake follower detection
- Subscription billing: Stripe Billing
- Optional: HubSpot or Salesforce for agency CRM integration
- Status page provider (Statuspage.io) or self-hosted

---

## 7. Phase 2 doesn't include (saved for later phases)

- Direct brand self-serve signup → P3
- Marketplace mode for creators → P3
- AI features → P3
- Workflow rule builder → P3
- Multi-region deployment → P3
- Vertical AI agents → P4
- Public API → P3
- ISO 27001 certification → P4

---

## 8. Phase 2 timeline estimate

Roughly 5–7 months for a small team (founder + 1–2 engineers). Solo dev: 7–9 months.

By the end of Phase 2:

- The platform supports multiple agencies with isolated data
- Brand clients have direct access to their campaigns
- Mobile apps are in production for creators
- The platform is SOC 2 Type 1 certified
- The team is ready to scale to direct brands and the marketplace in Phase 3

---

**End of Phase 2 reference spec. DO NOT implement until ACTIVE-PHASE.md points to Phase 2.**
