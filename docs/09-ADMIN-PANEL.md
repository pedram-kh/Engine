# 09 — Admin Panel

> **Status: Always active reference. Defines the structure, features, and security model of the Vue admin SPA. Cursor builds this as a separate, dedicated SPA from Phase 1.**

The admin panel is not a feature; it's a separate product. It's used by Catalyst Engine ops staff to operate the platform — KYC review, profile approvals, dispute resolution, audit investigation, manual data corrections. Without it, you'd run raw SQL in production at 2am. With it, you operate the platform safely.

---

## 1. Architectural placement

- **Separate Vue 3 SPA** in `apps/admin/`.
- **Same backend** as the main app (`apps/api/`) — admin endpoints under `/api/v1/admin/...`.
- **Separate domain:** `admin.catalyst-engine.com`.
- **Separate auth flow:** same Sanctum cookies, but mandatory 2FA, shorter session timeout, optional IP allowlist.
- **Shared design tokens and component library** (`packages/ui/`, `packages/design-tokens/`) so visual consistency is maintained, but with admin-specific layout density (see `01-UI-UX.md` § 11).

---

## 2. Why separate SPA (decision recap)

The decision was made (after considering Filament) to build a custom Vue admin SPA from Phase 1. Tradeoff acknowledged:

- **Cost:** ~4–6 extra weeks of Phase 1 build time.
- **Benefit:** total UI flexibility, single Vue + TypeScript codebase, shared component library, no vendor lock-in, custom workflows possible.
- **Implication:** admin features will be more deliberate (less "free" CRUD generation), and the developer should resist building admin features that aren't truly needed.

---

## 3. Authentication & access

### 3.1 Login flow

- Same Sanctum SPA cookie auth as main app.
- Cookies scoped to `.catalyst-engine.com` so they work across `app.` and `admin.` subdomains, but admin has its own session table entry with its own timeout.
- Login at `https://admin.catalyst-engine.com/sign-in`.
- Email + password + mandatory 2FA code in one flow (no separate 2FA step).

### 3.2 Role model

Admin users have `users.type = 'platform_admin'` and an entry in `admin_profiles` with their `admin_role`.

| Phase   | Roles                                                                |
| ------- | -------------------------------------------------------------------- |
| **P1**  | `super_admin` (only role) — can do everything                        |
| **P2**  | `super_admin`, `support`, `finance`, `security` — scoped permissions |
| **P3+** | Refined roles, custom roles, role hierarchy                          |

Phase 1 keeps it simple: one role. Adding scoped roles is a Phase 2 hardening task.

### 3.3 Access controls

- **2FA mandatory** for every admin user. No exceptions.
- **Optional IP allowlist** per admin user. If set, requests from other IPs return 403.
- **Session timeout:** 30 minutes idle, 8 hours absolute. Users are signed out and must re-authenticate.
- **Concurrent session limit:** an admin can only have one active session. New login invalidates old.

### 3.4 Audit

Every admin action is double-logged:

1. To the standard `audit_logs` table (queryable from admin SPA itself).
2. To a dedicated S3 bucket with object-lock (compliance mode, immutable).

This redundancy means even if `audit_logs` is somehow compromised, the S3 record stands.

---

## 4. Admin SPA structure

```
apps/admin/
├── public/
│   └── fonts/
├── src/
│   ├── modules/
│   │   ├── auth/                   # Login + 2FA
│   │   ├── dashboard/              # Home / overview
│   │   ├── agencies/               # Manage agencies
│   │   ├── brands/                 # Manage brands across agencies
│   │   ├── creators/               # Creator management, KYC, approvals
│   │   ├── campaigns/              # Campaign visibility (read-mostly)
│   │   ├── payments/               # Payment investigation, refunds, disputes
│   │   ├── audit/                  # Audit log viewer
│   │   ├── support/                # User search, impersonation, support tools
│   │   ├── operations/             # System health, queues, jobs
│   │   ├── compliance/             # GDPR exports, erasure queue
│   │   ├── feature-flags/          # Toggle features
│   │   └── settings/               # Admin user management (P2)
│   ├── core/
│   │   ├── api/
│   │   ├── auth/
│   │   ├── i18n/
│   │   ├── router/
│   │   └── stores/
│   ├── plugins/
│   │   └── vuetify.ts
│   ├── App.vue
│   └── main.ts
└── tests/
```

The structure mirrors the main app's modular pattern, but the modules are admin-scoped.

---

## 5. Layout and navigation

### 5.1 Layout

