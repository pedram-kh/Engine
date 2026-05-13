# 20 — Phase 1 Specification

> **Status: This is the ACTIVE build target when `ACTIVE-PHASE.md` points to Phase 1. Cursor implements only what's defined here. Future-phase features are out of scope.**

This document is the contract for what Phase 1 contains. If a feature isn't listed here, it's not in Phase 1, regardless of how easy it seems to add. If something is unclear, Cursor asks before building.

---

## 1. Phase 1 mission

**Build the foundation of Catalyst Engine and ship it as an internal operational tool for one agency partner (Catalyst), with the architectural shape required to scale to thousands of agencies across the next three phases.**

The agency uses it to manage real campaigns with their existing roster of hundreds of creators. The platform handles real money via Stripe Connect, real PII under GDPR, and real client commitments. It is not a prototype. It is a serious, enterprise-grade V1.

---

## 2. Success criteria

Phase 1 is complete when:

- ✅ One agency (Catalyst) is using the platform to run real campaigns
- ✅ 100+ creators are onboarded and verified
- ✅ At least 5 campaigns have been executed end-to-end (invitation → payment release)
- ✅ All payments flow through the platform via Stripe Connect
- ✅ Zero data loss incidents
- ✅ Zero cross-tenant data leaks
- ✅ Zero successful payment fraud incidents
- ✅ 99.9% uptime sustained for 30 days
- ✅ All 20 critical-path E2E tests passing
- ✅ Coverage thresholds met (80% overall, 100% on critical modules)
- ✅ SOC 2 Type 1 audit prep complete
- ✅ The agency would be genuinely upset if you turned it off

---

## 3. Out of scope (do NOT build)

These are explicitly out of Phase 1. Future-phase features. Building them in Phase 1 violates the spec.

**User-facing**:

- Brand-side users / brand client portal (P2)
- Creator job board / marketplace browsing (P3)
- Native mobile apps (P2)
- Direct brand self-serve signup without an agency (P3)
- Public API for external integrators (P3)

**Functional**:

- Fast 48h payouts (P2; standard payout only in P1)
- AI creator matching (P3)
- AI content QC (P3)
- AI brief generation (P3)
- AI agents (P4)
- Vertical-specific workflows (P4)
- Workflow rule builder beyond the smart automation catalog (P3)
- Cross-campaign personal kanban (P2)
- Two-way Google Calendar sync (P2)
- Whitelisting / paid amplification (P4)
- Affiliate / performance attribution (P4)
- Content licensing (P4)
- Saved board templates per agency (P2)

**Infrastructure**:

- Multi-region deployment (eu-central-1 only in P1; eu-west-1 is DR snapshot only)
- Multi-currency conversion (each campaign uses one currency in P1)
- WebSocket real-time updates (P2; polling in P1)
- Visual regression testing (P2)
- Bug bounty program (P3)

**Compliance**:

- ISO 27001 certification (P4)
- SOC 2 Type 2 (P3)
- LGPD or other regional certifications (P4)

---

## 4. Personas in Phase 1

### 4.1 Creator

A content creator (typically Instagram, TikTok, or YouTube). Self-onboards, builds profile, signs master contract, gets approved by Catalyst staff, manages availability, accepts assignments, submits drafts, posts content, gets paid.

### 4.2 Agency staff (Catalyst's team)

Three roles in P1:

- **agency_admin** — full control, billing, user management
- **agency_manager** — campaigns and creators; no billing or user management
- **agency_staff** — execute campaigns; no creating brands or users

### 4.3 Platform admin (Catalyst Engine ops)

One role in P1: `super_admin`. Catalyst Engine's internal staff. Approves creators, handles disputes, investigates incidents.

---

## 5. Build sequence (12-sprint plan)

This is the recommended sequence. Each sprint is roughly 1–2 weeks for a solo developer. Total Phase 1: ~16–18 weeks (4–5 months).

### Sprint 0 — Foundation (1 week)