- **Top bar:** 48px tall (denser than main app's 56px). Catalyst Engine logo, environment indicator (red border on production), admin user menu.
- **Left sidebar:** 220px wide, never collapses. Lists primary modules.
- **Main content:** fluid, no max-width (admins want every pixel).
- **Right detail drawer:** 480px or 640px when needed for entity inspection.

### 5.2 Environment indicator

A persistent banner at the top showing which environment the admin is connected to:

- **Local:** gray banner, "LOCAL"
- **Staging:** blue banner, "STAGING"
- **Production:** red banner, "PRODUCTION — ALL ACTIONS LOGGED"

This prevents accidental destructive actions in production by mistake.

### 5.3 Sidebar navigation (Phase 1)

```
Dashboard
─────
Agencies
Brands
Creators
  Pending approvals  (badge with count)
  KYC queue          (badge with count)
  All creators
  Blacklisted
Campaigns
Payments
  Disputes           (badge if any open)
  Recent
─────
Audit Log
Support
  User search
  Impersonation log
─────
Operations
  System health
  Background jobs
  Failed jobs        (badge if any)
Compliance
  Export requests
  Erasure queue      (badge if pending)
Feature Flags
─────
[Admin user menu]
```

---

## 6. Phase 1 features

### 6.1 Dashboard

A home page showing the platform's current operational state.

**Cards:**

- Active agencies (count)
- Active campaigns (count)
- Pending creator approvals (count + link)
- Pending KYC reviews (count + link)
- Open disputes (count + link)
- Failed payments today (count + link)
- Failed background jobs (count + link)
- API error rate (last hour)
- Active sessions (admin + user)

**Activity feed:** the last 50 audit entries, real-time-ish (5-second polling).

### 6.2 Agency management

- **List view:** all agencies with status, subscription tier, count of brands, count of creators (in their roster), date joined.
- **Detail view:** full agency profile, list of agency users, list of brands, billing info, recent activity.
- **Actions:**
  - Suspend agency (with reason, audit-logged)
  - Reactivate agency
  - Change subscription tier (P2)
  - View as agency admin (impersonation flow)
  - Trigger GDPR export
  - Approve agency erasure request

### 6.3 Brand management

- **List view:** all brands across all agencies. Filter by agency.
- **Detail view:** brand profile, parent agency, campaigns, brand-scoped blacklists.
- **Actions:** rare in admin (mostly belongs to agency staff), but admin can:
  - Force-disable a brand if compliance requires
  - View as brand user (impersonation, P2)

### 6.4 Creator management

This is one of the highest-value admin areas.

- **Pending approvals queue:** sortable list of creators awaiting profile review.
  - Each row shows: avatar, name, country, completeness score, days since signup.
  - Click opens detailed review pane: profile, social accounts, samples, completeness checks.
  - Actions: approve, reject (with reason), request more info (sends message).

- **KYC review queue:** creators with pending KYC verifications (where manual review is required).
  - Decision data from KYC vendor displayed (with PII appropriately masked unless drilled into).
  - Actions: mark verified, mark rejected (with reason), request re-verification.

- **Creator detail view:** full creator profile. Includes:
  - Profile, samples, social accounts, availability calendar
  - KYC history
  - Tax profile
  - Payout method status
  - Campaign history (assignments across all agencies)
  - Payment history
  - Audit history (every change to this creator)
  - Reviews and ratings
  - Blacklist status across agencies

- **Actions:**
  - Approve / reject application
  - Suspend creator
  - Edit profile (with reason logged)
  - Trigger GDPR export
  - Trigger GDPR erasure
  - Override blacklist (rare; logged)
  - Impersonate creator

- **Search:** by name, email, social handle, ULID. Fuzzy search.

- **Filter:** by status, country, KYC status, application status, creation date.

### 6.5 Campaign visibility

Read-mostly. Admins shouldn't be modifying campaigns directly except in support situations.

- **List view:** all campaigns across the platform. Filter by agency, brand, status, date.
- **Detail view:** full campaign with assignments and board state.
- **Actions:**
  - Force-cancel a campaign (if compliance / dispute requires; logged)
  - Resync campaign metrics from social APIs
  - Export campaign report

### 6.6 Payment investigation

A critical area. Admins handle payment issues in support situations.

- **List view:** all payments. Filter by status, date range, dispute status, amount.
- **Detail view:** full payment record including:
  - Brand charge
  - Creator payout amount
  - Platform fee
  - Stripe transaction IDs
  - Payment events timeline
  - Linked assignment
  - Dispute history if any

- **Actions:**
  - Manual refund (with reason; logged; triggers Stripe refund)
  - Resolve dispute (mark in favor of brand or creator)
  - Manual payout retry on failed payouts
  - View raw Stripe data (proxy to Stripe API)

### 6.7 Audit log viewer

A first-class feature.

- **Filter by:**
  - Actor (user search)
  - Action (dropdown of all action types)
  - Subject type (Campaign, Creator, etc.)
  - Subject (search by ULID or name)
  - Agency
  - Date range

- **Display:** time, actor, action, subject, reason (if present), and a "View details" expansion showing `before` / `after` JSON.

- **Export:** CSV or JSON download for compliance investigations (logged as its own audit action).

- **Performance:** paginated server-side with cursor pagination given the table's growth rate.

### 6.8 User search & impersonation

The single most-used support tool.

- **User search:** by email, name, ULID, agency name. Across all user types (creator, agency_user, brand_user, admin).
- **User detail:** full profile, agency memberships, role(s), recent activity, recent audit entries.
- **Impersonation:**
  - Admin clicks "Impersonate" with a mandatory reason (free text) and optional support ticket reference.
  - Audit entry created.
  - Admin sees the platform as that user in a separate browser tab/window.
  - A persistent banner at the top of the impersonated session shows: "Impersonating [username]. Started at [time]. Ends in [time]." Click banner to end.
  - Default impersonation session: 30 minutes, extendable.
  - All actions during impersonation are dual-logged: as the user (for normal app behavior) AND as the admin impersonating (for audit).
  - Impersonation cannot be used for: changing user passwords, disabling 2FA, signing contracts, releasing payments. These hard-blocked actions require an admin to act as themselves with their own authority.

- **Impersonation log:** searchable list of all impersonation sessions ever.

### 6.9 Operations

- **System health:**
  - API status
  - Database connection pool
  - Redis status
  - Queue depths per queue
  - Background job processing rate
  - Recent error rate (from Sentry)
  - Disk usage
  - External integrations health (last successful Stripe call, last KYC webhook, etc.)

- **Background jobs:**
  - List of recent jobs by status
  - Failed job inspection with full payload and stack trace
  - Manual retry of failed jobs (with reason)
  - Manual deletion of failed jobs that should not retry

### 6.10 Compliance

- **GDPR export queue:**
  - List of pending export requests
  - Status: pending, processing, ready, downloaded, expired
  - Manual approval (most are auto-approved; admin reviews edge cases)

- **GDPR erasure queue:**
  - List of erasure requests awaiting admin approval
  - Each shows: requesting user, subject, requested at
  - Approval triggers `ExecuteDataErasureJob` after a 7-day cool-down (recoverable mistake window)
  - Rejection requires reason

### 6.11 Feature flags

- **Flag list:** all flags with current state (enabled / disabled / partial).
- **Per-tenant overrides:** show which agencies have which flags overridden.
- **Toggle a flag:** with reason, immediate effect, audit-logged.
- **Flag history:** every change to every flag.

---

## 7. Phase 2 admin additions

- **Brand portal management:** monitor brand-side users.
- **Mobile session management:** see active mobile sessions, force logout.
- **Push notification testing:** send test push notifications.
- **Calendar integration management:** see calendar sync status per creator.
- **Refined admin roles:** `support`, `finance`, `security` with scoped permissions.
- **Two-person approval** for high-risk actions (mass operations, large refunds).
- **Refund reconciliation:** match Stripe refunds to internal records.
- **Subscription billing management:** agency subscription tier changes, billing history.

---

## 8. Phase 3 admin additions

- **Fraud detection dashboard:** anomaly alerts, manual review queue.
- **AI / ML model monitoring:** model versions, prediction accuracy, drift detection.
- **A/B test management:** define experiments, view results.
- **Marketplace moderation:** approve campaigns visible to creators.
- **Direct brand approval queue:** vetting brands signing up directly without an agency.
- **Vendor cost dashboard:** Stripe fees, KYC costs, e-sign costs per agency / per month.

---

## 9. Phase 4 admin additions

- **Compliance reporting:** SOC 2 / ISO 27001 evidence collection, automated reports.
- **Multi-region operations:** view per-region health and status.
- **Vertical AI agent monitoring:** for music / beauty / gaming agents.
- **Affiliate / performance attribution oversight.**
- **Content licensing marketplace moderation.**

---

## 10. Permission model details

### 10.1 The `super_admin` role (Phase 1)

In Phase 1, the only role. Can:

- Read everything
- Modify users (suspend, edit)
- Approve / reject creator applications
- Manage KYC outcomes
- Refund payments
- Resolve disputes
- Toggle feature flags
- Trigger GDPR exports / erasures
- Impersonate any user
- View audit logs
- Manage admin users (create, suspend)

### 10.2 Refined roles (Phase 2)

| Role          | Capabilities                                                                      |
| ------------- | --------------------------------------------------------------------------------- |
| `super_admin` | All capabilities, including admin user management                                 |
| `support`     | User search, impersonation, profile edits, message replies. NOT payments.         |
| `finance`     | Payment investigation, refunds, disputes, billing. Read-only on user profiles.    |
| `security`    | Audit log access, security incident handling, 2FA resets. Read-only on most data. |

### 10.3 Cross-cutting rules (all roles)

- Cannot disable own 2FA.
- Cannot delete own account.
- Cannot change own role.
- Every action audit-logged.
- Reasons mandatory on destructive / sensitive actions.

---

## 11. Two-person rule (Phase 2+)

For high-risk actions, require approval from a second admin:

- Mass operations affecting >100 records
- Refunds above €10,000
- Account deletion of an agency with active campaigns
- Bulk creator suspension
- Production database queries (Phase 3)

The first admin initiates. A request goes to a second admin's dashboard. The second admin reviews and approves or rejects (with reason). Only then does the action execute.

Phase 1 doesn't enforce this; Phase 2 adds it as part of compliance maturity.

---

## 12. Operational features

### 12.1 Real-time queue depth

The admin home shows queue depth in real time. Sustained backlog triggers an alert in CloudWatch and surfaces a banner in admin SPA.

### 12.2 Failed job triage

Every failed job is visible in admin with:

- Full job class and payload
- Exception class and message
- Stack trace
- Attempts so far
- Time of last attempt

Admin can retry manually (with reason) or delete (with reason).

### 12.3 Manual data corrections

When data needs to be fixed and there's no UI for it (e.g., a Stripe webhook arrived out of order and our internal state is wrong), admin uses Artisan-style commands surfaced in admin SPA:

- A whitelisted catalog of "data correction commands" is available.
- Each command has a form for its parameters.
- Each command requires a reason.
- Each command is dry-run-able (preview impact before executing).
- Each execution is logged in audit.

This replaces the historical pattern of "ssh in and run a script" with safer, audited operation.

---

## 13. UI patterns specific to admin

### 13.1 Density

Admin tables use 32px row height (vs main app's 40px). More info per screen.

### 13.2 Bulk operations

Most list views support bulk selection. Bulk actions appear in a sticky footer when items are selected:

- Bulk approve creators
- Bulk message users
- Bulk feature flag toggle for selected agencies

Bulk operations invoke the same audit and reason requirements as individual operations.

### 13.3 Inline editing

Frequently-edited fields support inline editing on the detail view (click to edit, save on blur or Enter). With reason captured for fields that require it.

### 13.4 Quick actions

Each list row has a kebab menu with quick actions: View, Impersonate, Audit history, Suspend, etc.

### 13.5 Expandable rows

Tables expand on click to show a few key fields without navigating to the detail page.

---

## 14. Performance considerations

- All list views are paginated server-side.
- Defaults: 25 per page, max 100.
- Audit log uses cursor pagination (high volume).
- Search uses backend full-text search (Postgres FTS in P1; Meilisearch/OpenSearch in P2/P3).
- Heavy reports (audit exports, GDPR exports) run as background jobs and notify when ready.

---

## 15. Testing the admin SPA

- Component tests for every reusable admin component.
- E2E tests for critical admin workflows:
  - Approve a creator
  - Reject a creator with reason
  - Investigate a payment
  - Refund a payment
  - Impersonate a user
  - Search audit log
  - Trigger GDPR export
  - Toggle feature flag
- Permission tests confirming roles can only access permitted features (Phase 2+).

---

## 16. The admin developer mindset

When building admin features, think:

- "If something goes wrong in production at 2am, can I diagnose it from this UI?"
- "If support gets a frustrated user complaint, can I find their data and fix the issue?"
- "If a bug causes inconsistent state, can I correct it without raw SQL?"
- "If a creator's account is hacked, can I investigate from this UI?"
- "If a payment failed, can I see the Stripe IDs to look it up?"

Every admin feature exists because someone, someday, will need it under stress. Build it for that moment.

---

## 17. What admin must NOT do

- Bypass the API. Admin SPA goes through the same API as the main app, with admin-specific endpoints. No direct DB access from the SPA.
- Show secrets. API keys, OAuth tokens, password hashes — never displayed even to admins.
- Allow undefined operations. Every action is a defined endpoint with a policy.
- Skip audit. Even read operations on sensitive data (KYC documents, tax info) emit an audit entry.
- Run unbounded queries. Pagination on everything. No "show all" buttons.

---

**End of admin panel spec. The platform's safety net.**