- Repo monorepo setup (apps/api, apps/main, apps/admin, packages/ui, packages/design-tokens, packages/api-client)
- Laravel 11 skeleton with module structure
- Vue 3 + TypeScript + Vuetify skeletons for both SPAs
- Design tokens package with all Phase 1 tokens
- pnpm workspace, ESLint, Prettier, Pint, Larastan, Pest, Vitest, Playwright configured
- Pre-commit hooks (Husky + lint-staged)
- Docker Compose for local dev (Postgres, Redis, Mailhog)
- GitHub Actions CI pipeline (lint, typecheck, test on PR)
- Terraform skeleton for AWS (eu-central-1 staging + production accounts)
- Sentry projects created for all 3 surfaces
- AWS Secrets Manager structure defined
- `CURSOR-INSTRUCTIONS.md` and architecture docs in repo

**Acceptance:** `pnpm dev` starts everything locally. `pnpm test` runs the test suite (empty but green). PR pipeline runs and all checks pass. Two AWS environments accessible.

### Sprint 1 — Identity & multi-tenancy core (1.5 weeks)

- `users` table + Laravel Sanctum SPA auth setup
- `agencies` table + first agency seed (Catalyst)
- `agency_users` table + role enum
- `BelongsToAgency` trait with global scope
- `admin_profiles` table + first super_admin user seed
- Login / logout / password reset flows on both main and admin SPAs
- 2FA enrollment and verification (TOTP)
- Mandatory 2FA for admin users
- Email verification flow
- Password breach check via HaveIBeenPwned
- Rate limiting on auth endpoints
- Account lockout after failed attempts
- Audit logging foundation (`audit_logs` table, `Audited` trait, `AuditObserver`)
- i18n setup for both SPAs (en, pt, it) and Laravel `lang/`
- Theme system (light + dark) with user preference persisted
- The full Definition of Done applied: tests, types, lint, audit, etc.

**Acceptance:** A creator can sign up. An agency admin can sign in. Admin can sign in to admin SPA. 2FA works for admin. Cross-tenant access tests pass. Audit log captures auth events. All tests green.

### Sprint 2 — Brands and basic agency UI (1.5 weeks)

- `brands` table with full Phase 1 + reserved Phase 2 columns
- Agency layout shell (sidebar, top bar, workspace switcher)
- Brand CRUD (list, create, edit, archive) under `/api/v1/agencies/{agency}/brands`
- Brand detail view in main SPA
- Per-agency settings page (basic — defaults like currency, language)
- Agency user invitation flow (invite by email, role assignment, accept)
- Theme toggle, language switcher in user menu
- Empty states for every list view
- Loading skeletons for every async surface
- All required E2E tests for the flows above

**Acceptance:** Catalyst admin can create their first brand, invite a manager, and see the brand in their workspace. UI follows the design system from `01-UI-UX.md`. All tests green.

### Sprint 3 — Creator profile & onboarding (2 weeks)

This is one of the bigger sprints because the creator side is rich.

- `creators`, `creator_social_accounts`, `creator_portfolio_items`, `creator_availability_blocks`, `creator_tax_profiles`, `creator_payout_methods`, `creator_kyc_verifications`, `agency_creator_relations` tables (the last is added in Sprint 3 Chunk 1 — Sprint 1's self-review §a inaccurately listed it as already shipped; see [`docs/tech-debt.md`](./tech-debt.md))
- Creator self-signup flow (multi-step wizard)
  - Step 1: account creation (email, password, 2FA encouraged)
  - Step 2: profile basics (name, bio, country, language, region, categories)
  - Step 3: connect social accounts (OAuth flow for IG, TikTok, YouTube — at least IG must work; TikTok and YouTube can be stubs initially)
  - Step 4: portfolio uploads (video samples, images)
  - Step 5: KYC initiation (vendor TBD; integration uses the contract pattern from `06-INTEGRATIONS.md`; mock provider in dev)
  - Step 6: tax profile collection (VIES validation for VAT numbers)
  - Step 7: payout method (Stripe Connect Express onboarding flow)
  - Step 8: master contract review and signature (e-sign vendor TBD; mock in dev)
  - Step 9: submitted, awaiting approval state
- Creator dashboard (post-signup, pre-approval): shows status, lets creator continue editing
- Profile completeness score calculation
- Bulk roster invitation (CSV upload by agency staff with email invitations)
  - CSV format: email, name, primary_platform, handle (optional pre-fill)
  - Sends invitation emails with magic-link signup that pre-fills agency context
  - Creates `agency_creator_relations` row with status `prospect` until creator signs up

**Acceptance:** A creator can complete the full signup wizard. Catalyst can bulk-invite 100 creators via CSV. All onboarding steps emit appropriate audit events. KYC and e-sign use mock providers in dev. All tests green.

### Sprint 4 — Creator approval workflow (1 week)

- Admin SPA: pending approvals queue
- Admin SPA: KYC review queue
- Approve / reject creator actions with reason
- Rejection feedback message sent to creator (via in-app + email)
- Creator-side view: rejection feedback, ability to update and resubmit
- Email notifications for approval / rejection
- Profile-completeness-bar enforcement (creators can't submit until score >= 80)

**Acceptance:** A creator submits, admin reviews and approves OR rejects with reason. Creator receives notification. If rejected, creator updates and resubmits. Audit trail complete. Tests green.

### Sprint 5 — Creator availability calendar (1 week)

- Availability block CRUD
- Calendar UI on creator side (month and week views)
- Auto-blocks created when creator accepts an assignment (linked to assignment_id)
- Soft vs hard blocks
- Recurring blocks (basic — once per week)
- Reason field per block (creator-only visibility)
- Agency-side view of creator availability when matching to campaigns
- Conflict warnings: agency tries to invite creator during a hard block → warning modal

**Acceptance:** Creator can add and edit blocks. Agency staff can see availability. Auto-blocks happen on assignment acceptance. Tests green.

### Sprint 6 — Internal creator matching (1 week)

- Agency SPA: creator roster view (the agency's creators)
- Filtering: country, primary language, categories, follower range, engagement rate, availability status
- Postgres FTS for name/bio/handle search
- Saved talent pools (per agency, per brand)
- Per-creator detail view in agency SPA: profile, samples, social metrics, history with this agency, blacklist status, internal notes, internal rating
- `agency_creator_relations` row management (rating, internal notes)

**Acceptance:** Agency staff can search and filter the global creator pool, see which are in their roster, view detail of any creator. Tests green.

### Sprint 7 — Blacklisting (0.5 weeks)

- Blacklist scope: agency-wide or brand-specific
- Soft vs hard blocks
- Mandatory reason field
- Audit logged
- Agency-side: notification policy setting (whether creators are notified of blacklisting)
- Creator notification email (if policy says yes)
- Brand-scoped blacklist via `brand_creator_blacklists` table
- Matching/invitation flow respects blacklists

**Acceptance:** Agency can blacklist with reason. Brand-scoped vs agency-scoped works. Matching automatically excludes blacklisted creators. Tests green.

### Sprint 8 — Campaigns & assignments (2 weeks)

The heart of the platform.

- `campaigns`, `campaign_assignments`, `campaign_drafts`, `campaign_posted_content` tables
- Campaign creation flow
  - Form: brand selection, name, description, objective, dates, posting window, budget + currency, brief (deliverables, do/don'ts, hashtags, mentions, links, attachments), required-per-campaign-contract toggle
- Campaign list view per agency (filter by brand, status, dates)
- Campaign detail view with tabs: Overview / Board / Creators / Drafts / Payments / Messages / Settings
- Assignment state machine implementation (`CampaignAssignmentStateMachine` service)
- Invite creators to campaign (single + bulk)
- Creator side: invitation appears in their dashboard
- Creator accept / decline / counter flows
- Per-campaign contract addendum flow (if required by campaign settings)
- Assignment cancellation with reason

**Acceptance:** Agency creates a campaign, invites creators (single and bulk). Creators see invitations and accept/decline/counter. State machine is enforced. Audit complete. Tests green.

### Sprint 9 — Drafts and review (1.5 weeks)

- Draft submission flow (creator side)
  - Upload media (video, image) via pre-signed S3 URLs
  - Caption, hashtags, mentions
- Draft review flow (agency side)
  - Drawer with media preview, full draft content
  - Approve / reject / request revision actions
  - Revision feedback text
- Versioning of drafts (creator can resubmit; new version)
- Posted content link submission (after approval, creator marks as posted)
- Auto-verification via social API (post URL matched against creator's account)

**Acceptance:** Creator submits draft. Agency reviews and approves OR requests revision. Creator resubmits. Final version posted. Auto-verification picks up the post. Tests green.

### Sprint 10 — Payments (1.5 weeks)

- `payments`, `payment_events` tables
- Stripe Connect integration (real, not mock — but in test mode for staging)
- Stripe Connect Express account creation for creators (during onboarding)
- Escrow funding flow when assignment moves to "contracted" or "draft_approved" (configurable per agency setting)
  - Charge brand/agency via Stripe charge
  - Hold funds in platform balance
- Escrow release flow when assignment moves to "live_verified" or manually triggered
  - Transfer to creator's Connect account
  - Standard payout speed (no fast payout in P1)
- Webhook handling for: `account.updated`, `charge.succeeded`, `charge.refunded`, `transfer.created`, `transfer.failed`, `payout.paid`, `payout.failed`, dispute events
- Idempotency on all payment operations
- Markup hiding (agency markup not exposed to brand client when brand portal exists in P2; in P1, no brand client yet)
- Currency display logic (campaign currency, formatted per locale)

**Acceptance:** Real Stripe test-mode money flows: agency funds escrow → creator receives payout. Webhooks update internal state. Failures alert admin. Audit and payment events complete. Tests green.

### Sprint 11 — Messaging (1 week)

- `message_threads`, `messages`, `message_read_receipts` tables
- Per-assignment thread auto-created when assignment is created
- Chat UI in assignment drawer
- File attachment support (via pre-signed S3)
- System messages (e.g., "Draft submitted by X")
- Read receipts
- Email digest of unread messages (daily — configurable per user)
- Notifications (in-app + email) on new messages

**Acceptance:** Agency and creator can exchange messages within an assignment. Files attach correctly. System messages appear. Read receipts work. Email notifications fire. Tests green.

### Sprint 12 — Boards & automation (1.5 weeks)

- `boards`, `board_columns`, `board_automations`, `board_cards`, `board_card_movements` tables
- Default board template (provisioned automatically when campaign is created)
- Default automation set (mapped per `10-BOARD-AUTOMATION.md`)
- Board view in campaign detail page (Kanban with horizontal scroll)
- Drag-and-drop manual moves (recorded as user-triggered movements)
- Column CRUD (add, rename, recolor, reorder, delete with safety)
- Automation configuration UI (table of events, dropdown to select target column, enable/disable toggle)
- Card details drawer on click
- Card movement history visible per assignment
- The `BoardAutomationListener` listening to all assignment events
- 30-second polling for board updates

**Acceptance:** Each campaign has a board. Automations fire correctly when assignment events emit. Manual moves are allowed and audit-logged but don't trigger business logic. Column edit works with safeguards. Tests green.

### Sprint 13 — Admin panel core (1.5 weeks)

- Admin dashboard with operational stats
- Agency management (list, detail, suspend)
- Creator management (pending approvals queue, KYC queue, full creator detail with all linked data)
- Audit log viewer with filtering
- User search + impersonation (with mandatory reason and time-limited session)
- Operations: queue depth, failed jobs (Horizon-integrated), system health
- Compliance: GDPR export queue, erasure queue
- Feature flag toggle UI
- Persistent environment banner (LOCAL / STAGING / PRODUCTION)

**Acceptance:** Catalyst Engine ops staff can perform every Phase 1 admin task without touching production database directly. All admin actions audit-logged. Tests green.

### Sprint 14 — GDPR features (1 week)

- `data_export_requests`, `data_erasure_requests` tables
- Self-serve data export endpoint (background job, S3-stored archive, signed download URL)
- Erasure request flow (user-initiated → admin approval queue → 7-day cool-down → execution job)
- `ExecuteDataErasureJob` with full anonymization including S3 file cleanup, social token revocation, audit log preservation
- Cookie consent banner (self-built minimal CMP)
- Privacy policy and terms pages (static content, multilingual)

**Acceptance:** Any user can export their data and download an archive. Any user can request erasure; admin approves; erasure runs after cool-down. Audit logs preserved. Tests green.

### Sprint 15 — Production readiness & SOC 2 prep (1 week)

- Final security review against checklist in `05-SECURITY-COMPLIANCE.md`
- Penetration testing self-assessment
- Backup restoration tested (DB snapshot restore drill)
- Incident response runbook completed
- DPAs in place with all chosen vendors
- All vendor decisions documented in `docs/integrations/vendor-decisions.md`
- Status page set up (statuspage.io or self-hosted)
- Monitoring dashboards finalized
- On-call procedures defined (even if just for solo developer)
- Final 20 critical-path E2E tests verified green
- SOC 2 Type 1 readiness checklist confirmed

**Acceptance:** Security review passes. Backup restore works. All runbooks exist. Status page live. Phase 1 ready for production handoff to Catalyst.

---

## 6. Detailed feature specifications

### 6.1 Creator self-onboarding wizard

A multi-step wizard with persistent progress (creator can save and resume). Steps:

#### Step 1: Account creation

- Email
- Password (12+ chars, breach-checked)
- Password confirmation
- Marketing consent checkbox (defaults off)
- Terms and privacy consent (mandatory)
- After submit: email verification link sent

#### Step 2: Profile basics

- Display name
- Bio (markdown supported, sanitized)
- Avatar upload (max 5MB, JPG/PNG/WebP)
- Country (dropdown of EU + UK)
- Region / city (free text)
- Primary language
- Secondary languages (multi-select)
- Categories (multi-select from predefined list: lifestyle, sports, beauty, fashion, food, travel, gaming, tech, music, art, fitness, parenting, business, education, comedy, other)
- Save and continue

#### Step 3: Social accounts

- Connect Instagram (OAuth via Meta)
- Connect TikTok (OAuth)
- Connect YouTube (OAuth via Google)
- At least one required for submission
- Auto-fetch follower count, engagement rate, recent posts
- Mark one as primary

#### Step 4: Portfolio

- Upload up to 10 portfolio items (video or image)
- Each: title, description, optional external URL
- Drag-and-drop reorder
- Skip allowed but reduces completeness score

#### Step 5: Identity verification (KYC)

- Initiates KYC via vendor (mocked in dev)
- Vendor's flow takes over (document + selfie/liveness)
- Returns to platform on completion
- Status updated via webhook

#### Step 6: Tax profile

- Tax form type (radio: EU self-employed, EU company, UK self-employed, UK company)
- Legal name
- Tax ID (validated via VIES for EU, HMRC for UK)
- Address (country, city, postal, street)
- Encrypted at rest

#### Step 7: Payout method

- Stripe Connect Express onboarding flow (redirect to Stripe, return on completion)
- Bank account or card (Stripe handles)
- Status visible after return

#### Step 8: Master contract

- Display master contract (markdown rendered, sanitized)
- Read-receipts captured (which sections were viewed)
- Signature flow via e-sign vendor (mocked in dev)
- Returns to platform on signature

#### Step 9: Review and submit

- Summary of all entered info
- Submit for approval
- Confirmation: "Your profile is under review. We'll notify you within 3 business days."
- Status: `application_status = pending`

### 6.2 Agency creator approval

Admin SPA → Pending approvals → click row → review pane shows:

- Profile completeness score
- All profile fields
- Social account connection status + metrics
- Portfolio items (preview videos/images)
- KYC status and decision (with PII appropriately redacted unless drilled in)
- Tax profile (encrypted; admin can drill in with audit trail)
- Master contract signed status
- Two action buttons: "Approve" / "Reject"

#### Approve action

- Confirmation modal
- Optional welcome message
- Sets `application_status = approved`, `approved_at`, `approved_by_user_id`
- Triggers welcome email
- Creator can now be assigned to campaigns
- Audit log entry: `creator.approved`

#### Reject action

- Mandatory reason (free text)
- Optional structured fields: which sections were inadequate
- Sets `application_status = rejected`, `rejected_at`, `rejection_reason`
- Email sent to creator with feedback
- Creator can update and resubmit
- Audit log entry: `creator.rejected`

### 6.3 Campaign creation form

Form sections:

#### Basics

- Brand (dropdown of agency's brands; required)
- Campaign name
- Description (rich text, markdown)
- Objective (dropdown: awareness, engagement, conversion, ugc, launch)

#### Schedule

- Campaign start date
- Campaign end date
- Posting window start
- Posting window end

#### Budget

- Total budget (numeric input + currency dropdown)
- Currency (auto-fills from brand default; editable)

#### Brief

- Deliverables (repeater: kind dropdown + count)
  - Kinds: instagram_post, instagram_reel, instagram_story, tiktok_video, youtube_short, youtube_long
- Do's (text area, bullet points)
- Don'ts (text area, bullet points)
- Required hashtags (tag input)
- Required mentions (tag input)
- Required links (URL list)
- Disclaimer requirements (checkbox: FTC #ad)
- Brand assets (file uploads — mood boards, product shots)

#### Settings

- Require per-campaign contract addendum (checkbox; default off)
- Auto-fund escrow on contract signing OR on draft approval (radio)

#### Save behavior

- Save as draft (default; not visible to creators yet)
- Publish (becomes "active"; creators can be invited)

### 6.4 Inviting creators to a campaign

From campaign detail → "Invite creators" button:

- Modal opens
- Two tabs: "From roster" and "Search global"
- From roster: list of agency's creators (filtered by availability, blacklist, eligibility)
- Search global: search the platform (Phase 1: results limited to creators not blacklisted by this agency)
- Multi-select with checkboxes
- Per-creator: optional fee override (otherwise campaign default)
- Per-creator: optional deliverable override
- "Send invitations" button (idempotent, with idempotency key)
- Bulk invitations queued; status visible in campaign tab

### 6.5 Creator-side assignment flow

#### Receiving an invitation

- Creator's dashboard shows new invitation card
- Click → opens assignment detail page
- Displays: campaign info, brand info, brief, deliverables, fee, posting deadlines, master contract reminder, optional addendum

#### Actions

- **Accept:** confirms, status → `accepted`, auto-creates availability block, board card moves
- **Decline:** mandatory reason (free text or preset reasons: "out of capacity," "not a fit," "schedule conflict," "fee too low," "other")
- **Counter:** form to propose different fee, deliverables, or timing; agency reviews and accepts or counters back

#### Producing

- Creator sees clear deadlines
- "Submit draft" button when ready

#### Submitting a draft

- Form: caption text, hashtags, mentions, media uploads
- Pre-signed S3 upload for video files
- After submit: status → `draft_submitted`, agency notified

#### Reviewing feedback

- If revision requested: see feedback, edit and resubmit (new version)
- If approved: see "approved" status, "ready to post" instruction

#### Posting

- Creator posts on the actual platform (Instagram, TikTok, etc.)
- Returns to Catalyst Engine and submits the post URL
- System auto-verifies via social API

#### Payment

- After verification (and after escrow released by agency):
- Creator sees payout in their wallet
- Bank transfer initiated by Stripe; funds arrive in 2–5 business days

### 6.6 Agency-side draft review

From campaign board → click card in "In Review" column → drawer opens:

- Top: creator info + assignment summary
- Middle: full draft preview (media + caption + hashtags + mentions)
- Inline comments allowed (Phase 2; basic feedback in Phase 1)
- Three buttons:
  - **Approve** — moves to approved state, optionally also funds escrow
  - **Request revision** — text area for feedback, sends back to creator
  - **Reject** — terminal action, ends assignment with reason

### 6.7 Verification of posted content

- After creator submits the post URL, a queued job runs
- Job calls the social API for the post
- Verifies: post belongs to creator's connected account, post URL matches submitted, content matches brief deliverable kind (e.g., is it actually a Reel?)
- If verified: status → `live_verified`, board card moves
- If failed: agency notified, manual review

### 6.8 Payment release flow

Agency-initiated:

- From assignment drawer → "Release payment" button (only enabled when status is `live_verified`)
- Confirmation modal showing: amount, fee, recipient
- Idempotent submission
- Triggers Stripe transfer
- Status → `payment_released`
- Board card moves to "Paid" column

Or via automation:

- Agency settings can enable "auto-release on verification"
- When `assignment.live_verified` event fires AND auto-release is on, payment automatically released

---

## 7. Critical-path E2E tests (Phase 1)

These 20 Playwright tests must be green at all times:

1. Creator self-signup → email verify → wizard step 2 (mock KYC, mock e-sign)
2. Creator completes full wizard → submitted state
3. Admin approves a creator → creator sees approved state
4. Admin rejects a creator with reason → creator sees feedback → resubmits
5. Creator adds availability block → block visible
6. Agency admin creates first brand
7. Agency admin invites manager → manager accepts → has access
8. Agency creates a campaign for a brand
9. Agency bulk-invites 5 creators via CSV
10. Creator accepts an invitation → board card appears in "Invited" → moves to "Approved" via automation when contract is met
11. Creator submits a draft → board card moves to "In Review"
12. Agency requests a revision → creator sees feedback → resubmits
13. Agency approves a draft → board card moves to "Approved"
14. Creator marks content as posted → social API mock verifies → board card moves to "Posted"
15. Agency releases payment → Stripe test mode → creator receives payout (mocked transfer succeeded)
16. Agency blacklists a creator with reason → creator excluded from matching
17. Admin impersonates an agency user with reason → returns to admin role
18. User initiates GDPR export → admin approves → user downloads archive
19. User enrolls 2FA → signs out → signs in with 2FA code
20. Failed login attempts → account locks after threshold → password reset unlocks

Additional non-critical E2E tests are nice-to-have but not gating.

---

## 8. Key implementation notes

### 8.1 Use mocks aggressively in dev

- Mock KYC provider returns a pass after 5 seconds.
- Mock e-sign provider returns a signature after 5 seconds.
- Mock Stripe in dev unless testing the real integration.
- Mock social APIs return canned responses.

This keeps dev fast and CI deterministic. Real providers only in staging.

### 8.2 Don't ship untranslated strings

Every user-facing string in i18n files for en, pt, it from day one. The Catalyst pilot is in EU; multi-language support isn't optional.

### 8.3 Don't skip tests under time pressure

If a sprint is running long, cut scope. Don't ship without tests. Untested code in Phase 1 becomes a Phase 2 fire.

### 8.4 The agency partner is your beta tester, not your product designer

Listen to their feedback. Filter it through "is this a Phase 1 universal need or a Catalyst-specific request?" Build the universal needs; politely defer or configure-flag the specific ones.

### 8.5 Keep migrations atomic and reviewed

Every migration PR gets careful self-review even solo. Reference `08-DATABASE-EVOLUTION.md`. Even Phase 1 migrations that touch tables added earlier in Phase 1 follow expand/migrate/contract once Catalyst's data is loaded.

### 8.6 Production launch is a milestone, not a step

Before flipping Phase 1 from staging to production with live Catalyst data:

- All success criteria met
- Backup restoration drill executed and passed
- Final pen-test self-assessment done
- Catalyst trained on the platform
- Communication plan in place for the agency partner
- Rollback procedure rehearsed

---

## 9. What gets handed to Phase 2

When Phase 1 is complete and stable, Phase 2 starts. Phase 2 inherits:

- A fully working multi-tenant platform with one happy customer (Catalyst)
- 100+ creators onboarded
- Real campaign and payment data flowing
- A defined operational process around admin tasks
- An audit log full of real events
- A user base whose data must NOT be lost during Phase 2 changes

This last point is why all Phase 2 schema changes follow expand/migrate/contract from day one of Phase 2.

---

## 10. Risks and mitigations

| Risk                                                         | Mitigation                                                                                |
| ------------------------------------------------------------ | ----------------------------------------------------------------------------------------- |
| Vendor selection delays Sprint 3 (KYC) and Sprint 9 (e-sign) | Start with mock providers; choose vendors during Sprints 1–2                              |
| Stripe Connect approval takes weeks                          | Apply during Sprint 0                                                                     |
| Meta / TikTok / YouTube app review delays                    | Apply for Meta and TikTok app reviews during Sprint 0; YouTube is simpler                 |
| Solo developer burnout on a 4–5 month sprint                 | Realistic sprint sizing; don't compress; lean on Cursor for boilerplate                   |
| Catalyst pushes scope expansion mid-build                    | Reference partnership terms; offer a configurable solution; defer new features to Phase 2 |
| GDPR audit finds gaps post-launch                            | Self-assessment before launch; consult external counsel if uncertain                      |
| Production incident in first month                           | Runbooks ready; monitoring tight; rollback rehearsed                                      |

---

## 11. The Phase 1 mantra

**Build the foundation correctly. Ship for one customer. Learn fast. Earn the right to Phase 2.**

This isn't an MVP that gets thrown away. It's the first 25% of a product that will serve thousands of agencies. Every line of code respects that future. Every shortcut now is a bill due in Phase 2 or 3.

---

**End of Phase 1 specification. Build only what's defined here.**
